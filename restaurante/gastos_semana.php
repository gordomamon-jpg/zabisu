<?php
require_once "../config/db.php";
require_once "auth_check.php";
require_once "../includes/gastos_frecuentes.php";

/*
    Gastos de la semana: captura rápida desde el celular.
    En lugar de un formulario por gasto (concepto, categoría, fecha,
    semana, notas), aquí se anota toda la semana de una sola vez:
    "Lo de siempre" sale de lo que más se ha capturado antes y solo se
    pone el monto; lo demás va en "Otro gasto". La categoría y la fecha
    se ponen solas. Escribe en la misma tabla `gastos` que finanzas.php.

    ?semana=AAAA-MM-DD para otra semana (cualquier día; se toma su lunes).
*/

$categorias = ['Ingredientes', 'Empaque y desechables', 'Gas / Transporte', 'Servicios', 'Nómina', 'Varios'];

function getLunes(string $date): string {
    $ts  = strtotime($date);
    $dow = (int)date('N', $ts);
    return date('Y-m-d', strtotime('-' . ($dow - 1) . ' days', $ts));
}

function formatSemana(string $lunes): string {
    static $meses = ['ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];
    $lts = strtotime($lunes);
    $vts = strtotime($lunes . ' +4 days');
    $str = date('j', $lts);
    if (date('m', $lts) !== date('m', $vts)) $str .= ' ' . $meses[(int)date('n', $lts) - 1];
    return $str . ' al ' . date('j', $vts) . ' ' . $meses[(int)date('n', $vts) - 1];
}

function pesos(float $n): string {
    return '$' . number_format($n, $n == floor($n) ? 0 : 2, '.', ',');
}

/* "138+906+54", "$1,400" o "350" → total; null si no es válido */
function leerMonto(string $txt): ?float {
    $txt = str_replace(['$', ',', ' '], '', $txt);
    if ($txt === '') return null;
    $total = 0.0;
    foreach (explode('+', $txt) as $parte) {
        if (!is_numeric($parte) || (float)$parte <= 0) return null;
        $total += (float)$parte;
    }
    return round($total, 2);
}

/* Categoría por palabras, para conceptos que nunca se han capturado */
function categoriaPorPalabras(string $clave): string {
    $reglas = [
        'Nómina'                => '/sueldo|nomina|salario|raya|aguinaldo/',
        'Gas / Transporte'      => '/gasolina|^gas$|gaslp|tanque|uber|didi|taxi|transporte|envio|pasaje/',
        'Empaque y desechables' => '/desechable|bolsa|cofia|empaque|contenedor|unicel|servilleta|cubierto|guante|charola|vaso/',
        'Servicios'             => '/^luz$|cfe|internet|renta|telefono|celular|recarga|basura|predial|agua potable|garrafon/',
    ];
    foreach ($reglas as $cat => $re) {
        if (preg_match($re, $clave)) return $cat;
    }
    return 'Ingredientes';
}

// ── Semana ────────────────────────────────────────────────
$hoy = date('Y-m-d');
if (isset($_GET['semana']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['semana']) && strtotime($_GET['semana'])) {
    $lunes = getLunes($_GET['semana']);
} else {
    // Entre semana es esta semana; el fin de semana ya se compra para la siguiente
    $lunes = (int)date('N') >= 6 ? date('Y-m-d', strtotime('next monday')) : getLunes($hoy);
}
$domingo   = date('Y-m-d', strtotime($lunes . ' +6 days'));
$anterior  = date('Y-m-d', strtotime($lunes . ' -7 days'));
$siguiente = date('Y-m-d', strtotime($lunes . ' +7 days'));
$esEstaSemana = $lunes === getLunes($hoy);

// Fecha de compra: hoy, salvo que la semana ya haya pasado (entonces su viernes)
$fechaCompra = $hoy > $domingo ? date('Y-m-d', strtotime($lunes . ' +4 days')) : $hoy;

// ── Lo de siempre ─────────────────────────────────────────
[$conceptos, $compras, $sueldos] = gastosFrecuentes($conexion);

// ── POST: guardar todo lo anotado ─────────────────────────
$error   = '';
$enviado = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['g']) && is_array($_POST['g'])) {
    $porGuardar = [];
    $malos      = [];
    foreach ($_POST['g'] as $i => $fila) {
        if (!is_array($fila)) continue;
        $concepto = mb_substr(trim((string)($fila['concepto'] ?? '')), 0, 255);
        $montoTxt = trim((string)($fila['monto'] ?? ''));
        $enviado[$i] = ['concepto' => $concepto, 'monto' => $montoTxt];
        if ($montoTxt === '' && $concepto === '') continue;
        if ($montoTxt === '') continue; // fila de "lo de siempre" sin monto: no se compró

        $monto = leerMonto($montoTxt);
        if ($monto === null || $concepto === '') { $malos[] = $concepto !== '' ? $concepto : 'un gasto sin nombre'; continue; }

        $k   = claveConcepto($concepto);
        $cat = $conceptos[$k]['categoria'] ?? categoriaPorPalabras($k);
        if (!in_array($cat, $categorias, true)) $cat = 'Ingredientes';

        $porGuardar[] = [
            ':fc'    => $fechaCompra,
            ':sd'    => $lunes,
            ':con'   => $concepto,
            ':cat'   => $cat,
            ':monto' => $monto,
            ':notas' => str_contains($montoTxt, '+') ? 'Tickets: ' . str_replace('+', ' + ', str_replace(' ', '', $montoTxt)) : null,
        ];
    }

    if ($malos) {
        $error = 'Revisa el monto de: ' . implode(', ', array_unique($malos)) . '.';
    } elseif (!$porGuardar) {
        $error = 'No anotaste ningún monto.';
    } else {
        $stmt = $conexion->prepare(
            "INSERT INTO gastos (fecha_compra, semana_destino, concepto, categoria, monto, notas)
             VALUES (:fc, :sd, :con, :cat, :monto, :notas)"
        );
        $conexion->beginTransaction();
        foreach ($porGuardar as $p) $stmt->execute($p);
        $conexion->commit();
        header('Location: gastos_semana.php?semana=' . $lunes . '&ok=' . count($porGuardar));
        exit;
    }
}
$guardados = isset($_GET['ok']) ? max(0, (int)$_GET['ok']) : 0;

// ── Lo que ya está anotado en la semana ───────────────────
$stmt = $conexion->prepare(
    "SELECT id_gasto, concepto, categoria, monto, notas FROM gastos
     WHERE semana_destino = :sd ORDER BY id_gasto DESC"
);
$stmt->execute([':sd' => $lunes]);
$anotados     = $stmt->fetchAll(PDO::FETCH_ASSOC);
$totalGastado = array_sum(array_map(fn($g) => (float)$g['monto'], $anotados));

// Ventas de la semana, con el día del menú pedido (igual que finanzas.php)
$stmt = $conexion->prepare(
    "SELECT COALESCE(SUM(p.total), 0)
     FROM pedidos p
     LEFT JOIN (SELECT pm2.id_pedido, MIN(md2.fecha) AS fecha_menu
                FROM pedido_menus pm2
                INNER JOIN detalle_pedido dp2 ON dp2.id_pedido_menu = pm2.id_pedido_menu
                INNER JOIN productos pr2 ON pr2.id_producto = dp2.id_producto
                INNER JOIN menu_dia md2 ON md2.id_menu = pr2.id_menu
                GROUP BY pm2.id_pedido) AS mi ON mi.id_pedido = p.id_pedido
     WHERE p.estado != 'Cancelado' AND p.es_prueba = 0
       AND COALESCE(mi.fecha_menu, DATE(p.fecha_pedido)) BETWEEN :l AND :d"
);
$stmt->execute([':l' => $lunes, ':d' => $domingo]);
$vendido = (float)$stmt->fetchColumn();
$quedo   = $vendido - $totalGastado;

/* Una fila de "lo de siempre": nombre fijo y solo el monto */
$n = 0;
function filaFija(int $i, array $c, array $enviado): void {
    $monto = $enviado[$i]['monto'] ?? '';
    ?>
    <li class="pz-fila gs-fila">
        <span class="pz-tira pz-fibra" aria-hidden="true"></span>
        <label class="pz-fila__txt" for="g<?php echo $i; ?>">
            <span class="pz-fila__nombre"><?php echo htmlspecialchars($c['nombre']); ?></span>
            <?php if ($c['ultMonto'] > 0): ?>
                <button type="button" class="gs-ultima" data-llenar="g<?php echo $i; ?>" data-monto="<?php echo rtrim(rtrim(number_format($c['ultMonto'], 2, '.', ''), '0'), '.'); ?>">
                    La última vez <?php echo pesos($c['ultMonto']); ?>
                </button>
            <?php endif; ?>
        </label>
        <input type="hidden" name="g[<?php echo $i; ?>][concepto]" value="<?php echo htmlspecialchars($c['nombre']); ?>">
        <span class="gs-monto">
            <span aria-hidden="true">$</span>
            <input id="g<?php echo $i; ?>" name="g[<?php echo $i; ?>][monto]" value="<?php echo htmlspecialchars($monto); ?>"
                   inputmode="decimal" autocomplete="off" placeholder="0" aria-label="Monto de <?php echo htmlspecialchars($c['nombre']); ?>">
        </span>
    </li>
    <?php
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Gastos de la semana | Zabisu</title>
    <link rel="icon" type="image/png" href="../assets/img/LOGO_NARA.png">
    <meta name="theme-color" content="#151413">
    <link rel="preload" href="../assets/fonts/kaushan-script-400.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="../assets/fonts/alfa-slab-one-400.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="../assets/css/menu-pizarron.css?v=2">
    <link rel="stylesheet" href="../assets/css/resumen-pizarron.css?v=1">
    <link rel="stylesheet" href="../assets/css/gastos-pizarron.css?v=2">
</head>
<body class="pz rp gs">
<?php require __DIR__ . "/../includes/pz_filtros.php"; ?>

<main class="pz-pagina rp-pagina gs-pagina">

    <header class="pz-cab">
        <a class="pz-marca" href="panel_general.php" aria-label="Zabisu, volver al panel general">
            <img src="../assets/img/LOGO_N_128.png" alt="">
            <div>
                <span class="pz-marca__nombre">Zabisu</span>
                <span class="pz-marca__lema">Sabor y Servicio</span>
            </div>
        </a>
        <nav class="rp-dias" aria-label="Cambiar de semana">
            <a href="?semana=<?php echo $anterior; ?>" aria-label="Semana anterior">
                <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M15 5l-7 7 7 7" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </a>
            <a href="?semana=<?php echo $siguiente; ?>" aria-label="Semana siguiente">
                <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M9 5l7 7-7 7" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </a>
        </nav>
    </header>

    <p class="rp-eyebrow">Gastos de la semana<?php echo $esEstaSemana ? ' (esta semana)' : ''; ?></p>
    <h1 class="pz-titulo rp-titulo"><span class="pz-pincel"><span><?php echo htmlspecialchars(formatSemana($lunes)); ?></span></span></h1>
    <a class="gs-hoja" href="hoja_gastos.php?semana=<?php echo $lunes; ?>">
        <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M7 9V3h10v6M7 17H4v-7h16v7h-3M7 14h10v7H7z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
        Imprimir hoja para llenar a mano
    </a>

    <?php if ($guardados): ?>
        <p class="gs-aviso gs-aviso--ok" role="status">Listo, se anotaron <?php echo $guardados; ?> gasto<?php echo $guardados !== 1 ? 's' : ''; ?>.</p>
    <?php endif; ?>
    <?php if ($error): ?>
        <p class="gs-aviso gs-aviso--error" role="alert"><?php echo htmlspecialchars($error); ?></p>
    <?php endif; ?>

    <!-- Cómo va la semana -->
    <section class="pz-boleto rp-comanda gs-cuenta" aria-label="Cómo va la semana">
        <div class="pz-boleto__papel">
            <span class="pz-boleto__ojo" aria-hidden="true"></span>
            <dl class="rp-comanda__lineas">
                <div><dt>Vendido</dt><dd><?php echo pesos($vendido); ?></dd></div>
                <div><dt>Gastado</dt><dd><?php echo pesos($totalGastado); ?></dd></div>
            </dl>
            <hr class="pz-boleto__corte">
            <div class="gs-quedo">
                <span><?php echo $quedo >= 0 ? 'Quedó' : 'Faltó'; ?></span>
                <b><?php echo pesos(abs($quedo)); ?></b>
            </div>
        </div>
    </section>

    <form method="POST" action="gastos_semana.php?semana=<?php echo $lunes; ?>" id="gs-form" novalidate>

        <section class="pz-sec">
            <h2 class="pz-cinta pz-fibra">Lo de siempre</h2>
            <p class="gs-ayuda">Pon solo lo que gastaste. Si fueron varios tickets, súmalos con +, por ejemplo 138+906.</p>
            <ul class="pz-grupo">
                <?php foreach ($compras as $c) filaFija($n++, $c, $enviado); ?>
            </ul>
        </section>

        <?php if ($sueldos): ?>
        <section class="pz-sec">
            <h2 class="pz-cinta pz-fibra">Sueldos</h2>
            <ul class="pz-grupo">
                <?php foreach ($sueldos as $c) filaFija($n++, $c, $enviado); ?>
            </ul>
        </section>
        <?php endif; ?>

        <section class="pz-sec">
            <h2 class="pz-cinta pz-fibra">Otro gasto</h2>
            <ul class="pz-grupo" id="gs-otros">
                <?php
                // Los que se mandaron con error se vuelven a mostrar; si no, uno vacío
                $otros = array_filter($enviado, fn($e, $i) => $i >= $n && ($e['concepto'] !== '' || $e['monto'] !== ''), ARRAY_FILTER_USE_BOTH);
                if (!$otros) $otros = [$n => ['concepto' => '', 'monto' => '']];
                foreach ($otros as $o):
                    $i = $n++;
                ?>
                <li class="pz-fila gs-fila gs-fila--otro">
                    <span class="pz-tira pz-fibra" aria-hidden="true"></span>
                    <input class="gs-concepto" name="g[<?php echo $i; ?>][concepto]" value="<?php echo htmlspecialchars($o['concepto']); ?>"
                           placeholder="¿Qué fue?" maxlength="255" autocomplete="off" aria-label="Qué fue el gasto">
                    <span class="gs-monto">
                        <span aria-hidden="true">$</span>
                        <input name="g[<?php echo $i; ?>][monto]" value="<?php echo htmlspecialchars($o['monto']); ?>"
                               inputmode="decimal" autocomplete="off" placeholder="0" aria-label="Monto">
                    </span>
                </li>
                <?php endforeach; ?>
            </ul>
            <button type="button" class="gs-agregar" id="gs-agregar">
                <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M12 5v14M5 12h14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg>
                Agregar otro
            </button>
        </section>

        <div class="pz-barra">
            <button type="submit" class="pz-btn gs-guardar" id="gs-guardar" disabled>
                <span>Guardar gastos</span>
                <span id="gs-total">$0</span>
            </button>
        </div>
    </form>

    <section class="pz-sec gs-anotados">
        <h2 class="pz-cinta pz-fibra">Ya anotado</h2>
        <?php if (!$anotados): ?>
            <p class="gs-ayuda">Todavía no hay gastos en esta semana.</p>
        <?php else: ?>
            <ul class="pz-grupo">
                <?php foreach ($anotados as $g): ?>
                <li class="pz-fila gs-fila">
                    <div class="pz-fila__txt">
                        <p class="pz-fila__nombre"><?php echo htmlspecialchars($g['concepto']); ?></p>
                        <p class="pz-fila__desc"><?php echo htmlspecialchars($g['categoria']); ?><?php echo $g['notas'] ? ', ' . htmlspecialchars($g['notas']) : ''; ?></p>
                    </div>
                    <span class="pz-fila__extra"><?php echo pesos((float)$g['monto']); ?></span>
                    <button type="button" class="gs-quitar" data-id="<?php echo (int)$g['id_gasto']; ?>"
                            aria-label="Quitar <?php echo htmlspecialchars($g['concepto']); ?>">
                        <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                    </button>
                </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <p class="pz-pie"><a href="finanzas.php?tab=gastos&semana=<?php echo $lunes; ?>">Ver en Finanzas</a></p>
    </section>

</main>

<template id="gs-fila-otro">
    <li class="pz-fila gs-fila gs-fila--otro">
        <span class="pz-tira pz-fibra" aria-hidden="true"></span>
        <input class="gs-concepto" name="g[N][concepto]" placeholder="¿Qué fue?" maxlength="255" autocomplete="off" aria-label="Qué fue el gasto">
        <span class="gs-monto">
            <span aria-hidden="true">$</span>
            <input name="g[N][monto]" inputmode="decimal" autocomplete="off" placeholder="0" aria-label="Monto">
        </span>
    </li>
</template>

<script>
(function () {
    const form    = document.getElementById('gs-form');
    const totalEl = document.getElementById('gs-total');
    const btn     = document.getElementById('gs-guardar');
    let siguiente = <?php echo $n; ?>;

    // Mismo criterio que leerMonto() en PHP
    function leer(txt) {
        txt = txt.replace(/[$,\s]/g, '');
        if (!txt) return 0;
        let t = 0;
        for (const p of txt.split('+')) {
            const v = Number(p);
            if (!p || isNaN(v) || v <= 0) return NaN;
            t += v;
        }
        return t;
    }

    function recalcular() {
        let total = 0, malos = 0;
        form.querySelectorAll('input[name$="[monto]"]').forEach(inp => {
            const v = leer(inp.value);
            inp.closest('.gs-fila').classList.toggle('gs-fila--mal', isNaN(v));
            inp.closest('.gs-fila').classList.toggle('gs-fila--lleno', v > 0);
            if (isNaN(v)) malos++; else total += v;
        });
        totalEl.textContent = '$' + total.toLocaleString('es-MX', { maximumFractionDigits: 2 });
        btn.disabled = total <= 0 || malos > 0;
    }

    form.addEventListener('input', recalcular);

    form.addEventListener('click', e => {
        const b = e.target.closest('[data-llenar]');
        if (!b) return;
        e.preventDefault();
        const inp = document.getElementById(b.dataset.llenar);
        inp.value = b.dataset.monto;
        inp.focus();
        recalcular();
    });

    document.getElementById('gs-agregar').addEventListener('click', () => {
        const html = document.getElementById('gs-fila-otro').innerHTML.replace(/\[N\]/g, '[' + (siguiente++) + ']');
        document.getElementById('gs-otros').insertAdjacentHTML('beforeend', html);
        document.querySelector('#gs-otros li:last-child .gs-concepto').focus();
    });

    form.addEventListener('submit', e => {
        // Un monto sin nombre en "Otro gasto" no se puede guardar
        const sinNombre = [...document.querySelectorAll('.gs-fila--otro')].find(li =>
            li.querySelector('[name$="[monto]"]').value.trim() && !li.querySelector('.gs-concepto').value.trim());
        if (sinNombre) {
            e.preventDefault();
            alert('Ponle nombre al gasto de "Otro gasto".');
            sinNombre.querySelector('.gs-concepto').focus();
            return;
        }
        btn.disabled = true;
        btn.firstElementChild.textContent = 'Guardando…';
    });

    document.querySelectorAll('.gs-quitar').forEach(b => b.addEventListener('click', () => {
        if (!confirm('¿Quitar este gasto?')) return;
        b.disabled = true;
        fetch('eliminar_gasto.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'id_gasto=' + b.dataset.id,
        })
        .then(r => r.json())
        .then(d => { if (d.ok) location.href = 'gastos_semana.php?semana=<?php echo $lunes; ?>'; else throw 0; })
        .catch(() => { alert('No se pudo quitar. Intenta de nuevo.'); b.disabled = false; });
    }));

    recalcular();
})();
</script>
</body>
</html>
