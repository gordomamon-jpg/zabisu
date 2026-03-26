<?php
/*
 * cron_notificar.php
 * Solo ejecutable desde CLI. El cron del VPS lo llama a las horas exactas
 * de entrega (13:00, 13:30, 14:00, 14:30, 15:00). Si la notificación ya
 * fue enviada manualmente para ese horario y fecha de menú, no hace nada.
 */
if (php_sapi_name() !== "cli") {
    http_response_code(403);
    exit("Acceso denegado.\n");
}

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../includes/enviar_correo.php";

date_default_timezone_set("America/Mexico_City");

$ahora     = date("H:i");   // ej. "13:00"
$fechaHoy  = date("Y-m-d");

echo "[" . date("Y-m-d H:i:s") . "] cron_notificar iniciado — hora actual: {$ahora}\n";

/* ── Buscar horarios que coincidan con la hora actual y no hayan sido notificados ── */
$stmtHorarios = $conexion->prepare("
    SELECT h.id_horario, h.hora_entrega, u.nombre_ubicacion
    FROM horarios_ubicacion h
    INNER JOIN ubicaciones u ON h.id_ubicacion = u.id_ubicacion
    WHERE HOUR(h.hora_entrega)   = HOUR(NOW())
      AND MINUTE(h.hora_entrega) = MINUTE(NOW())
      AND h.id_horario NOT IN (
          SELECT id_horario FROM notificaciones_ruta
          WHERE fecha_menu = :fecha_hoy
      )
");
$stmtHorarios->execute([":fecha_hoy" => $fechaHoy]);
$horarios = $stmtHorarios->fetchAll(PDO::FETCH_ASSOC);

if (empty($horarios)) {
    echo "No hay horarios pendientes de notificar para las {$ahora}.\n";
    exit;
}

/* ── Reutilizar el mismo HTML del correo ── */
foreach ($horarios as $horario) {
    $id_horario       = (int)$horario["id_horario"];
    $hora_entrega     = $horario["hora_entrega"];
    $nombre_ubicacion = $horario["nombre_ubicacion"];

    echo "Procesando: {$nombre_ubicacion} — {$hora_entrega}\n";

    /* Obtener pedidos del grupo para el menú de hoy */
    $sqlPedidos = "SELECT
                       p.id_pedido,
                       p.folio,
                       p.nombre_cliente,
                       p.correo_cliente
                   FROM pedidos p
                   INNER JOIN horarios_ubicacion h ON p.id_horario = h.id_horario
                   INNER JOIN ubicaciones u        ON h.id_ubicacion = u.id_ubicacion
                   INNER JOIN (
                       SELECT pm2.id_pedido, MIN(md2.fecha) AS fecha_menu
                       FROM pedido_menus pm2
                       INNER JOIN detalle_pedido dp2 ON dp2.id_pedido_menu = pm2.id_pedido_menu
                       INNER JOIN productos pr2      ON pr2.id_producto    = dp2.id_producto
                       INNER JOIN menu_dia md2       ON md2.id_menu        = pr2.id_menu
                       GROUP BY pm2.id_pedido
                   ) AS mi ON mi.id_pedido = p.id_pedido
                   WHERE mi.fecha_menu  = :fecha_hoy
                     AND h.id_horario   = :id_horario
                     AND p.es_prueba    = 0
                   ORDER BY p.nombre_cliente ASC";

    $stmtPedidos = $conexion->prepare($sqlPedidos);
    $stmtPedidos->execute([":fecha_hoy" => $fechaHoy, ":id_horario" => $id_horario]);
    $pedidos = $stmtPedidos->fetchAll(PDO::FETCH_ASSOC);

    if (empty($pedidos)) {
        echo "  Sin pedidos para este horario. Se omite.\n";
        continue;
    }

    $ubicacionBonita = $nombre_ubicacion;
    $horaBonita      = date("g:i A", strtotime($hora_entrega));

    $enviados  = 0;
    $sinCorreo = 0;
    $errores   = 0;

    foreach ($pedidos as $pedido) {
        $correo = trim($pedido["correo_cliente"] ?? "");

        if ($correo === "" || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            $sinCorreo++;
            continue;
        }

        $nombre = $pedido["nombre_cliente"];
        $folio  = $pedido["folio"];

        $asunto = "¡Tu pedido ya llegó! · Zabisu";

        $mensaje = "
            <div style='font-family:Arial,Helvetica,sans-serif;max-width:520px;margin:0 auto;color:#222;line-height:1.6;'>

                <div style='background:#0c0c0f;padding:28px 24px 20px;border-radius:14px 14px 0 0;text-align:center;'>
                    <p style='margin:0 0 6px;font-size:11px;font-weight:700;letter-spacing:3px;color:#ff7a00;text-transform:uppercase;'>
                        Zabisu
                    </p>
                    <h1 style='margin:0;font-size:26px;font-weight:800;color:#fff;'>
                        ¡Tu pedido llegó al punto!
                    </h1>
                </div>

                <div style='background:#f9f9f9;padding:24px;border-radius:0 0 14px 14px;'>
                    <p>Hola, <strong>" . htmlspecialchars($nombre) . "</strong>.</p>
                    <p>🎉 Tu pedido de Zabisu ya se encuentra en el punto de entrega. ¡Pasa a recogerlo!</p>

                    <table style='width:100%;border-collapse:collapse;margin:20px 0;'>
                        <tr style='border-bottom:1px solid #e5e5e5;'>
                            <td style='padding:10px 0;color:#888;font-weight:700;width:140px;'>Folio</td>
                            <td style='padding:10px 0;font-weight:700;color:#222;'>" . htmlspecialchars($folio) . "</td>
                        </tr>
                        <tr style='border-bottom:1px solid #e5e5e5;'>
                            <td style='padding:10px 0;color:#888;font-weight:700;'>Punto de entrega</td>
                            <td style='padding:10px 0;color:#222;'>" . htmlspecialchars($ubicacionBonita) . "</td>
                        </tr>
                        <tr>
                            <td style='padding:10px 0;color:#888;font-weight:700;'>Horario</td>
                            <td style='padding:10px 0;color:#222;'>{$horaBonita}</td>
                        </tr>
                    </table>

                    <p style='margin-top:6px;'>Si tienes alguna duda, comunícate con nosotros.</p>
                    <p style='margin-top:24px;font-size:13px;color:#aaa;'>
                        Zabisu — <em>Sabor y Servicio</em>
                    </p>
                </div>

            </div>
        ";

        $resultado = enviarCorreo($correo, $nombre, $asunto, $mensaje);
        if ($resultado) {
            $enviados++;
        } else {
            $errores++;
        }
    }

    /* ── Registrar notificación automática ── */
    $stmtLog = $conexion->prepare("
        INSERT IGNORE INTO notificaciones_ruta (fecha_menu, id_horario, enviado_en, tipo, total_enviados)
        VALUES (:fecha, :id_horario, NOW(), 'automatico', :total_enviados)
    ");
    $stmtLog->execute([
        ":fecha"          => $fechaHoy,
        ":id_horario"     => $id_horario,
        ":total_enviados" => $enviados,
    ]);

    echo "  Total pedidos: " . count($pedidos) . " | Enviados: {$enviados} | Sin correo: {$sinCorreo} | Errores: {$errores}\n";
}

echo "[" . date("Y-m-d H:i:s") . "] cron_notificar finalizado.\n";
