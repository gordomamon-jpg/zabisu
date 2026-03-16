<?php
require_once "../config/db.php";
require_once "auth_check.php";

$id_pedido = isset($_GET["id"]) ? (int)$_GET["id"] : 0;

if ($id_pedido <= 0) {
    die("No se recibió un pedido válido.");
}

/*
    Obtener pedido
*/
$sqlPedido = "SELECT *
              FROM pedidos
              WHERE id_pedido = :id_pedido
              LIMIT 1";
$stmtPedido = $conexion->prepare($sqlPedido);
$stmtPedido->bindParam(":id_pedido", $id_pedido, PDO::PARAM_INT);
$stmtPedido->execute();
$pedido = $stmtPedido->fetch(PDO::FETCH_ASSOC);

if (!$pedido) {
    die("No se encontró el pedido.");
}

if (($pedido["metodo_pago"] ?? "") !== "Transferencia") {
    die("Este pedido no corresponde a un pago por transferencia.");
}

if (($pedido["estado_pago"] ?? "") !== "Pendiente de validación") {
    header("Location: pedidos.php?scroll=1");
    exit;
}

try {
    $estado_pago = "Pagado";
    $fecha_pago = date("Y-m-d H:i:s");

    $sqlUpdate = "UPDATE pedidos
                  SET estado_pago = :estado_pago,
                      fecha_pago = :fecha_pago
                  WHERE id_pedido = :id_pedido";
    $stmtUpdate = $conexion->prepare($sqlUpdate);
    $stmtUpdate->bindParam(":estado_pago", $estado_pago);
    $stmtUpdate->bindParam(":fecha_pago", $fecha_pago);
    $stmtUpdate->bindParam(":id_pedido", $id_pedido, PDO::PARAM_INT);
    $stmtUpdate->execute();

    header("Location: pedidos.php?scroll=1");
    exit;

} catch (Exception $e) {
    die("Ocurrió un error al confirmar el pago: " . $e->getMessage());
}