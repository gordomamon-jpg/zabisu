<?php
require_once "../config/db.php";

$folio = trim($_GET["folio"] ?? "");

if ($folio === "") {
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
              WHERE p.folio = :folio
              LIMIT 1";
$stmtPedido = $conexion->prepare($sqlPedido);
$stmtPedido->bindParam(":folio", $folio);
$stmtPedido->execute();
$pedido = $stmtPedido->fetch(PDO::FETCH_ASSOC);

if (!$pedido) {
    die("No se encontró el pedido.");
}

$id_pedido = (int)$pedido["id_pedido"];

/*
    2. Obtener menús del pedido
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
    3. Obtener extras del pedido
*/
$sqlExtras = "SELECT * FROM pedido_extras WHERE id_pedido = :id_pedido ORDER BY id_extra ASC";
$stmtExtras = $conexion->prepare($sqlExtras);
$stmtExtras->bindParam(":id_pedido", $id_pedido, PDO::PARAM_INT);
$stmtExtras->execute();
$extrasConfirmar = $stmtExtras->fetchAll(PDO::FETCH_ASSOC);

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

$sqlPrecios = "SELECT nombre_menu, precio FROM tipos_menu WHERE activo = 1";
$stmtPrecios = $conexion->prepare($sqlPrecios);
$stmtPrecios->execute();
$preciosMenus = [];
foreach ($stmtPrecios->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $preciosMenus[$r["nombre_menu"]] = (float)$r["precio"];
}

function agruparDetallePorCategoria($detalles)
{
    $agrupado = [];

    foreach ($detalles as $detalle) {
        $agrupado[$detalle["categoria"]][] = $detalle["nombre_producto"];
    }

    return $agrupado;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pedido confirmado | Zabisu</title>
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
            radial-gradient(48vw 42vh at 8% 4%,    rgba(var(--zb-naranja-rgb), .24), transparent 65%),
            radial-gradient(42vw 38vh at 100% 46%, rgba(var(--zb-naranja-rgb), .13), transparent 62%),
            radial-gradient(48vw 42vh at 4% 94%,   rgba(var(--zb-naranja-rgb), .13), transparent 62%),
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
    .conf-hero {
        background: rgba(30,24,20,.16) !important;
        backdrop-filter: blur(18px) saturate(160%);
        -webkit-backdrop-filter: blur(18px) saturate(160%);
    }
    .conf-card {
        backdrop-filter: blur(16px) saturate(150%);
        -webkit-backdrop-filter: blur(16px) saturate(150%);
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
        margin: 4px 0 0;
    }

    /* ── Hero de confirmación ── */
    .conf-hero {
        position: relative;
        overflow: hidden;
        text-align: center;
        padding: 40px 24px 32px;
        margin-bottom: 18px;
        border-radius: 26px;
        background:
            radial-gradient(ellipse at 50% 0%, rgba(255,122,0,.22) 0%, transparent 65%),
            linear-gradient(180deg, rgba(255,122,0,.08) 0%, rgba(12,12,15,.7) 100%);
        box-shadow: inset 0 1px 0 rgba(255,255,255,.12), 0 12px 30px -18px rgba(0,0,0,.5);
    }

    /* Checkmark animado */
    .conf-check { display: flex; justify-content: center; margin-bottom: 20px; }
    .conf-check svg { overflow: visible; }
    .conf-check__circle {
        fill: none; stroke: #4ac86e; stroke-width: 3;
        stroke-dasharray: 166; stroke-dashoffset: 166;
        animation: conf-circle .5s cubic-bezier(.65,0,.45,1) .1s forwards;
    }
    .conf-check__tick {
        fill: none; stroke: #4ac86e; stroke-width: 3;
        stroke-linecap: round; stroke-linejoin: round;
        stroke-dasharray: 48; stroke-dashoffset: 48;
        animation: conf-tick .35s cubic-bezier(.65,0,.45,1) .55s forwards;
    }
    @keyframes conf-circle {
        to { stroke-dashoffset: 0; }
    }
    @keyframes conf-tick {
        to { stroke-dashoffset: 0; }
    }

    .conf-titulo {
        font-size: 26px; font-weight: 900; color: #fff;
        margin: 0 0 6px; letter-spacing: -.4px;
        animation: conf-fadein .4s ease .7s both;
    }
    .conf-subtitulo {
        font-size: 13px; color: rgba(255,255,255,.4);
        margin: 0 0 22px;
        animation: conf-fadein .4s ease .8s both;
    }
    .conf-folio-wrap {
        display: inline-block;
        background: rgba(255,122,0,.1);
        border: 1px solid rgba(255,122,0,.3);
        border-radius: 18px;
        padding: 12px 24px;
        margin-bottom: 20px;
        animation: conf-popin .45s cubic-bezier(.34,1.56,.64,1) .85s both;
    }
    .conf-folio-label {
        font-size: 10px; font-weight: 700; letter-spacing: 2px;
        text-transform: uppercase; color: rgba(255,122,0,.6);
        display: block; margin-bottom: 4px;
    }
    .conf-folio-num {
        font-size: 22px; font-weight: 900; color: #ff7a00;
        letter-spacing: 1px;
    }
    .conf-pills {
        display: flex; justify-content: center; flex-wrap: wrap;
        gap: 8px;
        animation: conf-fadein .4s ease 1s both;
    }
    .conf-pill {
        display: inline-flex; align-items: center; gap: 5px;
        background: rgba(255,255,255,.05); border: 1px solid rgba(255,255,255,.09);
        border-radius: 999px; padding: 6px 14px;
        font-size: 13px; font-weight: 600; color: rgba(255,255,255,.7);
    }

    @keyframes conf-fadein {
        from { opacity: 0; transform: translateY(8px); }
        to   { opacity: 1; transform: translateY(0); }
    }
    @keyframes conf-popin {
        from { opacity: 0; transform: scale(.88); }
        to   { opacity: 1; transform: scale(1); }
    }

    /* ── Cuerpo ── */
    .conf-body {
        max-width: 560px;
        margin: 0 auto;
        padding: 0 16px 48px;
        display: flex;
        flex-direction: column;
        gap: 18px;
    }

    /* ── Tarjetas ── */
    .conf-card {
        background: rgba(255,255,255,.055);
        border: 1px solid rgba(247,236,220,.14);
        border-radius: 22px;
        padding: 20px;
        box-shadow: inset 0 1px 0 rgba(255,255,255,.12), 0 12px 30px -18px rgba(0,0,0,.5);
    }
    .conf-card__titulo {
        font-size: 12px; font-weight: 700; letter-spacing: 2px;
        text-transform: uppercase; color: rgba(255,255,255,.3);
        margin: 0 0 14px;
    }

    /* Grid de datos */
    .conf-datos {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 14px 12px;
    }
    .conf-dato__label {
        font-size: 11px; color: rgba(255,255,255,.3);
        font-weight: 600; letter-spacing: .5px;
        text-transform: uppercase; display: block; margin-bottom: 3px;
    }
    .conf-dato__val {
        font-size: 14px; font-weight: 700; color: #e0e0e0;
    }
    .conf-dato__val--pagado { color: #4ac86e; }
    .conf-dato__val--pendiente { color: #facc15; }
    .conf-obs {
        margin-top: 14px; padding-top: 14px;
        border-top: 1px solid rgba(255,255,255,.06);
        font-size: 13px; color: rgba(255,255,255,.5);
    }
    .conf-obs strong { color: rgba(255,255,255,.7); }

    /* Líneas de detalle */
    .conf-linea {
        display: flex; justify-content: space-between; align-items: baseline;
        padding: 7px 0; border-bottom: 1px solid rgba(255,255,255,.05);
        gap: 12px;
    }
    .conf-linea:last-child { border-bottom: none; padding-bottom: 0; }
    .conf-linea__cat { font-size: 12px; color: rgba(255,255,255,.35); flex-shrink: 0; }
    .conf-linea__val { font-size: 13px; color: #ccc; text-align: right; }
    .conf-menu-header {
        display: flex; justify-content: space-between; align-items: center;
        margin-bottom: 10px; padding-bottom: 10px;
        border-bottom: 1px solid rgba(255,255,255,.07);
    }
    .conf-menu-header__nombre { font-size: 13px; font-weight: 700; color: rgba(255,255,255,.6); }
    .conf-menu-header__precio { font-size: 14px; font-weight: 800; color: #ff7a00; }
    .conf-menu-sep { margin-top: 16px; padding-top: 16px; border-top: 1px solid rgba(255,255,255,.05); }

    /* Total */
    .conf-card--total {
        display: flex; justify-content: space-between; align-items: center;
        background: rgba(255,122,0,.07); border-color: rgba(255,122,0,.2);
    }
    .conf-total-label { font-size: 15px; font-weight: 700; color: rgba(255,255,255,.5); }
    .conf-total-val   { font-size: 32px; font-weight: 900; color: #ff7a00; letter-spacing: -1px; }

    /* Feedback */
    .conf-feedback__estrellas {
        display: flex; gap: 8px; justify-content: center; margin-bottom: 14px;
    }

    /* Acciones finales */
    .conf-acciones {
        display: flex; flex-direction: column; gap: 10px;
        text-align: center;
    }
    .conf-wa {
        display: inline-flex; align-items: center; justify-content: center;
        gap: 8px; background: #25d366; color: #fff; text-decoration: none;
        font-size: 15px; font-weight: 700; padding: 14px 24px;
        border-radius: 999px; transition: opacity .15s;
    }
    .conf-wa:active { opacity: .85; }
    .conf-btn-nuevo {
        display: inline-block; color: rgba(255,255,255,.4);
        text-decoration: none; font-size: 14px; font-weight: 600;
        padding: 10px; transition: color .15s;
    }
    .conf-btn-nuevo:hover { color: rgba(255,255,255,.7); }
    .conf-correo {
        font-size: 12px; color: rgba(255,255,255,.25);
        line-height: 1.5;
    }
    .conf-correo strong { color: rgba(255,255,255,.4); }
    </style>
</head>
<body>

<!-- ── Marca de agua fija ── -->
<img class="zb-marca-watermark" src="../assets/img/LOGO_BLANCO.png" alt="">

<?php
$esPagoEfectivo    = ($pedido["metodo_pago"] ?? "") !== "Transferencia";
$estadoPagoClass   = str_contains(strtolower($pedido["estado_pago"] ?? ""), "pagado") ? "conf-dato__val--pagado" : "conf-dato__val--pendiente";
$waPath            = "M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z";
?>

<div class="contenedor">

<!-- ══ HERO ══════════════════════════════════════════════════ -->
<div class="conf-hero">
    <div class="md-hero__glow-top"></div>
    <div class="md-hero__glow-bottom"></div>

    <div class="md-hero__marca-grupo" style="margin-bottom:6px;">
        <img class="md-hero__logo" src="../assets/img/LOGO_BLANCO.png" alt="Zabisu">
        <h1 class="md-hero__marca">Zabisu</h1>
    </div>
    <p class="md-hero__tagline" style="margin-bottom:22px;">Sabor y Servicio</p>

    <div class="conf-check">
        <svg width="72" height="72" viewBox="0 0 52 52">
            <circle class="conf-check__circle" cx="26" cy="26" r="25"/>
            <path class="conf-check__tick" d="M14 27 l8 8 l16-16"/>
        </svg>
    </div>
    <p class="md-hero__eyebrow" style="margin-top:8px;">¡Pedido confirmado!</p>
    <p class="conf-subtitulo"><?php echo $esPagoEfectivo ? "Pagarás al recibir tu pedido" : "Pago pendiente de validación"; ?></p>

    <div class="conf-folio-wrap">
        <span class="conf-folio-label">Folio</span>
        <span class="conf-folio-num"><?php echo htmlspecialchars($pedido["folio"]); ?></span>
    </div>

    <div class="conf-pills">
        <span class="conf-pill"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7-6.1-7-11.5A7 7 0 0 1 19 9.5C19 14.9 12 21 12 21Z"/><circle cx="12" cy="9.5" r="2.4"/></svg> <?php echo htmlspecialchars($pedido["nombre_ubicacion"]); ?></span>
        <span class="conf-pill"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/></svg> <?php echo date("g:i A", strtotime($pedido["hora_entrega"])); ?></span>
    </div>
</div>

<!-- ══ CUERPO ════════════════════════════════════════════════ -->
<div class="conf-body">

    <!-- Datos del pedido -->
    <div class="conf-card">
        <p class="conf-card__titulo">Datos del pedido</p>
        <div class="conf-datos">
            <div>
                <span class="conf-dato__label">Cliente</span>
                <span class="conf-dato__val"><?php echo htmlspecialchars($pedido["nombre_cliente"]); ?></span>
            </div>
            <div>
                <span class="conf-dato__label">Método de pago</span>
                <span class="conf-dato__val"><?php echo htmlspecialchars($pedido["metodo_pago"]); ?></span>
            </div>
            <div>
                <span class="conf-dato__label">Estado de pago</span>
                <span class="conf-dato__val <?php echo $estadoPagoClass; ?>"><?php echo htmlspecialchars($pedido["estado_pago"]); ?></span>
            </div>
            <?php if (!empty($pedido["telefono"]) && $pedido["telefono"] !== "0000000000"): ?>
            <div>
                <span class="conf-dato__label">Teléfono</span>
                <span class="conf-dato__val"><?php echo htmlspecialchars($pedido["telefono"]); ?></span>
            </div>
            <?php endif; ?>
        </div>
        <?php if (!empty($pedido["observaciones"])): ?>
        <div class="conf-obs"><strong>Nota:</strong> <?php echo htmlspecialchars($pedido["observaciones"]); ?></div>
        <?php endif; ?>
    </div>

    <!-- Detalle del pedido -->
    <?php if (!empty($menusPedido)): ?>
    <div class="conf-card">
        <p class="conf-card__titulo">Tu pedido</p>
        <?php foreach ($menusPedido as $idx => $menu):
            $agrupado = agruparDetallePorCategoria($detallePorMenu[$menu["id_pedido_menu"]] ?? []);
        ?>
            <?php if ($idx > 0): ?><div class="conf-menu-sep"></div><?php endif; ?>
            <div class="conf-menu-header">
                <span class="conf-menu-header__nombre">Menú <?php echo (int)$menu["numero_menu"]; ?> · <?php echo htmlspecialchars($menu["tipo_menu"]); ?></span>
                <?php if (isset($preciosMenus[$menu["tipo_menu"]])): ?>
                <span class="conf-menu-header__precio">$<?php echo number_format($preciosMenus[$menu["tipo_menu"]], 2); ?></span>
                <?php endif; ?>
            </div>
            <?php foreach ($agrupado as $categoria => $items): ?>
            <div class="conf-linea">
                <span class="conf-linea__cat"><?php echo htmlspecialchars($categoria); ?></span>
                <span class="conf-linea__val"><?php echo htmlspecialchars(implode(", ", $items)); ?></span>
            </div>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Extras -->
    <?php if (!empty($extrasConfirmar)): ?>
    <div class="conf-card">
        <p class="conf-card__titulo">Extras</p>
        <?php $totalExtras = 0; ?>
        <?php foreach ($extrasConfirmar as $extra):
            $sub = $extra["cantidad"] * $extra["precio_unitario"];
            $totalExtras += $sub;
        ?>
        <div class="conf-linea">
            <span class="conf-linea__cat"><?php echo htmlspecialchars($extra["categoria"]); ?></span>
            <span class="conf-linea__val">
                <?php echo htmlspecialchars($extra["nombre"]); ?> ×<?php echo (int)$extra["cantidad"]; ?>
                &nbsp;<strong style="color:#ff7a00;">$<?php echo number_format($sub, 2); ?></strong>
            </span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Total -->
    <div class="conf-card conf-card--total">
        <span class="conf-total-label">Total</span>
        <span class="conf-total-val">$<?php echo number_format((float)$pedido["total"], 2); ?></span>
    </div>

    <!-- Feedback -->
    <div class="conf-card" id="bloque-feedback">
        <p class="conf-card__titulo">¿Cómo fue tu experiencia?</p>
        <div class="conf-feedback__estrellas zb-estrellas" id="estrellas">
            <?php for ($s = 1; $s <= 5; $s++): ?>
                <button type="button" class="zb-estrella" data-valor="<?php echo $s; ?>">★</button>
            <?php endfor; ?>
        </div>
        <textarea id="feedback-comentario" class="zb-modal__textarea" placeholder="Comentario opcional..." maxlength="500" rows="3"></textarea>
        <button type="button" id="btn-enviar-feedback" class="btn-principal" style="margin-top:12px;width:100%;" disabled>Enviar opinión</button>
        <p class="zb-modal__gracias" id="feedback-gracias" style="display:none;text-align:center;margin-top:10px;">¡Gracias! Tu opinión fue registrada.</p>
    </div>

    <!-- Acciones -->
    <div class="conf-acciones">
        <a class="conf-wa"
           href="https://wa.me/525560908778?text=Hola%2C%20tengo%20una%20duda%20sobre%20mi%20pedido%20Zabisu%20%F0%9F%8D%BD%EF%B8%8F"
           target="_blank" rel="noopener">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="<?php echo $waPath; ?>"/></svg>
            ¿Dudas con tu pedido? Escríbenos
        </a>

        <?php if (!empty($pedido["correo_cliente"])): ?>
        <p class="conf-correo">
            Te enviamos un correo a <strong><?php echo htmlspecialchars($pedido["correo_cliente"]); ?></strong><br>
            Si no lo ves, revisa spam.
        </p>
        <?php endif; ?>

        <a href="pedido.php" class="conf-btn-nuevo">+ Hacer otro pedido</a>
    </div>

</div>

</div><!-- /.contenedor -->

<script>
(function () {
    const folio = <?php echo json_encode($pedido["folio"] ?? ""); ?>;
    let calSeleccionada = 0;
    const estrellas = document.querySelectorAll(".zb-estrella");
    const btnEnviar = document.getElementById("btn-enviar-feedback");
    const gracias   = document.getElementById("feedback-gracias");
    const bloque    = document.getElementById("bloque-feedback");

    estrellas.forEach(function (btn) {
        btn.addEventListener("mouseenter", function () {
            const v = parseInt(this.dataset.valor);
            estrellas.forEach(function (b) {
                b.classList.toggle("zb-estrella--hover", parseInt(b.dataset.valor) <= v);
            });
        });
        btn.addEventListener("mouseleave", function () {
            estrellas.forEach(function (b) { b.classList.remove("zb-estrella--hover"); });
        });
        btn.addEventListener("click", function () {
            calSeleccionada = parseInt(this.dataset.valor);
            estrellas.forEach(function (b) {
                b.classList.toggle("zb-estrella--activa", parseInt(b.dataset.valor) <= calSeleccionada);
            });
            btnEnviar.disabled = false;
        });
    });

    btnEnviar.addEventListener("click", function () {
        if (!calSeleccionada) return;
        btnEnviar.disabled = true;
        const fd = new FormData();
        fd.append("calificacion", calSeleccionada);
        fd.append("comentario", document.getElementById("feedback-comentario").value.trim());
        fd.append("folio", folio);
        fetch("guardar_feedback.php", { method: "POST", body: fd })
            .then(function (r) { return r.json(); })
            .then(function () {
                bloque.querySelector(".zb-estrellas").style.display = "none";
                bloque.querySelector("#feedback-comentario").style.display = "none";
                btnEnviar.style.display = "none";
                bloque.querySelector(".nota-formulario").style.display = "none";
                gracias.style.display = "block";
            });
    });
})();
</script>

<footer class="cliente-footer">
    <span class="cliente-footer__slogan">© 2026 Zabisu - Sabor y Servicio. Todos los derechos reservados.</span>
</footer>

</body>
</html>