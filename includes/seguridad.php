<?php
/*
    Sesión con cookie endurecida (HttpOnly/SameSite/Secure) + verificación de
    origen en peticiones POST. La verificación de origen cierra el hueco de
    CSRF sin necesitar tocar cada formulario/AJAX existente: un navegador
    manda Origin o Referer en todo POST, y una página de otro dominio no
    puede falsificar ese valor.
*/

// Cuánto dura una sesión sin actividad. El servidor borra las sesiones a
// los 24 min (php.ini), y un cliente que se va a su banco a transferir
// puede tardar más: al regresar ya no estaba su pedido.
const DURACION_SESION_SEG = 3 * 60 * 60;

// "Mantener sesión en este celular" del restaurante: 24 h desde que se entra,
// aunque se cierre la app o el navegador.
const DURACION_RECORDAR_SEG = 24 * 60 * 60;

function iniciarSesionSegura(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        // Carpeta propia para las sesiones: la limpieza automática del
        // servidor (sessionclean) solo revisa la carpeta por defecto y usa
        // los 24 min de php.ini. Aquí la limpieza la hace el propio PHP.
        $dirSesiones = __DIR__ . "/../storage/sesiones";
        if (!is_dir($dirSesiones)) @mkdir($dirSesiones, 0700, true);
        if (is_dir($dirSesiones) && is_writable($dirSesiones)) {
            ini_set("session.save_path", $dirSesiones);
            // El servidor guarda la sesión hasta 24 h (por "mantener sesión");
            // las demás vencen a las 3 h sin actividad, revisado abajo.
            ini_set("session.gc_maxlifetime", (string)max(DURACION_SESION_SEG, DURACION_RECORDAR_SEG));
            ini_set("session.gc_probability", "1");
            ini_set("session.gc_divisor", "100");
        }

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

        // Vencimiento: 24 h exactas si se pidió mantener la sesión; si no, 3 h sin actividad
        $ahora   = time();
        $vencida = isset($_SESSION["_recordar_hasta"])
            ? $ahora > (int)$_SESSION["_recordar_hasta"]
            : (isset($_SESSION["_ultima_actividad"]) && $ahora - (int)$_SESSION["_ultima_actividad"] > DURACION_SESION_SEG);
        if ($vencida) {
            $_SESSION = [];
            session_regenerate_id(true);
            setcookie(session_name(), session_id(), [
                "expires" => 0, "path" => "/", "domain" => "",
                "secure" => $esHttps, "httponly" => true, "samesite" => "Lax",
            ]);
        }
        $_SESSION["_ultima_actividad"] = $ahora;
    }
}

/*
    Deja la sesión iniciada 24 h en este dispositivo: la cookie ya no se borra
    al cerrar la app o el navegador, y a las 24 h vence sola.
*/
function mantenerSesion24h(): void
{
    $hasta = time() + DURACION_RECORDAR_SEG;
    $_SESSION["_recordar_hasta"] = $hasta;
    $esHttps = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off")
        || (($_SERVER["HTTP_X_FORWARDED_PROTO"] ?? "") === "https");
    setcookie(session_name(), session_id(), [
        "expires" => $hasta, "path" => "/", "domain" => "",
        "secure" => $esHttps, "httponly" => true, "samesite" => "Lax",
    ]);
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
