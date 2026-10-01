<?php

function waToken(): string
{
    static $token = null;
    if ($token === null) {
        $ruta  = __DIR__ . "/../wa-service/.secret";
        $token = is_readable($ruta) ? trim((string)file_get_contents($ruta)) : "";
    }
    return $token;
}

function enviarWhatsApp(string $telefono, string $mensaje): bool
{
    $digitos = preg_replace('/\D/', '', $telefono);
    if (strlen($digitos) === 10) $digitos = '52' . $digitos;
    if (strlen($digitos) < 10) return false;

    $payload = json_encode(['phone' => $digitos, 'message' => $mensaje]);

    $ch = curl_init('http://127.0.0.1:3001/send');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-WA-Token: ' . waToken()],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 3,
    ]);
    $response = curl_exec($ch);
    $err      = curl_error($ch);
    curl_close($ch);

    if ($err || !$response) return false;
    $data = json_decode($response, true);
    return ($data['ok'] ?? false) === true;
}

function enviarWhatsAppBulk(array $mensajes): array
{
    $validos = [];
    foreach ($mensajes as $m) {
        $digitos = preg_replace('/\D/', '', $m['phone'] ?? '');
        if (strlen($digitos) === 10) $digitos = '52' . $digitos;
        if (strlen($digitos) < 10) continue;
        $validos[] = ['phone' => $digitos, 'message' => $m['message']];
    }

    if (empty($validos)) return ['ok' => false, 'queued' => 0, 'error' => 'Sin teléfonos válidos'];

    $payload = json_encode(['messages' => $validos]);

    $ch = curl_init('http://127.0.0.1:3001/send-bulk');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-WA-Token: ' . waToken()],
        CURLOPT_RETURNTRANSFER => true,
        // /send-bulk ahora solo encola el envío y responde de inmediato con
        // un job_id — el envío real ocurre en segundo plano en wa-service y
        // se consulta con consultarEstadoWhatsAppBulk().
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 3,
    ]);
    $response = curl_exec($ch);
    $err      = curl_error($ch);
    curl_close($ch);

    if ($err || !$response) return ['ok' => false, 'queued' => 0, 'error' => 'Servicio WA no disponible'];
    return json_decode($response, true) ?? ['ok' => false, 'queued' => 0];
}

function consultarEstadoWhatsAppBulk(string $jobId): array
{
    $payload = json_encode(['job_id' => $jobId]);

    $ch = curl_init('http://127.0.0.1:3001/bulk-status');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-WA-Token: ' . waToken()],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 3,
    ]);
    $response = curl_exec($ch);
    $err      = curl_error($ch);
    curl_close($ch);

    if ($err || !$response) return ['ok' => false, 'error' => 'Servicio WA no disponible'];
    return json_decode($response, true) ?? ['ok' => false, 'error' => 'Respuesta inválida del servicio WA'];
}

// Primer nombre en formato "Ivonne" a partir de "IVONNE JUÁREZ"; "" si no hay.
function primerNombreWA(string $nombre): string
{
    $nombre = trim(preg_replace('/[^\p{L}\s]/u', '', $nombre));
    if ($nombre === '') return '';
    $primero = preg_split('/\s+/', $nombre)[0];
    return mb_convert_case(mb_strtolower($primero, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
}

// Saludo distinto al azar por contacto, para que la difusión no sea el
// mismo texto idéntico para todos.
function saludoDifusionWA(string $nombre): string
{
    $n = primerNombreWA($nombre);
    $saludos = $n !== ''
        ? ["Hola {$n} 👋", "¡Buen día, {$n}!", "Qué tal, {$n} 😊", "Hola {$n}, ¿cómo estás?"]
        : ["Hola 👋", "¡Buen día!", "Qué tal 😊", "Hola, ¿cómo estás?"];
    return $saludos[array_rand($saludos)];
}

// $contactos = [["telefono" => ..., "nombre" => ...], ...]
function enviarBroadcastWA(array $contactos, string $caption, ?array $imagen = null): array
{
    $recipients = [];
    foreach ($contactos as $c) {
        $d = preg_replace('/\D/', '', $c['telefono'] ?? '');
        if (strlen($d) === 10) $d = '52' . $d;
        if (strlen($d) < 12) continue;
        $saludo = saludoDifusionWA($c['nombre'] ?? '');
        $recipients[] = [
            'phone'   => $d,
            'caption' => $caption !== '' ? $saludo . "\n\n" . $caption : $saludo,
        ];
    }
    if (empty($recipients)) return ['ok' => false, 'queued' => 0, 'error' => 'Sin teléfonos válidos'];

    $payload = ['recipients' => $recipients];
    if ($imagen) $payload['image'] = $imagen;

    $json = json_encode($payload);

    $ch = curl_init('http://127.0.0.1:3001/send-broadcast');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $json,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-WA-Token: ' . waToken()],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 3,
    ]);
    $response = curl_exec($ch);
    $err      = curl_error($ch);
    curl_close($ch);

    if ($err || !$response) return ['ok' => false, 'queued' => 0, 'error' => 'Servicio WA no disponible'];
    return json_decode($response, true) ?? ['ok' => false, 'queued' => 0];
}
