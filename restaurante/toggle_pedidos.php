<?php
require_once "../config/db.php";
require_once "auth_check.php";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $stmt = $conexion->prepare("SELECT valor FROM configuracion WHERE clave = 'recibir_pedidos' LIMIT 1");
    $stmt->execute();
    $actual = (int)($stmt->fetchColumn() ?? 1);

    $nuevo = $actual === 1 ? "0" : "1";

    $conexion->prepare("UPDATE configuracion SET valor = :valor WHERE clave = 'recibir_pedidos'")
             ->execute([":valor" => $nuevo]);
}

header("Location: pedidos.php");
exit;
