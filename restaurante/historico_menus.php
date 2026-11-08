<?php
require_once "../config/db.php";
require_once "auth_check.php";

// ── Todos los menús con sus productos ────────────────────
$rows = $conexion->query(
    "SELECT md.id_menu, md.fecha, md.activo,
            p.tipo_menu, p.categoria, p.nombre, p.descripcion
     FROM menu_dia md
     LEFT JOIN productos p ON p.id_menu = md.id_menu
     ORDER BY md.fecha DESC, p.tipo_menu, FIELD(p.categoria,'Plato fuerte','Sopa','Complemento','Postre','Agua'), p.nombre"
)->fetchAll(PDO::FETCH_ASSOC);

// Agrupar: menus[id_menu] → { meta, productos[tipo][categoria][] }
$menus = [];
foreach ($rows as $r) {
    $id = $r['id_menu'];
    if (!isset($menus[$id])) {
        $menus[$id] = [
            'fecha'  => $r['fecha'],
            'activo' => (int)$r['activo'],
            'prods'  => [],
        ];
    }
    if ($r['nombre'] !== null && $r['nombre'] !== '') {
        $tipo = $r['tipo_menu'];
        $cat  = $r['categoria'];
        $menus[$id]['prods'][$tipo][$cat][] = [
            'nombre'      => $r['nombre'],
            'descripcion' => $r['descripcion'],
        ];
    }
}

// Total de menús con contenido
$totalMenus      = count(array_filter($menus, fn($m) => !empty($m['prods'])));
$totalContenido  = array_filter($menus, fn($m) => !empty($m['prods']));

// ── Top platos históricos ─────────────────────────────────
$topRows = $conexion->query(
    "SELECT MIN(nombre) AS nombre, categoria, COUNT(*) AS veces
     FROM productos
     WHERE nombre IS NOT NULL AND TRIM(nombre) != ''
     GROUP BY LOWER(TRIM(nombre)), categoria
     ORDER BY veces DESC, MIN(nombre)
     LIMIT 40"
)->fetchAll(PDO::FETCH_ASSOC);

$topPorCategoria = [];
foreach ($topRows as $r) {
    $topPorCategoria[$r['categoria']][] = $r;
}

// ── Helpers ───────────────────────────────────────────────
function diaNombre(string $fecha): string {
    static $dias = ['Domingo','Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'];
    return $dias[(int)date('w', strtotime($fecha))];
}

function fmtFechaHistorico(string $fecha): string {
    static $meses = ['','ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];
    $t = strtotime($fecha);
    return date('j', $t) . ' ' . $meses[(int)date('n', $t)] . ' ' . date('Y', $t);
}

$hoy = date('Y-m-d');

// Orden de categorías para mostrar
$ordenCats = ['Plato fuerte', 'Sopa', 'Complemento', 'Postre', 'Agua'];
// Cada categoría con su clase de color (en menus-pizarron.css)
$catClase = [
    'Plato fuerte' => 'hm-c--plato',
    'Sopa'         => 'hm-c--sopa',
    'Complemento'  => 'hm-c--comp',
    'Postre'       => 'hm-c--postre',
    'Agua'         => 'hm-c--agua',
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historial de menús | Restaurante Zabisu</title>
    <link rel="icon" type="image/png" href="../assets/img/LOGO_NARA.png">
    <meta name="theme-color" content="#151413">
    <link rel="preload" href="../assets/fonts/kaushan-script-400.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="../assets/fonts/alfa-slab-one-400.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="../assets/css/menu-pizarron.css?v=5">
    <link rel="stylesheet" href="../assets/css/menus-pizarron.css?v=2">
</head>
<body class="pz">
<?php require __DIR__ . "/../includes/pz_filtros.php"; ?>

<main class="mz">
    <header class="mz-cab">
        <div class="pz-marca">
            <img src="../assets/img/LOGO_N_128.png" alt="">
            <div>
                <span class="pz-marca__nombre">Zabisu</span>
                <span class="pz-marca__lema">Sabor y Servicio</span>
            </div>
        </div>
        <h1 class="pz-titulo"><span class="pz-pincel"><span>Historial de menús</span></span></h1>

        <nav class="mz-cab__acc" aria-label="Acciones">
            <a href="nuevo_menu.php" class="mz-btn--naranja">+ Nuevo menú</a>
            <a href="menus.php" class="btn-volver-panel">Gestión de menús</a>
            <a href="panel_general.php" class="btn-volver-panel">← Panel</a>
        </nav>
    </header>
    <p class="mz-sub">Todos los menús del historial para inspirar los próximos</p>

    <!-- Stats rápidas -->
    <div class="hm-stats">
        <div class="hm-stat"><strong><?php echo count($menus); ?></strong> menús en historial</div>
        <div class="hm-stat"><strong><?php echo $totalMenus; ?></strong> con productos</div>
        <div class="hm-stat"><strong><?php echo count($topRows); ?></strong> platillos únicos</div>
    </div>

    <!-- Búsqueda -->
    <div class="hm-search-wrap">
        <svg class="hm-search-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
        <input type="text" class="hm-search" id="hm-search"
               placeholder="Buscar platillo, sopa, complemento…"
               autocomplete="off">
        <span class="hm-search-count" id="hm-count"></span>
    </div>

    <!-- Top platos históricos (colapsable) -->
    <?php if (!empty($topPorCategoria)): ?>
    <div class="hm-top">
        <div class="hm-top__header" onclick="toggleTop(this)">
            <h2 class="hm-top__titulo">Platillos más repetidos</h2>
            <span class="hm-top__chevron" id="top-chevron">&#x25BC;</span>
        </div>
        <div class="hm-top__body" id="top-body">
            <div class="hm-top-cats">
                <?php foreach ($ordenCats as $cat):
                    if (empty($topPorCategoria[$cat])) continue;
                ?>
                <div>
                    <div class="hm-top-cat__label"><?php echo htmlspecialchars($cat); ?></div>
                    <div class="hm-top-pills">
                        <?php foreach ($topPorCategoria[$cat] as $plato): ?>
                        <button type="button" class="hm-top-pill <?php echo $catClase[$cat] ?? ''; ?>"
                                onclick="buscarPlato('<?php echo htmlspecialchars(addslashes($plato['nombre']), ENT_QUOTES); ?>')">
                            <?php echo htmlspecialchars($plato['nombre']); ?>
                            <?php if ((int)$plato['veces'] > 1): ?>
                                <span class="hm-top-pill__count"><?php echo (int)$plato['veces']; ?>x</span>
                            <?php endif; ?>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Grid de menús -->
    <div class="hm-grid" id="hm-grid">

        <?php foreach ($menus as $id => $menu):
            $esActivo = (int)$menu['activo'] === 1;
            $esPasado = $menu['fecha'] < $hoy;
            $tieneProds = !empty($menu['prods']);

            // Unificar complementos, postre y agua (pueden ser iguales en Zabisu/Ejecutivo)
            $sharedCats  = ['Complemento', 'Postre', 'Agua'];
            $mainCats    = ['Plato fuerte', 'Sopa'];

            // Recopilar platos únicos para atributo data-search
            $searchText = strtolower($menu['fecha']);
            foreach ($menu['prods'] as $tipo => $cats) {
                foreach ($cats as $cat => $items) {
                    foreach ($items as $item) {
                        $searchText .= ' ' . strtolower($item['nombre']);
                    }
                }
            }
        ?>
        <div class="hm-card <?php echo $esActivo ? 'hm-card--activo' : ''; ?> <?php echo !$tieneProds ? 'hm-card--vacia' : ''; ?>"
             data-search="<?php echo htmlspecialchars($searchText); ?>">

            <!-- Header -->
            <div class="hm-card__header">
                <div class="hm-card__fecha-wrap">
                    <span class="hm-card__dia"><?php echo diaNombre($menu['fecha']); ?></span>
                    <span class="hm-card__fecha"><?php echo fmtFechaHistorico($menu['fecha']); ?></span>
                </div>
                <span class="hm-card__badge <?php echo $esActivo ? 'hm-card__badge--activo' : 'hm-card__badge--pasado'; ?>">
                    <?php echo $esActivo ? 'Activo' : 'Pasado'; ?>
                </span>
            </div>

            <?php if (!$tieneProds): ?>
                <div class="hm-card__vacia-msg">Sin productos registrados</div>
            <?php else: ?>
            <div class="hm-card__body">

                <!-- Platos principales por tipo de menú -->
                <?php
                $tipos = array_keys($menu['prods']);
                $hayZabisu    = isset($menu['prods']['Zabisu']);
                $hayEjecutivo = isset($menu['prods']['Ejecutivo']);
                ?>
                <?php if ($hayZabisu || $hayEjecutivo): ?>
                <div class="hm-tipos">
                    <?php foreach (['Zabisu' => 'zabisu', 'Ejecutivo' => 'ejecutivo'] as $tipo => $tipoSlug):
                        if (!isset($menu['prods'][$tipo])) continue;
                    ?>
                    <div>
                        <div class="hm-tipo__label">
                            <span class="hm-tipo__dot hm-tipo__dot--<?php echo $tipoSlug; ?>"></span>
                            <?php echo htmlspecialchars($tipo); ?>
                        </div>
                        <?php foreach ($mainCats as $cat):
                            if (empty($menu['prods'][$tipo][$cat])) continue;
                        ?>
                        <div class="hm-cat-section">
                            <div class="hm-cat-label <?php echo $catClase[$cat] ?? ''; ?>">
                                <?php echo htmlspecialchars($cat); ?>
                            </div>
                            <div class="hm-items">
                                <?php foreach ($menu['prods'][$tipo][$cat] as $item): ?>
                                <div class="hm-item">
                                    <?php echo htmlspecialchars($item['nombre']); ?>
                                    <?php if (!empty($item['descripcion'])): ?>
                                    <div class="hm-item__desc"><?php echo htmlspecialchars($item['descripcion']); ?></div>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- Complementos, Postre, Agua (tomados del primero disponible) -->
                <?php
                $sharedData = [];
                foreach ($sharedCats as $cat) {
                    foreach (['Zabisu', 'Ejecutivo'] as $tipo) {
                        if (!empty($menu['prods'][$tipo][$cat])) {
                            // Unión única por nombre
                            foreach ($menu['prods'][$tipo][$cat] as $item) {
                                $key = strtolower(trim($item['nombre']));
                                $sharedData[$cat][$key] = $item['nombre'];
                            }
                        }
                    }
                }
                if (!empty($sharedData)):
                ?>
                <div class="hm-shared">
                    <?php foreach ($sharedCats as $cat):
                        if (empty($sharedData[$cat])) continue;
                    ?>
                    <div class="hm-shared-row">
                        <span class="hm-shared-label <?php echo $catClase[$cat] ?? ''; ?>">
                            <?php echo htmlspecialchars($cat); ?>
                        </span>
                        <?php foreach ($sharedData[$cat] as $nombre): ?>
                        <span class="hm-pill <?php echo $catClase[$cat] ?? ''; ?>">
                            <?php echo htmlspecialchars($nombre); ?>
                        </span>
                        <?php endforeach; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

            </div>
            <?php endif; ?>

        </div>
        <?php endforeach; ?>

        <p class="hm-sin-resultados" id="hm-sin-resultados">
            No se encontraron menús con ese platillo.
        </p>
    </div>

</main>

<script>
// ── Búsqueda en tiempo real ───────────────────────────────
var cards = document.querySelectorAll('.hm-card');
var sinRes = document.getElementById('hm-sin-resultados');
var countEl = document.getElementById('hm-count');

document.getElementById('hm-search').addEventListener('input', function () {
    filtrar(this.value.trim().toLowerCase());
});

function filtrar(q) {
    var visibles = 0;
    cards.forEach(function (card) {
        var texto = card.dataset.search || '';
        var mostrar = q === '' || texto.includes(q);
        card.style.display = mostrar ? '' : 'none';
        if (mostrar) visibles++;
    });
    sinRes.style.display = visibles === 0 ? 'block' : 'none';
    countEl.textContent = q !== '' ? visibles + ' resultado' + (visibles !== 1 ? 's' : '') : '';
}

function buscarPlato(nombre) {
    var input = document.getElementById('hm-search');
    input.value = nombre;
    filtrar(nombre.toLowerCase());
    input.focus();
}

// ── Toggle top platos ─────────────────────────────────────
function toggleTop(header) {
    var body    = document.getElementById('top-body');
    var chevron = document.getElementById('top-chevron');
    var open    = body.classList.toggle('open');
    chevron.classList.toggle('open', open);
}
</script>
</body>
</html>
