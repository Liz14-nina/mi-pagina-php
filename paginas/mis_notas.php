<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/conexion.php';

if (!isset($_SESSION['usuario_id'])) {
    header('Location: ../index.php');
    exit;
}

$usuarioId = (int) $_SESSION['usuario_id'];

function hMisNotas($valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

/*
 * Obtener los grupos en los que participa el estudiante.
 */
$sqlGrupos = "
    SELECT
        g.id,
        g.nombre,
        m.nombre AS materia
    FROM integrantes_grupo ig
    INNER JOIN grupos g ON g.id = ig.grupo_id
    INNER JOIN materias m ON m.id = g.materia_id
    WHERE ig.usuario_id = ?
    ORDER BY m.nombre, g.nombre
";

$stmtGrupos = $pdo->prepare($sqlGrupos);
$stmtGrupos->execute([$usuarioId]);
$grupos = $stmtGrupos->fetchAll();

$datosGrupos = [];

$sqlEvaluaciones = "
    SELECT
        e.id,
        e.titulo,
        e.estado,
        e.nota_minima,
        i.estado AS estado_intento,
        i.nota
    FROM evaluaciones e
    LEFT JOIN intentos_evaluacion i
        ON i.evaluacion_id = e.id
        AND i.usuario_id = ?
    WHERE e.grupo_id = ?
      AND e.estado IN ('publicada', 'cerrada')
    ORDER BY e.creada_en, e.id
";

$stmtEvaluaciones = $pdo->prepare($sqlEvaluaciones);

foreach ($grupos as $grupo) {
    $stmtEvaluaciones->execute([
        $usuarioId,
        (int) $grupo['id']
    ]);

    $evaluaciones = $stmtEvaluaciones->fetchAll();

    $estadoCurso = 'Aprobado';

    foreach ($evaluaciones as $evaluacion) {
        if (
            $evaluacion['estado_intento'] !== 'calificado'
            || $evaluacion['nota'] === null
        ) {
            if ($estadoCurso !== 'No aprobado') {
                $estadoCurso = 'Pendiente';
            }

            continue;
        }

        if ((float) $evaluacion['nota'] < (float) $evaluacion['nota_minima']) {
            $estadoCurso = 'No aprobado';
        }
    }

    $datosGrupos[] = [
        'grupo' => $grupo,
        'evaluaciones' => $evaluaciones,
        'estado_curso' => $evaluaciones
            ? $estadoCurso
            : 'Sin evaluaciones'
    ];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mis notas - Night Owl</title>

    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f3f4f8;
            color: #222;
            margin: 0;
            padding: 24px;
        }

        .contenedor {
            max-width: 950px;
            margin: auto;
        }

        .grupo, .evaluacion {
            background: white;
            padding: 18px;
            margin: 16px 0;
            border-radius: 12px;
            box-shadow: 0 2px 8px #00000010;
        }

        .evaluacion {
            border: 1px solid #e5e7eb;
            box-shadow: none;
        }

        .boton {
            display: inline-block;
            padding: 10px 15px;
            border-radius: 8px;
            background: #25254b;
            color: white;
            text-decoration: none;
        }

        .estado {
            font-weight: bold;
        }

        .nota {
            font-size: 1.2rem;
            font-weight: bold;
        }

        .vacio {
            color: #666;
        }
    </style>
</head>

<body>
<div class="contenedor">

    <h1>Mis notas</h1>
    <p>Consulta tus calificaciones y el estado de tus cursos.</p>

    <p>
        <a class="boton" href="../index.php">Volver al inicio</a>
    </p>

    <?php if (!$datosGrupos): ?>
        <div class="grupo">
            <p class="vacio">
                Todavía no formas parte de ningún grupo.
            </p>
        </div>
    <?php endif; ?>

    <?php foreach ($datosGrupos as $datos): ?>
        <?php
        $grupo = $datos['grupo'];
        $evaluaciones = $datos['evaluaciones'];
        ?>

        <section class="grupo">
            <h2><?= hMisNotas($grupo['nombre']) ?></h2>

            <p>
                Materia: <?= hMisNotas($grupo['materia']) ?>
            </p>

            <p>
                Estado del curso:
                <span class="estado">
                    <?= hMisNotas($datos['estado_curso']) ?>
                </span>
            </p>

            <?php if (!$evaluaciones): ?>
                <p class="vacio">
                    Este grupo todavía no tiene evaluaciones publicadas.
                </p>
            <?php endif; ?>

            <?php foreach ($evaluaciones as $evaluacion): ?>
                <article class="evaluacion">
                    <h3><?= hMisNotas($evaluacion['titulo']) ?></h3>

                    <?php if (
                        $evaluacion['estado_intento'] !== 'calificado'
                        || $evaluacion['nota'] === null
                    ): ?>

                        <p>Calificación: Pendiente</p>

                        <?php if (
                            $evaluacion['estado_intento'] === 'entregado'
                        ): ?>
                            <p>
                                Tu evaluación fue entregada y está pendiente
                                de calificación.
                            </p>
                        <?php elseif (
                            $evaluacion['estado_intento'] === 'en_progreso'
                        ): ?>
                            <p>
                                Tienes una evaluación sin entregar.
                            </p>
                        <?php else: ?>
                            <p>
                                Aún no tienes una calificación registrada.
                            </p>
                        <?php endif; ?>

                    <?php else: ?>

                        <?php
                        $nota = (float) $evaluacion['nota'];
                        $minima = (float) $evaluacion['nota_minima'];
                        $aprobada = $nota >= $minima;
                        ?>

                        <p class="nota">
                            Nota: <?= number_format($nota, 2) ?>/100
                        </p>

                        <p>
                            Nota mínima para aprobar:
                            <?= number_format($minima, 2) ?>/100
                        </p>

                        <p>
                            Resultado:
                            <strong>
                                <?= $aprobada ? 'Aprobada' : 'No aprobada' ?>
                            </strong>
                        </p>

                    <?php endif; ?>
                </article>
            <?php endforeach; ?>

        </section>
    <?php endforeach; ?>

</div>
</body>
</html>
