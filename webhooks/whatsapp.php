<?php
/*
    Webhook de la API oficial de WhatsApp (Meta).
    URL a registrar en Meta: https://zabisu.com/webhooks/whatsapp.php
    Campo a suscribir: messages.

    - Estados de cada mensaje (enviado, entregado, leído, falló) → wa_mensajes.
    - Si un cliente le escribe al número de avisos, se le contesta una vez
      cada 24 h que escriba al WhatsApp atendido por una persona.
*/
require_once __DIR__ . "/../includes/whatsapp_api.php";

$config = waApiConfig();

/* ── Verificación inicial: Meta manda hub.mode, hub.verify_token y hub.challenge ── */
if ($_SERVER["REQUEST_METHOD"] === "GET") {
    $tokenOk = !empty($config["verify_token"])
        && hash_equals($config["verify_token"], (string)($_GET["hub_verify_token"] ?? ""));
    if (($_GET["hub_mode"] ?? "") === "subscribe" && $tokenOk) {
        header("Content-Type: text/plain");
        echo $_GET["hub_challenge"] ?? "";
    } else {
        http_response_code(403);
    }
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    exit;
}

/* ── Solo aceptar avisos firmados por Meta con la clave secreta de la app ── */
$cuerpo = file_get_contents("php://input");
$firma  = $_SERVER["HTTP_X_HUB_SIGNATURE_256"] ?? "";
if (empty($config["app_secret"])
    || !hash_equals("sha256=" . hash_hmac("sha256", $cuerpo, $config["app_secret"]), $firma)) {
    http_response_code(403);
    exit;
}

require_once __DIR__ . "/../config/db.php";
date_default_timezone_set("America/Mexico_City");

$data = json_decode($cuerpo, true) ?? [];

// Un estado solo avanza (Meta puede mandar "delivered" después de "read")
$stmtEstado = $conexion->prepare("
    UPDATE wa_mensajes
    SET estado = :estado, error = COALESCE(:error, error), actualizado_en = NOW()
    WHERE wamid = :wamid
      AND (:estado2 = 'failed'
           OR FIELD(estado, 'aceptado', 'sent', 'delivered', 'read') < FIELD(:estado3, 'aceptado', 'sent', 'delivered', 'read'))
");

$stmtYaRespondido = $conexion->prepare("
    SELECT 1 FROM wa_mensajes
    WHERE telefono = :telefono AND tipo = 'respuesta'
      AND creado_en > NOW() - INTERVAL 24 HOUR
    LIMIT 1
");

$respuesta = "Hola, este número de Zabisu solo envía avisos de pedidos y no recibe mensajes.\n\n"
           . "Para dudas o cambios escríbenos aquí: https://wa.me/" . WHATSAPP_DUDAS;

try {
    foreach ($data["entry"] ?? [] as $entrada) {
        foreach ($entrada["changes"] ?? [] as $cambio) {
            $valor = $cambio["value"] ?? [];

            foreach ($valor["statuses"] ?? [] as $s) {
                $error = null;
                if (!empty($s["errors"][0])) {
                    $e     = $s["errors"][0];
                    $error = mb_substr(($e["error_data"]["details"] ?? $e["title"] ?? "Error") . " (" . ($e["code"] ?? "?") . ")", 0, 255);
                }
                $stmtEstado->execute([
                    ":estado"  => $s["status"] ?? "",
                    ":estado2" => $s["status"] ?? "",
                    ":estado3" => $s["status"] ?? "",
                    ":error"   => $error,
                    ":wamid"   => $s["id"] ?? "",
                ]);
            }

            foreach ($valor["messages"] ?? [] as $m) {
                $telefono = waApiTelefono($m["from"] ?? "");
                if ($telefono === "") continue;
                $stmtYaRespondido->execute([":telefono" => $telefono]);
                if ($stmtYaRespondido->fetchColumn()) continue;

                $r = waApiTexto($telefono, $respuesta);
                waApiRegistrar($conexion, $r, $telefono, 'respuesta', null);
            }
        }
    }
} catch (Throwable $e) {
    // Meta reintenta si no recibe 200; un error nuestro no debe provocar reintentos sin fin
    error_log("webhook whatsapp: " . $e->getMessage());
}

http_response_code(200);
echo "ok";
