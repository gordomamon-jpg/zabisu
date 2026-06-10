<?php
require_once "../config/db.php";
date_default_timezone_set("America/Mexico_City");

$sqlMenu = "SELECT * FROM menu_dia WHERE activo = 1 ORDER BY fecha DESC LIMIT 1";
$stmtMenu = $conexion->prepare($sqlMenu);
$stmtMenu->execute();
$menuActivo = $stmtMenu->fetch(PDO::FETCH_ASSOC);

if (!$menuActivo) { ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Zabisu</title>
    <link rel="icon" type="image/png" href="../assets/img/LOGO_NARA.png">
    <link rel="manifest" href="../manifest.json">
    <meta name="theme-color" content="#FF7A00">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Zabisu">
    <link rel="apple-touch-icon" href="../assets/img/LOGO_NARA.png">
    <link rel="stylesheet" href="../assets/css/styles.css?v=5">
</head>
<body>
<div class="contenedor">
    <div class="hero-zabisu">
        <div class="hero-zabisu__glow"></div>
        <div class="hero-zabisu__contenido">
            <p class="hero-zabisu__eyebrow">ZABISU</p>
            <h1 class="hero-zabisu__titulo">Sin menú disponible</h1>
            <p class="hero-zabisu__texto">Aún no hay un menú publicado para hoy. Vuelve pronto.</p>
        </div>
    </div>
</div>
</body>
</html>
<?php exit; }

$sqlProductos = "SELECT * FROM productos WHERE id_menu = :id_menu AND disponible = 1
                 ORDER BY tipo_menu, categoria, nombre";
$stmtProductos = $conexion->prepare($sqlProductos);
$stmtProductos->bindParam(":id_menu", $menuActivo["id_menu"], PDO::PARAM_INT);
$stmtProductos->execute();

$sqlConteos = "SELECT dp.id_producto, COUNT(*) AS total_pedidos
               FROM detalle_pedido dp
               INNER JOIN pedido_menus pm ON dp.id_pedido_menu = pm.id_pedido_menu
               INNER JOIN pedidos p ON pm.id_pedido = p.id_pedido
               INNER JOIN productos pr ON dp.id_producto = pr.id_producto
               WHERE p.estado != 'Cancelado'
                 AND p.es_prueba = 0
                 AND pr.id_menu = :id_menu
               GROUP BY dp.id_producto";
$stmtConteos = $conexion->prepare($sqlConteos);
$stmtConteos->bindParam(":id_menu", $menuActivo["id_menu"], PDO::PARAM_INT);
$stmtConteos->execute();
$conteosProductos = [];
foreach ($stmtConteos->fetchAll(PDO::FETCH_ASSOC) as $fila) {
    $conteosProductos[$fila["id_producto"]] = (int)$fila["total_pedidos"];
}

$menusPorTipo = [];
foreach ($stmtProductos->fetchAll(PDO::FETCH_ASSOC) as $p) {
    $totalActual = $conteosProductos[(int)$p["id_producto"]] ?? 0;
    $limite = isset($p["limite_pedidos"]) ? (int)$p["limite_pedidos"] : 0;
    $p["agotado"] = ($p["categoria"] === "Plato fuerte" && $limite > 0 && $totalActual >= $limite);
    $menusPorTipo[$p["tipo_menu"]][$p["categoria"]][] = $p;
}

$sqlPrecios = "SELECT nombre_menu, precio FROM tipos_menu WHERE activo = 1";
$stmtPrecios = $conexion->prepare($sqlPrecios);
$stmtPrecios->execute();
$precios = [];
foreach ($stmtPrecios->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $precios[$r["nombre_menu"]] = (float)$r["precio"];
}

$dias   = ['Sunday'=>'Domingo','Monday'=>'Lunes','Tuesday'=>'Martes','Wednesday'=>'Miércoles','Thursday'=>'Jueves','Friday'=>'Viernes','Saturday'=>'Sábado'];
$meses  = ['01'=>'enero','02'=>'febrero','03'=>'marzo','04'=>'abril','05'=>'mayo','06'=>'junio','07'=>'julio','08'=>'agosto','09'=>'septiembre','10'=>'octubre','11'=>'noviembre','12'=>'diciembre'];
$ts         = strtotime($menuActivo["fecha"]);
$fechaBonita = ($dias[date("l",$ts)] ?? "") . " " . date("j",$ts) . " de " . ($meses[date("m",$ts)] ?? "");

$ordenCat = ["Plato fuerte","Sopa","Complemento","Agua","Cortesia"];
$iconosCat = ["Plato fuerte"=>"🍽️","Sopa"=>"🥣","Complemento"=>"🥗","Agua"=>"💧","Cortesia"=>"🍬"];

/* ── Productos populares (platos fuertes y complementos) ── */
$popularCats = ["Plato fuerte", "Complemento"];
$maxConteoPorCat = [];
foreach ($popularCats as $catPop) {
    $max = 0;
    foreach ($menusPorTipo as $cats) {
        foreach ($cats[$catPop] ?? [] as $p) {
            $c = $conteosProductos[(int)$p["id_producto"]] ?? 0;
            if ($c > $max) $max = $c;
        }
    }
    $maxConteoPorCat[$catPop] = $max;
}
$popularIds = [];
foreach ($popularCats as $catPop) {
    $max = $maxConteoPorCat[$catPop];
    if ($max < 3) continue;
    foreach ($menusPorTipo as $cats) {
        foreach ($cats[$catPop] ?? [] as $p) {
            if (($conteosProductos[(int)$p["id_producto"]] ?? 0) === $max) {
                $popularIds[(int)$p["id_producto"]] = true;
            }
        }
    }
}

/* ── Timestamp de cierre para el countdown ── */
$cierre_ts = (int)strtotime($menuActivo["pedido_hasta"]);

/* ── Calificaciones públicas ── */
$stmtRatings = $conexion->prepare(
    "SELECT COUNT(*) AS total, ROUND(AVG(calificacion), 1) AS promedio FROM feedback_pedidos"
);
$stmtRatings->execute();
$ratingsGlobal = $stmtRatings->fetch(PDO::FETCH_ASSOC);
$ratingsTotal  = (int)($ratingsGlobal["total"]    ?? 0);
$ratingsProm   = (float)($ratingsGlobal["promedio"] ?? 0);

$stmtRatingsDist = $conexion->prepare(
    "SELECT calificacion, COUNT(*) AS total
     FROM feedback_pedidos
     GROUP BY calificacion
     ORDER BY calificacion DESC"
);
$stmtRatingsDist->execute();
$ratingsDist = [];
foreach ($stmtRatingsDist->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $ratingsDist[(int)$row["calificacion"]] = (int)$row["total"];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Menú del día · Zabisu</title>
    <link rel="icon" type="image/png" href="../assets/img/LOGO_NARA.png">
    <link rel="manifest" href="../manifest.json">
    <meta name="theme-color" content="#FF7A00">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Zabisu">
    <link rel="apple-touch-icon" href="../assets/img/LOGO_NARA.png">
    <link rel="stylesheet" href="../assets/css/styles.css?v=5">
</head>
<style>
    .hamburguer-btn {
        position: fixed;
        top: 16px;
        right: 16px;
        z-index: 200;
        background: rgba(12,12,15,.75);
        border: 1px solid rgba(255,255,255,.12);
        backdrop-filter: blur(8px);
        -webkit-backdrop-filter: blur(8px);
        border-radius: 10px;
        width: 42px;
        height: 42px;
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        gap: 5px;
        cursor: pointer;
        padding: 0;
    }
    .hamburguer-btn span {
        display: block;
        width: 20px;
        height: 2px;
        background: #fff;
        border-radius: 2px;
        transition: transform .25s, opacity .25s;
    }
    .hamburguer-btn.abierto span:nth-child(1) { transform: translateY(7px) rotate(45deg); }
    .hamburguer-btn.abierto span:nth-child(2) { opacity: 0; }
    .hamburguer-btn.abierto span:nth-child(3) { transform: translateY(-7px) rotate(-45deg); }

    .nav-overlay {
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,.5);
        z-index: 190;
        opacity: 0;
        pointer-events: none;
        transition: opacity .25s;
    }
    .nav-overlay.visible { opacity: 1; pointer-events: all; }

    .nav-drawer {
        position: fixed;
        top: 0;
        right: 0;
        bottom: 0;
        width: 260px;
        max-width: 85vw;
        background: #0c0c0f;
        z-index: 195;
        transform: translateX(100%);
        transition: transform .28s cubic-bezier(.4,0,.2,1);
        display: flex;
        flex-direction: column;
        padding: 72px 0 32px;
    }
    .nav-drawer.abierto { transform: translateX(0); }

    .nav-drawer__titulo {
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 3px;
        color: #ff7a00;
        text-transform: uppercase;
        padding: 0 24px 16px;
        border-bottom: 1px solid rgba(255,255,255,.08);
        margin-bottom: 8px;
    }

    .nav-drawer__item {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 14px 24px;
        color: #fff;
        text-decoration: none;
        font-size: 15px;
        font-weight: 600;
        transition: background .15s;
    }
    .nav-drawer__item:hover { background: rgba(255,255,255,.06); }
    .nav-drawer__item__icono {
        font-size: 20px;
        width: 28px;
        text-align: center;
        flex-shrink: 0;
    }

    /* ── Día del Niño (30 abr) ────────────────────────────── */
    .dnino-confetti {
        position: absolute;
        inset: 0;
        pointer-events: none;
        overflow: hidden;
    }
    .dnino-confetti span {
        position: absolute;
        bottom: -8px;
        border-radius: 50%;
        opacity: 0;
        animation: dnino-float linear infinite;
    }
    .dnino-confetti span:nth-child(1)  { width:5px;  height:5px;  background:#ff6b9d; left:8%;  animation-duration:7s;   animation-delay:0s;    }
    .dnino-confetti span:nth-child(2)  { width:6px;  height:6px;  background:#4ecdc4; left:18%; animation-duration:9s;   animation-delay:1.3s;  }
    .dnino-confetti span:nth-child(3)  { width:5px;  height:5px;  background:#ffe66d; left:30%; animation-duration:8s;   animation-delay:0.5s;  }
    .dnino-confetti span:nth-child(4)  { width:7px;  height:7px;  background:#a29bfe; left:44%; animation-duration:10s;  animation-delay:2.1s;  }
    .dnino-confetti span:nth-child(5)  { width:5px;  height:5px;  background:#ff6b9d; left:57%; animation-duration:7.5s; animation-delay:0.9s;  }
    .dnino-confetti span:nth-child(6)  { width:6px;  height:6px;  background:#ffe66d; left:70%; animation-duration:9.5s; animation-delay:1.8s;  }
    .dnino-confetti span:nth-child(7)  { width:5px;  height:5px;  background:#4ecdc4; left:82%; animation-duration:8.5s; animation-delay:3.2s;  }
    .dnino-confetti span:nth-child(8)  { width:6px;  height:6px;  background:#c7f2a4; left:93%; animation-duration:7s;   animation-delay:2.6s;  }
    @keyframes dnino-float {
        0%   { transform: translateY(0)      scale(0.8); opacity: 0;   }
        12%  {                                            opacity: 0.55; }
        88%  {                                            opacity: 0.4;  }
        100% { transform: translateY(-280px) scale(1.1); opacity: 0;   }
    }
    /* ── Animación de entrada ── */
    .md-seccion__cabecera,
    .md-plato,
    .md-chip { will-change: opacity, transform; }

    /* ── Countdown ── */
    .md-hero__countdown {
        font-size: 13px;
        font-weight: 700;
        letter-spacing: .3px;
        margin-top: 5px;
        min-height: 18px;
    }
    .md-hero__countdown--ok      { color: rgba(255,255,255,.7); }
    .md-hero__countdown--pronto  { color: #facc15; }
    .md-hero__countdown--urgente { color: #f87171; animation: md-pulso .9s ease-in-out infinite; }
    @keyframes md-pulso { 0%,100% { opacity:1; } 50% { opacity:.45; } }

    /* ── Badge popular ── */
    .md-badge-popular {
        display: inline-flex;
        align-items: center;
        gap: 3px;
        font-size: 11px;
        font-weight: 700;
        color: #ff7a00;
        background: rgba(255,122,0,.12);
        border: 1px solid rgba(255,122,0,.3);
        border-radius: 99px;
        padding: 2px 8px;
        letter-spacing: .2px;
        flex-shrink: 0;
        white-space: nowrap;
    }

    /* ── Rating pill en el hero ── */
    .md-hero__rating {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        margin-top: 14px;
        padding: 7px 16px;
        border-radius: 999px;
        background: rgba(255,255,255,0.06);
        border: 1px solid rgba(255,255,255,0.11);
        backdrop-filter: blur(4px);
    }
    .md-hero__rating__estrellas {
        display: flex;
        gap: 2px;
    }
    .md-hero__rating__estrella {
        font-size: 13px;
        color: rgba(255,255,255,.18);
    }
    .md-hero__rating__estrella--llena { color: #ff7a00; }
    .md-hero__rating__estrella--media {
        background: linear-gradient(90deg, #ff7a00 50%, rgba(255,255,255,.18) 50%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }
    .md-hero__rating__score {
        font-size: 14px;
        font-weight: 800;
        color: #fff;
        letter-spacing: -.3px;
    }
    .md-hero__rating__sep {
        width: 1px;
        height: 12px;
        background: rgba(255,255,255,.18);
        flex-shrink: 0;
    }
    .md-hero__rating__label {
        font-size: 12px;
        font-weight: 700;
        color: #ff7a00;
        letter-spacing: .3px;
    }
    .md-hero__rating__total {
        font-size: 11px;
        color: rgba(255,255,255,.35);
        font-weight: 500;
    }

    .dnino-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        margin-top: 14px;
        padding: 6px 15px;
        border-radius: 999px;
        background: rgba(255,255,255,0.07);
        border: 1px solid rgba(255,255,255,0.13);
        font-size: 13px;
        font-weight: 600;
        color: rgba(255,255,255,0.8);
        letter-spacing: 0.2px;
    }
</style>

<body>

<!-- ── Hamburger ── -->
<button class="hamburguer-btn" id="hamburguer-btn" aria-label="Menú">
    <span></span><span></span><span></span>
</button>

<!-- ── Overlay ── -->
<div class="nav-overlay" id="nav-overlay"></div>

<!-- ── Drawer ── -->
<nav class="nav-drawer" id="nav-drawer">
    <div class="nav-drawer__titulo">Zabisu</div>
    <a class="nav-drawer__item" href="estado_pedido.php">
        <span class="nav-drawer__item__icono">🔍</span>
        Consultar mi pedido
    </a>
    <a class="nav-drawer__item" href="menu.php">
        <span class="nav-drawer__item__icono">🍽️</span>
        Ver menú del día
    </a>
    <a class="nav-drawer__item" href="pedido.php">
        <span class="nav-drawer__item__icono">🛒</span>
        Hacer un pedido
    </a>
</nav>

<div class="md-pagina">

    <!-- ══ HERO ══════════════════════════════════════════════ -->
    <div class="md-hero">
        <div class="md-hero__glow-top"></div>
        <div class="md-hero__glow-bottom"></div>

        <?php if (in_array(date('m-d'), ['04-29','04-30'])): ?>
        <div class="dnino-confetti" aria-hidden="true">
            <span></span><span></span><span></span><span></span>
            <span></span><span></span><span></span><span></span>
        </div>
        <?php endif; ?>

        <p class="md-hero__eyebrow">Menú del día</p>
        <div class="md-hero__marca-grupo">
            <img class="md-hero__logo" src="../assets/img/LOGO_BLANCO.png" alt="Zabisu">
            <h1 class="md-hero__marca">Zabisu</h1>
        </div>
        <p class="md-hero__fecha"><?php echo htmlspecialchars($fechaBonita); ?></p>

        <div class="md-hero__precios">
            <?php foreach (["Zabisu","Ejecutivo"] as $t): ?>
                <?php if (isset($precios[$t])): ?>
                <div class="md-precio-pill">
                    <span class="md-precio-pill__tipo"><?php echo $t; ?></span>
                    <span class="md-precio-pill__sep">·</span>
                    <span class="md-precio-pill__valor">$<?php echo number_format($precios[$t],2); ?></span>
                </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>

        <div class="md-hero__cierre">
            Pedidos hasta las <strong><?php echo date("g:i A", strtotime($menuActivo["pedido_hasta"])); ?></strong>
        </div>
        <div class="md-hero__countdown md-hero__countdown--ok" id="md-countdown"></div>

        <?php if ($ratingsTotal >= 3):
            $promR = (float)$ratingsProm;
            $promPiso = floor($promR);
            $tieneMediaR = ($promR - $promPiso) >= 0.25;
            $etqR = match(true) {
                $promR >= 4.5 => "Excelente",
                $promR >= 3.5 => "Bueno",
                $promR >= 2.5 => "Regular",
                default       => "Mejorable",
            };
        ?>
        <div class="md-hero__rating">
            <div class="md-hero__rating__estrellas">
                <?php for ($s = 1; $s <= 5; $s++):
                    if ($s <= $promPiso) $cls = "md-hero__rating__estrella--llena";
                    elseif ($s === $promPiso + 1 && $tieneMediaR) $cls = "md-hero__rating__estrella--media";
                    else $cls = "";
                ?>
                    <span class="md-hero__rating__estrella <?php echo $cls; ?>">★</span>
                <?php endfor; ?>
            </div>
            <span class="md-hero__rating__score"><?php echo number_format($promR, 1); ?></span>
            <span class="md-hero__rating__sep"></span>
            <span class="md-hero__rating__label"><?php echo $etqR; ?></span>
            <span class="md-hero__rating__total"><?php echo $ratingsTotal; ?> opiniones</span>
        </div>
        <?php endif; ?>

        <?php if (in_array(date('m-d'), ['04-29','04-30'])): ?>
        <div class="dnino-badge">🎈 ¡Feliz Día del Niño!</div>
        <?php endif; ?>
    </div>

    <!-- ══ TABS ═══════════════════════════════════════════════ -->
    <div class="md-contenido">

        <div class="md-tabs" role="tablist">
            <?php foreach (["Zabisu","Ejecutivo"] as $i => $t): ?>
                <?php if (empty($menusPorTipo[$t])) continue; ?>
                <button class="md-tab <?php echo $i === 0 ? 'md-tab--activo' : ''; ?>"
                        data-tab="<?php echo $t; ?>" type="button" role="tab">
                    <span class="md-tab__nombre">Menú <?php echo $t; ?></span>
                    <?php if (isset($precios[$t])): ?>
                        <span class="md-tab__precio">$<?php echo number_format($precios[$t],2); ?></span>
                    <?php endif; ?>
                </button>
            <?php endforeach; ?>
        </div>

        <!-- ══ CONTENIDO POR TAB ═════════════════════════════ -->
        <?php foreach (["Zabisu","Ejecutivo"] as $i => $tipoMenu):
            if (empty($menusPorTipo[$tipoMenu])) continue;
        ?>
        <div class="md-tab-panel <?php echo $i === 0 ? 'md-tab-panel--activo' : ''; ?>"
             id="panel-<?php echo $tipoMenu; ?>">

            <?php
            // Renderizar Agua y Cortesia juntos en una fila
            $tieneAgua     = !empty($menusPorTipo[$tipoMenu]["Agua"]);
            $tieneCortesia = !empty($menusPorTipo[$tipoMenu]["Cortesia"]);
            $simplesMostradas = false;
            ?>

            <?php foreach ($ordenCat as $cat):
                if (empty($menusPorTipo[$tipoMenu][$cat])) continue;
                $items   = $menusPorTipo[$tipoMenu][$cat];
                $icono   = $iconosCat[$cat] ?? "•";
                $esPlato = ($cat === "Plato fuerte");
                $esChip  = ($cat === "Complemento");
                $esSimple = in_array($cat, ["Agua","Cortesia"]);

                // Agua y Cortesia se renderizan juntas, solo una vez
                if ($esSimple && $simplesMostradas) continue;
                if ($esSimple) $simplesMostradas = true;
            ?>

            <?php if ($esSimple): ?>
            <div class="md-seccion">
                <div class="md-fila-simple">
                    <?php foreach (["Agua","Cortesia"] as $catSimple):
                        if (empty($menusPorTipo[$tipoMenu][$catSimple])) continue;
                    ?>
                    <div class="md-fila-simple__col">
                        <div class="md-seccion__cabecera">
                            <span class="md-seccion__icono"><?php echo $iconosCat[$catSimple]; ?></span>
                            <h2 class="md-seccion__titulo"><?php echo $catSimple; ?></h2>
                        </div>
                        <div class="md-chips">
                            <?php foreach ($menusPorTipo[$tipoMenu][$catSimple] as $item): ?>
                                <span class="md-chip <?php echo !empty($item['agotado']) ? 'md-chip--agotado' : ''; ?>">
                                    <?php echo htmlspecialchars($item["nombre"]); ?>
                                    <?php if (!empty($item["descripcion"])): ?>
                                        <span class="md-chip__desc"><?php echo htmlspecialchars($item["descripcion"]); ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($item["agotado"])): ?><span class="md-badge-agotado">Agotado</span><?php endif; ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php else: ?>
            <div class="md-seccion">
                <div class="md-seccion__cabecera">
                    <span class="md-seccion__icono"><?php echo $icono; ?></span>
                    <h2 class="md-seccion__titulo">
                        <?php echo $cat === "Complemento" ? "Complementos" : htmlspecialchars($cat); ?>
                    </h2>
                </div>

                <?php if ($esChip): ?>
                    <div class="md-chips">
                        <?php foreach ($items as $item): ?>
                            <span class="md-chip <?php echo !empty($item['agotado']) ? 'md-chip--agotado' : ''; ?>">
                                <?php echo htmlspecialchars($item["nombre"]); ?>
                                <?php if (!empty($item["descripcion"])): ?>
                                    <span class="md-chip__desc"><?php echo htmlspecialchars($item["descripcion"]); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($item["agotado"])): ?>
                                    <span class="md-badge-agotado">Agotado</span>
                                <?php elseif (!empty($popularIds[(int)$item["id_producto"]])): ?>
                                    <span class="md-badge-popular">🔥 Popular</span>
                                <?php endif; ?>
                            </span>
                        <?php endforeach; ?>
                    </div>

                <?php elseif ($esPlato): ?>
                    <div class="md-platos">
                        <?php foreach ($items as $j => $item): ?>
                            <div class="md-plato <?php echo !empty($item['agotado']) ? 'md-plato--agotado' : ''; ?>">
                                <span class="md-plato__num"><?php echo $j+1; ?></span>
                                <div class="md-plato__info">
                                    <strong class="md-plato__nombre"><?php echo htmlspecialchars($item["nombre"]); ?></strong>
                                    <?php if (!empty($item["descripcion"])): ?>
                                        <p class="md-plato__desc"><?php echo htmlspecialchars($item["descripcion"]); ?></p>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($item["agotado"])): ?>
                                    <span class="md-badge-agotado">Agotado</span>
                                <?php elseif (!empty($popularIds[(int)$item["id_producto"]])): ?>
                                    <span class="md-badge-popular">🔥 Popular</span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                <?php else: ?>
                    <div class="md-platos">
                        <?php foreach ($items as $item): ?>
                            <div class="md-plato md-plato--sin-num <?php echo !empty($item['agotado']) ? 'md-plato--agotado' : ''; ?>">
                                <div class="md-plato__info">
                                    <strong class="md-plato__nombre"><?php echo htmlspecialchars($item["nombre"]); ?></strong>
                                    <?php if (!empty($item["descripcion"])): ?>
                                        <p class="md-plato__desc"><?php echo htmlspecialchars($item["descripcion"]); ?></p>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($item["agotado"])): ?>
                                    <span class="md-badge-agotado">Agotado</span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php endforeach; ?>

        </div>
        <?php endforeach; ?>

    </div><!-- /.md-contenido -->

    <div class="md-contenido">
        <!-- ══ CTA ════════════════════════════════════════════ -->
        <a href="pedido.php" class="md-cta">
            Ordenar ahora
            <span class="md-cta__flecha">→</span>
        </a>
    </div>
</div>

<script>
function animarPanel(panel) {
    if (!panel) return;
    var items = Array.from(panel.querySelectorAll(".md-seccion__cabecera, .md-plato, .md-chip"));
    var EASE = "cubic-bezier(.2,0,.1,1)";
    var DUR  = ".4s";
    var GAP  = 45;

    items.forEach(function (el) {
        el.style.transition = "none";
        el.style.opacity    = "0";
        el.style.transform  = "translateY(16px)";
    });

    requestAnimationFrame(function () {
        requestAnimationFrame(function () {
            items.forEach(function (el, i) {
                var d = (i * GAP) + "ms";
                el.style.transition = "opacity " + DUR + " " + EASE + " " + d +
                                      ", transform " + DUR + " " + EASE + " " + d;
                el.style.opacity   = "1";
                el.style.transform = "translateY(0)";
            });
        });
    });
}

document.querySelectorAll(".md-tab").forEach(function (tab) {
    tab.addEventListener("click", function () {
        document.querySelectorAll(".md-tab").forEach(function (t) { t.classList.remove("md-tab--activo"); });
        document.querySelectorAll(".md-tab-panel").forEach(function (p) { p.classList.remove("md-tab-panel--activo"); });
        this.classList.add("md-tab--activo");
        var panel = document.getElementById("panel-" + this.dataset.tab);
        if (panel) { panel.classList.add("md-tab-panel--activo"); animarPanel(panel); }
    });
});

animarPanel(document.querySelector(".md-tab-panel--activo"));

// Al tocar cualquier ítem del menú, bajar al botón y animarlo
var cta = document.querySelector(".md-cta");

function llamarCta() {
    if (!cta) return;
    cta.scrollIntoView({ behavior: "smooth", block: "center" });
    cta.classList.remove("md-cta--pulso");
    void cta.offsetWidth; // forzar reflow para reiniciar animación
    cta.classList.add("md-cta--pulso");
}

document.querySelectorAll(".md-plato:not(.md-plato--agotado), .md-chip:not(.md-chip--agotado)").forEach(function (el) {
    el.addEventListener("click", llamarCta);
});
</script>

<script>
(function () {
    var btn     = document.getElementById("hamburguer-btn");
    var drawer  = document.getElementById("nav-drawer");
    var overlay = document.getElementById("nav-overlay");

    function abrir() {
        drawer.classList.add("abierto");
        overlay.classList.add("visible");
        btn.classList.add("abierto");
        document.body.style.overflow = "hidden";
    }
    function cerrar() {
        drawer.classList.remove("abierto");
        overlay.classList.remove("visible");
        btn.classList.remove("abierto");
        document.body.style.overflow = "";
    }

    btn.addEventListener("click", function () {
        drawer.classList.contains("abierto") ? cerrar() : abrir();
    });
    overlay.addEventListener("click", cerrar);
    document.addEventListener("keydown", function (e) {
        if (e.key === "Escape") cerrar();
    });
})();
</script>

<script>
(function () {
    var el     = document.getElementById("md-countdown");
    if (!el) return;
    var cierre = <?= $cierre_ts ?> * 1000;

    function actualizar() {
        var diff = cierre - Date.now();
        if (diff <= 0) {
            el.textContent = "Pedidos cerrados";
            el.className = "md-hero__countdown md-hero__countdown--urgente";
            return;
        }
        var h = Math.floor(diff / 3600000);
        var m = Math.floor((diff % 3600000) / 60000);
        var s = Math.floor((diff % 60000) / 1000);
        var texto;
        if (h > 0)      texto = "Cierra en " + h + "h " + m + "m";
        else if (m >= 10) texto = "Cierra en " + m + "m";
        else              texto = "Cierra en " + (m > 0 ? m + "m " : "") + s + "s";

        el.textContent = texto;
        el.className = "md-hero__countdown " + (
            diff < 600000  ? "md-hero__countdown--urgente" :
            diff < 1800000 ? "md-hero__countdown--pronto"  :
                             "md-hero__countdown--ok"
        );
    }

    actualizar();
    setInterval(actualizar, 1000);
})();
</script>

<footer class="cliente-footer">
    <span class="cliente-footer__slogan">© 2026 Zabisu - Sabor y Servicio. Todos los derechos reservados.</span>
</footer>

<!-- ══ PWA MODAL ═══════════════════════════════════════════ -->
<div id="pwa-modal" style="display:none;" aria-modal="true" role="dialog">
    <div class="pwa-overlay" id="pwa-overlay"></div>
    <div class="pwa-sheet">
        <button class="pwa-close" id="pwa-close" aria-label="Cerrar">✕</button>

        <p class="pwa-eyebrow">Sin descargas · Gratis</p>
        <h2 class="pwa-titulo">Instala Zabisu</h2>

        <!-- Teléfono 3D -->
        <div class="pwa-phone-scene">
            <div class="pwa-phone-float">
                <div class="pwa-phone">
                    <div class="pwa-phone__side pwa-phone__side--left"></div>
                    <div class="pwa-phone__side pwa-phone__side--right"></div>
                    <div class="pwa-phone__face">
                        <div class="pwa-phone__island"></div>
                        <div class="pwa-phone__screen">
                            <div class="pwa-phone__grid">
                                <span style="background:#ff6b6b"></span><span style="background:#ff7a00"></span><span style="background:#facc15"></span><span style="background:#4ac86e"></span>
                                <span style="background:#38bdf8"></span><span style="background:#a78bfa"></span><span style="background:#f472b6"></span><span style="background:#34d399"></span>
                                <span style="background:#fb923c"></span><span style="background:#60a5fa"></span><span style="background:#e879f9"></span><span style="background:#4ade80"></span>
                            </div>
                            <div class="pwa-phone__app">
                                <div class="pwa-phone__app-icon">
                                    <img src="../assets/img/LOGO_NARA.png" alt="Zabisu">
                                </div>
                                <span class="pwa-phone__app-label">Zabisu</span>
                            </div>
                        </div>
                        <div class="pwa-phone__home"></div>
                    </div>
                </div>
            </div>
            <div class="pwa-phone__glow"></div>
        </div>

        <!-- Pasos -->
        <div class="pwa-steps" id="pwa-steps"></div>
    </div>
</div>

<style>
/* ── Modal overlay ── */
#pwa-modal {
    position: fixed; inset: 0; z-index: 9999;
    display: flex; align-items: flex-end; justify-content: center;
}
.pwa-overlay {
    position: absolute; inset: 0;
    background: rgba(0,0,0,.7);
    backdrop-filter: blur(6px);
    -webkit-backdrop-filter: blur(6px);
    opacity: 0; transition: opacity .35s;
}
#pwa-modal.visible .pwa-overlay { opacity: 1; }

/* ── Sheet ── */
.pwa-sheet {
    position: relative; z-index: 1;
    width: 100%; max-width: 480px;
    background: #0f0f18;
    border-radius: 28px 28px 0 0;
    padding: 28px 24px 40px;
    text-align: center;
    transform: translateY(100%);
    transition: transform .5s cubic-bezier(.34,1.18,.64,1);
    box-shadow: 0 -2px 60px rgba(255,122,0,.12);
    border-top: 1px solid rgba(255,122,0,.2);
}
#pwa-modal.visible .pwa-sheet { transform: translateY(0); }

.pwa-close {
    position: absolute; top: 16px; right: 18px;
    background: rgba(255,255,255,.08); border: none;
    color: rgba(255,255,255,.5); border-radius: 50%;
    width: 32px; height: 32px; font-size: 14px;
    cursor: pointer; display: flex; align-items: center; justify-content: center;
    transition: background .15s, color .15s;
}
.pwa-close:hover { background: rgba(255,255,255,.15); color: #fff; }

.pwa-eyebrow {
    font-size: 10px; font-weight: 700; letter-spacing: 2.5px;
    text-transform: uppercase; color: rgba(255,122,0,.6); margin: 0 0 6px;
}
.pwa-titulo {
    font-size: 22px; font-weight: 900; color: #fff;
    margin: 0 0 20px; letter-spacing: -.3px;
}

/* ── Teléfono 3D ── */
.pwa-phone-scene {
    position: relative; display: flex;
    justify-content: center; align-items: center;
    height: 200px; margin-bottom: 8px;
}
.pwa-phone-float {
    animation: pwa-float 3s ease-in-out infinite alternate;
}
@keyframes pwa-float {
    from { transform: translateY(0); }
    to   { transform: translateY(-10px); }
}
.pwa-phone {
    position: relative; width: 100px; height: 178px;
    animation: pwa-spin 4s ease-in-out infinite alternate;
    transform-style: preserve-3d;
    transform: perspective(600px) rotateY(-18deg) rotateX(-5deg);
}
@keyframes pwa-spin {
    from { transform: perspective(600px) rotateY(-22deg) rotateX(-5deg); }
    to   { transform: perspective(600px) rotateY(22deg)  rotateX(-5deg); }
}

/* Cara frontal */
.pwa-phone__face {
    position: absolute; inset: 0;
    background: linear-gradient(160deg, #1e1e2e 0%, #12121c 100%);
    border-radius: 22px;
    border: 2px solid rgba(255,255,255,.18);
    overflow: hidden;
    display: flex; flex-direction: column; align-items: center;
    box-shadow:
        inset 0 1px 0 rgba(255,255,255,.12),
        inset -1px 0 0 rgba(255,255,255,.06);
}
/* Lados del teléfono para dar volumen */
.pwa-phone__side {
    position: absolute; top: 8px; bottom: 8px; width: 4px;
    background: linear-gradient(180deg, #2a2a3e, #111120);
    border-radius: 2px;
}
.pwa-phone__side--left  { left: -3px;  box-shadow: -1px 0 4px rgba(0,0,0,.4); }
.pwa-phone__side--right { right: -3px; box-shadow:  1px 0 4px rgba(0,0,0,.4); }

/* Dynamic island */
.pwa-phone__island {
    width: 32px; height: 9px;
    background: #000; border-radius: 99px;
    margin: 8px auto 6px; flex-shrink: 0;
}
/* Pantalla */
.pwa-phone__screen {
    flex: 1; width: 100%; padding: 0 8px 6px;
    display: flex; flex-direction: column;
    align-items: center; gap: 6px;
}
/* Grid de apps */
.pwa-phone__grid {
    display: grid; grid-template-columns: repeat(4, 1fr); gap: 4px;
    width: 100%;
}
.pwa-phone__grid span {
    display: block; aspect-ratio: 1;
    border-radius: 5px; opacity: .85;
}
/* App destacada */
.pwa-phone__app {
    display: flex; flex-direction: column; align-items: center; gap: 3px;
    margin-top: 2px;
}
.pwa-phone__app-icon {
    width: 36px; height: 36px; border-radius: 10px;
    background: #ff7a00;
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 2px 10px rgba(255,122,0,.5);
    overflow: hidden;
}
.pwa-phone__app-icon img { width: 28px; height: 28px; object-fit: contain; }
.pwa-phone__app-label {
    font-size: 8px; color: rgba(255,255,255,.7); font-weight: 600;
}
/* Barra home */
.pwa-phone__home {
    width: 36px; height: 4px; border-radius: 99px;
    background: rgba(255,255,255,.25); margin: 4px auto 6px; flex-shrink: 0;
}
/* Glow debajo del teléfono */
.pwa-phone__glow {
    position: absolute; bottom: -10px; left: 50%;
    transform: translateX(-50%);
    width: 80px; height: 20px;
    background: radial-gradient(ellipse, rgba(255,122,0,.35) 0%, transparent 70%);
    filter: blur(6px);
    animation: pwa-glow 3s ease-in-out infinite alternate;
}
@keyframes pwa-glow {
    from { opacity: .5; transform: translateX(-50%) scaleX(.8); }
    to   { opacity: 1;  transform: translateX(-50%) scaleX(1.2); }
}

/* ── Pasos ── */
.pwa-steps {
    display: flex; flex-direction: column;
    gap: 10px; margin-top: 18px; text-align: left;
}
.pwa-step {
    display: flex; align-items: center; gap: 12px;
    background: rgba(255,255,255,.04);
    border: 1px solid rgba(255,255,255,.07);
    border-radius: 14px; padding: 10px 14px;
}
.pwa-step__num {
    width: 26px; height: 26px; border-radius: 50%; flex-shrink: 0;
    background: rgba(255,122,0,.15); border: 1px solid rgba(255,122,0,.35);
    color: #ff7a00; font-size: 12px; font-weight: 800;
    display: flex; align-items: center; justify-content: center;
}
.pwa-step__ico { font-size: 20px; flex-shrink: 0; }
.pwa-step__txt { font-size: 13px; color: rgba(255,255,255,.7); line-height: 1.4; }
.pwa-step__txt strong { color: #fff; font-weight: 700; }
</style>

<script>
(function () {
    if ("serviceWorker" in navigator) navigator.serviceWorker.register("../sw.js");

    var yaInstalada = window.navigator.standalone === true ||
                      window.matchMedia("(display-mode: standalone)").matches;
    if (yaInstalada) return;
    try { if (localStorage.getItem("pwa_dismissed")) return; } catch(e) {}

    var ua      = navigator.userAgent;
    var ios     = /iPhone|iPad|iPod/.test(ua) && !/CriOS|FxiOS/.test(ua);
    var android = /Android/.test(ua) && /Chrome\//.test(ua) && !/SamsungBrowser/.test(ua);
    if (!ios && !android) return;

    var modal   = document.getElementById("pwa-modal");
    var stepsEl = document.getElementById("pwa-steps");

    var pasos = ios ? [
        { ico: "⬆️", txt: "Abre Safari y toca el botón de <strong>Compartir</strong> en la parte inferior" },
        { ico: "➕", txt: "Desplázate y elige <strong>\"Agregar a pantalla de inicio\"</strong>" },
        { ico: "✅", txt: "Toca <strong>Agregar</strong> — aparecerá el ícono de Zabisu" }
    ] : [
        { ico: "⋮",  txt: "Toca los <strong>tres puntos</strong> en la esquina superior derecha de Chrome" },
        { ico: "📲", txt: "Selecciona <strong>\"Instalar app\"</strong> o <strong>\"Agregar a pantalla de inicio\"</strong>" },
        { ico: "✅", txt: "Confirma — aparecerá el ícono de Zabisu" }
    ];

    stepsEl.innerHTML = pasos.map(function (p, i) {
        return '<div class="pwa-step">' +
            '<span class="pwa-step__num">' + (i+1) + '</span>' +
            '<span class="pwa-step__ico">' + p.ico + '</span>' +
            '<span class="pwa-step__txt">' + p.txt + '</span>' +
        '</div>';
    }).join("");

    modal.style.display = "flex";
    setTimeout(function () { modal.classList.add("visible"); }, 2800);

    function cerrar() {
        modal.classList.remove("visible");
        setTimeout(function () { modal.style.display = "none"; }, 500);
        try { localStorage.setItem("pwa_dismissed", "1"); } catch(e) {}
    }

    document.getElementById("pwa-close").addEventListener("click", cerrar);
    document.getElementById("pwa-overlay").addEventListener("click", cerrar);
})();
</script>

</body>
</html>
