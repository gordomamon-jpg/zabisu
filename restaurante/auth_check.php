<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION["restaurante_auth"])) {
    /*
        Para endpoints AJAX (devuelven JSON) responder con 401
        en lugar de redirigir al HTML del login.
    */
    $esAjax = (
        !empty($_SERVER["HTTP_X_REQUESTED_WITH"]) ||
        (isset($_SERVER["HTTP_ACCEPT"]) && str_contains($_SERVER["HTTP_ACCEPT"], "application/json"))
    );

    if ($esAjax) {
        http_response_code(401);
        header("Content-Type: application/json; charset=UTF-8");
        echo json_encode(["ok" => false, "mensaje" => "Sesión no iniciada."]);
        exit;
    }

    header("Location: login.php");
    exit;
}
