<?php
require_once "../config/db.php";

header("Content-Type: application/json");

// Se busca por teléfono o por correo (temporalmente el pedido pide correo)
$telefono = preg_replace('/\D/', '', $_GET["telefono"] ?? "");
$correo   = mb_strtolower(trim($_GET["correo"] ?? ""), "UTF-8");

if (strlen($telefono) === 10) {
    $campo = "p.telefono";
    $valor = $telefono;
} elseif ($correo !== "" && filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    $campo = "LOWER(p.correo_cliente)";
    $valor = $correo;
} else {
    echo json_encode(["encontrado" => false]);
    exit;
}

$stmt = $conexion->prepare(
    "SELECT p.nombre_cliente, p.correo_cliente, p.id_horario,
            h.id_ubicacion
     FROM pedidos p
     INNER JOIN horarios_ubicacion h ON p.id_horario = h.id_horario
     WHERE {$campo} = :valor
       AND p.estado != 'Cancelado'
     ORDER BY p.id_pedido DESC
     LIMIT 1"
);
$stmt->bindParam(":valor", $valor);
$stmt->execute();
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    echo json_encode(["encontrado" => false]);
    exit;
}

echo json_encode([
    "encontrado"     => true,
    "nombre_cliente" => $row["nombre_cliente"],
    "correo_cliente" => $row["correo_cliente"],
    "id_horario"     => (int)$row["id_horario"],
    "id_ubicacion"   => (int)$row["id_ubicacion"],
]);
