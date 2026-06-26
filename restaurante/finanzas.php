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
$categorias = ['Ingredientes', 'Empaque y desechables', 'Gas / Transporte', 'Servicios', 'Varios'];
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
        header('Location: finanzas.php?ok=gasto#gastos');
        exit;
    }
}

// ── Presupuesto de la semana actual ───────────────────────
$stmtPres = $conexion->prepare("SELECT presupuesto, notas FROM presupuesto_semana WHERE semana_lunes = :sl");
$stmtPres->execute([':sl' => $lunesActual]);
$presupuestoActual = $stmtPres->fetch(PDO::FETCH_ASSOC);
$presupuestoMonto  = $presupuestoActual ? (float)$presupuestoActual['presupuesto'] : 0;

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

$subFecha = "(SELECT pm2.id_pedido, MIN(md2.fecha) AS fecha_menu
              FROM pedido_menus pm2
              INNER JOIN detalle_pedido dp2 ON dp2.id_pedido_menu = pm2.id_pedido_menu
              INNER JOIN productos pr2 ON pr2.id_producto = dp2.id_producto
              INNER JOIN menu_dia md2 ON md2.id_menu = pr2.id_menu
              GROUP BY pm2.id_pedido) AS mi";

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
    <title>Finanzas | Restaurante Zabisu</title>
    <link rel="icon" type="image/png" href="../assets/img/LOGO_NARA.png">
    <link rel="stylesheet" href="../assets/css/styles.css?v=5">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<style>
/* ── Tabs ──────────────────────────────────────────────── */
.fin-tabs {
    display: flex;
    gap: 4px;
    background: rgba(255,255,255,0.04);
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 12px;
    padding: 5px;
    margin-bottom: 28px;
}
.fin-tab {
    flex: 1;
    background: none;
    border: none;
    color: var(--zabisu-gray);
    font-family: inherit;
    font-size: 14px;
    font-weight: 600;
    padding: 10px 16px;
    border-radius: 8px;
    cursor: pointer;
    transition: background .15s, color .15s;
    letter-spacing: .3px;
}
.fin-tab:hover { color: var(--zabisu-white); background: rgba(255,255,255,0.05); }
.fin-tab.active { background: var(--zabisu-orange); color: #fff; }
.fin-panel { display: none; }
.fin-panel.active { display: block; }

/* ── Resumen semana actual ─────────────────────────────── */
.fin-resumen {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
    margin-bottom: 24px;
}
@media (max-width: 700px) { .fin-resumen { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 400px) { .fin-resumen { grid-template-columns: 1fr; } }

.fin-kpi {
    background: rgba(255,255,255,0.04);
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 12px;
    padding: 18px 16px;
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.fin-kpi--presupuesto { border-color: rgba(255,122,0,0.25); background: rgba(255,122,0,0.05); }
.fin-kpi--ingresos    { border-color: rgba(78,205,196,0.2); background: rgba(78,205,196,0.04); }
.fin-kpi--gastos      { border-color: rgba(255,107,107,0.2); background: rgba(255,107,107,0.04); }
.fin-kpi--ganancia    { border-color: rgba(199,242,164,0.2); background: rgba(199,242,164,0.04); }
.fin-kpi--ganancia-neg { border-color: rgba(255,107,107,0.3); background: rgba(255,107,107,0.06); }

.fin-kpi__label {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 1.2px;
    text-transform: uppercase;
    color: var(--zabisu-gray);
}
.fin-kpi__valor {
    font-size: 26px;
    font-weight: 800;
    line-height: 1;
}
.fin-kpi--presupuesto .fin-kpi__valor { color: var(--zabisu-orange); }
.fin-kpi--ingresos    .fin-kpi__valor { color: #4ecdc4; }
.fin-kpi--gastos      .fin-kpi__valor { color: #ff6b9d; }
.fin-kpi--ganancia    .fin-kpi__valor { color: #c7f2a4; }
.fin-kpi--ganancia-neg .fin-kpi__valor { color: #ff6b6b; }
.fin-kpi__sub {
    font-size: 12px;
    color: var(--zabisu-gray);
}

/* ── Presupuesto form ───────────────────────────────────── */
.pres-form-wrap {
    background: rgba(255,122,0,0.06);
    border: 1px solid rgba(255,122,0,0.2);
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 24px;
}
.pres-form-wrap h3 {
    font-size: 13px;
    font-weight: 700;
    letter-spacing: 1px;
    text-transform: uppercase;
    color: var(--zabisu-orange);
    margin: 0 0 14px;
}
.pres-form-row {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    align-items: flex-end;
}
.pres-form-row label {
    display: flex;
    flex-direction: column;
    gap: 5px;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 1px;
    text-transform: uppercase;
    color: var(--zabisu-gray);
}
.pres-form-row input, .pres-form-row select {
    background: rgba(255,255,255,0.06);
    border: 1px solid rgba(255,255,255,0.12);
    border-radius: 8px;
    color: var(--zabisu-white);
    font-size: 15px;
    padding: 9px 12px;
    font-family: inherit;
}
.pres-form-row input:focus, .pres-form-row select:focus {
    outline: none;
    border-color: var(--zabisu-orange);
}
.pres-form-row select option { background: #1a1a20; }
.pres-monto-wrap { position: relative; }
.pres-monto-wrap span {
    position: absolute;
    left: 12px; top: 50%;
    transform: translateY(-50%);
    color: var(--zabisu-orange);
    font-weight: 800;
    font-size: 16px;
    pointer-events: none;
}
.pres-monto-wrap input { padding-left: 26px; }

/* ── P&L table ──────────────────────────────────────────── */
.pl-table { width: 100%; border-collapse: collapse; }
.pl-table th {
    font-size: 11px; font-weight: 700;
    letter-spacing: 1.5px; text-transform: uppercase;
    color: var(--zabisu-gray);
    padding: 0 12px 10px;
    border-bottom: 1px solid rgba(255,255,255,0.07);
    text-align: right;
}
.pl-table th:first-child { text-align: left; }
.pl-table td {
    padding: 12px;
    font-size: 14px;
    border-bottom: 1px solid rgba(255,255,255,0.05);
    vertical-align: middle;
    text-align: right;
}
.pl-table td:first-child { text-align: left; }
.pl-table tr:last-child td { border-bottom: none; }
.pl-table tr:hover td { background: rgba(255,255,255,0.02); }
.pl-semana { color: var(--zabisu-white); font-weight: 600; }
.pl-badge {
    display: inline-block;
    font-size: 10px; font-weight: 700;
    letter-spacing: 1px; text-transform: uppercase;
    background: rgba(255,122,0,0.12);
    color: var(--zabisu-orange);
    border-radius: 4px; padding: 2px 6px;
    margin-left: 6px;
}
.pl-presupuesto { color: var(--zabisu-orange); font-weight: 700; }
.pl-ingreso  { color: #4ecdc4; font-weight: 700; }
.pl-gasto    { color: #ff6b9d; font-weight: 700; }
.pl-balance  { font-weight: 800; }
.pl-balance--pos  { color: #c7f2a4; }
.pl-balance--neg  { color: #ff6b6b; }
.pl-balance--cero { color: var(--zabisu-gray); }
.pl-vacio { color: var(--zabisu-gray); font-size: 13px; }
.pl-sem-link { color: var(--zabisu-orange); text-decoration: none; font-size: 11px; }
.pl-sem-link:hover { text-decoration: underline; }

/* ── Progress bar de gastos vs presupuesto ─────────────── */
.pres-progress {
    margin-top: 8px;
    height: 5px;
    background: rgba(255,255,255,0.08);
    border-radius: 3px;
    overflow: hidden;
}
.pres-progress__fill {
    height: 100%;
    border-radius: 3px;
    transition: width .3s;
}
.pres-progress__fill--ok  { background: #4ecdc4; }
.pres-progress__fill--warn { background: #ffe66d; }
.pres-progress__fill--over { background: #ff6b6b; }

/* ── Stats KPIs ─────────────────────────────────────────── */
.estadisticas-filtro__form {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    align-items: flex-end;
}
.estadisticas-filtro__grupo {
    display: flex;
    flex-direction: column;
    gap: 5px;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 1px;
    text-transform: uppercase;
    color: var(--zabisu-gray);
}
.estadisticas-filtro__grupo input[type=date] {
    background: rgba(255,255,255,0.05);
    border: 1px solid rgba(255,255,255,0.12);
    border-radius: 8px;
    color: var(--zabisu-white);
    font-size: 14px;
    padding: 9px 12px;
    font-family: inherit;
}
.estadisticas-filtro__grupo input:focus { outline: none; border-color: var(--zabisu-orange); }
.estadisticas-filtro__acciones {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    align-items: center;
}
.estadisticas-filtro__rango { font-size: 13px; color: var(--zabisu-gray); margin-top: 10px; }

/* ── Gastos form ────────────────────────────────────────── */
.gastos-form-grid {
    display: grid; grid-template-columns: 1fr 1fr; gap: 14px;
}
.gastos-form-grid .campo-full { grid-column: 1 / -1; }
@media (max-width: 560px) {
    .gastos-form-grid { grid-template-columns: 1fr; }
    .gastos-form-grid .campo-full { grid-column: 1; }
}
.gastos-form-grid label {
    display: flex; flex-direction: column; gap: 5px;
    font-size: 12px; font-weight: 700; letter-spacing: 1px;
    text-transform: uppercase; color: var(--zabisu-gray);
}
.gastos-form-grid input,
.gastos-form-grid select,
.gastos-form-grid textarea {
    background: rgba(255,255,255,0.05);
    border: 1px solid rgba(255,255,255,0.12);
    border-radius: 8px; color: var(--zabisu-white);
    font-size: 15px; padding: 10px 12px;
    transition: border-color .15s; font-family: inherit;
}
.gastos-form-grid input:focus,
.gastos-form-grid select:focus,
.gastos-form-grid textarea:focus { outline: none; border-color: var(--zabisu-orange); }
.gastos-form-grid textarea { resize: vertical; min-height: 70px; }
.gastos-form-grid select option { background: #1a1a20; }
.gastos-monto-wrap { position: relative; }
.gastos-monto-wrap span {
    position: absolute; left: 12px; top: 50%;
    transform: translateY(-50%); color: var(--zabisu-orange);
    font-weight: 800; font-size: 16px; pointer-events: none;
}
.gastos-monto-wrap input { padding-left: 26px; }
.cat-badge {
    display: inline-block; font-size: 11px; font-weight: 700;
    border-radius: 5px; padding: 3px 8px; white-space: nowrap;
}
.cat-Ingredientes { background: rgba(255,122,0,0.15); color: #ff9a40; }
.cat-Empaque      { background: rgba(78,205,196,0.15); color: #4ecdc4; }
.cat-Gas          { background: rgba(255,230,109,0.15); color: #ffe66d; }
.cat-Servicios    { background: rgba(162,155,254,0.15); color: #a29bfe; }
.cat-Varios       { background: rgba(167,167,173,0.12); color: #a7a7ad; }
.gastos-filtro {
    display: flex; align-items: center; gap: 10px;
    flex-wrap: wrap; margin-bottom: 16px;
}
.gastos-filtro label {
    font-size: 12px; font-weight: 700; letter-spacing: 1px;
    text-transform: uppercase; color: var(--zabisu-gray);
}
.gastos-filtro select {
    background: rgba(255,255,255,0.05);
    border: 1px solid rgba(255,255,255,0.12);
    border-radius: 8px; color: var(--zabisu-white);
    font-size: 13px; padding: 7px 10px; font-family: inherit;
}
.gastos-filtro select option { background: #1a1a20; }
.gasto-notas { color: var(--zabisu-gray); font-size: 12px; }
.gasto-btn-del {
    background: none; border: 1px solid rgba(255,107,107,0.3);
    color: rgba(255,107,107,0.7); border-radius: 6px;
    padding: 4px 10px; font-size: 12px; cursor: pointer;
    transition: background .15s, color .15s; font-family: inherit;
}
.gasto-btn-del:hover {
    background: rgba(255,107,107,0.12); color: #ff6b6b; border-color: #ff6b6b;
}
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
                <h1 class="hero-zabisu__titulo">Control financiero</h1>
            </div>
            <p class="hero-zabisu__texto">Presupuesto, ingresos, gastos y estadísticas de ventas</p>
            <div style="margin-top:10px;">
                <a href="panel_general.php" class="btn-volver-panel">← Volver al panel</a>
            </div>
        </div>
    </div>

    <?php if ($mostrarExito): ?>
        <div class="nota-formulario nota-formulario--exito" style="margin-bottom:18px;">
            <?php if ($_GET['ok'] === 'presupuesto'): ?>
                Presupuesto actualizado correctamente.
            <?php else: ?>
                Gasto registrado correctamente.
            <?php endif; ?>
        </div>
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
        <div class="pres-form-wrap">
            <h3>Fijar presupuesto semanal</h3>
            <form method="POST" action="finanzas.php">
                <div class="pres-form-row">
                    <label>
                        Semana
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
                    <label>
                        Presupuesto de gastos
                        <div class="pres-monto-wrap">
                            <span>$</span>
                            <input type="number" name="presupuesto_monto" step="0.01" min="0"
                                   placeholder="0.00"
                                   value="<?php echo $presupuestoMonto > 0 ? $presupuestoMonto : ''; ?>"
                                   style="width:160px;">
                        </div>
                    </label>
                    <label>
                        Nota (opcional)
                        <input type="text" name="presupuesto_notas" placeholder="Ej: fondo inicial $3,000"
                               value="<?php echo htmlspecialchars($presupuestoActual['notas'] ?? ''); ?>"
                               style="width:200px;" maxlength="255">
                    </label>
                    <button type="submit" name="guardar_presupuesto" class="btn-stepper" style="margin-bottom:1px;">
                        Guardar
                    </button>
                </div>
            </form>
        </div>

        <!-- P&L histórico semanal -->
        <div class="bloque-formulario">
            <h2>Historial semanal</h2>
            <?php if (empty($todasSemanas)): ?>
                <p class="texto-secundario" style="text-align:center;padding:20px 0;">
                    Aún no hay datos. Registra tu primer gasto o espera a tener pedidos.
                </p>
            <?php else: ?>
            <div class="tabla-pedidos-wrapper">
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
                        <tr>
                            <td class="pl-semana">
                                <?php echo htmlspecialchars(formatSemana($sem)); ?>
                                <?php if ($esSemActual): ?><span class="pl-badge">esta semana</span><?php endif; ?>
                                <br>
                                <a href="finanzas.php?tab=gastos&semana=<?php echo urlencode($sem); ?>"
                                   class="pl-sem-link">ver gastos →</a>
                            </td>
                            <td class="pl-presupuesto">
                                <?php echo $pres > 0 ? fmtPeso($pres) : '<span class="pl-vacio">—</span>'; ?>
                            </td>
                            <td class="pl-ingreso">
                                <?php echo $ingresos > 0 ? fmtPeso($ingresos) : '<span class="pl-vacio">—</span>'; ?>
                            </td>
                            <td class="pl-gasto">
                                <?php if ($gastos > 0):
                                    echo fmtPeso($gastos);
                                    if ($pres > 0):
                                        $overPct = round(($gastos / $pres) * 100);
                                        $overClass = $gastos > $pres ? 'color:#ff6b6b' : 'color:var(--zabisu-gray)';
                                        echo '<br><span style="font-size:11px;font-weight:400;'.$overClass.'">'.$overPct.'% del pres.</span>';
                                    endif;
                                else: echo '<span class="pl-vacio">—</span>'; endif; ?>
                            </td>
                            <td class="pl-balance <?php
                                if ($balance > 0)     echo 'pl-balance--pos';
                                elseif ($balance < 0) echo 'pl-balance--neg';
                                else                  echo 'pl-balance--cero';
                            ?>">
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
        </div>

    </div><!-- /tab-semana -->


    <!-- ════════════════════════════════════════════════════════
         TAB: VENTAS
    ══════════════════════════════════════════════════════════ -->
    <div class="fin-panel <?php echo $activeTab === 'ventas' ? 'active' : ''; ?>" id="tab-ventas">

        <!-- Filtro de fechas -->
        <div class="bloque-formulario">
            <form method="GET" action="finanzas.php" class="estadisticas-filtro__form">
                <input type="hidden" name="tab" value="ventas">
                <div class="estadisticas-filtro__grupo">
                    <label for="fi">Desde</label>
                    <input type="date" id="fi" name="fi"
                           value="<?php echo htmlspecialchars($fechaInicio); ?>"
                           max="<?php echo $hoy; ?>">
                </div>
                <div class="estadisticas-filtro__grupo">
                    <label for="ff">Hasta</label>
                    <input type="date" id="ff" name="ff"
                           value="<?php echo htmlspecialchars($fechaFin); ?>"
                           max="<?php echo $hoy; ?>">
                </div>
                <div class="estadisticas-filtro__acciones">
                    <button type="submit" class="btn-stepper btn-stepper--siguiente">Aplicar</button>
                    <a href="finanzas.php?tab=ventas" class="btn-limpiar-filtros">Este mes</a>
                    <a href="finanzas.php?tab=ventas&fi=<?php echo date('Y-m-d', strtotime('monday this week')); ?>&ff=<?php echo $hoy; ?>" class="btn-limpiar-filtros">Esta semana</a>
                    <a href="finanzas.php?tab=ventas&fi=<?php echo $hoy; ?>&ff=<?php echo $hoy; ?>" class="btn-limpiar-filtros">Hoy</a>
                    <a href="reporte_ventas.php?fecha_inicio=<?php echo urlencode($fechaInicio); ?>&fecha_fin=<?php echo urlencode($fechaFin); ?>" class="btn-tabla" style="text-decoration:none;padding:10px 18px;font-size:14px;">Generar reporte</a>
                </div>
            </form>
            <p class="estadisticas-filtro__rango">
                Del <strong><?php echo fmtFecha($fechaInicio); ?></strong>
                al <strong><?php echo fmtFecha($fechaFin); ?></strong>
            </p>
        </div>

        <?php if ((int)$kpis['total_pedidos'] === 0): ?>
            <div class="bloque-formulario">
                <p class="nota-formulario">No hay pedidos en el período seleccionado.</p>
            </div>
        <?php else: ?>

        <!-- KPI cards -->
        <div class="estadisticas-kpis">
            <div class="kpi-card kpi-card--principal">
                <span class="kpi-card__etiqueta">Ventas totales</span>
                <strong class="kpi-card__valor"><?php echo fmtPeso($kpis['total_ventas']); ?></strong>
                <span class="kpi-card__sub"><?php echo (int)$kpis['total_pedidos']; ?> pedido<?php echo (int)$kpis['total_pedidos'] !== 1 ? 's' : ''; ?> · <?php echo $totalComidas; ?> comida<?php echo $totalComidas !== 1 ? 's' : ''; ?></span>
            </div>
            <div class="kpi-card">
                <span class="kpi-card__etiqueta">Transferencia</span>
                <strong class="kpi-card__valor"><?php echo fmtPeso($kpis['total_transferencia']); ?></strong>
                <span class="kpi-card__sub">Pagos digitales</span>
            </div>
            <div class="kpi-card">
                <span class="kpi-card__etiqueta">Efectivo</span>
                <strong class="kpi-card__valor"><?php echo fmtPeso($kpis['total_efectivo']); ?></strong>
                <span class="kpi-card__sub">Pago al recibir</span>
            </div>
            <div class="kpi-card kpi-card--confirmado">
                <span class="kpi-card__etiqueta">Confirmado</span>
                <strong class="kpi-card__valor"><?php echo fmtPeso($kpis['total_confirmado']); ?></strong>
                <span class="kpi-card__sub">Por confirmar: <?php echo fmtPeso($kpis['total_por_confirmar']); ?></span>
            </div>
        </div>

        <!-- Gráficas fila 1 -->
        <div class="estadisticas-grid estadisticas-grid--70-30">
            <div class="bloque-formulario">
                <h2>Ventas por día</h2>
                <div class="estadisticas-chart-wrapper">
                    <canvas id="chart-ventas-dia"></canvas>
                </div>
            </div>
            <div class="bloque-formulario">
                <h2>Método de pago</h2>
                <div class="estadisticas-chart-wrapper estadisticas-chart-wrapper--donut">
                    <canvas id="chart-metodo-pago"></canvas>
                </div>
                <div class="estadisticas-leyenda">
                    <?php foreach ($ventasPorMetodo as $metodo): ?>
                        <div class="estadisticas-leyenda__item">
                            <span class="estadisticas-leyenda__nombre">
                                <?php echo htmlspecialchars($metodo['metodo_pago'] ?: 'Sin especificar'); ?>
                            </span>
                            <span class="estadisticas-leyenda__valor">
                                <?php echo fmtPeso($metodo['total']); ?>
                                <em><?php echo (int)$metodo['pedidos']; ?> ped.</em>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Ventas por ubicación -->
        <?php if (!empty($ventasPorUbicacion)): ?>
        <div class="bloque-formulario">
            <h2>Ventas por ubicación</h2>
            <div class="estadisticas-ubicaciones">
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
        </div>
        <?php endif; ?>

        <!-- Top productos -->
        <div class="estadisticas-grid estadisticas-grid--50-50">
            <?php if (!empty($topPlatos)): ?>
            <div class="bloque-formulario">
                <h2>Platos más pedidos</h2>
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
            <?php endif; ?>
            <?php if (!empty($topComplementos)): ?>
            <div class="bloque-formulario">
                <h2>Complementos más pedidos</h2>
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
            <?php endif; ?>
        </div>

        <?php endif; ?>

    </div><!-- /tab-ventas -->


    <!-- ════════════════════════════════════════════════════════
         TAB: GASTOS
    ══════════════════════════════════════════════════════════ -->
    <div class="fin-panel <?php echo $activeTab === 'gastos' ? 'active' : ''; ?>" id="tab-gastos">

        <!-- Formulario -->
        <div class="bloque-formulario">
            <h2>Registrar gasto</h2>

            <?php if (!empty($erroresGasto)): ?>
                <div class="nota-formulario" style="margin-bottom:16px;border-color:rgba(255,107,107,.4);background:rgba(255,107,107,.06);">
                    <?php foreach ($erroresGasto as $e): ?>
                        <p style="margin:0;color:#ff6b6b;font-size:13px;">⚠ <?php echo htmlspecialchars($e); ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="finanzas.php">
                <div class="gastos-form-grid">
                    <label class="campo-full">
                        Concepto
                        <input type="text" name="concepto"
                               value="<?php echo htmlspecialchars($_POST['concepto'] ?? ''); ?>"
                               placeholder="Ej: Pollo, jitomate, cebolla…" required maxlength="255">
                    </label>
                    <label>
                        Categoría
                        <select name="categoria" required>
                            <?php foreach ($categorias as $cat): ?>
                                <option value="<?php echo htmlspecialchars($cat); ?>"
                                    <?php echo (($_POST['categoria'] ?? '') === $cat) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        Monto
                        <div class="gastos-monto-wrap">
                            <span>$</span>
                            <input type="number" name="monto" step="0.01" min="0.01"
                                   value="<?php echo htmlspecialchars($_POST['monto'] ?? ''); ?>"
                                   placeholder="0.00" required>
                        </div>
                    </label>
                    <label>
                        Fecha de compra
                        <input type="date" name="fecha_compra"
                               value="<?php echo htmlspecialchars($_POST['fecha_compra'] ?? $hoy); ?>"
                               required>
                    </label>
                    <label>
                        Para la semana de
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
                    <label>
                        Notas <span style="font-weight:400;text-transform:none;letter-spacing:0;">(opcional)</span>
                        <textarea name="notas" placeholder="Mercado, proveedor, detalles…"><?php echo htmlspecialchars($_POST['notas'] ?? ''); ?></textarea>
                    </label>
                </div>
                <div style="margin-top:20px;">
                    <button type="submit" name="guardar_gasto" class="btn-stepper">Guardar gasto</button>
                </div>
            </form>
        </div>

        <!-- Lista de gastos -->
        <div class="bloque-formulario">
            <h2>
                <?php if ($filtroSemana !== ''): ?>
                    Gastos — <?php echo htmlspecialchars(formatSemana($filtroSemana)); ?>
                <?php else: ?>
                    Últimos gastos
                <?php endif; ?>
            </h2>

            <div class="gastos-filtro">
                <form method="GET" action="finanzas.php" style="display:contents;">
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
                        <a href="finanzas.php?tab=gastos" class="btn-tabla">Ver todas</a>
                    <?php endif; ?>
                </form>
            </div>

            <?php if (empty($listaGastos)): ?>
                <p class="texto-secundario" style="text-align:center;padding:16px 0;">
                    No hay gastos registrados<?php echo $filtroSemana !== '' ? ' para esta semana' : ''; ?>.
                </p>
            <?php else: ?>
            <div class="tabla-pedidos-wrapper">
                <table class="tabla-pedidos" id="tabla-gastos">
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
                            <td><?php echo htmlspecialchars(formatSemana($g['semana_destino'])); ?></td>
                            <td><?php echo htmlspecialchars($g['concepto']); ?></td>
                            <td>
                                <?php $catKey = explode(' ', $g['categoria'])[0]; ?>
                                <span class="cat-badge cat-<?php echo htmlspecialchars($catKey); ?>">
                                    <?php echo htmlspecialchars($g['categoria']); ?>
                                </span>
                            </td>
                            <td><?php echo date('d/m/Y', strtotime($g['fecha_compra'])); ?></td>
                            <td><strong><?php echo fmtPeso((float)$g['monto']); ?></strong></td>
                            <td class="gasto-notas"><?php echo htmlspecialchars($g['notas'] ?? '—'); ?></td>
                            <td>
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
        </div>

    </div><!-- /tab-gastos -->

</div><!-- /contenedor -->

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

    const ORANGE       = 'rgba(255, 122, 0, 0.85)';
    const ORANGE_SOFT  = 'rgba(255, 122, 0, 0.18)';
    const ORANGE_BORDER = 'rgba(255, 122, 0, 1)';
    const GRID_COLOR   = 'rgba(255, 255, 255, 0.07)';
    const TEXT_COLOR   = '#a7a7ad';

    Chart.defaults.color       = TEXT_COLOR;
    Chart.defaults.font.family = 'Arial, sans-serif';
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
                    { label: 'Pedidos', data: pedidos, type: 'line', borderColor: 'rgba(255,255,255,0.45)', backgroundColor: 'transparent', borderWidth: 2, pointBackgroundColor: 'rgba(255,255,255,0.8)', pointRadius: 4, tension: 0.35, yAxisID: 'y2' }
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
                    backgroundColor: ['rgba(255,122,0,0.82)','rgba(255,200,0,0.72)','rgba(255,255,255,0.25)','rgba(160,160,160,0.35)'],
                    borderColor: 'rgba(0,0,0,0.3)', borderWidth: 2, hoverOffset: 6 }]
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
