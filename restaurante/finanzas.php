<?php
require_once "../config/db.php";
require_once "auth_check.php";

// ── Tablas ────────────────────────────────────────────────
$conexion->exec("CREATE TABLE IF NOT EXISTS gastos (
    id_gasto       INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    fecha_compra   DATE          NOT NULL,
    semana_destino DATE          NOT NULL COMMENT 'Lunes de la semana operativa',
    concepto       VARCHAR(255)  NOT NULL,
    categoria      VARCHAR(50)   NOT NULL DEFAULT 'Varios',
    monto          DECIMAL(10,2) NOT NULL,
    notas          TEXT,
    created_at     TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_gasto),
    KEY idx_semana (semana_destino)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conexion->exec("CREATE TABLE IF NOT EXISTS presupuesto_semana (
    semana_lunes DATE         PRIMARY KEY,
    presupuesto  DECIMAL(10,2) NOT NULL DEFAULT 0,
    notas        VARCHAR(255),
    updated_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── Helpers ───────────────────────────────────────────────
function getLunes(string $date): string {
    $ts  = strtotime($date);
    $dow = (int)date('N', $ts);
    return date('Y-m-d', strtotime('-' . ($dow - 1) . ' days', $ts));
}

function formatSemana(string $lunes): string {
    static $meses = [
        '01'=>'ene','02'=>'feb','03'=>'mar','04'=>'abr','05'=>'may','06'=>'jun',
        '07'=>'jul','08'=>'ago','09'=>'sep','10'=>'oct','11'=>'nov','12'=>'dic',
    ];
    $lts = strtotime($lunes);
    $vts = strtotime($lunes . ' +4 days');
    $str = date('j', $lts) . ' ' . ($meses[date('m', $lts)] ?? '');
    if (date('m', $lts) !== date('m', $vts)) {
        $str .= ' – ' . date('j', $vts) . ' ' . ($meses[date('m', $vts)] ?? '');
    } else {
        $str .= ' – ' . date('j', $vts);
    }
    return $str;
}

function fmtPeso(float $n): string {
    return '$' . number_format($n, 2, '.', ',');
}

function fmtFecha(string $fecha): string {
    $meses = ['ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];
    $t = strtotime($fecha);
    return date('j', $t) . ' ' . $meses[(int)date('n', $t) - 1] . ' ' . date('Y', $t);
}

// ── Datos base ────────────────────────────────────────────
$hoy        = date('Y-m-d');
$categorias = ['Ingredientes', 'Empaque y desechables', 'Gas / Transporte', 'Servicios', 'Nómina', 'Varios'];
$dowHoy     = (int)date('N');
$lunesActual = getLunes($hoy);

$lunesBase   = $lunesActual;
$weekOptions = [];
for ($i = -8; $i <= 4; $i++) {
    $weekOptions[] = $i === 0
        ? $lunesBase
        : date('Y-m-d', strtotime($lunesBase . ($i > 0 ? " +$i weeks" : "$i weeks")));
}
rsort($weekOptions);

// ── POST: guardar presupuesto semanal ─────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_presupuesto'])) {
    $semana_pres = trim($_POST['semana_presupuesto'] ?? '');
    $monto_pres  = trim($_POST['presupuesto_monto']  ?? '');
    $notas_pres  = trim($_POST['presupuesto_notas']  ?? '');

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $semana_pres) && is_numeric($monto_pres) && (float)$monto_pres >= 0) {
        $stmt = $conexion->prepare(
            "INSERT INTO presupuesto_semana (semana_lunes, presupuesto, notas) VALUES (:sl, :p, :n)
             ON DUPLICATE KEY UPDATE presupuesto = :p2, notas = :n2"
        );
        $stmt->execute([
            ':sl' => $semana_pres,
            ':p'  => (float)$monto_pres,
            ':n'  => $notas_pres ?: null,
            ':p2' => (float)$monto_pres,
            ':n2' => $notas_pres ?: null,
        ]);
    }
    header('Location: finanzas.php?ok=presupuesto#semana');
    exit;
}

// ── POST: guardar nuevo gasto ─────────────────────────────
$erroresGasto = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_gasto'])) {
    $concepto       = trim($_POST['concepto']       ?? '');
    $categoria      = trim($_POST['categoria']      ?? '');
    $monto_raw      = trim($_POST['monto']          ?? '');
    $fecha_compra   = trim($_POST['fecha_compra']   ?? '');
    $semana_destino = trim($_POST['semana_destino'] ?? '');
    $notas          = trim($_POST['notas']          ?? '');

    if ($concepto === '')                                   $erroresGasto[] = 'El concepto es obligatorio.';
    if (!in_array($categoria, $categorias, true))           $erroresGasto[] = 'Categoría inválida.';
    if (!is_numeric($monto_raw) || (float)$monto_raw <= 0) $erroresGasto[] = 'El monto debe ser un número positivo.';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_compra))   $erroresGasto[] = 'Fecha de compra inválida.';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $semana_destino)) $erroresGasto[] = 'Semana destino inválida.';

    if (empty($erroresGasto)) {
        $stmt = $conexion->prepare(
            "INSERT INTO gastos (fecha_compra, semana_destino, concepto, categoria, monto, notas)
             VALUES (:fc, :sd, :con, :cat, :monto, :notas)"
        );
        $stmt->execute([
            ':fc'    => $fecha_compra,
            ':sd'    => $semana_destino,
            ':con'   => $concepto,
            ':cat'   => $categoria,
            ':monto' => (float)$monto_raw,
            ':notas' => $notas !== '' ? $notas : null,
        ]);
        header('Location: finanzas.php?tab=gastos&ok=gasto');
        exit;
    }
}

// ── Presupuesto de la semana actual ───────────────────────
$stmtPres = $conexion->prepare("SELECT presupuesto, notas FROM presupuesto_semana WHERE semana_lunes = :sl");
$stmtPres->execute([':sl' => $lunesActual]);
$presupuestoActual = $stmtPres->fetch(PDO::FETCH_ASSOC);
$presupuestoMonto  = $presupuestoActual ? (float)$presupuestoActual['presupuesto'] : 0;

// ── Subquery reutilizable: fecha real del menú ────────────
$subFecha = "(SELECT pm2.id_pedido, MIN(md2.fecha) AS fecha_menu
              FROM pedido_menus pm2
              INNER JOIN detalle_pedido dp2 ON dp2.id_pedido_menu = pm2.id_pedido_menu
              INNER JOIN productos pr2 ON pr2.id_producto = dp2.id_producto
              INNER JOIN menu_dia md2 ON md2.id_menu = pr2.id_menu
              GROUP BY pm2.id_pedido) AS mi";

// ── P&L semanal (últimas 12 semanas) ─────────────────────
$rowsGastos = $conexion->query(
    "SELECT semana_destino, SUM(monto) AS total_gastos
     FROM gastos GROUP BY semana_destino
     ORDER BY semana_destino DESC LIMIT 12"
)->fetchAll(PDO::FETCH_ASSOC);

$gastosPorSemana = [];
foreach ($rowsGastos as $r) $gastosPorSemana[$r['semana_destino']] = (float)$r['total_gastos'];

$rowsIngresos = $conexion->query(
    "SELECT DATE_SUB(
                COALESCE(mi.fecha_menu, DATE(p.fecha_pedido)),
                INTERVAL WEEKDAY(COALESCE(mi.fecha_menu, DATE(p.fecha_pedido))) DAY
             ) AS semana_lunes,
            SUM(p.total) AS total_ingresos
     FROM pedidos p
     LEFT JOIN $subFecha ON mi.id_pedido = p.id_pedido
     WHERE p.estado != 'Cancelado' AND p.es_prueba = 0
       AND COALESCE(mi.fecha_menu, DATE(p.fecha_pedido)) >= DATE_SUB(CURDATE(), INTERVAL 84 DAY)
     GROUP BY semana_lunes ORDER BY semana_lunes DESC"
)->fetchAll(PDO::FETCH_ASSOC);

$ingresosPorSemana = [];
foreach ($rowsIngresos as $r) $ingresosPorSemana[$r['semana_lunes']] = (float)$r['total_ingresos'];

$rowsPresupuestos = $conexion->query(
    "SELECT semana_lunes, presupuesto FROM presupuesto_semana ORDER BY semana_lunes DESC LIMIT 12"
)->fetchAll(PDO::FETCH_ASSOC);

$presupuestosPorSemana = [];
foreach ($rowsPresupuestos as $r) $presupuestosPorSemana[$r['semana_lunes']] = (float)$r['presupuesto'];

$todasSemanas = array_unique(array_merge(
    array_keys($gastosPorSemana),
    array_keys($ingresosPorSemana),
    array_keys($presupuestosPorSemana)
));
rsort($todasSemanas);
$todasSemanas = array_slice($todasSemanas, 0, 12);

// KPIs de la semana actual
$ingresosActuales = $ingresosPorSemana[$lunesActual] ?? 0;
$gastosActuales   = $gastosPorSemana[$lunesActual]   ?? 0;
$gananciaActual   = $ingresosActuales - $gastosActuales;

// ── Estadísticas de ventas (filtro por fecha) ─────────────
$primerDia   = date('Y-m-01');
$fechaInicio = (isset($_GET['fi']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['fi'])) ? $_GET['fi'] : $primerDia;
$fechaFin    = (isset($_GET['ff']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['ff'])) ? $_GET['ff'] : $hoy;
if ($fechaInicio > $fechaFin) [$fechaInicio, $fechaFin] = [$fechaFin, $fechaInicio];

$stmtKpis = $conexion->prepare(
    "SELECT COUNT(*) AS total_pedidos,
            COALESCE(SUM(p.total), 0) AS total_ventas,
            COALESCE(SUM(CASE WHEN p.metodo_pago = 'Transferencia' THEN p.total ELSE 0 END), 0) AS total_transferencia,
            COALESCE(SUM(CASE WHEN p.metodo_pago = 'Efectivo'      THEN p.total ELSE 0 END), 0) AS total_efectivo,
            COALESCE(SUM(CASE WHEN p.estado_pago = 'Pagado'                   THEN p.total ELSE 0 END), 0) AS total_confirmado,
            COALESCE(SUM(CASE WHEN p.estado_pago = 'Pendiente de validación'  THEN p.total ELSE 0 END), 0) AS total_por_confirmar
     FROM pedidos p LEFT JOIN $subFecha ON mi.id_pedido = p.id_pedido
     WHERE COALESCE(mi.fecha_menu, DATE(p.fecha_pedido)) BETWEEN :fi AND :ff AND p.es_prueba = 0 AND p.estado != 'Cancelado'"
);
$stmtKpis->execute([':fi' => $fechaInicio, ':ff' => $fechaFin]);
$kpis = $stmtKpis->fetch(PDO::FETCH_ASSOC);

$stmtComidas = $conexion->prepare(
    "SELECT COUNT(*) FROM pedido_menus pm INNER JOIN pedidos p ON pm.id_pedido = p.id_pedido
     LEFT JOIN $subFecha ON mi.id_pedido = p.id_pedido
     WHERE COALESCE(mi.fecha_menu, DATE(p.fecha_pedido)) BETWEEN :fi AND :ff AND p.es_prueba = 0 AND p.estado != 'Cancelado'"
);
$stmtComidas->execute([':fi' => $fechaInicio, ':ff' => $fechaFin]);
$totalComidas = (int)$stmtComidas->fetchColumn();

$stmtPorDia = $conexion->prepare(
    "SELECT COALESCE(mi.fecha_menu, DATE(p.fecha_pedido)) AS fecha,
            COUNT(*) AS pedidos, COALESCE(SUM(p.total), 0) AS total
     FROM pedidos p LEFT JOIN $subFecha ON mi.id_pedido = p.id_pedido
     WHERE COALESCE(mi.fecha_menu, DATE(p.fecha_pedido)) BETWEEN :fi AND :ff AND p.es_prueba = 0 AND p.estado != 'Cancelado'
     GROUP BY COALESCE(mi.fecha_menu, DATE(p.fecha_pedido)) ORDER BY fecha ASC"
);
$stmtPorDia->execute([':fi' => $fechaInicio, ':ff' => $fechaFin]);
$ventasPorDia = $stmtPorDia->fetchAll(PDO::FETCH_ASSOC);

$stmtPorMetodo = $conexion->prepare(
    "SELECT p.metodo_pago, COUNT(*) AS pedidos, COALESCE(SUM(p.total), 0) AS total
     FROM pedidos p LEFT JOIN $subFecha ON mi.id_pedido = p.id_pedido
     WHERE COALESCE(mi.fecha_menu, DATE(p.fecha_pedido)) BETWEEN :fi AND :ff AND p.es_prueba = 0 AND p.estado != 'Cancelado'
     GROUP BY p.metodo_pago ORDER BY total DESC"
);
$stmtPorMetodo->execute([':fi' => $fechaInicio, ':ff' => $fechaFin]);
$ventasPorMetodo = $stmtPorMetodo->fetchAll(PDO::FETCH_ASSOC);

$stmtPorUbicacion = $conexion->prepare(
    "SELECT u.nombre_ubicacion, u.tipo, COUNT(*) AS pedidos, COALESCE(SUM(p.total), 0) AS total
     FROM pedidos p
     INNER JOIN horarios_ubicacion h ON p.id_horario = h.id_horario
     INNER JOIN ubicaciones u ON h.id_ubicacion = u.id_ubicacion
     LEFT JOIN $subFecha ON mi.id_pedido = p.id_pedido
     WHERE COALESCE(mi.fecha_menu, DATE(p.fecha_pedido)) BETWEEN :fi AND :ff AND p.es_prueba = 0 AND p.estado != 'Cancelado'
     GROUP BY u.id_ubicacion, u.nombre_ubicacion, u.tipo ORDER BY total DESC"
);
$stmtPorUbicacion->execute([':fi' => $fechaInicio, ':ff' => $fechaFin]);
$ventasPorUbicacion = $stmtPorUbicacion->fetchAll(PDO::FETCH_ASSOC);

$stmtTopPlatos = $conexion->prepare(
    "SELECT dp.nombre_producto, COUNT(*) AS total
     FROM detalle_pedido dp
     INNER JOIN pedido_menus pm ON dp.id_pedido_menu = pm.id_pedido_menu
     INNER JOIN pedidos p ON pm.id_pedido = p.id_pedido
     INNER JOIN productos pr ON pr.id_producto = dp.id_producto
     LEFT JOIN menu_dia md ON md.id_menu = pr.id_menu
     WHERE dp.categoria = 'Plato fuerte'
       AND COALESCE(md.fecha, DATE(p.fecha_pedido)) BETWEEN :fi AND :ff AND p.es_prueba = 0 AND p.estado != 'Cancelado'
     GROUP BY dp.nombre_producto ORDER BY total DESC LIMIT 8"
);
$stmtTopPlatos->execute([':fi' => $fechaInicio, ':ff' => $fechaFin]);
$topPlatos = $stmtTopPlatos->fetchAll(PDO::FETCH_ASSOC);

$stmtTopComplementos = $conexion->prepare(
    "SELECT dp.nombre_producto, COUNT(*) AS total
     FROM detalle_pedido dp
     INNER JOIN pedido_menus pm ON dp.id_pedido_menu = pm.id_pedido_menu
     INNER JOIN pedidos p ON pm.id_pedido = p.id_pedido
     INNER JOIN productos pr ON pr.id_producto = dp.id_producto
     LEFT JOIN menu_dia md ON md.id_menu = pr.id_menu
     WHERE dp.categoria = 'Complemento'
       AND COALESCE(md.fecha, DATE(p.fecha_pedido)) BETWEEN :fi AND :ff AND p.es_prueba = 0 AND p.estado != 'Cancelado'
     GROUP BY dp.nombre_producto ORDER BY total DESC LIMIT 8"
);
$stmtTopComplementos->execute([':fi' => $fechaInicio, ':ff' => $fechaFin]);
$topComplementos = $stmtTopComplementos->fetchAll(PDO::FETCH_ASSOC);

// ── Lista de gastos ───────────────────────────────────────
$filtroSemana = $_GET['semana'] ?? '';
if ($filtroSemana !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filtroSemana)) {
    $stmtLista = $conexion->prepare(
        "SELECT * FROM gastos WHERE semana_destino = :sd ORDER BY fecha_compra DESC, id_gasto DESC"
    );
    $stmtLista->execute([':sd' => $filtroSemana]);
} else {
    $filtroSemana = '';
    $stmtLista    = $conexion->query(
        "SELECT * FROM gastos ORDER BY fecha_compra DESC, id_gasto DESC LIMIT 60"
    );
}
$listaGastos = $stmtLista->fetchAll(PDO::FETCH_ASSOC);

$activeTab    = $_GET['tab'] ?? 'semana';
$mostrarExito = isset($_GET['ok']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require __DIR__ . "/../includes/rz_app.php"; ?>
    <title>Finanzas | Restaurante Zabisu</title>
    <link rel="icon" type="image/png" href="../assets/img/LOGO_NARA.png">
    <meta name="theme-color" content="#151413">
    <link rel="preload" href="../assets/fonts/kaushan-script-400.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="../assets/fonts/alfa-slab-one-400.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="../assets/css/menu-pizarron.css?v=5">
    <link rel="stylesheet" href="../assets/css/finanzas-pizarron.css?v=1">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
</head>
<body class="pz">
<?php require __DIR__ . "/../includes/pz_filtros.php"; ?>

<main class="fz">
    <header class="fz-cab">
        <div class="pz-marca">
            <img src="../assets/img/LOGO_N_128.png" alt="">
            <div>
                <span class="pz-marca__nombre">Zabisu</span>
                <span class="pz-marca__lema">Sabor y Servicio</span>
            </div>
        </div>
        <h1 class="pz-titulo"><span class="pz-pincel"><span>Finanzas</span></span></h1>
        <nav class="fz-cab__acc" aria-label="Navegación">
            <a href="panel_general.php" class="fz-btn">← Volver al panel</a>
        </nav>
    </header>
    <p class="fz-sub">Presupuesto, ingresos, gastos y estadísticas de ventas</p>

    <?php if ($mostrarExito): ?>
        <p class="fz-aviso fz-aviso--ok" role="status">
            <?php if ($_GET['ok'] === 'presupuesto'): ?>
                ✓ Presupuesto actualizado correctamente.
            <?php else: ?>
                ✓ Gasto registrado correctamente.
            <?php endif; ?>
        </p>
    <?php endif; ?>

    <!-- ══ TABS ══════════════════════════════════════════════ -->
    <div class="fin-tabs" role="tablist">
        <button class="fin-tab <?php echo $activeTab === 'semana' ? 'active' : ''; ?>"
                data-tab="semana" role="tab">Esta semana</button>
        <button class="fin-tab <?php echo $activeTab === 'ventas' ? 'active' : ''; ?>"
                data-tab="ventas" role="tab">Ventas</button>
        <button class="fin-tab <?php echo $activeTab === 'gastos' ? 'active' : ''; ?>"
                data-tab="gastos" role="tab">Gastos</button>
    </div>

    <!-- ════════════════════════════════════════════════════════
         TAB: ESTA SEMANA
    ══════════════════════════════════════════════════════════ -->
    <div class="fin-panel <?php echo $activeTab === 'semana' ? 'active' : ''; ?>" id="tab-semana">

        <!-- KPIs semana actual -->
        <div class="fin-resumen">
            <div class="fin-kpi fin-kpi--presupuesto">
                <span class="fin-kpi__label">Presupuesto</span>
                <strong class="fin-kpi__valor"><?php echo $presupuestoMonto > 0 ? fmtPeso($presupuestoMonto) : '—'; ?></strong>
                <span class="fin-kpi__sub"><?php echo htmlspecialchars(formatSemana($lunesActual)); ?></span>
            </div>
            <div class="fin-kpi fin-kpi--ingresos">
                <span class="fin-kpi__label">Ingresos</span>
                <strong class="fin-kpi__valor"><?php echo $ingresosActuales > 0 ? fmtPeso($ingresosActuales) : '—'; ?></strong>
                <span class="fin-kpi__sub">Ventas de la semana</span>
            </div>
            <div class="fin-kpi fin-kpi--gastos">
                <span class="fin-kpi__label">Gastos</span>
                <strong class="fin-kpi__valor"><?php echo $gastosActuales > 0 ? fmtPeso($gastosActuales) : '—'; ?></strong>
                <?php if ($presupuestoMonto > 0 && $gastosActuales > 0):
                    $pct = min(round(($gastosActuales / $presupuestoMonto) * 100), 100);
                    $fillClass = $gastosActuales > $presupuestoMonto ? 'over' : ($pct >= 80 ? 'warn' : 'ok');
                ?>
                <span class="fin-kpi__sub"><?php echo $pct; ?>% del presupuesto</span>
                <div class="pres-progress">
                    <div class="pres-progress__fill pres-progress__fill--<?php echo $fillClass; ?>"
                         style="width:<?php echo $pct; ?>%"></div>
                </div>
                <?php else: ?>
                <span class="fin-kpi__sub">Egresos registrados</span>
                <?php endif; ?>
            </div>
            <?php
            $gananciaClass = $gananciaActual >= 0 ? 'fin-kpi--ganancia' : 'fin-kpi--ganancia-neg';
            $gananciaPrefix = $gananciaActual >= 0 ? '+' : '';
            ?>
            <div class="fin-kpi <?php echo $gananciaClass; ?>">
                <span class="fin-kpi__label">Ganancia</span>
                <strong class="fin-kpi__valor">
                    <?php echo ($ingresosActuales > 0 || $gastosActuales > 0)
                        ? $gananciaPrefix . fmtPeso($gananciaActual)
                        : '—'; ?>
                </strong>
                <span class="fin-kpi__sub">Ingresos – Gastos</span>
            </div>
        </div>

        <!-- Formulario presupuesto -->
        <section class="fz-sec">
            <h2 class="pz-cinta pz-fibra">Fijar presupuesto semanal</h2>
            <form method="POST" action="finanzas.php" class="fz-tarjeta">
                <div class="pres-form-row">
                    <label class="fz-campo">
                        <span>Semana</span>
                        <select name="semana_presupuesto">
                            <?php foreach ($weekOptions as $lunes): ?>
                                <option value="<?php echo $lunes; ?>"
                                    <?php echo $lunes === $lunesActual ? 'selected' : ''; ?>>
                                    <?php echo formatSemana($lunes); ?>
                                    <?php echo $lunes === $lunesActual ? ' (esta semana)' : ''; ?>
                                    <?php echo isset($presupuestosPorSemana[$lunes]) ? ' ✓' : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="fz-campo">
                        <span>Presupuesto de gastos</span>
                        <span class="fz-monto">
                            <i>$</i>
                            <input type="number" name="presupuesto_monto" step="0.01" min="0"
                                   placeholder="0.00"
                                   value="<?php echo $presupuestoMonto > 0 ? $presupuestoMonto : ''; ?>">
                        </span>
                    </label>
                    <label class="fz-campo">
                        <span>Nota (opcional)</span>
                        <input type="text" name="presupuesto_notas" placeholder="Ej: fondo inicial $3,000"
                               value="<?php echo htmlspecialchars($presupuestoActual['notas'] ?? ''); ?>"
                               maxlength="255">
                    </label>
                    <button type="submit" name="guardar_presupuesto" class="fz-btn fz-btn--naranja">
                        Guardar
                    </button>
                </div>
            </form>
        </section>

        <!-- P&L histórico semanal -->
        <section class="fz-sec">
            <h2 class="pz-cinta pz-fibra">Historial semanal</h2>
            <?php if (empty($todasSemanas)): ?>
                <p class="fz-vacio">
                    Aún no hay datos. Registra tu primer gasto o espera a tener pedidos.
                </p>
            <?php else: ?>
            <div class="fz-tabla-wrap">
                <table class="pl-table">
                    <thead>
                        <tr>
                            <th>Semana</th>
                            <th>Presupuesto</th>
                            <th>Ingresos</th>
                            <th>Gastos</th>
                            <th>Ganancia</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($todasSemanas as $sem):
                            $ingresos   = $ingresosPorSemana[$sem]      ?? 0;
                            $gastos     = $gastosPorSemana[$sem]         ?? 0;
                            $pres       = $presupuestosPorSemana[$sem]   ?? 0;
                            $balance    = $ingresos - $gastos;
                            $esSemActual = $sem === $lunesActual;
                        ?>
                        <tr<?php echo $esSemActual ? ' class="pl-actual"' : ''; ?>>
                            <td class="pl-semana" data-th="Semana">
                                <?php echo htmlspecialchars(formatSemana($sem)); ?>
                                <?php if ($esSemActual): ?><span class="pl-badge">esta semana</span><?php endif; ?>
                                <br>
                                <a href="finanzas.php?tab=gastos&semana=<?php echo urlencode($sem); ?>"
                                   class="pl-sem-link">ver gastos →</a>
                            </td>
                            <td class="pl-presupuesto" data-th="Presupuesto">
                                <?php echo $pres > 0 ? fmtPeso($pres) : '<span class="pl-vacio">—</span>'; ?>
                            </td>
                            <td class="pl-ingreso" data-th="Ingresos">
                                <?php echo $ingresos > 0 ? fmtPeso($ingresos) : '<span class="pl-vacio">—</span>'; ?>
                            </td>
                            <td class="pl-gasto" data-th="Gastos">
                                <?php if ($gastos > 0):
                                    echo fmtPeso($gastos);
                                    if ($pres > 0):
                                        $overPct = round(($gastos / $pres) * 100);
                                        $overClass = $gastos > $pres ? 'pl-pct pl-pct--over' : 'pl-pct';
                                        echo '<br><span class="'.$overClass.'">'.$overPct.'% del pres.</span>';
                                    endif;
                                else: echo '<span class="pl-vacio">—</span>'; endif; ?>
                            </td>
                            <td class="pl-balance <?php
                                if ($balance > 0)     echo 'pl-balance--pos';
                                elseif ($balance < 0) echo 'pl-balance--neg';
                                else                  echo 'pl-balance--cero';
                            ?>" data-th="Ganancia">
                                <?php
                                if ($ingresos == 0 && $gastos == 0) echo '<span class="pl-vacio">—</span>';
                                else echo ($balance >= 0 ? '+' : '') . fmtPeso($balance);
                                ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </section>

    </div><!-- /tab-semana -->


    <!-- ════════════════════════════════════════════════════════
         TAB: VENTAS
    ══════════════════════════════════════════════════════════ -->
    <div class="fin-panel <?php echo $activeTab === 'ventas' ? 'active' : ''; ?>" id="tab-ventas">

        <!-- Filtro de fechas -->
        <div class="fz-tarjeta fz-filtro">
            <form method="GET" action="finanzas.php" class="fz-filtro__form">
                <input type="hidden" name="tab" value="ventas">
                <label class="fz-campo" for="fi">
                    <span>Desde</span>
                    <input type="date" id="fi" name="fi"
                           value="<?php echo htmlspecialchars($fechaInicio); ?>"
                           max="<?php echo $hoy; ?>">
                </label>
                <label class="fz-campo" for="ff">
                    <span>Hasta</span>
                    <input type="date" id="ff" name="ff"
                           value="<?php echo htmlspecialchars($fechaFin); ?>"
                           max="<?php echo $hoy; ?>">
                </label>
                <button type="submit" class="fz-btn fz-btn--naranja">Aplicar</button>
            </form>
            <div class="fz-filtro__atajos">
                <a href="finanzas.php?tab=ventas" class="fz-chip">Este mes</a>
                <a href="finanzas.php?tab=ventas&fi=<?php echo date('Y-m-d', strtotime('monday this week')); ?>&ff=<?php echo $hoy; ?>" class="fz-chip">Esta semana</a>
                <a href="finanzas.php?tab=ventas&fi=<?php echo $hoy; ?>&ff=<?php echo $hoy; ?>" class="fz-chip">Hoy</a>
                <a href="reporte_ventas.php?fecha_inicio=<?php echo urlencode($fechaInicio); ?>&fecha_fin=<?php echo urlencode($fechaFin); ?>" class="fz-btn fz-btn--estraza">Generar reporte</a>
            </div>
            <p class="fz-filtro__rango">
                Del <strong><?php echo fmtFecha($fechaInicio); ?></strong>
                al <strong><?php echo fmtFecha($fechaFin); ?></strong>
            </p>
        </div>

        <?php if ((int)$kpis['total_pedidos'] === 0): ?>
            <p class="fz-vacio">No hay pedidos en el período seleccionado.</p>
        <?php else: ?>

        <!-- KPI cards -->
        <div class="fin-resumen fin-resumen--ventas">
            <div class="fin-kpi fin-kpi--principal">
                <span class="fin-kpi__label">Ventas totales</span>
                <strong class="fin-kpi__valor"><?php echo fmtPeso($kpis['total_ventas']); ?></strong>
                <span class="fin-kpi__sub"><?php echo (int)$kpis['total_pedidos']; ?> pedido<?php echo (int)$kpis['total_pedidos'] !== 1 ? 's' : ''; ?> · <?php echo $totalComidas; ?> comida<?php echo $totalComidas !== 1 ? 's' : ''; ?></span>
            </div>
            <div class="fin-kpi">
                <span class="fin-kpi__label">Transferencia</span>
                <strong class="fin-kpi__valor"><?php echo fmtPeso($kpis['total_transferencia']); ?></strong>
                <span class="fin-kpi__sub">Pagos digitales</span>
            </div>
            <div class="fin-kpi">
                <span class="fin-kpi__label">Efectivo</span>
                <strong class="fin-kpi__valor"><?php echo fmtPeso($kpis['total_efectivo']); ?></strong>
                <span class="fin-kpi__sub">Pago al recibir</span>
            </div>
            <div class="fin-kpi fin-kpi--confirmado">
                <span class="fin-kpi__label">Confirmado</span>
                <strong class="fin-kpi__valor"><?php echo fmtPeso($kpis['total_confirmado']); ?></strong>
                <span class="fin-kpi__sub">Por confirmar: <?php echo fmtPeso($kpis['total_por_confirmar']); ?></span>
            </div>
        </div>

        <!-- Gráficas fila 1 -->
        <div class="fz-grid fz-grid--70-30">
            <section class="fz-sec">
                <h2 class="pz-cinta pz-fibra">Ventas por día</h2>
                <div class="fz-tarjeta">
                    <div class="fz-grafica">
                        <canvas id="chart-ventas-dia"></canvas>
                    </div>
                </div>
            </section>
            <section class="fz-sec">
                <h2 class="pz-cinta pz-fibra">Método de pago</h2>
                <div class="fz-tarjeta">
                    <div class="fz-grafica fz-grafica--dona">
                        <canvas id="chart-metodo-pago"></canvas>
                    </div>
                    <div class="fz-leyenda">
                        <?php foreach ($ventasPorMetodo as $iMetodo => $metodo): ?>
                            <div class="fz-leyenda__item">
                                <span class="fz-leyenda__nombre">
                                    <i class="fz-leyenda__color fz-leyenda__color--<?php echo $iMetodo; ?>"></i>
                                    <?php echo htmlspecialchars($metodo['metodo_pago'] ?: 'Sin especificar'); ?>
                                </span>
                                <span class="fz-leyenda__valor">
                                    <?php echo fmtPeso($metodo['total']); ?>
                                    <em><?php echo (int)$metodo['pedidos']; ?> ped.</em>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
        </div>

        <!-- Ventas por ubicación -->
        <?php if (!empty($ventasPorUbicacion)): ?>
        <section class="fz-sec">
            <h2 class="pz-cinta pz-fibra">Ventas por ubicación</h2>
            <div class="fz-tarjeta fz-ubicaciones">
                <?php
                $maxUb = max(array_column($ventasPorUbicacion, 'total')) ?: 1;
                foreach ($ventasPorUbicacion as $ub):
                    $pct = round(($ub['total'] / $maxUb) * 100);
                ?>
                    <div class="ubicacion-barra">
                        <div class="ubicacion-barra__info">
                            <span class="ubicacion-barra__nombre"><?php echo htmlspecialchars($ub['nombre_ubicacion']); ?></span>
                            <span class="ubicacion-barra__tipo"><?php echo ucfirst(htmlspecialchars($ub['tipo'])); ?></span>
                        </div>
                        <div class="ubicacion-barra__track">
                            <div class="ubicacion-barra__fill" style="width:<?php echo $pct; ?>%"></div>
                        </div>
                        <div class="ubicacion-barra__nums">
                            <strong><?php echo fmtPeso($ub['total']); ?></strong>
                            <span><?php echo (int)$ub['pedidos']; ?> ped.</span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <!-- Top productos -->
        <div class="fz-grid fz-grid--50-50">
            <?php if (!empty($topPlatos)): ?>
            <section class="fz-sec">
                <h2 class="pz-cinta pz-fibra">Platos más pedidos</h2>
                <div class="fz-tarjeta">
                <?php
                $maxP = max(array_column($topPlatos, 'total')) ?: 1;
                foreach ($topPlatos as $i => $plato):
                    $pct = round(($plato['total'] / $maxP) * 100);
                ?>
                    <div class="ranking-item">
                        <span class="ranking-item__pos"><?php echo $i + 1; ?></span>
                        <div class="ranking-item__contenido">
                            <div class="ranking-item__nombre"><?php echo htmlspecialchars($plato['nombre_producto']); ?></div>
                            <div class="ranking-item__barra">
                                <div class="ranking-item__fill" style="width:<?php echo $pct; ?>%"></div>
                            </div>
                        </div>
                        <span class="ranking-item__total"><?php echo (int)$plato['total']; ?></span>
                    </div>
                <?php endforeach; ?>
                </div>
            </section>
            <?php endif; ?>
            <?php if (!empty($topComplementos)): ?>
            <section class="fz-sec">
                <h2 class="pz-cinta pz-fibra">Complementos más pedidos</h2>
                <div class="fz-tarjeta">
                <?php
                $maxC = max(array_column($topComplementos, 'total')) ?: 1;
                foreach ($topComplementos as $i => $comp):
                    $pct = round(($comp['total'] / $maxC) * 100);
                ?>
                    <div class="ranking-item">
                        <span class="ranking-item__pos"><?php echo $i + 1; ?></span>
                        <div class="ranking-item__contenido">
                            <div class="ranking-item__nombre"><?php echo htmlspecialchars($comp['nombre_producto']); ?></div>
                            <div class="ranking-item__barra">
                                <div class="ranking-item__fill ranking-item__fill--secundario" style="width:<?php echo $pct; ?>%"></div>
                            </div>
                        </div>
                        <span class="ranking-item__total"><?php echo (int)$comp['total']; ?></span>
                    </div>
                <?php endforeach; ?>
                </div>
            </section>
            <?php endif; ?>
        </div>

        <?php endif; ?>

    </div><!-- /tab-ventas -->


    <!-- ════════════════════════════════════════════════════════
         TAB: GASTOS
    ══════════════════════════════════════════════════════════ -->
    <div class="fin-panel <?php echo $activeTab === 'gastos' ? 'active' : ''; ?>" id="tab-gastos">

        <!-- Captura rápida: toda la semana en una pantalla -->
        <a href="gastos_semana.php" class="fz-atajo">
            <span class="fz-atajo__icono" aria-hidden="true"><svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 4h6M9 4a2 2 0 0 0-2 2v0h10v0a2 2 0 0 0-2-2M7 6H5.5A1.5 1.5 0 0 0 4 7.5v12A1.5 1.5 0 0 0 5.5 21h13a1.5 1.5 0 0 0 1.5-1.5v-12A1.5 1.5 0 0 0 18.5 6H17"/><path d="m8.5 13.5 2.2 2.2 4.8-4.8"/></svg></span>
            <span class="fz-atajo__txt">
                <strong>Anotar gastos de la semana</strong>
                <span>Lo de siempre ya está en la lista: solo pon los montos</span>
            </span>
            <span class="fz-atajo__flecha" aria-hidden="true">→</span>
        </a>

        <!-- Formulario -->
        <section class="fz-sec">
            <h2 class="pz-cinta pz-fibra">Registrar gasto</h2>

            <?php if (!empty($erroresGasto)): ?>
                <div class="fz-aviso fz-aviso--error" role="alert">
                    <?php foreach ($erroresGasto as $e): ?>
                        <p>⚠ <?php echo htmlspecialchars($e); ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="finanzas.php?tab=gastos" class="fz-tarjeta">
                <div class="gastos-form-grid">
                    <label class="fz-campo campo-full">
                        <span>Concepto</span>
                        <input type="text" name="concepto"
                               value="<?php echo htmlspecialchars($_POST['concepto'] ?? ''); ?>"
                               placeholder="Ej: Pollo, jitomate, cebolla…" required maxlength="255">
                    </label>
                    <label class="fz-campo">
                        <span>Categoría</span>
                        <select name="categoria" required>
                            <?php foreach ($categorias as $cat): ?>
                                <option value="<?php echo htmlspecialchars($cat); ?>"
                                    <?php echo (($_POST['categoria'] ?? '') === $cat) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="fz-campo">
                        <span>Monto</span>
                        <span class="fz-monto">
                            <i>$</i>
                            <input type="number" name="monto" step="0.01" min="0.01"
                                   value="<?php echo htmlspecialchars($_POST['monto'] ?? ''); ?>"
                                   placeholder="0.00" required>
                        </span>
                    </label>
                    <label class="fz-campo">
                        <span>Fecha de compra</span>
                        <input type="date" name="fecha_compra"
                               value="<?php echo htmlspecialchars($_POST['fecha_compra'] ?? $hoy); ?>"
                               required>
                    </label>
                    <label class="fz-campo">
                        <span>Para la semana de</span>
                        <select name="semana_destino" required>
                            <?php
                            $lunesDefault = $dowHoy >= 6
                                ? date('Y-m-d', strtotime('next monday', strtotime($hoy)))
                                : $lunesActual;
                            foreach ($weekOptions as $lunes):
                                $esDef = $lunes === ($filtroSemana ?: $lunesDefault);
                            ?>
                                <option value="<?php echo $lunes; ?>" <?php echo $esDef ? 'selected' : ''; ?>>
                                    <?php echo formatSemana($lunes); ?>
                                    <?php echo $lunes === $lunesActual ? ' (esta semana)' : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="fz-campo campo-full">
                        <span>Notas <small>(opcional)</small></span>
                        <textarea name="notas" placeholder="Mercado, proveedor, detalles…"><?php echo htmlspecialchars($_POST['notas'] ?? ''); ?></textarea>
                    </label>
                </div>
                <div class="fz-form-pie">
                    <button type="submit" name="guardar_gasto" class="fz-btn fz-btn--naranja">Guardar gasto</button>
                </div>
            </form>
        </section>

        <!-- Lista de gastos -->
        <section class="fz-sec">
            <h2 class="pz-cinta pz-fibra">
                <?php if ($filtroSemana !== ''): ?>
                    Gastos — <?php echo htmlspecialchars(formatSemana($filtroSemana)); ?>
                <?php else: ?>
                    Últimos gastos
                <?php endif; ?>
            </h2>

            <div class="gastos-filtro">
                <form method="GET" action="finanzas.php" class="gastos-filtro__form">
                    <input type="hidden" name="tab" value="gastos">
                    <label for="filtro-semana">Semana:</label>
                    <select name="semana" id="filtro-semana" onchange="this.form.submit()">
                        <option value="">Todas</option>
                        <?php foreach ($weekOptions as $lunes): ?>
                            <option value="<?php echo $lunes; ?>"
                                <?php echo $filtroSemana === $lunes ? 'selected' : ''; ?>>
                                <?php echo formatSemana($lunes); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($filtroSemana !== ''): ?>
                        <a href="finanzas.php?tab=gastos" class="fz-chip">Ver todas</a>
                    <?php endif; ?>
                </form>
            </div>

            <?php if (empty($listaGastos)): ?>
                <p class="fz-vacio">
                    No hay gastos registrados<?php echo $filtroSemana !== '' ? ' para esta semana' : ''; ?>.
                </p>
            <?php else: ?>
            <div class="fz-tabla-wrap">
                <table class="fz-gastos" id="tabla-gastos">
                    <thead>
                        <tr>
                            <th>Semana para</th>
                            <th>Concepto</th>
                            <th>Categoría</th>
                            <th>Fecha compra</th>
                            <th>Monto</th>
                            <th>Notas</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($listaGastos as $g): ?>
                        <tr id="gasto-fila-<?php echo (int)$g['id_gasto']; ?>">
                            <td class="fz-gastos__semana"><?php echo htmlspecialchars(formatSemana($g['semana_destino'])); ?></td>
                            <td class="fz-gastos__concepto"><?php echo htmlspecialchars($g['concepto']); ?></td>
                            <td class="fz-gastos__cat">
                                <?php $catKey = explode(' ', $g['categoria'])[0]; ?>
                                <span class="cat-badge cat-<?php echo htmlspecialchars($catKey); ?>">
                                    <?php echo htmlspecialchars($g['categoria']); ?>
                                </span>
                            </td>
                            <td class="fz-gastos__fecha"><?php echo date('d/m/Y', strtotime($g['fecha_compra'])); ?></td>
                            <td class="fz-gastos__monto"><strong><?php echo fmtPeso((float)$g['monto']); ?></strong></td>
                            <td class="gasto-notas"><?php echo htmlspecialchars($g['notas'] ?? '—'); ?></td>
                            <td class="fz-gastos__acc">
                                <button class="gasto-btn-del"
                                        onclick="eliminarGasto(<?php echo (int)$g['id_gasto']; ?>, this)">
                                    Eliminar
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </section>

    </div><!-- /tab-gastos -->

</main>

<script>
// ── Tabs ─────────────────────────────────────────────────
(function () {
    const tabs   = document.querySelectorAll('.fin-tab');
    const panels = document.querySelectorAll('.fin-panel');

    tabs.forEach(function (btn) {
        btn.addEventListener('click', function () {
            const target = btn.dataset.tab;
            tabs.forEach(function (t) { t.classList.remove('active'); });
            panels.forEach(function (p) { p.classList.remove('active'); });
            btn.classList.add('active');
            document.getElementById('tab-' + target).classList.add('active');
            history.replaceState(null, '', '?tab=' + target);
            if (target === 'ventas') initCharts();
        });
    });

    // Abrir tab correcto si hay semana en URL (desde "ver gastos →")
    const params = new URLSearchParams(location.search);
    const tabParam = params.get('tab');
    if (tabParam && document.getElementById('tab-' + tabParam)) {
        tabs.forEach(function (t) { t.classList.remove('active'); });
        panels.forEach(function (p) { p.classList.remove('active'); });
        document.querySelector('[data-tab="' + tabParam + '"]').classList.add('active');
        document.getElementById('tab-' + tabParam).classList.add('active');
    }

    // Inicializar charts si tab ventas es el activo desde el inicio
    if (document.getElementById('tab-ventas').classList.contains('active')) {
        initCharts();
    }
})();

// ── Charts ────────────────────────────────────────────────
var chartsInit = false;
function initCharts() {
    if (chartsInit) return;
    chartsInit = true;

        const ORANGE_SOFT  = 'rgba(255, 122, 0, 0.22)';
    const ORANGE_BORDER = 'rgba(255, 122, 0, 1)';
    const GRID_COLOR   = 'rgba(236, 235, 231, 0.07)';
    const TEXT_COLOR   = '#A5A29B';

    Chart.defaults.color       = TEXT_COLOR;
    Chart.defaults.font.family = 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif';
    Chart.defaults.font.size   = 13;

    const ctxDia = document.getElementById('chart-ventas-dia');
    if (ctxDia) {
        const diasData = <?php echo json_encode(array_map(function ($r) {
            return ['fecha' => $r['fecha'], 'total' => (float)$r['total'], 'pedidos' => (int)$r['pedidos']];
        }, $ventasPorDia)); ?>;
        const labels  = diasData.map(function (d) { const p = d.fecha.split('-'); return p[2]+'/'+p[1]; });
        const totales = diasData.map(function (d) { return d.total; });
        const pedidos = diasData.map(function (d) { return d.pedidos; });
        new Chart(ctxDia, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    { label: 'Ventas ($)', data: totales, backgroundColor: ORANGE_SOFT, borderColor: ORANGE_BORDER, borderWidth: 2, borderRadius: 8, yAxisID: 'y' },
                    { label: 'Pedidos', data: pedidos, type: 'line', borderColor: 'rgba(217,184,143,0.85)', backgroundColor: 'transparent', borderWidth: 2, pointBackgroundColor: '#D9B88F', pointRadius: 4, tension: 0.35, yAxisID: 'y2' }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: true,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'top' },
                    tooltip: { callbacks: { label: function (ctx) {
                        return ctx.dataset.yAxisID === 'y' ? ' $' + ctx.parsed.y.toFixed(2) : ' ' + ctx.parsed.y + ' pedidos';
                    }}}
                },
                scales: {
                    x: { grid: { color: GRID_COLOR } },
                    y: { grid: { color: GRID_COLOR }, ticks: { callback: function (v) { return '$' + v.toLocaleString(); }}},
                    y2: { position: 'right', grid: { drawOnChartArea: false }, ticks: { stepSize: 1 }}
                }
            }
        });
    }

    const ctxMetodo = document.getElementById('chart-metodo-pago');
    if (ctxMetodo) {
        const metodosData = <?php echo json_encode(array_map(function ($r) {
            return ['metodo' => $r['metodo_pago'] ?: 'Sin especificar', 'total' => (float)$r['total']];
        }, $ventasPorMetodo)); ?>;
        new Chart(ctxMetodo, {
            type: 'doughnut',
            data: {
                labels: metodosData.map(function (d) { return d.metodo; }),
                datasets: [{ data: metodosData.map(function (d) { return d.total; }),
                    backgroundColor: ['#FF7A00','#C49A6C','#ECEBE7','#5E5953'],
                    borderColor: '#201E1C', borderWidth: 2, hoverOffset: 6 }]
            },
            options: {
                responsive: true, maintainAspectRatio: true, cutout: '68%',
                plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (ctx) { return ' $' + ctx.parsed.toFixed(2); }}}}
            }
        });
    }
}

// ── Eliminar gasto ────────────────────────────────────────
function eliminarGasto(id, btn) {
    if (!confirm('¿Eliminar este gasto? Esta acción no se puede deshacer.')) return;
    btn.disabled = true;
    btn.textContent = '…';
    fetch('eliminar_gasto.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'id_gasto=' + id,
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        if (data.ok) {
            const fila = document.getElementById('gasto-fila-' + id);
            fila.style.transition = 'opacity .3s';
            fila.style.opacity = '0';
            setTimeout(function () { fila.remove(); }, 300);
        } else {
            alert('No se pudo eliminar. Intenta de nuevo.');
            btn.disabled = false;
            btn.textContent = 'Eliminar';
        }
    })
    .catch(function () {
        alert('Error de red. Intenta de nuevo.');
        btn.disabled = false;
        btn.textContent = 'Eliminar';
    });
}
</script>
</body>
</html>
