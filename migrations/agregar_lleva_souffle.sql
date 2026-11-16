-- Casilla "Lleva vasito de salsa o crema" por plato fuerte (inventario: vaso soufflé no.2)
-- Correr ANTES de desplegar el código que la usa.

ALTER TABLE productos
    ADD COLUMN lleva_souffle TINYINT(1) NOT NULL DEFAULT 0 AFTER opciones;

-- Marcar los platos ya capturados con la regla de hoy, para que el sistema
-- los recuerde por nombre al capturar el siguiente menú:
-- Ejecutivo (salvo ensaladas) y tacos, quesadillas o alambre en cualquier menú.
UPDATE productos
   SET lleva_souffle = 1
 WHERE categoria = 'Plato fuerte'
   AND ((tipo_menu = 'Ejecutivo' AND nombre NOT LIKE '%ensalada%')
        OR nombre LIKE '%taco%'
        OR nombre LIKE '%quesadilla%'
        OR nombre LIKE '%alambre%');
