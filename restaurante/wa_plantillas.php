<?php
/*
 * wa_plantillas.php
 * Solo ejecutable desde CLI. Manda a revisión de Meta las 2 plantillas de
 * utilidad que usa includes/whatsapp_api.php, o muestra su estado:
 *
 *   php restaurante/wa_plantillas.php crear
 *   php restaurante/wa_plantillas.php estado
 *   php restaurante/wa_plantillas.php registrar 123456
 *   php restaurante/wa_plantillas.php probar 5512345678
 *
 * Reglas de Meta para que sigan como "utilidad" (y no se cobren como
 * marketing): solo datos del pedido, sin promociones ni botones de sí/no.
 */
if (php_sapi_name() !== "cli") {
    http_response_code(403);
    exit("Acceso denegado.\n");
}

require_once __DIR__ . "/../includes/whatsapp_api.php";

$config = waApiConfig();
if (empty($config['token']) || empty($config['waba_id'])) {
    exit("Falta config/whatsapp.php con token y waba_id.\n");
}

$folioEjemplo = "ZAB-20261020-A1B2C";
$pie          = "Este número solo envía avisos";
$urlPedido    = "https://zabisu.com/cliente/estado_pedido.php?folio={{1}}";
$urlDuda      = "https://zabisu.com/cliente/duda.php?folio={{1}}";

$plantillas = [
    [
        "name"       => WA_PLANTILLA_CONFIRMACION,
        "language"   => WA_IDIOMA_PLANTILLAS,
        "category"   => "UTILITY",
        "components" => [
            ["type" => "HEADER", "format" => "TEXT", "text" => "Pedido confirmado"],
            [
                "type"    => "BODY",
                "text"    => "Hola {{1}}, tu pedido {{2}} de Zabisu está confirmado.\n\n"
                           . "Lo entregamos en {{3}} a las {{4}}. Te avisaremos por aquí cuando lleguemos a tu punto de entrega.",
                "example" => ["body_text" => [["Ana", $folioEjemplo, "City Market", "2:00 pm"]]],
            ],
            ["type" => "FOOTER", "text" => $pie],
            ["type" => "BUTTONS", "buttons" => [
                ["type" => "URL", "text" => "Ver mi pedido",  "url" => $urlPedido, "example" => [str_replace("{{1}}", $folioEjemplo, $urlPedido)]],
                ["type" => "URL", "text" => "Tengo una duda", "url" => $urlDuda,   "example" => [str_replace("{{1}}", $folioEjemplo, $urlDuda)]],
            ]],
        ],
    ],
    [
        "name"       => WA_PLANTILLA_LLEGADA,
        "language"   => WA_IDIOMA_PLANTILLAS,
        "category"   => "UTILITY",
        "components" => [
            ["type" => "HEADER", "format" => "TEXT", "text" => "Tu pedido llegó"],
            [
                "type"    => "BODY",
                "text"    => "Hola {{1}}, ya estamos en {{2}} con tu pedido {{3}} de Zabisu. Pasa a recogerlo cuando puedas.",
                "example" => ["body_text" => [["Ana", "City Market", $folioEjemplo]]],
            ],
            ["type" => "FOOTER", "text" => $pie],
            ["type" => "BUTTONS", "buttons" => [
                ["type" => "URL", "text" => "Tengo una duda", "url" => $urlDuda, "example" => [str_replace("{{1}}", $folioEjemplo, $urlDuda)]],
            ]],
        ],
    ],
];

$accion = $argv[1] ?? "estado";

/*
    registrar PIN: último paso para activar el número en la Cloud API, ya
    verificado con el código. El PIN de 6 dígitos queda como verificación
    en dos pasos del número; guárdalo, Meta lo pide si se vuelve a registrar.
*/
if ($accion === "registrar") {
    $pin = $argv[2] ?? "";
    if (!preg_match('/^\d{6}$/', $pin)) exit("Uso: php restaurante/wa_plantillas.php registrar 123456\n");
    $r = waApiPeticion($config['phone_number_id'] . "/register", ["messaging_product" => "whatsapp", "pin" => $pin]);
    exit(($r["ok"] ? "Número registrado." : "ERROR — " . $r["error"]) . "\n");
}

// probar TELÉFONO: manda la plantilla hello_world que Meta crea en cada cuenta nueva
if ($accion === "probar") {
    $r = waApiPeticion($config['phone_number_id'] . "/messages", [
        "messaging_product" => "whatsapp",
        "to"                => waApiTelefono($argv[2] ?? ""),
        "type"              => "template",
        "template"          => ["name" => "hello_world", "language" => ["code" => "en_US"]],
    ]);
    exit(($r["ok"] ? "Enviado (id " . $r["id"] . ")." : "ERROR — " . $r["error"]) . "\n");
}

if ($accion === "crear") {
    foreach ($plantillas as $p) {
        $r = waApiPeticion($config['waba_id'] . "/message_templates", $p);
        echo $p["name"] . ": " . ($r["ok"]
            ? "enviada a revisión (estado: " . ($r["data"]["status"] ?? "?") . ", categoría: " . ($r["data"]["category"] ?? "?") . ")"
            : "ERROR — " . $r["error"]) . "\n";
    }
    exit;
}

$r = waApiPeticion($config['waba_id'] . "/message_templates?fields=name,status,category,language,rejected_reason&limit=50", [], "GET");
if (!$r["ok"]) exit("ERROR — " . $r["error"] . "\n");
foreach ($r["data"]["data"] ?? [] as $t) {
    echo str_pad($t["name"], 24) . str_pad($t["language"], 8) . str_pad($t["status"], 12) . $t["category"]
       . (!empty($t["rejected_reason"]) && $t["rejected_reason"] !== "NONE" ? "  motivo: " . $t["rejected_reason"] : "") . "\n";
}
