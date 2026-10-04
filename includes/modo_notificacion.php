<?php
/*
    Canal para avisarle al cliente (confirmación al imprimir el ticket y
    aviso de llegada al punto).

    'correo'   → temporal (oct-2026), mientras se implementa la API oficial
                 de Meta: el pedido pide correo en lugar de teléfono.
    'whatsapp' → wa-service / API de WhatsApp.
*/
const MODO_NOTIFICACION = 'correo';
