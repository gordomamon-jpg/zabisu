<?php
require_once "../config/db.php";

header("Content-Type: application/json");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["ok" => false]);
    exit;
}

$calificacion = (int)($_POST["calificacion"] ?? 0);
$comentario   = trim($_POST["comentario"]   ?? "");
$folio        = trim($_POST["folio"]        ?? "");

if ($calificacion < 1 || $calificacion > 5) {
    echo json_encode(["ok" => false, "error" => "Calificación inválida."]);
    exit;
}

$id_pedido = null;
if ($folio !== "") {
    $stmtF = $conexion->prepare("SELECT id_pedido FROM pedidos WHERE folio = :folio LIMIT 1");
    $stmtF->execute([":folio" => $folio]);
    $row = $stmtF->fetch(PDO::FETCH_ASSOC);
    if ($row) $id_pedido = (int)$row["id_pedido"];
}

$stmt = $conexion->prepare(
    "INSERT INTO feedback_pedidos (id_pedido, folio, calificacion, comentario)
     VALUES (:id_pedido, :folio, :calificacion, :comentario)"
);
$stmt->execute([
    ":id_pedido"    => $id_pedido,
    ":folio"        => $folio !== "" ? $folio : null,
    ":calificacion" => $calificacion,
    ":comentario"   => $comentario !== "" ? $comentario : null,
]);

echo json_encode(["ok" => true]);
