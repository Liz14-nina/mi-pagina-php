<?php
declare(strict_types= 1);

session_start();

if (!isset($_SESSION['usuario_id'])) {
    header('Location: auth/login.php');
    exit;
}

require_once __DIR__ . '/config/conexion.php';

$nombre = htmlspecialchars(
    $_SESSION['usuario_nombre'] ?? 'Estudiante',
    ENT_QUOTES,
    'UTF-8'
);

$stmt = $pdo->query(
    'SELECT id, nombre FROM materias ORDER BY nombre'
);
$materias = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Night Owl | Inicio</title>
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
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        header h1 {
            margin: 0;
        }

        nav {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
        }

        nav a {
            color: white;
            text-decoration: none;
        }

        nav a:hover {
            text-decoration: underline;
        }

        main {
            width: min(1100px, 90%);
            margin: 35px auto;
        }

        .bienvenida {
            margin-bottom: 30px;
        }

        .rejilla {
            display: grid;
            grid-template-columns:
                repeat(auto-fit, minmax(210px, 1fr));
            gap: 18px;
        }

        .tarjeta {
            display: block;
            padding: 23px;
            background: white;
            border-radius: 14px;
            border: 1px solid #e1e5ef;
            text-decoration: none;
            color: #20243a;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .tarjeta:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 22px #20243a12;
        }

        .tarjeta h3 {
            margin-top: 0;
        }

        .materia {
            border-top: 4px solid #7164d8;
        }

        .seccion {
            margin-top: 38px;
        }

        .vacio {
            background: white;
            padding: 20px;
            border-radius: 12px;
        }

        footer {
            text-align: center;
            padding: 25px;
            color: #686d80;
        }
    </style>
</head>
<body>

<header>
    <h1>🦉 Night Owl</h1>

    <nav>
        <a href="index.php">Inicio</a>
        <a href="#materias">Materias</a>
        <a href="paginas/grupos.php">Grupos</a>
        <a href="paginas/horarios.php">Horarios</a>
        <a href="paginas/cuenta.php">Mi cuenta</a>
        <a href="auth/cerrar_sesion.php">Cerrar sesión</a>
    </nav>
</header>

<main>
    <section class="bienvenida">
        <h2>¡Hola, <?= $nombre ?>!</h2>
        <p>Organiza tus materias, encuentra grupos y planifica tus sesiones de estudio.</p>
    </section>

    <section id="materias">
        <h2>Mis materias</h2>

        <?php if (empty($materias)): ?>
            <div class="vacio">
                Todavía no hay materias registradas en la base de datos.
            </div>
        <?php else: ?>
            <div class="rejilla">
                <?php foreach ($materias as $materia): ?>
                    <a class="tarjeta materia"
                       href="paginas/materia.php?id=<?= (int) $materia['id'] ?>">
                        <h3>
                            <?= htmlspecialchars(
                                $materia['nombre'],
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </h3>
                        <p>Ver grupos y contenido de esta materia.</p>
                        <span>Explorar materia →</span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="seccion">
        <h2>Mi espacio de estudio</h2>

        <div class="rejilla">
            <a class="tarjeta" href="paginas/grupos.php">
                <h3>👥 Grupos de estudio</h3>
                <p>Explora grupos, busca códigos y gestiona solicitudes.</p>
            </a>

            <a class="tarjeta" href="paginas/horarios.php">
                <h3>📅 Horarios</h3>
                <p>Organiza tus sesiones de estudio.</p>
            </a>

            <a class="tarjeta" href="paginas/cuenta.php">
                <h3>👤 Mi cuenta</h3>
                <p>Consulta tus datos personales.</p>
            </a>
        </div>
    </section>
</main>

<footer>
    Night Owl · Tu espacio para aprender
</footer>

</body>
</html>