<?php
require_once "../config/db.php";
require_once "auth_check.php";
require_once "../includes/wa_etiqueta.php";

/*
    Estados de WhatsApp de los avisos de los últimos 3 días, para refrescar
    las palomitas del panel sin recargar: {"id_pedido": "<span…>", …}
    (Sin lista de ids en la URL: el panel carga miles de pedidos y Apache
    rechaza URLs tan largas con 414.)
*/
$stmt = $conexion->prepare(
    "SELECT w.id_pedido, w.tipo, w.estado
     FROM wa_mensajes w
     INNER JOIN (
         SELECT MAX(id) AS id FROM wa_mensajes
         WHERE id_pedido IS NOT NULL
           AND tipo IN ('confirmacion', 'llegada')
           AND creado_en >= NOW() - INTERVAL 3 DAY
         GROUP BY id_pedido, tipo
     ) ult ON ult.id = w.id"
);
$stmt->execute();

$porPedido = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $f) {
    $porPedido[(int)$f['id_pedido']][$f['tipo']] = $f['estado'];
}

$salida = [];
foreach ($porPedido as $id => $t) {
    $salida[$id] = etiquetaWA("Confirmación", $t['confirmacion'] ?? null)
                 . etiquetaWA("Llegada", $t['llegada'] ?? null);
}

header('Content-Type: application/json');
header('Cache-Control: no-store');
echo json_encode((object)$salida, JSON_UNESCAPED_UNICODE);
