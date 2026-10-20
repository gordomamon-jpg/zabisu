<?php
/*
    Canal para avisarle al cliente (confirmación al imprimir el ticket y
    aviso de llegada al punto).

    'correo'   → temporal (oct-2026): el pedido pide correo en lugar de teléfono.
    'api'      → API oficial de WhatsApp (Meta), desde el número de avisos.
                 El pedido pide WhatsApp. Necesita config/whatsapp.php,
                 la tabla wa_mensajes y las plantillas aprobadas
                 (restaurante/wa_plantillas.php).
    'whatsapp' → wa-service no oficial (whatsapp-web.js). Ya no se usa:
                 WhatsApp restringió los números que lo usaron.
*/
const MODO_NOTIFICACION = 'correo';
