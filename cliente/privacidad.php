<?php
/*
    Aviso de privacidad. Meta lo pide (URL pública) para publicar la app
    "Zabisu Avisos" de la API oficial de WhatsApp.
*/
require_once "../includes/canal_whatsapp.php";

$correoContacto = "zabisusaboryservicio@gmail.com";
$waContacto     = "https://wa.me/" . WHATSAPP_DUDAS;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aviso de privacidad · Zabisu</title>
    <link rel="icon" type="image/png" href="../assets/img/LOGO_NARA.png">
    <meta name="theme-color" content="#FF7A00">
    <link rel="stylesheet" href="../assets/css/styles.css?v=5">
    <style>
        .privacidad .md-hero__marca { font-size: 38px; letter-spacing: -1px; }
        .privacidad h2 { margin-top: 0; }
        .privacidad p, .privacidad li { line-height: 1.6; }
        .privacidad ul { padding-left: 20px; margin: 8px 0 0; }
        .privacidad a { color: #FF7A00; }
    </style>
</head>
<body>
<div class="contenedor privacidad">

    <div class="md-hero">
        <div class="md-hero__glow-top"></div>
        <div class="md-hero__glow-bottom"></div>
        <p class="md-hero__eyebrow">ZABISU</p>
        <div class="md-hero__marca-grupo">
            <img class="md-hero__logo" src="../assets/img/LOGO_BLANCO.png" alt="Zabisu">
            <h1 class="md-hero__marca">Aviso de privacidad</h1>
        </div>
        <p class="md-hero__fecha">Última actualización: 20 de octubre de 2026</p>
    </div>

    <section class="bloque-formulario">
        <h2>Quiénes somos</h2>
        <p>Zabisu es un servicio de comida corrida en la Ciudad de México. Somos responsables de los datos que nos das al hacer un pedido en zabisu.com.</p>
    </section>

    <section class="bloque-formulario">
        <h2>Qué datos usamos</h2>
        <ul>
            <li>Tu nombre.</li>
            <li>Tu número de WhatsApp o tu correo electrónico.</li>
            <li>Tu pedido: lo que pediste, el punto y horario de entrega y las notas que escribas.</li>
            <li>Cómo pagas y, si es por transferencia, el comprobante que subes.</li>
        </ul>
    </section>

    <section class="bloque-formulario">
        <h2>Para qué los usamos</h2>
        <ul>
            <li>Preparar y entregar tu pedido.</li>
            <li>Enviarte por WhatsApp o correo la confirmación de tu pedido y el aviso cuando llegue a tu punto de entrega.</li>
            <li>Atender tus dudas, cambios o aclaraciones.</li>
            <li>Llenar tus datos más rápido la próxima vez que pidas.</li>
        </ul>
        <p>No te enviamos publicidad por WhatsApp a menos que tú lo pidas.</p>
    </section>

    <section class="bloque-formulario">
        <h2>Con quién se comparten</h2>
        <p>No vendemos ni rentamos tus datos. Solo los usan los servicios que necesitamos para operar:</p>
        <ul>
            <li>WhatsApp (Meta), para enviarte los avisos de tu pedido.</li>
            <li>Google (Gmail), para los avisos por correo.</li>
            <li>Nuestro proveedor de servidores, donde se guarda el sistema de pedidos.</li>
        </ul>
    </section>

    <section class="bloque-formulario">
        <h2>Tus derechos</h2>
        <p>Puedes pedir ver, corregir o borrar tus datos, o dejar de recibir avisos por WhatsApp, cuando quieras. Escríbenos y lo atendemos:</p>
        <ul>
            <li>WhatsApp: <a href="<?php echo htmlspecialchars($waContacto); ?>">55 6090 8778</a></li>
            <li>Correo: <a href="mailto:<?php echo htmlspecialchars($correoContacto); ?>"><?php echo htmlspecialchars($correoContacto); ?></a></li>
        </ul>
    </section>

    <section class="bloque-formulario">
        <h2>Cuánto tiempo los guardamos</h2>
        <p>Guardamos tus pedidos para llevar nuestras cuentas y atender aclaraciones. Si nos pides borrar tus datos, los quitamos salvo lo que la ley nos pida conservar.</p>
    </section>

</div>
</body>
</html>
