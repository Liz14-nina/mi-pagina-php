<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../config/conexion.php';

if (empty($_SESSION['usuario_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

$usuarioId = (int) $_SESSION['usuario_id'];
$temaId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$temaId || $temaId < 1) {
    http_response_code(400);
    exit('Tema no válido.');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function escaparTema(string $valor): string
{
    return htmlspecialchars($valor, ENT_QUOTES, 'UTF-8');
}

function subirArchivoTema(array $archivo): string
{
    $maximo = 8 * 1024 * 1024;

    if (!isset($archivo['error']) || is_array($archivo['error'])) {
        throw new DomainException('El archivo enviado no es válido.');
    }

    if ($archivo['error'] !== UPLOAD_ERR_OK) {
        throw new DomainException('No se pudo recibir el archivo.');
    }

    if (
        !isset($archivo['size']) ||
        $archivo['size'] < 1 ||
        $archivo['size'] > $maximo
    ) {
        throw new DomainException(
            'El archivo debe pesar como máximo 8 MB.'
        );
    }

    $nombreOriginal = (string) ($archivo['name'] ?? '');
    $extension = strtolower(pathinfo($nombreOriginal, PATHINFO_EXTENSION));

    $tiposPermitidos = [
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

    if (!isset($tiposPermitidos[$extension])) {
        throw new DomainException(
            'Solo se permiten PDF, DOCX, TXT, JPG y PNG.'
        );
    }

    if (!class_exists('finfo')) {
        throw new RuntimeException(
            'La extensión Fileinfo de PHP no está disponible.'
        );
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($archivo['tmp_name']);

    if (
        !is_string($mime) ||
        !in_array($mime, $tiposPermitidos[$extension], true)
    ) {
        throw new DomainException(
            'El contenido del archivo no coincide con su extensión.'
        );
    }

    // El nombre almacenado no depende del nombre proporcionado
    // por el usuario.
    $directorio = __DIR__ . '/../privado/materiales';

    if (
        !is_dir($directorio) &&
        !mkdir($directorio, 0750, true) &&
        !is_dir($directorio)
    ) {
        throw new RuntimeException(
            'No se pudo preparar la carpeta de archivos.'
        );
    }

    $nombreGuardado = bin2hex(random_bytes(24)) . '.' . $extension;
    $destino = $directorio . '/' . $nombreGuardado;

    if (!move_uploaded_file($archivo['tmp_name'], $destino)) {
        throw new RuntimeException(
            'No se pudo guardar el archivo subido.'
        );
    }

    return $nombreGuardado;
}

$error = '';

try {
    // Obtener tema, grupo y creador.
    $consulta = $pdo->prepare(
        'SELECT t.id, t.nombre AS tema,
                g.id AS grupo_id, g.nombre AS grupo,
                g.creador_id, u.nombre AS creador
         FROM temas t
         INNER JOIN grupos g ON g.id = t.grupo_id
         INNER JOIN usuarios u ON u.id = g.creador_id
         WHERE t.id = ?'
    );
    $consulta->execute([$temaId]);
    $tema = $consulta->fetch();

    if (!$tema) {
        http_response_code(404);
        exit('El tema no existe.');
    }

    $grupoId = (int) $tema['grupo_id'];
    $esCreador = (int) $tema['creador_id'] === $usuarioId;

    $consulta = $pdo->prepare(
        'SELECT 1
         FROM integrantes_grupo
         WHERE grupo_id = ? AND usuario_id = ?'
    );
    $consulta->execute([$grupoId, $usuarioId]);
    $esIntegrante = (bool) $consulta->fetchColumn();

    if (!$esCreador && !$esIntegrante) {
        http_response_code(403);
        exit(
            'Debes pertenecer al grupo y tener tu acceso aprobado para ver este tema.'
        );
    }

    // Solo el creador puede publicar guías y actividades.
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';

        if (
            !is_string($token) ||
            !hash_equals($_SESSION['csrf_token'], $token)
        ) {
            throw new DomainException(
                'El formulario caducó. Recarga la página.'
            );
        }

        if (!$esCreador) {
            throw new DomainException(
                'Solo el creador puede publicar guías y actividades.'
            );
        }

        $accion = $_POST['accion'] ?? '';

        if ($accion === 'crear_guia') {
            $titulo = trim((string) ($_POST['titulo'] ?? ''));
            $descripcion = trim(
                (string) ($_POST['descripcion'] ?? '')
            );

            if (
                $titulo === '' ||
                mb_strlen($titulo) > 150 ||
                mb_strlen($descripcion) > 5000
            ) {
                throw new DomainException(
                    'Revisa el título y la descripción de la guía.'
                );
            }

            if (
                !isset($_FILES['archivo']) ||
                !is_array($_FILES['archivo'])
            ) {
                throw new DomainException(
                    'Selecciona el archivo de la guía.'
                );
            }

            $nombreArchivo = subirArchivoTema($_FILES['archivo']);

            try {
                $consulta = $pdo->prepare(
                    'INSERT INTO guias_estudio
                     (tema_id, titulo, descripcion, archivo, creador_id)
                     VALUES (?, ?, ?, ?, ?)'
                );
                $consulta->execute([
                    $temaId,
                    $titulo,
                    $descripcion !== '' ? $descripcion : null,
                    $nombreArchivo,
                    $usuarioId
                ]);
            } catch (Throwable $e) {
                $ruta = __DIR__ . '/../privado/materiales/' . $nombreArchivo;

                if (is_file($ruta)) {
                    unlink($ruta);
                }

                throw $e;
            }

            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            header(
                'Location: tema.php?id=' . $temaId . '&ok=guia#guias'
            );
            exit;
        }

        if ($accion === 'crear_actividad') {
            $titulo = trim((string) ($_POST['titulo'] ?? ''));
            $instrucciones = trim(
                (string) ($_POST['instrucciones'] ?? '')
            );
            $fecha = trim((string) ($_POST['fecha_limite'] ?? ''));
            $notaMaxima = filter_var(
                $_POST['nota_maxima'] ?? null,
                FILTER_VALIDATE_FLOAT
            );

            if (
                $titulo === '' ||
                mb_strlen($titulo) > 150 ||
                $instrucciones === '' ||
                mb_strlen($instrucciones) > 10000
            ) {
                throw new DomainException(
                    'Completa el título y las instrucciones de la actividad.'
                );
            }

            if (
                $notaMaxima === false ||
                $notaMaxima <= 0 ||
                $notaMaxima > 100
            ) {
                throw new DomainException(
                    'La nota máxima debe ser mayor que 0 y no superar 100.'
                );
            }

            $fechaSQL = null;

            if ($fecha !== '') {
                $fechaObjeto = DateTimeImmutable::createFromFormat(
                    '!Y-m-d\TH:i',
                    $fecha
                );

                if (
                    !$fechaObjeto ||
                    $fechaObjeto->format('Y-m-d\TH:i') !== $fecha
                ) {
                    throw new DomainException(
                        'La fecha límite no es válida.'
                    );
                }

                $fechaSQL = $fechaObjeto->format('Y-m-d H:i:s');
            }

            $consulta = $pdo->prepare(
                'INSERT INTO actividades
                 (tema_id, titulo, instrucciones, fecha_limite,
                  nota_maxima, creador_id)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $consulta->execute([
                $temaId,
                $titulo,
                $instrucciones,
                $fechaSQL,
                $notaMaxima,
                $usuarioId
            ]);

            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            header(
                'Location: tema.php?id=' . $temaId . '&ok=actividad#actividades'
            );
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

// Cargar guías y actividades.
try {
    $consulta = $pdo->prepare(
        'SELECT id, titulo, descripcion, creado_en
         FROM guias_estudio
         WHERE tema_id = ?
         ORDER BY creado_en DESC, id DESC'
    );
    $consulta->execute([$temaId]);
    $guias = $consulta->fetchAll();

    $consulta = $pdo->prepare(
        'SELECT a.id, a.titulo, a.instrucciones, a.fecha_limite,
                a.nota_maxima, a.creado_en,
                (SELECT COUNT(*)
                 FROM entregas_actividad ea
                 WHERE ea.actividad_id = a.id) AS total_entregas
         FROM actividades a
         WHERE a.tema_id = ?
         ORDER BY a.creado_en DESC, a.id DESC'
    );
    $consulta->execute([$temaId]);
    $actividades = $consulta->fetchAll();
} catch (Throwable $e) {
    error_log($e->getMessage());
    $guias = [];
    $actividades = [];
    $error = 'No se pudieron cargar los materiales del tema.';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= escaparTema($tema['tema']) ?> | NIGHT OWL</title>
<style>
*{box-sizing:border-box}
body{margin:0;padding:24px;font-family:Arial,sans-serif;background:#eef4ff;color:#172554}
main{max-width:950px;margin:auto}
.tarjeta{background:#fff;padding:24px;margin-bottom:20px;border-radius:16px;box-shadow:0 8px 25px rgba(30,64,175,.08)}
h1,h2{color:#1d4ed8}
label{display:block;margin:12px 0 6px;font-weight:bold}
input,textarea,button{width:100%;padding:12px;border-radius:8px;font-size:16px}
input,textarea{border:1px solid #cbd5e1}
textarea{min-height:110px;resize:vertical}
button{border:0;background:#2563eb;color:#fff;font-weight:bold;cursor:pointer;margin-top:12px}
.item{padding:16px 0;border-bottom:1px solid #e2e8f0}
.item:last-child{border-bottom:0}
.error{background:#fee2e2;color:#991b1b;padding:12px;border-radius:8px}
.aviso{background:#dcfce7;color:#166534;padding:12px;border-radius:8px}
a{color:#1d4ed8}
small{color:#475569}
</style>
</head>
<body>
<main>

<p>
    <a href="grupo.php?id=<?= $grupoId ?>">← Volver al grupo</a>
</p>

<section class="tarjeta">
    <h1><?= escaparTema($tema['tema']) ?></h1>
    <p>
        <strong>Grupo:</strong> <?= escaparTema($tema['grupo']) ?><br>
        <strong>Creador:</strong> <?= escaparTema($tema['creador']) ?>
    </p>
</section>

<?php if ($error !== ''): ?>
    <p class="error" role="alert"><?= escaparTema($error) ?></p>
<?php endif; ?>

<?php if (($_GET['ok'] ?? '') === 'guia'): ?>
    <p class="aviso">La guía se publicó correctamente.</p>
<?php elseif (($_GET['ok'] ?? '') === 'actividad'): ?>
    <p class="aviso">La actividad se creó correctamente.</p>
<?php endif; ?>

<?php if ($esCreador): ?>
<section class="tarjeta">
    <h2>Publicar guía de estudio</h2>
    <p>Las guías sirven para estudiar y no llevan calificación.</p>

    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token"
               value="<?= escaparTema($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="accion" value="crear_guia">

        <label for="titulo_guia">Título de la guía</label>
        <input id="titulo_guia" name="titulo" maxlength="150" required>

        <label for="descripcion_guia">Descripción (opcional)</label>
        <textarea id="descripcion_guia" name="descripcion"
                  maxlength="5000"></textarea>

        <label for="archivo_guia">Archivo de apoyo (máximo 8 MB)</label>
        <input id="archivo_guia" type="file" name="archivo"
               accept=".pdf,.docx,.txt,.jpg,.jpeg,.png" required>

        <button type="submit">Publicar guía</button>
    </form>
</section>

<section class="tarjeta">
    <h2>Crear actividad</h2>

    <form method="post">
        <input type="hidden" name="csrf_token"
               value="<?= escaparTema($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="accion" value="crear_actividad">

        <label for="titulo_actividad">Título</label>
        <input id="titulo_actividad" name="titulo"
               maxlength="150" required>

        <label for="instrucciones">Instrucciones</label>
        <textarea id="instrucciones" name="instrucciones"
                  maxlength="10000" required></textarea>

        <label for="fecha_limite">Fecha límite (opcional)</label>
        <input id="fecha_limite" type="datetime-local"
               name="fecha_limite">

        <label for="nota_maxima">Nota máxima</label>
        <input id="nota_maxima" type="number" name="nota_maxima"
               min="0.01" max="100" step="0.01"
               value="100" required>

        <button type="submit">Crear actividad</button>
    </form>
</section>
<?php endif; ?>

<section class="tarjeta" id="guias">
    <h2>Guías de estudio (<?= count($guias) ?>)</h2>

    <?php if (!$guias): ?>
        <p>Aún no se han publicado guías para este tema.</p>
    <?php else: ?>
        <?php foreach ($guias as $guia): ?>
            <div class="item">
                <strong><?= escaparTema($guia['titulo']) ?></strong>

                <?php if (!empty($guia['descripcion'])): ?>
                    <p><?= nl2br(escaparTema($guia['descripcion'])) ?></p>
                <?php endif; ?>

                <small>
                    Publicada: <?= escaparTema($guia['creado_en']) ?>
                </small><br>

                <a href="descargar.php?tipo=guia&id=<?= (int) $guia['id'] ?>">
                    Ver o descargar guía
                </a>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</section>

<section class="tarjeta" id="actividades">
    <h2>Actividades (<?= count($actividades) ?>)</h2>

    <?php if (!$actividades): ?>
        <p>Aún no se han publicado actividades para este tema.</p>
    <?php else: ?>
        <?php foreach ($actividades as $actividad): ?>
            <div class="item">
                <h3><?= escaparTema($actividad['titulo']) ?></h3>

                <p>
                    <?= nl2br(escaparTema($actividad['instrucciones'])) ?>
                </p>

                <p>
                    <strong>Nota máxima:</strong>
                    <?= escaparTema((string) $actividad['nota_maxima']) ?>/100
                </p>

                <p>
                    <strong>Fecha límite:</strong>
                    <?= $actividad['fecha_limite']
                        ? escaparTema($actividad['fecha_limite'])
                        : 'Sin fecha límite' ?>
                </p>

                <?php if ($esCreador): ?>
                    <p>
                        Entregas recibidas:
                        <?= (int) $actividad['total_entregas'] ?>
                    </p>
                <?php endif; ?>

                <a href="actividad.php?id=<?= (int) $actividad['id'] ?>">
                    <?= $esCreador
                        ? 'Revisar entregas y calificar'
                        : 'Ver y entregar actividad' ?>
                </a>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</section>

</main>
</body>
</html>
