<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/generar_codigo.php';

if (empty($_SESSION['usuario_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

$usuarioId = (int) $_SESSION['usuario_id'];

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function escapar(string $texto): string
{
    return htmlspecialchars($texto, ENT_QUOTES, 'UTF-8');
}

$error = '';
$mensaje = '';
$materias = [];
$grupos = [];
$filtroNombre = '';
$filtroMateria = '';
$filtroGrado = '';

// Materia recibida desde la página de una materia.
$materiaSeleccionada = filter_input(
    INPUT_GET,
    'materia_id',
    FILTER_VALIDATE_INT
);

if (
    $materiaSeleccionada === false ||
    $materiaSeleccionada === null ||
    $materiaSeleccionada < 1
) {
    $materiaSeleccionada = 0;
}

try {
    // Crear grupo.
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';

        if (
            !is_string($token) ||
            !hash_equals($_SESSION['csrf_token'], $token)
        ) {
            $error = 'El formulario caducó. Recarga la página.';
        } else {
            $nombre = trim((string) ($_POST['nombre'] ?? ''));
            $grado = trim((string) ($_POST['grado'] ?? ''));

            $materiaId = filter_var(
                $_POST['materia_id'] ?? null,
                FILTER_VALIDATE_INT
            );

            if (
                $nombre === '' ||
                mb_strlen($nombre) > 100 ||
                $grado === '' ||
                mb_strlen($grado) > 20 ||
                $materiaId === false ||
                $materiaId === null ||
                $materiaId < 1
            ) {
                $error = 'Revisa el nombre, el grado y la materia.';
            } else {
                try {
                    $pdo->beginTransaction();

                    $consulta = $pdo->prepare(
                        'SELECT id FROM materias WHERE id = ?'
                    );
                    $consulta->execute([$materiaId]);

                    if (!$consulta->fetch()) {
                        throw new DomainException(
                            'Selecciona una materia válida.'
                        );
                    }

                    $codigo = generarCodigoInvitacion($pdo);

                    $consulta = $pdo->prepare(
                        'INSERT INTO grupos
                            (materia_id, nombre, grado, creador_id,
                             codigo_invitacion)
                         VALUES (?, ?, ?, ?, ?)'
                    );

                    $consulta->execute([
                        $materiaId,
                        $nombre,
                        $grado,
                        $usuarioId,
                        $codigo
                    ]);

                    $grupoId = (int) $pdo->lastInsertId();

                    $consulta = $pdo->prepare(
                        'INSERT INTO integrantes_grupo
                            (grupo_id, usuario_id)
                         VALUES (?, ?)'
                    );
                    $consulta->execute([$grupoId, $usuarioId]);

                    $pdo->commit();

                    $_SESSION['csrf_token'] =
                        bin2hex(random_bytes(32));

                    header('Location: grupo.php?id=' . $grupoId);
                    exit;
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    if ($e instanceof DomainException) {
                        $error = $e->getMessage();
                    } else {
                        error_log($e->getMessage());
                        $error =
                            'No se pudo crear el grupo. Inténtalo de nuevo.';
                    }
                }
            }
        }
    }

    // Buscar grupo por código.
    $codigoBusqueda = strtoupper(trim(
        (string) ($_GET['codigo'] ?? '')
    ));

    if ($codigoBusqueda !== '') {
        if (!preg_match('/^[A-HJ-NP-Z2-9]{8}$/', $codigoBusqueda)) {
            $error = 'Introduce un código válido de 8 caracteres.';
        } else {
            $consulta = $pdo->prepare(
                'SELECT id FROM grupos WHERE codigo_invitacion = ?'
            );
            $consulta->execute([$codigoBusqueda]);
            $grupoEncontrado = $consulta->fetch();

            if ($grupoEncontrado) {
                header(
                    'Location: grupo.php?id=' .
                    (int) $grupoEncontrado['id']
                );
                exit;
            }

            $error = 'No encontramos un grupo con ese código.';
        }
    }

    // Cargar materias.
    $materias = $pdo->query(
        'SELECT id, nombre FROM materias ORDER BY nombre'
    )->fetchAll();

    // Verificar que la materia recibida exista.
    if ($materiaSeleccionada > 0) {
        $existeMateria = false;

        foreach ($materias as $materia) {
            if ((int) $materia['id'] === $materiaSeleccionada) {
                $existeMateria = true;
                break;
            }
        }

        if (!$existeMateria) {
            $materiaSeleccionada = 0;
        }
    }

    // Leer filtros del catálogo.
    $filtroNombre = trim(
        (string) ($_GET['nombre_busqueda'] ?? '')
    );

    $materiaRecibida = filter_var(
        $_GET['materia_filtro'] ?? '',
        FILTER_VALIDATE_INT
    );

    $filtroMateria = (
        $materiaRecibida !== false &&
        $materiaRecibida !== null &&
        $materiaRecibida > 0
    ) ? (string) $materiaRecibida : '';

    $filtroGrado = trim(
        (string) ($_GET['grado_filtro'] ?? '')
    );

    // Consultar catálogo.
    $sql = '
        SELECT
            g.id,
            g.nombre,
            g.grado,
            g.codigo_invitacion,
            m.nombre AS materia,
            u.nombre AS creador,
            COUNT(DISTINCT ig.usuario_id) AS total_integrantes,
            MAX(
                CASE
                    WHEN ig.usuario_id = ? THEN 1
                    ELSE 0
                END
            ) AS soy_integrante
        FROM grupos g
        INNER JOIN materias m ON m.id = g.materia_id
        INNER JOIN usuarios u ON u.id = g.creador_id
        LEFT JOIN integrantes_grupo ig ON ig.grupo_id = g.id
        WHERE 1 = 1
    ';

    $parametros = [$usuarioId];

    if ($filtroNombre !== '') {
        $sql .= ' AND g.nombre LIKE ?';
        $parametros[] = '%' . $filtroNombre . '%';
    }

    if ($filtroMateria !== '') {
        $sql .= ' AND g.materia_id = ?';
        $parametros[] = (int) $filtroMateria;
    }

    if ($filtroGrado !== '') {
        $sql .= ' AND g.grado LIKE ?';
        $parametros[] = '%' . $filtroGrado . '%';
    }

    $sql .= '
        GROUP BY
            g.id, g.nombre, g.grado, g.codigo_invitacion,
            m.nombre, u.nombre, g.creado_en
        ORDER BY g.creado_en DESC
    ';

    $consulta = $pdo->prepare($sql);
    $consulta->execute($parametros);
    $grupos = $consulta->fetchAll();

} catch (Throwable $e) {
    error_log($e->getMessage());
    $error = 'No se pudieron cargar los grupos o las materias.';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Grupos | NIGHT OWL</title>

    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 24px;
            font-family: Arial, sans-serif;
            background: #eef4ff;
            color: #172554;
        }

        main {
            max-width: 1000px;
            margin: 0 auto;
        }

        .tarjeta {
            background: white;
            padding: 24px;
            margin-bottom: 24px;
            border-radius: 16px;
            box-shadow: 0 8px 25px rgba(30,64,175,.08);
        }

        h1, h2 { color: #1d4ed8; }

        label {
            display: block;
            margin: 14px 0 6px;
            font-weight: bold;
        }

        input, select, button {
            width: 100%;
            padding: 12px;
            border-radius: 8px;
            font-size: 16px;
        }

        input, select {
            border: 1px solid #cbd5e1;
            background: white;
        }

        button, .boton {
            display: inline-block;
            border: 0;
            background: #2563eb;
            color: white;
            text-align: center;
            text-decoration: none;
            font-weight: bold;
            cursor: pointer;
        }

        button { margin-top: 16px; }

        .boton {
            width: auto;
            padding: 10px 16px;
            border-radius: 8px;
        }

        .grupo {
            padding: 18px 0;
            border-bottom: 1px solid #e2e8f0;
        }

        .grupo:last-child { border-bottom: 0; }

        .error, .mensaje {
            padding: 12px;
            border-radius: 8px;
        }

        .error { background: #fee2e2; color: #991b1b; }
        .mensaje { background: #dbeafe; color: #1e40af; }
        .vacio { color: #64748b; }

        .barra {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .filtros {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
            align-items: end;
        }

        .filtros label { margin-top: 0; }
        .filtros button { margin-top: 0; }

        .codigo {
            font-family: monospace;
            font-size: 1.05rem;
            letter-spacing: 2px;
            font-weight: bold;
        }

        .secundario { background: #475569; }

        .boton-copiar {
            width: auto;
            margin: 0 0 0 8px;
            padding: 8px 12px;
        }
    </style>
</head>
<body>
<main>
    <header class="barra">
        <h1>NIGHT OWL · Grupos</h1>
        <a href="../index.php">Volver al inicio</a>
    </header>

    <?php if ($error !== ''): ?>
        <p class="error" role="alert"><?= escapar($error) ?></p>
    <?php endif; ?>

    <?php if ($mensaje !== ''): ?>
        <p class="mensaje"><?= escapar($mensaje) ?></p>
    <?php endif; ?>

    <section class="tarjeta">
        <h2>Buscar con código de invitación</h2>
        <p>¿Te compartieron un código? Escríbelo para localizar el grupo.</p>

        <form method="GET">
            <label for="codigo">Código de invitación</label>
            <input
                id="codigo"
                name="codigo"
                type="text"
                minlength="8"
                maxlength="8"
                pattern="[A-HJ-NP-Z2-9]{8}"
                placeholder="Ej. AB3K7XYZ"
                style="text-transform: uppercase"
                required
            >
            <button type="submit">Buscar grupo</button>
        </form>
    </section>

    <section class="tarjeta" id="crear-grupo">
        <h2>Crear un grupo</h2>

        <?php if (!$materias): ?>
            <p class="vacio">
                Primero necesitas registrar al menos una materia.
            </p>
        <?php else: ?>
            <form method="POST" action="grupos.php?materia_id=<?= (int) $materiaSeleccionada ?>#crear-grupo">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= escapar($_SESSION['csrf_token']) ?>"
                >

                <label for="nombre">Nombre del grupo</label>
                <input
                    id="nombre"
                    name="nombre"
                    type="text"
                    maxlength="100"
                    required
                >

                <label for="grado">Grado académico</label>
                <input
                    id="grado"
                    name="grado"
                    type="text"
                    maxlength="20"
                    placeholder="Ej. 9°"
                    required
                >

                <label for="materia_id">Materia</label>
                <select id="materia_id" name="materia_id" required>
                    <option
                        value=""
                        <?= $materiaSeleccionada === 0 ? 'selected' : '' ?>
                    >Selecciona una materia</option>

                    <?php foreach ($materias as $materia): ?>
                        <option
                            value="<?= (int) $materia['id'] ?>"
                            <?= (int) $materia['id'] === $materiaSeleccionada
                                ? 'selected' : '' ?>
                        >
                            <?= escapar($materia['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button type="submit">Crear grupo</button>
            </form>
        <?php endif; ?>
    </section>

    <section class="tarjeta">
        <h2>Catálogo de grupos</h2>

        <form method="GET" class="filtros">
            <div>
                <label for="nombre_busqueda">Nombre del grupo</label>
                <input
                    id="nombre_busqueda"
                    name="nombre_busqueda"
                    type="search"
                    maxlength="100"
                    value="<?= escapar($filtroNombre) ?>"
                    placeholder="Buscar..."
                >
            </div>

            <div>
                <label for="materia_filtro">Materia</label>
                <select id="materia_filtro" name="materia_filtro">
                    <option value="">Todas las materias</option>
                    <?php foreach ($materias as $materia): ?>
                        <option
                            value="<?= (int) $materia['id'] ?>"
                            <?= $filtroMateria === (string) $materia['id']
                                ? 'selected' : '' ?>
                        >
                            <?= escapar($materia['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label for="grado_filtro">Grado</label>
                <input
                    id="grado_filtro"
                    name="grado_filtro"
                    type="search"
                    maxlength="20"
                    value="<?= escapar($filtroGrado) ?>"
                    placeholder="Ej. 9°"
                >
            </div>

            <button type="submit">Filtrar grupos</button>
        </form>

        <p><a href="grupos.php">Limpiar filtros</a></p>

        <?php if (!$grupos): ?>
            <p class="vacio">
                No encontramos grupos con esos criterios.
            </p>
        <?php else: ?>
            <?php foreach ($grupos as $grupo): ?>
                <?php
                $codigoGrupo = (string) ($grupo['codigo_invitacion'] ?? '');
                ?>
                <article class="grupo">
                    <h3><?= escapar($grupo['nombre']) ?></h3>

                    <p>
                        Materia: <?= escapar($grupo['materia']) ?><br>
                        Grado: <?= escapar($grupo['grado']) ?><br>
                        Creador: <?= escapar($grupo['creador']) ?><br>
                        Integrantes: <?= (int) $grupo['total_integrantes'] ?>
                    </p>

                    <p>
                        Código para compartir:
                        <span class="codigo"><?= escapar(
                            $codigoGrupo !== '' ? $codigoGrupo : 'Pendiente'
                        ) ?></span>
                    </p>

                    <button
                        type="button"
                        class="secundario boton-copiar"
                        data-codigo="<?= escapar($codigoGrupo) ?>"
                    >Copiar código</button>

                    <a
                        class="boton"
                        href="grupo.php?id=<?= (int) $grupo['id'] ?>"
                    >
                        <?= (int) $grupo['soy_integrante']
                            ? 'Ver grupo'
                            : 'Ver y solicitar ingreso' ?>
                    </a>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>
</main>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const botones = document.querySelectorAll('.boton-copiar');

    botones.forEach(function (boton) {
        boton.addEventListener('click', function () {
            const codigo = boton.getAttribute('data-codigo') || '';

            if (!codigo) {
                alert('Este grupo no tiene un código de invitación.');
                return;
            }

            const campo = document.createElement('textarea');
            campo.value = codigo;
            campo.setAttribute('readonly', '');
            campo.style.position = 'fixed';
            campo.style.left = '-9999px';

            document.body.appendChild(campo);
            campo.select();

            let copiado = false;

            try {
                copiado = document.execCommand('copy');
            } catch (error) {
                copiado = false;
            }

            campo.remove();

            if (copiado) {
                alert('¡Código copiado correctamente: ' + codigo + '!');
            } else {
                window.prompt('Copia el código manualmente:', codigo);
            }
        });
    });
});
</script>
</body>
</html>
