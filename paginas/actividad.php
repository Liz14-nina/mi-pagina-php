<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../config/conexion.php';

if (empty($_SESSION['usuario_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

$usuarioId = (int) $_SESSION['usuario_id'];
$actividadId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$actividadId || $actividadId < 1) {
    http_response_code(400);
    exit('Actividad no válida.');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function escaparActividad(string $valor): string
{
    return htmlspecialchars($valor, ENT_QUOTES, 'UTF-8');
}

function subirEntregaActividad(array $archivo): ?string
{
    if (($archivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if (
        !isset($archivo['error']) ||
        is_array($archivo['error']) ||
        $archivo['error'] !== UPLOAD_ERR_OK
    ) {
        throw new DomainException('No se pudo recibir el archivo.');
    }

    if (
        !isset($archivo['size']) ||
        $archivo['size'] < 1 ||
        $archivo['size'] > 8 * 1024 * 1024
    ) {
        throw new DomainException('El archivo debe pesar como máximo 8 MB.');
    }

    $extension = strtolower(
        pathinfo((string) ($archivo['name'] ?? ''), PATHINFO_EXTENSION)
    );

    $permitidos = [
        'pdf'  => ['application/pdf'],
        'docx' => [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/zip'
        ],
        'txt'  => ['text/plain'],
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png']
    ];

    if (!isset($permitidos[$extension]) || !class_exists('finfo')) {
        throw new DomainException('El tipo de archivo no está permitido.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($archivo['tmp_name']);

    if (!is_string($mime) || !in_array($mime, $permitidos[$extension], true)) {
        throw new DomainException('El contenido del archivo no es válido.');
    }

    $directorio = __DIR__ . '/../privado/materiales';

    if (
        !is_dir($directorio) &&
        !mkdir($directorio, 0750, true) &&
        !is_dir($directorio)
    ) {
        throw new RuntimeException('No se pudo preparar la carpeta de archivos.');
    }

    $nombre = bin2hex(random_bytes(24)) . '.' . $extension;

    if (!move_uploaded_file(
        $archivo['tmp_name'],
        $directorio . '/' . $nombre
    )) {
        throw new RuntimeException('No se pudo guardar el archivo.');
    }

    return $nombre;
}

$error = '';

try {
    $consulta = $pdo->prepare(
        'SELECT a.id, a.tema_id, a.titulo, a.instrucciones,
                a.fecha_limite, a.nota_maxima, a.creador_id,
                t.nombre AS tema,
                g.id AS grupo_id, g.nombre AS grupo,
                g.creador_id AS creador_grupo
         FROM actividades a
         INNER JOIN temas t ON t.id = a.tema_id
         INNER JOIN grupos g ON g.id = t.grupo_id
         WHERE a.id = ?'
    );
    $consulta->execute([$actividadId]);
    $actividad = $consulta->fetch();

    if (!$actividad) {
        http_response_code(404);
        exit('La actividad no existe.');
    }

    $grupoId = (int) $actividad['grupo_id'];
    $esCreador = (int) $actividad['creador_grupo'] === $usuarioId;

    $consulta = $pdo->prepare(
        'SELECT 1
         FROM integrantes_grupo
         WHERE grupo_id = ? AND usuario_id = ?'
    );
    $consulta->execute([$grupoId, $usuarioId]);
    $esIntegrante = (bool) $consulta->fetchColumn();

    if (!$esCreador && !$esIntegrante) {
        http_response_code(403);
        exit('No tienes permiso para acceder a esta actividad.');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';

        if (
            !is_string($token) ||
            !hash_equals($_SESSION['csrf_token'], $token)
        ) {
            throw new DomainException('El formulario caducó. Recarga la página.');
        }

        $accion = $_POST['accion'] ?? '';

        // Entregar actividad: solo integrantes aprobados.
        if ($accion === 'entregar') {
            if (!$esIntegrante) {
                throw new DomainException(
                    'Solo los integrantes aprobados pueden entregar actividades.'
                );
            }

            $respuesta = trim((string) ($_POST['respuesta'] ?? ''));

            if (mb_strlen($respuesta) > 20000) {
                throw new DomainException(
                    'La respuesta no puede superar 20.000 caracteres.'
                );
            }

            $archivoNuevo = null;

            if (
                isset($_FILES['archivo']) &&
                is_array($_FILES['archivo'])
            ) {
                $archivoNuevo = subirEntregaActividad($_FILES['archivo']);
            }

            $consulta = $pdo->prepare(
                'SELECT archivo
                 FROM entregas_actividad
                 WHERE actividad_id = ? AND usuario_id = ?'
            );
            $consulta->execute([$actividadId, $usuarioId]);
            $entregaAnterior = $consulta->fetch();

            $archivoFinal = $archivoNuevo
                ?? ($entregaAnterior['archivo'] ?? null);

            if ($respuesta === '' && $archivoFinal === null) {
                throw new DomainException(
                    'Escribe una respuesta o adjunta un archivo.'
                );
            }

            try {
                $consulta = $pdo->prepare(
                    'INSERT INTO entregas_actividad
                     (actividad_id, usuario_id, respuesta, archivo)
                     VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                        respuesta = VALUES(respuesta),
                        archivo = VALUES(archivo),
                        nota = NULL,
                        comentario_creador = NULL,
                        calificada_en = NULL,
                        calificada_por = NULL'
                );
                $consulta->execute([
                    $actividadId,
                    $usuarioId,
                    $respuesta !== '' ? $respuesta : null,
                    $archivoFinal
                ]);
            } catch (Throwable $e) {
                if ($archivoNuevo !== null) {
                    $ruta = __DIR__ . '/../privado/materiales/' . $archivoNuevo;
                    if (is_file($ruta)) {
                        unlink($ruta);
                    }
                }
                throw $e;
            }

            // Eliminar el archivo anterior solo después de guardar el nuevo.
            if (
                $archivoNuevo !== null &&
                !empty($entregaAnterior['archivo']) &&
                $entregaAnterior['archivo'] !== $archivoNuevo
            ) {
                $rutaAnterior = __DIR__ . '/../privado/materiales/'
                    . basename($entregaAnterior['archivo']);

                if (is_file($rutaAnterior)) {
                    unlink($rutaAnterior);
                }
            }

            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            header('Location: actividad.php?id=' . $actividadId . '&ok=entrega');
            exit;
        }

        // Calificar entrega: solo el creador del grupo.
        if ($accion === 'calificar') {
            if (!$esCreador) {
                throw new DomainException(
                    'Solo el creador del grupo puede calificar.'
                );
            }

            $entregaId = filter_var(
                $_POST['entrega_id'] ?? null,
                FILTER_VALIDATE_INT
            );

            $nota = filter_var(
                $_POST['nota'] ?? null,
                FILTER_VALIDATE_FLOAT
            );

            $comentario = trim(
                (string) ($_POST['comentario'] ?? '')
            );

            if (
                !$entregaId ||
                $entregaId < 1 ||
                $nota === false ||
                $nota < 0 ||
                $nota > (float) $actividad['nota_maxima'] ||
                mb_strlen($comentario) > 5000
            ) {
                throw new DomainException(
                    'Revisa la nota y el comentario. La nota no puede superar '
                    . $actividad['nota_maxima'] . ' puntos.'
                );
            }

            $consulta = $pdo->prepare(
                'UPDATE entregas_actividad
                 SET nota = ?, comentario_creador = ?,
                     calificada_en = CURRENT_TIMESTAMP,
                     calificada_por = ?
                 WHERE id = ? AND actividad_id = ?'
            );
            $consulta->execute([
                $nota,
                $comentario !== '' ? $comentario : null,
                $usuarioId,
                $entregaId,
                $actividadId
            ]);

            if ($consulta->rowCount() === 0) {
                $consulta = $pdo->prepare(
                    'SELECT id FROM entregas_actividad
                     WHERE id = ? AND actividad_id = ?'
                );
                $consulta->execute([$entregaId, $actividadId]);

                if (!$consulta->fetch()) {
                    throw new DomainException('No se encontró esa entrega.');
                }
            }

            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            header('Location: actividad.php?id=' . $actividadId . '&ok=nota');
            exit;
        }

        throw new DomainException('Acción no reconocida.');
    }
} catch (DomainException $e) {
    $error = $e->getMessage();
} catch (Throwable $e) {
    error_log($e->getMessage());
    $error = 'Ocurrió un error. Revisa los datos e inténtalo de nuevo.';
}

// Consultar la entrega propia o todas las entregas si es el creador.
try {
    if ($esCreador) {
        $consulta = $pdo->prepare(
            'SELECT ea.id, ea.usuario_id, ea.respuesta, ea.archivo,
                    ea.entregada_en, ea.nota, ea.comentario_creador,
                    u.nombre, u.correo
             FROM entregas_actividad ea
             INNER JOIN usuarios u ON u.id = ea.usuario_id
             WHERE ea.actividad_id = ?
             ORDER BY ea.entregada_en DESC'
        );
        $consulta->execute([$actividadId]);
        $entregas = $consulta->fetchAll();
        $miEntrega = null;
    } else {
        $consulta = $pdo->prepare(
            'SELECT id, usuario_id, respuesta, archivo,
                    entregada_en, nota, comentario_creador
             FROM entregas_actividad
             WHERE actividad_id = ? AND usuario_id = ?'
        );
        $consulta->execute([$actividadId, $usuarioId]);
        $miEntrega = $consulta->fetch() ?: null;
        $entregas = [];
    }
} catch (Throwable $e) {
    error_log($e->getMessage());
    $entregas = [];
    $miEntrega = null;
    $error = 'No se pudieron cargar las entregas.';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= escaparActividad($actividad['titulo']) ?> | NIGHT OWL</title>
<style>
*{box-sizing:border-box}
body{margin:0;padding:24px;font-family:Arial,sans-serif;background:#eef4ff;color:#172554}
main{max-width:950px;margin:auto}
.tarjeta{background:#fff;padding:24px;margin-bottom:20px;border-radius:16px;box-shadow:0 8px 25px rgba(30,64,175,.08)}
h1,h2,h3{color:#1d4ed8}
label{display:block;margin:12px 0 6px;font-weight:bold}
input,textarea,button{width:100%;padding:12px;border-radius:8px;font-size:16px}
input,textarea{border:1px solid #cbd5e1}
textarea{min-height:120px}
button{border:0;background:#2563eb;color:#fff;font-weight:bold;cursor:pointer;margin-top:10px}
.item{padding:18px 0;border-bottom:1px solid #e2e8f0}
.item:last-child{border-bottom:0}
.error{background:#fee2e2;color:#991b1b;padding:12px;border-radius:8px}
.aviso{background:#dcfce7;color:#166534;padding:12px;border-radius:8px}
a{color:#1d4ed8}
</style>
</head>
<body>
<main>

<p>
    <a href="tema.php?id=<?= (int) $actividad['tema_id'] ?>">
        ← Volver al tema
    </a>
</p>

<section class="tarjeta">
    <h1><?= escaparActividad($actividad['titulo']) ?></h1>
    <p>
        <strong>Grupo:</strong> <?= escaparActividad($actividad['grupo']) ?><br>
        <strong>Tema:</strong> <?= escaparActividad($actividad['tema']) ?><br>
        <strong>Nota máxima:</strong>
        <?= escaparActividad((string) $actividad['nota_maxima']) ?>/100
    </p>

    <?php if (!empty($actividad['fecha_limite'])): ?>
        <p>
            <strong>Fecha límite:</strong>
            <?= escaparActividad($actividad['fecha_limite']) ?>
        </p>
    <?php endif; ?>

    <h2>Instrucciones</h2>
    <p><?= nl2br(escaparActividad($actividad['instrucciones'])) ?></p>
</section>

<?php if ($error !== ''): ?>
    <p class="error" role="alert"><?= escaparActividad($error) ?></p>
<?php endif; ?>

<?php if (($_GET['ok'] ?? '') === 'entrega'): ?>
    <p class="aviso">Tu entrega se guardó correctamente.</p>
<?php elseif (($_GET['ok'] ?? '') === 'nota'): ?>
    <p class="aviso">La calificación se guardó correctamente.</p>
<?php endif; ?>

<?php if ($esIntegrante): ?>
<section class="tarjeta">
    <h2>Mi entrega</h2>

    <?php if ($miEntrega && $miEntrega['nota'] !== null): ?>
        <p>
            <strong>Nota:</strong>
            <?= escaparActividad((string) $miEntrega['nota']) ?>/100
        </p>
        <?php if (!empty($miEntrega['comentario_creador'])): ?>
            <p>
                <strong>Comentario del creador:</strong><br>
                <?= nl2br(escaparActividad($miEntrega['comentario_creador'])) ?>
            </p>
        <?php endif; ?>
    <?php elseif ($miEntrega): ?>
        <p>Tu entrega está pendiente de calificación.</p>
    <?php else: ?>
        <p>Aún no has entregado esta actividad.</p>
    <?php endif; ?>

    <?php if ($miEntrega && !empty($miEntrega['archivo'])): ?>
        <p>
            <a href="descargar.php?tipo=entrega&id=<?= (int) $miEntrega['id'] ?>">
                Descargar mi archivo enviado
            </a>
        </p>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token"
               value="<?= escaparActividad($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="accion" value="entregar">

        <label for="respuesta">Tu respuesta escrita</label>
        <textarea id="respuesta" name="respuesta"
                  maxlength="20000"><?= escaparActividad(
                      (string) ($miEntrega['respuesta'] ?? '')
                  ) ?></textarea>

        <label for="archivo">Adjuntar archivo (opcional, máximo 8 MB)</label>
        <input id="archivo" type="file" name="archivo"
               accept=".pdf,.docx,.txt,.jpg,.jpeg,.png">

        <button type="submit">Guardar y entregar</button>
    </form>

    <p>
        Puedes actualizar tu entrega antes de la calificación.
        Si vuelves a enviarla después de recibir una nota, la nota anterior
        se eliminará para que el creador revise la nueva versión.
    </p>
</section>
<?php endif; ?>

<?php if ($esCreador): ?>
<section class="tarjeta">
    <h2>Entregas recibidas (<?= count($entregas) ?>)</h2>

    <?php if (!$entregas): ?>
        <p>Todavía no hay entregas.</p>
    <?php else: ?>
        <?php foreach ($entregas as $entrega): ?>
            <div class="item">
                <h3><?= escaparActividad($entrega['nombre']) ?></h3>
                <p><?= escaparActividad($entrega['correo']) ?></p>
                <small>
                    Entregada: <?= escaparActividad($entrega['entregada_en']) ?>
                </small>

                <?php if (!empty($entrega['respuesta'])): ?>
                    <h4>Respuesta</h4>
                    <p><?= nl2br(escaparActividad($entrega['respuesta'])) ?></p>
                <?php endif; ?>

                <?php if (!empty($entrega['archivo'])): ?>
                    <p>
                        <a href="descargar.php?tipo=entrega&id=<?= (int) $entrega['id'] ?>">
                            Descargar archivo de la entrega
                        </a>
                    </p>
                <?php endif; ?>

                <form method="post">
                    <input type="hidden" name="csrf_token"
                           value="<?= escaparActividad($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="accion" value="calificar">
                    <input type="hidden" name="entrega_id"
                           value="<?= (int) $entrega['id'] ?>">

                    <label for="nota_<?= (int) $entrega['id'] ?>">
                        Nota (0 a <?= escaparActividad((string) $actividad['nota_maxima']) ?>)
                    </label>
                    <input
                        id="nota_<?= (int) $entrega['id'] ?>"
                        type="number"
                        name="nota"
                        min="0"
                        max="<?= escaparActividad((string) $actividad['nota_maxima']) ?>"
                        step="0.01"
                        value="<?= $entrega['nota'] !== null
                            ? escaparActividad((string) $entrega['nota'])
                            : '' ?>"
                        required
                    >

                    <label for="comentario_<?= (int) $entrega['id'] ?>">
                        Comentario de retroalimentación
                    </label>
                    <textarea
                        id="comentario_<?= (int) $entrega['id'] ?>"
                        name="comentario"
                        maxlength="5000"
                    ><?= escaparActividad(
                        (string) ($entrega['comentario_creador'] ?? '')
                    ) ?></textarea>

                    <button type="submit">Guardar calificación</button>
                </form>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</section>
<?php endif; ?>

</main>
</body>
</html>
