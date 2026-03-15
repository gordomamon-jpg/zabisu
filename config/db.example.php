<?php
// Copia este archivo como db.php y llena con tus datos reales
$host     = "localhost";
$dbname   = "zabisu";
$usuario  = "TU_USUARIO_BD";
$password = "TU_PASSWORD_BD";

try {
    $conexion = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $usuario,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    die("Error de conexión: " . $e->getMessage());
}
