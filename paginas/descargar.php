<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../config/conexion.php';

if (empty($_SESSION['usuario_id'])) {
    http_response_code(401);
    exit('Debes iniciar sesión.');
}

$usuarioId = (int) $_SESSION['usuario_id'];
$tipo = $_GET['tipo'] ?? '';
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id || $id < 1 || !in_array($tipo, ['guia', 'entrega'], true)) {
    http_response_code(400);
    exit('Solicitud de descarga no válida.');
}

try {
    if ($tipo === 'guia') {
        $consulta = $pdo->prepare(
            'SELECT ge.archivo, ge.titulo,
                    g.id AS grupo_id, g.creador_id
             FROM guias_estudio ge
             INNER JOIN temas t ON t.id = ge.tema_id
             INNER JOIN grupos g ON g.id = t.grupo_id
             WHERE ge.id = ?'
        );
        $consulta->execute([$id]);
        $archivo = $consulta->fetch();

        if (!$archivo) {
            http_response_code(404);
            exit('La guía no existe.');
        }

        $grupoId = (int) $archivo['grupo_id'];
        $esCreador = (int) $archivo['creador_id'] === $usuarioId;

        $consulta = $pdo->prepare(
            'SELECT 1 FROM integrantes_grupo
             WHERE grupo_id = ? AND usuario_id = ?'
        );
        $consulta->execute([$grupoId, $usuarioId]);
        $esIntegrante = (bool) $consulta->fetchColumn();

        if (!$esCreador && !$esIntegrante) {
            http_response_code(403);
            exit('No tienes permiso para descargar esta guía.');
        }
    } else {
        $consulta = $pdo->prepare(
            'SELECT ea.archivo, ea.usuario_id,
                    g.id AS grupo_id, g.creador_id
             FROM entregas_actividad ea
             INNER JOIN actividades a ON a.id = ea.actividad_id
             INNER JOIN temas t ON t.id = a.tema_id
             INNER JOIN grupos g ON g.id = t.grupo_id
             WHERE ea.id = ?'
        );
        $consulta->execute([$id]);
        $archivo = $consulta->fetch();

        if (!$archivo || empty($archivo['archivo'])) {
            http_response_code(404);
            exit('El archivo de la entrega no existe.');
        }

        $esCreador = (int) $archivo['creador_id'] === $usuarioId;
        $esAutor = (int) $archivo['usuario_id'] === $usuarioId;

        if (!$esCreador && !$esAutor) {
            http_response_code(403);
            exit('No tienes permiso para descargar esta entrega.');
        }
    }

    $nombreGuardado = basename((string) $archivo['archivo']);
    $ruta = __DIR__ . '/../privado/materiales/' . $nombreGuardado;

    if (!is_file($ruta) || !is_readable($ruta)) {
        http_response_code(404);
        exit('El archivo no está disponible.');
    }

    $extension = strtolower(pathinfo($nombreGuardado, PATHINFO_EXTENSION));

    $tipos = [
        'pdf' => 'application/pdf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'txt' => 'text/plain; charset=UTF-8',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png'
    ];

    if (!isset($tipos[$extension])) {
        http_response_code(415);
        exit('Tipo de archivo no permitido.');
    }

    $nombreDescarga = $tipo === 'guia'
        ? preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $archivo['titulo'])
        : 'entrega_' . $id;

    if (!is_string($nombreDescarga) || $nombreDescarga === '') {
        $nombreDescarga = 'material';
    }

    $nombreDescarga .= '.' . $extension;

    header('Content-Type: ' . $tipos[$extension]);
    header('Content-Length: ' . (string) filesize($ruta));
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename="' . $nombreDescarga . '"');

    readfile($ruta);
    exit;
} catch (Throwable $e) {
    error_log($e->getMessage());
    http_response_code(500);
    exit('No se pudo descargar el archivo.');
}
