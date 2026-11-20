<?php
/*
 * cron_menu.php — solo desde CLI. El cron del VPS lo llama cada 5 minutos:
 *   1. Activa el menú programado en cuanto llega su "Publicar desde"
 *      (antes esperaba a la primera visita).
 *   2. Si el menú ya se ve en la página y no se ha avisado, manda la
 *      notificación "¡Ya está el menú!" a todos los celulares con avisos.
 *
 * crontab:  *\/5 * * * * php /var/www/html/zabisu/restaurante/cron_menu.php >> /var/log/zabisu_cron_menu.log 2>&1
 */
if (php_sapi_name() !== "cli") {
    http_response_code(403);
    exit("Acceso denegado.\n");
}

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../includes/menu_programado.php";
require_once __DIR__ . "/../includes/push.php";

date_default_timezone_set("America/Mexico_City");

menuActivarProgramado($conexion);
$enviados = pushAvisoMenu($conexion);

if ($enviados > 0) {
    echo "[" . date("Y-m-d H:i:s") . "] Aviso del menú enviado a {$enviados} celular(es).\n";
}
