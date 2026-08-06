CREATE TABLE IF NOT EXISTS notas_cuenta (
    id_nota        INT AUTO_INCREMENT PRIMARY KEY,
    folio          VARCHAR(30)   NOT NULL UNIQUE,
    nombre_cliente VARCHAR(200)  NOT NULL,
    telefono       VARCHAR(30)   DEFAULT NULL,
    notas          TEXT          DEFAULT NULL,
    estado         ENUM('abierta','cerrada') NOT NULL DEFAULT 'abierta',
    total          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    created_at     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS notas_cuenta_items (
    id_item         INT AUTO_INCREMENT PRIMARY KEY,
    id_nota         INT           NOT NULL,
    fecha           DATE          NOT NULL,
    descripcion     VARCHAR(200)  NOT NULL,
    cantidad        DECIMAL(10,2) NOT NULL DEFAULT 1,
    precio_unitario DECIMAL(10,2) NOT NULL,
    subtotal        DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (id_nota) REFERENCES notas_cuenta(id_nota) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
