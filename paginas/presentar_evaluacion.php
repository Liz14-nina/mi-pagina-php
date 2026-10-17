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

function h($valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf_presentar_evaluacion'])) {
        $_SESSION['csrf_presentar_evaluacion'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_presentar_evaluacion'];
}

function validarCsrf(): void
{
    $enviado = (string) ($_POST['csrf_token'] ?? '');

    if (
        empty($_SESSION['csrf_presentar_evaluacion']) ||
        !hash_equals($_SESSION['csrf_presentar_evaluacion'], $enviado)
    ) {
        http_response_code(403);
        exit('Formulario vencido. Recarga la página e inténtalo de nuevo.');
    }
}

/*
|--------------------------------------------------------------------------
| Obtener la evaluación y el grupo
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare(
    'SELECT
        e.*,
        g.nombre AS grupo_nombre,
        g.id AS grupo_id
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

$grupoId = (int) $evaluacion['grupo_id'];
$esCreador = (int) $evaluacion['creador_id'] === $usuarioId;

/*
|--------------------------------------------------------------------------
| Comprobar pertenencia al grupo
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare(
    'SELECT 1
     FROM integrantes_grupo
     WHERE grupo_id = ? AND usuario_id = ?'
);

$stmt->execute([$grupoId, $usuarioId]);
$esIntegrante = (bool) $stmt->fetchColumn();

if (!$esCreador && !$esIntegrante) {
    http_response_code(403);
    exit('No tienes permiso para acceder a esta evaluación.');
}

if ($esCreador) {
    exit(
        'El creador no puede presentar su propia evaluación. ' .
        '<a href="evaluaciones.php?grupo_id=' . $grupoId . '">Volver</a>'
    );
}

/*
|--------------------------------------------------------------------------
| Consultar el intento existente
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare(
    'SELECT id, estado, entregado_en
     FROM intentos_evaluacion
     WHERE evaluacion_id = ? AND usuario_id = ?'
);

$stmt->execute([$evaluacionId, $usuarioId]);
$intento = $stmt->fetch();

/*
|--------------------------------------------------------------------------
| Reglas de disponibilidad
|--------------------------------------------------------------------------
|
| Una evaluación publicada permite iniciar un intento.
| Una evaluación cerrada solo permite continuar un intento que ya existía
| y que sigue en progreso.
|
*/

$intentoEnProgreso = (
    $intento &&
    $intento['estado'] === 'en_progreso'
);

$evaluacionDisponible = $evaluacion['estado'] === 'publicada';

if (!$evaluacionDisponible && !$intentoEnProgreso) {
    http_response_code(403);
    exit('Esta evaluación no está disponible para presentar.');
}

if ($intento && $intento['estado'] !== 'en_progreso') {
    exit(
        '<h2>Ya entregaste esta evaluación</h2>' .
        '<p>Solo puedes presentar esta evaluación una vez.</p>' .
        '<p>Estado: ' . h($intento['estado']) . '</p>' .
        '<p><a href="grupo.php?id=' . $grupoId . '">Volver al grupo</a></p>'
    );
}

/*
|--------------------------------------------------------------------------
| Obtener las preguntas
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare(
    'SELECT p.id, p.enunciado, p.tipo, p.puntos, p.orden
     FROM preguntas_evaluacion p
     WHERE p.evaluacion_id = ?
     ORDER BY p.orden, p.id'
);

$stmt->execute([$evaluacionId]);
$preguntas = $stmt->fetchAll();

if (!$preguntas) {
    exit('Esta evaluación todavía no tiene preguntas.');
}

/*
|--------------------------------------------------------------------------
| Guardar respuestas
|--------------------------------------------------------------------------
*/

$error = '';
$mensaje = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validarCsrf();

    $accion = (string) ($_POST['accion'] ?? '');

    if (!in_array($accion, ['guardar', 'entregar'], true)) {
        http_response_code(400);
        exit('Acción no válida.');
    }

    try {
        $pdo->beginTransaction();

        /*
         * Bloquear la evaluación evita que el estado cambie mientras
         * se procesan las respuestas.
         */
        $stmt = $pdo->prepare(
            'SELECT estado
             FROM evaluaciones
             WHERE id = ?
             FOR UPDATE'
        );

        $stmt->execute([$evaluacionId]);
        $estadoActual = $stmt->fetchColumn();

        if ($estadoActual === false) {
            throw new RuntimeException('La evaluación no existe.');
        }

        /*
         * Volver a consultar el intento dentro de la transacción.
         */
        $stmt = $pdo->prepare(
            'SELECT id, estado
             FROM intentos_evaluacion
             WHERE evaluacion_id = ? AND usuario_id = ?
             FOR UPDATE'
        );

        $stmt->execute([$evaluacionId, $usuarioId]);
        $intentoActual = $stmt->fetch();

        /*
         * Si la evaluación está cerrada, solo se permite continuar
         * cuando el estudiante ya tenía un intento en progreso.
         */
        if ($estadoActual !== 'publicada') {
            if (
                !$intentoActual ||
                $intentoActual['estado'] !== 'en_progreso'
            ) {
                throw new RuntimeException(
                    'La evaluación está cerrada y no puedes iniciar un intento nuevo.'
                );
            }
        }

        if (
            $intentoActual &&
            $intentoActual['estado'] !== 'en_progreso'
        ) {
            throw new RuntimeException(
                'Esta evaluación ya fue entregada.'
            );
        }

        /*
         * Crear un intento únicamente si todavía no existe.
         */
        if (!$intentoActual) {
            $stmt = $pdo->prepare(
                "INSERT INTO intentos_evaluacion
                    (evaluacion_id, usuario_id, estado)
                 VALUES (?, ?, 'en_progreso')"
            );

            $stmt->execute([$evaluacionId, $usuarioId]);
            $intentoId = (int) $pdo->lastInsertId();
        } else {
            $intentoId = (int) $intentoActual['id'];
        }

        $respuestas = $_POST['respuestas'] ?? [];

        if (!is_array($respuestas)) {
            throw new RuntimeException(
                'El formato de las respuestas no es válido.'
            );
        }

        /*
         * Crear una lista de preguntas válidas para impedir que se
         * guarden respuestas para preguntas de otra evaluación.
         */
        $preguntasPorId = [];

        foreach ($preguntas as $pregunta) {
            $preguntasPorId[(int) $pregunta['id']] = $pregunta;
        }

        /*
         * Reemplazar las respuestas guardadas del intento en progreso.
         */
        $stmt = $pdo->prepare(
            'DELETE FROM respuestas_evaluacion
             WHERE intento_id = ?'
        );

        $stmt->execute([$intentoId]);

        $stmtGuardar = $pdo->prepare(
            'INSERT INTO respuestas_evaluacion
                (
                    intento_id,
                    pregunta_id,
                    respuesta_texto,
                    opcion_id,
                    puntos_obtenidos
                )
             VALUES (?, ?, ?, ?, ?)'
        );

        foreach ($preguntas as $pregunta) {
            $preguntaId = (int) $pregunta['id'];

            $respuesta = $respuestas[$preguntaId] ?? [];

            if (!is_array($respuesta)) {
                $respuesta = [];
            }

            $texto = null;
            $opcionId = null;
            $puntosObtenidos = null;

            if ($pregunta['tipo'] === 'escrita') {
                $texto = trim(
                    (string) ($respuesta['texto'] ?? '')
                );

                if (mb_strlen($texto) > 10000) {
                    throw new RuntimeException(
                        'Una respuesta supera el límite de 10000 caracteres.'
                    );
                }

                if ($texto === '') {
                    $texto = null;
                }
            } elseif ($pregunta['tipo'] === 'opcion_multiple') {
                $opcionSeleccionada = filter_var(
                    $respuesta['opcion_id'] ?? null,
                    FILTER_VALIDATE_INT
                );

                if (
                    $opcionSeleccionada !== false &&
                    $opcionSeleccionada !== null
                ) {
                    /*
                     * Comprobar que la opción pertenece realmente
                     * a la pregunta contestada.
                     */
                    $stmtValida = $pdo->prepare(
                        'SELECT id
                         FROM opciones_pregunta
                         WHERE id = ? AND pregunta_id = ?'
                    );

                    $stmtValida->execute([
                        $opcionSeleccionada,
                        $preguntaId
                    ]);

                    $opcionValida = $stmtValida->fetchColumn();

                    if ($opcionValida !== false) {
                        $opcionId = (int) $opcionValida;
                    }
                }
            } else {
                throw new RuntimeException(
                    'Se encontró un tipo de pregunta no válido.'
                );
            }

            $stmtGuardar->execute([
                $intentoId,
                $preguntaId,
                $texto,
                $opcionId,
                $puntosObtenidos
            ]);
        }

        /*
         * Entrega definitiva: corregir automáticamente las preguntas
         * de opción múltiple y marcar el intento como entregado.
         */
        if ($accion === 'entregar') {
            $stmt = $pdo->prepare(
                'SELECT
                    r.id,
                    r.opcion_id,
                    p.puntos,
                    o.es_correcta
                 FROM respuestas_evaluacion r
                 INNER JOIN preguntas_evaluacion p
                    ON p.id = r.pregunta_id
                 LEFT JOIN opciones_pregunta o
                    ON o.id = r.opcion_id
                 WHERE r.intento_id = ?
                   AND p.tipo = ?'
            );

            $stmt->execute([
                $intentoId,
                'opcion_multiple'
            ]);

            $respuestasMultiple = $stmt->fetchAll();

            $stmtActualizar = $pdo->prepare(
                'UPDATE respuestas_evaluacion
                 SET puntos_obtenidos = ?
                 WHERE id = ? AND intento_id = ?'
            );

            foreach ($respuestasMultiple as $respuestaMultiple) {
                $correcta = (
                    $respuestaMultiple['opcion_id'] !== null &&
                    (int) $respuestaMultiple['es_correcta'] === 1
                );

                $puntos = $correcta
                    ? (float) $respuestaMultiple['puntos']
                    : 0.0;

                $stmtActualizar->execute([
                    $puntos,
                    (int) $respuestaMultiple['id'],
                    $intentoId
                ]);
            }

            $stmt = $pdo->prepare(
                "UPDATE intentos_evaluacion
                 SET estado = 'entregado',
                     entregado_en = NOW()
                 WHERE id = ?
                   AND usuario_id = ?
                   AND estado = 'en_progreso'"
            );

            $stmt->execute([$intentoId, $usuarioId]);

            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException(
                    'No se pudo registrar la entrega. Recarga la página.'
                );
            }
        }

        $pdo->commit();

        if ($accion === 'entregar') {
            header(
                'Location: presentar_evaluacion.php?id=' .
                $evaluacionId . '&entregada=1'
            );
        } else {
            header(
                'Location: presentar_evaluacion.php?id=' .
                $evaluacionId . '&guardado=1'
            );
        }

        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log($e->getMessage());

        $error = $e instanceof RuntimeException
            ? $e->getMessage()
            : 'No se pudieron guardar las respuestas. Inténtalo de nuevo.';
    }
}

/*
|--------------------------------------------------------------------------
| Mostrar el estado después de una entrega
|--------------------------------------------------------------------------
*/

if (isset($_GET['entregada'])) {
    exit(
        '<!doctype html>' .
        '<html lang="es">' .
        '<head><meta charset="utf-8">' .
        '<meta name="viewport" content="width=device-width, initial-scale=1">' .
        '<title>Evaluación entregada | Night Owl</title></head>' .
        '<body style="font-family:Arial,sans-serif;max-width:700px;margin:50px auto;padding:20px;">' .
        '<h1>Evaluación entregada</h1>' .
        '<p>Tu entrega quedó registrada correctamente.</p>' .
        '<p>Las preguntas de opción múltiple se corrigen automáticamente. Las preguntas escritas quedan pendientes de revisión del creador.</p>' .
        '<p><a href="grupo.php?id=' . $grupoId . '">Volver al grupo</a></p>' .
        '<p><a href="mis_notas.php">Consultar mis notas</a></p>' .
        '</body></html>'
    );
}

/*
|--------------------------------------------------------------------------
| Cargar respuestas guardadas
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare(
    'SELECT pregunta_id, respuesta_texto, opcion_id
     FROM respuestas_evaluacion
     WHERE intento_id = ?'
);

$stmt->execute([
    $intento ? (int) $intento['id'] : 0
]);

$respuestasGuardadas = [];

foreach ($stmt->fetchAll() as $respuesta) {
    $respuestasGuardadas[(int) $respuesta['pregunta_id']] = $respuesta;
}

/*
|--------------------------------------------------------------------------
| Cargar opciones sin exponer las respuestas correctas
|--------------------------------------------------------------------------
*/

$stmtOpciones = $pdo->prepare(
    'SELECT id, texto
     FROM opciones_pregunta
     WHERE pregunta_id = ?
     ORDER BY orden, id'
);

$opcionesPorPregunta = [];

foreach ($preguntas as $pregunta) {
    if ($pregunta['tipo'] === 'opcion_multiple') {
        $stmtOpciones->execute([(int) $pregunta['id']]);

        $opcionesPorPregunta[(int) $pregunta['id']] =
            $stmtOpciones->fetchAll();
    }
}

?>

<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Presentar evaluación | Night Owl</title>

    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f3f4f8;
            color: #202336;
            margin: 0;
            padding: 24px;
        }

        main {
            max-width: 850px;
            margin: auto;
        }

        .panel {
            background: #fff;
            padding: 22px;
            border-radius: 14px;
            margin-bottom: 18px;
            box-shadow: 0 3px 12px #0000000c;
        }

        .pregunta {
            background: #fff;
            border: 1px solid #dddfee;
            padding: 18px;
            border-radius: 10px;
            margin: 18px 0;
        }

        textarea {
            box-sizing: border-box;
            width: 100%;
            min-height: 110px;
            padding: 10px;
            font: inherit;
            border: 1px solid #cdd0dc;
            border-radius: 8px;
        }

        button,
        .boton {
            display: inline-block;
            border: 0;
            border-radius: 8px;
            padding: 12px 16px;
            background: #3730a3;
            color: #fff;
            font: inherit;
            text-decoration: none;
            cursor: pointer;
            margin: 8px 8px 0 0;
        }

        .entregar {
            background: #166534;
        }

        .error {
            background: #fff0f0;
            padding: 12px;
            border-radius: 8px;
            color: #991b1b;
        }

        .ok {
            background: #e9f9ef;
            padding: 12px;
            border-radius: 8px;
            color: #166534;
        }

        .opcion {
            display: block;
            margin: 12px 0;
            font-weight: normal;
        }

        .muted {
            color: #626779;
        }

        @media (max-width: 600px) {
            body {
                padding: 12px;
            }

            .panel {
                padding: 16px;
            }

            button,
            .boton {
                width: 100%;
                box-sizing: border-box;
                margin-right: 0;
            }
        }
    </style>
</head>

<body>
<main>

    <p>
        <a href="grupo.php?id=<?= $grupoId ?>">
            ← Volver al grupo
        </a>
    </p>

    <section class="panel">

        <h1><?= h($evaluacion['titulo']) ?></h1>

        <p>
            <strong>Grupo:</strong>
            <?= h($evaluacion['grupo_nombre']) ?>
        </p>

        <p><?= nl2br(h($evaluacion['instrucciones'])) ?></p>

        <p>
            Nota mínima para aprobar:
            <?= h($evaluacion['nota_minima']) ?>/100
        </p>

        <p>
            Solo puedes entregar esta evaluación una vez.
            Revisa tus respuestas antes de la entrega definitiva.
        </p>

        <?php if ($evaluacion['estado'] === 'cerrada'): ?>
            <p class="ok">
                Esta evaluación está cerrada para nuevos intentos.
                Como ya habías empezado, puedes continuar y entregar
                el intento que tienes en progreso.
            </p>
        <?php endif; ?>

        <?php if (isset($_GET['guardado'])): ?>
            <p class="ok">
                Tus respuestas se guardaron correctamente.
                Puedes continuar y entregarlas cuando estés listo.
            </p>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <p class="error"><?= h($error) ?></p>
        <?php endif; ?>

    </section>

    <form method="post" id="formEvaluacion">

        <input
            type="hidden"
            name="csrf_token"
            value="<?= h(csrfToken()) ?>"
        >

        <?php foreach ($preguntas as $indice => $pregunta): ?>

            <?php
                $pid = (int) $pregunta['id'];
                $guardada = $respuestasGuardadas[$pid] ?? [];
            ?>

            <section class="pregunta">

                <h2>Pregunta <?= $indice + 1 ?></h2>

                <p><?= nl2br(h($pregunta['enunciado'])) ?></p>

                <p>
                    <small>
                        Puntos posibles: <?= h($pregunta['puntos']) ?>
                    </small>
                </p>

                <?php if ($pregunta['tipo'] === 'escrita'): ?>

                    <label for="respuesta_<?= $pid ?>">
                        Tu respuesta
                    </label>

                    <textarea
                        id="respuesta_<?= $pid ?>"
                        name="respuestas[<?= $pid ?>][texto]"
                        maxlength="10000"
                    ><?= h($guardada['respuesta_texto'] ?? '') ?></textarea>

                <?php else: ?>

                    <?php foreach ($opcionesPorPregunta[$pid] ?? [] as $opcion): ?>

                        <label class="opcion">

                            <input
                                type="radio"
                                name="respuestas[<?= $pid ?>][opcion_id]"
                                value="<?= (int) $opcion['id'] ?>"
                                <?= (
                                    isset($guardada['opcion_id']) &&
                                    (int) $guardada['opcion_id'] === (int) $opcion['id']
                                ) ? 'checked' : '' ?>
                            >

                            <?= h($opcion['texto']) ?>

                        </label>

                    <?php endforeach; ?>

                <?php endif; ?>

            </section>

        <?php endforeach; ?>

        <section class="panel">

            <button
                type="submit"
                name="accion"
                value="guardar"
            >
                Guardar y continuar después
            </button>

            <button
                type="submit"
                name="accion"
                value="entregar"
                class="entregar"
            >
                Entregar evaluación definitivamente
            </button>

        </section>

    </form>

</main>

<script>
document.getElementById('formEvaluacion').addEventListener('submit', function (event) {
    const boton = event.submitter;

    if (boton && boton.value === 'entregar') {
        const confirmado = confirm(
            '¿Estás seguro de entregar la evaluación? Después no podrás cambiar tus respuestas.'
        );

        if (!confirmado) {
            event.preventDefault();
        }
    }
});
</script>

</body>
</html>