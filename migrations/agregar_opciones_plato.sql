-- Opciones sin costo por plato fuerte (p. ej. aderezos de la Ensalada Ejecutiva)
-- Correr ANTES de desplegar el código que las usa.

-- Lista de opciones del plato, una por línea (se captura en productos_menu.php)
ALTER TABLE productos
    ADD COLUMN opciones VARCHAR(500) NULL AFTER complementos_max;

-- Opción que eligió el cliente para su plato fuerte
ALTER TABLE detalle_pedido
    ADD COLUMN opcion VARCHAR(100) NULL AFTER nombre_producto;

-- Dejar los aderezos en la última Ensalada Ejecutiva para que el sistema los
-- recuerde y los llene solos al capturar el siguiente menú.
UPDATE productos
SET opciones = CONCAT_WS(CHAR(10), 'Ranch de la Casa', 'Miel Mostaza', 'Mil Islas', 'Sin aderezo')
WHERE tipo_menu = 'Ejecutivo'
  AND categoria = 'Plato fuerte'
  AND nombre = 'Ensalada de la Casa - Ejecutiva'
ORDER BY id_producto DESC
LIMIT 1;
