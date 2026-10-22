<?php
require_once "../config/db.php";
require_once "auth_check.php";
require_once "../includes/wa_etiqueta.php";

/*
    Estados de WhatsApp de los pedidos visibles en el panel, para refrescar
    las palomitas sin recargar: ?ids=1,2,3 → {"1": "<span…>", …}
*/
$ids = array_slice(array_unique(array_filter(array_map('intval', explode(',', $_GET['ids'] ?? '')))), 0, 500);

header('Content-Type: application/json');
if (!$ids) {
    echo '{}';
    exit;
}

$marcas = implode(',', array_fill(0, count($ids), '?'));
$stmt = $conexion->prepare(
    "SELECT w.id_pedido, w.tipo, w.estado
     FROM wa_mensajes w
     INNER JOIN (
         SELECT MAX(id) AS id FROM wa_mensajes
         WHERE id_pedido IN ($marcas) AND tipo IN ('confirmacion', 'llegada')
         GROUP BY id_pedido, tipo
     ) ult ON ult.id = w.id"
);
$stmt->execute($ids);

$porPedido = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $f) {
    $porPedido[(int)$f['id_pedido']][$f['tipo']] = $f['estado'];
}

$salida = [];
foreach ($porPedido as $id => $t) {
    $salida[$id] = etiquetaWA("Confirmación", $t['confirmacion'] ?? null)
                 . etiquetaWA("Llegada", $t['llegada'] ?? null);
}
echo json_encode($salida, JSON_UNESCAPED_UNICODE);
