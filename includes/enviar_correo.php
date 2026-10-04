<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';

/*
    $textoPlano: versión en texto simple escrita a mano. Los filtros de spam
    penalizan los correos cuyo texto alterno es el HTML con las etiquetas
    quitadas (sale revuelto); si no se manda, se genera como antes.
*/
function enviarCorreo($destinatario, $nombreDestinatario, $asunto, $htmlMensaje, ?string $textoPlano = null)
{
    $config = require __DIR__ . '/../config/mail.php';

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = $config['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $config['username'];
        $mail->Password = $config['password'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = $config['port'];
        $mail->CharSet = 'UTF-8';

        $mail->setFrom($config['from_email'], $config['from_name']);
        $mail->addReplyTo($config['from_email'], $config['from_name']);
        $mail->addAddress($destinatario, $nombreDestinatario);
        $mail->XMailer = ' '; // no anunciar "PHPMailer" (señal de envío automatizado)

        $mail->isHTML(true);
        $mail->Subject = $asunto;
        $mail->Body = $htmlMensaje;
        $mail->AltBody = $textoPlano ?? strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlMensaje));

        $mail->send();
        return true;

    } catch (Exception $e) {
        return false;
    }
}