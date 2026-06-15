<?php
require_once "../config/db.php";
require_once "auth_check.php";
require_once "../includes/enviar_correo.php";

$id_pedido = isset($_GET["id"]) ? (int)$_GET["id"] : 0;

if ($id_pedido <= 0) {
    die("No se recibió un pedido válido.");
}

/*
    1. Obtener pedido principal con horario y ubicación
*/
$sqlPedido = "SELECT
                p.*,
                h.hora_entrega,
                u.nombre_ubicacion,
                u.tipo AS tipo_ubicacion
              FROM pedidos p
              INNER JOIN horarios_ubicacion h ON p.id_horario = h.id_horario
              INNER JOIN ubicaciones u ON h.id_ubicacion = u.id_ubicacion
              WHERE p.id_pedido = :id_pedido
              LIMIT 1";
$stmtPedido = $conexion->prepare($sqlPedido);
$stmtPedido->bindParam(":id_pedido", $id_pedido, PDO::PARAM_INT);
$stmtPedido->execute();
$pedido = $stmtPedido->fetch(PDO::FETCH_ASSOC);

if (!$pedido) {
    die("No se encontró el pedido.");
}

/*
    Marcar pedido como visto
*/
if ((int)$pedido["visto"] === 0) {
    $sqlMarcarVisto = "UPDATE pedidos
                       SET visto = 1
                       WHERE id_pedido = :id_pedido";
    $stmtMarcarVisto = $conexion->prepare($sqlMarcarVisto);
    $stmtMarcarVisto->bindParam(":id_pedido", $id_pedido, PDO::PARAM_INT);
    $stmtMarcarVisto->execute();

    $pedido["visto"] = 1;
}

/*
    2. Obtener precios de tipos de menú
*/
$sqlTiposMenu = "SELECT nombre_menu, precio FROM tipos_menu WHERE activo = 1";
$stmtTiposMenu = $conexion->prepare($sqlTiposMenu);
$stmtTiposMenu->execute();
$tiposMenuDB = $stmtTiposMenu->fetchAll(PDO::FETCH_ASSOC);

$preciosMenus = [];
foreach ($tiposMenuDB as $tipo) {
    $preciosMenus[$tipo["nombre_menu"]] = (float)$tipo["precio"];
}

/*
    3. Obtener menús del pedido
*/
$sqlMenus = "SELECT *
             FROM pedido_menus
             WHERE id_pedido = :id_pedido
             ORDER BY numero_menu ASC";
$stmtMenus = $conexion->prepare($sqlMenus);
$stmtMenus->bindParam(":id_pedido", $id_pedido, PDO::PARAM_INT);
$stmtMenus->execute();
$menusPedido = $stmtMenus->fetchAll(PDO::FETCH_ASSOC);

/*
    4. Obtener detalle de cada menú
*/
$detallePorMenu = [];

$sqlDetalle = "SELECT *
               FROM detalle_pedido
               WHERE id_pedido_menu = :id_pedido_menu
               ORDER BY id_detalle ASC";
$stmtDetalle = $conexion->prepare($sqlDetalle);

foreach ($menusPedido as $menu) {
    $stmtDetalle->bindParam(":id_pedido_menu", $menu["id_pedido_menu"], PDO::PARAM_INT);
    $stmtDetalle->execute();
    $detallePorMenu[$menu["id_pedido_menu"]] = $stmtDetalle->fetchAll(PDO::FETCH_ASSOC);
}

function construirResumenCorreoNotificacion($menusPedido, $detallePorMenu, $preciosMenus)
{
    $html    = "";
    $iconos  = ["Plato fuerte"=>"🍽️","Sopa"=>"🥣","Complemento"=>"🥗","Agua"=>"💧","Cortesia"=>"🍬"];
    $orden   = ["Plato fuerte","Sopa","Complemento","Agua","Cortesia"];

    foreach ($menusPedido as $menu) {
        $idPedidoMenu = $menu["id_pedido_menu"];
        $tipoMenu     = $menu["tipo_menu"];
        $numeroMenu   = $menu["numero_menu"];
        $precioMenu   = $preciosMenus[$tipoMenu] ?? null;

        $agrupado = [];
        foreach (($detallePorMenu[$idPedidoMenu] ?? []) as $d) {
            $agrupado[$d["categoria"]][] = $d["nombre_producto"];
        }

        $html .= "
        <table width='100%' cellpadding='0' cellspacing='0' style='margin-bottom:10px;border:1px solid #2c2c2e;border-radius:12px;overflow:hidden;'>
            <tr><td bgcolor='#1c1c1e' style='background:#1c1c1e;padding:12px 16px;border-bottom:1px solid #2c2c2e;'>
                <table width='100%' cellpadding='0' cellspacing='0'><tr>
                    <td style='font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;font-size:12px;font-weight:700;color:rgba(255,255,255,.4);letter-spacing:1.5px;text-transform:uppercase;'>Menú {$numeroMenu}</td>
                    <td align='right' style='font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;font-size:12px;font-weight:700;color:#ff7a00;letter-spacing:.5px;'>" . htmlspecialchars($tipoMenu) . "</td>
                </tr></table>
            </td></tr>
        ";

        foreach ($orden as $cat) {
            if (empty($agrupado[$cat])) continue;
            $icono = $iconos[$cat] ?? "·";
            $esPrincipal = $cat === "Plato fuerte";
            $html .= "
            <tr><td bgcolor='#161618' style='background:#161618;padding:9px 16px;border-bottom:1px solid #242426;'>
                <table width='100%' cellpadding='0' cellspacing='0'><tr>
                    <td width='130' style='font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;font-size:12px;color:rgba(255,255,255,.35);font-weight:600;'>{$icono} " . htmlspecialchars($cat) . "</td>
                    <td align='right' style='font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;font-size:13px;color:" . ($esPrincipal ? "#fff" : "#aaa") . ";font-weight:" . ($esPrincipal ? "700" : "400") . ";'>" . htmlspecialchars(implode(", ", $agrupado[$cat])) . "</td>
                </tr></table>
            </td></tr>
            ";
        }

        if ($precioMenu !== null) {
            $html .= "
            <tr><td bgcolor='#1c1c1e' style='background:#1c1c1e;padding:10px 16px;text-align:right;'>
                <span style='font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;font-size:15px;font-weight:800;color:#ff7a00;'>$" . number_format((float)$precioMenu, 2) . "</span>
            </td></tr>
            ";
        }

        $html .= "</table>";
    }

    return $html;
}

function construirExtrasCorreo($extras)
{
    if (empty($extras)) return "";

    $html = "
    <table width='100%' cellpadding='0' cellspacing='0' style='margin-bottom:10px;border:1px solid #2c2c2e;border-radius:12px;overflow:hidden;'>
        <tr><td bgcolor='#1c1c1e' style='background:#1c1c1e;padding:12px 16px;border-bottom:1px solid #2c2c2e;'>
            <span style='font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;font-size:12px;font-weight:700;color:rgba(255,255,255,.4);letter-spacing:1.5px;text-transform:uppercase;'>Extras</span>
        </td></tr>
    ";

    $totalExtras = 0;
    foreach ($extras as $extra) {
        $sub = $extra["cantidad"] * $extra["precio_unitario"];
        $totalExtras += $sub;
        $html .= "
        <tr><td bgcolor='#161618' style='background:#161618;padding:9px 16px;border-bottom:1px solid #242426;'>
            <table width='100%' cellpadding='0' cellspacing='0'><tr>
                <td style='font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;font-size:13px;color:#aaa;'>" . htmlspecialchars($extra["nombre"]) . " ×" . (int)$extra["cantidad"] . "</td>
                <td align='right' style='font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;font-size:13px;color:#ff7a00;font-weight:700;'>$" . number_format($sub, 2) . "</td>
            </tr></table>
        </td></tr>
        ";
    }

    $html .= "
        <tr><td bgcolor='#1c1c1e' style='background:#1c1c1e;padding:10px 16px;text-align:right;'>
            <span style='font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;font-size:15px;font-weight:800;color:#ff7a00;'>$" . number_format($totalExtras, 2) . "</span>
        </td></tr>
    </table>
    ";

    return $html;
}

try {
    /*
        5. Enviar correo solo si no se ha enviado antes
    */
    if ((int)$pedido["correo_enviado"] === 0) {
        $correo_cliente = trim($pedido["correo_cliente"] ?? "");
        $nombre_cliente = trim($pedido["nombre_cliente"] ?? "Cliente");
        $folio          = trim($pedido["folio"] ?? "");
        $totalPedido    = (float)($pedido["total"] ?? 0);
        $ubicacionCorreo = $pedido["nombre_ubicacion"] ?? "Ubicación pendiente";
        $horaCorreo     = !empty($pedido["hora_entrega"])
            ? date("g:i A", strtotime($pedido["hora_entrega"]))
            : "Horario pendiente";

        // Extras del pedido
        $stmtExtrasCorreo = $conexion->prepare(
            "SELECT nombre, categoria, cantidad, precio_unitario FROM pedido_extras WHERE id_pedido = :id ORDER BY id_extra ASC"
        );
        $stmtExtrasCorreo->bindParam(":id", $id_pedido, PDO::PARAM_INT);
        $stmtExtrasCorreo->execute();
        $extrasCorreo = $stmtExtrasCorreo->fetchAll(PDO::FETCH_ASSOC);

        if ($correo_cliente !== "" && filter_var($correo_cliente, FILTER_VALIDATE_EMAIL)) {
            $resumenMenusCorreo = construirResumenCorreoNotificacion($menusPedido, $detallePorMenu, $preciosMenus);
            $resumenExtrasCorreo = construirExtrasCorreo($extrasCorreo);

            $asunto = "✓ Tu pedido está confirmado · Zabisu";

            $mensaje = "
<!DOCTYPE html>
<html lang='es'>
<head><meta charset='UTF-8'><meta name='viewport' content='width=device-width,initial-scale=1'></head>
<body style='margin:0;padding:0;background:#0c0c0f;'>

<table width='100%' cellpadding='0' cellspacing='0' bgcolor='#0c0c0f' style='background:#0c0c0f;'>
<tr><td align='center' style='padding:24px 16px 40px;'>

    <table width='100%' cellpadding='0' cellspacing='0' style='max-width:520px;'>

        <!-- ══ HEADER ══ -->
        <tr><td bgcolor='#0c0c0f' style='background:#0c0c0f;padding:36px 24px 28px;text-align:center;border-radius:18px 18px 0 0;border:1px solid #1e1e20;border-bottom:none;'>

            <!-- Eyebrow -->
            <p style='margin:0 0 18px;font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;font-size:11px;font-weight:700;letter-spacing:3px;color:#ff7a00;text-transform:uppercase;'>Zabisu</p>

            <!-- Checkmark -->
            <table cellpadding='0' cellspacing='0' align='center' style='margin:0 auto 18px;'>
                <tr><td bgcolor='#1a2e1a' width='56' height='56' align='center'
                    style='background:#1a2e1a;width:56px;height:56px;border-radius:50%;border:2px solid #4ac86e;font-size:24px;font-weight:700;color:#4ac86e;font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;'>
                    ✓
                </td></tr>
            </table>

            <!-- Título -->
            <h1 style='margin:0 0 6px;font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;font-size:26px;font-weight:900;color:#ffffff;letter-spacing:-.5px;'>
                ¡Pedido confirmado!
            </h1>
            <p style='margin:0 0 22px;font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;font-size:14px;color:rgba(255,255,255,.4);'>
                Hola, <strong style='color:rgba(255,255,255,.75);'>" . htmlspecialchars($nombre_cliente) . "</strong> — tu pedido está en camino.
            </p>

            <!-- Folio pill -->
            <table cellpadding='0' cellspacing='0' align='center'>
                <tr><td bgcolor='#1a1208' style='background:#1a1208;padding:10px 22px;border-radius:10px;border:1px solid rgba(255,122,0,.3);'>
                    <p style='margin:0 0 2px;font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;font-size:10px;font-weight:700;letter-spacing:2px;color:rgba(255,122,0,.6);text-transform:uppercase;'>Folio</p>
                    <p style='margin:0;font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;font-size:20px;font-weight:900;color:#ff7a00;letter-spacing:1px;'>" . htmlspecialchars($folio) . "</p>
                </td></tr>
            </table>

        </td></tr>

        <!-- ══ PILLS ENTREGA ══ -->
        <tr><td bgcolor='#111113' style='background:#111113;padding:18px 24px;border-left:1px solid #1e1e20;border-right:1px solid #1e1e20;'>
            <table width='100%' cellpadding='0' cellspacing='0'>
                <tr>
                    <td width='48%'>
                        <table cellpadding='0' cellspacing='0' width='100%'>
                            <tr><td bgcolor='#1c1c1e' style='background:#1c1c1e;padding:12px 14px;border-radius:10px;border:1px solid #2c2c2e;'>
                                <p style='margin:0 0 3px;font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;font-size:10px;font-weight:700;letter-spacing:1.5px;color:rgba(255,255,255,.3);text-transform:uppercase;'>📍 Entrega</p>
                                <p style='margin:0;font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;font-size:13px;font-weight:700;color:#e0e0e0;'>" . htmlspecialchars($ubicacionCorreo) . "</p>
                            </td></tr>
                        </table>
                    </td>
                    <td width='4%'></td>
                    <td width='48%'>
                        <table cellpadding='0' cellspacing='0' width='100%'>
                            <tr><td bgcolor='#1c1c1e' style='background:#1c1c1e;padding:12px 14px;border-radius:10px;border:1px solid #2c2c2e;'>
                                <p style='margin:0 0 3px;font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;font-size:10px;font-weight:700;letter-spacing:1.5px;color:rgba(255,255,255,.3);text-transform:uppercase;'>🕐 Horario</p>
                                <p style='margin:0;font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;font-size:13px;font-weight:700;color:#e0e0e0;'>" . htmlspecialchars($horaCorreo) . "</p>
                            </td></tr>
                        </table>
                    </td>
                </tr>
            </table>
        </td></tr>

        <!-- ══ DATOS DE PAGO ══ -->
        <tr><td bgcolor='#111113' style='background:#111113;padding:0 24px 18px;border-left:1px solid #1e1e20;border-right:1px solid #1e1e20;'>
            <table width='100%' cellpadding='0' cellspacing='0' style='border:1px solid #2c2c2e;border-radius:12px;overflow:hidden;'>
                <tr><td bgcolor='#1c1c1e' style='background:#1c1c1e;padding:12px 16px;border-bottom:1px solid #2c2c2e;'>
                    <span style='font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;font-size:10px;font-weight:700;letter-spacing:1.5px;color:rgba(255,255,255,.3);text-transform:uppercase;'>Pago</span>
                </td></tr>
                <tr><td bgcolor='#161618' style='background:#161618;padding:10px 16px;border-bottom:1px solid #242426;'>
                    <table width='100%' cellpadding='0' cellspacing='0'><tr>
                        <td style='font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;font-size:12px;color:rgba(255,255,255,.35);font-weight:600;'>Método</td>
                        <td align='right' style='font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;font-size:13px;color:#ccc;font-weight:700;'>" . htmlspecialchars($pedido["metodo_pago"]) . "</td>
                    </tr></table>
                </td></tr>
                <tr><td bgcolor='#161618' style='background:#161618;padding:10px 16px;'>
                    <table width='100%' cellpadding='0' cellspacing='0'><tr>
                        <td style='font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;font-size:12px;color:rgba(255,255,255,.35);font-weight:600;'>Estado</td>
                        <td align='right' style='font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;font-size:13px;color:#facc15;font-weight:700;'>" . htmlspecialchars($pedido["estado_pago"]) . "</td>
                    </tr></table>
                </td></tr>
            </table>
        </td></tr>

        <!-- ══ MENÚS ══ -->
        <tr><td bgcolor='#111113' style='background:#111113;padding:0 24px 6px;border-left:1px solid #1e1e20;border-right:1px solid #1e1e20;'>
            <p style='margin:0 0 12px;font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;font-size:10px;font-weight:700;letter-spacing:2px;color:rgba(255,255,255,.25);text-transform:uppercase;'>Tu pedido</p>
            " . $resumenMenusCorreo . "
            " . $resumenExtrasCorreo . "
        </td></tr>

        <!-- ══ TOTAL ══ -->
        <tr><td bgcolor='#111113' style='background:#111113;padding:0 24px 24px;border-left:1px solid #1e1e20;border-right:1px solid #1e1e20;'>
            <table width='100%' cellpadding='0' cellspacing='0' style='border:1px solid rgba(255,122,0,.2);border-radius:12px;overflow:hidden;'>
                <tr><td bgcolor='#1a1208' style='background:#1a1208;padding:16px 20px;'>
                    <table width='100%' cellpadding='0' cellspacing='0'><tr>
                        <td style='font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;font-size:14px;font-weight:700;color:rgba(255,255,255,.5);'>Total</td>
                        <td align='right' style='font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;font-size:28px;font-weight:900;color:#ff7a00;letter-spacing:-1px;'>$" . number_format($totalPedido, 2) . "</td>
                    </tr></table>
                </td></tr>
            </table>
        </td></tr>

        <!-- ══ FOOTER ══ -->
        <tr><td bgcolor='#0c0c0f' style='background:#0c0c0f;padding:24px;text-align:center;border-radius:0 0 18px 18px;border:1px solid #1e1e20;border-top:1px solid #1e1e20;'>
            <p style='margin:0 0 6px;font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;font-size:13px;color:rgba(255,255,255,.5);'>
                Gracias por elegir <strong style='color:#ff7a00;'>Zabisu</strong>. ¡Buen provecho! 🧡
            </p>
            <p style='margin:0;font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;font-size:11px;color:rgba(255,255,255,.2);'>
                © 2026 Zabisu · Sabor y Servicio
            </p>
        </td></tr>

    </table>

</td></tr>
</table>

</body></html>
            ";

            enviarCorreo($correo_cliente, $nombre_cliente, $asunto, $mensaje);
        }

        $sqlCorreoEnviado = "UPDATE pedidos
                             SET correo_enviado = 1
                             WHERE id_pedido = :id_pedido";
        $stmtCorreoEnviado = $conexion->prepare($sqlCorreoEnviado);
        $stmtCorreoEnviado->bindParam(":id_pedido", $id_pedido, PDO::PARAM_INT);
        $stmtCorreoEnviado->execute();
    }

    /*
        6. Descontar inventario de desechable (solo una vez por pedido)
    */
    if ((int)$pedido["inventario_descontado"] === 0) {

        // Contar menús del pedido
        $stmtNumMenus = $conexion->prepare(
            "SELECT COUNT(*) FROM pedido_menus WHERE id_pedido = :id_pedido"
        );
        $stmtNumMenus->execute([":id_pedido" => $id_pedido]);
        $numMenus = (int)$stmtNumMenus->fetchColumn();

        // Extras: tazones (Sopa + Complemento) y botellas (Agua)
        $stmtExtrasInv = $conexion->prepare(
            "SELECT categoria, COALESCE(SUM(cantidad), 0) AS total
               FROM pedido_extras
              WHERE id_pedido = :id_pedido
                AND categoria IN ('Sopa', 'Complemento', 'Agua')
              GROUP BY categoria"
        );
        $stmtExtrasInv->execute([":id_pedido" => $id_pedido]);
        $extrasInv = $stmtExtrasInv->fetchAll(PDO::FETCH_KEY_PAIR);

        $extrasTazon   = (int)($extrasInv["Sopa"]        ?? 0)
                       + (int)($extrasInv["Complemento"] ?? 0);
        $extrasBotella = (int)($extrasInv["Agua"]        ?? 0);

        // Mapa: id_item => cantidad a descontar
        $descuentos = [
            1  => $numMenus,                      // Contenedor con división 8x8
            2  => $numMenus + $extrasTazon,        // Tazón
            3  => $numMenus + $extrasTazon,        // Tapa tazón
            4  => $numMenus,                      // Tenedor
            5  => $numMenus,                      // Cuchillo
            6  => $numMenus,                      // Cuchara
            7  => $numMenus,                      // Cuchara nevera
            8  => $numMenus,                      // Servilleta
            9  => $numMenus,                      // Bolsa con adherible
            10 => $numMenus + $extrasBotella,      // Botella
            11 => $numMenus + $extrasBotella,      // Tapa botella
            12 => $numMenus,                      // Bolsa mediana
        ];

        $stmtDescItem = $conexion->prepare(
            "UPDATE inventario_desechable
                SET stock_actual = stock_actual - :cantidad
              WHERE id_item = :id_item"
        );
        $stmtInsertMov = $conexion->prepare(
            "INSERT INTO inventario_movimientos (id_item, tipo, cantidad, id_pedido)
             VALUES (:id_item, 'descuento', :cantidad, :id_pedido)"
        );

        foreach ($descuentos as $id_item => $cantidad) {
            if ($cantidad > 0) {
                $stmtDescItem->execute([
                    ":cantidad" => $cantidad,
                    ":id_item"  => $id_item,
                ]);
                $stmtInsertMov->execute([
                    ":id_item"   => $id_item,
                    ":cantidad"  => -$cantidad,
                    ":id_pedido" => $id_pedido,
                ]);
            }
        }

        $conexion->prepare(
            "UPDATE pedidos SET inventario_descontado = 1 WHERE id_pedido = :id_pedido"
        )->execute([":id_pedido" => $id_pedido]);
    }

    header("Location: ticket.php?id=" . urlencode($id_pedido));
    exit;

} catch (Exception $e) {
    die("Ocurrió un error al imprimir y notificar: " . $e->getMessage());
}