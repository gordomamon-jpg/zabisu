<?php
require_once "../config/db.php";
require_once "auth_check.php";

/*
    Confirma el pago por transferencia de un pedido.
    - POST (desde pedidos.php, sin recargar): responde JSON con el nuevo
      estado para actualizar solo esa fila.
    - GET (liga directa, por si no hay JavaScript): regresa a pedidos.php.
*/
$esAjax    = $_SERVER["REQUEST_METHOD"] === "POST";
$id_pedido = (int)($_POST["id"] ?? $_GET["id"] ?? 0);

function terminar(bool $esAjax, bool $ok, string $mensaje = ""): void
{
    if ($esAjax) {
        header("Content-Type: application/json; charset=UTF-8");
        echo json_encode($ok
            ? ["ok" => true, "texto" => "Pago confirmado", "clase" => "estado estado-pagado"]
            : ["ok" => false, "mensaje" => $mensaje], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!$ok) die($mensaje);
    header("Location: pedidos.php?scroll=1");
    exit;
}

if ($id_pedido <= 0) {
    terminar($esAjax, false, "No se recibió un pedido válido.");
}

$stmtPedido = $conexion->prepare("SELECT metodo_pago, estado_pago FROM pedidos WHERE id_pedido = :id_pedido LIMIT 1");
$stmtPedido->execute([":id_pedido" => $id_pedido]);
$pedido = $stmtPedido->fetch(PDO::FETCH_ASSOC);

if (!$pedido) {
    terminar($esAjax, false, "No se encontró el pedido.");
}
if (($pedido["metodo_pago"] ?? "") !== "Transferencia") {
    terminar($esAjax, false, "Este pedido no corresponde a un pago por transferencia.");
}

// Si ya estaba confirmado (por ejemplo, desde otra pestaña), no se toca y se da por bueno
if (($pedido["estado_pago"] ?? "") === "Pendiente de validación") {
    try {
        $conexion->prepare(
            "UPDATE pedidos SET estado_pago = 'Pagado', fecha_pago = :fecha_pago
             WHERE id_pedido = :id_pedido AND estado_pago = 'Pendiente de validación'"
        )->execute([":fecha_pago" => date("Y-m-d H:i:s"), ":id_pedido" => $id_pedido]);
    } catch (Exception $e) {
        terminar($esAjax, false, "Ocurrió un error al confirmar el pago. Intenta de nuevo.");
    }
} elseif ($pedido["estado_pago"] !== "Pagado") {
    terminar($esAjax, false, "Este pedido ya no está pendiente de validación.");
}

terminar($esAjax, true);
