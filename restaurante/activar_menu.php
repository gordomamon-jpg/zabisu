<?php
require_once "../config/db.php";
require_once "auth_check.php";

$id_menu = isset($_GET["id_menu"]) ? (int)$_GET["id_menu"] : 0;

if ($id_menu <= 0) {
    die("No se recibió un menú válido.");
}

/*
    Verificar que el menú exista
*/
$sqlMenu = "SELECT id_menu, fecha
            FROM menu_dia
            WHERE id_menu = :id_menu
            LIMIT 1";
$stmtMenu = $conexion->prepare($sqlMenu);
$stmtMenu->bindParam(":id_menu", $id_menu, PDO::PARAM_INT);
$stmtMenu->execute();
$menu = $stmtMenu->fetch(PDO::FETCH_ASSOC);

if (!$menu) {
    die("No se encontró el menú.");
}

try {
    $conexion->beginTransaction();

    /*
        Desactivar todos los menús
    */
    $sqlDesactivar = "UPDATE menu_dia SET activo = 0";
    $stmtDesactivar = $conexion->prepare($sqlDesactivar);
    $stmtDesactivar->execute();

    /*
        Activar solo el seleccionado
    */
    $sqlActivar = "UPDATE menu_dia
                   SET activo = 1
                   WHERE id_menu = :id_menu";
    $stmtActivar = $conexion->prepare($sqlActivar);
    $stmtActivar->bindParam(":id_menu", $id_menu, PDO::PARAM_INT);
    $stmtActivar->execute();

    $conexion->commit();

    header("Location: menus.php");
    exit;

} catch (Exception $e) {
    $conexion->rollBack();
    die("Ocurrió un error al activar el menú: " . $e->getMessage());
}