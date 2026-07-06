<?php
require_once "../config/db.php";
require_once "auth_check.php";

header("Content-Type: application/json; charset=UTF-8");

$id_producto = (int)($_POST["id_producto"] ?? 0);
$accion      = trim($_POST["accion"] ?? "");

if ($id_producto <= 0 || !in_array($accion, ["agotado", "disponible"])) {
    echo json_encode(["ok" => false, "error" => "Parámetros inválidos."]);
    exit;
}

$disponible = $accion === "disponible" ? 1 : 0;

$stmt = $conexion->prepare("UPDATE productos SET disponible = :disponible WHERE id_producto = :id");
$stmt->execute([":disponible" => $disponible, ":id" => $id_producto]);

echo json_encode(["ok" => true, "disponible" => $disponible]);
