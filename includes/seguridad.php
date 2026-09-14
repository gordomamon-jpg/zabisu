<?php
/*
    Sesión con cookie endurecida (HttpOnly/SameSite/Secure) + verificación de
    origen en peticiones POST. La verificación de origen cierra el hueco de
    CSRF sin necesitar tocar cada formulario/AJAX existente: un navegador
    manda Origin o Referer en todo POST, y una página de otro dominio no
    puede falsificar ese valor.
*/

function iniciarSesionSegura(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        $esHttps = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off")
            || (($_SERVER["HTTP_X_FORWARDED_PROTO"] ?? "") === "https");

        session_set_cookie_params([
            "lifetime" => 0,
            "path"     => "/",
            "domain"   => "",
            "secure"   => $esHttps,
            "httponly" => true,
            "samesite" => "Lax",
        ]);
        session_start();
    }
}

function verificarOrigenPeticion(): void
{
    if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") {
        return;
    }

    $hostPropio = $_SERVER["HTTP_HOST"] ?? "";
    $origen     = $_SERVER["HTTP_ORIGIN"]  ?? "";
    $referer    = $_SERVER["HTTP_REFERER"] ?? "";

    $hostRecibido = "";
    if ($origen !== "") {
        $hostRecibido = (string)(parse_url($origen, PHP_URL_HOST) ?? "");
    } elseif ($referer !== "") {
        $hostRecibido = (string)(parse_url($referer, PHP_URL_HOST) ?? "");
    } else {
        // Ningún navegador moderno omite ambos encabezados en un POST;
        // si llegara a pasar, se deja pasar para no bloquear de más.
        return;
    }

    if ($hostRecibido === "" || strcasecmp($hostRecibido, $hostPropio) !== 0) {
        http_response_code(403);
        header("Content-Type: application/json; charset=UTF-8");
        echo json_encode(["ok" => false, "mensaje" => "Solicitud rechazada: origen no válido."]);
        exit;
    }
}

/*
    Límite de intentos de login — sin tabla nueva en la BD, un archivo
    JSON pequeño protegido junto a config/db.php (mismo Deny-from-all
    del .htaccess de esa carpeta).
*/
define("LOGIN_INTENTOS_MAX", 6);
define("LOGIN_BLOQUEO_SEGUNDOS", 300);
define("LOGIN_INTENTOS_ARCHIVO", __DIR__ . "/../config/login_intentos.json");

function loginPuedeIntentar(string $usuario): array
{
    $registro = loginLeerIntentos()[loginClave($usuario)] ?? null;
    if ($registro && ($registro["bloqueado_hasta"] ?? 0) > time()) {
        return [false, $registro["bloqueado_hasta"] - time()];
    }
    return [true, 0];
}

function loginRegistrarFallo(string $usuario): void
{
    $clave    = loginClave($usuario);
    $datos    = loginLeerIntentos();
    $registro = $datos[$clave] ?? ["fallos" => 0, "bloqueado_hasta" => 0];

    if (($registro["bloqueado_hasta"] ?? 0) > 0 && $registro["bloqueado_hasta"] < time()) {
        $registro = ["fallos" => 0, "bloqueado_hasta" => 0];
    }

    $registro["fallos"] = ($registro["fallos"] ?? 0) + 1;
    if ($registro["fallos"] >= LOGIN_INTENTOS_MAX) {
        $registro["bloqueado_hasta"] = time() + LOGIN_BLOQUEO_SEGUNDOS;
        $registro["fallos"] = 0;
    }

    $datos[$clave] = $registro;
    loginGuardarIntentos($datos);
}

function loginLimpiarIntentos(string $usuario): void
{
    $datos = loginLeerIntentos();
    unset($datos[loginClave($usuario)]);
    loginGuardarIntentos($datos);
}

function loginClave(string $usuario): string
{
    return strtolower($usuario) . "|" . ($_SERVER["REMOTE_ADDR"] ?? "");
}

function loginLeerIntentos(): array
{
    if (!file_exists(LOGIN_INTENTOS_ARCHIVO)) return [];
    $datos = json_decode((string)@file_get_contents(LOGIN_INTENTOS_ARCHIVO), true);
    return is_array($datos) ? $datos : [];
}

function loginGuardarIntentos(array $datos): void
{
    $ahora = time();
    foreach ($datos as $k => $r) {
        if (($r["bloqueado_hasta"] ?? 0) < $ahora && ($r["fallos"] ?? 0) === 0) {
            unset($datos[$k]);
        }
    }
    @file_put_contents(LOGIN_INTENTOS_ARCHIVO, json_encode($datos), LOCK_EX);
}
