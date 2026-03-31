<?php
require_once "../config/db.php";
require_once "auth_check.php";

date_default_timezone_set("America/Mexico_City");

$hoy        = date("Y-m-d");
$primerDia  = date("Y-m-01");

$fechaInicio = (isset($_GET["fecha_inicio"]) && $_GET["fecha_inicio"] !== "")
    ? $_GET["fecha_inicio"] : $primerDia;
$fechaFin    = (isset($_GET["fecha_fin"])    && $_GET["fecha_fin"]    !== "")
    ? $_GET["fecha_fin"]    : $hoy;

if ($fechaInicio > $fechaFin) {
    [$fechaInicio, $fechaFin] = [$fechaFin, $fechaInicio];
}

$subFecha = "(SELECT pm2.id_pedido, MIN(md2.fecha) AS fecha_menu
              FROM pedido_menus pm2
              INNER JOIN detalle_pedido dp2 ON dp2.id_pedido_menu = pm2.id_pedido_menu
              INNER JOIN productos pr2      ON pr2.id_producto    = dp2.id_producto
              INNER JOIN menu_dia md2       ON md2.id_menu        = pr2.id_menu
              GROUP BY pm2.id_pedido) AS mi";

/* ── KPIs ── */
$sqlKpis = "SELECT
                COUNT(*)                                                          AS total_pedidos,
                COALESCE(SUM(p.total), 0)                                         AS total_ventas,
                COALESCE(SUM(CASE WHEN p.metodo_pago = 'Transferencia' THEN p.total ELSE 0 END), 0) AS total_transferencia,
                COALESCE(SUM(CASE WHEN p.metodo_pago = 'Efectivo'       THEN p.total ELSE 0 END), 0) AS total_efectivo,
                COALESCE(SUM(CASE WHEN p.estado_pago = 'Pagado'                   THEN p.total ELSE 0 END), 0) AS total_confirmado,
                COALESCE(SUM(CASE WHEN p.estado_pago = 'Pendiente de validación'  THEN p.total ELSE 0 END), 0) AS total_por_confirmar,
                COALESCE(SUM(CASE WHEN p.estado_pago = 'Pago en efectivo'         THEN p.total ELSE 0 END), 0) AS total_efectivo_estado
            FROM pedidos p
            LEFT JOIN $subFecha ON mi.id_pedido = p.id_pedido
            WHERE COALESCE(mi.fecha_menu, DATE(p.fecha_pedido)) BETWEEN :fi AND :ff
              AND p.es_prueba = 0";
$stmtKpis = $conexion->prepare($sqlKpis);
$stmtKpis->execute([":fi" => $fechaInicio, ":ff" => $fechaFin]);
$kpis = $stmtKpis->fetch(PDO::FETCH_ASSOC);

/* ── Total comidas ── */
$sqlComidas = "SELECT COUNT(*) AS total
               FROM pedido_menus pm
               INNER JOIN pedidos p ON pm.id_pedido = p.id_pedido
               LEFT JOIN $subFecha ON mi.id_pedido = p.id_pedido
               WHERE COALESCE(mi.fecha_menu, DATE(p.fecha_pedido)) BETWEEN :fi AND :ff
                 AND p.es_prueba = 0";
$stmtComidas = $conexion->prepare($sqlComidas);
$stmtComidas->execute([":fi" => $fechaInicio, ":ff" => $fechaFin]);
$totalComidas = (int)$stmtComidas->fetchColumn();

/* ── Por día ── */
$sqlPorDia = "SELECT
                COALESCE(mi.fecha_menu, DATE(p.fecha_pedido)) AS fecha,
                COUNT(*)                                       AS pedidos,
                COALESCE(SUM(p.total), 0)                      AS total,
                COALESCE(SUM(CASE WHEN p.metodo_pago = 'Efectivo'       THEN p.total ELSE 0 END), 0) AS efectivo,
                COALESCE(SUM(CASE WHEN p.metodo_pago = 'Transferencia'  THEN p.total ELSE 0 END), 0) AS transferencia
              FROM pedidos p
              LEFT JOIN $subFecha ON mi.id_pedido = p.id_pedido
              WHERE COALESCE(mi.fecha_menu, DATE(p.fecha_pedido)) BETWEEN :fi AND :ff
                AND p.es_prueba = 0
              GROUP BY COALESCE(mi.fecha_menu, DATE(p.fecha_pedido))
              ORDER BY fecha ASC";
$stmtPorDia = $conexion->prepare($sqlPorDia);
$stmtPorDia->execute([":fi" => $fechaInicio, ":ff" => $fechaFin]);
$ventasPorDia = $stmtPorDia->fetchAll(PDO::FETCH_ASSOC);

/* ── Por ubicación ── */
$sqlPorUbicacion = "SELECT
                        u.nombre_ubicacion,
                        u.tipo,
                        COUNT(*)                  AS pedidos,
                        COALESCE(SUM(p.total), 0) AS total
                    FROM pedidos p
                    INNER JOIN horarios_ubicacion h ON p.id_horario = h.id_horario
                    INNER JOIN ubicaciones u        ON h.id_ubicacion = u.id_ubicacion
                    LEFT JOIN $subFecha ON mi.id_pedido = p.id_pedido
                    WHERE COALESCE(mi.fecha_menu, DATE(p.fecha_pedido)) BETWEEN :fi AND :ff
                      AND p.es_prueba = 0
                    GROUP BY u.id_ubicacion, u.nombre_ubicacion, u.tipo
                    ORDER BY total DESC";
$stmtUbicacion = $conexion->prepare($sqlPorUbicacion);
$stmtUbicacion->execute([":fi" => $fechaInicio, ":ff" => $fechaFin]);
$ventasPorUbicacion = $stmtUbicacion->fetchAll(PDO::FETCH_ASSOC);

/* ── Por método de pago ── */
$sqlPorMetodo = "SELECT
                    p.metodo_pago,
                    COUNT(*)                  AS pedidos,
                    COALESCE(SUM(p.total), 0) AS total
                 FROM pedidos p
                 LEFT JOIN $subFecha ON mi.id_pedido = p.id_pedido
                 WHERE COALESCE(mi.fecha_menu, DATE(p.fecha_pedido)) BETWEEN :fi AND :ff
                   AND p.es_prueba = 0
                 GROUP BY p.metodo_pago
                 ORDER BY total DESC";
$stmtMetodo = $conexion->prepare($sqlPorMetodo);
$stmtMetodo->execute([":fi" => $fechaInicio, ":ff" => $fechaFin]);
$ventasPorMetodo = $stmtMetodo->fetchAll(PDO::FETCH_ASSOC);

/* ── Detalle completo ── */
$sqlDetalle = "SELECT
                   p.folio,
                   p.nombre_cliente,
                   p.telefono,
                   p.metodo_pago,
                   p.estado_pago,
                   p.total,
                   p.observaciones,
                   COALESCE(mi.fecha_menu, DATE(p.fecha_pedido)) AS fecha_menu,
                   h.hora_entrega,
                   u.nombre_ubicacion
               FROM pedidos p
               INNER JOIN horarios_ubicacion h ON p.id_horario = h.id_horario
               INNER JOIN ubicaciones u        ON h.id_ubicacion = u.id_ubicacion
               LEFT JOIN $subFecha ON mi.id_pedido = p.id_pedido
               WHERE COALESCE(mi.fecha_menu, DATE(p.fecha_pedido)) BETWEEN :fi AND :ff
                 AND p.es_prueba = 0
               ORDER BY fecha_menu ASC, h.hora_entrega ASC";
$stmtDetalle = $conexion->prepare($sqlDetalle);
$stmtDetalle->execute([":fi" => $fechaInicio, ":ff" => $fechaFin]);
$pedidosDetalle = $stmtDetalle->fetchAll(PDO::FETCH_ASSOC);

/* ── Helpers ── */
function rp($n)  { return "$" . number_format((float)$n, 2, ".", ","); }
function rf($f)  {
    $meses = ["","enero","febrero","marzo","abril","mayo","junio",
              "julio","agosto","septiembre","octubre","noviembre","diciembre"];
    $t = strtotime($f);
    return date("j", $t) . " de " . $meses[(int)date("n", $t)] . " de " . date("Y", $t);
}
function rfCorta($f) {
    $t = strtotime($f);
    return date("d/m/Y", $t);
}

$generadoEn   = date("d/m/Y H:i A");
$periodoTexto = rf($fechaInicio) . " — " . rf($fechaFin);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reporte de Ventas · Zabisu · <?php echo date("Y-m-d"); ?></title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 13px;
            color: #1a1a1a;
            background: #f0f0f0;
            padding: 24px;
        }

        .reporte {
            background: #fff;
            max-width: 900px;
            margin: 0 auto;
            border: 1px solid #ddd;
            box-shadow: 0 4px 24px rgba(0,0,0,.1);
        }

        /* ── ENCABEZADO ── */
        .rp-header {
            background: #0c0c0f;
            color: #fff;
            padding: 28px 36px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
        }
        .rp-header__logo {
            height: 56px;
            width: auto;
            object-fit: contain;
            flex-shrink: 0;
        }
        .rp-header__info { flex: 1; }
        .rp-header__eyebrow {
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 3px;
            color: #ff7a00;
            text-transform: uppercase;
            margin-bottom: 4px;
        }
        .rp-header__titulo {
            font-size: 22px;
            font-weight: 800;
            line-height: 1.2;
            margin-bottom: 10px;
        }
        .rp-header__meta {
            font-size: 12px;
            color: rgba(255,255,255,.65);
            line-height: 1.7;
        }
        .rp-header__meta strong { color: #fff; }

        /* ── SECCIONES ── */
        .rp-section {
            padding: 24px 36px;
            border-bottom: 1px solid #e8e8e8;
        }
        .rp-section:last-child { border-bottom: none; }

        .rp-section__titulo {
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 2.5px;
            text-transform: uppercase;
            color: #ff7a00;
            margin-bottom: 16px;
            padding-bottom: 8px;
            border-bottom: 2px solid #ff7a00;
            display: inline-block;
        }

        /* ── RESUMEN KPIs ── */
        .rp-kpis {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin-top: 4px;
        }
        .rp-kpi {
            background: #f8f8f8;
            border: 1px solid #e8e8e8;
            border-radius: 8px;
            padding: 14px 16px;
        }
        .rp-kpi--destaque {
            background: #0c0c0f;
            border-color: #0c0c0f;
            color: #fff;
        }
        .rp-kpi__label {
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: #888;
            margin-bottom: 4px;
        }
        .rp-kpi--destaque .rp-kpi__label { color: rgba(255,255,255,.6); }
        .rp-kpi__valor {
            font-size: 20px;
            font-weight: 800;
            color: #1a1a1a;
            line-height: 1.1;
        }
        .rp-kpi--destaque .rp-kpi__valor { color: #fff; }
        .rp-kpi__sub {
            font-size: 11px;
            color: #999;
            margin-top: 3px;
        }
        .rp-kpi--destaque .rp-kpi__sub { color: rgba(255,255,255,.5); }

        /* ── TABLAS ── */
        .rp-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
            margin-top: 4px;
        }
        .rp-table th {
            background: #1a1a1a;
            color: #fff;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
            padding: 9px 12px;
            text-align: left;
            white-space: nowrap;
        }
        .rp-table td {
            padding: 9px 12px;
            border-bottom: 1px solid #efefef;
            vertical-align: middle;
        }
        .rp-table tbody tr:nth-child(even) td { background: #fafafa; }
        .rp-table tbody tr:last-child td { border-bottom: none; }
        .rp-table tfoot td {
            background: #f0f0f0;
            font-weight: 800;
            font-size: 13px;
            border-top: 2px solid #1a1a1a;
            padding: 10px 12px;
        }
        .rp-table .text-right { text-align: right; }
        .rp-table .text-center { text-align: center; }

        .badge-metodo {
            display: inline-block;
            font-size: 10px;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 4px;
            background: #e8e8e8;
            color: #444;
        }
        .badge-metodo--efectivo    { background: #e8f5e9; color: #2e7d32; }
        .badge-metodo--transferencia { background: #e3f2fd; color: #1565c0; }
        .badge-estado { display: inline-block; font-size: 10px; font-weight: 700; padding: 2px 7px; border-radius: 4px; }
        .badge-estado--pagado      { background: #e8f5e9; color: #2e7d32; }
        .badge-estado--efectivo    { background: #fff8e1; color: #f57f17; }
        .badge-estado--pendiente   { background: #fff3e0; color: #e65100; }

        /* ── FIRMA / PIE ── */
        .rp-footer {
            background: #f8f8f8;
            padding: 18px 36px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-top: 2px solid #e8e8e8;
            font-size: 11px;
            color: #888;
        }
        .rp-footer strong { color: #1a1a1a; }

        /* ── BOTÓN IMPRIMIR (solo en pantalla) ── */
        .rp-print-bar {
            max-width: 900px;
            margin: 0 auto 16px;
            display: flex;
            gap: 12px;
            align-items: center;
        }
        .rp-btn-print {
            background: #ff7a00;
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 11px 24px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            letter-spacing: .3px;
        }
        .rp-btn-print:hover { background: #e06900; }
        .rp-btn-back {
            color: #666;
            font-size: 13px;
            text-decoration: none;
        }
        .rp-btn-back:hover { color: #1a1a1a; }

        /* ── SEPARADOR ── */
        .rp-divider {
            height: 1px;
            background: #e8e8e8;
            margin: 16px 0;
        }

        /* ── PRINT ── */
        @media print {
            body {
                background: #fff;
                padding: 0;
                font-size: 11px;
            }
            .rp-print-bar { display: none; }
            .reporte {
                box-shadow: none;
                border: none;
                max-width: 100%;
            }
            .rp-section { padding: 16px 24px; }
            .rp-header  { padding: 20px 24px; }
            .rp-kpis    { grid-template-columns: repeat(3, 1fr); }
            .rp-table th, .rp-table td { padding: 6px 8px; }
            @page {
                size: A4;
                margin: 10mm 12mm;
            }
            .rp-header {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .rp-kpi--destaque {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .rp-table th {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
    </style>
</head>
<body>

<!-- Barra de acciones (solo en pantalla) -->
<div class="rp-print-bar">
    <button class="rp-btn-print" onclick="window.print()">⬇ Guardar como PDF / Imprimir</button>
    <a class="rp-btn-back" href="estadisticas.php?fecha_inicio=<?php echo $fechaInicio; ?>&fecha_fin=<?php echo $fechaFin; ?>">← Volver a estadísticas</a>
</div>

<div class="reporte">

    <!-- ══ ENCABEZADO ══════════════════════════════════════════ -->
    <div class="rp-header">
        <img class="rp-header__logo" src="../assets/img/dd.png" alt="Zabisu">
        <div class="rp-header__info">
            <p class="rp-header__eyebrow">Zabisu — Sabor y Servicio</p>
            <h1 class="rp-header__titulo">Reporte de Ventas</h1>
            <div class="rp-header__meta">
                <strong>Período:</strong> <?php echo $periodoTexto; ?><br>
                <strong>Generado:</strong> <?php echo $generadoEn; ?>
            </div>
        </div>
    </div>

    <?php if (empty($pedidosDetalle)): ?>
    <div class="rp-section">
        <p style="color:#888;">No se encontraron pedidos en el período seleccionado.</p>
    </div>
    <?php else: ?>

    <!-- ══ RESUMEN EJECUTIVO ════════════════════════════════════ -->
    <div class="rp-section">
        <span class="rp-section__titulo">Resumen Ejecutivo</span>
        <div class="rp-kpis">
            <div class="rp-kpi rp-kpi--destaque">
                <div class="rp-kpi__label">Ventas totales</div>
                <div class="rp-kpi__valor"><?php echo rp($kpis["total_ventas"]); ?></div>
                <div class="rp-kpi__sub"><?php echo (int)$kpis["total_pedidos"]; ?> pedidos · <?php echo $totalComidas; ?> comidas</div>
            </div>
            <div class="rp-kpi">
                <div class="rp-kpi__label">Pago en efectivo</div>
                <div class="rp-kpi__valor"><?php echo rp($kpis["total_efectivo"]); ?></div>
                <div class="rp-kpi__sub">Cobro al entregar</div>
            </div>
            <div class="rp-kpi">
                <div class="rp-kpi__label">Transferencia</div>
                <div class="rp-kpi__valor"><?php echo rp($kpis["total_transferencia"]); ?></div>
                <div class="rp-kpi__sub">Pagos digitales</div>
            </div>
            <div class="rp-kpi">
                <div class="rp-kpi__label">Pago confirmado</div>
                <div class="rp-kpi__valor"><?php echo rp($kpis["total_confirmado"]); ?></div>
                <div class="rp-kpi__sub">Transferencias validadas</div>
            </div>
            <div class="rp-kpi">
                <div class="rp-kpi__label">Por confirmar</div>
                <div class="rp-kpi__valor"><?php echo rp($kpis["total_por_confirmar"]); ?></div>
                <div class="rp-kpi__sub">Pendientes de validación</div>
            </div>
            <div class="rp-kpi">
                <div class="rp-kpi__label">Ticket promedio</div>
                <div class="rp-kpi__valor"><?php echo rp((int)$kpis["total_pedidos"] > 0 ? $kpis["total_ventas"] / $kpis["total_pedidos"] : 0); ?></div>
                <div class="rp-kpi__sub">Por pedido</div>
            </div>
        </div>
    </div>

    <!-- ══ VENTAS POR DÍA ═══════════════════════════════════════ -->
    <div class="rp-section">
        <span class="rp-section__titulo">Ventas por Día</span>
        <table class="rp-table">
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th class="text-center">Pedidos</th>
                    <th class="text-right">Efectivo</th>
                    <th class="text-right">Transferencia</th>
                    <th class="text-right">Total del día</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($ventasPorDia as $dia): ?>
                <tr>
                    <td><?php echo rfCorta($dia["fecha"]); ?></td>
                    <td class="text-center"><?php echo (int)$dia["pedidos"]; ?></td>
                    <td class="text-right"><?php echo rp($dia["efectivo"]); ?></td>
                    <td class="text-right"><?php echo rp($dia["transferencia"]); ?></td>
                    <td class="text-right"><strong><?php echo rp($dia["total"]); ?></strong></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td><strong>Total</strong></td>
                    <td class="text-center"><strong><?php echo (int)$kpis["total_pedidos"]; ?></strong></td>
                    <td class="text-right"><strong><?php echo rp($kpis["total_efectivo"]); ?></strong></td>
                    <td class="text-right"><strong><?php echo rp($kpis["total_transferencia"]); ?></strong></td>
                    <td class="text-right"><strong><?php echo rp($kpis["total_ventas"]); ?></strong></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <!-- ══ VENTAS POR PUNTO DE ENTREGA ═════════════════════════ -->
    <?php if (!empty($ventasPorUbicacion)): ?>
    <div class="rp-section">
        <span class="rp-section__titulo">Ventas por Punto de Entrega</span>
        <table class="rp-table">
            <thead>
                <tr>
                    <th>Punto de entrega</th>
                    <th>Tipo</th>
                    <th class="text-center">Pedidos</th>
                    <th class="text-right">Total</th>
                    <th class="text-right">% del total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($ventasPorUbicacion as $ub):
                    $pct = $kpis["total_ventas"] > 0 ? round(($ub["total"] / $kpis["total_ventas"]) * 100, 1) : 0;
                ?>
                <tr>
                    <td><?php echo htmlspecialchars($ub["nombre_ubicacion"]); ?></td>
                    <td><?php echo ucfirst(htmlspecialchars($ub["tipo"])); ?></td>
                    <td class="text-center"><?php echo (int)$ub["pedidos"]; ?></td>
                    <td class="text-right"><strong><?php echo rp($ub["total"]); ?></strong></td>
                    <td class="text-right"><?php echo $pct; ?>%</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- ══ MÉTODO DE PAGO ═══════════════════════════════════════ -->
    <div class="rp-section">
        <span class="rp-section__titulo">Distribución por Método de Pago</span>
        <table class="rp-table">
            <thead>
                <tr>
                    <th>Método</th>
                    <th class="text-center">Pedidos</th>
                    <th class="text-right">Total</th>
                    <th class="text-right">% del total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($ventasPorMetodo as $m):
                    $pct = $kpis["total_ventas"] > 0 ? round(($m["total"] / $kpis["total_ventas"]) * 100, 1) : 0;
                    $badgeClass = $m["metodo_pago"] === "Efectivo" ? "badge-metodo--efectivo" : "badge-metodo--transferencia";
                ?>
                <tr>
                    <td><span class="badge-metodo <?php echo $badgeClass; ?>"><?php echo htmlspecialchars($m["metodo_pago"] ?: "Sin especificar"); ?></span></td>
                    <td class="text-center"><?php echo (int)$m["pedidos"]; ?></td>
                    <td class="text-right"><strong><?php echo rp($m["total"]); ?></strong></td>
                    <td class="text-right"><?php echo $pct; ?>%</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- ══ DETALLE COMPLETO DE PEDIDOS ═════════════════════════ -->
    <div class="rp-section">
        <span class="rp-section__titulo">Detalle Completo de Pedidos</span>
        <table class="rp-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Folio</th>
                    <th>Fecha</th>
                    <th>Cliente</th>
                    <th>Punto de entrega</th>
                    <th>Hora</th>
                    <th>Método</th>
                    <th>Estado</th>
                    <th class="text-right">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pedidosDetalle as $i => $p):
                    $badgeMetodo = $p["metodo_pago"] === "Efectivo" ? "badge-metodo--efectivo" : "badge-metodo--transferencia";
                    $badgeEstado = match($p["estado_pago"]) {
                        "Pagado"                   => "badge-estado--pagado",
                        "Pago en efectivo"         => "badge-estado--efectivo",
                        "Pendiente de validación"  => "badge-estado--pendiente",
                        default                    => ""
                    };
                    $textoEstado = match($p["estado_pago"]) {
                        "Pagado"                   => "Pagado",
                        "Pago en efectivo"         => "Efectivo",
                        "Pendiente de validación"  => "Pendiente",
                        default                    => $p["estado_pago"]
                    };
                ?>
                <tr>
                    <td class="text-center" style="color:#999;"><?php echo $i + 1; ?></td>
                    <td style="font-weight:700;font-size:11px;"><?php echo htmlspecialchars($p["folio"]); ?></td>
                    <td><?php echo rfCorta($p["fecha_menu"]); ?></td>
                    <td><?php echo htmlspecialchars($p["nombre_cliente"]); ?></td>
                    <td><?php echo htmlspecialchars($p["nombre_ubicacion"]); ?></td>
                    <td><?php echo date("g:i A", strtotime($p["hora_entrega"])); ?></td>
                    <td><span class="badge-metodo <?php echo $badgeMetodo; ?>"><?php echo htmlspecialchars($p["metodo_pago"]); ?></span></td>
                    <td><span class="badge-estado <?php echo $badgeEstado; ?>"><?php echo $textoEstado; ?></span></td>
                    <td class="text-right"><strong><?php echo rp($p["total"]); ?></strong></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="8"><strong>Total del período</strong></td>
                    <td class="text-right"><strong><?php echo rp($kpis["total_ventas"]); ?></strong></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <?php endif; ?>

    <!-- ══ PIE DEL REPORTE ══════════════════════════════════════ -->
    <div class="rp-footer">
        <span>Zabisu — Sabor y Servicio · Documento generado el <?php echo $generadoEn; ?></span>
        <span>Uso interno · Confidencial</span>
    </div>

</div>

</body>
</html>
