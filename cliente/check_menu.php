<?php
require_once "../config/db.php";
require_once "../includes/menu_programado.php";
menuActivarProgramado($conexion);
$stmt = $conexion->query("SELECT COUNT(*) FROM menu_dia WHERE activo = 1 LIMIT 1");
echo $stmt->fetchColumn() > 0 ? '1' : '0';
