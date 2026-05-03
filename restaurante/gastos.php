<?php
require_once "../config/db.php";
require_once "auth_check.php";

// Auto-create table on first load
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

// ── Helpers ───────────────────────────────────────────────
function getLunes(string $date): string {
    $ts  = strtotime($date);
    $dow = (int)date('N', $ts); // 1=Lun … 7=Dom
    return date('Y-m-d', strtotime('-' . ($dow - 1) . ' days', $ts));
}

function formatSemana(string $lunes): string {
    static $meses = [
        '01'=>'ene','02'=>'feb','03'=>'mar','04'=>'abr','05'=>'may','06'=>'jun',
        '07'=>'jul','08'=>'ago','09'=>'sep','10'=>'oct','11'=>'nov','12'=>'dic',
    ];
    $lunes_ts    = strtotime($lunes);
    $viernes_ts  = strtotime($lunes . ' +4 days');
    $str = date('j', $lunes_ts) . ' ' . ($meses[date('m', $lunes_ts)] ?? '');
    if (date('m', $lunes_ts) !== date('m', $viernes_ts)) {
        $str .= ' – ' . date('j', $viernes_ts) . ' ' . ($meses[date('m', $viernes_ts)] ?? '');
    } else {
        $str .= ' – ' . date('j', $viernes_ts);
    }
    return $str;
}

function fmtPeso(float $n): string {
    return '$' . number_format($n, 2, '.', ',');
}

// ── Datos base ────────────────────────────────────────────
$hoy       = date('Y-m-d');
$categorias = ['Ingredientes', 'Empaque y desechables', 'Gas / Transporte', 'Servicios', 'Varios'];

// Default semana_destino: esta semana entre semana, siguiente semana en finde
$dowHoy       = (int)date('N');
$lunesDefault = $dowHoy >= 6
    ? date('Y-m-d', strtotime('next monday', strtotime($hoy)))
    : getLunes($hoy);

// Opciones de semana para el selector (8 anteriores + actual + 4 futuras)
$lunesBase   = getLunes($hoy);
$weekOptions = [];
for ($i = -8; $i <= 4; $i++) {
    $weekOptions[] = $i === 0
        ? $lunesBase
        : date('Y-m-d', strtotime($lunesBase . ($i > 0 ? " +$i weeks" : "$i weeks")));
}
rsort($weekOptions); // más reciente primero

// ── POST: guardar nuevo gasto ─────────────────────────────
$errores = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_gasto'])) {
    $concepto       = trim($_POST['concepto']       ?? '');
    $categoria      = trim($_POST['categoria']      ?? '');
    $monto_raw      = trim($_POST['monto']          ?? '');
    $fecha_compra   = trim($_POST['fecha_compra']   ?? '');
    $semana_destino = trim($_POST['semana_destino'] ?? '');
    $notas          = trim($_POST['notas']          ?? '');

    if ($concepto === '')                              $errores[] = 'El concepto es obligatorio.';
    if (!in_array($categoria, $categorias, true))     $errores[] = 'Categoría inválida.';
    if (!is_numeric($monto_raw) || (float)$monto_raw <= 0) $errores[] = 'El monto debe ser un número positivo.';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_compra))   $errores[] = 'Fecha de compra inválida.';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $semana_destino)) $errores[] = 'Semana destino inválida.';

    if (empty($errores)) {
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
        header('Location: gastos.php?ok=1');
        exit;
    }
}

$mostrarExito = isset($_GET['ok']);

// ── P&L semanal ───────────────────────────────────────────
$rowsGastos = $conexion->query(
    "SELECT semana_destino, SUM(monto) AS total_gastos
     FROM gastos
     GROUP BY semana_destino
     ORDER BY semana_destino DESC
     LIMIT 12"
)->fetchAll(PDO::FETCH_ASSOC);

$gastosPorSemana = [];
foreach ($rowsGastos as $r) {
    $gastosPorSemana[$r['semana_destino']] = (float)$r['total_gastos'];
}

$rowsIngresos = $conexion->query(
    "SELECT
         DATE_SUB(DATE(fecha_pedido), INTERVAL WEEKDAY(DATE(fecha_pedido)) DAY) AS semana_lunes,
         SUM(total) AS total_ingresos
     FROM pedidos
     WHERE estado != 'Cancelado'
       AND es_prueba = 0
       AND DATE(fecha_pedido) >= DATE_SUB(CURDATE(), INTERVAL 84 DAY)
     GROUP BY semana_lunes
     ORDER BY semana_lunes DESC"
)->fetchAll(PDO::FETCH_ASSOC);

$ingresosPorSemana = [];
foreach ($rowsIngresos as $r) {
    $ingresosPorSemana[$r['semana_lunes']] = (float)$r['total_ingresos'];
}

$todasSemanas = array_unique(
    array_merge(array_keys($gastosPorSemana), array_keys($ingresosPorSemana))
);
rsort($todasSemanas);
$todasSemanas = array_slice($todasSemanas, 0, 12);

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
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registro de gastos | Restaurante Zabisu</title>
    <link rel="icon" type="image/png" href="../assets/img/LOGO_NARA.png">
    <link rel="stylesheet" href="../assets/css/styles.css?v=5">
<style>
    /* ── P&L semanal ─────────────────────────────────── */
    .gastos-semanas {
        width: 100%;
        border-collapse: collapse;
    }
    .gastos-semanas th {
        text-align: left;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 1.5px;
        text-transform: uppercase;
        color: var(--zabisu-gray);
        padding: 0 12px 10px;
        border-bottom: 1px solid rgba(255,255,255,0.07);
    }
    .gastos-semanas td {
        padding: 12px;
        font-size: 14px;
        border-bottom: 1px solid rgba(255,255,255,0.05);
        vertical-align: middle;
    }
    .gastos-semanas tr:last-child td { border-bottom: none; }
    .gastos-semanas tr:hover td { background: rgba(255,255,255,0.03); }
    .gsem-semana  { color: var(--zabisu-white); font-weight: 600; }
    .gsem-ingreso { color: #4ecdc4; font-weight: 700; text-align: right; }
    .gsem-gasto   { color: #ff6b9d; font-weight: 700; text-align: right; }
    .gsem-balance { font-weight: 800; text-align: right; }
    .gsem-balance--pos { color: #c7f2a4; }
    .gsem-balance--neg { color: #ff6b9d; }
    .gsem-balance--cero { color: var(--zabisu-gray); }
    .gsem-vacio { color: var(--zabisu-gray); font-size: 13px; }
    .gsem-lunes-badge {
        display: inline-block;
        font-size: 10px;
        font-weight: 700;
        letter-spacing: 1px;
        text-transform: uppercase;
        background: rgba(255,122,0,0.12);
        color: var(--zabisu-orange);
        border-radius: 4px;
        padding: 2px 6px;
        margin-left: 6px;
    }

    /* ── Formulario de gasto ─────────────────────────── */
    .gastos-form-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 14px;
    }
    .gastos-form-grid .campo-full { grid-column: 1 / -1; }
    @media (max-width: 560px) {
        .gastos-form-grid { grid-template-columns: 1fr; }
        .gastos-form-grid .campo-full { grid-column: 1; }
    }
    .gastos-form-grid label {
        display: flex;
        flex-direction: column;
        gap: 5px;
        font-size: 12px;
        font-weight: 700;
        letter-spacing: 1px;
        text-transform: uppercase;
        color: var(--zabisu-gray);
    }
    .gastos-form-grid input,
    .gastos-form-grid select,
    .gastos-form-grid textarea {
        background: rgba(255,255,255,0.05);
        border: 1px solid rgba(255,255,255,0.12);
        border-radius: 8px;
        color: var(--zabisu-white);
        font-size: 15px;
        padding: 10px 12px;
        transition: border-color .15s;
        font-family: inherit;
    }
    .gastos-form-grid input:focus,
    .gastos-form-grid select:focus,
    .gastos-form-grid textarea:focus {
        outline: none;
        border-color: var(--zabisu-orange);
    }
    .gastos-form-grid textarea { resize: vertical; min-height: 70px; }
    .gastos-form-grid select option { background: #1a1a20; }
    .gastos-monto-wrap { position: relative; }
    .gastos-monto-wrap span {
        position: absolute;
        left: 12px;
        top: 50%;
        transform: translateY(-50%);
        color: var(--zabisu-orange);
        font-weight: 800;
        font-size: 16px;
        pointer-events: none;
    }
    .gastos-monto-wrap input { padding-left: 26px; }

    /* ── Categoría badge ─────────────────────────────── */
    .cat-badge {
        display: inline-block;
        font-size: 11px;
        font-weight: 700;
        border-radius: 5px;
        padding: 3px 8px;
        white-space: nowrap;
    }
    .cat-Ingredientes          { background: rgba(255,122,0,0.15); color: #ff9a40; }
    .cat-Empaque               { background: rgba(78,205,196,0.15); color: #4ecdc4; }
    .cat-Gas                   { background: rgba(255,230,109,0.15); color: #ffe66d; }
    .cat-Servicios             { background: rgba(162,155,254,0.15); color: #a29bfe; }
    .cat-Varios                { background: rgba(167,167,173,0.12); color: #a7a7ad; }

    /* ── Lista ───────────────────────────────────────── */
    .gastos-filtro {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
        margin-bottom: 16px;
    }
    .gastos-filtro label {
        font-size: 12px;
        font-weight: 700;
        letter-spacing: 1px;
        text-transform: uppercase;
        color: var(--zabisu-gray);
    }
    .gastos-filtro select {
        background: rgba(255,255,255,0.05);
        border: 1px solid rgba(255,255,255,0.12);
        border-radius: 8px;
        color: var(--zabisu-white);
        font-size: 13px;
        padding: 7px 10px;
        font-family: inherit;
    }
    .gastos-filtro select option { background: #1a1a20; }
    .gastos-filtro .btn-tabla { margin-left: auto; }
    .gasto-notas { color: var(--zabisu-gray); font-size: 12px; }
    .gasto-btn-del {
        background: none;
        border: 1px solid rgba(255,107,107,0.3);
        color: rgba(255,107,107,0.7);
        border-radius: 6px;
        padding: 4px 10px;
        font-size: 12px;
        cursor: pointer;
        transition: background .15s, color .15s;
        font-family: inherit;
    }
    .gasto-btn-del:hover {
        background: rgba(255,107,107,0.12);
        color: #ff6b6b;
        border-color: #ff6b6b;
    }
    .gsem-link {
        color: var(--zabisu-orange);
        text-decoration: none;
        font-size: 12px;
    }
    .gsem-link:hover { text-decoration: underline; }
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
                <h1 class="hero-zabisu__titulo">Registro de gastos</h1>
            </div>
            <p class="hero-zabisu__texto">Control semanal de ingresos vs. egresos</p>
            <div style="margin-top:10px;">
                <a href="panel_general.php" class="btn-volver-panel">← Volver al panel</a>
            </div>
        </div>
    </div>

    <!-- ══ RESUMEN SEMANAL ══════════════════════════════════ -->
    <div class="bloque-formulario">
        <h2>Resumen semanal</h2>

        <?php if (empty($todasSemanas)): ?>
            <p class="texto-secundario" style="text-align:center;padding:20px 0;">
                Aún no hay datos. Empieza registrando el primer gasto.
            </p>
        <?php else: ?>
        <div class="tabla-pedidos-wrapper">
            <table class="gastos-semanas">
                <thead>
                    <tr>
                        <th>Semana</th>
                        <th style="text-align:right;">Ingresos</th>
                        <th style="text-align:right;">Gastos</th>
                        <th style="text-align:right;">Balance</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($todasSemanas as $sem):
                        $ingresos = $ingresosPorSemana[$sem] ?? 0;
                        $gastos   = $gastosPorSemana[$sem]   ?? 0;
                        $balance  = $ingresos - $gastos;
                        $esSemanaActual = $sem === getLunes($hoy);
                    ?>
                    <tr>
                        <td class="gsem-semana">
                            <?php echo htmlspecialchars(formatSemana($sem)); ?>
                            <?php if ($esSemanaActual): ?>
                                <span class="gsem-lunes-badge">esta semana</span>
                            <?php endif; ?>
                            <br>
                            <a href="gastos.php?semana=<?php echo urlencode($sem); ?>" class="gsem-link">
                                ver gastos
                            </a>
                        </td>
                        <td class="gsem-ingreso">
                            <?php echo $ingresos > 0 ? fmtPeso($ingresos) : '<span class="gsem-vacio">—</span>'; ?>
                        </td>
                        <td class="gsem-gasto">
                            <?php echo $gastos > 0 ? fmtPeso($gastos) : '<span class="gsem-vacio">—</span>'; ?>
                        </td>
                        <td class="gsem-balance <?php
                            if ($balance > 0)      echo 'gsem-balance--pos';
                            elseif ($balance < 0)  echo 'gsem-balance--neg';
                            else                   echo 'gsem-balance--cero';
                        ?>">
                            <?php
                            if ($ingresos == 0 && $gastos == 0) echo '<span class="gsem-vacio">—</span>';
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

    <!-- ══ NUEVO GASTO ══════════════════════════════════════ -->
    <div class="bloque-formulario">
        <h2>Registrar gasto</h2>

        <?php if ($mostrarExito): ?>
            <div class="nota-formulario nota-formulario--exito" style="margin-bottom:16px;">
                ✅ Gasto registrado correctamente.
            </div>
        <?php endif; ?>

        <?php if (!empty($errores)): ?>
            <div class="nota-formulario" style="margin-bottom:16px;border-color:rgba(255,107,107,.4);background:rgba(255,107,107,.06);">
                <?php foreach ($errores as $e): ?>
                    <p style="margin:0;color:#ff6b6b;font-size:13px;">⚠ <?php echo htmlspecialchars($e); ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="gastos.php">
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
                        <?php foreach ($weekOptions as $lunes):
                            $esDef = $lunes === ($filtroSemana ?: $lunesDefault);
                        ?>
                            <option value="<?php echo $lunes; ?>" <?php echo $esDef ? 'selected' : ''; ?>>
                                <?php echo formatSemana($lunes); ?>
                                <?php echo $lunes === getLunes($hoy) ? ' (esta semana)' : ''; ?>
                                <?php echo $lunes === date('Y-m-d', strtotime('next monday', strtotime($hoy))) && $dowHoy >= 6 ? ' (siguiente semana)' : ''; ?>
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
                <button type="submit" name="guardar_gasto" class="btn-stepper">
                    Guardar gasto
                </button>
            </div>
        </form>
    </div>

    <!-- ══ LISTA DE GASTOS ══════════════════════════════════ -->
    <div class="bloque-formulario">

        <div class="cabecera-modulo">
            <h2>
                <?php if ($filtroSemana !== ''): ?>
                    Gastos — <?php echo htmlspecialchars(formatSemana($filtroSemana)); ?>
                <?php else: ?>
                    Últimos gastos
                <?php endif; ?>
            </h2>
        </div>

        <div class="gastos-filtro">
            <form method="GET" action="gastos.php" style="display:contents;">
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
                    <a href="gastos.php" class="btn-tabla">Ver todas</a>
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
                            <?php
                            $catKey = explode(' ', $g['categoria'])[0];
                            $catClass = 'cat-' . $catKey;
                            ?>
                            <span class="cat-badge <?php echo htmlspecialchars($catClass); ?>">
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

</div>

<script>
function eliminarGasto(id, btn) {
    if (!confirm('¿Eliminar este gasto? Esta acción no se puede deshacer.')) return;
    btn.disabled = true;
    btn.textContent = '…';
    fetch('eliminar_gasto.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'id_gasto=' + id,
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            const fila = document.getElementById('gasto-fila-' + id);
            fila.style.transition = 'opacity .3s';
            fila.style.opacity = '0';
            setTimeout(() => fila.remove(), 300);
        } else {
            alert('No se pudo eliminar. Intenta de nuevo.');
            btn.disabled = false;
            btn.textContent = 'Eliminar';
        }
    })
    .catch(() => {
        alert('Error de red. Intenta de nuevo.');
        btn.disabled = false;
        btn.textContent = 'Eliminar';
    });
}
</script>
</body>
</html>
