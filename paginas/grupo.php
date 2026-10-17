<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../config/conexion.php';

if (empty($_SESSION['usuario_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

$usuarioId = (int) $_SESSION['usuario_id'];
$grupoId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$grupoId || $grupoId < 1) {
    http_response_code(400);
    exit('Identificador de grupo no válido.');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function escapar(string $valor): string
{
    return htmlspecialchars($valor, ENT_QUOTES, 'UTF-8');
}

$error = '';

try {
    // Obtener los datos del grupo.
    $consulta = $pdo->prepare(
        'SELECT g.id, g.nombre, g.grado, g.creador_id,
                m.nombre AS materia, u.nombre AS creador
         FROM grupos g
         INNER JOIN materias m ON m.id = g.materia_id
         INNER JOIN usuarios u ON u.id = g.creador_id
         WHERE g.id = ?'
    );
    $consulta->execute([$grupoId]);
    $grupo = $consulta->fetch();

    if (!$grupo) {
        http_response_code(404);
        exit('El grupo no existe.');
    }

    $esCreador = (int) $grupo['creador_id'] === $usuarioId;

    // Comprobar si el usuario pertenece al grupo.
    $consulta = $pdo->prepare(
        'SELECT 1
         FROM integrantes_grupo
         WHERE grupo_id = ? AND usuario_id = ?'
    );
    $consulta->execute([$grupoId, $usuarioId]);
    $esIntegrante = (bool) $consulta->fetchColumn();

    // Procesar los formularios.
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

        $accion = $_POST['accion'] ?? '';

        // Solicitar ingreso al grupo.
        if ($accion === 'solicitar') {
            if ($esIntegrante || $esCreador) {
                throw new DomainException(
                    'Ya formas parte de este grupo.'
                );
            }

            $pdo->beginTransaction();

            try {
                $consulta = $pdo->prepare(
                    'SELECT estado
                     FROM solicitudes_grupo
                     WHERE grupo_id = ? AND usuario_id = ?
                     FOR UPDATE'
                );
                $consulta->execute([$grupoId, $usuarioId]);
                $solicitud = $consulta->fetchColumn();

                if ($solicitud === 'pendiente') {
                    throw new DomainException(
                        'Ya tienes una solicitud pendiente.'
                    );
                }

                if ($solicitud === false) {
                    $consulta = $pdo->prepare(
                        "INSERT INTO solicitudes_grupo
                         (grupo_id, usuario_id, estado)
                         VALUES (?, ?, 'pendiente')"
                    );
                    $consulta->execute([$grupoId, $usuarioId]);
                } else {
                    $consulta = $pdo->prepare(
                        "UPDATE solicitudes_grupo
                         SET estado = 'pendiente',
                             creada_en = CURRENT_TIMESTAMP
                         WHERE grupo_id = ? AND usuario_id = ?"
                    );
                    $consulta->execute([$grupoId, $usuarioId]);
                }

                $pdo->commit();

                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                header('Location: grupo.php?id=' . $grupoId);
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                throw $e;
            }
        }

        // Eliminar grupo: únicamente el creador puede hacerlo.
        if ($accion === 'eliminar') {
            if (!$esCreador) {
                throw new DomainException(
                    'Solo el creador puede eliminar este grupo.'
                );
            }

            if (
                ($_POST['confirmar_eliminacion'] ?? '') !== 'si'
            ) {
                throw new DomainException(
                    'Debes confirmar que deseas eliminar el grupo.'
                );
            }

            $consulta = $pdo->prepare(
                'DELETE FROM grupos
                 WHERE id = ? AND creador_id = ?'
            );
            $consulta->execute([$grupoId, $usuarioId]);

            if ($consulta->rowCount() !== 1) {
                throw new DomainException(
                    'No se pudo eliminar el grupo.'
                );
            }

            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            header('Location: grupos.php?eliminado=1');
            exit;
        }

        // Administrar temas: solo el creador puede modificarlos.
        if (
            $accion === 'agregar_tema' ||
            $accion === 'eliminar_tema'
        ) {
            if (!$esCreador) {
                throw new DomainException(
                    'Solo el creador puede administrar los temas.'
                );
            }

            if ($accion === 'agregar_tema') {
                $nombreTema = trim(
                    (string) ($_POST['nombre_tema'] ?? '')
                );

                if (
                    $nombreTema === '' ||
                    preg_match('/\A.{1,120}\z/us', $nombreTema) !== 1
                ) {
                    throw new DomainException(
                        'El tema debe tener entre 1 y 120 caracteres.'
                    );
                }

                $consulta = $pdo->prepare(
                    'INSERT INTO temas (grupo_id, nombre)
                     VALUES (?, ?)'
                );
                $consulta->execute([$grupoId, $nombreTema]);

                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                header(
                    'Location: grupo.php?id=' . $grupoId . '#temas'
                );
                exit;
            }

            if ($accion === 'eliminar_tema') {
                $temaId = filter_var(
                    $_POST['tema_id'] ?? null,
                    FILTER_VALIDATE_INT
                );

                if (!$temaId || $temaId < 1) {
                    throw new DomainException(
                        'El tema seleccionado no es válido.'
                    );
                }

                $consulta = $pdo->prepare(
                    'DELETE FROM temas
                     WHERE id = ? AND grupo_id = ?'
                );
                $consulta->execute([$temaId, $grupoId]);

                if ($consulta->rowCount() !== 1) {
                    throw new DomainException(
                        'No se encontró el tema o ya fue eliminado.'
                    );
                }

                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                header(
                    'Location: grupo.php?id=' . $grupoId . '#temas'
                );
                exit;
            }
        }

        // Las demás acciones administrativas son exclusivas
        // del creador del grupo.
        if (!$esCreador) {
            throw new DomainException(
                'Solo el creador puede administrar integrantes.'
            );
        }

        // Agregar integrante por correo electrónico.
        if ($accion === 'agregar') {
            $correo = trim((string) ($_POST['correo'] ?? ''));

            if (
                !filter_var($correo, FILTER_VALIDATE_EMAIL) ||
                strlen($correo) > 190
            ) {
                throw new DomainException(
                    'Escribe un correo electrónico válido.'
                );
            }

            $consulta = $pdo->prepare(
                'SELECT id FROM usuarios WHERE correo = ?'
            );
            $consulta->execute([$correo]);
            $nuevoUsuarioId = $consulta->fetchColumn();

            if ($nuevoUsuarioId === false) {
                throw new DomainException(
                    'No existe un usuario registrado con ese correo.'
                );
            }

            $nuevoUsuarioId = (int) $nuevoUsuarioId;

            $pdo->beginTransaction();

            try {
                $consulta = $pdo->prepare(
                    'INSERT IGNORE INTO integrantes_grupo
                     (grupo_id, usuario_id)
                     VALUES (?, ?)'
                );
                $consulta->execute([$grupoId, $nuevoUsuarioId]);

                $consulta = $pdo->prepare(
                    "UPDATE solicitudes_grupo
                     SET estado = 'aceptada'
                     WHERE grupo_id = ? AND usuario_id = ?
                       AND estado = 'pendiente'"
                );
                $consulta->execute([$grupoId, $nuevoUsuarioId]);

                $pdo->commit();

                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                header('Location: grupo.php?id=' . $grupoId);
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                throw $e;
            }
        }

        // Aceptar o rechazar una solicitud.
        if ($accion === 'resolver') {
            $solicitudId = filter_var(
                $_POST['solicitud_id'] ?? null,
                FILTER_VALIDATE_INT
            );
            $decision = $_POST['decision'] ?? '';

            if (
                !$solicitudId ||
                $solicitudId < 1 ||
                !in_array(
                    $decision,
                    ['aceptada', 'rechazada'],
                    true
                )
            ) {
                throw new DomainException(
                    'La decisión enviada no es válida.'
                );
            }

            $pdo->beginTransaction();

            try {
                $consulta = $pdo->prepare(
                    "SELECT usuario_id
                     FROM solicitudes_grupo
                     WHERE id = ? AND grupo_id = ?
                       AND estado = 'pendiente'
                     FOR UPDATE"
                );
                $consulta->execute([$solicitudId, $grupoId]);
                $solicitanteId = $consulta->fetchColumn();

                if ($solicitanteId === false) {
                    throw new DomainException(
                        'La solicitud ya no está pendiente.'
                    );
                }

                $solicitanteId = (int) $solicitanteId;

                $consulta = $pdo->prepare(
                    'UPDATE solicitudes_grupo
                     SET estado = ?
                     WHERE id = ? AND grupo_id = ?'
                );
                $consulta->execute([
                    $decision,
                    $solicitudId,
                    $grupoId
                ]);

                if ($decision === 'aceptada') {
                    $consulta = $pdo->prepare(
                        'INSERT IGNORE INTO integrantes_grupo
                         (grupo_id, usuario_id)
                         VALUES (?, ?)'
                    );
                    $consulta->execute([
                        $grupoId,
                        $solicitanteId
                    ]);
                }

                $pdo->commit();

                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                header('Location: grupo.php?id=' . $grupoId);
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                throw $e;
            }
        }

        throw new DomainException('Acción no reconocida.');
    }
} catch (DomainException $e) {
    $error = $e->getMessage();
} catch (Throwable $e) {
    error_log($e->getMessage());
    $error = 'Ocurrió un error. Recarga la página e inténtalo de nuevo.';
}

// Volver a cargar integrantes, solicitudes y temas.
try {
    $consulta = $pdo->prepare(
        'SELECT u.id, u.nombre, u.correo
         FROM integrantes_grupo ig
         INNER JOIN usuarios u ON u.id = ig.usuario_id
         WHERE ig.grupo_id = ?
         ORDER BY u.nombre'
    );
    $consulta->execute([$grupoId]);
    $integrantes = $consulta->fetchAll();

    $consulta = $pdo->prepare(
        "SELECT s.id, s.creada_en, u.nombre, u.correo
         FROM solicitudes_grupo s
         INNER JOIN usuarios u ON u.id = s.usuario_id
         WHERE s.grupo_id = ? AND s.estado = 'pendiente'
         ORDER BY s.creada_en ASC"
    );
    $consulta->execute([$grupoId]);
    $solicitudes = $esCreador ? $consulta->fetchAll() : [];

    $consulta = $pdo->prepare(
        'SELECT estado
         FROM solicitudes_grupo
         WHERE grupo_id = ? AND usuario_id = ?'
    );
    $consulta->execute([$grupoId, $usuarioId]);
    $miSolicitud = $consulta->fetchColumn();

    // Solo el creador y los integrantes aprobados pueden ver temas.
    if ($esCreador || $esIntegrante) {
        $consulta = $pdo->prepare(
            'SELECT id, nombre
             FROM temas
             WHERE grupo_id = ?
             ORDER BY nombre ASC, id ASC'
        );
        $consulta->execute([$grupoId]);
        $temas = $consulta->fetchAll();
    } else {
        $temas = [];
    }
} catch (Throwable $e) {
    error_log($e->getMessage());

    $integrantes = $integrantes ?? [];
    $solicitudes = $solicitudes ?? [];
    $miSolicitud = $miSolicitud ?? false;
    $temas = $temas ?? [];

    $error = 'No se pudo cargar toda la información del grupo.';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title><?= escapar($grupo['nombre']) ?> | NIGHT OWL</title>

<style>
* {
    box-sizing: border-box;
}

body {
    margin: 0;
    padding: 24px;
    font-family: Arial, sans-serif;
    background: #eef4ff;
    color: #172554;
}

main {
    max-width: 900px;
    margin: auto;
}

.tarjeta {
    background: white;
    padding: 24px;
    margin-bottom: 20px;
    border-radius: 16px;
    box-shadow: 0 8px 25px rgba(30, 64, 175, .08);
}

h1, h2 {
    color: #1d4ed8;
}

label {
    display: block;
    margin: 12px 0 6px;
    font-weight: bold;
}

input, button {
    width: 100%;
    padding: 12px;
    border-radius: 8px;
    font-size: 16px;
}

input {
    border: 1px solid #cbd5e1;
}

button {
    border: 0;
    background: #2563eb;
    color: white;
    font-weight: bold;
    cursor: pointer;
    margin-top: 10px;
}

button:hover {
    background: #1d4ed8;
}

button.rechazar,
button.eliminar {
    background: #b91c1c;
}

button.rechazar:hover,
button.eliminar:hover {
    background: #991b1b;
}

.error {
    background: #fee2e2;
    color: #991b1b;
    padding: 12px;
    border-radius: 8px;
}

.item {
    padding: 16px 0;
    border-bottom: 1px solid #e2e8f0;
}

.item:last-child {
    border-bottom: 0;
}

a {
    color: #1d4ed8;
}

.confirmacion {
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: normal;
}

.confirmacion input {
    width: auto;
    flex-shrink: 0;
}

.estado {
    display: inline-block;
    padding: 6px 10px;
    border-radius: 8px;
    background: #dbeafe;
    color: #1e40af;
}

.formulario-tema {
    margin-bottom: 16px;
}

.tema-nombre {
    overflow-wrap: anywhere;
}

/* Acceso a las evaluaciones */
.boton-evaluaciones {
    display: inline-block;
    background: #2563eb;
    color: #ffffff;
    padding: 12px 18px;
    border-radius: 8px;
    text-decoration: none;
    font-weight: bold;
}

.boton-evaluaciones:hover {
    background: #1d4ed8;
    color: #ffffff;
}

.enlace-notas {
    display: inline-block;
    margin-left: 12px;
}

@media (max-width: 600px) {
    body {
        padding: 12px;
    }

    .tarjeta {
        padding: 16px;
    }

    .enlace-notas {
        margin: 12px 0 0;
    }
}
</style>
</head>

<body>
<main>

<p><a href="grupos.php">← Volver a grupos</a></p>

<section class="tarjeta">
    <h1><?= escapar($grupo['nombre']) ?></h1>

    <p>
        <strong>Materia:</strong>
        <?= escapar($grupo['materia']) ?><br>

        <strong>Grado:</strong>
        <?= escapar((string) $grupo['grado']) ?><br>

        <strong>Creador:</strong>
        <?= escapar($grupo['creador']) ?>
    </p>
</section>

<?php if ($error !== ''): ?>
    <p class="error" role="alert">
        <?= escapar($error) ?>
    </p>
<?php endif; ?>

<?php if (!$esCreador && !$esIntegrante): ?>
<section class="tarjeta">
    <h2>Solicitar ingreso</h2>

    <?php if ($miSolicitud === 'pendiente'): ?>
        <p class="estado">Tu solicitud está pendiente de aprobación.</p>
        <p>Cuando el creador la apruebe, podrás consultar los temas del grupo.</p>

    <?php elseif ($miSolicitud === 'rechazada'): ?>
        <p>Tu solicitud anterior fue rechazada. Puedes volver a solicitar ingreso.</p>

        <form method="post">
            <input type="hidden" name="csrf_token"
                   value="<?= escapar($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="accion" value="solicitar">
            <button type="submit">Solicitar nuevamente</button>
        </form>

    <?php elseif ($miSolicitud === 'aceptada'): ?>
        <p>
            Tu solicitud figura como aceptada, pero tu cuenta todavía no aparece
            como integrante. Contacta al creador para que revise tu acceso.
        </p>

    <?php else: ?>
        <p>Solicita acceso para poder ver los temas y participar en el grupo.</p>

        <form method="post">
            <input type="hidden" name="csrf_token"
                   value="<?= escapar($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="accion" value="solicitar">
            <button type="submit">Solicitar ingreso</button>
        </form>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($esCreador || $esIntegrante): ?>
<section class="tarjeta">
    <h2>Evaluaciones</h2>

    <p>
        Consulta las evaluaciones de este grupo,
        presenta las que estén publicadas y revisa tus notas.
    </p>

    <p>
        <a class="boton-evaluaciones"
           href="evaluaciones.php?grupo_id=<?= (int) $grupoId ?>">
            Ver evaluaciones
        </a>

        <a class="enlace-notas" href="mis_notas.php">
            Consultar mis notas
        </a>
    </p>
</section>
<?php endif; ?>

<?php if ($esCreador || $esIntegrante): ?>
<section class="tarjeta" id="temas">
    <h2>Temas del grupo (<?= count($temas) ?>)</h2>

    <?php if ($esCreador): ?>
        <p>Agrega todos los temas que necesiten estudiar en este grupo.</p>

        <form class="formulario-tema" method="post">
            <input type="hidden" name="csrf_token"
                   value="<?= escapar($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="accion" value="agregar_tema">

            <label for="nombre_tema">Nombre del tema</label>
            <input
                id="nombre_tema"
                name="nombre_tema"
                type="text"
                maxlength="120"
                required
                placeholder="Ej.: Ecuaciones de primer grado"
            >

            <button type="submit">Agregar tema</button>
        </form>
    <?php endif; ?>

    <?php if (!$temas): ?>
        <p>Todavía no se han agregado temas a este grupo.</p>
    <?php else: ?>
        <?php foreach ($temas as $tema): ?>
            <div class="item">
                <div class="tema-nombre">
                    <a href="tema.php?id=<?= (int) $tema['id'] ?>">
                        <?= escapar($tema['nombre']) ?>
                    </a>
                </div>

                <?php if ($esCreador): ?>
                    <form
                        method="post"
                        onsubmit="return confirm('¿Deseas eliminar este tema?');"
                    >
                        <input type="hidden" name="csrf_token"
                               value="<?= escapar($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="accion"
                               value="eliminar_tema">
                        <input type="hidden" name="tema_id"
                               value="<?= (int) $tema['id'] ?>">

                        <button class="eliminar" type="submit">
                            Eliminar tema
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</section>
<?php endif; ?>

<section class="tarjeta">
    <h2>Integrantes (<?= count($integrantes) ?>)</h2>

    <?php if (!$integrantes): ?>
        <p>Aún no hay integrantes.</p>
    <?php else: ?>
        <?php foreach ($integrantes as $integrante): ?>
            <div class="item">
                <strong><?= escapar($integrante['nombre']) ?></strong><br>
                <small><?= escapar($integrante['correo']) ?></small>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</section>

<?php if ($esCreador): ?>

<section class="tarjeta">
    <h2>Agregar integrante por correo</h2>

    <form method="post">
        <input type="hidden" name="csrf_token"
               value="<?= escapar($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="accion" value="agregar">

        <label for="correo">Correo del usuario registrado</label>
        <input id="correo" name="correo" type="email"
               maxlength="190" required>

        <button type="submit">Agregar integrante</button>
    </form>
</section>

<section class="tarjeta">
    <h2>Solicitudes pendientes (<?= count($solicitudes) ?>)</h2>

    <?php if (!$solicitudes): ?>
        <p>No hay solicitudes pendientes.</p>
    <?php else: ?>
        <?php foreach ($solicitudes as $solicitud): ?>
            <div class="item">
                <strong><?= escapar($solicitud['nombre']) ?></strong><br>
                <small><?= escapar($solicitud['correo']) ?></small>

                <form method="post">
                    <input type="hidden" name="csrf_token"
                           value="<?= escapar($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="accion" value="resolver">
                    <input type="hidden" name="solicitud_id"
                           value="<?= (int) $solicitud['id'] ?>">

                    <button name="decision" value="aceptada" type="submit">
                        Aceptar solicitud
                    </button>

                    <button class="rechazar" name="decision"
                            value="rechazada" type="submit">
                        Rechazar solicitud
                    </button>
                </form>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</section>

<section class="tarjeta">
    <h2>Zona de administración</h2>

    <p>
        Si eliminas este grupo, también se eliminarán sus temas,
        integrantes y solicitudes asociadas. Esta acción no se puede
        deshacer.
    </p>

    <form method="post"
          onsubmit="return confirm('¿Estás seguro de que deseas eliminar este grupo?');">

        <input type="hidden" name="csrf_token"
               value="<?= escapar($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="accion" value="eliminar">

        <label class="confirmacion">
            <input type="checkbox" name="confirmar_eliminacion"
                   value="si" required>
            Confirmo que deseo eliminar este grupo.
        </label>

        <button class="eliminar" type="submit">
            Eliminar grupo permanentemente
        </button>
    </form>
</section>

<?php endif; ?>

</main>
</body>
</html>