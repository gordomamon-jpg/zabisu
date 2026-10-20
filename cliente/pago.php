<?php
require_once "../config/db.php";
require_once "../includes/seguridad.php";
require_once "../includes/limite_platos.php";
require_once "../includes/diseno.php";
// Sin esto PHP usa UTC (6 h adelante): la hora de cierre "11:50" se
// interpretaba como 5:50 AM de México y se rechazaban todos los pedidos.
date_default_timezone_set("America/Mexico_City");
iniciarSesionSegura();

if (empty($_SESSION["pedido_temporal"])) {
    // Sesión vencida o pedido ya confirmado: explicarlo en lugar de un texto seco
    http_response_code(200);
    ?><!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Tu pedido no está activo | Zabisu</title>
<link rel="icon" type="image/png" href="../assets/img/LOGO_NARA.png">
<link rel="stylesheet" href="../assets/css/styles.css?v=5">
<link rel="stylesheet" href="../assets/css/cliente-rediseno.css?v=4">
<style>
    body { background: #0a0908 !important; font-family: 'Instrument Sans', Arial, sans-serif; margin: 0; padding: 16px; }
    .exp { max-width: 440px; margin: 12vh auto 0; padding: 28px 22px; text-align: center;
           background: rgba(255,255,255,.05); border: 1px solid rgba(247,236,220,.14); border-radius: 22px; color: #f7ecdc; }
    .exp h1 { font-size: 22px; margin: 0 0 10px; color: #f7ecdc; }
    .exp p { font-size: 15px; line-height: 1.55; color: rgba(247,236,220,.8); margin: 0 0 12px; }
    .exp strong { color: #f7ecdc; }
    .exp a { display: inline-block; margin-top: 8px; padding: 14px 22px; border-radius: 16px; background: #ff7a00;
             color: #0a0908; font-weight: 800; text-decoration: none; }
</style></head><body>
<div class="exp">
    <h1>⏳ Tu pedido ya no está activo</h1>
    <p>Pasó demasiado tiempo sin confirmarlo, o ya lo habías confirmado.</p>
    <p><strong>Si ya transferiste pero no tocaste "Confirmar pedido", tu pedido no quedó registrado.</strong>
       Vuelve a hacerlo y sube tu comprobante; si tienes dudas, escríbenos por WhatsApp.</p>
    <a href="pedido.php">Volver a hacer mi pedido</a>
</div>
</body></html><?php
    exit;
}

$pedidoTemporal = $_SESSION["pedido_temporal"];

/*  Modo prueba  */
$stmtMP = $conexion->prepare("SELECT valor FROM configuracion WHERE clave = 'modo_prueba' LIMIT 1");
$stmtMP->execute();
$esPrueba = (int)($stmtMP->fetchColumn() ?? 0);

$nombre_cliente = $pedidoTemporal["nombre_cliente"] ?? "";
$telefono = $pedidoTemporal["telefono"] ?? "";
$correo_cliente = $pedidoTemporal["correo_cliente"] ?? "";
$observaciones = $pedidoTemporal["observaciones"] ?? "";
$id_horario = $pedidoTemporal["id_horario"] ?? "";
$menusRecibidos = $pedidoTemporal["menus"] ?? [];
$totalPedido    = (float)($pedidoTemporal["total"] ?? 0);
$extrasSession  = $pedidoTemporal["extras"] ?? [];

/*
    Obtener productos para reconstruir nombres/categorías
*/
$sqlProductos = "SELECT * FROM productos WHERE disponible = 1";
$stmtProductos = $conexion->prepare($sqlProductos);
$stmtProductos->execute();
$productos = $stmtProductos->fetchAll(PDO::FETCH_ASSOC);

$productosIndexados = [];
foreach ($productos as $producto) {
    $productosIndexados[$producto["id_producto"]] = $producto;
}

/*
    Obtener precios de tipos de menú
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
    Obtener datos de ubicación y horario del pedido temporal
*/
$sqlHorario = "SELECT
                  h.hora_entrega,
                  u.nombre_ubicacion,
                  u.tipo
               FROM horarios_ubicacion h
               INNER JOIN ubicaciones u ON h.id_ubicacion = u.id_ubicacion
               WHERE h.id_horario = :id_horario
               LIMIT 1";
$stmtHorario = $conexion->prepare($sqlHorario);
$stmtHorario->bindParam(":id_horario", $id_horario, PDO::PARAM_INT);
$stmtHorario->execute();
$datosHorario = $stmtHorario->fetch(PDO::FETCH_ASSOC);


$errores = [];
$mensajeExito = "";

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["finalizar_pedido"])) {
    verificarOrigenPeticion();

    $metodo_pago = trim($_POST["metodo_pago"] ?? "");

    if ($metodo_pago === "") {
        $errores[] = "Debes seleccionar un método de pago.";
    }

    // La sesión ahora dura horas: si el cliente regresa después del cierre
    // de pedidos, no se debe registrar (salvo en modo prueba)
    if (!$esPrueba && !empty($pedidoTemporal["fecha_menu"])) {
        $stmtCierre = $conexion->prepare("SELECT MAX(pedido_hasta) FROM menu_dia WHERE fecha = :fecha AND activo = 1");
        $stmtCierre->execute([":fecha" => $pedidoTemporal["fecha_menu"]]);
        $cierre = $stmtCierre->fetchColumn();
        if ($cierre && time() > strtotime($cierre)) {
            $errores[] = "El horario para hacer pedidos ya cerró (" . date("g:i A", strtotime($cierre)) . "). Tu pedido no se registró.";
        }
    }

    if (!in_array($metodo_pago, ["Transferencia", "Efectivo"])) {
        $errores[] = "Método de pago no válido.";
    }

    // Extensión de salida decidida por el tipo real del archivo (no por lo
    // que mande el navegador en el nombre) — evita subir un ejecutable
    // disfrazado de comprobante.
    $extensionComprobante = "";
    $mimesComprobantePermitidos = [
        "image/jpeg"       => "jpg",
        "image/png"        => "png",
        "application/pdf"  => "pdf",
    ];

    if ($metodo_pago === "Transferencia") {
        if (!isset($_FILES["comprobante_pago"]) || $_FILES["comprobante_pago"]["error"] !== 0) {
            $errores[] = "Debes subir el comprobante de pago.";
        } else {
            $finfo    = finfo_open(FILEINFO_MIME_TYPE);
            $mimeReal = finfo_file($finfo, $_FILES["comprobante_pago"]["tmp_name"]);
            finfo_close($finfo);

            if (!isset($mimesComprobantePermitidos[$mimeReal])) {
                $errores[] = "El comprobante debe ser una imagen (JPG/PNG) o un PDF.";
            } else {
                $extensionComprobante = $mimesComprobantePermitidos[$mimeReal];
            }
        }
    }

    if (empty($errores)) {
        try {
            $conexion->beginTransaction();

            // Cupo de platos fuertes: suma los menús de este pedido y bloquea
            // las filas para que dos pagos casi simultáneos por los últimos
            // lugares no pasen los dos.
            $platosPedido = [];
            foreach ($menusRecibidos as $menu) {
                $idPlato = (int)($menu["plato_fuerte"] ?? 0);
                if ($idPlato) $platosPedido[$idPlato] = ($platosPedido[$idPlato] ?? 0) + 1;
            }
            foreach (validarCupoPlatos($conexion, $platosPedido, true) as $info) {
                $errores[] = mensajeCupoPlato($info) . " Regresa a tu pedido para cambiarlo.";
            }

            if (!empty($errores)) {
                $conexion->rollBack();
            } else {

            $folio = "ZAB-" . date("Ymd") . "-" . strtoupper(substr(md5(uniqid()), 0, 5));
            $referencia_pago = $folio;

            $estado_pago = "Pendiente de pago";
            $comprobante_pago = null;
            $fecha_pago = null;

            if ($metodo_pago === "Transferencia") {
                $nombreOriginal = basename($_FILES["comprobante_pago"]["name"]);
                $nombreArchivo = time() . "_" . preg_replace('/[^A-Za-z0-9_\-]/', '_', pathinfo($nombreOriginal, PATHINFO_FILENAME)) . "." . $extensionComprobante;

                $carpetaDestino = __DIR__ . "/../uploads/comprobantes/";

                if (!is_dir($carpetaDestino)) {
                    if (!mkdir($carpetaDestino, 0777, true)) {
                        throw new Exception("No se pudo crear la carpeta de comprobantes.");
                    }
                }

                $rutaDestino = $carpetaDestino . $nombreArchivo;

                if (!move_uploaded_file($_FILES["comprobante_pago"]["tmp_name"], $rutaDestino)) {
                    throw new Exception("No se pudo guardar el comprobante de pago.");
                }

                $estado_pago = "Pendiente de validación";
                $comprobante_pago = $nombreArchivo;
                $fecha_pago = date("Y-m-d H:i:s");
            }

            if ($metodo_pago === "Efectivo") {
                $estado_pago = "Pago en efectivo";
            }

            /*
                Insertar pedido general
            */
            $sqlPedido = "INSERT INTO pedidos
              (folio, nombre_cliente, telefono, correo_cliente, id_horario, metodo_pago, observaciones, total, estado, id_repartidor, estado_pago, comprobante_pago, referencia_pago, fecha_pago, es_prueba)
              VALUES
              (:folio, :nombre_cliente, :telefono, :correo_cliente, :id_horario, :metodo_pago, :observaciones, :total, 'Pendiente', NULL, :estado_pago, :comprobante_pago, :referencia_pago, :fecha_pago, :es_prueba)";
            $stmtPedido = $conexion->prepare($sqlPedido);
            $stmtPedido->bindParam(":folio", $folio);
            $stmtPedido->bindParam(":nombre_cliente", $nombre_cliente);
            $stmtPedido->bindParam(":telefono", $telefono);
            $stmtPedido->bindParam(":correo_cliente", $correo_cliente);
            $stmtPedido->bindParam(":id_horario", $id_horario, PDO::PARAM_INT);
            $stmtPedido->bindParam(":metodo_pago", $metodo_pago);
            $stmtPedido->bindParam(":observaciones", $observaciones);
            $stmtPedido->bindParam(":total", $totalPedido);
            $stmtPedido->bindParam(":estado_pago", $estado_pago);
            $stmtPedido->bindParam(":comprobante_pago", $comprobante_pago);
            $stmtPedido->bindParam(":referencia_pago", $referencia_pago);
            $stmtPedido->bindParam(":fecha_pago", $fecha_pago);
            $stmtPedido->bindParam(":es_prueba", $esPrueba, PDO::PARAM_INT);
            $stmtPedido->execute();

            $id_pedido = $conexion->lastInsertId();

            /*
                Preparar inserciones para menús
            */
            $sqlPedidoMenu = "INSERT INTO pedido_menus
                              (id_pedido, tipo_menu, numero_menu)
                              VALUES
                              (:id_pedido, :tipo_menu, :numero_menu)";
            $stmtPedidoMenu = $conexion->prepare($sqlPedidoMenu);

            $sqlDetalle = "INSERT INTO detalle_pedido
                           (id_pedido_menu, id_producto, categoria, nombre_producto, opcion)
                           VALUES
                           (:id_pedido_menu, :id_producto, :categoria, :nombre_producto, :opcion)";
            $stmtDetalle = $conexion->prepare($sqlDetalle);

            foreach ($menusRecibidos as $numeroMenu => $menu) {
                $tipo_menu = $menu["tipo_menu"] ?? "";
                $plato_fuerte = $menu["plato_fuerte"] ?? "";
                $sopa = $menu["sopa"] ?? "";
                $agua = $menu["agua"] ?? "";
                $cortesia = $menu["cortesia"] ?? "";
                $complementos = $menu["complementos"] ?? [];

                $stmtPedidoMenu->bindParam(":id_pedido", $id_pedido, PDO::PARAM_INT);
                $stmtPedidoMenu->bindParam(":tipo_menu", $tipo_menu);
                $stmtPedidoMenu->bindParam(":numero_menu", $numeroMenu, PDO::PARAM_INT);
                $stmtPedidoMenu->execute();

                $id_pedido_menu = $conexion->lastInsertId();

                $productosSeleccionados = array_merge(
                    [$plato_fuerte],
                    [$sopa],
                    $complementos,
                    [$agua],
                    [$cortesia]
                );

                foreach ($productosSeleccionados as $idProducto) {
                    if (!isset($productosIndexados[$idProducto])) {
                        continue;
                    }

                    $productoInfo = $productosIndexados[$idProducto];
                    $categoria = $productoInfo["categoria"];
                    $nombre_producto = $productoInfo["nombre"];

                    $stmtDetalle->bindParam(":id_pedido_menu", $id_pedido_menu, PDO::PARAM_INT);
                    $stmtDetalle->bindParam(":id_producto", $idProducto, PDO::PARAM_INT);
                    $stmtDetalle->bindParam(":categoria", $categoria);
                    $stmtDetalle->bindParam(":nombre_producto", $nombre_producto);
                    // La opción (p. ej. aderezo) va solo en el renglón del plato fuerte
                    $opcionDetalle = ($categoria === "Plato fuerte" && (string)$idProducto === (string)$plato_fuerte)
                        ? ($menu["opcion"] ?? null)
                        : null;
                    $stmtDetalle->bindValue(":opcion", $opcionDetalle);
                    $stmtDetalle->execute();
                }
            }

            // Insertar extras
            if (!empty($extrasSession)) {
                $sqlExtra = "INSERT INTO pedido_extras
                             (id_pedido, id_producto, nombre, categoria, cantidad, precio_unitario)
                             VALUES (:id_pedido, :id_producto, :nombre, :categoria, :cantidad, :precio)";
                $stmtExtra = $conexion->prepare($sqlExtra);
                foreach ($extrasSession as $extra) {
                    $stmtExtra->execute([
                        ":id_pedido"  => $id_pedido,
                        ":id_producto"=> $extra["id_producto"],
                        ":nombre"     => $extra["nombre"],
                        ":categoria"  => $extra["categoria"],
                        ":cantidad"   => $extra["cantidad"],
                        ":precio"     => $extra["precio_unitario"],
                    ]);
                }
            }

            $conexion->commit();

            unset($_SESSION["pedido_temporal"]);

            header("Location: confirmar.php?folio=" . urlencode($folio));
            exit;

            }

        } catch (Exception $e) {
            $conexion->rollBack();
            $errores[] = "Ocurrió un error al finalizar el pedido: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pago | Zabisu</title>
    <link rel="icon" type="image/png" href="../assets/img/LOGO_NARA.png">
    <link rel="manifest" href="../manifest.json">
    <meta name="theme-color" content="#FF7A00">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Zabisu">
    <link rel="apple-touch-icon" href="../assets/img/LOGO_NARA.png">
    <link rel="stylesheet" href="../assets/css/styles.css?v=5">
    <link rel="stylesheet" href="../assets/css/cliente-rediseno.css?v=4">
    <style>
    *, *::before, *::after { box-sizing: border-box; }

    /* ── Rediseño: tipografía, marca sólida, vidrio y atmósfera ── */
    body, button, input, textarea, select {
        font-family: 'Instrument Sans', Arial, sans-serif;
    }
    body { position: relative; background: var(--zb-negro) !important; }
    body::before {
        content: '';
        position: fixed; inset: 0; z-index: -1; pointer-events: none;
        background:
            radial-gradient(36vw 30vh at 8% 4%,    rgba(var(--zb-naranja-rgb), .12), transparent 68%),
            radial-gradient(30vw 26vh at 100% 46%, rgba(var(--zb-naranja-rgb), .07), transparent 65%),
            radial-gradient(36vw 30vh at 4% 94%,   rgba(var(--zb-naranja-rgb), .07), transparent 65%),
            var(--zb-negro);
    }
    body::after {
        content: '';
        position: fixed; inset: 0; z-index: -1; pointer-events: none; opacity: .4; mix-blend-mode: overlay;
        background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='120' height='120'><filter id='n'><feTurbulence type='fractalNoise' baseFrequency='0.85' numOctaves='2' stitchTiles='stitch'/><feColorMatrix type='matrix' values='0 0 0 0 1  0 0 0 0 1  0 0 0 0 1  0 0 0 0.05 0'/></filter><rect width='100%25' height='100%25' filter='url(%23n)'/></svg>");
    }

    .contenedor {
        background: rgba(30,24,20,.28) !important;
        backdrop-filter: blur(22px) saturate(160%);
        -webkit-backdrop-filter: blur(22px) saturate(160%);
        box-shadow: inset 0 1px 0 rgba(247,236,220,.14) !important;
    }
    .md-hero {
        background: rgba(30,24,20,.16) !important;
        backdrop-filter: blur(18px) saturate(160%);
        -webkit-backdrop-filter: blur(18px) saturate(160%);
        box-shadow: inset 0 1px 0 rgba(247,236,220,.16) !important;
    }
    .md-hero__marca {
        font-weight: 800;
        letter-spacing: -.02em;
        background: none !important;
        -webkit-background-clip: initial !important;
        background-clip: initial !important;
        -webkit-text-fill-color: var(--zb-crema) !important;
        color: var(--zb-crema) !important;
        text-shadow: 0 0 40px rgba(var(--zb-naranja-rgb), .5);
    }
    .md-hero__logo { filter: drop-shadow(0 0 26px rgba(var(--zb-naranja-rgb), .6)) !important; }
    .md-hero__tagline {
        font-family: 'Instrument Serif', serif;
        font-style: italic;
        font-size: 16px;
        color: var(--zb-naranja);
        margin: 4px 0 6px;
    }

    .conf-card, .pago-card {
        backdrop-filter: blur(16px) saturate(150%);
        -webkit-backdrop-filter: blur(16px) saturate(150%);
    }

    /* ── Cuerpo del formulario ── */
    .pago-body {
        max-width: 560px;
        margin: 0 auto;
        padding: 20px 16px 48px;
        display: flex;
        flex-direction: column;
        gap: 12px;
    }

    /* ── Tarjetas (mismo sistema que confirmar.php) ── */
    .conf-card {
        background: rgba(255,255,255,.03);
        border: 1px solid rgba(255,255,255,.07);
        border-radius: 16px;
        padding: 18px;
    }
    .conf-card__titulo {
        font-size: 12px; font-weight: 700; letter-spacing: 2px;
        text-transform: uppercase; color: rgba(255,255,255,.3);
        margin: 0 0 16px;
    }

    /* ── Cards de método de pago ── */
    .pago-cards {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px;
        margin-bottom: 12px;
    }
    /* Transferencia ocupa el ancho completo arriba */
    .pago-card--transfer { grid-column: 1 / -1; flex-direction: row; align-items: flex-start; gap: 14px; padding: 18px 18px 18px 18px; }
    .pago-card--transfer .pago-card__dot { position: static; margin-left: auto; flex-shrink: 0; align-self: center; }
    .pago-card--transfer .pago-card__badge { margin-bottom: 0; }
    .pago-card--transfer .pago-card__icono { font-size: 30px; margin-bottom: 0; flex-shrink: 0; align-self: center; }
    .pago-card--transfer .pago-card__body { display: flex; flex-direction: column; gap: 3px; flex: 1; min-width: 0; }

    .pago-card {
        position: relative;
        display: flex;
        flex-direction: column;
        padding: 16px 14px 44px;
        border-radius: 14px;
        border: 1.5px solid rgba(255,255,255,.07);
        background: rgba(255,255,255,.02);
        cursor: pointer;
        transition: border-color .2s, background .2s, box-shadow .2s;
        user-select: none;
        -webkit-tap-highlight-color: transparent;
    }
    .pago-card input[type="radio"] {
        position: absolute; opacity: 0; width: 0; height: 0; pointer-events: none;
    }

    .pago-card--transfer {
        border-color: rgba(255,122,0,.22);
        background: rgba(255,122,0,.04);
    }
    .pago-card--transfer.pago-card--activo {
        border-color: #ff7a00;
        background: rgba(255,122,0,.1);
        box-shadow: 0 0 0 1px rgba(255,122,0,.2), 0 4px 24px rgba(255,122,0,.1);
    }
    .pago-card--cash.pago-card--activo {
        border-color: rgba(255,255,255,.28);
        background: rgba(255,255,255,.06);
    }
    .pago-card--card {
        border-color: rgba(74,200,110,.18);
        background: rgba(74,200,110,.03);
    }
    .pago-card--card.pago-card--activo {
        border-color: #4ac86e;
        background: rgba(74,200,110,.09);
        box-shadow: 0 0 0 1px rgba(74,200,110,.2), 0 4px 24px rgba(74,200,110,.08);
    }
    .pago-card--card.pago-card--activo .pago-card__dot { border-color: #4ac86e; }
    .pago-card--card.pago-card--activo .pago-card__dot::after { background: #4ac86e; }

    .pago-card__badge {
        align-self: flex-start;
        font-size: 9px; font-weight: 700; letter-spacing: 1.5px;
        text-transform: uppercase; color: #ff7a00;
        background: rgba(255,122,0,.12);
        border: 1px solid rgba(255,122,0,.25);
        border-radius: 99px; padding: 2px 8px;
        margin-bottom: 10px;
    }
    .pago-card__icono { font-size: 26px; margin-bottom: 6px; display: block; }
    .pago-card__nombre { font-size: 15px; font-weight: 800; color: #fff; margin-bottom: 3px; display: block; }
    .pago-card__desc { font-size: 12px; color: rgba(255,255,255,.35); line-height: 1.4; margin: 0; }

    .pago-card__dot {
        position: absolute; bottom: 14px; right: 14px;
        width: 20px; height: 20px; border-radius: 50%;
        border: 1.5px solid rgba(255,255,255,.15);
        display: flex; align-items: center; justify-content: center;
        transition: border-color .2s;
    }
    .pago-card__dot::after {
        content: ''; width: 8px; height: 8px; border-radius: 50%;
        background: transparent; transition: background .2s;
    }
    .pago-card--transfer.pago-card--activo .pago-card__dot { border-color: #ff7a00; }
    .pago-card--transfer.pago-card--activo .pago-card__dot::after { background: #ff7a00; }
    .pago-card--cash.pago-card--activo .pago-card__dot { border-color: rgba(255,255,255,.45); }
    .pago-card--cash.pago-card--activo .pago-card__dot::after { background: rgba(255,255,255,.6); }

    /* ── Avisos al pie de las cards ── */
    .pago-aviso {
        display: none;
        align-items: flex-start;
        gap: 10px;
        padding: 13px 14px;
        border-radius: 13px;
        font-size: 13px;
        color: rgba(255,255,255,.6);
        line-height: 1.55;
    }
    .pago-aviso.visible { display: flex; }
    .pago-aviso--efectivo {
        background: rgba(250,204,21,.06);
        border: 1px solid rgba(250,204,21,.2);
    }
    .pago-aviso--efectivo strong { color: #facc15; }
    .pago-aviso--tarjeta {
        background: rgba(74,200,110,.06);
        border: 1px solid rgba(74,200,110,.2);
    }
    .pago-aviso--tarjeta strong { color: #4ac86e; }

    /* ── Líneas de datos bancarios ── */
    .pago-lineas { display: flex; flex-direction: column; }
    .pago-linea {
        display: flex; justify-content: space-between; align-items: center;
        padding: 10px 0;
        border-bottom: 1px solid rgba(255,255,255,.05);
        gap: 12px;
    }
    .pago-linea:last-child { border-bottom: none; padding-bottom: 0; }
    .pago-linea__label { font-size: 12px; font-weight: 600; color: rgba(255,255,255,.3); flex-shrink: 0; }
    .pago-linea__val { font-size: 14px; font-weight: 700; color: #e0e0e0; text-align: right; }
    .pago-linea__val--monto { color: #ff7a00; font-size: 18px; }

    /* ── Uploader comprobante ── */
    .pago-upload { display: flex; flex-direction: column; gap: 10px; }
    .pago-upload__desc { font-size: 13px; color: rgba(255,255,255,.4); line-height: 1.5; margin: 0; }
    .pago-upload__btn {
        display: flex; align-items: center; justify-content: center; gap: 8px;
        background: rgba(255,255,255,.05);
        border: 1px solid rgba(255,255,255,.1);
        border-radius: 12px;
        padding: 13px 18px;
        font-size: 14px; font-weight: 700; color: rgba(255,255,255,.75);
        cursor: pointer;
        transition: background .15s, border-color .15s;
        width: 100%;
    }
    .pago-upload__btn:hover { background: rgba(255,255,255,.08); border-color: rgba(255,255,255,.18); }
    .pago-upload__nombre {
        font-size: 13px; color: rgba(255,255,255,.35);
        text-align: center; word-break: break-all;
    }
    .pago-upload__nombre.tiene-archivo { color: #4ac86e; font-weight: 600; }

    /* ── Botón finalizar ── */
    .pago-btn-final {
        display: flex; align-items: center; justify-content: center; gap: 8px;
        width: 100%;
        background: #ff7a00;
        color: #fff;
        border: none;
        border-radius: 14px;
        padding: 16px 24px;
        font-size: 16px; font-weight: 800;
        cursor: pointer;
        transition: opacity .15s, transform .1s;
        letter-spacing: .2px;
    }
    .pago-btn-final:active { opacity: .88; transform: scale(.98); }
    .pago-btn-final__flecha { font-size: 18px; }

    /* ── Error ── */
    .pago-error {
        display: flex; align-items: flex-start; gap: 10px;
        padding: 14px 16px;
        background: rgba(248,113,113,.07);
        border: 1px solid rgba(248,113,113,.2);
        border-radius: 14px;
        font-size: 13px; color: rgba(255,255,255,.7); line-height: 1.55;
    }
    /* Pasos del pago por transferencia */
    .pago-pasos {
        display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px;
        list-style: none; margin: 16px 0; padding: 0;
    }
    .pago-pasos li {
        display: flex; flex-direction: column; align-items: center; gap: 6px; text-align: center;
        font-size: 12.5px; line-height: 1.3; color: rgba(247,236,220,.75);
    }
    .pago-pasos__num {
        display: inline-flex; align-items: center; justify-content: center;
        width: 26px; height: 26px; border-radius: 50%;
        border: 1.5px solid rgba(255,122,0,.8); color: #ff7a00; font-weight: 800; font-size: 13px;
    }
    /* Campo para copiar (CLABE / concepto), a todo lo ancho para que no se parta */
    .copiar-campo { margin-top: 14px; }
    .copiar-campo__label {
        display: block; margin-bottom: 6px; font-size: 12px; letter-spacing: .5px;
        color: rgba(247,236,220,.55);
    }
    .copiar-campo__btn {
        display: flex; align-items: center; justify-content: space-between; gap: 10px;
        width: 100%; padding: 12px 14px; border-radius: 12px; cursor: pointer;
        background: rgba(255,255,255,.04); border: 1px solid rgba(247,236,220,.14);
        font: inherit; color: #f7ecdc; text-align: left;
    }
    .copiar-campo__valor {
        font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 15px; font-weight: 700;
        letter-spacing: .5px; word-break: break-all;
    }
    .copiar-campo__accion { flex-shrink: 0; font-size: 13px; font-weight: 700; color: #ff7a00; }
    .copiar-campo__btn.copiado { border-color: rgba(74,200,110,.6); }
    .copiar-campo__btn.copiado .copiar-campo__accion { color: #4ac86e; }
    .copiar-campo__nota { margin: 6px 0 0; font-size: 12px; line-height: 1.45; color: rgba(247,236,220,.55); }
    .pago-nota-final {
        margin: 18px 4px 10px; text-align: center; font-size: 13px; line-height: 1.5;
        color: rgba(247,236,220,.65);
    }
    .pago-nota-final strong { color: #f7ecdc; }
    .pago-error ul { margin: 0; padding: 0 0 0 16px; }
    .pago-error li { margin-bottom: 4px; }
    .pago-error li:last-child { margin-bottom: 0; }
    </style>
    <?php if ($disenoNuevo): ?>
    <link rel="preload" href="../assets/fonts/kaushan-script-400.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="../assets/fonts/alfa-slab-one-400.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="../assets/css/menu-pizarron.css?v=2">
    <link rel="stylesheet" href="../assets/css/cliente-pizarron.css?v=3">
    <?php endif; ?>
</head>
<body<?php echo $disenoNuevo ? ' class="pz pz-cliente"' : ''; ?>>
<?php if ($disenoNuevo) require "../includes/pz_filtros.php"; ?>

<!-- ── Marca de agua fija ── -->
<img class="zb-marca-watermark" src="../assets/img/LOGO_BLANCO.png" alt="">

<div class="contenedor">

<!-- ══ HERO ══════════════════════════════════════════════════ -->
<?php if ($disenoNuevo): ?>
<header class="pz-cab">
    <div class="pz-marca">
        <img src="../assets/img/LOGO_N_128.png" alt="">
        <div>
            <span class="pz-marca__nombre">Zabisu</span>
            <span class="pz-marca__lema">Sabor y Servicio</span>
        </div>
    </div>
</header>
<h1 class="pz-titulo"><span class="pz-pincel"><span>Tu pago</span></span></h1>
<p class="pc-sub">Último paso: elige cómo pagarás tu pedido.</p>
<?php else: ?>
<div class="md-hero">
    <div class="md-hero__glow-top"></div>
    <div class="md-hero__glow-bottom"></div>
    <p class="md-hero__eyebrow">Último paso</p>
    <div class="md-hero__marca-grupo">
        <img class="md-hero__logo" src="../assets/img/LOGO_BLANCO.png" alt="Zabisu">
        <h1 class="md-hero__marca">Zabisu</h1>
    </div>
    <p class="md-hero__tagline">Sabor y Servicio</p>
    <p class="md-hero__fecha">Elige cómo pagarás tu pedido</p>
</div>
<?php endif; ?>

<?php if ($disenoNuevo):
    $metodoPost      = $_POST["metodo_pago"] ?? "";
    $conceptoEjemplo = strtoupper($nombre_cliente) . " " . date("d-m-Y");
?>
<!-- ══ PASOS DEL PAGO (diseño Pizarrón) ══════════════════════ -->
<form action="" method="POST" enctype="multipart/form-data" class="pg-form">

    <?php if (!empty($errores)): ?>
    <div class="pago-error">
        <ul>
            <?php foreach ($errores as $error): ?>
                <li><?php echo htmlspecialchars($error); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <ol class="pg-pasos">
        <li class="pg-paso pg-paso--metodo">
            <div class="pg-fila">
                <span class="pg-num" aria-hidden="true"></span>
                <div class="pg-cuerpo">
                    <h2 class="pg-titulo">¿Cómo pagas?</h2>
                    <div class="pg-chips" role="radiogroup" aria-label="Método de pago">
                        <label class="pg-chip <?php echo $metodoPost === "Transferencia" ? "pago-card--activo" : ""; ?>" id="card-transfer">
                            <input type="radio" name="metodo_pago" value="Transferencia" <?php echo $metodoPost === "Transferencia" ? "checked" : ""; ?>>
                            Transferencia
                        </label>
                        <label class="pg-chip <?php echo $metodoPost === "Efectivo" ? "pago-card--activo" : ""; ?>" id="card-cash">
                            <input type="radio" name="metodo_pago" value="Efectivo" <?php echo $metodoPost === "Efectivo" ? "checked" : ""; ?>>
                            Efectivo
                        </label>
                    </div>
                </div>
            </div>
        </li>

        <?php
        $pgHora = !empty($datosHorario["hora_entrega"])
            ? date("g:i", strtotime($datosHorario["hora_entrega"])) . " " . (date("A", strtotime($datosHorario["hora_entrega"])) === "AM" ? "am" : "pm")
            : "";
        ?>
        <!-- Efectivo: el JavaScript lo muestra con la clase "visible" -->
        <li class="pg-paso pg-efectivo <?php echo $metodoPost === "Efectivo" ? "visible" : ""; ?>" id="aviso-efectivo">
            <div class="pg-fila">
                <span class="pg-num" aria-hidden="true"></span>
                <div class="pg-cuerpo">
                    <h2 class="pg-titulo">Ten listo tu pago</h2>
                    <div class="pg-cuenta">
                        <span class="pg-cuenta__grapa" aria-hidden="true"></span>
                        <span class="pg-sello">Pago al recibir</span>
                        <p class="pg-cuenta__titulo">La cuenta</p>
                        <div class="pg-cuenta__total"><span>Total</span><b>$<?php echo number_format($totalPedido, 2); ?></b></div>
                        <?php if (!empty($datosHorario["nombre_ubicacion"])): ?>
                        <div class="pg-cuenta__linea"><span>Entrega</span><b><?php echo htmlspecialchars($datosHorario["nombre_ubicacion"]); ?></b></div>
                        <?php endif; ?>
                        <?php if ($pgHora !== ""): ?>
                        <div class="pg-cuenta__linea"><span>Hora</span><b><?php echo $pgHora; ?></b></div>
                        <?php endif; ?>
                        <div class="pg-cuenta__linea"><span>Pagas</span><b>En efectivo</b></div>
                    </div>
                    <p class="pg-mano"><span aria-hidden="true">↑</span> Monto exacto, porfa: no siempre llevamos cambio</p>
                </div>
            </div>
        </li>

        <li class="pg-paso bloque-transferencia">
            <div class="pg-fila">
                <span class="pg-num" aria-hidden="true"></span>
                <div class="pg-cuerpo">
                    <h2 class="pg-titulo">Transfiere</h2>
                    <div class="pg-cuenta">
                        <span class="pg-cuenta__grapa" aria-hidden="true"></span>
                        <p class="pg-cuenta__titulo">La cuenta</p>
                        <div class="pg-cuenta__total"><span>Total</span><b>$<?php echo number_format($totalPedido, 2); ?></b></div>
                        <div class="pg-cuenta__linea"><span>Mercado Pago</span><b>Diana Piña</b></div>

                        <div class="copiar-campo">
                            <span class="copiar-campo__label">CLABE</span>
                            <button type="button" class="copiar-campo__btn" data-copiar="texto-clabe">
                                <span class="copiar-campo__valor" id="texto-clabe">722969014258283039</span>
                                <span class="copiar-campo__accion">Copiar</span>
                            </button>
                        </div>
                        <div class="copiar-campo">
                            <span class="copiar-campo__label">Concepto</span>
                            <button type="button" class="copiar-campo__btn" data-copiar="texto-concepto">
                                <span class="copiar-campo__valor" id="texto-concepto"><?php echo htmlspecialchars($conceptoEjemplo); ?></span>
                                <span class="copiar-campo__accion">Copiar</span>
                            </button>
                        </div>
                        <p class="pg-cuenta__nota">Escribe el concepto en tu transferencia; sin él no podemos validar tu pago.</p>
                    </div>
                </div>
            </div>
        </li>

        <li class="pg-paso bloque-transferencia">
            <div class="pg-fila">
                <span class="pg-num" aria-hidden="true"></span>
                <div class="pg-cuerpo">
                    <h2 class="pg-titulo">Sube tu comprobante</h2>
                    <label for="comprobante_pago" class="pg-subir">
                        <span class="pg-subir__clip" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M7 12.5 15 4.5a3.5 3.5 0 0 1 5 5L10.5 19a5.5 5.5 0 0 1-8-8L11 2.5"/></svg></span>
                        <span><b>Toca para subirlo</b><small>Foto o PDF donde se vean el monto y el concepto</small></span>
                    </label>
                    <input type="file" name="comprobante_pago" id="comprobante_pago" accept=".jpg,.jpeg,.png,.pdf" hidden>
                    <p class="pago-upload__nombre" id="nombre-archivo">Ningún archivo seleccionado</p>
                </div>
            </div>
        </li>

        <li class="pg-paso">
            <div class="pg-fila">
                <span class="pg-num" aria-hidden="true"></span>
                <div class="pg-cuerpo">
                    <h2 class="pg-titulo">Confirma tu pedido</h2>
                    <p class="pg-texto">Tu pedido queda registrado hasta que tocas <b>Confirmar pedido</b>.<span class="bloque-transferencia"> Si sales a tu banco, regresa aquí: guardamos tus datos por un rato.</span></p>
                </div>
            </div>
        </li>
    </ol>

    <div class="pz-barra">
        <button type="submit" name="finalizar_pedido" value="1" class="pz-btn pg-btn">
            <span>Confirmar pedido</span><span>$<?php echo number_format($totalPedido, 2); ?></span>
        </button>
    </div>
</form>
<?php else: ?>
<!-- ══ CUERPO ════════════════════════════════════════════════ -->
<div class="pago-body">
<form action="" method="POST" enctype="multipart/form-data">

    <?php if (!empty($errores)): ?>
    <div class="pago-error">
        <ul>
            <?php foreach ($errores as $error): ?>
                <li><?php echo htmlspecialchars($error); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <!-- ── Método de pago ── -->
    <div class="conf-card">
        <p class="conf-card__titulo">Método de pago</p>

        <div class="pago-cards">

            <!-- Transferencia (ancho completo) -->
            <label class="pago-card pago-card--transfer <?php echo (($_POST["metodo_pago"] ?? "") === "Transferencia") ? "pago-card--activo" : ""; ?>"
                   id="card-transfer">
                <input type="radio" name="metodo_pago" value="Transferencia"
                    <?php echo (($_POST["metodo_pago"] ?? "") === "Transferencia") ? "checked" : ""; ?>>
                <span class="pago-card__icono"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10 12 4l9 6"/><path d="M5 10v9M10 10v9M14 10v9M19 10v9"/><path d="M3 19h18"/></svg></span>
                <div class="pago-card__body">
                    <span class="pago-card__badge">Recomendado</span>
                    <strong class="pago-card__nombre">Transferencia</strong>
                    <p class="pago-card__desc">Paga desde tu banco y sube tu comprobante</p>
                </div>
                <div class="pago-card__dot"></div>
            </label>

            <!-- Efectivo -->
            <label class="pago-card pago-card--cash <?php echo (($_POST["metodo_pago"] ?? "") === "Efectivo") ? "pago-card--activo" : ""; ?>"
                   id="card-cash" style="grid-column: 1 / -1;">
                <input type="radio" name="metodo_pago" value="Efectivo"
                    <?php echo (($_POST["metodo_pago"] ?? "") === "Efectivo") ? "checked" : ""; ?>>
                <span class="pago-card__icono"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="2.5" y="6" width="19" height="12" rx="2"/><circle cx="12" cy="12" r="2.6"/></svg></span>
                <strong class="pago-card__nombre">Efectivo</strong>
                <p class="pago-card__desc">Ten el monto exacto al recibir</p>
                <div class="pago-card__dot"></div>
            </label>

        </div>

        <!-- Aviso efectivo -->
        <div class="pago-aviso pago-aviso--efectivo <?php echo (($_POST["metodo_pago"] ?? "") === "Efectivo") ? "visible" : ""; ?>"
             id="aviso-efectivo">
            <span style="font-size:17px;flex-shrink:0;margin-top:1px;"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3.5 22 20.5H2Z"/><path d="M12 9.5v5M12 17.5h.01"/></svg></span>
            <p style="margin:0;">Ten exactamente <strong>$<?php echo number_format($totalPedido, 2); ?></strong> listos al recibir tu pedido. El repartidor no siempre lleva cambio.</p>
        </div>

    </div>

    <!-- ── Pasos: el pedido se registra hasta confirmar ── -->
    <div class="bloque-transferencia">
        <ol class="pago-pasos">
            <li><span class="pago-pasos__num">1</span>Transfiere</li>
            <li><span class="pago-pasos__num">2</span>Sube tu comprobante</li>
            <li><span class="pago-pasos__num">3</span>Confirma tu pedido</li>
        </ol>
    </div>

    <!-- ── Datos para transferir ── -->
    <?php $conceptoEjemplo = strtoupper($nombre_cliente) . " " . date("d-m-Y"); ?>
    <div class="conf-card bloque-transferencia" id="bloque-transferencia-datos">
        <p class="conf-card__titulo">Datos para transferir</p>
        <div class="pago-lineas">
            <div class="pago-linea">
                <span class="pago-linea__label">Banco</span>
                <span class="pago-linea__val">Mercado Pago</span>
            </div>
            <div class="pago-linea">
                <span class="pago-linea__label">Titular</span>
                <span class="pago-linea__val">Diana Piña</span>
            </div>
            <div class="pago-linea">
                <span class="pago-linea__label">Monto</span>
                <span class="pago-linea__val pago-linea__val--monto">$<?php echo number_format($totalPedido, 2); ?></span>
            </div>
        </div>

        <div class="copiar-campo">
            <span class="copiar-campo__label">CLABE</span>
            <button type="button" class="copiar-campo__btn" data-copiar="texto-clabe">
                <span class="copiar-campo__valor" id="texto-clabe">722969014258283039</span>
                <span class="copiar-campo__accion">Copiar</span>
            </button>
        </div>

        <div class="copiar-campo">
            <span class="copiar-campo__label">Concepto</span>
            <button type="button" class="copiar-campo__btn" data-copiar="texto-concepto">
                <span class="copiar-campo__valor" id="texto-concepto"><?php echo htmlspecialchars($conceptoEjemplo); ?></span>
                <span class="copiar-campo__accion">Copiar</span>
            </button>
            <p class="copiar-campo__nota">Escríbelo en el concepto o referencia de tu transferencia; sin él no podemos validar tu pago.</p>
        </div>
    </div>

    <!-- ── Subir comprobante ── -->
    <div class="conf-card bloque-transferencia" id="bloque-transferencia-comprobante">
        <p class="conf-card__titulo">Comprobante de pago</p>
        <div class="pago-upload">
            <p class="pago-upload__desc">Sube una imagen o PDF donde se vea claramente el monto transferido y la referencia.</p>
            <label for="comprobante_pago" class="pago-upload__btn">
                <svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M7 12.5 15 4.5a3.5 3.5 0 0 1 5 5L10.5 19a5.5 5.5 0 0 1-8-8L11 2.5"/></svg>
                Elegir archivo
            </label>
            <input type="file" name="comprobante_pago" id="comprobante_pago"
                   accept=".jpg,.jpeg,.png,.pdf" hidden>
            <p class="pago-upload__nombre" id="nombre-archivo">Ningún archivo seleccionado</p>
        </div>
    </div>

    <!-- ── Finalizar ── -->
    <p class="pago-nota-final bloque-transferencia">
        Tu pedido se registra al tocar <strong>Confirmar pedido</strong>. Si sales a tu banco, regresa aquí; Tus datos se guardan temporalmente por 1 hora.
    </p>
    <button type="submit" name="finalizar_pedido" value="1" class="pago-btn-final">
        Confirmar pedido
        <span class="pago-btn-final__flecha">→</span>
    </button>

</form>
</div>

<?php endif; ?>

</div><!-- /.contenedor -->

<script>
document.addEventListener("DOMContentLoaded", function () {
    var radios          = document.querySelectorAll('input[name="metodo_pago"]');
    var cardTransfer    = document.getElementById("card-transfer");
    var cardCash        = document.getElementById("card-cash");
    var bloquesTransfer = document.querySelectorAll(".bloque-transferencia");
    var avisoEfectivo   = document.getElementById("aviso-efectivo");

    function actualizarVista() {
        var checked = document.querySelector('input[name="metodo_pago"]:checked');
        var val = checked ? checked.value : "";

        cardTransfer.classList.toggle("pago-card--activo", val === "Transferencia");
        cardCash.classList.toggle("pago-card--activo",     val === "Efectivo");

        bloquesTransfer.forEach(function (b) {
            b.style.display = val === "Transferencia" ? "block" : "none";
        });

        avisoEfectivo.classList.toggle("visible", val === "Efectivo");
    }

    // Si el celular recarga la página al volver de la app del banco, que
    // siga seleccionado el método (si no, los datos y el comprobante se esconden)
    try {
        var guardado = sessionStorage.getItem("zabisu_metodo_pago");
        if (guardado && !document.querySelector('input[name="metodo_pago"]:checked')) {
            var r = document.querySelector('input[name="metodo_pago"][value="' + guardado + '"]');
            if (r) r.checked = true;
        }
    } catch (e) {}
    radios.forEach(function (r) {
        r.addEventListener("change", function () {
            try { sessionStorage.setItem("zabisu_metodo_pago", r.value); } catch (e) {}
            actualizarVista();
        });
    });
    actualizarVista();

    // Copiar CLABE / concepto
    document.querySelectorAll(".copiar-campo__btn").forEach(function (btn) {
        btn.addEventListener("click", function () {
            var texto  = document.getElementById(btn.dataset.copiar).textContent.trim();
            var accion = btn.querySelector(".copiar-campo__accion");
            function ok() {
                btn.classList.add("copiado"); accion.textContent = "¡Copiado!";
                setTimeout(function () { btn.classList.remove("copiado"); accion.textContent = "Copiar"; }, 2000);
            }
            (navigator.clipboard ? navigator.clipboard.writeText(texto) : Promise.reject()).then(ok).catch(function () {
                var ta = document.createElement("textarea");
                ta.value = texto; ta.style.cssText = "position:fixed;opacity:0";
                document.body.appendChild(ta); ta.select(); document.execCommand("copy");
                document.body.removeChild(ta); ok();
            });
        });
    });

    // Nombre del archivo seleccionado
    var inputArchivo  = document.getElementById("comprobante_pago");
    var nombreArchivo = document.getElementById("nombre-archivo");
    if (inputArchivo && nombreArchivo) {
        inputArchivo.addEventListener("change", function () {
            if (this.files && this.files.length > 0) {
                nombreArchivo.textContent = this.files[0].name;
                nombreArchivo.classList.add("tiene-archivo");
            } else {
                nombreArchivo.textContent = "Ningún archivo seleccionado";
                nombreArchivo.classList.remove("tiene-archivo");
            }
        });
    }
});
</script>

<footer class="cliente-footer">
    <span class="cliente-footer__slogan">© 2026 Zabisu - Sabor y Servicio. Todos los derechos reservados.</span>
</footer>

</body>
</html>
