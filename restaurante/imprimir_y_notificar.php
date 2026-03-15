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
    $html = "";

    foreach ($menusPedido as $menu) {
        $idPedidoMenu = $menu["id_pedido_menu"];
        $tipoMenu = $menu["tipo_menu"];
        $numeroMenu = $menu["numero_menu"];
        $precioMenu = $preciosMenus[$tipoMenu] ?? null;

        $agrupado = [];
        foreach (($detallePorMenu[$idPedidoMenu] ?? []) as $detalle) {
            $agrupado[$detalle["categoria"]][] = $detalle["nombre_producto"];
        }

        $html .= "
            <div style='margin-bottom:14px;padding:16px;background:#fff;border:1px solid #e5e5e5;border-radius:10px;'>
                <div style='display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;padding-bottom:10px;border-bottom:1px dashed #e0e0e0;'>
                    <span style='font-weight:700;color:#222;font-size:15px;'>Menú {$numeroMenu}</span>
                    <span style='font-weight:700;color:#ff7a00;font-size:14px;'>" . htmlspecialchars($tipoMenu) . "</span>
                </div>
        ";

        foreach ($agrupado as $categoria => $items) {
            $html .= "
                <div style='display:flex;gap:10px;padding:6px 0;border-bottom:1px solid #f0f0f0;'>
                    <span style='color:#888;font-weight:700;font-size:13px;min-width:110px;'>" . htmlspecialchars($categoria) . "</span>
                    <span style='color:#333;font-size:13px;'>" . htmlspecialchars(implode(", ", $items)) . "</span>
                </div>
            ";
        }

        $html .= "
                <p style='margin:12px 0 0;text-align:right;font-weight:700;color:#ff7a00;font-size:15px;'>" .
                    ($precioMenu !== null ? "$" . number_format((float)$precioMenu, 2) : "—") .
                "</p>
            </div>
        ";
    }

    return $html;
}

try {
    /*
        5. Enviar correo solo si no se ha enviado antes
    */
    if ((int)$pedido["correo_enviado"] === 0) {
        $correo_cliente = trim($pedido["correo_cliente"] ?? "");
        $nombre_cliente = trim($pedido["nombre_cliente"] ?? "Cliente");
        $folio = trim($pedido["folio"] ?? "");
        $totalPedido = (float)($pedido["total"] ?? 0);
        $ubicacionCorreo = $pedido["nombre_ubicacion"] ?? "Ubicación pendiente";
        $horaCorreo = !empty($pedido["hora_entrega"])
            ? date("g:i A", strtotime($pedido["hora_entrega"]))
            : "Horario pendiente";

        if ($correo_cliente !== "" && filter_var($correo_cliente, FILTER_VALIDATE_EMAIL)) {
            $resumenMenusCorreo = construirResumenCorreoNotificacion($menusPedido, $detallePorMenu, $preciosMenus);

            $asunto = "Pedido confirmado · Zabisu";

            $mensaje = "
                <div style='font-family:Arial,Helvetica,sans-serif;max-width:520px;margin:0 auto;color:#222;line-height:1.6;'>

                    <div style='background:#0c0c0f;padding:28px 24px 20px;border-radius:14px 14px 0 0;text-align:center;'>
                        <p style='margin:0 0 6px;font-size:11px;font-weight:700;letter-spacing:3px;color:#ff7a00;text-transform:uppercase;'>
                            Zabisu
                        </p>
                        <h1 style='margin:0;font-size:26px;font-weight:800;color:#fff;'>
                            ¡Pedido confirmado!
                        </h1>
                    </div>

                    <div style='background:#f9f9f9;padding:24px;border-radius:0 0 14px 14px;'>
                        <p>Hola, <strong>" . htmlspecialchars($nombre_cliente) . "</strong>.</p>
                        <p>Tu pedido ya fue liberado por Zabisu y está en camino. Aquí tienes el resumen:</p>

                        <table style='width:100%;border-collapse:collapse;margin:20px 0;'>
                            <tr style='border-bottom:1px solid #e5e5e5;'>
                                <td style='padding:10px 0;color:#888;font-weight:700;width:160px;'>Folio</td>
                                <td style='padding:10px 0;font-weight:700;color:#222;'>" . htmlspecialchars($folio) . "</td>
                            </tr>
                            <tr style='border-bottom:1px solid #e5e5e5;'>
                                <td style='padding:10px 0;color:#888;font-weight:700;'>Método de pago</td>
                                <td style='padding:10px 0;color:#222;'>" . htmlspecialchars($pedido["metodo_pago"]) . "</td>
                            </tr>
                            <tr style='border-bottom:1px solid #e5e5e5;'>
                                <td style='padding:10px 0;color:#888;font-weight:700;'>Estado de pago</td>
                                <td style='padding:10px 0;color:#222;'>" . htmlspecialchars($pedido["estado_pago"]) . "</td>
                            </tr>
                            <tr style='border-bottom:1px solid #e5e5e5;'>
                                <td style='padding:10px 0;color:#888;font-weight:700;'>Total</td>
                                <td style='padding:10px 0;font-weight:700;color:#ff7a00;font-size:16px;'>$" . number_format($totalPedido, 2) . "</td>
                            </tr>
                            <tr style='border-bottom:1px solid #e5e5e5;'>
                                <td style='padding:10px 0;color:#888;font-weight:700;'>Punto de entrega</td>
                                <td style='padding:10px 0;color:#222;'>" . htmlspecialchars($ubicacionCorreo) . "</td>
                            </tr>
                            <tr>
                                <td style='padding:10px 0;color:#888;font-weight:700;'>Horario</td>
                                <td style='padding:10px 0;color:#222;'>" . htmlspecialchars($horaCorreo) . "</td>
                            </tr>
                        </table>

                        <h2 style='font-size:16px;font-weight:700;color:#222;margin:24px 0 12px;border-top:2px solid #e5e5e5;padding-top:20px;'>
                            Desglose de tu pedido
                        </h2>

                        " . $resumenMenusCorreo . "

                        <p style='margin-top:20px;'>Gracias por elegir Zabisu. ¡Buen provecho!</p>
                        <p style='margin-top:24px;font-size:13px;color:#aaa;'>
                            Zabisu — <em>Sabor y Servicio</em>
                        </p>
                    </div>

                </div>
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

    header("Location: ticket.php?id=" . urlencode($id_pedido));
    exit;

} catch (Exception $e) {
    die("Ocurrió un error al imprimir y notificar: " . $e->getMessage());
}