-- Migración: renombrar categoría "Postre" a "Cortesia" en tabla productos
UPDATE productos SET categoria = 'Cortesia' WHERE categoria = 'Postre';
