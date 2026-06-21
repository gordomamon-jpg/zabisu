<?php
require_once "../config/db.php";
$stmt = $conexion->query("SELECT COUNT(*) FROM menu_dia WHERE activo = 1 LIMIT 1");
echo $stmt->fetchColumn() > 0 ? '1' : '0';
