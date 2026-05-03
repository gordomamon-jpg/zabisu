<?php
require_once "../config/db.php";
require_once "auth_check.php";

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['id_gasto'])) {
    echo json_encode(['ok' => false, 'error' => 'Petición inválida']);
    exit;
}

$id   = (int)$_POST['id_gasto'];
$stmt = $conexion->prepare("DELETE FROM gastos WHERE id_gasto = :id");
$stmt->execute([':id' => $id]);

echo json_encode(['ok' => true]);
