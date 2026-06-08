<?php
require_once "../config/db.php";

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
                                <?php if (!empty($item["agotado"])): ?><span class="md-badge-agotado">Agotado</span><?php endif; ?>
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

        <!-- ══ CTA ════════════════════════════════════════════ -->
        <a href="pedido.php" class="md-cta">
            Ordenar ahora
            <span class="md-cta__flecha">→</span>
        </a>

    </div>
</div>

<script>
document.querySelectorAll(".md-tab").forEach(function (tab) {
    tab.addEventListener("click", function () {
        document.querySelectorAll(".md-tab").forEach(function (t) { t.classList.remove("md-tab--activo"); });
        document.querySelectorAll(".md-tab-panel").forEach(function (p) { p.classList.remove("md-tab-panel--activo"); });
        this.classList.add("md-tab--activo");
        var panel = document.getElementById("panel-" + this.dataset.tab);
        if (panel) panel.classList.add("md-tab-panel--activo");
    });
});

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

<footer class="cliente-footer">
    <span class="cliente-footer__slogan">© 2026 Zabisu - Sabor y Servicio. Todos los derechos reservados.</span>
</footer>

<script>
if ("serviceWorker" in navigator) {
    navigator.serviceWorker.register("../sw.js");
}
</script>

</body>
</html>
