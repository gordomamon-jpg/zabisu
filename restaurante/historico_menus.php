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
$catColors = [
    'Plato fuerte' => ['bg' => 'rgba(255,122,0,0.12)', 'color' => '#ff9a40', 'label' => 'Plato fuerte'],
    'Sopa'         => ['bg' => 'rgba(255,230,109,0.12)', 'color' => '#ffe66d', 'label' => 'Sopa'],
    'Complemento'  => ['bg' => 'rgba(78,205,196,0.12)', 'color' => '#4ecdc4', 'label' => 'Complemento'],
    'Postre'       => ['bg' => 'rgba(255,107,157,0.12)', 'color' => '#ff6b9d', 'label' => 'Postre'],
    'Agua'         => ['bg' => 'rgba(162,155,254,0.12)', 'color' => '#a29bfe', 'label' => 'Agua'],
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historial de menús | Restaurante Zabisu</title>
    <link rel="icon" type="image/png" href="../assets/img/LOGO_NARA.png">
    <link rel="stylesheet" href="../assets/css/styles.css?v=5">
<style>
/* ── Search ──────────────────────────────────────────────── */
.hm-search-wrap {
    position: relative;
    margin-bottom: 28px;
}
.hm-search {
    width: 100%;
    background: rgba(255,255,255,0.05);
    border: 1px solid rgba(255,255,255,0.12);
    border-radius: 10px;
    color: var(--zabisu-white);
    font-size: 16px;
    font-family: inherit;
    padding: 13px 16px 13px 44px;
    transition: border-color .15s;
    box-sizing: border-box;
}
.hm-search:focus { outline: none; border-color: var(--zabisu-orange); }
.hm-search::placeholder { color: var(--zabisu-gray); }
.hm-search-icon {
    position: absolute;
    left: 14px; top: 50%;
    transform: translateY(-50%);
    color: var(--zabisu-gray);
    font-size: 18px;
    pointer-events: none;
}
.hm-search-count {
    position: absolute;
    right: 14px; top: 50%;
    transform: translateY(-50%);
    font-size: 12px;
    color: var(--zabisu-gray);
}

/* ── Top platos ──────────────────────────────────────────── */
.hm-top {
    margin-bottom: 28px;
}
.hm-top__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    cursor: pointer;
    user-select: none;
    padding: 16px 20px;
    background: rgba(255,255,255,0.03);
    border: 1px solid rgba(255,255,255,0.07);
    border-radius: 12px;
    transition: background .15s;
}
.hm-top__header:hover { background: rgba(255,255,255,0.05); }
.hm-top__titulo {
    font-size: 14px;
    font-weight: 700;
    letter-spacing: .5px;
    color: var(--zabisu-white);
    margin: 0;
}
.hm-top__chevron {
    color: var(--zabisu-gray);
    font-size: 13px;
    transition: transform .2s;
}
.hm-top__chevron.open { transform: rotate(180deg); }
.hm-top__body {
    display: none;
    padding: 16px 0 0;
}
.hm-top__body.open { display: block; }
.hm-top-cats {
    display: flex;
    flex-direction: column;
    gap: 14px;
}
.hm-top-cat__label {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 1.2px;
    text-transform: uppercase;
    color: var(--zabisu-gray);
    margin-bottom: 8px;
}
.hm-top-pills {
    display: flex;
    flex-wrap: wrap;
    gap: 7px;
}
.hm-top-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    border-radius: 20px;
    padding: 5px 12px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: opacity .15s, transform .1s;
    border: none;
    font-family: inherit;
}
.hm-top-pill:hover { opacity: .8; transform: scale(1.03); }
.hm-top-pill__count {
    font-size: 10px;
    font-weight: 800;
    opacity: .7;
}

/* ── Grid de menús ───────────────────────────────────────── */
.hm-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 18px;
}
@media (max-width: 720px) { .hm-grid { grid-template-columns: 1fr; } }

.hm-card {
    background: rgba(255,255,255,0.03);
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 14px;
    overflow: hidden;
    transition: border-color .15s;
}
.hm-card:hover { border-color: rgba(255,255,255,0.14); }
.hm-card--activo { border-color: rgba(255,122,0,0.35); }

.hm-card__header {
    padding: 14px 18px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-bottom: 1px solid rgba(255,255,255,0.06);
}
.hm-card__fecha-wrap { display: flex; flex-direction: column; gap: 2px; }
.hm-card__dia {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 1.2px;
    text-transform: uppercase;
    color: var(--zabisu-orange);
}
.hm-card__fecha {
    font-size: 16px;
    font-weight: 700;
    color: var(--zabisu-white);
}
.hm-card__badge {
    font-size: 10px;
    font-weight: 700;
    letter-spacing: .8px;
    text-transform: uppercase;
    padding: 4px 10px;
    border-radius: 20px;
}
.hm-card__badge--activo { background: rgba(255,122,0,0.15); color: var(--zabisu-orange); }
.hm-card__badge--pasado { background: rgba(255,255,255,0.06); color: var(--zabisu-gray); }

.hm-card__body {
    padding: 16px 18px;
    display: flex;
    flex-direction: column;
    gap: 16px;
}

/* ── Tipos (Zabisu / Ejecutivo) ──────────────────────────── */
.hm-tipos {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
}
@media (max-width: 480px) { .hm-tipos { grid-template-columns: 1fr; } }

.hm-tipo__label {
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 1.2px;
    text-transform: uppercase;
    color: var(--zabisu-gray);
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.hm-tipo__dot {
    width: 6px; height: 6px;
    border-radius: 50%;
    display: inline-block;
}
.hm-tipo__dot--zabisu { background: var(--zabisu-orange); }
.hm-tipo__dot--ejecutivo { background: #a29bfe; }

/* ── Items por categoría ─────────────────────────────────── */
.hm-cat-section { margin-bottom: 10px; }
.hm-cat-section:last-child { margin-bottom: 0; }
.hm-cat-label {
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 1px;
    text-transform: uppercase;
    margin-bottom: 5px;
}
.hm-items {
    display: flex;
    flex-direction: column;
    gap: 3px;
}
.hm-item {
    font-size: 13px;
    color: var(--zabisu-white);
    padding: 3px 0;
    border-bottom: 1px solid rgba(255,255,255,0.04);
}
.hm-item:last-child { border-bottom: none; }
.hm-item__desc {
    font-size: 11px;
    color: var(--zabisu-gray);
    margin-top: 1px;
}

/* ── Shared cats (complemento, postre, agua) ─────────────── */
.hm-shared {
    border-top: 1px solid rgba(255,255,255,0.06);
    padding-top: 14px;
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.hm-shared-row { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
.hm-shared-label {
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 1px;
    text-transform: uppercase;
    min-width: 90px;
}
.hm-pill {
    font-size: 12px;
    font-weight: 500;
    padding: 3px 10px;
    border-radius: 20px;
}

/* ── Card vacía ──────────────────────────────────────────── */
.hm-card--vacia { opacity: .4; }
.hm-card__vacia-msg {
    padding: 20px 18px;
    color: var(--zabisu-gray);
    font-size: 13px;
    text-align: center;
}

/* ── Sin resultados ──────────────────────────────────────── */
.hm-sin-resultados {
    display: none;
    text-align: center;
    padding: 40px 20px;
    color: var(--zabisu-gray);
    font-size: 15px;
    grid-column: 1 / -1;
}

/* ── Stats header ────────────────────────────────────────── */
.hm-stats {
    display: flex;
    gap: 20px;
    margin-bottom: 24px;
    flex-wrap: wrap;
}
.hm-stat {
    font-size: 13px;
    color: var(--zabisu-gray);
}
.hm-stat strong { color: var(--zabisu-white); }
</style>
</head>
<body>
<div class="contenedor">

    <div class="hero-zabisu">
        <div class="hero-zabisu__glow"></div>
        <div class="hero-zabisu__contenido">
            <p class="hero-zabisu__eyebrow">RESTAURANTE</p>
            <div class="hero-zabisu__marca-grupo">
                <img class="hero-zabisu__logo" src="../assets/img/LOGO_BLANCO.png" alt="Zabisu">
                <h1 class="hero-zabisu__titulo">Historial de menús</h1>
            </div>
            <p class="hero-zabisu__texto">Todos los menús del historial para inspirar los próximos</p>
            <div style="margin-top:10px; display:flex; gap:10px; flex-wrap:wrap;">
                <a href="panel_general.php" class="btn-volver-panel">← Panel</a>
                <a href="menus.php" class="btn-volver-panel">Gestión de menús</a>
                <a href="nuevo_menu.php" class="btn-volver-panel">+ Nuevo menú</a>
            </div>
        </div>
    </div>

    <!-- Stats rápidas -->
    <div class="hm-stats">
        <div class="hm-stat"><strong><?php echo count($menus); ?></strong> menús en historial</div>
        <div class="hm-stat"><strong><?php echo $totalMenus; ?></strong> con productos</div>
        <div class="hm-stat"><strong><?php echo count($topRows); ?></strong> platillos únicos</div>
    </div>

    <!-- Búsqueda -->
    <div class="hm-search-wrap">
        <span class="hm-search-icon">&#x1F50D;</span>
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
                    $cc = $catColors[$cat] ?? ['bg' => 'rgba(255,255,255,0.08)', 'color' => '#fff'];
                ?>
                <div>
                    <div class="hm-top-cat__label"><?php echo htmlspecialchars($cat); ?></div>
                    <div class="hm-top-pills">
                        <?php foreach ($topPorCategoria[$cat] as $plato): ?>
                        <button class="hm-top-pill"
                                style="background:<?php echo $cc['bg']; ?>;color:<?php echo $cc['color']; ?>;"
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
                        $cc = $catColors;
                    ?>
                    <div>
                        <div class="hm-tipo__label">
                            <span class="hm-tipo__dot hm-tipo__dot--<?php echo $tipoSlug; ?>"></span>
                            <?php echo htmlspecialchars($tipo); ?>
                        </div>
                        <?php foreach ($mainCats as $cat):
                            if (empty($menu['prods'][$tipo][$cat])) continue;
                            $c = $catColors[$cat] ?? ['color' => '#fff'];
                        ?>
                        <div class="hm-cat-section">
                            <div class="hm-cat-label" style="color:<?php echo $c['color']; ?>">
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
                        $c = $catColors[$cat] ?? ['bg' => 'rgba(255,255,255,0.06)', 'color' => '#ccc'];
                    ?>
                    <div class="hm-shared-row">
                        <span class="hm-shared-label" style="color:<?php echo $c['color']; ?>">
                            <?php echo htmlspecialchars($cat); ?>
                        </span>
                        <?php foreach ($sharedData[$cat] as $nombre): ?>
                        <span class="hm-pill"
                              style="background:<?php echo $c['bg']; ?>;color:<?php echo $c['color']; ?>;">
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

</div>

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
