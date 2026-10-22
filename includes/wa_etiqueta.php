<?php
/*
    Etiqueta del panel con el estado de un aviso de WhatsApp (tabla wa_mensajes,
    que actualiza webhooks/whatsapp.php). Sin registro no pinta nada.
*/
function etiquetaWA(string $texto, ?string $estado): string
{
    if ($estado === null || $estado === '') return '';

    $estados = [
        'aceptado'  => ['wa-et--enviado',   '✓',  'Enviado'],
        'sent'      => ['wa-et--enviado',   '✓',  'Enviado'],
        'delivered' => ['wa-et--entregado', '✓✓', 'Entregado'],
        'read'      => ['wa-et--leido',     '✓✓', 'Leído'],
        'failed'    => ['wa-et--fallo',     '✕',  'No llegó'],
    ];
    [$clase, $marca, $detalle] = $estados[$estado] ?? ['wa-et--enviado', '✓', $estado];

    return '<span class="wa-et ' . $clase . '" title="' . htmlspecialchars("$texto por WhatsApp: $detalle") . '">'
         . '<b>' . $marca . '</b> ' . htmlspecialchars($texto) . '</span>';
}
