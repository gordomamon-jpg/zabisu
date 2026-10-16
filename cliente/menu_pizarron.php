<?php
/*
    Vista "Pizarrón de fonda" del menú del día (ver DESIGN.md).
    Solo presentación: la incluye menu.php cuando el diseño nuevo está
    activo y usa los datos que menu.php ya calculó ($menuActivo,
    $menusPorTipo, $precios, $popularIds, $postreDelDia, $cierre_ts,
    $ratingsTotal, $ratingsProm).
*/
if (!isset($menuActivo)) { header("Location: menu.php"); exit; }

$pzDiasMin = ['Sunday'=>'domingo','Monday'=>'lunes','Tuesday'=>'martes','Wednesday'=>'miércoles','Thursday'=>'jueves','Friday'=>'viernes','Saturday'=>'sábado'];
$pzMesesC  = ['01'=>'ene','02'=>'feb','03'=>'mar','04'=>'abr','05'=>'may','06'=>'jun','07'=>'jul','08'=>'ago','09'=>'sep','10'=>'oct','11'=>'nov','12'=>'dic'];
$pzTs      = strtotime($menuActivo["fecha"]);
$pzDia     = $pzDiasMin[date("l", $pzTs)] ?? "";
$pzFecha   = date("j", $pzTs) . " " . ($pzMesesC[date("m", $pzTs)] ?? "");
$pzCierre  = date("g:i", $cierre_ts);
$pzCierreAm = date("g:i", $cierre_ts) . " " . (date("A", $cierre_ts) === "AM" ? "am" : "pm");

function pzPrecio($n) {
    return "$" . (floor($n) == $n ? number_format($n, 0) : number_format($n, 2));
}

$pzTipos = array_values(array_filter(["Zabisu", "Ejecutivo"], fn($t) => !empty($menusPorTipo[$t])));
$pzSecciones = ["Plato fuerte" => "Plato fuerte", "Sopa" => "Sopa", "Complemento" => "Complementos"];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Menú del día · Zabisu</title>
    <link rel="icon" type="image/png" href="../assets/img/LOGO_NARA.png">
    <link rel="manifest" href="../manifest.json">
    <meta name="theme-color" content="#151413">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Zabisu">
    <link rel="apple-touch-icon" href="../assets/img/LOGO_NARA.png">
    <link rel="preload" href="../assets/fonts/kaushan-script-400.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="../assets/fonts/alfa-slab-one-400.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="../assets/css/menu-pizarron.css?v=2">
</head>
<body class="pz">

<!-- Filtros de las orillas irregulares (tinta y papel roto) -->
<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
    <filter id="pz-tinta"><feTurbulence type="fractalNoise" baseFrequency=".9" numOctaves="2" seed="4" result="r"/><feDisplacementMap in="SourceGraphic" in2="r" scale="2.4"/><feComponentTransfer><feFuncA type="discrete" tableValues="0 1 1 1 1 .85 1"/></feComponentTransfer></filter>
    <filter id="pz-borde"><feTurbulence type="fractalNoise" baseFrequency=".05" numOctaves="3" seed="2" result="r"/><feDisplacementMap in="SourceGraphic" in2="r" scale="3"/></filter>
</svg>

<?php require_once "../includes/aviso_birria.php"; ?>
<?php require_once "../includes/aviso_incremento.php"; ?>
<?php require_once "../includes/aviso_sin_servicio.php"; ?>

<main class="pz-pagina">

    <header class="pz-cab">
        <div class="pz-marca">
            <img src="../assets/img/LOGO_N_128.png" alt="">
            <div>
                <h1 class="pz-marca__nombre">Zabisu</h1>
                <span class="pz-marca__lema">Sabor y Servicio</span>
            </div>
        </div>
    </header>

    <?php if ($ratingsTotal >= 3): ?>
    <div class="pz-estrellas">
        <span class="pz-estrellas__fila" aria-hidden="true">
            <?php for ($s = 1; $s <= 5; $s++):
                $relleno = max(0, min(1, $ratingsProm - ($s - 1))) * 100; ?>
                <svg viewBox="0 0 24 24">
                    <defs><linearGradient id="pz-e<?php echo $s; ?>"><stop offset="<?php echo $relleno; ?>%" stop-color="#FF7A00"/><stop offset="<?php echo $relleno; ?>%" stop-color="#3A3734"/></linearGradient></defs>
                    <path fill="url(#pz-e<?php echo $s; ?>)" d="M12 2.6l2.9 6 6.5.8-4.8 4.5 1.2 6.5L12 17.3l-5.8 3.1 1.2-6.5-4.8-4.5 6.5-.8z"/>
                </svg>
            <?php endfor; ?>
        </span>
        <b><?php echo number_format($ratingsProm, 1); ?></b>
        <span>de <?php echo number_format($ratingsTotal); ?> opiniones</span>
    </div>
    <?php endif; ?>

    <h2 class="pz-titulo"><span class="pz-pincel"><span>Menú del <?php echo htmlspecialchars($pzDia); ?></span></span></h2>

    <div class="pz-cierre">
        <span class="pz-cierre__pide">Pide antes de las<b><?php echo $pzCierreAm; ?></b></span>
        <span class="pz-falta" id="pz-falta" aria-live="polite"></span>
    </div>

    <?php if (count($pzTipos) > 1): ?>
    <div class="pz-seg pz-fibra" role="tablist" aria-label="Tipo de menú">
        <?php foreach ($pzTipos as $i => $t): ?>
            <button type="button" role="tab" id="pz-tab-<?php echo $t; ?>"
                    aria-controls="pz-panel-<?php echo $t; ?>"
                    aria-selected="<?php echo $i === 0 ? 'true' : 'false'; ?>"
                    data-precio="<?php echo isset($precios[$t]) ? pzPrecio($precios[$t]) : ''; ?>">
                <?php echo $t; ?><?php if (isset($precios[$t])) echo " " . pzPrecio($precios[$t]); ?>
            </button>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php foreach ($pzTipos as $i => $tipoMenu): $cats = $menusPorTipo[$tipoMenu]; ?>
    <div class="pz-panel" id="pz-panel-<?php echo $tipoMenu; ?>" role="tabpanel"
         aria-labelledby="pz-tab-<?php echo $tipoMenu; ?>" <?php echo $i === 0 ? '' : 'hidden'; ?>>

        <?php foreach ($pzSecciones as $cat => $titulo):
            if (empty($cats[$cat])) continue; ?>
        <section class="pz-sec">
            <h3 class="pz-cinta pz-fibra"><?php echo $titulo; ?></h3>
            <ul class="pz-grupo">
                <?php foreach ($cats[$cat] as $item):
                    $agotado = !empty($item["agotado"]);
                    $popular = !$agotado && !empty($popularIds[(int)$item["id_producto"]]); ?>
                <li class="pz-fila<?php echo $agotado ? ' pz-fila--agotado' : ''; ?><?php echo $popular ? ' pz-fila--popular' : ''; ?>">
                    <?php if ($popular): ?>
                    <span class="pz-nota">el más pedido<svg viewBox="0 0 30 22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M27 3C20 4 12 8 6 17"/><path d="M5.2 10.6l.6 6.8 6.4-2.2"/></svg></span>
                    <?php endif; ?>
                    <span class="pz-tira pz-fibra" aria-hidden="true"></span>
                    <div class="pz-fila__txt">
                        <p class="pz-fila__nombre"><?php echo htmlspecialchars($item["nombre"]); ?></p>
                        <?php if (!empty($item["descripcion"])): ?>
                        <p class="pz-fila__desc"><?php echo htmlspecialchars($item["descripcion"]); ?></p>
                        <?php endif; ?>
                    </div>
                    <?php if ($agotado): ?><span class="pz-agotado">Agotado</span><?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php endforeach; ?>

        <?php $incluye = array_merge($cats["Agua"] ?? [], $cats["Cortesia"] ?? []);
        if ($incluye): ?>
        <div class="pz-boleto">
            <div class="pz-boleto__papel">
                <h3 class="pz-boleto__titulo">Va incluido, de la casa</h3>
                <?php foreach ($incluye as $k => $item): ?>
                    <?php if ($k > 0): ?><hr class="pz-boleto__corte"><?php endif; ?>
                    <p><b><?php echo htmlspecialchars($item["nombre"]); ?>.</b>
                    <?php echo htmlspecialchars($item["descripcion"] ?? ""); ?></p>
                <?php endforeach; ?>
            </div>
            <span class="pz-boleto__ojo" aria-hidden="true"></span>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <?php if ($postreDelDia): ?>
    <section class="pz-sec">
        <h3 class="pz-cinta pz-fibra">Postre del día</h3>
        <ul class="pz-grupo">
            <li class="pz-fila">
                <span class="pz-tira pz-fibra" aria-hidden="true"></span>
                <div class="pz-fila__txt">
                    <p class="pz-fila__nombre"><?php echo htmlspecialchars($postreDelDia["nombre"]); ?></p>
                    <?php if (!empty($postreDelDia["descripcion"])): ?>
                    <p class="pz-fila__desc"><?php echo htmlspecialchars($postreDelDia["descripcion"]); ?></p>
                    <?php endif; ?>
                </div>
                <?php if ((float)$postreDelDia["precio"] > 0): ?>
                <span class="pz-fila__extra">+<?php echo pzPrecio((float)$postreDelDia["precio"]); ?></span>
                <?php endif; ?>
            </li>
        </ul>
    </section>
    <?php endif; ?>

    <ul class="pz-ligas">
        <li><a href="estado_pedido.php"><span>Consultar mi pedido</span><span aria-hidden="true">›</span></a></li>
        <li><a href="<?php echo htmlspecialchars(CANAL_WHATSAPP_URL); ?>" target="_blank" rel="noopener"><span>Recibe el menú en nuestro canal de WhatsApp</span><span aria-hidden="true">›</span></a></li>
    </ul>

    <footer class="pz-pie">© 2026 Zabisu - Sabor y Servicio. Todos los derechos reservados.</footer>
</main>

<div class="pz-barra">
    <a class="pz-btn" href="pedido.php">
        <span>Ordenar ahora</span>
        <span id="pz-btn-precio"><?php echo isset($pzTipos[0], $precios[$pzTipos[0]]) ? pzPrecio($precios[$pzTipos[0]]) : ''; ?></span>
    </a>
</div>

<script>
// Pestañas Zabisu / Ejecutivo; el botón muestra el precio del menú elegido
(function () {
    var tabs = document.querySelectorAll(".pz-seg [role=tab]");
    var precio = document.getElementById("pz-btn-precio");
    tabs.forEach(function (tab) {
        tab.addEventListener("click", function () {
            tabs.forEach(function (t) {
                var activa = t === tab;
                t.setAttribute("aria-selected", activa ? "true" : "false");
                document.getElementById(t.getAttribute("aria-controls")).hidden = !activa;
            });
            if (precio && tab.dataset.precio) precio.textContent = tab.dataset.precio;
        });
    });
})();

// Cuenta regresiva al cierre de pedidos
(function () {
    var el = document.getElementById("pz-falta");
    if (!el) return;
    var cierre = <?= (int)$cierre_ts ?> * 1000;
    function actualizar() {
        var diff = cierre - Date.now();
        if (diff <= 0) {
            el.textContent = "pedidos cerrados";
            el.className = "pz-falta pz-falta--cerrado";
            return true;
        }
        var h = Math.floor(diff / 3600000);
        var m = Math.floor((diff % 3600000) / 60000);
        var s = Math.floor((diff % 60000) / 1000);
        el.textContent = h > 0 ? "faltan " + h + " h " + m + " min"
                       : m >= 10 ? "faltan " + m + " min"
                       : "faltan " + (m > 0 ? m + " min " : "") + s + " s";
        el.className = "pz-falta" + (diff < 600000 ? " pz-falta--urgente" : "");
    }
    if (!actualizar()) {
        var t = setInterval(function () { if (actualizar()) clearInterval(t); }, 1000);
    }
})();

if ("serviceWorker" in navigator) navigator.serviceWorker.register("../sw.js");
</script>
</body>
</html>
