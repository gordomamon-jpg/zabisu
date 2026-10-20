<?php
/*
    Credenciales de la API oficial de WhatsApp (Cloud API de Meta).
    Copia este archivo como config/whatsapp.php en el servidor y llénalo
    ahí; config/whatsapp.php no se sube a git.

    Dónde sale cada dato (developers.facebook.com → app "Zabisu Avisos"):
    - token:           clave permanente de un usuario del sistema del
                       portafolio Zabisu, con permisos whatsapp_business_messaging
                       y whatsapp_business_management.
    - phone_number_id: WhatsApp → Configuración de la API, debajo del número.
    - waba_id:         el identificador de la cuenta de WhatsApp Business, mismo lugar.
    - app_secret:      Configuración de la app → Básica → Clave secreta de la app.
    - verify_token:    un texto inventado; el mismo se escribe en Meta al
                       configurar el webhook (https://zabisu.com/webhooks/whatsapp.php).
*/
return [
    'token'           => '',
    'phone_number_id' => '',
    'waba_id'         => '',
    'app_secret'      => '',
    'verify_token'    => '',
    'version'         => 'v23.0',
];
