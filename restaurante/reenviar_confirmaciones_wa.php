<?php
/*
    Reenvía la confirmación de WhatsApp de los pedidos de HOY que ya se
    imprimieron pero cuyo mensaje no salió (p. ej. el servicio de WhatsApp
    estuvo caído). Solo pedidos cuya hora de entrega todavía no pasa.

    Solo por línea de comandos, en el VPS:
        php restaurante/reenviar_confirmaciones_wa.php            → solo muestra la lista
        php restaurante/reenviar_confirmaciones_wa.php --enviar   → envía

    Envía primero los horarios más próximos, con una pausa al azar de
    20–40 s entre mensajes para no salir a ritmo de máquina.
*/
if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

chdir(__DIR__);
require_once "../config/db.php";
require_once "../includes/enviar_whatsapp.php";
require_once "../includes/confirmacion_wa.php";
date_default_timezone_set("America/Mexico_City");

$enviar = in_array("--enviar", $argv, true);

$stmt = $conexion->prepare(
    "SELECT p.id_pedido
     FROM pedidos p
     INNER JOIN horarios_ubicacion h ON p.id_horario = h.id_horario
     WHERE DATE(p.fecha_pedido) = CURDATE()
       AND p.es_prueba = 0
       AND p.estado != 'Cancelado'
       AND p.visto = 1
       AND p.correo_enviado = 0
       AND p.telefono NOT REGEXP '^0+$'
       AND h.hora_entrega > :ahora
     ORDER BY h.hora_entrega ASC, p.fecha_pedido ASC"
);
$stmt->execute([":ahora" => date("H:i:s")]);
$ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

echo count($ids) . " confirmaciones pendientes" . ($enviar ? "" : " (prueba en seco, agrega --enviar para mandarlas)") . "\n";

$ok = 0;
foreach ($ids as $i => $idPedido) {
    $datos = mensajeConfirmacionWAPorId($conexion, (int)$idPedido);
    if (!$datos) continue;
    $p = $datos["pedido"];
    $linea = date("g:i A", strtotime($p["hora_entrega"])) . " | " . $p["nombre_ubicacion"] . " | " . $p["nombre_cliente"] . " | " . $p["telefono"];

    if (!$enviar) {
        echo "  - {$linea}\n";
        continue;
    }

    // Re-checar justo antes de enviar: pudo haberse mandado al reimprimir
    $chk = $conexion->prepare("SELECT correo_enviado FROM pedidos WHERE id_pedido = :id");
    $chk->execute([":id" => $idPedido]);
    if ((int)$chk->fetchColumn() === 1) {
        echo "  ⏭️  {$linea} (ya se había enviado)\n";
        continue;
    }

    if (enviarWhatsApp($p["telefono"], $datos["mensaje"])) {
        $conexion->prepare("UPDATE pedidos SET correo_enviado = 1 WHERE id_pedido = :id")
                 ->execute([":id" => $idPedido]);
        $ok++;
        echo "  ✅ {$linea}\n";
    } else {
        echo "  ❌ {$linea}\n";
    }

    if ($i < count($ids) - 1) sleep(random_int(20, 40));
}

if ($enviar) echo "Listo: {$ok} de " . count($ids) . " enviadas.\n";
