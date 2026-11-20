<?php
/*
    Notificaciones push al cliente (app web / navegador), sin librerías:
    solo openssl de PHP (cifrado RFC 8291 aes128gcm y firma VAPID RFC 8292).

    Tablas (se crean solas la primera vez, como las de Finanzas):
    - push_claves:        el par de claves VAPID del sitio (se genera solo una vez).
    - push_suscripciones: cada celular/navegador que aceptó avisos.
    - push_pedido:        qué suscripción recibe avisos de qué pedido.
    - push_enviados:      candado para no mandar dos veces el mismo aviso.
    - push_menu_enviados: candado del aviso "ya está el menú" (uno por menú).

    Cuándo se avisa (pushAvisoPedido):
    - 'confirmado' al imprimir el ticket (imprimir_y_notificar.php)
    - 'pago'       al confirmar una transferencia (confirmar_pago.php)
    - 'llegada'    al avisar que el pedido llegó al punto (notificar_ruta.php / cron)
    Y a todos los celulares con avisos, cuando se publica el menú (pushAvisoMenu,
    desde restaurante/cron_menu.php cada 5 minutos).
    Un error aquí nunca detiene lo demás: todo va dentro de try/catch.
*/

const PUSH_SUJETO = "https://zabisu.com";

/* Servicios de push válidos (evita que alguien nos haga llamar a otra URL) */
const PUSH_HOSTS = [
    "fcm.googleapis.com",
    "updates.push.services.mozilla.com",
    "push.services.mozilla.com",
    "web.push.apple.com",
    "api.push.apple.com",
];

function pushPrepararTablas(PDO $c): void
{
    static $hecho = false;
    if ($hecho) return;
    $c->exec("CREATE TABLE IF NOT EXISTS push_claves (
        id        TINYINT     NOT NULL PRIMARY KEY,
        publica   VARCHAR(100) NOT NULL,
        privada   TEXT        NOT NULL,
        creado_en TIMESTAMP   DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $c->exec("CREATE TABLE IF NOT EXISTS push_suscripciones (
        id_suscripcion INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
        endpoint_hash  CHAR(64)     NOT NULL,
        endpoint       VARCHAR(1000) NOT NULL,
        p256dh         VARCHAR(200) NOT NULL,
        auth           VARCHAR(100) NOT NULL,
        agente         VARCHAR(255) NULL,
        creado_en      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        ultimo_envio   DATETIME     NULL,
        UNIQUE KEY uk_endpoint (endpoint_hash)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $c->exec("CREATE TABLE IF NOT EXISTS push_pedido (
        id_pedido      INT NOT NULL,
        id_suscripcion INT NOT NULL,
        creado_en      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id_pedido, id_suscripcion),
        KEY idx_suscripcion (id_suscripcion)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $c->exec("CREATE TABLE IF NOT EXISTS push_enviados (
        id_pedido  INT         NOT NULL,
        tipo       VARCHAR(20) NOT NULL,
        enviados   INT         NOT NULL DEFAULT 0,
        creado_en  TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id_pedido, tipo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $c->exec("CREATE TABLE IF NOT EXISTS push_menu_enviados (
        id_menu    INT       NOT NULL PRIMARY KEY,
        enviados   INT       NOT NULL DEFAULT 0,
        creado_en  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $hecho = true;
}

function pushB64u(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), "+/", "-_"), "=");
}

function pushB64uDec(string $txt): string
{
    $txt = strtr($txt, "-_", "+/");
    return (string)base64_decode($txt . str_repeat("=", (4 - strlen($txt) % 4) % 4), true);
}

/* Clave pública EC P-256 "cruda" (65 bytes: 0x04 || X || Y) de una clave de openssl */
function pushPublicaCruda($clave): string
{
    $d = openssl_pkey_get_details($clave);
    $x = str_pad($d["ec"]["x"], 32, "\0", STR_PAD_LEFT);
    $y = str_pad($d["ec"]["y"], 32, "\0", STR_PAD_LEFT);
    return "\x04" . $x . $y;
}

/* Clave pública cruda (65 bytes) → objeto de openssl */
function pushPublicaDesdeCruda(string $cruda)
{
    $der = hex2bin("3059301306072a8648ce3d020106082a8648ce3d030107034200") . $cruda;
    $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    return openssl_pkey_get_public($pem);
}

function pushNuevaClaveEc()
{
    return openssl_pkey_new(["curve_name" => "prime256v1", "private_key_type" => OPENSSL_KEYTYPE_EC]);
}

/* Claves VAPID del sitio: se generan una sola vez y se guardan en la BD */
function pushClaves(PDO $c): array
{
    pushPrepararTablas($c);
    $fila = $c->query("SELECT publica, privada FROM push_claves WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    if ($fila) return ["publica" => $fila["publica"], "privada" => $fila["privada"]];

    $clave = pushNuevaClaveEc();
    openssl_pkey_export($clave, $pem);
    $publica = pushB64u(pushPublicaCruda($clave));
    $c->prepare("INSERT IGNORE INTO push_claves (id, publica, privada) VALUES (1, :pub, :priv)")
      ->execute([":pub" => $publica, ":priv" => $pem]);
    // Si dos peticiones la generaron a la vez, se queda la primera
    $fila = $c->query("SELECT publica, privada FROM push_claves WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    return ["publica" => $fila["publica"], "privada" => $fila["privada"]];
}

/* Firma ES256 de openssl (DER) → 64 bytes r || s, como pide JWT */
function pushDerARaw(string $der): string
{
    $pos = 2;
    if (ord($der[1]) & 0x80) $pos += ord($der[1]) & 0x7f;
    $partes = [];
    for ($i = 0; $i < 2; $i++) {
        $pos++; // 0x02
        $len = ord($der[$pos++]);
        $num = substr($der, $pos, $len);
        $pos += $len;
        $num = ltrim($num, "\0");
        $partes[] = str_pad($num, 32, "\0", STR_PAD_LEFT);
    }
    return $partes[0] . $partes[1];
}

function pushJwt(string $endpoint, string $privadaPem): string
{
    $u   = parse_url($endpoint);
    $aud = $u["scheme"] . "://" . $u["host"];
    $cab = pushB64u(json_encode(["typ" => "JWT", "alg" => "ES256"]));
    $cue = pushB64u(json_encode(["aud" => $aud, "exp" => time() + 12 * 3600, "sub" => PUSH_SUJETO]));
    $datos = $cab . "." . $cue;
    openssl_sign($datos, $firmaDer, openssl_pkey_get_private($privadaPem), OPENSSL_ALGO_SHA256);
    return $datos . "." . pushB64u(pushDerARaw($firmaDer));
}

/* Cifra el mensaje para una suscripción (RFC 8291, aes128gcm, un solo registro) */
function pushCifrar(string $mensaje, string $uaPublica, string $authSecreto): string
{
    $efimera     = pushNuevaClaveEc();
    $asPublica   = pushPublicaCruda($efimera);
    $secretoEcdh = openssl_pkey_derive(pushPublicaDesdeCruda($uaPublica), $efimera);
    if ($secretoEcdh === false) throw new RuntimeException("No se pudo derivar la clave");

    $info = "WebPush: info\0" . $uaPublica . $asPublica;
    $ikm  = hash_hkdf("sha256", $secretoEcdh, 32, $info, $authSecreto);
    $sal  = random_bytes(16);
    $cek  = hash_hkdf("sha256", $ikm, 16, "Content-Encoding: aes128gcm\0", $sal);
    $nonce = hash_hkdf("sha256", $ikm, 12, "Content-Encoding: nonce\0", $sal);

    $tag = "";
    $cifrado = openssl_encrypt($mensaje . "\x02", "aes-128-gcm", $cek, OPENSSL_RAW_DATA, $nonce, $tag, "", 16);
    if ($cifrado === false) throw new RuntimeException("No se pudo cifrar");

    return $sal . pack("N", 4096) . chr(65) . $asPublica . $cifrado . $tag;
}

function pushEndpointValido(string $endpoint): bool
{
    $u = parse_url($endpoint);
    if (!$u || ($u["scheme"] ?? "") !== "https" || empty($u["host"])) return false;
    $host = strtolower($u["host"]);
    foreach (PUSH_HOSTS as $h) {
        if ($host === $h) return true;
    }
    // Windows (Edge) usa subdominios de notify.windows.com
    return (bool)preg_match('/(^|\.)notify\.windows\.com$/', $host);
}

/* Guarda (o actualiza) una suscripción y la liga a un pedido */
function pushVincular(PDO $c, int $idPedido, array $sub, string $agente = ""): bool
{
    pushPrepararTablas($c);
    $endpoint = (string)($sub["endpoint"] ?? "");
    $p256dh   = (string)($sub["keys"]["p256dh"] ?? "");
    $auth     = (string)($sub["keys"]["auth"] ?? "");
    if (!pushEndpointValido($endpoint) || strlen($endpoint) > 1000) return false;
    if (strlen(pushB64uDec($p256dh)) !== 65 || strlen(pushB64uDec($auth)) !== 16) return false;

    $hash = hash("sha256", $endpoint);
    $c->prepare(
        "INSERT INTO push_suscripciones (endpoint_hash, endpoint, p256dh, auth, agente)
         VALUES (:h, :e, :p, :a, :ag)
         ON DUPLICATE KEY UPDATE p256dh = VALUES(p256dh), auth = VALUES(auth), agente = VALUES(agente)"
    )->execute([":h" => $hash, ":e" => $endpoint, ":p" => $p256dh, ":a" => $auth, ":ag" => mb_substr($agente, 0, 255)]);

    $st = $c->prepare("SELECT id_suscripcion FROM push_suscripciones WHERE endpoint_hash = :h");
    $st->execute([":h" => $hash]);
    $idSub = (int)$st->fetchColumn();
    if ($idSub <= 0) return false;

    $c->prepare("INSERT IGNORE INTO push_pedido (id_pedido, id_suscripcion) VALUES (:p, :s)")
      ->execute([":p" => $idPedido, ":s" => $idSub]);
    return true;
}

/*
    Manda un mensaje a varias suscripciones a la vez (curl_multi).
    $subs: filas de push_suscripciones. Devuelve cuántas lo aceptaron.
    Las que el servicio da por vencidas (404/410) se borran.
*/
function pushEnviar(PDO $c, array $subs, array $mensaje, int $ttl = 86400, string $urgencia = "normal"): int
{
    if (!$subs) return 0;
    $claves = pushClaves($c);
    $json   = json_encode($mensaje, JSON_UNESCAPED_UNICODE);

    $multi = curl_multi_init();
    $manejadores = [];
    foreach ($subs as $s) {
        try {
            $cuerpo = pushCifrar($json, pushB64uDec($s["p256dh"]), pushB64uDec($s["auth"]));
            $jwt    = pushJwt($s["endpoint"], $claves["privada"]);
        } catch (Throwable $e) {
            continue;
        }
        $ch = curl_init($s["endpoint"]);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $cuerpo,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_HTTPHEADER     => [
                "Content-Type: application/octet-stream",
                "Content-Encoding: aes128gcm",
                "TTL: " . $ttl,
                "Urgency: " . $urgencia,
                "Authorization: vapid t=" . $jwt . ", k=" . $claves["publica"],
            ],
        ]);
        curl_multi_add_handle($multi, $ch);
        $manejadores[] = [$ch, (int)$s["id_suscripcion"]];
    }

    do {
        $estado = curl_multi_exec($multi, $activos);
        if ($activos) curl_multi_select($multi, 1.0);
    } while ($activos && $estado === CURLM_OK);

    $ok = 0;
    $stOk    = $c->prepare("UPDATE push_suscripciones SET ultimo_envio = NOW() WHERE id_suscripcion = :id");
    $stBorra = $c->prepare("DELETE FROM push_suscripciones WHERE id_suscripcion = :id");
    $stDesl  = $c->prepare("DELETE FROM push_pedido WHERE id_suscripcion = :id");
    foreach ($manejadores as [$ch, $idSub]) {
        $codigo = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($codigo >= 200 && $codigo < 300) {
            $ok++;
            $stOk->execute([":id" => $idSub]);
        } elseif ($codigo === 404 || $codigo === 410) {
            $stBorra->execute([":id" => $idSub]);
            $stDesl->execute([":id" => $idSub]);
        } else {
            error_log("Push: respuesta " . $codigo . " del servicio para la suscripción " . $idSub);
        }
        curl_multi_remove_handle($multi, $ch);
    }
    curl_multi_close($multi);
    return $ok;
}

/*
    Aviso de un pedido ('confirmado', 'pago' o 'llegada'), una sola vez por tipo.
    Devuelve cuántos celulares lo recibieron (0 si no tiene avisos activados).
*/
function pushAvisoPedido(PDO $c, int $idPedido, string $tipo): int
{
    try {
        pushPrepararTablas($c);

        $st = $c->prepare(
            "SELECT s.* FROM push_suscripciones s
               JOIN push_pedido pp ON pp.id_suscripcion = s.id_suscripcion
              WHERE pp.id_pedido = :id"
        );
        $st->execute([":id" => $idPedido]);
        $subs = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$subs) return 0;

        // Candado: si ya se mandó este tipo de aviso para el pedido, no se repite
        $candado = $c->prepare("INSERT IGNORE INTO push_enviados (id_pedido, tipo) VALUES (:id, :t)");
        $candado->execute([":id" => $idPedido, ":t" => $tipo]);
        if ($candado->rowCount() === 0) return 0;

        $st = $c->prepare(
            "SELECT p.folio, p.nombre_cliente, h.hora_entrega, u.nombre_ubicacion
               FROM pedidos p
               JOIN horarios_ubicacion h ON h.id_horario = p.id_horario
               JOIN ubicaciones u        ON u.id_ubicacion = h.id_ubicacion
              WHERE p.id_pedido = :id"
        );
        $st->execute([":id" => $idPedido]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p) return 0;

        $folio  = strtoupper($p["folio"]);
        $hora   = date("g:i a", strtotime($p["hora_entrega"]));
        $punto  = $p["nombre_ubicacion"];
        $nombre = trim((string)$p["nombre_cliente"]);
        $hola   = "Hola" . ($nombre !== "" ? " " . $nombre : "");
        $url    = "/cliente/estado_pedido.php?folio=" . rawurlencode($p["folio"]);

        // Mismos textos que los WhatsApp (sin negritas: la notificación no las muestra)
        switch ($tipo) {
            case "confirmado":
                $msg = ["titulo" => "¡Pedido confirmado!",
                        "cuerpo" => $hola . ", gracias por tu pedido en Zabisu 🍽️\n\n"
                                  . "📦 Folio: " . $folio . "\n"
                                  . "📍 Entrega: " . $punto . "\n"
                                  . "🕑 Hora: " . $hora . "\n\n"
                                  . "Te avisaremos por aquí en cuanto lleguemos a tu punto de entrega."];
                $ttl = 6 * 3600; $urg = "normal";
                break;
            case "pago":
                // No hay WhatsApp de este aviso: mismo tono que los otros dos
                $msg = ["titulo" => "¡Pago recibido!",
                        "cuerpo" => $hola . ", ya confirmamos tu transferencia del pedido " . $folio . " de Zabisu. ✅\n\n"
                                  . "📍 Entrega: " . $punto . "\n"
                                  . "🕑 Hora: " . $hora . "\n\n"
                                  . "¡Gracias! Te avisaremos por aquí en cuanto lleguemos a tu punto de entrega."];
                $ttl = 6 * 3600; $urg = "normal";
                break;
            case "llegada":
                $msg = ["titulo" => "¡Tu pedido llegó!",
                        "cuerpo" => $hola . ", ya estamos en 📍 " . $punto . " con tu pedido " . $folio . " de Zabisu.\n\n"
                                  . "Pasa a recogerlo cuando puedas. ¡Buen provecho! 🍽️"];
                $ttl = 2 * 3600; $urg = "high";
                break;
            default:
                return 0;
        }
        $msg["url"] = $url;
        $msg["tag"] = "pedido-" . $idPedido . "-" . $tipo;

        $enviados = pushEnviar($c, $subs, $msg, $ttl, $urg);
        $c->prepare("UPDATE push_enviados SET enviados = :n WHERE id_pedido = :id AND tipo = :t")
          ->execute([":n" => $enviados, ":id" => $idPedido, ":t" => $tipo]);
        return $enviados;
    } catch (Throwable $e) {
        error_log("Push (" . $tipo . ", pedido " . $idPedido . "): " . $e->getMessage());
        return 0;
    }
}

/*
    "Ya está el menú": cuando el menú del día ya se ve en la página
    (activo y dentro de su horario), se avisa una sola vez a todos los
    celulares con avisos. La primera vez que corre (tabla vacía) solo marca
    el menú que ya estaba publicado, para no avisar tarde de uno viejo.
    Devuelve cuántos celulares lo recibieron.
*/
function pushAvisoMenu(PDO $c): int
{
    try {
        pushPrepararTablas($c);

        $menu = $c->query(
            "SELECT m.id_menu, m.fecha, m.pedido_hasta
               FROM menu_dia m
              WHERE m.activo = 1
                AND m.publicado_desde <= NOW() AND m.pedido_hasta > NOW()
                AND EXISTS (SELECT 1 FROM productos p WHERE p.id_menu = m.id_menu)
              ORDER BY m.publicado_desde DESC
              LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);

        // Primera vez: marca (fila 0) y el menú que ya estaba publicado, sin avisar
        $candado = $c->prepare("INSERT IGNORE INTO push_menu_enviados (id_menu) VALUES (:id)");
        if ((int)$c->query("SELECT COUNT(*) FROM push_menu_enviados")->fetchColumn() === 0) {
            $candado->execute([":id" => 0]);
            if ($menu) $candado->execute([":id" => (int)$menu["id_menu"]]);
            return 0;
        }
        if (!$menu) return 0;
        $idMenu = (int)$menu["id_menu"];

        // Candado: un aviso por menú
        $candado->execute([":id" => $idMenu]);
        if ($candado->rowCount() === 0) return 0;

        // Texto: día, platos fuertes del menú Zabisu y hora de cierre
        $dias   = ["domingo", "lunes", "martes", "miércoles", "jueves", "viernes", "sábado"];
        $fecha  = strtotime($menu["fecha"]);
        $hoy    = strtotime(date("Y-m-d"));
        if ($fecha === $hoy)              $cuando = "de hoy";
        elseif ($fecha === $hoy + 86400)  $cuando = "de mañana";
        else                              $cuando = "del " . $dias[(int)date("w", $fecha)] . " " . date("j", $fecha);

        $st = $c->prepare(
            "SELECT nombre FROM productos
              WHERE id_menu = :id AND categoria = 'Plato fuerte' AND disponible = 1
              ORDER BY tipo_menu = 'Zabisu' DESC, id_producto
              LIMIT 3"
        );
        $st->execute([":id" => $idMenu]);
        $platos = $st->fetchAll(PDO::FETCH_COLUMN);
        if (count($platos) > 1) {
            $ultimo = array_pop($platos);
            $lista  = implode(", ", $platos) . " y " . $ultimo;
        } else {
            $lista = $platos[0] ?? "";
        }

        $cierre = strtotime($menu["pedido_hasta"]);
        $horaCierre = date("g:i", $cierre) . " " . (date("A", $cierre) === "AM" ? "am" : "pm");
        $diaCierre  = date("Y-m-d", $cierre) === date("Y-m-d") ? "hoy" : ($dias[(int)date("w", $cierre)]);

        $msg = [
            "titulo" => "¡Ya está el menú " . $cuando . "! 🍽️",
            "cuerpo" => ($lista !== "" ? $lista . ".\n\n" : "")
                      . "Pide antes de las " . $horaCierre . " (" . $diaCierre . ").",
            "url"    => "/cliente/menu.php",
            "tag"    => "menu-" . $idMenu,
        ];
        $ttl = max(600, min(86400, $cierre - time()));

        // A todos, en grupos de 100
        $enviados = 0;
        $desde = 0;
        $st = $c->prepare("SELECT * FROM push_suscripciones WHERE id_suscripcion > :desde ORDER BY id_suscripcion LIMIT 100");
        while (true) {
            $st->execute([":desde" => $desde]);
            $grupo = $st->fetchAll(PDO::FETCH_ASSOC);
            if (!$grupo) break;
            $enviados += pushEnviar($c, $grupo, $msg, $ttl, "normal");
            $desde = (int)end($grupo)["id_suscripcion"];
        }

        $c->prepare("UPDATE push_menu_enviados SET enviados = :n WHERE id_menu = :id")
          ->execute([":n" => $enviados, ":id" => $idMenu]);
        return $enviados;
    } catch (Throwable $e) {
        error_log("Push (menú): " . $e->getMessage());
        return 0;
    }
}
