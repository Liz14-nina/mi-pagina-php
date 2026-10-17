<?php
declare(strict_types=1);

// El resto de tu código continúa aquí

session_start();
require_once __DIR__ . '/../config/conexion.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (!is_string($token) ||
        !hash_equals($_SESSION['csrf_token'], $token)) {
        $error = 'La sesión del formulario ha caducado. Recarga la página e inténtalo de nuevo.';
    } else {
        $nombre = trim($_POST['nombre'] ?? '');
        $edadTexto = trim($_POST['edad'] ?? '');
        $correo = trim($_POST['correo'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirmacion = $_POST['confirmacion'] ?? '';

        $edad = filter_var($edadTexto, FILTER_VALIDATE_INT);

        if ($nombre === '' || strlen($nombre) > 100) {
            $error = 'Escribe un nombre de hasta 100 caracteres.';
        } elseif ($edad === false || $edad < 1 || $edad > 120) {
            $error = 'Escribe una edad válida.';
        } elseif (!filter_var($correo, FILTER_VALIDATE_EMAIL)
            || strlen($correo) > 190) {
            $error = 'Escribe un correo electrónico válido.';
        } elseif (!is_string($password) || strlen($password) < 10) {
            $error = 'La contraseña debe tener al menos 10 caracteres.';
        } elseif (!is_string($confirmacion)
            || $password !== $confirmacion) {
            $error = 'Las contraseñas no coinciden.';
        } else {
            try {
                $consulta = $pdo->prepare(
                    'SELECT id FROM usuarios WHERE correo = ?'
                );
                $consulta->execute([$correo]);

                if ($consulta->fetch()) {
                    $error = 'Ya existe una cuenta con ese correo.';
                } else {
                    $hash = password_hash($password, PASSWORD_DEFAULT);

                    $consulta = $pdo->prepare(
                        'INSERT INTO usuarios
                         (nombre, edad, correo, password_hash)
                         VALUES (?, ?, ?, ?)'
                    );

                    $consulta->execute([
                        $nombre,
                        $edad,
                        $correo,
                        $hash
                    ]);

                    session_regenerate_id(true);
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                    header('Location: login.php?registro=correcto');
                    exit;
                }
            } catch (PDOException $e) {
                error_log($e->getMessage());
                $error = 'No se pudo completar el registro. Inténtalo de nuevo.';
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
    <title>Crear cuenta | NIGHT OWL</title>
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
            max-width: 440px;
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
            margin: 16px 0 6px;
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

        .error {
            padding: 12px;
            border-radius: 8px;
            background: #fee2e2;
            color: #991b1b;
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
        <p class="subtitulo">Crea tu cuenta para comenzar a aprender.</p>

        <?php if ($error !== ''): ?>
            <p class="error" role="alert"><?= escapar($error) ?></p>
        <?php endif; ?>

        <form method="POST" action="">
            <input
                type="hidden"
                name="csrf_token"
                value="<?= escapar($_SESSION['csrf_token']) ?>"
            >

            <label for="nombre">Nombre</label>
            <input
                id="nombre"
                name="nombre"
                type="text"
                maxlength="100"
                autocomplete="name"
                required
            >

            <label for="edad">Edad</label>
            <input
                id="edad"
                name="edad"
                type="number"
                min="1"
                max="120"
                required
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
                minlength="10"
                autocomplete="new-password"
                required
            >

            <label for="confirmacion">Confirma tu contraseña</label>
            <input
                id="confirmacion"
                name="confirmacion"
                type="password"
                minlength="10"
                autocomplete="new-password"
                required
            >

            <button type="submit">Crear mi cuenta</button>
        </form>

        <p class="enlace">
            ¿Ya tienes cuenta?
            <a href="login.php">Inicia sesión</a>
        </p>
    </main>
</body>
</html>
