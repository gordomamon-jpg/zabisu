<?php
/*
    Cupo de platos fuertes (limite_pedidos).

    El límite es por pieza: si un plato tiene límite 10 y ya van 8, un pedido
    con 3 menús de ese plato se rechaza (8 + 3 > 10), aunque el plato todavía
    no esté "agotado".

    $platos = [id_producto => cantidad pedida en este pedido]

    Regresa [id_producto => ["nombre" => ..., "quedan" => n]] con los platos
    que NO alcanzan; vacío si todo cabe.

    Con $bloquear = true bloquea las filas de esos platos (FOR UPDATE) para
    que dos pedidos simultáneos por los últimos lugares no pasen los dos.
    En ese caso debe llamarse dentro de una transacción y ANTES de cualquier
    otro SELECT de esa transacción (para que el conteo vea lo último
    confirmado y no un snapshot viejo).
*/
function validarCupoPlatos(PDO $conexion, array $platos, bool $bloquear = false): array
{
    $platos = array_filter($platos, fn($cant) => (int)$cant > 0);
    if (empty($platos)) return [];

    $ids        = array_map('intval', array_keys($platos));
    $marcadores = implode(",", array_fill(0, count($ids), "?"));

    $stmtProd = $conexion->prepare(
        "SELECT id_producto, nombre, categoria, limite_pedidos, agotado_manual
         FROM productos
         WHERE id_producto IN ($marcadores)" . ($bloquear ? " FOR UPDATE" : "")
    );
    $stmtProd->execute($ids);
    $productos = $stmtProd->fetchAll(PDO::FETCH_ASSOC);

    $stmtCont = $conexion->prepare(
        "SELECT dp.id_producto, COUNT(*) AS total
         FROM detalle_pedido dp
         INNER JOIN pedido_menus pm ON dp.id_pedido_menu = pm.id_pedido_menu
         INNER JOIN pedidos p       ON pm.id_pedido = p.id_pedido
         WHERE p.estado != 'Cancelado'
           AND p.es_prueba = 0
           AND dp.id_producto IN ($marcadores)
         GROUP BY dp.id_producto"
    );
    $stmtCont->execute($ids);
    $conteos = [];
    foreach ($stmtCont->fetchAll(PDO::FETCH_ASSOC) as $fila) {
        $conteos[(int)$fila["id_producto"]] = (int)$fila["total"];
    }

    $sinCupo = [];
    foreach ($productos as $prod) {
        if ($prod["categoria"] !== "Plato fuerte") continue;
        $id     = (int)$prod["id_producto"];
        $pedida = (int)$platos[$id];

        if (!empty($prod["agotado_manual"])) {
            $sinCupo[$id] = ["nombre" => $prod["nombre"], "quedan" => 0];
            continue;
        }

        $limite = (int)($prod["limite_pedidos"] ?? 0);
        if ($limite <= 0) continue;

        $quedan = max(0, $limite - ($conteos[$id] ?? 0));
        if ($pedida > $quedan) {
            $sinCupo[$id] = ["nombre" => $prod["nombre"], "quedan" => $quedan];
        }
    }
    return $sinCupo;
}

// Sin escapar: las vistas ya aplican htmlspecialchars al mostrar errores.
function mensajeCupoPlato(array $info): string
{
    $nombre = $info["nombre"];
    if ($info["quedan"] <= 0) {
        return "El plato \"{$nombre}\" ya está agotado. Elige otro.";
    }
    $quedan = $info["quedan"] === 1 ? "queda 1 pieza" : "quedan {$info["quedan"]} piezas";
    return "Del plato \"{$nombre}\" solo {$quedan}. Reduce los menús con ese plato o elige otro.";
}
