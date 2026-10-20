<?php
/*
    Avisos por la API oficial de WhatsApp (Cloud API de Meta), desde el
    número dedicado a avisos. Reemplaza al wa-service no oficial.

    Fuera de la ventana de 24 h solo se pueden mandar plantillas aprobadas
    por Meta (categoría utilidad). Las crea tools/wa_plantillas.php.
    Cada envío queda en la tabla wa_mensajes; el webhook
    (webhooks/whatsapp.php) actualiza ahí si se entregó o se leyó.
*/
require_once __DIR__ . "/canal_whatsapp.php";

const WA_PLANTILLA_CONFIRMACION = 'zabisu_confirmacion';
const WA_PLANTILLA_LLEGADA      = 'zabisu_llegada';
const WA_IDIOMA_PLANTILLAS      = 'es_MX';

function waApiConfig(): array
{
    static $config = null;
    if ($config === null) {
        $ruta   = __DIR__ . "/../config/whatsapp.php";
        $config = is_readable($ruta) ? (require $ruta) : [];
    }
    return $config;
}

function waApiDisponible(): bool
{
    $c = waApiConfig();
    return !empty($c['token']) && !empty($c['phone_number_id']);
}

// 10 dígitos de México → 52XXXXXXXXXX; "" si no es un número usable
// ("0000000000" = pedido sin teléfono real, p. ej. El Bigoton). Los
// mensajes que llegan de celulares de México traen 521XXXXXXXXXX.
function waApiTelefono(?string $telefono): string
{
    $d = preg_replace('/\D/', '', $telefono ?? '');
    if (strlen($d) === 10) $d = '52' . $d;
    if (strlen($d) === 13 && str_starts_with($d, '521')) $d = '52' . substr($d, 3);
    if (strlen($d) !== 12 || preg_match('/^520+$/', $d)) return '';
    return $d;
}

// Meta rechaza parámetros con saltos de línea, tabuladores o más de 4 espacios seguidos
function waApiParam(string $texto): string
{
    return trim(preg_replace('/\s+/u', ' ', $texto));
}

function waApiNombre(string $nombre): string
{
    $nombre = trim(preg_replace('/[^\p{L}\s]/u', '', $nombre));
    if ($nombre === '') return 'cliente';
    $primero = preg_split('/\s+/', $nombre)[0];
    return mb_convert_case(mb_strtolower($primero, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
}

function waApiPeticion(string $ruta, array $payload, string $metodo = 'POST'): array
{
    $c = waApiConfig();
    if (empty($c['token'])) return ['ok' => false, 'error' => 'Falta config/whatsapp.php'];

    $url = 'https://graph.facebook.com/' . ($c['version'] ?? 'v23.0') . '/' . $ruta;
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $metodo,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $c['token']],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    if ($metodo !== 'GET') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
    }
    $respuesta = curl_exec($ch);
    $errCurl   = curl_error($ch);

    if ($errCurl || !$respuesta) return ['ok' => false, 'error' => 'Sin conexión con Meta: ' . $errCurl];

    $data = json_decode($respuesta, true) ?? [];
    if (isset($data['error'])) {
        return [
            'ok'     => false,
            'error'  => ($data['error']['error_user_msg'] ?? $data['error']['message'] ?? 'Error de Meta')
                      . ' (' . ($data['error']['code'] ?? '?') . ')',
            'codigo' => $data['error']['code'] ?? null,
            'data'   => $data,
        ];
    }
    return ['ok' => true, 'data' => $data, 'id' => $data['messages'][0]['id'] ?? null];
}

/*
    $botones: [índice del botón => parámetro], para botones URL con
    variable al final (p. ej. el folio).
*/
function waApiPlantilla(string $telefono, string $plantilla, array $cuerpo, array $botones = []): array
{
    $tel = waApiTelefono($telefono);
    if ($tel === '') return ['ok' => false, 'error' => 'Teléfono no válido'];

    $componentes = [[
        'type'       => 'body',
        'parameters' => array_map(fn($v) => ['type' => 'text', 'text' => waApiParam((string)$v)], $cuerpo),
    ]];
    foreach ($botones as $indice => $valor) {
        $componentes[] = [
            'type'       => 'button',
            'sub_type'   => 'url',
            'index'      => (string)$indice,
            'parameters' => [['type' => 'text', 'text' => (string)$valor]],
        ];
    }

    return waApiPeticion(waApiConfig()['phone_number_id'] . '/messages', [
        'messaging_product' => 'whatsapp',
        'to'                => $tel,
        'type'              => 'template',
        'template'          => [
            'name'       => $plantilla,
            'language'   => ['code' => WA_IDIOMA_PLANTILLAS],
            'components' => $componentes,
        ],
    ]);
}

// Texto libre: solo funciona dentro de las 24 h después de que el cliente escribió
function waApiTexto(string $telefono, string $texto): array
{
    $tel = waApiTelefono($telefono);
    if ($tel === '') return ['ok' => false, 'error' => 'Teléfono no válido'];

    return waApiPeticion(waApiConfig()['phone_number_id'] . '/messages', [
        'messaging_product' => 'whatsapp',
        'to'                => $tel,
        'type'              => 'text',
        'text'              => ['body' => $texto, 'preview_url' => false],
    ]);
}

// Registro en wa_mensajes; si la tabla aún no existe, el envío no se detiene
function waApiRegistrar(PDO $conexion, array $resultado, string $telefono, string $tipo, ?int $idPedido): void
{
    try {
        $conexion->prepare("
            INSERT INTO wa_mensajes (wamid, id_pedido, telefono, tipo, estado, error)
            VALUES (:wamid, :id_pedido, :telefono, :tipo, :estado, :error)
        ")->execute([
            ":wamid"     => $resultado['id'] ?? null,
            ":id_pedido" => $idPedido,
            ":telefono"  => waApiTelefono($telefono) ?: $telefono,
            ":tipo"      => $tipo,
            ":estado"    => $resultado['ok'] ? 'aceptado' : 'failed',
            ":error"     => $resultado['ok'] ? null : mb_substr($resultado['error'] ?? '', 0, 255),
        ]);
    } catch (Throwable $e) {
        error_log("wa_mensajes: " . $e->getMessage());
    }
}

function waHoraBonita(string $hora): string
{
    $ts = strtotime($hora);
    return date("g:i", $ts) . " " . (date("A", $ts) === "AM" ? "am" : "pm");
}

/*
    Confirmación del pedido. $pedido necesita: id_pedido, telefono,
    nombre_cliente, folio, nombre_ubicacion, hora_entrega.
*/
function waEnviarConfirmacion(PDO $conexion, array $pedido): array
{
    $folio = strtoupper($pedido["folio"]);
    $r = waApiPlantilla($pedido["telefono"], WA_PLANTILLA_CONFIRMACION, [
        waApiNombre($pedido["nombre_cliente"]),
        $folio,
        $pedido["nombre_ubicacion"],
        waHoraBonita($pedido["hora_entrega"]),
    ], [0 => $folio, 1 => $folio]);
    waApiRegistrar($conexion, $r, $pedido["telefono"], 'confirmacion', (int)$pedido["id_pedido"]);
    return $r;
}

// Aviso de llegada al punto de entrega
function waEnviarLlegada(PDO $conexion, array $pedido): array
{
    $folio = strtoupper($pedido["folio"]);
    $r = waApiPlantilla($pedido["telefono"], WA_PLANTILLA_LLEGADA, [
        waApiNombre($pedido["nombre_cliente"]),
        $pedido["nombre_ubicacion"],
        $folio,
    ], [0 => $folio]);
    waApiRegistrar($conexion, $r, $pedido["telefono"], 'llegada', (int)$pedido["id_pedido"]);
    return $r;
}
