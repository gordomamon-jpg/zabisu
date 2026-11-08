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

$generadoEn   = date("d/m/Y g:i A");
$periodoTexto = rf($fechaInicio) . " — " . rf($fechaFin);

/* ── Datos para el resumen escrito (con lo ya consultado) ── */
function rDia($f) {
    $dias = ["domingo","lunes","martes","miércoles","jueves","viernes","sábado"];
    return $dias[(int)date("w", strtotime($f))];
}
$totalVentas   = (float)$kpis["total_ventas"];
$totalPedidos  = (int)$kpis["total_pedidos"];
$diasConVenta  = count($ventasPorDia);
$promedioDia   = $diasConVenta > 0 ? $totalVentas / $diasConVenta : 0;
$ticketProm    = $totalPedidos > 0 ? $totalVentas / $totalPedidos : 0;
$comidasPorPed = $totalPedidos > 0 ? $totalComidas / $totalPedidos : 0;
$pctEfectivo   = $totalVentas > 0 ? number_format((float)$kpis["total_efectivo"] / $totalVentas * 100, 1) : "0.0";
$pctTransfer   = $totalVentas > 0 ? number_format((float)$kpis["total_transferencia"] / $totalVentas * 100, 1) : "0.0";
$diaTop = null;
foreach ($ventasPorDia as $d) {
    if ($diaTop === null || (float)$d["total"] > (float)$diaTop["total"]) $diaTop = $d;
}
$maxDia  = $diaTop ? max((float)$diaTop["total"], 1) : 1;
$ubTop   = $ventasPorUbicacion[0] ?? null;
$pctUbTop = ($ubTop && $totalVentas > 0) ? number_format((float)$ubTop["total"] / $totalVentas * 100, 1) : "0.0";
$folioReporte = "RV-" . date("Ymd", strtotime($fechaInicio)) . "-" . date("Ymd", strtotime($fechaFin));
$periodoCorto = date("d/m/Y", strtotime($fechaInicio)) . " – " . date("d/m/Y", strtotime($fechaFin));
$nombreArchivo = "Reporte de ventas Zabisu " . $fechaInicio . " a " . $fechaFin;
// Texto del pie de cada página (va dentro de un string de CSS)
$piePagina = addcslashes("Zabisu · Reporte de ventas · " . $periodoCorto, '"\\');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- El navegador usa el título como nombre del PDF al guardarlo -->
    <title><?php echo htmlspecialchars($nombreArchivo); ?></title>
    <link rel="icon" type="image/png" href="../assets/img/LOGO_NARA.png">
    <link rel="preload" href="../assets/fonts/alfa-slab-one-400.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="../assets/css/reporte-ventas.css?v=1">
    <style>
        /* Pie de cada página del PDF: periodo y número de página */
        @page {
            @bottom-left  { content: "<?php echo $piePagina; ?>"; }
            @bottom-right { content: "Página " counter(page) " de " counter(pages); }
        }
    </style>
</head>
<body>

<!-- Barra de acciones (solo en pantalla) -->
<div class="rv-barra">
    <a class="rv-barra__volver" href="finanzas.php?tab=ventas&fi=<?php echo urlencode($fechaInicio); ?>&ff=<?php echo urlencode($fechaFin); ?>">← Volver a Finanzas</a>
    <span class="rv-barra__info">Tamaño carta · <?php echo $totalPedidos; ?> pedidos</span>
    <button class="rv-barra__pdf" type="button" onclick="window.print()">Descargar PDF</button>
</div>

<main class="rv-hoja">

    <!-- ══ ENCABEZADO ══════════════════════════════════════════ -->
    <header class="rv-cab">
        <div class="rv-cab__marca">
            <img src="../assets/img/LOGO_NARA_300.png" alt="">
            <div>
                <strong>Zabisu</strong>
                <span>Sabor y Servicio</span>
            </div>
        </div>
        <dl class="rv-cab__datos">
            <div><dt>Documento</dt><dd><?php echo $folioReporte; ?></dd></div>
            <div><dt>Generado</dt><dd><?php echo $generadoEn; ?></dd></div>
        </dl>
    </header>

    <div class="rv-titulo">
        <p class="rv-titulo__tipo">Reporte de ventas</p>
        <h1><?php echo $periodoTexto; ?></h1>
        <p class="rv-titulo__nota">Pedidos reales (sin pedidos de prueba), agrupados por la fecha del menú.</p>
    </div>

    <?php if (empty($pedidosDetalle)): ?>
    <section class="rv-sec">
        <p class="rv-vacio">No se encontraron pedidos en el período seleccionado.</p>
    </section>
    <?php else: ?>

    <!-- ══ RESUMEN ═════════════════════════════════════════════ -->
    <section class="rv-sec">
        <h2><span>1</span> Resumen</h2>

        <div class="rv-kpis">
            <div class="rv-kpi rv-kpi--principal">
                <span>Ventas totales</span>
                <strong><?php echo rp($totalVentas); ?></strong>
                <small><?php echo $totalPedidos; ?> pedidos · <?php echo $totalComidas; ?> comidas</small>
            </div>
            <div class="rv-kpi">
                <span>Ticket promedio</span>
                <strong><?php echo rp($ticketProm); ?></strong>
                <small>Por pedido</small>
            </div>
            <div class="rv-kpi">
                <span>Promedio por día</span>
                <strong><?php echo rp($promedioDia); ?></strong>
                <small><?php echo $diasConVenta; ?> día<?php echo $diasConVenta !== 1 ? "s" : ""; ?> con venta</small>
            </div>
            <div class="rv-kpi">
                <span>Efectivo</span>
                <strong><?php echo rp($kpis["total_efectivo"]); ?></strong>
                <small><?php echo $pctEfectivo; ?>% · cobro al entregar</small>
            </div>
            <div class="rv-kpi">
                <span>Transferencia</span>
                <strong><?php echo rp($kpis["total_transferencia"]); ?></strong>
                <small><?php echo $pctTransfer; ?>% · pagos digitales</small>
            </div>
            <div class="rv-kpi">
                <span>Transferencias confirmadas</span>
                <strong><?php echo rp($kpis["total_confirmado"]); ?></strong>
                <small>Por confirmar: <?php echo rp($kpis["total_por_confirmar"]); ?></small>
            </div>
        </div>

        <ul class="rv-hallazgos">
            <li>Se vendieron <b><?php echo rp($totalVentas); ?></b> en <b><?php echo $totalPedidos; ?> pedidos</b>, con un promedio de <b><?php echo number_format($comidasPorPed, 2); ?> comidas por pedido</b>.</li>
            <?php if ($diaTop): ?>
            <li>El día de mayor venta fue el <b><?php echo rDia($diaTop["fecha"]) . " " . rf($diaTop["fecha"]); ?></b>, con <b><?php echo rp($diaTop["total"]); ?></b> en <?php echo (int)$diaTop["pedidos"]; ?> pedidos.</li>
            <?php endif; ?>
            <?php if ($ubTop): ?>
            <li>El punto de entrega con más ventas fue <b><?php echo htmlspecialchars($ubTop["nombre_ubicacion"]); ?></b>, con <b><?php echo rp($ubTop["total"]); ?></b> (<?php echo $pctUbTop; ?>% del total).</li>
            <?php endif; ?>
            <li>El <b><?php echo $pctEfectivo; ?>%</b> se cobró en efectivo y el <b><?php echo $pctTransfer; ?>%</b> por transferencia<?php echo (float)$kpis["total_por_confirmar"] > 0 ? "; quedan <b>" . rp($kpis["total_por_confirmar"]) . "</b> en transferencias por confirmar" : ""; ?>.</li>
        </ul>
    </section>

    <!-- ══ VENTAS POR DÍA ═══════════════════════════════════════ -->
    <section class="rv-sec">
        <h2><span>2</span> Ventas por día</h2>
        <table class="rv-tabla">
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th class="rv-c">Pedidos</th>
                    <th class="rv-d">Efectivo</th>
                    <th class="rv-d">Transferencia</th>
                    <th class="rv-d">Total del día</th>
                    <th class="rv-barra-col" aria-hidden="true"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($ventasPorDia as $dia): ?>
                <tr>
                    <td><span class="rv-dia"><?php echo ucfirst(rDia($dia["fecha"])); ?></span> <?php echo rfCorta($dia["fecha"]); ?></td>
                    <td class="rv-c"><?php echo (int)$dia["pedidos"]; ?></td>
                    <td class="rv-d"><?php echo rp($dia["efectivo"]); ?></td>
                    <td class="rv-d"><?php echo rp($dia["transferencia"]); ?></td>
                    <td class="rv-d"><strong><?php echo rp($dia["total"]); ?></strong></td>
                    <td class="rv-barra-col"><i style="width:<?php echo round((float)$dia["total"] / $maxDia * 100); ?>%"></i></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td>Total</td>
                    <td class="rv-c"><?php echo $totalPedidos; ?></td>
                    <td class="rv-d"><?php echo rp($kpis["total_efectivo"]); ?></td>
                    <td class="rv-d"><?php echo rp($kpis["total_transferencia"]); ?></td>
                    <td class="rv-d"><?php echo rp($totalVentas); ?></td>
                    <td class="rv-barra-col"></td>
                </tr>
            </tfoot>
        </table>
    </section>

    <!-- ══ PUNTOS DE ENTREGA Y MÉTODO DE PAGO ══════════════════ -->
    <div class="rv-dos">
        <?php if (!empty($ventasPorUbicacion)): ?>
        <section class="rv-sec">
            <h2><span>3</span> Por punto de entrega</h2>
            <table class="rv-tabla">
                <thead>
                    <tr>
                        <th>Punto</th>
                        <th class="rv-c">Pedidos</th>
                        <th class="rv-d">Total</th>
                        <th class="rv-d">%</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ventasPorUbicacion as $ub):
                        $pct = $totalVentas > 0 ? number_format(($ub["total"] / $totalVentas) * 100, 1) : "0.0";
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars($ub["nombre_ubicacion"]); ?><small class="rv-tipo"><?php echo ucfirst(htmlspecialchars($ub["tipo"])); ?></small></td>
                        <td class="rv-c"><?php echo (int)$ub["pedidos"]; ?></td>
                        <td class="rv-d"><strong><?php echo rp($ub["total"]); ?></strong></td>
                        <td class="rv-d"><?php echo $pct; ?>%</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>
        <?php endif; ?>

        <section class="rv-sec">
            <h2><span>4</span> Por método de pago</h2>
            <table class="rv-tabla">
                <thead>
                    <tr>
                        <th>Método</th>
                        <th class="rv-c">Pedidos</th>
                        <th class="rv-d">Total</th>
                        <th class="rv-d">%</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ventasPorMetodo as $m):
                        $pct = $totalVentas > 0 ? number_format(($m["total"] / $totalVentas) * 100, 1) : "0.0";
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars($m["metodo_pago"] ?: "Sin especificar"); ?></td>
                        <td class="rv-c"><?php echo (int)$m["pedidos"]; ?></td>
                        <td class="rv-d"><strong><?php echo rp($m["total"]); ?></strong></td>
                        <td class="rv-d"><?php echo $pct; ?>%</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    </div>

    <!-- ══ DETALLE DE PEDIDOS, agrupado por día ═══════════════ -->
    <?php
        $porDia = [];
        foreach ($pedidosDetalle as $p) $porDia[$p["fecha_menu"]][] = $p;
        $resumenDia = [];
        foreach ($ventasPorDia as $d) $resumenDia[$d["fecha"]] = $d;
        $n = 0;
    ?>
    <section class="rv-sec rv-sec--detalle">
        <h2><span>5</span> Detalle de pedidos</h2>
        <table class="rv-tabla rv-tabla--detalle">
            <thead>
                <tr>
                    <th class="rv-c">#</th>
                    <th>Folio</th>
                    <th>Cliente</th>
                    <th>Punto de entrega</th>
                    <th>Hora</th>
                    <th>Pago</th>
                    <th class="rv-d">Total</th>
                </tr>
            </thead>
            <?php foreach ($porDia as $fechaDia => $pedidosDia):
                $rd = $resumenDia[$fechaDia] ?? null;
            ?>
            <tbody class="rv-grupo">
                <tr class="rv-grupo__cab">
                    <td colspan="5"><?php echo ucfirst(rDia($fechaDia)) . " " . rf($fechaDia); ?></td>
                    <td><?php echo count($pedidosDia); ?> pedido<?php echo count($pedidosDia) !== 1 ? "s" : ""; ?></td>
                    <td class="rv-d"><?php echo $rd ? rp($rd["total"]) : ""; ?></td>
                </tr>
                <?php foreach ($pedidosDia as $p):
                    $n++;
                    $textoPago = $p["metodo_pago"];
                    if ($p["estado_pago"] === "Pagado")                  $textoPago .= " · pagado";
                    elseif ($p["estado_pago"] === "Pendiente de validación") $textoPago .= " · por confirmar";
                    elseif (!in_array($p["estado_pago"], ["Pago en efectivo", "Pagado"], true)) $textoPago .= " · " . $p["estado_pago"];
                ?>
                <tr<?php echo $p["estado_pago"] === "Pendiente de validación" ? ' class="rv-pendiente"' : ''; ?>>
                    <td class="rv-c rv-num"><?php echo $n; ?></td>
                    <td class="rv-folio"><?php echo htmlspecialchars($p["folio"]); ?></td>
                    <td class="rv-cliente"><?php echo htmlspecialchars($p["nombre_cliente"]); ?></td>
                    <td class="rv-punto"><?php echo htmlspecialchars($p["nombre_ubicacion"]); ?></td>
                    <td class="rv-hora"><?php echo date("g:i A", strtotime($p["hora_entrega"])); ?></td>
                    <td class="rv-pago"><?php echo htmlspecialchars($textoPago); ?></td>
                    <td class="rv-d"><?php echo rp($p["total"]); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <?php endforeach; ?>
            <tfoot>
                <tr>
                    <td colspan="6">Total del período · <?php echo $totalPedidos; ?> pedidos</td>
                    <td class="rv-d"><?php echo rp($totalVentas); ?></td>
                </tr>
            </tfoot>
        </table>
    </section>

    <?php endif; ?>

    <footer class="rv-fin">
        <span>Documento de uso interno · Confidencial</span>
        <span>Zabisu — Sabor y Servicio · zabisu.com</span>
    </footer>

</main>

</body>
</html>
