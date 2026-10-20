-- Migración: registro de mensajes de la API oficial de WhatsApp
-- (confirmaciones, avisos de llegada y respuestas automáticas).
-- El webhook actualiza `estado`: aceptado → sent → delivered → read, o failed.
-- Ejecutar en phpMyAdmin o MySQL CLI

CREATE TABLE IF NOT EXISTS wa_mensajes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    wamid VARCHAR(128) NULL,
    id_pedido INT NULL,
    telefono VARCHAR(20) NOT NULL,
    tipo ENUM('confirmacion', 'llegada', 'respuesta') NOT NULL,
    estado VARCHAR(20) NOT NULL DEFAULT 'aceptado',
    error VARCHAR(255) NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP NULL,
    UNIQUE KEY uk_wamid (wamid),
    KEY idx_pedido (id_pedido),
    KEY idx_telefono_tipo (telefono, tipo, creado_en)
);
