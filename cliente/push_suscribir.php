<?php
/*
    Avisos en el celular del cliente (notificaciones push).
    GET  → la clave pública VAPID para que el navegador se suscriba.
    POST → {folio, suscripcion}: guarda la suscripción y la liga a ese pedido.
           Se llama desde la pantalla de pedido confirmado (confirmar.php):
           al tocar "Activar avisos" o solo, si ese celular ya los tenía activos.
*/
require_once "../config/db.php";
require_once "../includes/seguridad.php";
require_once "../includes/push.php";

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store");

try {
    if ($_SERVER["REQUEST_METHOD"] === "GET") {
        echo json_encode(["ok" => true, "publica" => pushClaves($conexion)["publica"]]);
        exit;
    }

    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        http_response_code(405);
        echo json_encode(["ok" => false]);
        exit;
    }

    verificarOrigenPeticion();

    $datos = json_decode((string)file_get_contents("php://input"), true);
    $folio = strtoupper(trim((string)($datos["folio"] ?? "")));
    $sub   = $datos["suscripcion"] ?? null;

    if ($folio === "" || !is_array($sub)) {
        echo json_encode(["ok" => false, "mensaje" => "Faltan datos."]);
        exit;
    }

    $st = $conexion->prepare("SELECT id_pedido FROM pedidos WHERE folio = :f LIMIT 1");
    $st->execute([":f" => $folio]);
    $idPedido = (int)$st->fetchColumn();
    if ($idPedido <= 0) {
        echo json_encode(["ok" => false, "mensaje" => "No encontramos el pedido."]);
        exit;
    }

    $ok = pushVincular($conexion, $idPedido, $sub, (string)($_SERVER["HTTP_USER_AGENT"] ?? ""));
    echo json_encode($ok ? ["ok" => true] : ["ok" => false, "mensaje" => "Suscripción no válida."]);
} catch (Throwable $e) {
    error_log("push_suscribir: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["ok" => false, "mensaje" => "Error al guardar."]);
}
