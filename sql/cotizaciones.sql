CREATE TABLE IF NOT EXISTS cotizaciones (
    id_cotizacion    INT AUTO_INCREMENT PRIMARY KEY,
    folio            VARCHAR(30)   NOT NULL UNIQUE,
    nombre_cliente   VARCHAR(200)  NOT NULL,
    empresa          VARCHAR(200)  DEFAULT NULL,
    telefono         VARCHAR(30)   DEFAULT NULL,
    correo           VARCHAR(150)  DEFAULT NULL,
    fecha_evento     DATE          DEFAULT NULL,
    lugar_evento     VARCHAR(300)  DEFAULT NULL,
    fecha_cotizacion DATE          NOT NULL,
    vigencia_dias    INT           NOT NULL DEFAULT 15,
    notas            TEXT          DEFAULT NULL,
    total            DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    estado           ENUM('borrador','enviada','aceptada','rechazada') NOT NULL DEFAULT 'borrador',
    created_at       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cotizacion_items (
    id_item          INT AUTO_INCREMENT PRIMARY KEY,
    id_cotizacion    INT           NOT NULL,
    descripcion      VARCHAR(300)  NOT NULL,
    cantidad         DECIMAL(10,2) NOT NULL DEFAULT 1,
    precio_unitario  DECIMAL(10,2) NOT NULL,
    subtotal         DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (id_cotizacion) REFERENCES cotizaciones(id_cotizacion) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
