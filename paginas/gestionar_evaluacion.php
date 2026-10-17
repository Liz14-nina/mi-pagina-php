<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../config/conexion.php';

if (!isset($_SESSION['usuario_id'])) {
    header('Location: ../index.php');
    exit;
}

$usuarioId = (int) $_SESSION['usuario_id'];
$evaluacionId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$evaluacionId || $evaluacionId < 1) {
    http_response_code(400);
    exit('Evaluación no válida.');
}

function hGestion($valor): string {
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

function tokenGestion(): string {
    if (empty($_SESSION['csrf_gestion_evaluacion'])) {
        $_SESSION['csrf_gestion_evaluacion'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_gestion_evaluacion'];
}

function comprobarTokenGestion(): void {
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (
        empty($_SESSION['csrf_gestion_evaluacion']) ||
        !hash_equals($_SESSION['csrf_gestion_evaluacion'], $token)
    ) {
        http_response_code(403);
        exit('Formulario vencido. Recarga la página e inténtalo de nuevo.');
    }
}

$stmt = $pdo->prepare(
    'SELECT e.*, g.nombre AS grupo_nombre
     FROM evaluaciones e
     INNER JOIN grupos g ON g.id = e.grupo_id
     WHERE e.id = ?'
);
$stmt->execute([$evaluacionId]);
$evaluacion = $stmt->fetch();

if (!$evaluacion) {
    http_response_code(404);
    exit('No se encontró la evaluación.');
}

$esCreador = (int) $evaluacion['creador_id'] === $usuarioId;

if (!$esCreador) {
    http_response_code(403);
    exit('Solo el creador de la evaluación puede administrarla.');
}

$errores = [];
$mensaje = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    comprobarTokenGestion();

    $accion = (string) ($_POST['accion'] ?? '');

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            'SELECT estado, nota_minima
             FROM evaluaciones
             WHERE id = ? AND creador_id = ?
             FOR UPDATE'
        );
        $stmt->execute([$evaluacionId, $usuarioId]);
        $actual = $stmt->fetch();

        if (!$actual) {
            throw new RuntimeException('No se encontró la evaluación.');
        }

        if ($accion === 'publicar') {
            if ($actual['estado'] !== 'borrador') {
                throw new RuntimeException('Solo puedes publicar una evaluación en borrador.');
            }

            $stmt = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM preguntas_evaluacion
                 WHERE evaluacion_id = ?'
            );
            $stmt->execute([$evaluacionId]);
            $cantidadPreguntas = (int) $stmt->fetchColumn();

            if ($cantidadPreguntas < 1) {
                throw new RuntimeException('Agrega al menos una pregunta antes de publicar.');
            }

            $stmt = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM preguntas_evaluacion p
                 WHERE p.evaluacion_id = ?
                   AND p.tipo = 'opcion_multiple'
                   AND (
                       (SELECT COUNT(*)
                        FROM opciones_pregunta o
                        WHERE o.pregunta_id = p.id) < 2
                       OR
                       (SELECT COUNT(*)
                        FROM opciones_pregunta o
                        WHERE o.pregunta_id = p.id
                          AND o.es_correcta = 1) <> 1
                   )"
            );
            $stmt->execute([$evaluacionId]);
            $preguntasIncompletas = (int) $stmt->fetchColumn();

            if ($preguntasIncompletas > 0) {
                throw new RuntimeException(
                    'Hay preguntas de opción múltiple sin opciones suficientes o sin una única respuesta correcta.'
                );
            }

            $stmt = $pdo->prepare(
                "UPDATE evaluaciones
                 SET estado = 'publicada'
                 WHERE id = ? AND estado = 'borrador'"
            );
            $stmt->execute([$evaluacionId]);

            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('No se pudo publicar la evaluación.');
            }

            $mensaje = 'Evaluación publicada correctamente.';
        } elseif ($accion === 'cerrar') {
            if ($actual['estado'] !== 'publicada') {
                throw new RuntimeException('Solo puedes cerrar una evaluación publicada.');
            }

            $stmt = $pdo->prepare(
                "UPDATE evaluaciones
                 SET estado = 'cerrada'
                 WHERE id = ? AND estado = 'publicada'"
            );
            $stmt->execute([$evaluacionId]);

            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('No se pudo cerrar la evaluación.');
            }

            $mensaje = 'Evaluación cerrada. Los estudiantes ya no podrán enviar ni continuar respuestas.';
        } else {
            throw new RuntimeException('Acción no válida.');
        }

        $pdo->commit();

        // Redirige para evitar repetir la acción al actualizar la página.
        header(
            'Location: gestionar_evaluacion.php?id=' . $evaluacionId .
            '&mensaje=' . rawurlencode($mensaje)
        );
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($e instanceof RuntimeException) {
            $errores[] = $e->getMessage();
        } else {
            error_log($e->getMessage());
            $errores[] = 'Ocurrió un error al actualizar la evaluación.';
        }
    }
}

if (isset($_GET['mensaje'])) {
    $mensaje = (string) $_GET['mensaje'];
}

$stmt = $pdo->prepare(
    'SELECT COUNT(*)
     FROM preguntas_evaluacion
     WHERE evaluacion_id = ?'
);
$stmt->execute([$evaluacionId]);
$totalPreguntas = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM intentos_evaluacion
     WHERE evaluacion_id = ? AND estado IN ('entregado', 'calificado')"
);
$stmt->execute([$evaluacionId]);
$totalEntregadas = (int) $stmt->fetchColumn();
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gestionar evaluación | Night Owl</title>
    <style>
        body{font-family:Arial,sans-serif;background:#f3f4f8;color:#202336;margin:0;padding:24px}
        main{max-width:750px;margin:auto}
        .panel{background:#fff;padding:22px;border-radius:14px;margin-bottom:20px;box-shadow:0 3px 12px #0000000c}
        button,.boton{display:inline-block;border:0;border-radius:8px;padding:11px 16px;background:#3730a3;color:white;text-decoration:none;cursor:pointer;font:inherit}
        .cerrar{background:#9a3412}
        .error{background:#fff0f0;padding:12px;border-radius:8px;color:#991b1b}
        .ok{background:#e9f9ef;padding:12px;border-radius:8px;color:#166534}
        .muted{color:#626779}
        dt{font-weight:bold;margin-top:12px}
        dd{margin:5px 0 12px}
    </style>
</head>
<body>
<main>
    <p><a href="evaluaciones.php?grupo_id=<?= (int) $evaluacion['grupo_id'] ?>">← Volver a evaluaciones</a></p>

    <section class="panel">
        <h1>Gestionar evaluación</h1>
        <h2><?= hGestion($evaluacion['titulo']) ?></h2>

        <?php if ($mensaje): ?>
            <p class="ok"><?= hGestion($mensaje) ?></p>
        <?php endif; ?>

        <?php if ($errores): ?>
            <div class="error">
                <ul>
                    <?php foreach ($errores as $error): ?>
                        <li><?= hGestion($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <dl>
            <dt>Grupo</dt>
            <dd><?= hGestion($evaluacion['grupo_nombre']) ?></dd>

            <dt>Estado</dt>
            <dd><strong><?= hGestion($evaluacion['estado']) ?></strong></dd>

            <dt>Nota mínima para aprobar</dt>
            <dd><?= hGestion($evaluacion['nota_minima']) ?>/100</dd>

            <dt>Total de preguntas</dt>
            <dd><?= $totalPreguntas ?></dd>

            <dt>Entregas realizadas</dt>
            <dd><?= $totalEntregadas ?></dd>
        </dl>

        <?php if ($evaluacion['estado'] === 'borrador'): ?>
            <p>La evaluación todavía no es visible para los estudiantes.</p>
            <form method="post" onsubmit="return confirm('¿Publicar esta evaluación? Los estudiantes del grupo podrán empezar a responderla.');">
                <input type="hidden" name="csrf_token" value="<?= hGestion(tokenGestion()) ?>">
                <input type="hidden" name="accion" value="publicar">
                <button type="submit">Publicar evaluación</button>
            </form>
        <?php elseif ($evaluacion['estado'] === 'publicada'): ?>
            <p>La evaluación está disponible para los estudiantes.</p>
            <form method="post" onsubmit="return confirm('¿Cerrar esta evaluación? Los estudiantes ya no podrán continuar ni entregar respuestas.');">
                <input type="hidden" name="csrf_token" value="<?= hGestion(tokenGestion()) ?>">
                <input type="hidden" name="accion" value="cerrar">
                <button type="submit" class="cerrar">Cerrar evaluación</button>
            </form>
        <?php else: ?>
            <p>Esta evaluación está cerrada. No se puede volver a publicar desde esta página.</p>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
