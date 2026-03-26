-- Tabla para registrar notificaciones de llegada al punto
-- Evita duplicados: solo una notificación por fecha_menu + id_horario

CREATE TABLE IF NOT EXISTS notificaciones_ruta (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    fecha_menu     DATE        NOT NULL,
    id_horario     INT         NOT NULL,
    enviado_en     DATETIME    NOT NULL,
    tipo           ENUM('manual','automatico') NOT NULL DEFAULT 'manual',
    total_enviados INT         NOT NULL DEFAULT 0,
    UNIQUE KEY uq_notif (fecha_menu, id_horario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
