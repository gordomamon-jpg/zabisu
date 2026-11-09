<?php
/*
    Menús cargados por adelantado (toda la semana).

    Activación automática: cuando llega el "Publicar desde" de un menú
    (p. ej. lunes 6:30 pm el del martes), se activa solo, igual que si se
    hubiera presionado "Activar" en Gestión de menús. Reglas para no
    estorbar la operación:
      - Solo menús con platillos (nunca un duplicado vacío).
      - Solo mientras está en su horario (publicado ≤ ahora < cierre).
      - Si el menú activo sigue abierto (no ha llegado su cierre), se
        respeta: el siguiente espera a que cierre.
    No hay cron: lo dispara la primera visita (cliente o restaurante)
    después de la hora de publicación.
*/

function menuActivarProgramado(PDO $conexion): void
{
    static $hecho = false;
    if ($hecho) return;
    $hecho = true;

    $idMenu = $conexion->query(
        "SELECT m.id_menu
           FROM menu_dia m
          WHERE m.activo = 0
            AND m.publicado_desde IS NOT NULL AND m.pedido_hasta IS NOT NULL
            AND m.publicado_desde <= NOW() AND m.pedido_hasta > NOW()
            AND EXISTS (SELECT 1 FROM productos p WHERE p.id_menu = m.id_menu)
            AND NOT EXISTS (SELECT 1 FROM menu_dia o WHERE o.activo = 1 AND o.pedido_hasta > NOW())
          ORDER BY m.publicado_desde DESC, m.id_menu DESC
          LIMIT 1"
    )->fetchColumn();
    if (!$idMenu) return;

    try {
        $conexion->beginTransaction();
        $conexion->exec("UPDATE menu_dia SET activo = 0 WHERE activo = 1");
        $conexion->prepare("UPDATE menu_dia SET activo = 1 WHERE id_menu = :id")
                 ->execute([":id" => (int)$idMenu]);
        $conexion->commit();
    } catch (Exception $e) {
        if ($conexion->inTransaction()) $conexion->rollBack();
    }
}

/*
    El menú con el que trabaja el restaurante (tableta, nuevo pedido, Bigotón,
    edición): el activo; si no hay activo (p. ej. lunes después de las 11:50,
    antes de que se publique el del martes), el último que ya se publicó; si
    ninguno se ha publicado, el próximo. Nunca uno sin platillos.
*/
function menuActualRestaurante(PDO $conexion): ?array
{
    menuActivarProgramado($conexion);
    $menu = $conexion->query(
        "SELECT m.*
           FROM menu_dia m
          WHERE EXISTS (SELECT 1 FROM productos p WHERE p.id_menu = m.id_menu)
          ORDER BY m.activo DESC,
                   (m.publicado_desde <= NOW()) DESC,
                   CASE WHEN m.publicado_desde <= NOW() THEN m.publicado_desde END DESC,
                   m.publicado_desde ASC,
                   m.fecha DESC,
                   m.id_menu DESC
          LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    return $menu ?: null;
}
