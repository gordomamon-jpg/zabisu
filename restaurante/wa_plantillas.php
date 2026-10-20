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
 *   php restaurante/wa_plantillas.php suscribir
 *   php restaurante/wa_plantillas.php demo 5512345678 "Ana"
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
            // Meta no acepta emojis en el título (HEADER), solo en el cuerpo
            ["type" => "HEADER", "format" => "TEXT", "text" => "¡Pedido confirmado!"],
            [
                "type"    => "BODY",
                "text"    => "Hola {{1}}, gracias por tu pedido en Zabisu 🍽️\n\n"
                           . "📦 Folio: *{{2}}*\n"
                           . "📍 Entrega: *{{3}}*\n"
                           . "🕑 Hora: *{{4}}*\n\n"
                           . "Te avisaremos por aquí en cuanto lleguemos a tu punto de entrega.",
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
            ["type" => "HEADER", "format" => "TEXT", "text" => "¡Tu pedido llegó!"],
            [
                "type"    => "BODY",
                "text"    => "Hola {{1}}, ya estamos en 📍 *{{2}}* con tu pedido *{{3}}* de Zabisu.\n\n"
                           . "Pasa a recogerlo cuando puedas. ¡Buen provecho! 🍽️",
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

/*
    probar TELÉFONO: manda un texto de prueba. Fuera de plantillas, Meta
    solo lo entrega si ese teléfono le escribió al número de avisos en las
    últimas 24 h, así que primero manda un "hola" desde ese celular.
*/
if ($accion === "probar") {
    $r = waApiTexto($argv[2] ?? "", "Prueba de Zabisu: los avisos por WhatsApp ya funcionan.");
    exit(($r["ok"] ? "Enviado (id " . $r["id"] . ")." : "ERROR — " . $r["error"]) . "\n");
}

/*
    demo TELÉFONO: manda las 2 plantillas con datos de ejemplo, igual que a
    un cliente real (no hace falta que ese número escriba primero). Solo
    funciona con las plantillas ya aprobadas; Meta cobra los 2 mensajes.
*/
if ($accion === "demo") {
    $ejemplo = [
        "id_pedido"        => 0,
        "telefono"         => $argv[2] ?? "",
        "nombre_cliente"   => $argv[3] ?? "Cliente de prueba",
        "folio"            => $folioEjemplo,
        "nombre_ubicacion" => "City Market",
        "hora_entrega"     => "14:00:00",
    ];
    require_once __DIR__ . "/../config/db.php";
    foreach (["confirmación" => "waEnviarConfirmacion", "llegada" => "waEnviarLlegada"] as $tipo => $funcion) {
        $r = $funcion($conexion, $ejemplo);
        echo str_pad($tipo, 14) . ($r["ok"] ? "enviada (id " . $r["id"] . ")" : "ERROR — " . $r["error"]) . "\n";
    }
    exit;
}

// suscribir: conecta la app a la cuenta de WhatsApp para que lleguen sus webhooks
if ($accion === "suscribir") {
    $r = waApiPeticion($config['waba_id'] . "/subscribed_apps", []);
    exit(($r["ok"] ? "App suscrita a la cuenta de WhatsApp." : "ERROR — " . $r["error"]) . "\n");
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
if (empty($r["data"]["data"])) exit("La cuenta aún no tiene plantillas.\n");
foreach ($r["data"]["data"] ?? [] as $t) {
    echo str_pad($t["name"], 24) . str_pad($t["language"], 8) . str_pad($t["status"], 12) . $t["category"]
       . (!empty($t["rejected_reason"]) && $t["rejected_reason"] !== "NONE" ? "  motivo: " . $t["rejected_reason"] : "") . "\n";
}
