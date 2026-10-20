<?php
/*
    Botón "Tengo una duda" de los avisos de WhatsApp: abre el chat del
    WhatsApp atendido por una persona con el folio ya escrito.
*/
require_once "../includes/canal_whatsapp.php";

$folio = strtoupper(trim($_GET["folio"] ?? ""));
$texto = preg_match('/^[A-Z0-9-]{4,30}$/', $folio)
    ? "Hola, tengo una duda sobre mi pedido " . $folio
    : "Hola, tengo una duda sobre mi pedido";

header("Location: https://wa.me/" . WHATSAPP_DUDAS . "?text=" . rawurlencode($texto), true, 302);
exit;
