<?php
require_once "../config/db.php";
session_start();

if (empty($_SESSION["pedido_temporal"])) {
    die("No hay un pedido temporal disponible.");
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
    $metodo_pago = trim($_POST["metodo_pago"] ?? "");

    if ($metodo_pago === "") {
        $errores[] = "Debes seleccionar un método de pago.";
    }

    if (!in_array($metodo_pago, ["Transferencia", "Efectivo"])) {
        $errores[] = "Método de pago no válido.";
    }

    if ($metodo_pago === "Transferencia") {
        if (!isset($_FILES["comprobante_pago"]) || $_FILES["comprobante_pago"]["error"] !== 0) {
            $errores[] = "Debes subir el comprobante de pago.";
        }
    }

    if (empty($errores)) {
        // Verificar que ningún plato fuerte seleccionado haya alcanzado su límite
        $stmtContPago = $conexion->prepare(
            "SELECT dp2.id_producto, COUNT(*) AS total
             FROM detalle_pedido dp2
             INNER JOIN pedido_menus pm2 ON dp2.id_pedido_menu = pm2.id_pedido_menu
             INNER JOIN pedidos p2       ON pm2.id_pedido = p2.id_pedido
             INNER JOIN productos pr2    ON dp2.id_producto = pr2.id_producto
             WHERE p2.estado != 'Cancelado'
               AND p2.es_prueba = 0
               AND pr2.categoria = 'Plato fuerte'
             GROUP BY dp2.id_producto"
        );
        $stmtContPago->execute();
        $conteosActuales = [];
        foreach ($stmtContPago->fetchAll(PDO::FETCH_ASSOC) as $fila) {
            $conteosActuales[(int)$fila["id_producto"]] = (int)$fila["total"];
        }

        foreach ($menusRecibidos as $numMenu => $menu) {
            $idPlato = (int)($menu["plato_fuerte"] ?? 0);
            if (!$idPlato || !isset($productosIndexados[$idPlato])) continue;
            $prod = $productosIndexados[$idPlato];
            $limite = isset($prod["limite_pedidos"]) ? (int)$prod["limite_pedidos"] : 0;
            if ($limite > 0 && ($conteosActuales[$idPlato] ?? 0) >= $limite) {
                $errores[] = "El plato \"" . htmlspecialchars($prod["nombre"]) . "\" (menú {$numMenu}) ya está agotado. Regresa y elige otro.";
            }
        }
    }

    if (empty($errores)) {
        try {
            $conexion->beginTransaction();

            $folio = "ZAB-" . date("Ymd") . "-" . strtoupper(substr(md5(uniqid()), 0, 5));
            $referencia_pago = $folio;

            $estado_pago = "Pendiente de pago";
            $comprobante_pago = null;
            $fecha_pago = null;

            if ($metodo_pago === "Transferencia") {
                $nombreOriginal = basename($_FILES["comprobante_pago"]["name"]);
                $extension = pathinfo($nombreOriginal, PATHINFO_EXTENSION);
                $nombreArchivo = time() . "_" . preg_replace('/[^A-Za-z0-9_\-]/', '_', pathinfo($nombreOriginal, PATHINFO_FILENAME)) . "." . $extension;

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
                           (id_pedido_menu, id_producto, categoria, nombre_producto)
                           VALUES
                           (:id_pedido_menu, :id_producto, :categoria, :nombre_producto)";
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
    <style>
    *, *::before, *::after { box-sizing: border-box; }

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
    .pago-error ul { margin: 0; padding: 0 0 0 16px; }
    .pago-error li { margin-bottom: 4px; }
    .pago-error li:last-child { margin-bottom: 0; }
    </style>
</head>
<body>

<!-- ══ HERO ══════════════════════════════════════════════════ -->
<div class="md-hero">
    <div class="md-hero__glow-top"></div>
    <div class="md-hero__glow-bottom"></div>
    <p class="md-hero__eyebrow">Último paso</p>
    <div class="md-hero__marca-grupo">
        <img class="md-hero__logo" src="../assets/img/LOGO_BLANCO.png" alt="Zabisu">
        <h1 class="md-hero__marca">Zabisu</h1>
    </div>
    <p class="md-hero__fecha">Elige cómo pagarás tu pedido</p>
</div>

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
                <span class="pago-card__icono">💳</span>
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
                <span class="pago-card__icono">💵</span>
                <strong class="pago-card__nombre">Efectivo</strong>
                <p class="pago-card__desc">Ten el monto exacto al recibir</p>
                <div class="pago-card__dot"></div>
            </label>

        </div>

        <!-- Aviso efectivo -->
        <div class="pago-aviso pago-aviso--efectivo <?php echo (($_POST["metodo_pago"] ?? "") === "Efectivo") ? "visible" : ""; ?>"
             id="aviso-efectivo">
            <span style="font-size:17px;flex-shrink:0;margin-top:1px;">⚠️</span>
            <p style="margin:0;">Ten exactamente <strong>$<?php echo number_format($totalPedido, 2); ?></strong> listos al recibir tu pedido. El repartidor no siempre lleva cambio.</p>
        </div>

    </div>

    <!-- ── Datos bancarios ── -->
    <div class="conf-card bloque-transferencia" id="bloque-transferencia-datos">
        <p class="conf-card__titulo">Datos bancarios</p>
        <div class="pago-lineas">
            <div class="pago-linea">
                <span class="pago-linea__label">Banco</span>
                <span class="pago-linea__val">Mercado Pago</span>
            </div>
            <div class="pago-linea">
                <span class="pago-linea__label">CLABE</span>
                <button type="button" class="clabe-copiar" id="btn-copiar-clabe" title="Toca para copiar">
                    <span id="texto-clabe">722969014258283039</span>
                    <span class="clabe-copiar__icono">⎘</span>
                    <span class="clabe-copiar__copiado" id="msg-copiado-clabe">¡Copiado!</span>
                </button>
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

        <?php $conceptoEjemplo = strtoupper($nombre_cliente) . " " . date("d-m-Y"); ?>
        <div class="aviso-concepto" style="margin-top:16px;">
            <div class="aviso-concepto__header">
                <span class="aviso-concepto__icono">⚠️</span>
                <strong class="aviso-concepto__titulo">Concepto de la transferencia</strong>
            </div>
            <p class="aviso-concepto__texto">
                Escribe lo siguiente en el campo de <strong>concepto</strong> o <strong>referencia</strong> de tu transferencia:
            </p>
            <div class="aviso-concepto__ejemplo-wrapper">
                <span class="aviso-concepto__ejemplo-label">Tu concepto debe ser:</span>
                <button type="button" class="aviso-concepto__ejemplo" id="btn-copiar-concepto" title="Toca para copiar">
                    <span id="texto-concepto"><?php echo htmlspecialchars($conceptoEjemplo); ?></span>
                    <span class="aviso-concepto__copiar-icono">⎘</span>
                </button>
                <span class="aviso-concepto__copiado" id="msg-copiado">¡Copiado!</span>
            </div>
            <div class="aviso-concepto__toque-hint">
                <span>👆</span>
                <span>Toca el concepto para copiarlo automáticamente</span>
            </div>
            <p class="aviso-concepto__formato">
                Sin este concepto tu pago <strong>no podrá ser validado</strong>.
            </p>
        </div>
    </div>

    <!-- ── Subir comprobante ── -->
    <div class="conf-card bloque-transferencia" id="bloque-transferencia-comprobante">
        <p class="conf-card__titulo">Comprobante de pago</p>
        <div class="pago-upload">
            <p class="pago-upload__desc">Sube una imagen o PDF donde se vea claramente el monto transferido y la referencia.</p>
            <label for="comprobante_pago" class="pago-upload__btn">
                📎 Elegir archivo
            </label>
            <input type="file" name="comprobante_pago" id="comprobante_pago"
                   accept=".jpg,.jpeg,.png,.pdf" hidden>
            <p class="pago-upload__nombre" id="nombre-archivo">Ningún archivo seleccionado</p>
        </div>
    </div>

    <!-- ── Finalizar ── -->
    <button type="submit" name="finalizar_pedido" value="1" class="pago-btn-final">
        Confirmar pedido
        <span class="pago-btn-final__flecha">→</span>
    </button>

</form>
</div>

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

    radios.forEach(function (r) { r.addEventListener("change", actualizarVista); });
    actualizarVista();

    // Copiar CLABE
    var btnClabe    = document.getElementById("btn-copiar-clabe");
    var msgClabe    = document.getElementById("msg-copiado-clabe");
    if (btnClabe) {
        btnClabe.addEventListener("click", function () {
            var texto = document.getElementById("texto-clabe").textContent.trim();
            function ok() {
                msgClabe.classList.add("clabe-copiar__copiado--visible");
                setTimeout(function () { msgClabe.classList.remove("clabe-copiar__copiado--visible"); }, 2000);
            }
            navigator.clipboard.writeText(texto).then(ok).catch(function () {
                var ta = document.createElement("textarea");
                ta.value = texto; ta.style.cssText = "position:fixed;opacity:0";
                document.body.appendChild(ta); ta.select(); document.execCommand("copy");
                document.body.removeChild(ta); ok();
            });
        });
    }

    // Copiar concepto
    var btnConcepto = document.getElementById("btn-copiar-concepto");
    var msgConcepto = document.getElementById("msg-copiado");
    if (btnConcepto) {
        btnConcepto.addEventListener("click", function () {
            var texto = document.getElementById("texto-concepto").textContent.trim();
            function ok() {
                msgConcepto.classList.add("aviso-concepto__copiado--visible");
                setTimeout(function () { msgConcepto.classList.remove("aviso-concepto__copiado--visible"); }, 2000);
            }
            navigator.clipboard.writeText(texto).then(ok).catch(function () {
                var ta = document.createElement("textarea");
                ta.value = texto; ta.style.cssText = "position:fixed;opacity:0";
                document.body.appendChild(ta); ta.select(); document.execCommand("copy");
                document.body.removeChild(ta); ok();
            });
        });
    }

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
