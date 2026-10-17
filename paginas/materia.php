<?php
declare(strict_types=1);

session_start();

if (!isset($_SESSION['usuario_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

require_once __DIR__ . '/../config/conexion.php';

$materiaId = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT
);

if (!$materiaId || $materiaId < 1) {
    header('Location: ../index.php');
    exit;
}

// Buscar la materia seleccionada.
$stmt = $pdo->prepare(
    'SELECT id, nombre FROM materias WHERE id = ?'
);
$stmt->execute([$materiaId]);
$materia = $stmt->fetch();

if (!$materia) {
    http_response_code(404);
    exit('La materia que buscas no existe.');
}

// Buscar exclusivamente los grupos de esa materia.
$stmt = $pdo->prepare(
    'SELECT
        g.id,
        g.nombre,
        g.grado,
        g.codigo_invitacion,
        u.nombre AS nombre_creador,
        (SELECT COUNT(*)
         FROM integrantes_grupo ig
         WHERE ig.grupo_id = g.id) AS total_integrantes
     FROM grupos g
     INNER JOIN usuarios u ON u.id = g.creador_id
     WHERE g.materia_id = ?
     ORDER BY g.nombre'
);
$stmt->execute([$materiaId]);
$grupos = $stmt->fetchAll();

function escapar(mixed $valor): string
{
    return htmlspecialchars(
        (string) $valor,
        ENT_QUOTES,
        'UTF-8'
    );
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= escapar($materia['nombre']) ?> | Night Owl</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f4f6fb;
            color: #20243a;
        }

        header {
            background: #20243a;
            color: white;
            padding: 20px 6%;
        }

        header a {
            color: white;
            text-decoration: none;
        }

        main {
            width: min(1000px, 90%);
            margin: 35px auto;
        }

        .grupo {
            background: white;
            padding: 22px;
            border-radius: 14px;
            border: 1px solid #e1e5ef;
            margin-bottom: 16px;
        }

        .grupo h3 {
            margin-top: 0;
        }

        .boton {
            display: inline-block;
            background: #6254ce;
            color: white;
            padding: 10px 15px;
            border-radius: 8px;
            text-decoration: none;
        }

        .vacio {
            background: white;
            padding: 22px;
            border-radius: 12px;
        }

        .volver {
            display: inline-block;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>

<header>
    <a href="../index.php">🦉 Night Owl · Inicio</a>
</header>

<main>
    <a class="volver" href="../index.php">← Volver a las materias</a>

    <h1><?= escapar($materia['nombre']) ?></h1>
    <p>Estos son los grupos de estudio asociados a esta materia.</p>

    <p>
        <a class="boton"
           href="grupos.php?materia_id=<?= (int) $materia['id'] ?>">
            Buscar o crear un grupo
        </a>
    </p>

    <h2>Grupos de estudio</h2>

    <?php if (empty($grupos)): ?>
        <div class="vacio">
            <p>Aún no hay grupos para esta materia.</p>
            <p>Puedes explorar o crear uno desde la sección de grupos.</p>
        </div>
    <?php else: ?>
        <?php foreach ($grupos as $grupo): ?>
            <article class="grupo">
                <h3><?= escapar($grupo['nombre']) ?></h3>

                <p>
                    <strong>Grado:</strong>
                    <?= escapar($grupo['grado']) ?>
                </p>

                <p>
                    <strong>Creador:</strong>
                    <?= escapar($grupo['nombre_creador']) ?>
                </p>

                <p>
                    <strong>Integrantes:</strong>
                    <?= (int) $grupo['total_integrantes'] ?>
                </p>

                <?php if (!empty($grupo['codigo_invitacion'])): ?>
                    <p>
                        <strong>Código:</strong>
                        <?= escapar($grupo['codigo_invitacion']) ?>
                    </p>
                <?php endif; ?>

                <a class="boton"
                   href="grupo.php?id=<?= (int) $grupo['id'] ?>">
                    Ver grupo
                </a>
            </article>
        <?php endforeach; ?>
    <?php endif; ?>
</main>

</body>
</html>
