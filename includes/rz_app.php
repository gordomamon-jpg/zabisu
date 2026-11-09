<?php
/*
    Etiquetas para instalar el panel del restaurante como app en el celular
    (icono, nombre y pantalla completa). Va en el <head> de las páginas de restaurante/.
*/
?>
    <link rel="manifest" href="manifest.json">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black">
    <meta name="apple-mobile-web-app-title" content="Zabisu Rest.">
    <link rel="apple-touch-icon" href="../assets/img/app/restaurante-180.png">
    <script>if ("serviceWorker" in navigator) navigator.serviceWorker.register("sw.js");</script>
