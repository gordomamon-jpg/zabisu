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
<html lang='es' bgcolor='#0c0c0f'>
<head>
<meta charset='UTF-8'>
<meta name='viewport' content='width=device-width,initial-scale=1'>
<meta name='color-scheme' content='dark'>
<meta name='supported-color-schemes' content='dark'>
<style>
  body,html{margin:0;padding:0;background:#0c0c0f!important;}
  @media only screen and (max-width:600px){
    .email-body{padding:12px 8px 32px!important;}
    .col-half{display:block!important;width:100%!important;}
    .col-gap{display:none!important;}
  }
</style>
</head>
<body bgcolor='#0c0c0f' style='margin:0;padding:0;background:#0c0c0f;-webkit-text-size-adjust:100%;'>

<!-- Fondo total oscuro -->
<table width='100%' cellpadding='0' cellspacing='0' bgcolor='#0c0c0f' style='background:#0c0c0f;min-height:100%;'>
<tr><td align='center' class='email-body' bgcolor='#0c0c0f' style='background:#0c0c0f;padding:28px 16px 48px;'>

  <!-- Contenedor central -->
  <table width='100%' cellpadding='0' cellspacing='0' style='max-width:520px;'>

    <!-- ══ HEADER: barra naranja superior ══ -->
    <tr><td bgcolor='#ff7a00' height='4' style='background:#ff7a00;height:4px;border-radius:18px 18px 0 0;font-size:0;line-height:0;'>&nbsp;</td></tr>

    <!-- ══ HEADER: logo + título ══ -->
    <tr><td bgcolor='#0f0f12' style='background:#0f0f12;padding:36px 28px 32px;text-align:center;border-left:1px solid #1e1e22;border-right:1px solid #1e1e22;'>

      <!-- Wordmark -->
      <p style='margin:0 0 24px;font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;font-size:11px;font-weight:800;letter-spacing:4px;color:#ff7a00;text-transform:uppercase;'>● ZABISU</p>

      <!-- Ícono de confirmación -->
      <table cellpadding='0' cellspacing='0' align='center' style='margin:0 auto 20px;'>
        <tr><td width='60' height='60' align='center' bgcolor='#0d200d'
          style='background:#0d200d;width:60px;height:60px;border-radius:50%;border:2px solid #4ac86e;
                 font-size:26px;font-weight:900;color:#4ac86e;
                 font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;'>
          ✓
        </td></tr>
      </table>

      <h1 style='margin:0 0 8px;font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;font-size:28px;font-weight:900;color:#ffffff;letter-spacing:-.5px;line-height:1.1;'>
        ¡Tu pedido está<br>confirmado!
      </h1>
      <p style='margin:0 0 28px;font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;font-size:14px;color:rgba(255,255,255,.4);line-height:1.5;'>
        Hola, <strong style='color:rgba(255,255,255,.8);'>" . htmlspecialchars($nombre_cliente) . "</strong> — ya estamos preparando tu pedido.
      </p>

      <!-- Folio pill -->
      <table cellpadding='0' cellspacing='0' align='center'>
        <tr><td bgcolor='#1c1206' style='background:#1c1206;padding:12px 28px;border-radius:12px;border:1px solid rgba(255,122,0,.35);'>
          <p style='margin:0 0 3px;font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;font-size:10px;font-weight:800;letter-spacing:2.5px;color:rgba(255,122,0,.55);text-transform:uppercase;'>Folio del pedido</p>
          <p style='margin:0;font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;font-size:22px;font-weight:900;color:#ff7a00;letter-spacing:2px;'>" . htmlspecialchars($folio) . "</p>
        </td></tr>
      </table>

    </td></tr>

    <!-- ══ BARRA DIVISORA NARANJA ══ -->
    <tr><td bgcolor='#ff7a00' height='2' style='background:linear-gradient(90deg,#ff7a00,#ff9a30);height:2px;font-size:0;line-height:0;'>&nbsp;</td></tr>

    <!-- ══ ENTREGA: ubicación + horario ══ -->
    <tr><td bgcolor='#111114' style='background:#111114;padding:20px 24px;border-left:1px solid #1e1e22;border-right:1px solid #1e1e22;'>
      <p style='margin:0 0 12px;font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;font-size:10px;font-weight:800;letter-spacing:2px;color:rgba(255,255,255,.2);text-transform:uppercase;'>Detalles de entrega</p>
      <table width='100%' cellpadding='0' cellspacing='0'>
        <tr>
          <td width='48%' class='col-half' valign='top'>
            <table cellpadding='0' cellspacing='0' width='100%'>
              <tr><td bgcolor='#1c1c1f' style='background:#1c1c1f;padding:14px 16px;border-radius:12px;border:1px solid #2a2a2d;'>
                <p style='margin:0 0 4px;font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;font-size:10px;font-weight:700;letter-spacing:1.5px;color:rgba(255,255,255,.28);text-transform:uppercase;'>📍 Punto de entrega</p>
                <p style='margin:0;font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;font-size:14px;font-weight:700;color:#e8e8e8;line-height:1.3;'>" . htmlspecialchars($ubicacionCorreo) . "</p>
              </td></tr>
            </table>
          </td>
          <td width='4%' class='col-gap'></td>
          <td width='48%' class='col-half' valign='top'>
            <table cellpadding='0' cellspacing='0' width='100%'>
              <tr><td bgcolor='#1c1c1f' style='background:#1c1c1f;padding:14px 16px;border-radius:12px;border:1px solid #2a2a2d;'>
                <p style='margin:0 0 4px;font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;font-size:10px;font-weight:700;letter-spacing:1.5px;color:rgba(255,255,255,.28);text-transform:uppercase;'>🕐 Horario estimado</p>
                <p style='margin:0;font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;font-size:14px;font-weight:700;color:#e8e8e8;line-height:1.3;'>" . htmlspecialchars($horaCorreo) . "</p>
              </td></tr>
            </table>
          </td>
        </tr>
      </table>
    </td></tr>

    <!-- ══ PAGO ══ -->
    <tr><td bgcolor='#111114' style='background:#111114;padding:0 24px 20px;border-left:1px solid #1e1e22;border-right:1px solid #1e1e22;'>
      <table width='100%' cellpadding='0' cellspacing='0' style='border-radius:12px;overflow:hidden;border:1px solid #272729;'>
        <tr><td bgcolor='#1c1c1f' style='background:#1c1c1f;padding:11px 16px;border-bottom:1px solid #2a2a2d;'>
          <p style='margin:0;font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;font-size:10px;font-weight:800;letter-spacing:2px;color:rgba(255,255,255,.22);text-transform:uppercase;'>💳 Información de pago</p>
        </td></tr>
        <tr><td bgcolor='#161619' style='background:#161619;padding:11px 16px;border-bottom:1px solid #222224;'>
          <table width='100%' cellpadding='0' cellspacing='0'><tr>
            <td style='font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;font-size:12px;color:rgba(255,255,255,.3);font-weight:600;'>Método</td>
            <td align='right' style='font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;font-size:13px;color:#d0d0d0;font-weight:700;'>" . htmlspecialchars($pedido["metodo_pago"]) . "</td>
          </tr></table>
        </td></tr>
        <tr><td bgcolor='#161619' style='background:#161619;padding:11px 16px;'>
          <table width='100%' cellpadding='0' cellspacing='0'><tr>
            <td style='font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;font-size:12px;color:rgba(255,255,255,.3);font-weight:600;'>Estado</td>
            <td align='right' style='font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;font-size:13px;color:#fbbf24;font-weight:700;'>" . htmlspecialchars($pedido["estado_pago"]) . "</td>
          </tr></table>
        </td></tr>
      </table>
    </td></tr>

    <!-- ══ DIVISOR SUTIL ══ -->
    <tr><td bgcolor='#111114' style='background:#111114;padding:0 24px;border-left:1px solid #1e1e22;border-right:1px solid #1e1e22;'>
      <table width='100%' cellpadding='0' cellspacing='0'><tr><td bgcolor='#222225' height='1' style='background:#222225;height:1px;font-size:0;line-height:0;'>&nbsp;</td></tr></table>
    </td></tr>

    <!-- ══ MENÚS ══ -->
    <tr><td bgcolor='#111114' style='background:#111114;padding:20px 24px 0;border-left:1px solid #1e1e22;border-right:1px solid #1e1e22;'>
      <p style='margin:0 0 14px;font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;font-size:10px;font-weight:800;letter-spacing:2px;color:rgba(255,255,255,.2);text-transform:uppercase;'>🍱 Tu pedido</p>
      " . $resumenMenusCorreo . "
      " . $resumenExtrasCorreo . "
    </td></tr>

    <!-- ══ TOTAL ══ -->
    <tr><td bgcolor='#111114' style='background:#111114;padding:4px 24px 24px;border-left:1px solid #1e1e22;border-right:1px solid #1e1e22;'>
      <table width='100%' cellpadding='0' cellspacing='0' style='border-radius:14px;overflow:hidden;border:1px solid rgba(255,122,0,.25);'>
        <tr><td bgcolor='#1d1108' style='background:#1d1108;padding:18px 20px;'>
          <table width='100%' cellpadding='0' cellspacing='0'><tr>
            <td valign='middle'>
              <p style='margin:0 0 2px;font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;font-size:10px;font-weight:800;letter-spacing:2px;color:rgba(255,122,0,.5);text-transform:uppercase;'>Total a pagar</p>
              <p style='margin:0;font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;font-size:11px;color:rgba(255,255,255,.25);'>Incluye todos los menús y extras</p>
            </td>
            <td align='right' valign='middle'>
              <p style='margin:0;font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;font-size:32px;font-weight:900;color:#ff7a00;letter-spacing:-1.5px;line-height:1;'>$" . number_format($totalPedido, 2) . "</p>
            </td>
          </tr></table>
        </td></tr>
      </table>
    </td></tr>

    <!-- ══ BARRA DIVISORA ══ -->
    <tr><td bgcolor='#1e1e22' height='1' style='background:#1e1e22;height:1px;border-left:1px solid #1e1e22;border-right:1px solid #1e1e22;font-size:0;line-height:0;'>&nbsp;</td></tr>

    <!-- ══ ¿QUÉ PASA AHORA? ══ -->
    <tr><td bgcolor='#0e0e11' style='background:#0e0e11;padding:22px 24px;border-left:1px solid #1e1e22;border-right:1px solid #1e1e22;'>
      <p style='margin:0 0 16px;font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;font-size:10px;font-weight:800;letter-spacing:2px;color:rgba(255,255,255,.2);text-transform:uppercase;'>¿Qué pasa ahora?</p>
      <table width='100%' cellpadding='0' cellspacing='0'>

        <!-- Paso 1 -->
        <tr><td style='padding-bottom:12px;'>
          <table cellpadding='0' cellspacing='0' width='100%'>
            <tr>
              <td width='36' valign='top'>
                <table cellpadding='0' cellspacing='0'><tr>
                  <td width='28' height='28' align='center' bgcolor='#1c1206'
                    style='background:#1c1206;width:28px;height:28px;border-radius:50%;border:1px solid rgba(255,122,0,.4);
                           font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;font-size:12px;font-weight:800;color:#ff7a00;'>
                    1
                  </td>
                </tr></table>
              </td>
              <td valign='middle' style='padding-left:4px;'>
                <p style='margin:0 0 1px;font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;font-size:13px;font-weight:700;color:#e0e0e0;'>Preparando tu pedido</p>
                <p style='margin:0;font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;font-size:12px;color:rgba(255,255,255,.3);'>Nuestro equipo ya está cocinando tu orden.</p>
              </td>
            </tr>
          </table>
        </td></tr>

        <!-- Paso 2 -->
        <tr><td style='padding-bottom:12px;'>
          <table cellpadding='0' cellspacing='0' width='100%'>
            <tr>
              <td width='36' valign='top'>
                <table cellpadding='0' cellspacing='0'><tr>
                  <td width='28' height='28' align='center' bgcolor='#1c1206'
                    style='background:#1c1206;width:28px;height:28px;border-radius:50%;border:1px solid rgba(255,122,0,.4);
                           font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;font-size:12px;font-weight:800;color:#ff7a00;'>
                    2
                  </td>
                </tr></table>
              </td>
              <td valign='middle' style='padding-left:4px;'>
                <p style='margin:0 0 1px;font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;font-size:13px;font-weight:700;color:#e0e0e0;'>En camino</p>
                <p style='margin:0;font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;font-size:12px;color:rgba(255,255,255,.3);'>Tu repartidor saldrá antes del horario indicado.</p>
              </td>
            </tr>
          </table>
        </td></tr>

        <!-- Paso 3 -->
        <tr><td>
          <table cellpadding='0' cellspacing='0' width='100%'>
            <tr>
              <td width='36' valign='top'>
                <table cellpadding='0' cellspacing='0'><tr>
                  <td width='28' height='28' align='center' bgcolor='#0d200d'
                    style='background:#0d200d;width:28px;height:28px;border-radius:50%;border:1px solid rgba(74,200,110,.4);
                           font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;font-size:12px;font-weight:800;color:#4ac86e;'>
                    3
                  </td>
                </tr></table>
              </td>
              <td valign='middle' style='padding-left:4px;'>
                <p style='margin:0 0 1px;font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;font-size:13px;font-weight:700;color:#e0e0e0;'>¡A disfrutar!</p>
                <p style='margin:0;font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;font-size:12px;color:rgba(255,255,255,.3);'>Recibe tu pedido en <strong style='color:rgba(255,255,255,.5);'>" . htmlspecialchars($ubicacionCorreo) . "</strong> a las <strong style='color:rgba(255,255,255,.5);'>" . htmlspecialchars($horaCorreo) . "</strong>.</p>
              </td>
            </tr>
          </table>
        </td></tr>

      </table>
    </td></tr>

    <!-- ══ FOOTER ══ -->
    <tr><td bgcolor='#ff7a00' height='2' style='background:#ff7a00;height:2px;font-size:0;line-height:0;'>&nbsp;</td></tr>
    <tr><td bgcolor='#0a0a0c' style='background:#0a0a0c;padding:28px 24px;text-align:center;border-radius:0 0 18px 18px;border:1px solid #1a1a1c;border-top:none;'>

      <p style='margin:0 0 4px;font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;font-size:18px;font-weight:900;color:#ff7a00;letter-spacing:1px;'>ZABISU</p>
      <p style='margin:0 0 18px;font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;font-size:12px;color:rgba(255,255,255,.25);letter-spacing:.5px;'>Sabor y Servicio</p>

      <table cellpadding='0' cellspacing='0' align='center' style='margin:0 auto 18px;'>
        <tr>
          <td style='padding:0 10px;font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;font-size:12px;color:rgba(255,255,255,.3);border-right:1px solid #2a2a2c;'>¡Buen provecho!</td>
          <td style='padding:0 10px;font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;font-size:12px;color:rgba(255,255,255,.3);'>Gracias por tu preferencia</td>
        </tr>
      </table>

      <p style='margin:0;font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;font-size:11px;color:rgba(255,255,255,.15);'>© 2026 Zabisu · Todos los derechos reservados</p>

    </td></tr>

    <!-- ══ ESPACIO INFERIOR (oscuro) ══ -->
    <tr><td bgcolor='#0c0c0f' height='24' style='background:#0c0c0f;height:24px;font-size:0;line-height:0;'>&nbsp;</td></tr>

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