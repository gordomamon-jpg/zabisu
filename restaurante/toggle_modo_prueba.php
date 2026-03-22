<?php
require_once "../config/db.php";
require_once "auth_check.php";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $stmt = $conexion->prepare("SELECT valor FROM configuracion WHERE clave = 'modo_prueba' LIMIT 1");
    $stmt->execute();
    $actual = (int)($stmt->fetchColumn() ?? 0);
    $nuevo  = $actual === 1 ? "0" : "1";
    $conexion->prepare("UPDATE configuracion SET valor = :valor WHERE clave = 'modo_prueba'")->execute([":valor" => $nuevo]);
}

header("Location: panel_general.php");
exit;
