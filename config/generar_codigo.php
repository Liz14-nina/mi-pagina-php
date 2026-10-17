<?php
declare(strict_types=1);

/**
 * Genera un código único para un grupo.
 * Requiere que conexion.php haya creado la conexión PDO $pdo.
 */
function generarCodigoInvitacion(PDO $pdo): string
{
    $caracteres = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    for ($intento = 0; $intento < 10; $intento++) {
        $codigo = '';

        for ($i = 0; $i < 8; $i++) {
            $codigo .= $caracteres[random_int(
                0,
                strlen($caracteres) - 1
            )];
        }

        $consulta = $pdo->prepare(
            'SELECT id FROM grupos WHERE codigo_invitacion = ? LIMIT 1'
        );
        $consulta->execute([$codigo]);

        if (!$consulta->fetch()) {
            return $codigo;
        }
    }

    throw new RuntimeException(
        'No se pudo generar un código único. Inténtalo de nuevo.'
    );
}
