<?php
require_once "../config/db.php";
require_once "auth_check.php";

$sql = "SELECT COUNT(*) AS total_nuevos
        FROM pedidos
        WHERE visto = 0";
$stmt = $conexion->prepare($sql);
$stmt->execute();
$resultado = $stmt->fetch(PDO::FETCH_ASSOC);

$ultimoId = (int)$conexion->query("SELECT COALESCE(MAX(id_pedido), 0) FROM pedidos")->fetchColumn();

header('Content-Type: application/json');
echo json_encode([
    "total_nuevos" => (int)($resultado["total_nuevos"] ?? 0),
    "ultimo_id"    => $ultimoId
]);