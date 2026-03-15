<?php
require_once "../config/db.php";
require_once "auth_check.php";

/*
    Rango de fechas (por defecto: mes actual)
*/
$hoy        = date("Y-m-d");
$primerDia  = date("Y-m-01");

$fechaInicio = isset($_GET["fecha_inicio"]) && $_GET["fecha_inicio"] !== ""
    ? $_GET["fecha_inicio"]
    : $primerDia;

$fechaFin = isset($_GET["fecha_fin"]) && $_GET["fecha_fin"] !== ""
    ? $_GET["fecha_fin"]
    : $hoy;

// Asegurar que inicio <= fin
if ($fechaInicio > $fechaFin) {
    [$fechaInicio, $fechaFin] = [$fechaFin, $fechaInicio];
}

/*
    KPIs generales
*/
$sqlKpis = "SELECT
                COUNT(*)                                                          AS total_pedidos,
                COALESCE(SUM(total), 0)                                           AS total_ventas,
                COALESCE(SUM(CASE WHEN metodo_pago = 'Transferencia' THEN total ELSE 0 END), 0) AS total_transferencia,
                COALESCE(SUM(CASE WHEN metodo_pago = 'Efectivo'       THEN total ELSE 0 END), 0) AS total_efectivo,
                COALESCE(SUM(CASE WHEN estado_pago = 'Pagado'                    THEN total ELSE 0 END), 0) AS total_confirmado,
                COALESCE(SUM(CASE WHEN estado_pago = 'Pendiente de validación'   THEN total ELSE 0 END), 0) AS total_por_confirmar
            FROM pedidos
            WHERE DATE(fecha_pedido) BETWEEN :fi AND :ff";
$stmtKpis = $conexion->prepare($sqlKpis);
$stmtKpis->execute([":fi" => $fechaInicio, ":ff" => $fechaFin]);
$kpis = $stmtKpis->fetch(PDO::FETCH_ASSOC);

/*
    Total de comidas (filas en pedido_menus)
*/
$sqlComidas = "SELECT COUNT(*) AS total_comidas
               FROM pedido_menus pm
               INNER JOIN pedidos p ON pm.id_pedido = p.id_pedido
               WHERE DATE(p.fecha_pedido) BETWEEN :fi AND :ff";
$stmtComidas = $conexion->prepare($sqlComidas);
$stmtComidas->execute([":fi" => $fechaInicio, ":ff" => $fechaFin]);
$totalComidas = (int)$stmtComidas->fetchColumn();

/*
    Ventas por día
*/
$sqlPorDia = "SELECT
                DATE(fecha_pedido)       AS fecha,
                COUNT(*)                 AS pedidos,
                COALESCE(SUM(total), 0)  AS total
              FROM pedidos
              WHERE DATE(fecha_pedido) BETWEEN :fi AND :ff
              GROUP BY DATE(fecha_pedido)
              ORDER BY fecha ASC";
$stmtPorDia = $conexion->prepare($sqlPorDia);
$stmtPorDia->execute([":fi" => $fechaInicio, ":ff" => $fechaFin]);
$ventasPorDia = $stmtPorDia->fetchAll(PDO::FETCH_ASSOC);

/*
    Ventas por ubicación
*/
$sqlPorUbicacion = "SELECT
                        u.nombre_ubicacion,
                        u.tipo,
                        COUNT(*)                AS pedidos,
                        COALESCE(SUM(p.total), 0) AS total
                    FROM pedidos p
                    INNER JOIN horarios_ubicacion h ON p.id_horario = h.id_horario
                    INNER JOIN ubicaciones u ON h.id_ubicacion = u.id_ubicacion
                    WHERE DATE(p.fecha_pedido) BETWEEN :fi AND :ff
                    GROUP BY u.id_ubicacion, u.nombre_ubicacion, u.tipo
                    ORDER BY total DESC";
$stmtPorUbicacion = $conexion->prepare($sqlPorUbicacion);
$stmtPorUbicacion->execute([":fi" => $fechaInicio, ":ff" => $fechaFin]);
$ventasPorUbicacion = $stmtPorUbicacion->fetchAll(PDO::FETCH_ASSOC);

/*
    Distribución por método de pago
*/
$sqlPorMetodo = "SELECT
                    metodo_pago,
                    COUNT(*)                AS pedidos,
                    COALESCE(SUM(total), 0) AS total
                 FROM pedidos
                 WHERE DATE(fecha_pedido) BETWEEN :fi AND :ff
                 GROUP BY metodo_pago
                 ORDER BY total DESC";
$stmtPorMetodo = $conexion->prepare($sqlPorMetodo);
$stmtPorMetodo->execute([":fi" => $fechaInicio, ":ff" => $fechaFin]);
$ventasPorMetodo = $stmtPorMetodo->fetchAll(PDO::FETCH_ASSOC);

/*
    Top platos más pedidos
*/
$sqlTopPlatos = "SELECT
                    dp.nombre_producto,
                    COUNT(*) AS total
                 FROM detalle_pedido dp
                 INNER JOIN pedido_menus pm ON dp.id_pedido_menu = pm.id_pedido_menu
                 INNER JOIN pedidos p ON pm.id_pedido = p.id_pedido
                 WHERE dp.categoria = 'Plato fuerte'
                   AND DATE(p.fecha_pedido) BETWEEN :fi AND :ff
                 GROUP BY dp.nombre_producto
                 ORDER BY total DESC
                 LIMIT 10";
$stmtTopPlatos = $conexion->prepare($sqlTopPlatos);
$stmtTopPlatos->execute([":fi" => $fechaInicio, ":ff" => $fechaFin]);
$topPlatos = $stmtTopPlatos->fetchAll(PDO::FETCH_ASSOC);

/*
    Top complementos más pedidos
*/
$sqlTopComplementos = "SELECT
                          dp.nombre_producto,
                          COUNT(*) AS total
                       FROM detalle_pedido dp
                       INNER JOIN pedido_menus pm ON dp.id_pedido_menu = pm.id_pedido_menu
                       INNER JOIN pedidos p ON pm.id_pedido = p.id_pedido
                       WHERE dp.categoria = 'Complemento'
                         AND DATE(p.fecha_pedido) BETWEEN :fi AND :ff
                       GROUP BY dp.nombre_producto
                       ORDER BY total DESC
                       LIMIT 10";
$stmtTopComplementos = $conexion->prepare($sqlTopComplementos);
$stmtTopComplementos->execute([":fi" => $fechaInicio, ":ff" => $fechaFin]);
$topComplementos = $stmtTopComplementos->fetchAll(PDO::FETCH_ASSOC);

/*
    Pedidos detallados del período (para la tabla al final)
*/
$sqlDetallePeriodo = "SELECT
                         p.id_pedido,
                         p.folio,
                         p.nombre_cliente,
                         p.total,
                         p.metodo_pago,
                         p.estado_pago,
                         p.fecha_pedido,
                         h.hora_entrega,
                         u.nombre_ubicacion
                      FROM pedidos p
                      INNER JOIN horarios_ubicacion h ON p.id_horario = h.id_horario
                      INNER JOIN ubicaciones u ON h.id_ubicacion = u.id_ubicacion
                      WHERE DATE(p.fecha_pedido) BETWEEN :fi AND :ff
                      ORDER BY p.fecha_pedido DESC";
$stmtDetalle = $conexion->prepare($sqlDetallePeriodo);
$stmtDetalle->execute([":fi" => $fechaInicio, ":ff" => $fechaFin]);
$pedidosPeriodo = $stmtDetalle->fetchAll(PDO::FETCH_ASSOC);

/*
    Helpers
*/
function fmtPeso($n)
{
    return "$" . number_format((float)$n, 2, ".", ",");
}

function fmtFecha($fecha)
{
    $meses = ["ene","feb","mar","abr","may","jun","jul","ago","sep","oct","nov","dic"];
    $t = strtotime($fecha);
    return date("j", $t) . " " . $meses[(int)date("n", $t) - 1] . " " . date("Y", $t);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Estadísticas | Restaurante Zabisu</title>
    <link rel="stylesheet" href="../assets/css/styles.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
</head>
<body>

<div class="contenedor">

    <div class="hero-zabisu">
        <div class="hero-zabisu__glow"></div>
        <div class="hero-zabisu__contenido">
            <p class="hero-zabisu__eyebrow">RESTAURANTE</p>
            <h1 class="hero-zabisu__titulo">Estadísticas de ventas</h1>
            <p class="hero-zabisu__texto">
                Consulta el desempeño de ventas, métodos de pago y productos más populares.
            </p>
            <a href="panel_general.php" class="btn-volver-panel">← Panel general</a>
        </div>
    </div>

    <!-- FILTRO DE FECHAS -->
    <div class="bloque-formulario estadisticas-filtro">
        <form method="GET" action="" class="estadisticas-filtro__form">
            <div class="estadisticas-filtro__grupo">
                <label for="fecha_inicio">Desde</label>
                <input type="date" id="fecha_inicio" name="fecha_inicio"
                       value="<?php echo htmlspecialchars($fechaInicio); ?>"
                       max="<?php echo $hoy; ?>">
            </div>
            <div class="estadisticas-filtro__grupo">
                <label for="fecha_fin">Hasta</label>
                <input type="date" id="fecha_fin" name="fecha_fin"
                       value="<?php echo htmlspecialchars($fechaFin); ?>"
                       max="<?php echo $hoy; ?>">
            </div>
            <div class="estadisticas-filtro__acciones">
                <button type="submit" class="btn-stepper btn-stepper--siguiente">Aplicar</button>
                <a href="estadisticas.php" class="btn-limpiar-filtros">Este mes</a>
                <a href="estadisticas.php?fecha_inicio=<?php echo date("Y-m-d", strtotime("monday this week")); ?>&fecha_fin=<?php echo $hoy; ?>" class="btn-limpiar-filtros">Esta semana</a>
                <a href="estadisticas.php?fecha_inicio=<?php echo $hoy; ?>&fecha_fin=<?php echo $hoy; ?>" class="btn-limpiar-filtros">Hoy</a>
            </div>
        </form>
        <p class="estadisticas-filtro__rango">
            Mostrando del <strong><?php echo fmtFecha($fechaInicio); ?></strong>
            al <strong><?php echo fmtFecha($fechaFin); ?></strong>
        </p>
    </div>

    <?php if (empty($pedidosPeriodo)): ?>
        <div class="bloque-formulario">
            <p class="nota-formulario">No hay pedidos registrados en el período seleccionado.</p>
        </div>
    <?php else: ?>

    <!-- KPI CARDS -->
    <div class="estadisticas-kpis">
        <div class="kpi-card kpi-card--principal">
            <span class="kpi-card__etiqueta">Ventas totales</span>
            <strong class="kpi-card__valor"><?php echo fmtPeso($kpis["total_ventas"]); ?></strong>
            <span class="kpi-card__sub"><?php echo (int)$kpis["total_pedidos"]; ?> pedido<?php echo (int)$kpis["total_pedidos"] !== 1 ? "s" : ""; ?> &middot; <?php echo $totalComidas; ?> comida<?php echo $totalComidas !== 1 ? "s" : ""; ?></span>
        </div>
        <div class="kpi-card">
            <span class="kpi-card__etiqueta">Transferencia</span>
            <strong class="kpi-card__valor"><?php echo fmtPeso($kpis["total_transferencia"]); ?></strong>
            <span class="kpi-card__sub">Pagos digitales</span>
        </div>
        <div class="kpi-card">
            <span class="kpi-card__etiqueta">Efectivo</span>
            <strong class="kpi-card__valor"><?php echo fmtPeso($kpis["total_efectivo"]); ?></strong>
            <span class="kpi-card__sub">Pago al recibir</span>
        </div>
        <div class="kpi-card kpi-card--confirmado">
            <span class="kpi-card__etiqueta">Pago confirmado</span>
            <strong class="kpi-card__valor"><?php echo fmtPeso($kpis["total_confirmado"]); ?></strong>
            <span class="kpi-card__sub">Por confirmar: <?php echo fmtPeso($kpis["total_por_confirmar"]); ?></span>
        </div>
    </div>

    <!-- GRÁFICAS FILA 1 -->
    <div class="estadisticas-grid estadisticas-grid--70-30">

        <!-- Ventas por día -->
        <div class="bloque-formulario">
            <h2>Ventas por día</h2>
            <div class="estadisticas-chart-wrapper">
                <canvas id="chart-ventas-dia"></canvas>
            </div>
        </div>

        <!-- Distribución de pagos -->
        <div class="bloque-formulario">
            <h2>Método de pago</h2>
            <div class="estadisticas-chart-wrapper estadisticas-chart-wrapper--donut">
                <canvas id="chart-metodo-pago"></canvas>
            </div>
            <div class="estadisticas-leyenda">
                <?php foreach ($ventasPorMetodo as $metodo): ?>
                    <div class="estadisticas-leyenda__item">
                        <span class="estadisticas-leyenda__nombre">
                            <?php echo htmlspecialchars($metodo["metodo_pago"] ?: "Sin especificar"); ?>
                        </span>
                        <span class="estadisticas-leyenda__valor">
                            <?php echo fmtPeso($metodo["total"]); ?>
                            <em><?php echo (int)$metodo["pedidos"]; ?> ped.</em>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- GRÁFICAS FILA 2 -->
    <?php if (!empty($ventasPorUbicacion)): ?>
    <div class="bloque-formulario">
        <h2>Ventas por ubicación</h2>
        <div class="estadisticas-ubicaciones">
            <?php
            $maxUb = max(array_column($ventasPorUbicacion, "total")) ?: 1;
            foreach ($ventasPorUbicacion as $ub):
                $pct = round(($ub["total"] / $maxUb) * 100);
            ?>
                <div class="ubicacion-barra">
                    <div class="ubicacion-barra__info">
                        <span class="ubicacion-barra__nombre"><?php echo htmlspecialchars($ub["nombre_ubicacion"]); ?></span>
                        <span class="ubicacion-barra__tipo"><?php echo ucfirst(htmlspecialchars($ub["tipo"])); ?></span>
                    </div>
                    <div class="ubicacion-barra__track">
                        <div class="ubicacion-barra__fill" style="width:<?php echo $pct; ?>%"></div>
                    </div>
                    <div class="ubicacion-barra__nums">
                        <strong><?php echo fmtPeso($ub["total"]); ?></strong>
                        <span><?php echo (int)$ub["pedidos"]; ?> ped.</span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- TOP PRODUCTOS -->
    <div class="estadisticas-grid estadisticas-grid--50-50">

        <?php if (!empty($topPlatos)): ?>
        <div class="bloque-formulario">
            <h2>Platos fuertes más pedidos</h2>
            <?php
            $maxP = max(array_column($topPlatos, "total")) ?: 1;
            foreach ($topPlatos as $i => $plato):
                $pct = round(($plato["total"] / $maxP) * 100);
            ?>
                <div class="ranking-item">
                    <span class="ranking-item__pos"><?php echo $i + 1; ?></span>
                    <div class="ranking-item__contenido">
                        <div class="ranking-item__nombre"><?php echo htmlspecialchars($plato["nombre_producto"]); ?></div>
                        <div class="ranking-item__barra">
                            <div class="ranking-item__fill" style="width:<?php echo $pct; ?>%"></div>
                        </div>
                    </div>
                    <span class="ranking-item__total"><?php echo (int)$plato["total"]; ?></span>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($topComplementos)): ?>
        <div class="bloque-formulario">
            <h2>Complementos más pedidos</h2>
            <?php
            $maxC = max(array_column($topComplementos, "total")) ?: 1;
            foreach ($topComplementos as $i => $comp):
                $pct = round(($comp["total"] / $maxC) * 100);
            ?>
                <div class="ranking-item">
                    <span class="ranking-item__pos"><?php echo $i + 1; ?></span>
                    <div class="ranking-item__contenido">
                        <div class="ranking-item__nombre"><?php echo htmlspecialchars($comp["nombre_producto"]); ?></div>
                        <div class="ranking-item__barra">
                            <div class="ranking-item__fill ranking-item__fill--secundario" style="width:<?php echo $pct; ?>%"></div>
                        </div>
                    </div>
                    <span class="ranking-item__total"><?php echo (int)$comp["total"]; ?></span>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

    </div>

    <!-- TABLA DETALLADA -->
    <div class="bloque-formulario">
        <h2>Detalle de pedidos del período</h2>
        <div class="tabla-pedidos-wrapper">
            <table class="tabla-pedidos">
                <thead>
                    <tr>
                        <th>Folio</th>
                        <th>Fecha</th>
                        <th>Cliente</th>
                        <th>Ubicación</th>
                        <th>Hora</th>
                        <th>Método</th>
                        <th>Estado pago</th>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pedidosPeriodo as $p): ?>
                        <tr>
                            <td>
                                <a class="btn-tabla" href="ver_pedido.php?id=<?php echo urlencode($p["id_pedido"]); ?>" style="padding:6px 12px;font-size:13px;">
                                    <?php echo htmlspecialchars($p["folio"]); ?>
                                </a>
                            </td>
                            <td><?php echo date("d/m/Y", strtotime($p["fecha_pedido"])); ?></td>
                            <td><?php echo htmlspecialchars($p["nombre_cliente"]); ?></td>
                            <td><?php echo htmlspecialchars($p["nombre_ubicacion"]); ?></td>
                            <td><?php echo date("g:i A", strtotime($p["hora_entrega"])); ?></td>
                            <td><?php echo htmlspecialchars($p["metodo_pago"] ?: "—"); ?></td>
                            <td>
                                <?php
                                $clases = [
                                    "Pagado"                   => "estado estado-pagado",
                                    "Pago en efectivo"         => "estado estado-efectivo",
                                    "Pendiente de validación"  => "estado estado-revision",
                                ];
                                $cl = $clases[$p["estado_pago"]] ?? "estado estado-pendiente";
                                ?>
                                <span class="<?php echo $cl; ?>">
                                    <?php echo htmlspecialchars($p["estado_pago"] ?: "Pendiente"); ?>
                                </span>
                            </td>
                            <td><strong><?php echo fmtPeso($p["total"]); ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="tabla-pedidos__total-row">
                        <td colspan="7"><strong>Total del período</strong></td>
                        <td><strong><?php echo fmtPeso($kpis["total_ventas"]); ?></strong></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <?php endif; ?>

    <div class="estadisticas-nav-links">
        <a href="panel_general.php" class="btn-limpiar-filtros">← Panel general</a>
        <a href="pedidos.php" class="btn-limpiar-filtros">Ver pedidos</a>
    </div>

</div>

<script>
(function () {
    const ORANGE       = "rgba(255, 122, 0, 0.85)";
    const ORANGE_SOFT  = "rgba(255, 122, 0, 0.18)";
    const ORANGE_BORDER = "rgba(255, 122, 0, 1)";
    const GRID_COLOR   = "rgba(255, 255, 255, 0.07)";
    const TEXT_COLOR   = "#a7a7ad";

    Chart.defaults.color          = TEXT_COLOR;
    Chart.defaults.font.family    = "Arial, sans-serif";
    Chart.defaults.font.size      = 13;

    // ── VENTAS POR DÍA ──────────────────────────────────────────
    const ctxDia = document.getElementById("chart-ventas-dia");
    if (ctxDia) {
        const diasData = <?php echo json_encode(array_map(function ($r) {
            return ["fecha" => $r["fecha"], "total" => (float)$r["total"], "pedidos" => (int)$r["pedidos"]];
        }, $ventasPorDia)); ?>;

        const labels  = diasData.map(function (d) {
            const [y, m, dia] = d.fecha.split("-");
            return dia + "/" + m;
        });
        const totales = diasData.map(function (d) { return d.total; });
        const pedidos = diasData.map(function (d) { return d.pedidos; });

        new Chart(ctxDia, {
            type: "bar",
            data: {
                labels: labels,
                datasets: [
                    {
                        label: "Ventas ($)",
                        data: totales,
                        backgroundColor: ORANGE_SOFT,
                        borderColor: ORANGE_BORDER,
                        borderWidth: 2,
                        borderRadius: 8,
                        yAxisID: "y"
                    },
                    {
                        label: "Pedidos",
                        data: pedidos,
                        type: "line",
                        borderColor: "rgba(255,255,255,0.45)",
                        backgroundColor: "transparent",
                        borderWidth: 2,
                        pointBackgroundColor: "rgba(255,255,255,0.8)",
                        pointRadius: 4,
                        tension: 0.35,
                        yAxisID: "y2"
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                interaction: { mode: "index", intersect: false },
                plugins: {
                    legend: { position: "top" },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                if (ctx.dataset.yAxisID === "y") {
                                    return " $" + ctx.parsed.y.toFixed(2);
                                }
                                return " " + ctx.parsed.y + " pedidos";
                            }
                        }
                    }
                },
                scales: {
                    x: { grid: { color: GRID_COLOR } },
                    y: {
                        grid: { color: GRID_COLOR },
                        ticks: {
                            callback: function (v) { return "$" + v.toLocaleString(); }
                        }
                    },
                    y2: {
                        position: "right",
                        grid: { drawOnChartArea: false },
                        ticks: { stepSize: 1 }
                    }
                }
            }
        });
    }

    // ── MÉTODO DE PAGO ───────────────────────────────────────────
    const ctxMetodo = document.getElementById("chart-metodo-pago");
    if (ctxMetodo) {
        const metodosData = <?php echo json_encode(array_map(function ($r) {
            return ["metodo" => $r["metodo_pago"] ?: "Sin especificar", "total" => (float)$r["total"]];
        }, $ventasPorMetodo)); ?>;

        const colores = [
            "rgba(255, 122,  0, 0.82)",
            "rgba(255, 200,  0, 0.72)",
            "rgba(255, 255, 255, 0.25)",
            "rgba(160, 160, 160, 0.35)"
        ];

        new Chart(ctxMetodo, {
            type: "doughnut",
            data: {
                labels: metodosData.map(function (d) { return d.metodo; }),
                datasets: [{
                    data: metodosData.map(function (d) { return d.total; }),
                    backgroundColor: colores,
                    borderColor: "rgba(0,0,0,0.3)",
                    borderWidth: 2,
                    hoverOffset: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                cutout: "68%",
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                return " $" + ctx.parsed.toFixed(2);
                            }
                        }
                    }
                }
            }
        });
    }
})();
</script>

</body>
</html>
