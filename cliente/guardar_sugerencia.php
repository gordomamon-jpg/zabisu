<?php
require_once "../config/db.php";

header("Content-Type: application/json");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["ok" => false]);
    exit;
}

$sugerencia = trim($_POST["sugerencia"] ?? "");
if ($sugerencia === "" || mb_strlen($sugerencia) > 200) {
    echo json_encode(["ok" => false, "error" => "Sugerencia inválida."]);
    exit;
}

$stmt = $conexion->prepare(
    "INSERT INTO sugerencias_plato (sugerencia, fecha) VALUES (:sugerencia, :fecha)"
);
$stmt->execute([
    ":sugerencia" => $sugerencia,
    ":fecha"      => date("Y-m-d"),
]);

echo json_encode(["ok" => true]);
