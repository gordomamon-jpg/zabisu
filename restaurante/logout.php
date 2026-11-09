<?php
require_once __DIR__ . "/../includes/seguridad.php";
iniciarSesionSegura();

session_unset();
session_destroy();
// Borra también la cookie (por si se había pedido mantener la sesión 24 h)
setcookie(session_name(), "", ["expires" => time() - 3600, "path" => "/", "domain" => "", "httponly" => true, "samesite" => "Lax"]);

header("Location: login.php");
exit;
