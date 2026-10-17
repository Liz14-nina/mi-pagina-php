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

function hCalificar($valor): string {
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

function csrfCalificar(): string {
    if (empty($_SESSION['csrf_calificar_evaluacion'])) {
        $_SESSION['csrf_calificar_evaluacion'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_calificar_evaluacion'];
}

function comprobarCsrfCalificar(): void {
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (
        empty($_SESSION['csrf_calificar_evaluacion']) ||
        !hash_equals($_SESSION['csrf_calificar_evaluacion'], $token)
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
    exit('Evaluación no encontrada.');
}

if ((int) $evaluacion['creador_id'] !== $usuarioId) {
    http_response_code(403);
    exit('Solo el creador puede calificar esta evaluación.');
}

$errores = [];
$mensaje = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    comprobarCsrfCalificar();

    $intentoId = filter_input(INPUT_POST, 'intento_id', FILTER_VALIDATE_INT);
    $puntosPost = $_POST['puntos'] ?? [];
    $comentariosPost = $_POST['comentarios'] ?? [];
    $comentarioGeneral = trim((string) ($_POST['comentario_general'] ?? ''));

    if (!$intentoId || $intentoId < 1) {
        $errores[] = 'Intento no válido.';
    } elseif (!is_array($puntosPost) || !is_array($comentariosPost)) {
        $errores[] = 'Los datos enviados no tienen un formato válido.';
    } elseif (mb_strlen($comentarioGeneral) > 10000) {
        $errores[] = 'El comentario general supera el límite de caracteres.';
    }

    if (!$errores) {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                'SELECT id, usuario_id, estado
                 FROM intentos_evaluacion
                 WHERE id = ? AND evaluacion_id = ?
                 FOR UPDATE'
            );
            $stmt->execute([$intentoId, $evaluacionId]);
            $intento = $stmt->fetch();

            if (!$intento) {
                throw new RuntimeException('No se encontró la entrega.');
            }

            if (!in_array($intento['estado'], ['entregado', 'calificado'], true)) {
                throw new RuntimeException('El estudiante todavía no ha entregado esta evaluación.');
            }

            $stmt = $pdo->prepare(
                'SELECT r.id AS respuesta_id, r.pregunta_id,
                        r.opcion_id, r.respuesta_texto, r.puntos_obtenidos,
                        p.tipo, p.puntos AS puntos_maximos,
                        o.es_correcta
                 FROM respuestas_evaluacion r
                 INNER JOIN preguntas_evaluacion p
                    ON p.id = r.pregunta_id
                 LEFT JOIN opciones_pregunta o
                    ON o.id = r.opcion_id
                 WHERE r.intento_id = ?
                   AND p.evaluacion_id = ?
                 ORDER BY p.orden, p.id'
            );
            $stmt->execute([$intentoId, $evaluacionId]);
            $respuestas = $stmt->fetchAll();

            if (!$respuestas) {
                throw new RuntimeException('Esta entrega no contiene respuestas para calificar.');
            }

            $actualizarRespuesta = $pdo->prepare(
                'UPDATE respuestas_evaluacion
                 SET puntos_obtenidos = ?, comentario_creador = ?
                 WHERE id = ? AND intento_id = ?'
            );

            $totalPosible = 0.0;
            $totalObtenido = 0.0;
            $hayPreguntaEscritaSinCalificar = false;

            foreach ($respuestas as $respuesta) {
                $respuestaId = (int) $respuesta['respuesta_id'];
                $puntosMaximos = (float) $respuesta['puntos_maximos'];
                $totalPosible += $puntosMaximos;

                if ($respuesta['tipo'] === 'opcion_multiple') {
                    // La puntuación automática se calcula a partir de la clave
                    // guardada en la base de datos, nunca desde el formulario.
                    $puntos = (
                        $respuesta['opcion_id'] !== null &&
                        (int) $respuesta['es_correcta'] === 1
                    ) ? $puntosMaximos : 0.0;

                    $comentario = trim((string) ($comentariosPost[$respuestaId] ?? ''));

                    if (mb_strlen($comentario) > 5000) {
                        throw new RuntimeException('Un comentario supera el límite de 5000 caracteres.');
                    }

                    $actualizarRespuesta->execute([
                        $puntos,
                        $comentario !== '' ? $comentario : null,
                        $respuestaId,
                        $intentoId
                    ]);

                    $totalObtenido += $puntos;
                    continue;
                }

                $valorPuntos = $puntosPost[$respuestaId] ?? '';

                // Una pregunta escrita sin calificar impide publicar una nota final.
                if (trim((string) $valorPuntos) === '') {
                    $hayPreguntaEscritaSinCalificar = true;
                    continue;
                }

                if (!is_numeric($valorPuntos)) {
                    throw new RuntimeException('Introduce puntos numéricos para cada respuesta escrita.');
                }

                $puntos = (float) $valorPuntos;

                if ($puntos < 0 || $puntos > $puntosMaximos) {
                    throw new RuntimeException(
                        'Los puntos asignados deben estar entre 0 y ' . $puntosMaximos . '.'
                    );
                }

                $comentario = trim((string) ($comentariosPost[$respuestaId] ?? ''));

                if (mb_strlen($comentario) > 5000) {
                    throw new RuntimeException('Un comentario supera el límite de 5000 caracteres.');
                }

                $actualizarRespuesta->execute([
                    round($puntos, 2),
                    $comentario !== '' ? $comentario : null,
                    $respuestaId,
                    $intentoId
                ]);

                $totalObtenido += $puntos;
            }

            if ($totalPosible <= 0) {
                throw new RuntimeException('La evaluación no tiene un total de puntos válido.');
            }

            if ($hayPreguntaEscritaSinCalificar) {
                throw new RuntimeException(
                    'Califica todas las preguntas escritas antes de guardar la nota final.'
                );
            }

            // Convierte los puntos obtenidos en una nota sobre 100.
            $notaFinal = round(($totalObtenido / $totalPosible) * 100, 2);
            $notaFinal = max(0, min(100, $notaFinal));

            $stmt = $pdo->prepare(
                "UPDATE intentos_evaluacion
                 SET estado = 'calificado',
                     nota = ?,
                     comentario_creador = ?,
                     calificada_por = ?,
                     calificada_en = NOW()
                 WHERE id = ? AND evaluacion_id = ?"
            );
            $stmt->execute([
                $notaFinal,
                $comentarioGeneral !== '' ? $comentarioGeneral : null,
                $usuarioId,
                $intentoId,
                $evaluacionId
            ]);

            $pdo->commit();

            header(
                'Location: calificar_evaluacion.php?id=' . $evaluacionId .
                '&guardado=1'
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
                $errores[] = 'No se pudo guardar la calificación. Revisa el registro de errores.';
            }
        }
    }
}

if (isset($_GET['guardado'])) {
    $mensaje = 'Calificación guardada correctamente.';
}

$stmt = $pdo->prepare(
    'SELECT i.id AS intento_id, i.usuario_id, i.estado, i.entregado_en,
            i.nota, i.comentario_creador AS comentario_general,
            u.nombre, u.correo
     FROM intentos_evaluacion i
     INNER JOIN usuarios u ON u.id = i.usuario_id
     WHERE i.evaluacion_id = ?
       AND i.estado IN ("entregado", "calificado")
     ORDER BY i.entregado_en ASC, i.id ASC'
);
$stmt->execute([$evaluacionId]);
$intentos = $stmt->fetchAll();

$stmtPreguntas = $pdo->prepare(
    'SELECT r.id AS respuesta_id, r.pregunta_id, r.respuesta_texto,
            r.opcion_id, r.puntos_obtenidos, r.comentario_creador,
            p.enunciado, p.tipo, p.puntos AS puntos_maximos, p.orden,
            o.texto AS opcion_seleccionada
     FROM respuestas_evaluacion r
     INNER JOIN preguntas_evaluacion p
        ON p.id = r.pregunta_id
     LEFT JOIN opciones_pregunta o ON o.id = r.opcion_id
     WHERE r.intento_id = ?
     ORDER BY p.orden, p.id'
);
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Calificar evaluación | Night Owl</title>
    <style>
        body{font-family:Arial,sans-serif;background:#f3f4f8;color:#202336;margin:0;padding:24px}
        main{max-width:1000px;margin:auto}
        .panel{background:#fff;padding:22px;border-radius:14px;margin-bottom:20px;box-shadow:0 3px 12px #0000000c}
        .respuesta{border:1px solid #dddfee;padding:16px;border-radius:10px;margin:16px 0}
        label{display:block;font-weight:bold;margin:12px 0 6px}
        input,textarea{box-sizing:border-box;width:100%;padding:10px;border:1px solid #cdd0dc;border-radius:8px;font:inherit}
        textarea{min-height:75px}
        button{border:0;border-radius:8px;padding:11px 16px;background:#3730a3;color:white;cursor:pointer;font:inherit}
        .error{background:#fff0f0;padding:12px;border-radius:8px;color:#991b1b}
        .ok{background:#e9f9ef;padding:12px;border-radius:8px;color:#166534}
        .muted{color:#626779}
        .nota{font-size:1.1rem;font-weight:bold}
        details{margin-top:14px}
        summary{cursor:pointer;font-weight:bold}
    </style>
</head>
<body>
<main>
    <p><a href="evaluaciones.php?grupo_id=<?= (int) $evaluacion['grupo_id'] ?>">← Volver a evaluaciones</a></p>

    <section class="panel">
        <h1>Calificar: <?= hCalificar($evaluacion['titulo']) ?></h1>
        <p><strong>Grupo:</strong> <?= hCalificar($evaluacion['grupo_nombre']) ?></p>
        <p><strong>Nota mínima para aprobar:</strong> <?= hCalificar($evaluacion['nota_minima']) ?>/100</p>

        <?php if ($mensaje): ?>
            <p class="ok"><?= hCalificar($mensaje) ?></p>
        <?php endif; ?>

        <?php if ($errores): ?>
            <div class="error">
                <ul>
                    <?php foreach ($errores as $error): ?>
                        <li><?= hCalificar($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </section>

    <?php if (!$intentos): ?>
        <section class="panel">
            <p>Todavía no hay entregas para calificar.</p>
        </section>
    <?php endif; ?>

    <?php foreach ($intentos as $intento): ?>
        <?php
        $stmtPreguntas->execute([(int) $intento['intento_id']]);
        $respuestas = $stmtPreguntas->fetchAll();
        ?>
        <section class="panel">
            <h2><?= hCalificar($intento['nombre']) ?></h2>
            <p class="muted"><?= hCalificar($intento['correo']) ?></p>
            <p>Estado: <?= hCalificar($intento['estado']) ?></p>
            <p>Entregada: <?= hCalificar($intento['entregado_en']) ?></p>

            <?php if ($intento['nota'] !== null): ?>
                <p class="nota">
                    Nota actual: <?= hCalificar(number_format((float) $intento['nota'], 2)) ?>/100
                    — <?= (float) $intento['nota'] >= (float) $evaluacion['nota_minima'] ? 'Aprobada' : 'No aprobada' ?>
                </p>
            <?php endif; ?>

            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= hCalificar(csrfCalificar()) ?>">
                <input type="hidden" name="intento_id" value="<?= (int) $intento['intento_id'] ?>">

                <?php foreach ($respuestas as $respuesta): ?>
                    <article class="respuesta">
                        <h3>Pregunta <?= (int) $respuesta['orden'] ?></h3>
                        <p><?= nl2br(hCalificar($respuesta['enunciado'])) ?></p>
                        <p><small>Puntos posibles: <?= hCalificar($respuesta['puntos_maximos']) ?></small></p>

                        <?php if ($respuesta['tipo'] === 'escrita'): ?>
                            <p><strong>Respuesta del estudiante:</strong></p>
                            <p><?= nl2br(hCalificar($respuesta['respuesta_texto'] ?? 'Sin respuesta')) ?></p>

                            <label for="puntos_<?= (int) $respuesta['respuesta_id'] ?>">Puntos obtenidos</label>
                            <input
                                id="puntos_<?= (int) $respuesta['respuesta_id'] ?>"
                                type="number"
                                name="puntos[<?= (int) $respuesta['respuesta_id'] ?>]"
                                min="0"
                                max="<?= hCalificar($respuesta['puntos_maximos']) ?>"
                                step="0.01"
                                value="<?= $respuesta['puntos_obtenidos'] !== null ? hCalificar($respuesta['puntos_obtenidos']) : '' ?>"
                                required>

                        <?php else: ?>
                            <p><strong>Opción elegida:</strong> <?= hCalificar($respuesta['opcion_seleccionada'] ?? 'Sin respuesta') ?></p>
                            <p><strong>Puntuación automática:</strong>
                                <?= hCalificar($respuesta['puntos_obtenidos'] ?? 'Pendiente') ?>
                                / <?= hCalificar($respuesta['puntos_maximos']) ?>
                            </p>
                        <?php endif; ?>

                        <label for="comentario_<?= (int) $respuesta['respuesta_id'] ?>">Comentario para esta respuesta (opcional)</label>
                        <textarea
                            id="comentario_<?= (int) $respuesta['respuesta_id'] ?>"
                            name="comentarios[<?= (int) $respuesta['respuesta_id'] ?>]"
                            maxlength="5000"><?= hCalificar($respuesta['comentario_creador'] ?? '') ?></textarea>
                    </article>
                <?php endforeach; ?>

                <label for="comentario_general_<?= (int) $intento['intento_id'] ?>">Comentario general para el estudiante (opcional)</label>
                <textarea
                    id="comentario_general_<?= (int) $intento['intento_id'] ?>"
                    name="comentario_general"
                    maxlength="10000"><?= hCalificar($intento['comentario_general'] ?? '') ?></textarea>

                <button type="submit">Guardar calificación</button>
            </form>
        </section>
    <?php endforeach; ?>
</main>
</body>
</html>
