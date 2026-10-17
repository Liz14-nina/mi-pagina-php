<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../config/conexion.php';

if (!empty($_SESSION['usuario_id'])) {
    header('Location: ../index.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
$registroCorrecto = isset($_GET['registro'])
    && $_GET['registro'] === 'correcto';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (!is_string($token) ||
        !hash_equals($_SESSION['csrf_token'], $token)) {
        $error = 'La sesión del formulario ha caducado. Recarga la página.';
    } else {
        $correo = trim($_POST['correo'] ?? '');
        $password = $_POST['password'] ?? '';

        if (!filter_var($correo, FILTER_VALIDATE_EMAIL)
            || !is_string($password)) {
            $error = 'El correo o la contraseña son incorrectos.';
        } else {
            try {
                $consulta = $pdo->prepare(
                    'SELECT id, nombre, password_hash
                     FROM usuarios
                     WHERE correo = ?
                     LIMIT 1'
                );
                $consulta->execute([$correo]);
                $usuario = $consulta->fetch();

                if ($usuario &&
                    password_verify($password, $usuario['password_hash'])) {
                    session_regenerate_id(true);

                    $_SESSION['usuario_id'] = (int) $usuario['id'];
                    $_SESSION['usuario_nombre'] = $usuario['nombre'];
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                    header('Location: ../index.php');
                    exit;
                }

                $error = 'El correo o la contraseña son incorrectos.';
            } catch (PDOException $e) {
                error_log($e->getMessage());
                $error = 'No se pudo iniciar sesión. Inténtalo de nuevo.';
            }
        }
    }
}

function escapar(string $texto): string {
    return htmlspecialchars($texto, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar sesión | NIGHT OWL</title>
    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            font-family: Arial, sans-serif;
            background: #eef4ff;
            color: #172554;
        }

        .tarjeta {
            width: 100%;
            max-width: 420px;
            padding: 32px;
            background: white;
            border-radius: 18px;
            box-shadow: 0 10px 35px rgba(30, 64, 175, 0.12);
        }

        h1 {
            margin: 0 0 8px;
            color: #1d4ed8;
            text-align: center;
        }

        .subtitulo {
            margin-bottom: 24px;
            color: #64748b;
            text-align: center;
        }

        label {
            display: block;
            margin: 18px 0 6px;
            font-weight: bold;
        }

        input {
            width: 100%;
            padding: 12px;
            border: 1px solid #cbd5e1;
            border-radius: 9px;
            font-size: 16px;
        }

        input:focus {
            outline: 2px solid #93c5fd;
            border-color: #2563eb;
        }

        button {
            width: 100%;
            margin-top: 22px;
            padding: 13px;
            border: 0;
            border-radius: 9px;
            background: #2563eb;
            color: white;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
        }

        button:hover { background: #1d4ed8; }

        .error, .exito {
            padding: 12px;
            border-radius: 8px;
        }

        .error {
            background: #fee2e2;
            color: #991b1b;
        }

        .exito {
            background: #dcfce7;
            color: #166534;
        }

        .enlace {
            margin-top: 20px;
            text-align: center;
        }

        a { color: #1d4ed8; }
    </style>
</head>
<body>
    <main class="tarjeta">
        <h1>NIGHT OWL</h1>
        <p class="subtitulo">Tu espacio para aprender y avanzar.</p>

        <?php if ($registroCorrecto): ?>
            <p class="exito" role="status">
                ¡Cuenta creada! Ahora puedes iniciar sesión.
            </p>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <p class="error" role="alert"><?= escapar($error) ?></p>
        <?php endif; ?>

        <form method="POST" action="">
            <input
                type="hidden"
                name="csrf_token"
                value="<?= escapar($_SESSION['csrf_token']) ?>"
            >

            <label for="correo">Correo electrónico</label>
            <input
                id="correo"
                name="correo"
                type="email"
                maxlength="190"
                autocomplete="email"
                required
            >

            <label for="password">Contraseña</label>
            <input
                id="password"
                name="password"
                type="password"
                autocomplete="current-password"
                required
            >

            <button type="submit">Iniciar sesión</button>
        </form>

        <p class="enlace">
            ¿Todavía no tienes cuenta?
            <a href="registro.php">Crear una cuenta</a>
        </p>
    </main>
</body>
</html>
