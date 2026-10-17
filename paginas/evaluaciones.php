<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../config/conexion.php';

if (!isset($_SESSION['usuario_id'])) {
    header('Location: ../index.php');
    exit;
}

$usuarioId = (int) $_SESSION['usuario_id'];
$grupoId = filter_input(INPUT_GET, 'grupo_id', FILTER_VALIDATE_INT);

if (!$grupoId || $grupoId < 1) {
    http_response_code(400);
    exit('Grupo no válido.');
}

function escapar($valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

function tokenCsrf(): string
{
    if (empty($_SESSION['csrf_evaluaciones'])) {
        $_SESSION['csrf_evaluaciones'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_evaluaciones'];
}

function comprobarCsrf(): void
{
    $token = (string) ($_POST['csrf_token'] ?? '');

    if (
        empty($_SESSION['csrf_evaluaciones']) ||
        !hash_equals($_SESSION['csrf_evaluaciones'], $token)
    ) {
        http_response_code(403);
        exit('La sesión del formulario venció. Recarga la página e inténtalo otra vez.');
    }
}

/* Obtener el grupo */
$stmt = $pdo->prepare(
    'SELECT id, nombre, creador_id
     FROM grupos
     WHERE id = ?'
);
$stmt->execute([$grupoId]);
$grupo = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$grupo) {
    http_response_code(404);
    exit('No se encontró el grupo.');
}

/* Comprobar permisos del usuario */
$stmt = $pdo->prepare(
    'SELECT 1
     FROM integrantes_grupo
     WHERE grupo_id = ? AND usuario_id = ?'
);
$stmt->execute([$grupoId, $usuarioId]);
$esIntegrante = (bool) $stmt->fetchColumn();

$esCreador = (int) $grupo['creador_id'] === $usuarioId;

if (!$esCreador && !$esIntegrante) {
    http_response_code(403);
    exit('No tienes permiso para ver las evaluaciones de este grupo.');
}

$errores = [];
$mensaje = '';

/* Crear una evaluación: solo el creador */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    comprobarCsrf();

    if (!$esCreador) {
        http_response_code(403);
        exit('Solo el creador del grupo puede crear evaluaciones.');
    }

    $titulo = trim((string) ($_POST['titulo'] ?? ''));
    $instrucciones = trim((string) ($_POST['instrucciones'] ?? ''));
    $notaMinimaTexto = trim((string) ($_POST['nota_minima'] ?? '70'));
    $preguntas = $_POST['preguntas'] ?? [];

    if ($titulo === '' || mb_strlen($titulo) > 150) {
        $errores[] = 'El título es obligatorio y no puede superar 150 caracteres.';
    }

    if ($instrucciones === '') {
        $errores[] = 'Escribe las instrucciones de la evaluación.';
    }

    if (
        $notaMinimaTexto === '' ||
        !is_numeric($notaMinimaTexto) ||
        (float) $notaMinimaTexto < 0 ||
        (float) $notaMinimaTexto > 100
    ) {
        $errores[] = 'La nota mínima debe estar entre 0 y 100.';
    }

    if (
        !is_array($preguntas) ||
        count($preguntas) < 1 ||
        count($preguntas) > 50
    ) {
        $errores[] = 'Agrega entre 1 y 50 preguntas.';
    }

    $preguntasValidas = [];

    if (is_array($preguntas)) {
        foreach ($preguntas as $pregunta) {
            if (!is_array($pregunta)) {
                $errores[] = 'Una pregunta tiene un formato no válido.';
                continue;
            }

            $enunciado = trim((string) ($pregunta['enunciado'] ?? ''));
            $tipo = (string) ($pregunta['tipo'] ?? 'escrita');
            $puntosTexto = trim((string) ($pregunta['puntos'] ?? '1'));

            if ($enunciado === '' || mb_strlen($enunciado) > 5000) {
                $errores[] = 'Cada pregunta debe tener un enunciado de hasta 5000 caracteres.';
                continue;
            }

            if (!in_array($tipo, ['escrita', 'opcion_multiple'], true)) {
                $errores[] = 'Selecciona un tipo de pregunta válido.';
                continue;
            }

            if (
                $puntosTexto === '' ||
                !is_numeric($puntosTexto) ||
                (float) $puntosTexto <= 0 ||
                (float) $puntosTexto > 10000
            ) {
                $errores[] = 'Los puntos de cada pregunta deben ser mayores que cero.';
                continue;
            }

            $opciones = [];
            $correcta = null;

            if ($tipo === 'opcion_multiple') {
                $opcionesTexto = $pregunta['opciones'] ?? [];
                $correctaTexto = (string) ($pregunta['correcta'] ?? '');

                if (!is_array($opcionesTexto) || count($opcionesTexto) !== 4) {
                    $errores[] = 'Cada pregunta de opción múltiple debe tener cuatro opciones.';
                    continue;
                }

                $opcionesValidas = true;

                foreach ($opcionesTexto as $opcionTexto) {
                    $opcionTexto = trim((string) $opcionTexto);

                    if ($opcionTexto === '' || mb_strlen($opcionTexto) > 500) {
                        $errores[] = 'Completa las cuatro opciones. Máximo 500 caracteres por opción.';
                        $opcionesValidas = false;
                        break;
                    }

                    $opciones[] = $opcionTexto;
                }

                if (!$opcionesValidas) {
                    continue;
                }

                if (!in_array($correctaTexto, ['0', '1', '2', '3'], true)) {
                    $errores[] = 'Selecciona la respuesta correcta.';
                    continue;
                }

                $correcta = (int) $correctaTexto;
            }

            $preguntasValidas[] = [
                'enunciado' => $enunciado,
                'tipo' => $tipo,
                'puntos' => round((float) $puntosTexto, 2),
                'opciones' => $opciones,
                'correcta' => $correcta,
            ];
        }
    }

    if (
        !$errores &&
        count($preguntasValidas) !== count($preguntas)
    ) {
        $errores[] = 'Revisa las preguntas que tienen errores.';
    }

    /* Guardar evaluación y preguntas */
    if (!$errores) {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                "INSERT INTO evaluaciones
                    (grupo_id, titulo, instrucciones, estado,
                     nota_maxima, nota_minima, creador_id)
                 VALUES (?, ?, ?, 'borrador', 100, ?, ?)"
            );

            $stmt->execute([
                $grupoId,
                $titulo,
                $instrucciones,
                round((float) $notaMinimaTexto, 2),
                $usuarioId
            ]);

            $evaluacionId = (int) $pdo->lastInsertId();

            $stmtPregunta = $pdo->prepare(
                'INSERT INTO preguntas_evaluacion
                    (evaluacion_id, enunciado, tipo, puntos, orden)
                 VALUES (?, ?, ?, ?, ?)'
            );

            $stmtOpcion = $pdo->prepare(
                'INSERT INTO opciones_pregunta
                    (pregunta_id, texto, es_correcta, orden)
                 VALUES (?, ?, ?, ?)'
            );

            foreach ($preguntasValidas as $indice => $pregunta) {
                $stmtPregunta->execute([
                    $evaluacionId,
                    $pregunta['enunciado'],
                    $pregunta['tipo'],
                    $pregunta['puntos'],
                    $indice + 1
                ]);

                $preguntaId = (int) $pdo->lastInsertId();

                foreach ($pregunta['opciones'] as $i => $opcion) {
                    $stmtOpcion->execute([
                        $preguntaId,
                        $opcion,
                        $i === $pregunta['correcta'] ? 1 : 0,
                        $i + 1
                    ]);
                }
            }

            $pdo->commit();

            header(
                'Location: evaluaciones.php?grupo_id=' .
                $grupoId . '&creada=1'
            );
            exit;

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log($e->getMessage());
            $errores[] = 'No se pudo guardar la evaluación. Revisa la configuración de la base de datos.';
        }
    }
}

if (isset($_GET['creada'])) {
    $mensaje = 'La evaluación se guardó como borrador. Todavía no está publicada.';
}

/*
 * LISTAR EVALUACIONES
 *
 * Creador: ve todos los estados de su grupo.
 * Estudiante: ve únicamente las publicadas de su grupo.
 */
if ($esCreador) {
    $stmt = $pdo->prepare(
        'SELECT
            e.id,
            e.titulo,
            e.estado,
            e.nota_minima,
            e.creada_en,
            (
                SELECT COUNT(*)
                FROM preguntas_evaluacion p
                WHERE p.evaluacion_id = e.id
            ) AS total_preguntas
         FROM evaluaciones e
         WHERE e.grupo_id = ?
         ORDER BY e.creada_en DESC'
    );
} else {
    $stmt = $pdo->prepare(
        "SELECT
            e.id,
            e.titulo,
            e.estado,
            e.nota_minima,
            e.creada_en,
            (
                SELECT COUNT(*)
                FROM preguntas_evaluacion p
                WHERE p.evaluacion_id = e.id
            ) AS total_preguntas
         FROM evaluaciones e
         WHERE e.grupo_id = ?
           AND e.estado = 'publicada'
         ORDER BY e.creada_en DESC"
    );
}

$stmt->execute([$grupoId]);
$evaluaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Evaluaciones | Night Owl</title>

    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f3f4f8;
            color: #202336;
            margin: 0;
            padding: 24px;
        }

        main {
            max-width: 900px;
            margin: auto;
        }

        .panel {
            background: #fff;
            padding: 22px;
            border-radius: 14px;
            margin-bottom: 20px;
            box-shadow: 0 3px 12px #0000000c;
        }

        h1, h2 {
            margin-top: 0;
        }

        label {
            display: block;
            font-weight: bold;
            margin: 12px 0 6px;
        }

        input, textarea, select {
            box-sizing: border-box;
            width: 100%;
            padding: 10px;
            border: 1px solid #cdd0dc;
            border-radius: 8px;
            font: inherit;
        }

        textarea {
            min-height: 90px;
        }

        button, .boton {
            display: inline-block;
            border: 0;
            border-radius: 8px;
            padding: 11px 16px;
            background: #3730a3;
            color: white;
            text-decoration: none;
            cursor: pointer;
            font: inherit;
            margin: 4px 0;
        }

        .pregunta {
            border: 1px solid #dddfee;
            padding: 16px;
            border-radius: 10px;
            margin: 16px 0;
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

        .muted {
            color: #626779;
        }

        .fila {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .fila > * {
            flex: 1;
            min-width: 180px;
        }

        @media (max-width: 600px) {
            body {
                padding: 12px;
            }

            .panel {
                padding: 16px;
            }
        }
    </style>
</head>

<body>
<main>
    <p>
        <a href="grupo.php?id=<?= (int) $grupoId ?>">
            ← Volver al grupo
        </a>
    </p>

    <section class="panel">
        <h1>Evaluaciones: <?= escapar($grupo['nombre']) ?></h1>

        <p class="muted">
            Cada evaluación se califica sobre 100.
            El creador establece la nota mínima para aprobar.
        </p>

        <p>
            <a class="boton" href="mis_notas.php">
                Consultar mis notas
            </a>
        </p>

        <?php if ($mensaje): ?>
            <p class="ok"><?= escapar($mensaje) ?></p>
        <?php endif; ?>

        <?php if ($errores): ?>
            <div class="error">
                <strong>Revisa lo siguiente:</strong>
                <ul>
                    <?php foreach ($errores as $error): ?>
                        <li><?= escapar($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($esCreador): ?>
            <h2>Crear evaluación</h2>

            <form method="post">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= escapar(tokenCsrf()) ?>"
                >

                <label for="titulo">Título</label>
                <input
                    id="titulo"
                    name="titulo"
                    maxlength="150"
                    required
                >

                <label for="instrucciones">Instrucciones</label>
                <textarea
                    id="instrucciones"
                    name="instrucciones"
                    required
                ></textarea>

                <label for="nota_minima">
                    Nota mínima para aprobar (0–100)
                </label>
                <input
                    id="nota_minima"
                    type="number"
                    name="nota_minima"
                    min="0"
                    max="100"
                    step="0.01"
                    value="70"
                    required
                >

                <div id="preguntas">
                    <div class="pregunta">
                        <h3>Pregunta 1</h3>

                        <label>Enunciado</label>
                        <textarea
                            name="preguntas[0][enunciado]"
                            maxlength="5000"
                            required
                        ></textarea>

                        <div class="fila">
                            <div>
                                <label>Tipo</label>
                                <select
                                    name="preguntas[0][tipo]"
                                    class="tipo-pregunta"
                                >
                                    <option value="escrita">
                                        Respuesta escrita
                                    </option>
                                    <option value="opcion_multiple">
                                        Opción múltiple
                                    </option>
                                </select>
                            </div>

                            <div>
                                <label>Puntos</label>
                                <input
                                    type="number"
                                    name="preguntas[0][puntos]"
                                    min="0.01"
                                    max="10000"
                                    step="0.01"
                                    value="1"
                                    required
                                >
                            </div>
                        </div>

                        <div class="opciones" hidden>
                            <?php for ($i = 0; $i < 4; $i++): ?>
                                <label>Opción <?= $i + 1 ?></label>
                                <input
                                    name="preguntas[0][opciones][]"
                                    maxlength="500"
                                    disabled
                                >
                            <?php endfor; ?>

                            <label>Respuesta correcta</label>
                            <select name="preguntas[0][correcta]" disabled>
                                <option value="0">Opción 1</option>
                                <option value="1">Opción 2</option>
                                <option value="2">Opción 3</option>
                                <option value="3">Opción 4</option>
                            </select>
                        </div>
                    </div>
                </div>

                <button type="button" id="agregar">
                    + Agregar pregunta
                </button>

                <button type="submit">
                    Guardar como borrador
                </button>
            </form>
        <?php else: ?>
            <p>Solo el creador del grupo puede crear evaluaciones.</p>
        <?php endif; ?>
    </section>

    <section class="panel">
        <h2>Evaluaciones del grupo</h2>

        <?php if (!$evaluaciones): ?>
            <p>
                <?php if ($esCreador): ?>
                    Todavía no hay evaluaciones en este grupo. Puedes crear una nueva arriba.
                <?php else: ?>
                    No hay evaluaciones publicadas disponibles en este grupo por ahora.
                <?php endif; ?>
            </p>
        <?php else: ?>
            <?php foreach ($evaluaciones as $evaluacion): ?>
                <article class="pregunta">
                    <h3><?= escapar($evaluacion['titulo']) ?></h3>

                    <p>
                        Estado:
                        <strong><?= escapar($evaluacion['estado']) ?></strong>
                    </p>

                    <p>
                        Nota mínima:
                        <?= escapar($evaluacion['nota_minima']) ?>/100
                    </p>

                    <p>
                        Preguntas:
                        <?= (int) $evaluacion['total_preguntas'] ?>
                    </p>

                    <p class="muted">
                        Creada: <?= escapar($evaluacion['creada_en']) ?>
                    </p>

                    <?php if ($esCreador): ?>
                        <p>
                            <a
                                class="boton"
                                href="gestionar_evaluacion.php?id=<?= (int) $evaluacion['id'] ?>"
                            >
                                Gestionar evaluación
                            </a>
                        </p>

                        <p>
                            <a
                                class="boton"
                                href="calificar_evaluacion.php?id=<?= (int) $evaluacion['id'] ?>"
                            >
                                Calificar entregas
                            </a>
                        </p>

                    <?php elseif ($evaluacion['estado'] === 'publicada'): ?>
                        <p>
                            <a
                                class="boton"
                                href="presentar_evaluacion.php?id=<?= (int) $evaluacion['id'] ?>"
                            >
                                Presentar evaluación
                            </a>
                        </p>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>
</main>

<script>
(() => {
    const contenedor = document.getElementById('preguntas');
    const boton = document.getElementById('agregar');

    if (!contenedor || !boton) {
        return;
    }

    let contador = 1;

    function configurarPregunta(bloque) {
        const tipo = bloque.querySelector('.tipo-pregunta');
        const opciones = bloque.querySelector('.opciones');

        function actualizar() {
            const multiple = tipo.value === 'opcion_multiple';
            opciones.hidden = !multiple;

            opciones.querySelectorAll('input, select').forEach(campo => {
                campo.required = multiple;
                campo.disabled = !multiple;
            });
        }

        tipo.addEventListener('change', actualizar);
        actualizar();
    }

    configurarPregunta(contenedor.querySelector('.pregunta'));

    boton.addEventListener('click', () => {
        if (contador >= 50) {
            alert('Puedes agregar un máximo de 50 preguntas.');
            return;
        }

        const indice = contador++;
        const bloque = document.createElement('div');
        bloque.className = 'pregunta';

        const titulo = document.createElement('h3');
        titulo.textContent = 'Pregunta ' + (indice + 1);
        bloque.appendChild(titulo);

        const labelEnunciado = document.createElement('label');
        labelEnunciado.textContent = 'Enunciado';

        const enunciado = document.createElement('textarea');
        enunciado.name = `preguntas[${indice}][enunciado]`;
        enunciado.maxLength = 5000;
        enunciado.required = true;

        bloque.append(labelEnunciado, enunciado);

        const fila = document.createElement('div');
        fila.className = 'fila';

        const tipoCont = document.createElement('div');
        const tipoLabel = document.createElement('label');
        tipoLabel.textContent = 'Tipo';

        const tipo = document.createElement('select');
        tipo.name = `preguntas[${indice}][tipo]`;
        tipo.className = 'tipo-pregunta';

        const escrita = document.createElement('option');
        escrita.value = 'escrita';
        escrita.textContent = 'Respuesta escrita';

        const multiple = document.createElement('option');
        multiple.value = 'opcion_multiple';
        multiple.textContent = 'Opción múltiple';

        tipo.append(escrita, multiple);
        tipoCont.append(tipoLabel, tipo);

        const puntosCont = document.createElement('div');
        const puntosLabel = document.createElement('label');
        puntosLabel.textContent = 'Puntos';

        const puntos = document.createElement('input');
        puntos.type = 'number';
        puntos.name = `preguntas[${indice}][puntos]`;
        puntos.min = '0.01';
        puntos.max = '10000';
        puntos.step = '0.01';
        puntos.value = '1';
        puntos.required = true;

        puntosCont.append(puntosLabel, puntos);
        fila.append(tipoCont, puntosCont);
        bloque.appendChild(fila);

        const opciones = document.createElement('div');
        opciones.className = 'opciones';
        opciones.hidden = true;

        for (let i = 0; i < 4; i++) {
            const label = document.createElement('label');
            label.textContent = 'Opción ' + (i + 1);

            const input = document.createElement('input');
            input.name = `preguntas[${indice}][opciones][]`;
            input.maxLength = 500;
            input.disabled = true;

            opciones.append(label, input);
        }

        const labelCorrecta = document.createElement('label');
        labelCorrecta.textContent = 'Respuesta correcta';

        const correcta = document.createElement('select');
        correcta.name = `preguntas[${indice}][correcta]`;
        correcta.disabled = true;

        for (let i = 0; i < 4; i++) {
            const opcion = document.createElement('option');
            opcion.value = String(i);
            opcion.textContent = 'Opción ' + (i + 1);
            correcta.appendChild(opcion);
        }

        opciones.append(labelCorrecta, correcta);
        bloque.appendChild(opciones);

        contenedor.appendChild(bloque);
        configurarPregunta(bloque);
    });
})();
</script>
</body>
</html>
