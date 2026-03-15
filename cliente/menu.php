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
    <link rel="stylesheet" href="../assets/css/styles.css">
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

$menusPorTipo = [];
foreach ($stmtProductos->fetchAll(PDO::FETCH_ASSOC) as $p) {
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

$ordenCat = ["Plato fuerte","Sopa","Complemento","Agua","Postre"];
$iconosCat = ["Plato fuerte"=>"🍽️","Sopa"=>"🥣","Complemento"=>"🥗","Agua"=>"💧","Postre"=>"🍮"];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Menú del día · Zabisu</title>
    <link rel="stylesheet" href="../assets/css/styles.css">
</head>
<body>

<div class="md-pagina">

    <!-- ══ HERO ══════════════════════════════════════════════ -->
    <div class="md-hero">
        <div class="md-hero__glow-top"></div>
        <div class="md-hero__glow-bottom"></div>

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

            <?php foreach ($ordenCat as $cat):
                if (empty($menusPorTipo[$tipoMenu][$cat])) continue;
                $items  = $menusPorTipo[$tipoMenu][$cat];
                $icono  = $iconosCat[$cat] ?? "•";
                $esPlato = ($cat === "Plato fuerte");
                $esChip  = ($cat === "Complemento");
                $esSimple = in_array($cat, ["Agua","Postre"]);
            ?>
            <div class="md-seccion">
                <div class="md-seccion__cabecera">
                    <span class="md-seccion__icono"><?php echo $icono; ?></span>
                    <h2 class="md-seccion__titulo">
                        <?php echo $cat === "Complemento" ? "Complementos" : htmlspecialchars($cat); ?>
                    </h2>
                    <?php if ($esPlato && count($items) > 1): ?>
                        <span class="md-seccion__hint">Elige 1</span>
                    <?php endif; ?>
                </div>

                <?php if ($esChip): ?>
                    <div class="md-chips">
                        <?php foreach ($items as $item): ?>
                            <span class="md-chip"><?php echo htmlspecialchars($item["nombre"]); ?></span>
                        <?php endforeach; ?>
                    </div>

                <?php elseif ($esSimple): ?>
                    <div class="md-lista-simple">
                        <?php foreach ($items as $item): ?>
                            <span class="md-lista-simple__item">
                                <?php echo htmlspecialchars($item["nombre"]); ?>
                                <?php if (!empty($item["descripcion"])): ?>
                                    <em><?php echo htmlspecialchars($item["descripcion"]); ?></em>
                                <?php endif; ?>
                            </span>
                        <?php endforeach; ?>
                    </div>

                <?php elseif ($esPlato): ?>
                    <div class="md-platos">
                        <?php foreach ($items as $j => $item): ?>
                            <div class="md-plato">
                                <span class="md-plato__num"><?php echo $j+1; ?></span>
                                <div class="md-plato__info">
                                    <strong class="md-plato__nombre"><?php echo htmlspecialchars($item["nombre"]); ?></strong>
                                    <?php if (!empty($item["descripcion"])): ?>
                                        <p class="md-plato__desc"><?php echo htmlspecialchars($item["descripcion"]); ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                <?php else: ?>
                    <div class="md-platos">
                        <?php foreach ($items as $item): ?>
                            <div class="md-plato md-plato--sin-num">
                                <div class="md-plato__info">
                                    <strong class="md-plato__nombre"><?php echo htmlspecialchars($item["nombre"]); ?></strong>
                                    <?php if (!empty($item["descripcion"])): ?>
                                        <p class="md-plato__desc"><?php echo htmlspecialchars($item["descripcion"]); ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
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
</script>

<footer class="cliente-footer">
    <span class="cliente-footer__slogan">Sabor y Servicio</span>
</footer>

</body>
</html>
