<?php
/*
    Mensaje de WhatsApp "Pedido confirmado". Lo usan imprimir_y_notificar.php
    (al imprimir el ticket) y reenviar_confirmaciones_wa.php (reenvío de las
    que no salieron), para que el texto sea siempre el mismo.
*/

require_once __DIR__ . "/opciones_plato.php";

function construirResumenWA($menusPedido, $detallePorMenu, $preciosMenus)
{
    $texto = "";
    $orden = ["Plato fuerte","Sopa","Complemento","Agua","Cortesia"];

    foreach ($menusPedido as $menu) {
        $idPedidoMenu = $menu["id_pedido_menu"];
        $agrupado     = [];
        foreach (($detallePorMenu[$idPedidoMenu] ?? []) as $d) {
            $agrupado[$d["categoria"]][] = nombreConOpcion($d);
        }
        $precio = $preciosMenus[$menu["tipo_menu"]] ?? null;

        $texto .= "\n*Menú " . $menu["numero_menu"] . "* — " . $menu["tipo_menu"] . "\n";
        foreach ($orden as $cat) {
            if (empty($agrupado[$cat])) continue;
            $texto .= "  " . $cat . ": " . implode(", ", $agrupado[$cat]) . "\n";
        }
        if ($precio !== null) {
            $texto .= "  $" . number_format((float)$precio, 2) . "\n";
        }
    }
    return $texto;
}

function construirExtrasWA($extras)
{
    if (empty($extras)) return "";

    $texto       = "\n*Extras*\n";
    $totalExtras = 0;
    foreach ($extras as $extra) {
        $sub          = $extra["cantidad"] * $extra["precio_unitario"];
        $totalExtras += $sub;
        $texto .= "  " . $extra["nombre"] . " ×" . (int)$extra["cantidad"] . "\n";
    }
    $texto .= "  $" . number_format($totalExtras, 2) . "\n";
    return $texto;
}

// $pedido debe traer hora_entrega y nombre_ubicacion (join con horarios/ubicaciones)
function mensajeConfirmacionWA(array $pedido, array $menusPedido, array $detallePorMenu, array $preciosMenus, array $extras): string
{
    $horaWA = !empty($pedido["hora_entrega"]) ? date("g:i A", strtotime($pedido["hora_entrega"])) : "";
    $ubicWA = $pedido["nombre_ubicacion"] ?? "";

    return "*Pedido confirmado* · Zabisu\n\n"
         . "Hola, " . ($pedido["nombre_cliente"] ?? "") . ". Tu orden ya está en preparación.\n\n"
         . "Folio: *" . strtoupper($pedido["folio"] ?? "") . "*\n\n"
         . "─────────────────\n"
         . "*Entrega*\n"
         . "{$ubicWA}\n"
         . "{$horaWA}\n\n"
         . "*Pago*\n"
         . ($pedido["metodo_pago"] ?? "") . " · " . ($pedido["estado_pago"] ?? "") . "\n"
         . "─────────────────\n"
         . "*Tu pedido*"
         . construirResumenWA($menusPedido, $detallePorMenu, $preciosMenus)
         . construirExtrasWA($extras)
         . "\n*Total  $" . number_format((float)($pedido["total"] ?? 0), 2) . "*\n\n"
         . "─────────────────\n"
         . "Te avisaremos cuando tu pedido llegue al punto de entrega.\n"
         . "_Zabisu — Sabor y Servicio_";
}

// Carga todo lo necesario de un pedido y arma su mensaje. null si no existe.
function mensajeConfirmacionWAPorId(PDO $conexion, int $idPedido): ?array
{
    $stmt = $conexion->prepare(
        "SELECT p.*, h.hora_entrega, u.nombre_ubicacion
         FROM pedidos p
         INNER JOIN horarios_ubicacion h ON p.id_horario = h.id_horario
         INNER JOIN ubicaciones u ON h.id_ubicacion = u.id_ubicacion
         WHERE p.id_pedido = :id LIMIT 1"
    );
    $stmt->execute([":id" => $idPedido]);
    $pedido = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$pedido) return null;

    $precios = [];
    foreach ($conexion->query("SELECT nombre_menu, precio FROM tipos_menu WHERE activo = 1") as $t) {
        $precios[$t["nombre_menu"]] = (float)$t["precio"];
    }

    $stmtM = $conexion->prepare("SELECT * FROM pedido_menus WHERE id_pedido = :id ORDER BY numero_menu ASC");
    $stmtM->execute([":id" => $idPedido]);
    $menus = $stmtM->fetchAll(PDO::FETCH_ASSOC);

    $detalle = [];
    $stmtD = $conexion->prepare("SELECT * FROM detalle_pedido WHERE id_pedido_menu = :id ORDER BY id_detalle ASC");
    foreach ($menus as $m) {
        $stmtD->execute([":id" => $m["id_pedido_menu"]]);
        $detalle[$m["id_pedido_menu"]] = $stmtD->fetchAll(PDO::FETCH_ASSOC);
    }

    $stmtE = $conexion->prepare(
        "SELECT nombre, categoria, cantidad, precio_unitario FROM pedido_extras WHERE id_pedido = :id ORDER BY id_extra ASC"
    );
    $stmtE->execute([":id" => $idPedido]);

    return [
        "pedido"  => $pedido,
        "mensaje" => mensajeConfirmacionWA($pedido, $menus, $detalle, $precios, $stmtE->fetchAll(PDO::FETCH_ASSOC)),
    ];
}
