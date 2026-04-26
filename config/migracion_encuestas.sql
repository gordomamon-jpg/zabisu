-- Migración: tablas para sugerencias de plato fuerte y feedback de pedidos
-- Ejecutar en phpMyAdmin o MySQL CLI

CREATE TABLE IF NOT EXISTS sugerencias_plato (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sugerencia VARCHAR(200) NOT NULL,
    fecha DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS feedback_pedidos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_pedido INT NULL,
    folio VARCHAR(50) NULL,
    calificacion TINYINT NOT NULL,
    comentario TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
