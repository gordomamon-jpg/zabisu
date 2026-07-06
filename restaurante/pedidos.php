<?php
require_once "../config/db.php";
require_once "auth_check.php";

/*
    Estado de recepción de pedidos
*/
$stmtEstado = $conexion->prepare("SELECT valor FROM configuracion WHERE clave = 'recibir_pedidos' LIMIT 1");
$stmtEstado->execute();
$recibiendoPedidos = (int)($stmtEstado->fetchColumn() ?? 1);

$stmtMP = $conexion->prepare("SELECT valor FROM configuracion WHERE clave = 'modo_prueba' LIMIT 1");
$stmtMP->execute();
$modoPrueba = (int)($stmtMP->fetchColumn() ?? 0);

/*
    Obtener pedidos con ubicación y horario
*/
$sqlBase = "SELECT
                    p.id_pedido,
                    p.folio,
                    p.nombre_cliente,
                    p.telefono,
                    p.total,
                    p.metodo_pago,
                    p.estado,
                    p.estado_pago,
                    p.fecha_pedido,
                    p.visto,
                    p.comprobante_pago,
                    p.es_prueba,
                    h.hora_entrega,
                    u.nombre_ubicacion,
                    u.tipo AS tipo_ubicacion,
                    COALESCE(mi.fecha_menu, DATE(p.fecha_pedido)) AS fecha_grupo_menu,
                    COALESCE(mc.num_menus, 0) AS num_menus
               FROM pedidos p
               INNER JOIN horarios_ubicacion h ON p.id_horario = h.id_horario
               INNER JOIN ubicaciones u ON h.id_ubicacion = u.id_ubicacion
               LEFT JOIN (
                   SELECT pm2.id_pedido, MIN(md2.fecha) AS fecha_menu
                   FROM pedido_menus pm2
                   INNER JOIN detalle_pedido dp2 ON dp2.id_pedido_menu = pm2.id_pedido_menu
                   INNER JOIN productos pr2 ON pr2.id_producto = dp2.id_producto
                   INNER JOIN menu_dia md2 ON md2.id_menu = pr2.id_menu
                   GROUP BY pm2.id_pedido
               ) AS mi ON mi.id_pedido = p.id_pedido
               LEFT JOIN (
                   SELECT id_pedido, COUNT(*) AS num_menus
                   FROM pedido_menus
                   GROUP BY id_pedido
               ) AS mc ON mc.id_pedido = p.id_pedido";

$sqlPedidos = $sqlBase . " WHERE p.es_prueba = 0
               ORDER BY COALESCE(mi.fecha_menu, DATE(p.fecha_pedido)) DESC, p.visto ASC, p.fecha_pedido DESC, p.id_pedido DESC";
$stmtPedidos = $conexion->prepare($sqlPedidos);
$stmtPedidos->execute();
$pedidos = $stmtPedidos->fetchAll(PDO::FETCH_ASSOC);

$sqlPedidosPrueba = $sqlBase . " WHERE p.es_prueba = 1
               ORDER BY COALESCE(mi.fecha_menu, DATE(p.fecha_pedido)) DESC, p.fecha_pedido DESC, p.id_pedido DESC";
$stmtPrueba = $conexion->prepare($sqlPedidosPrueba);
$stmtPrueba->execute();
$pedidosPrueba = $stmtPrueba->fetchAll(PDO::FETCH_ASSOC);

/*
    Contar pedidos nuevos actuales
*/
$totalNuevosActual = count(array_filter($pedidos, function ($pedido) {
    return (int)$pedido["visto"] === 0;
}));

/*
    Pedidos TAB- sin ver para auto-impresión en esta página
*/
$tabAutoprint = array_values(array_filter($pedidos, function ($p) {
    return strpos($p["folio"], "TAB-") === 0 && (int)$p["visto"] === 0;
}));
$tabAutoprintJS = json_encode(array_map(function ($p) {
    return [
        "id"        => (int)$p["id_pedido"],
        "folio"     => $p["folio"],
        "num_menus" => (int)$p["num_menus"],
    ];
}, $tabAutoprint));

/*
    Agrupar pedidos por fecha
*/
$pedidosPorFecha = [];

foreach ($pedidos as $pedido) {
    $fechaGrupo = $pedido["fecha_grupo_menu"];
    $pedidosPorFecha[$fechaGrupo][] = $pedido;
}

/*
    Obtener resumen de platos fuertes por fecha
*/
$sqlResumenPlatos = "SELECT
                        COALESCE(md.fecha, DATE(p.fecha_pedido)) AS fecha_grupo,
                        dp.nombre_producto,
                        COUNT(*) AS total,
                        MIN(pr.id_producto) AS id_producto,
                        MIN(pr.disponible)  AS disponible
                     FROM detalle_pedido dp
                     INNER JOIN pedido_menus pm ON dp.id_pedido_menu = pm.id_pedido_menu
                     INNER JOIN pedidos p ON pm.id_pedido = p.id_pedido
                     INNER JOIN productos pr ON pr.id_producto = dp.id_producto
                     LEFT JOIN menu_dia md ON md.id_menu = pr.id_menu
                     WHERE dp.categoria = 'Plato fuerte' AND p.es_prueba = 0
                     GROUP BY COALESCE(md.fecha, DATE(p.fecha_pedido)), dp.nombre_producto
                     ORDER BY COALESCE(md.fecha, DATE(p.fecha_pedido)) DESC, dp.nombre_producto ASC";
$stmtResumenPlatos = $conexion->prepare($sqlResumenPlatos);
$stmtResumenPlatos->execute();
$resumenPlatosDB = $stmtResumenPlatos->fetchAll(PDO::FETCH_ASSOC);

$resumenPlatosPorFecha = [];

foreach ($resumenPlatosDB as $fila) {
    $fechaGrupo = $fila["fecha_grupo"];
    $resumenPlatosPorFecha[$fechaGrupo][] = [
        "nombre_producto" => $fila["nombre_producto"],
        "total"           => (int)$fila["total"],
        "id_producto"     => (int)$fila["id_producto"],
        "disponible"      => (int)$fila["disponible"],
    ];
}

/*
    Obtener resumen de complementos por fecha
*/
$sqlResumenComplementos = "SELECT
                              COALESCE(md.fecha, DATE(p.fecha_pedido)) AS fecha_grupo,
                              dp.nombre_producto,
                              COUNT(*) AS total,
                              MIN(pr.id_producto) AS id_producto,
                              MIN(pr.disponible)  AS disponible
                           FROM detalle_pedido dp
                           INNER JOIN pedido_menus pm ON dp.id_pedido_menu = pm.id_pedido_menu
                           INNER JOIN pedidos p ON pm.id_pedido = p.id_pedido
                           INNER JOIN productos pr ON pr.id_producto = dp.id_producto
                           LEFT JOIN menu_dia md ON md.id_menu = pr.id_menu
                           WHERE dp.categoria IN ('Complemento', 'Agua') AND p.es_prueba = 0
                           GROUP BY COALESCE(md.fecha, DATE(p.fecha_pedido)), dp.nombre_producto
                           ORDER BY COALESCE(md.fecha, DATE(p.fecha_pedido)) DESC, dp.nombre_producto ASC";
$stmtResumenComplementos = $conexion->prepare($sqlResumenComplementos);
$stmtResumenComplementos->execute();
$resumenComplementosDB = $stmtResumenComplementos->fetchAll(PDO::FETCH_ASSOC);

$resumenComplementosPorFecha = [];

foreach ($resumenComplementosDB as $fila) {
    $fechaGrupo = $fila["fecha_grupo"];
    $resumenComplementosPorFecha[$fechaGrupo][$fila["nombre_producto"]] = [
        "nombre_producto" => $fila["nombre_producto"],
        "total"           => (int)$fila["total"],
        "id_producto"     => (int)$fila["id_producto"],
        "disponible"      => (int)$fila["disponible"],
    ];
}

// Sumar aguas de pedido_extras
$sqlAguasExtra = "SELECT
                      COALESCE(mi.fecha_menu, DATE(p.fecha_pedido)) AS fecha_grupo,
                      pe.nombre AS nombre_producto,
                      SUM(pe.cantidad) AS total
                  FROM pedido_extras pe
                  INNER JOIN pedidos p ON pe.id_pedido = p.id_pedido
                  LEFT JOIN (
                      SELECT pm2.id_pedido, MIN(md2.fecha) AS fecha_menu
                      FROM pedido_menus pm2
                      INNER JOIN detalle_pedido dp2 ON dp2.id_pedido_menu = pm2.id_pedido_menu
                      INNER JOIN productos pr2 ON pr2.id_producto = dp2.id_producto
                      INNER JOIN menu_dia md2 ON md2.id_menu = pr2.id_menu
                      GROUP BY pm2.id_pedido
                  ) AS mi ON mi.id_pedido = p.id_pedido
                  WHERE pe.categoria = 'Agua' AND p.es_prueba = 0
                  GROUP BY fecha_grupo, pe.nombre
                  ORDER BY fecha_grupo DESC, pe.nombre ASC";
$stmtAguasExtra = $conexion->prepare($sqlAguasExtra);
$stmtAguasExtra->execute();
foreach ($stmtAguasExtra->fetchAll(PDO::FETCH_ASSOC) as $fila) {
    $fechaGrupo = $fila["fecha_grupo"];
    $nombre     = $fila["nombre_producto"];
    if (isset($resumenComplementosPorFecha[$fechaGrupo][$nombre])) {
        $resumenComplementosPorFecha[$fechaGrupo][$nombre]["total"] += (int)$fila["total"];
    } else {
        $resumenComplementosPorFecha[$fechaGrupo][$nombre] = [
            "nombre_producto" => $nombre,
            "total"           => (int)$fila["total"],
        ];
    }
}

// Re-indexar a array simple ordenado por nombre
foreach ($resumenComplementosPorFecha as $fecha => $items) {
    ksort($resumenComplementosPorFecha[$fecha]);
    $resumenComplementosPorFecha[$fecha] = array_values($resumenComplementosPorFecha[$fecha]);
}

/*
    Totales por fecha (comidas = suma de platos fuertes; pedidos = conteo de pedidos)
*/
$totalComidasPorFecha = [];
foreach ($resumenPlatosPorFecha as $fecha => $platos) {
    $totalComidasPorFecha[$fecha] = array_sum(array_column($platos, "total"));
}

/*
    Resumen de ruta: menús por ubicación + horario + fecha
*/
$sqlResumenRuta = "SELECT
    COALESCE(mi.fecha_menu, DATE(p.fecha_pedido)) AS fecha_grupo,
    u.nombre_ubicacion,
    h.hora_entrega,
    SUM(mc.num_menus) AS total_menus
FROM pedidos p
INNER JOIN horarios_ubicacion h ON p.id_horario = h.id_horario
INNER JOIN ubicaciones u ON h.id_ubicacion = u.id_ubicacion
LEFT JOIN (
    SELECT id_pedido, COUNT(*) AS num_menus FROM pedido_menus GROUP BY id_pedido
) AS mc ON mc.id_pedido = p.id_pedido
LEFT JOIN (
    SELECT pm2.id_pedido, MIN(md2.fecha) AS fecha_menu
    FROM pedido_menus pm2
    INNER JOIN detalle_pedido dp2 ON dp2.id_pedido_menu = pm2.id_pedido_menu
    INNER JOIN productos pr2 ON pr2.id_producto = dp2.id_producto
    INNER JOIN menu_dia md2 ON md2.id_menu = pr2.id_menu
    GROUP BY pm2.id_pedido
) AS mi ON mi.id_pedido = p.id_pedido
WHERE p.es_prueba = 0 AND p.estado != 'Cancelado'
GROUP BY fecha_grupo, u.nombre_ubicacion, h.hora_entrega
ORDER BY fecha_grupo DESC, h.hora_entrega ASC, u.nombre_ubicacion ASC";

$stmtResumenRuta = $conexion->prepare($sqlResumenRuta);
$stmtResumenRuta->execute();
$resumenRutaDB = $stmtResumenRuta->fetchAll(PDO::FETCH_ASSOC);

/*
    Extras por ubicación + horario + fecha
*/
$sqlExtrasRuta = "SELECT
    COALESCE(mi.fecha_menu, DATE(p.fecha_pedido)) AS fecha_grupo,
    u.nombre_ubicacion,
    h.hora_entrega,
    pe.nombre AS nombre_extra,
    SUM(pe.cantidad) AS total_cantidad
FROM pedido_extras pe
INNER JOIN pedidos p ON pe.id_pedido = p.id_pedido
INNER JOIN horarios_ubicacion h ON p.id_horario = h.id_horario
INNER JOIN ubicaciones u ON h.id_ubicacion = u.id_ubicacion
LEFT JOIN (
    SELECT pm2.id_pedido, MIN(md2.fecha) AS fecha_menu
    FROM pedido_menus pm2
    INNER JOIN detalle_pedido dp2 ON dp2.id_pedido_menu = pm2.id_pedido_menu
    INNER JOIN productos pr2 ON pr2.id_producto = dp2.id_producto
    INNER JOIN menu_dia md2 ON md2.id_menu = pr2.id_menu
    GROUP BY pm2.id_pedido
) AS mi ON mi.id_pedido = p.id_pedido
WHERE p.es_prueba = 0 AND p.estado != 'Cancelado'
GROUP BY fecha_grupo, u.nombre_ubicacion, h.hora_entrega, pe.nombre
ORDER BY fecha_grupo DESC, h.hora_entrega ASC, u.nombre_ubicacion ASC, pe.nombre ASC";

$stmtExtrasRuta = $conexion->prepare($sqlExtrasRuta);
$stmtExtrasRuta->execute();
$extrasRutaDB = $stmtExtrasRuta->fetchAll(PDO::FETCH_ASSOC);

// Indexar: [fecha][ubicacion||hora] = ['nombre_ubicacion', 'hora_entrega', 'total_menus', 'extras']
$resumenRutaPorFecha = [];
foreach ($resumenRutaDB as $fila) {
    $fecha = $fila["fecha_grupo"];
    $key   = $fila["nombre_ubicacion"] . "||" . $fila["hora_entrega"];
    $resumenRutaPorFecha[$fecha][$key] = [
        "nombre_ubicacion" => $fila["nombre_ubicacion"],
        "hora_entrega"     => $fila["hora_entrega"],
        "total_menus"      => (int)$fila["total_menus"],
        "extras"           => [],
    ];
}
foreach ($extrasRutaDB as $fila) {
    $fecha = $fila["fecha_grupo"];
    $key   = $fila["nombre_ubicacion"] . "||" . $fila["hora_entrega"];
    if (isset($resumenRutaPorFecha[$fecha][$key])) {
        $resumenRutaPorFecha[$fecha][$key]["extras"][] = [
            "nombre"   => $fila["nombre_extra"],
            "cantidad" => (int)$fila["total_cantidad"],
        ];
    }
}

/*
    Opciones para filtros
*/
$ubicacionesFiltro = array_values(array_unique(array_column($pedidos, "nombre_ubicacion")));
sort($ubicacionesFiltro);

$horasFiltro = array_values(array_unique(array_map(function ($p) {
    return $p["hora_entrega"];
}, $pedidos)));
sort($horasFiltro);

/*
    Funciones auxiliares
*/
function obtenerClaseEstadoPago($estadoPago)
{
    switch ($estadoPago) {
        case "Pendiente de validación":
            return "estado estado-revision";
        case "Pago en efectivo":
            return "estado estado-efectivo";
        case "Pagado":
            return "estado estado-pagado";
        default:
            return "estado estado-pendiente";
    }
}

function obtenerTextoEstadoPago($estadoPago)
{
    switch ($estadoPago) {
        case "Pendiente de validación":
            return "Pago en revisión";
        case "Pago en efectivo":
            return "Pago al recibir";
        case "Pagado":
            return "Pago confirmado";
        default:
            return $estadoPago ?: "Pendiente";
    }
}

function formatearFechaBonita($fecha)
{
    $timestamp = strtotime($fecha);

    $dias = [
        'Sunday' => 'domingo',
        'Monday' => 'lunes',
        'Tuesday' => 'martes',
        'Wednesday' => 'miércoles',
        'Thursday' => 'jueves',
        'Friday' => 'viernes',
        'Saturday' => 'sábado'
    ];

    $meses = [
        '01' => 'enero',
        '02' => 'febrero',
        '03' => 'marzo',
        '04' => 'abril',
        '05' => 'mayo',
        '06' => 'junio',
        '07' => 'julio',
        '08' => 'agosto',
        '09' => 'septiembre',
        '10' => 'octubre',
        '11' => 'noviembre',
        '12' => 'diciembre'
    ];

    $diaSemana = $dias[date('l', $timestamp)] ?? date('l', $timestamp);
    $dia = date('j', $timestamp);
    $mes = $meses[date('m', $timestamp)] ?? date('m', $timestamp);
    $anio = date('Y', $timestamp);

    return ucfirst($diaSemana) . " " . $dia . " de " . $mes . " de " . $anio;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pedidos | Restaurante Zabisu</title>
    <link rel="icon" type="image/png" href="../assets/img/LOGO_NARA.png">
    <link rel="stylesheet" href="../assets/css/styles.css?v=5">
    <style>
        .tarjeta-produccion[data-id-producto] {
            cursor: pointer;
            user-select: none;
            transition: opacity .15s, transform .1s;
        }
        .tarjeta-produccion[data-id-producto]:hover {
            opacity: .75;
            transform: scale(.97);
        }
        .tarjeta-produccion--agotado {
            position: relative;
            opacity: .45;
        }
        .tarjeta-produccion--agotado::after {
            content: 'AGOTADO';
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(220, 38, 38, .12);
            border: 1px solid rgba(220, 38, 38, .35);
            border-radius: inherit;
            font-size: 9px;
            font-weight: 900;
            letter-spacing: 2px;
            color: #ef4444;
        }
        .tarjeta-produccion--agotado .tarjeta-produccion__nombre,
        .tarjeta-produccion--agotado .tarjeta-produccion__total {
            text-decoration: line-through;
        }
    </style>
</head>
<body>

<div class="contenedor">
    <div class="hero-zabisu">
        <div class="hero-zabisu__glow"></div>
        <div class="hero-zabisu__contenido">
            <p class="hero-zabisu__eyebrow">RESTAURANTE</p>
            <h1 class="hero-zabisu__titulo">Panel de pedidos</h1>
            <p class="hero-zabisu__texto">
                Visualiza, revisa y administra los pedidos registrados.
            </p>
            <a href="panel_general.php" class="btn-volver-panel">← Panel general</a>

            <form method="POST" action="toggle_pedidos.php" style="display:inline;">
                <button type="submit" class="btn-toggle-pedidos <?php echo $recibiendoPedidos ? 'btn-toggle-pedidos--activo' : 'btn-toggle-pedidos--pausado'; ?>">
                    <?php if ($recibiendoPedidos): ?>
                        ✅ Recibiendo pedidos — Pausar
                    <?php else: ?>
                        ⏸ Pedidos pausados — Reanudar
                    <?php endif; ?>
                </button>
            </form>
            <button type="button" id="btn-toggle-notificacion" class="btn-notificar-ruta">
                📍 Notificar llegada al punto
            </button>
            <button type="button" id="btn-toggle-resumen-ruta" class="btn-notificar-ruta btn-resumen-ruta">
                🚚 Ver resumen de ruta
            </button>
        </div>
    </div>

    <!-- ══ PANEL NOTIFICACIÓN DE RUTA ══════════════════════════ -->
    <div class="bloque-formulario bloque-notificacion-ruta" id="bloque-notificacion-ruta" style="display:none;">
        <h2>📍 Notificar llegada al punto</h2>
        <p class="nota-formulario">
            Envía un WhatsApp automático a todos los clientes de una ubicación y horario para avisarles que su pedido ya llegó.
        </p>

        <div class="notificacion-ruta__campos">
            <div class="notificacion-ruta__campo">
                <label for="notif-fecha">Fecha</label>
                <input type="date" id="notif-fecha" value="<?php echo date('Y-m-d'); ?>">
            </div>
            <div class="notificacion-ruta__campo">
                <label for="notif-ubicacion">Ubicación</label>
                <select id="notif-ubicacion">
                    <option value="">Selecciona una ubicación</option>
                    <?php foreach ($ubicacionesFiltro as $ub): ?>
                        <option value="<?php echo htmlspecialchars($ub); ?>">
                            <?php echo htmlspecialchars($ub); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="notificacion-ruta__campo">
                <label for="notif-hora">Horario</label>
                <select id="notif-hora">
                    <option value="">Selecciona un horario</option>
                    <?php foreach ($horasFiltro as $hora): ?>
                        <option value="<?php echo htmlspecialchars($hora); ?>">
                            <?php echo date("g:i A", strtotime($hora)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <button type="button" id="btn-enviar-notificacion" class="btn-principal" style="margin-top:20px;">
            Notificar llegada por WhatsApp
        </button>

        <div id="notificacion-resultado" class="notificacion-resultado" style="display:none;"></div>
    </div>

    <!-- ══ PANEL RESUMEN DE RUTA ════════════════════════════════ -->
    <?php $puntosHoy = $resumenRutaPorFecha[date("Y-m-d")] ?? []; ?>
    <div class="bloque-formulario bloque-resumen-ruta" id="bloque-resumen-ruta" style="display:none;">
        <div class="ruta-panel-header">
            <div>
                <h2>🚚 Resumen de ruta</h2>
                <p class="nota-formulario" style="margin:4px 0 0;">
                    Pedidos de hoy — <?php echo htmlspecialchars(formatearFechaBonita(date("Y-m-d"))); ?>
                </p>
            </div>
            <?php if (!empty($puntosHoy)): ?>
            <div class="ruta-panel-total">
                <span class="ruta-panel-total__num"><?php echo array_sum(array_column($puntosHoy, "total_menus")); ?></span>
                <span class="ruta-panel-total__label">menús en total</span>
            </div>
            <?php endif; ?>
        </div>

        <?php if (empty($puntosHoy)): ?>
            <p class="nota-formulario" style="margin-top:16px;">No hay pedidos registrados para hoy.</p>
        <?php else: ?>
        <div class="tabla-pedidos-wrapper" style="margin-top:18px;">
            <table class="tabla-pedidos ruta-tabla">
                <thead>
                    <tr>
                        <th>Ubicación</th>
                        <th>Hora</th>
                        <th style="text-align:center;">Menús</th>
                        <th>Extras</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($puntosHoy as $punto): ?>
                    <tr>
                        <td>
                            <strong class="ruta-tabla__ubicacion">
                                📍 <?php echo htmlspecialchars($punto["nombre_ubicacion"]); ?>
                            </strong>
                        </td>
                        <td>
                            <span class="ruta-tabla__hora">
                                <?php echo date("g:i A", strtotime($punto["hora_entrega"])); ?>
                            </span>
                        </td>
                        <td style="text-align:center;">
                            <span class="ruta-tabla__menus-badge">
                                <?php echo $punto["total_menus"]; ?>
                                <span class="ruta-tabla__menus-label"><?php echo $punto["total_menus"] === 1 ? "menú" : "menús"; ?></span>
                            </span>
                        </td>
                        <td>
                            <?php if (!empty($punto["extras"])): ?>
                                <div class="ruta-tabla__extras">
                                    <?php foreach ($punto["extras"] as $extra): ?>
                                    <span class="ruta-card__extra-chip">
                                        +<?php echo $extra["cantidad"]; ?> <?php echo htmlspecialchars($extra["nombre"]); ?>
                                    </span>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <span class="texto-secundario">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <?php if (!empty($pedidos)): ?>
    <div class="bloque-formulario filtros-pedidos">
        <div class="filtros-pedidos__fila">
            <div class="filtros-pedidos__busqueda">
                <input type="text" id="filtro-busqueda" placeholder="Buscar por folio o ID…" autocomplete="off">
            </div>
            <div class="filtros-pedidos__selects">
                <select id="filtro-ubicacion">
                    <option value="">Todas las ubicaciones</option>
                    <?php foreach ($ubicacionesFiltro as $ub): ?>
                        <option value="<?php echo htmlspecialchars($ub); ?>">
                            <?php echo htmlspecialchars($ub); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select id="filtro-hora">
                    <option value="">Todos los horarios</option>
                    <?php foreach ($horasFiltro as $hora): ?>
                        <option value="<?php echo htmlspecialchars($hora); ?>">
                            <?php echo date("g:i A", strtotime($hora)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="button" id="btn-limpiar-filtros" class="btn-limpiar-filtros">Limpiar</button>
            </div>
        </div>
        <p id="filtros-sin-resultados" class="filtros-sin-resultados" style="display:none;">
            No se encontraron pedidos con los filtros aplicados.
        </p>
    </div>
    <?php endif; ?>

    <?php if ($modoPrueba && !empty($pedidosPrueba)): ?>
    <div class="bloque-formulario">
        <div class="cabecera-modulo">
            <h2>🧪 Pedidos de prueba</h2>
            <button type="button" id="btn-toggle-prueba" class="btn-tabla">Mostrar</button>
        </div>
        <div id="seccion-prueba" style="display:none;">
            <p class="nota-formulario" style="margin-bottom:16px;">
                Estos pedidos fueron generados con el modo prueba activo. No afectan las estadísticas reales.
            </p>
            <div class="tabla-pedidos-wrapper">
                <table class="tabla-pedidos">
                    <thead>
                        <tr>
                            <th>Folio</th>
                            <th>Cliente</th>
                            <th>Ubicación</th>
                            <th>Hora</th>
                            <th>Pago</th>
                            <th>Total</th>
                            <th>Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pedidosPrueba as $pedido): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($pedido["folio"]); ?></strong></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($pedido["nombre_cliente"]); ?></strong><br>
                                    <span class="texto-secundario"><?php echo htmlspecialchars($pedido["telefono"]); ?></span>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($pedido["nombre_ubicacion"]); ?><br>
                                    <span class="texto-secundario"><?php echo ucfirst(htmlspecialchars($pedido["tipo_ubicacion"])); ?></span>
                                </td>
                                <td><?php echo date("g:i A", strtotime($pedido["hora_entrega"])); ?></td>
                                <td>
                                    <span class="<?php echo obtenerClaseEstadoPago($pedido["estado_pago"]); ?>">
                                        <?php echo htmlspecialchars(obtenerTextoEstadoPago($pedido["estado_pago"])); ?>
                                    </span>
                                </td>
                                <td><strong>$<?php echo number_format((float)$pedido["total"], 2); ?></strong></td>
                                <td>
                                    <a class="btn-tabla" href="ver_pedido.php?id=<?php echo urlencode($pedido["id_pedido"]); ?>">
                                        Ver detalle
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="bloque-formulario">
        <h2>Pedidos registrados</h2>

        <?php if (empty($pedidosPorFecha)): ?>
            <p class="nota-formulario">No hay pedidos registrados por el momento.</p>
        <?php else: ?>
            <?php foreach ($pedidosPorFecha as $fecha => $listaPedidos): ?>
                <div class="grupo-fecha-pedidos">
                    <div class="cabecera-fecha-pedidos">
                        <h3 class="titulo-fecha-pedidos">
                            <?php echo htmlspecialchars(formatearFechaBonita($fecha)); ?>
                        </h3>
                        <div class="stats-dia">
                            <div class="stat-dia">
                                <span class="stat-dia__numero">
                                    <?php echo $totalComidasPorFecha[$fecha] ?? 0; ?>
                                </span>
                                <span class="stat-dia__etiqueta">
                                    <?php echo ($totalComidasPorFecha[$fecha] ?? 0) === 1 ? "comida" : "comidas"; ?>
                                </span>
                            </div>
                            <div class="stat-dia stat-dia--secundario">
                                <span class="stat-dia__numero">
                                    <?php echo count($listaPedidos); ?>
                                </span>
                                <span class="stat-dia__etiqueta">
                                    <?php echo count($listaPedidos) === 1 ? "pedido" : "pedidos"; ?>
                                </span>
                            </div>
                        </div>
                    </div>

                    <?php if (!empty($resumenPlatosPorFecha[$fecha])): ?>
                        <div class="resumen-produccion-dia">
                            <h4 class="resumen-produccion-dia__titulo">Producción de platos fuertes</h4>

                            <div class="resumen-produccion-dia__grid">
                                <?php foreach ($resumenPlatosPorFecha[$fecha] as $plato): ?>
                                    <?php
                                        $esHoy       = ($fecha === date('Y-m-d'));
                                        $idProd      = (int)($plato["id_producto"] ?? 0);
                                        $disponible  = (int)($plato["disponible"] ?? 1);
                                        $claseExtra  = $disponible ? '' : 'tarjeta-produccion--agotado';
                                    ?>
                                    <div class="tarjeta-produccion <?php echo $claseExtra; ?>"
                                         <?php if ($esHoy && $idProd): ?>
                                             data-id-producto="<?php echo $idProd; ?>"
                                             data-disponible="<?php echo $disponible; ?>"
                                             data-nombre="<?php echo htmlspecialchars($plato['nombre_producto']); ?>"
                                         <?php endif; ?>>
                                        <span class="tarjeta-produccion__nombre">
                                            <?php echo htmlspecialchars($plato["nombre_producto"]); ?>
                                        </span>
                                        <strong class="tarjeta-produccion__total">
                                            <?php echo (int)$plato["total"]; ?>
                                        </strong>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($resumenComplementosPorFecha[$fecha])): ?>
                        <div class="resumen-produccion-dia resumen-produccion-dia--secundario">
                            <h4 class="resumen-produccion-dia__titulo">Producción de complementos y aguas</h4>

                            <div class="resumen-produccion-dia__grid">
                                <?php foreach ($resumenComplementosPorFecha[$fecha] as $complemento): ?>
                                    <?php
                                        $esHoy      = ($fecha === date('Y-m-d'));
                                        $idProd     = (int)($complemento["id_producto"] ?? 0);
                                        $disponible = (int)($complemento["disponible"] ?? 1);
                                        $claseExtra = $disponible ? '' : 'tarjeta-produccion--agotado';
                                    ?>
                                    <div class="tarjeta-produccion <?php echo $claseExtra; ?>"
                                         <?php if ($esHoy && $idProd): ?>
                                             data-id-producto="<?php echo $idProd; ?>"
                                             data-disponible="<?php echo $disponible; ?>"
                                             data-nombre="<?php echo htmlspecialchars($complemento['nombre_producto']); ?>"
                                         <?php endif; ?>>
                                        <span class="tarjeta-produccion__nombre">
                                            <?php echo htmlspecialchars($complemento["nombre_producto"]); ?>
                                        </span>
                                        <strong class="tarjeta-produccion__total">
                                            <?php echo (int)$complemento["total"]; ?>
                                        </strong>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="tabla-pedidos-wrapper">
                        <table class="tabla-pedidos">
                            <thead>
                                <tr>
                                    <th>Folio</th>
                                    <th>Cliente</th>
                                    <th>Ubicación</th>
                                    <th>Hora</th>
                                    <th>Pago</th>
                                    <th>Total</th>
                                    <th>Acción</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($listaPedidos as $pedido): ?>
                                    <tr id="pedido-<?php echo (int)$pedido["id_pedido"]; ?>"
                                        class="<?php echo ((int)$pedido["visto"] === 0) ? 'fila-pedido-nuevo' : 'fila-pedido-visto'; ?> fila-pedido"
                                        data-folio="<?php echo strtolower(htmlspecialchars($pedido["folio"])); ?>"
                                        data-id="<?php echo (int)$pedido["id_pedido"]; ?>"
                                        data-ubicacion="<?php echo htmlspecialchars($pedido["nombre_ubicacion"]); ?>"
                                        data-hora="<?php echo htmlspecialchars($pedido["hora_entrega"]); ?>"
                                        data-telefono="<?php echo htmlspecialchars($pedido["telefono"] ?? ''); ?>"
                                        data-nombre="<?php echo htmlspecialchars($pedido["nombre_cliente"] ?? ''); ?>"
                                        data-total="<?php echo number_format((float)$pedido["total"], 2); ?>">
                                        <td>
                                            <div class="folio-pedido">
                                                <strong><?php echo htmlspecialchars($pedido["folio"]); ?></strong>
                                                <?php if ((int)$pedido["visto"] === 0): ?>
                                                    <span class="badge-nuevo">Nuevo</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($pedido["nombre_cliente"]); ?></strong><br>
                                            <span class="texto-secundario"><?php echo htmlspecialchars($pedido["telefono"]); ?></span>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($pedido["nombre_ubicacion"]); ?><br>
                                            <span class="texto-secundario">
                                                <?php echo ucfirst(htmlspecialchars($pedido["tipo_ubicacion"])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo date("g:i A", strtotime($pedido["hora_entrega"])); ?>
                                        </td>
                                        <td>
                                            <span class="<?php echo obtenerClaseEstadoPago($pedido["estado_pago"]); ?>">
                                                <?php echo htmlspecialchars(obtenerTextoEstadoPago($pedido["estado_pago"])); ?>
                                            </span>
                                            <br>
                                            <span class="texto-secundario">
                                                <?php echo htmlspecialchars($pedido["metodo_pago"]); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <strong>$<?php echo number_format((float)$pedido["total"], 2); ?></strong>
                                        </td>
                                        <td>
                                            <div class="acciones-fila">
                                                <a class="btn-tabla" href="ver_pedido.php?id=<?php echo urlencode($pedido["id_pedido"]); ?>">
                                                    Ver detalle
                                                </a>
                                                <?php if ($pedido["metodo_pago"] === "Transferencia" && $pedido["estado_pago"] === "Pendiente de validación"): ?>
                                                    <a class="btn-tabla btn-tabla--confirmar" href="confirmar_pago.php?id=<?php echo (int)$pedido["id_pedido"]; ?>">
                                                        Confirmar pago
                                                    </a>
                                                <?php endif; ?>
                                                <?php if (in_array($pedido["metodo_pago"], ["Efectivo", "Tarjeta"]) || $pedido["estado_pago"] === "Pagado"): ?>
                                                    <?php if ((int)$pedido["num_menus"] > 1): ?>
                                                        <button class="btn-tabla btn-tabla--imprimir btn-tabla--imprimir-sep" data-id="<?php echo (int)$pedido["id_pedido"]; ?>">
                                                            Imprimir separado
                                                        </button>
                                                    <?php else: ?>
                                                        <button class="btn-tabla btn-tabla--imprimir" data-id="<?php echo (int)$pedido["id_pedido"]; ?>">
                                                            Imprimir ticket
                                                        </button>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                                <?php if (!empty($pedido["comprobante_pago"])): ?>
                                                    <?php $rutaComp = __DIR__ . "/../uploads/comprobantes/" . $pedido["comprobante_pago"]; ?>
                                                    <?php if (file_exists($rutaComp)): ?>
                                                        <a class="btn-tabla btn-tabla--comprobante" target="_blank" href="../uploads/comprobantes/<?php echo rawurlencode($pedido["comprobante_pago"]); ?>">
                                                            Ver comprobante
                                                        </a>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<iframe id="iframe-ticket" style="display:none;"></iframe>

<audio id="audio-alerta-pedido" preload="auto">
    <source src="../assets/audio/notificacion.mp3" type="audio/mpeg">
</audio>

<script>
document.addEventListener("DOMContentLoaded", function () {

    // ============================================================
    // HELPERS WHATSAPP
    // ============================================================
    function normalizarTelefono(tel) {
        if (!tel) return "";
        var digitos = tel.replace(/\D/g, "");
        if (digitos.length === 10) digitos = "52" + digitos;
        return digitos;
    }

    function formatHoraWA(hora24) {
        if (!hora24) return hora24;
        var partes = hora24.split(":");
        var h = parseInt(partes[0], 10);
        var m = partes[1] || "00";
        var ampm = h >= 12 ? "PM" : "AM";
        if (h > 12) h -= 12;
        if (h === 0) h = 12;
        return h + ":" + m + " " + ampm;
    }

    function abrirWhatsAppConfirmacion(fila) {
        if (!fila) return;
        var tel      = normalizarTelefono(fila.dataset.telefono || "");
        var nombre   = fila.dataset.nombre    || "";
        var folio    = (fila.dataset.folio    || "").toUpperCase();
        var ubicacion = fila.dataset.ubicacion || "";
        var horaRaw  = fila.dataset.hora      || "";
        var total    = fila.dataset.total     || "";
        if (!tel) return;
        var texto = encodeURIComponent(
            "Hola *" + nombre + "* 👋 Tu pedido Zabisu está confirmado ✅\n" +
            "*Folio:* " + folio + "\n" +
            "*Punto de entrega:* " + ubicacion + "\n" +
            "*Horario:* " + formatHoraWA(horaRaw) + "\n" +
            "*Total:* $" + total + "\n" +
            "¡Gracias por tu preferencia! 🍱"
        );
        window.open("https://wa.me/" + tel + "?text=" + texto, "_blank");
    }

    // ============================================================
    // TOGGLE PEDIDOS DE PRUEBA
    // ============================================================
    var btnTogglePrueba = document.getElementById("btn-toggle-prueba");
    var seccionPrueba   = document.getElementById("seccion-prueba");

    if (btnTogglePrueba && seccionPrueba) {
        btnTogglePrueba.addEventListener("click", function () {
            var visible = seccionPrueba.style.display !== "none";
            seccionPrueba.style.display = visible ? "none" : "block";
            btnTogglePrueba.textContent = visible ? "Mostrar" : "Ocultar";
        });
    }

    // ============================================================
    // FILTROS DE PEDIDOS
    // ============================================================
    const filtroBusqueda   = document.getElementById("filtro-busqueda");
    const filtroUbicacion  = document.getElementById("filtro-ubicacion");
    const filtroHora       = document.getElementById("filtro-hora");
    const btnLimpiar       = document.getElementById("btn-limpiar-filtros");
    const sinResultados    = document.getElementById("filtros-sin-resultados");

    function aplicarFiltros() {
        const busqueda  = (filtroBusqueda  ? filtroBusqueda.value.toLowerCase().trim()  : "");
        const ubicacion = (filtroUbicacion ? filtroUbicacion.value                       : "");
        const hora      = (filtroHora      ? filtroHora.value                            : "");

        let totalVisibles = 0;

        document.querySelectorAll(".grupo-fecha-pedidos").forEach(function (grupo) {
            let visiblesEnGrupo = 0;

            grupo.querySelectorAll(".fila-pedido").forEach(function (fila) {
                const folio     = fila.dataset.folio     || "";
                const id        = String(fila.dataset.id || "");
                const filUb     = fila.dataset.ubicacion || "";
                const filHora   = fila.dataset.hora      || "";

                const coincideBusqueda  = !busqueda  || folio.includes(busqueda) || id.includes(busqueda);
                const coincideUbicacion = !ubicacion || filUb  === ubicacion;
                const coincideHora      = !hora      || filHora === hora;

                const visible = coincideBusqueda && coincideUbicacion && coincideHora;
                fila.style.display = visible ? "" : "none";
                if (visible) { visiblesEnGrupo++; totalVisibles++; }
            });

            grupo.style.display = visiblesEnGrupo > 0 ? "" : "none";
        });

        if (sinResultados) {
            sinResultados.style.display = totalVisibles === 0 ? "block" : "none";
        }
    }

    if (filtroBusqueda)  filtroBusqueda.addEventListener("input",  aplicarFiltros);
    if (filtroUbicacion) filtroUbicacion.addEventListener("change", aplicarFiltros);
    if (filtroHora)      filtroHora.addEventListener("change",      aplicarFiltros);

    if (btnLimpiar) {
        btnLimpiar.addEventListener("click", function () {
            if (filtroBusqueda)  filtroBusqueda.value  = "";
            if (filtroUbicacion) filtroUbicacion.value = "";
            if (filtroHora)      filtroHora.value       = "";
            aplicarFiltros();
        });
    }

    // ============================================================
    // ALERTAS DE NUEVOS PEDIDOS
    // ============================================================
    let totalNuevosActual = <?php echo (int)$totalNuevosActual; ?>;
    const audioAlerta = document.getElementById("audio-alerta-pedido");
    let audioHabilitado = false;

    function habilitarAudio() {
        audioHabilitado = true;
        document.removeEventListener("click", habilitarAudio);
        document.removeEventListener("keydown", habilitarAudio);
    }

    document.addEventListener("click", habilitarAudio);
    document.addEventListener("keydown", habilitarAudio);

    function revisarNuevosPedidos() {
        fetch("check_nuevos_pedidos.php", {
            method: "GET",
            cache: "no-store"
        })
        .then(response => response.json())
        .then(data => {
            const totalNuevosServidor = parseInt(data.total_nuevos || 0, 10);

            if (totalNuevosServidor > totalNuevosActual) {
                if (audioHabilitado && audioAlerta) {
                    audioAlerta.currentTime = 0;
                    audioAlerta.play().catch(() => {});
                }

                setTimeout(function () {
                    window.location.reload();
                }, 1200);
            }

            totalNuevosActual = totalNuevosServidor;
        })
        .catch(error => {
            console.error("Error al revisar nuevos pedidos:", error);
        });
    }

    setInterval(revisarNuevosPedidos, 10000);

    // Restaurar posición de scroll al volver de confirmar pago
    <?php if (!empty($_GET['scroll'])): ?>
    var scrollGuardado = sessionStorage.getItem("pedidos_scroll");
    if (scrollGuardado !== null) {
        window.scrollTo(0, parseInt(scrollGuardado, 10));
        sessionStorage.removeItem("pedidos_scroll");
    }
    <?php endif; ?>

    // Guardar posición de scroll al confirmar pago
    document.querySelectorAll(".btn-tabla--confirmar").forEach(function (btn) {
        btn.addEventListener("click", function () {
            sessionStorage.setItem("pedidos_scroll", window.scrollY);
        });
    });

    // Imprimir ticket directo sin abrir nueva pestaña
    var iframeTicket = document.getElementById("iframe-ticket");

    document.querySelectorAll(".btn-tabla--imprimir").forEach(function (btn) {
        btn.addEventListener("click", function () {
            var id = btn.dataset.id;
            var fila = btn.closest("tr");

            // Marcar fila como vista visualmente
            if (fila) {
                fila.classList.remove("fila-pedido-nuevo");
                fila.classList.add("fila-pedido-visto");
                var badge = fila.querySelector(".badge-nuevo");
                if (badge) badge.remove();
            }

            // Llamar imprimir_y_notificar para marcar visto (correo desactivado)
            fetch("imprimir_y_notificar.php?id=" + id, { cache: "no-store" });

            // Cargar ticket en iframe oculto y disparar print
            iframeTicket.onload = function () {
                iframeTicket.contentWindow.focus();
                iframeTicket.contentWindow.print();
            };
            iframeTicket.src = "ticket.php?id=" + id;
        });
    });

    document.querySelectorAll(".btn-tabla--imprimir-sep").forEach(function (btn) {
        btn.addEventListener("click", function () {
            var id = btn.dataset.id;
            var fila = btn.closest("tr");
            if (fila) {
                fila.classList.remove("fila-pedido-nuevo");
                fila.classList.add("fila-pedido-visto");
                var badge = fila.querySelector(".badge-nuevo");
                if (badge) badge.remove();
            }
            fetch("imprimir_y_notificar.php?id=" + id, { cache: "no-store" });
            iframeTicket.onload = function () {
                iframeTicket.contentWindow.focus();
                iframeTicket.contentWindow.print();
            };
            iframeTicket.src = "ticket.php?id=" + id + "&separado=1";
        });
    });

    // ============================================================
    // NOTIFICACIÓN DE LLEGADA AL PUNTO
    // ============================================================
    const btnToggleNotif   = document.getElementById("btn-toggle-notificacion");
    const bloqueNotif      = document.getElementById("bloque-notificacion-ruta");
    const btnEnviarNotif   = document.getElementById("btn-enviar-notificacion");
    const resultadoNotif   = document.getElementById("notificacion-resultado");
    const notifFecha       = document.getElementById("notif-fecha");
    const notifUbicacion   = document.getElementById("notif-ubicacion");
    const notifHora        = document.getElementById("notif-hora");

    if (btnToggleNotif && bloqueNotif) {
        btnToggleNotif.addEventListener("click", function () {
            const visible = bloqueNotif.style.display !== "none";
            bloqueNotif.style.display = visible ? "none" : "block";
            btnToggleNotif.classList.toggle("btn-notificar-ruta--activo", !visible);
            if (!visible) {
                bloqueNotif.scrollIntoView({ behavior: "smooth", block: "start" });
            }
        });
    }

    // ── RESUMEN DE RUTA ──────────────────────────────────────────
    const btnToggleResumenRuta = document.getElementById("btn-toggle-resumen-ruta");
    const bloqueResumenRuta    = document.getElementById("bloque-resumen-ruta");

    if (btnToggleResumenRuta && bloqueResumenRuta) {
        btnToggleResumenRuta.addEventListener("click", function () {
            const visible = bloqueResumenRuta.style.display !== "none";
            bloqueResumenRuta.style.display = visible ? "none" : "block";
            btnToggleResumenRuta.classList.toggle("btn-resumen-ruta--activo", !visible);
            if (!visible) {
                bloqueResumenRuta.scrollIntoView({ behavior: "smooth", block: "start" });
            }
        });
    }

    if (btnEnviarNotif) {
        btnEnviarNotif.addEventListener("click", function () {
            const fecha     = notifFecha     ? notifFecha.value     : "";
            const ubicacion = notifUbicacion ? notifUbicacion.value : "";
            const hora      = notifHora      ? notifHora.value      : "";

            if (!ubicacion || !hora) {
                mostrarResultadoNotif("error", "Selecciona una ubicación y un horario antes de enviar.");
                return;
            }

            btnEnviarNotif.disabled    = true;
            btnEnviarNotif.textContent = "Cargando…";

            const datos = new FormData();
            datos.append("fecha",            fecha);
            datos.append("nombre_ubicacion", ubicacion);
            datos.append("hora_entrega",     hora);

            fetch("notificar_ruta.php", { method: "POST", body: datos })
                .then(r => r.json())
                .then(function (data) {
                    if (!data.ok) {
                        mostrarResultadoNotif("error", data.mensaje || "Ocurrió un error.");
                    } else {
                        var q = data.queued || 0;
                        var msg = "✅ " + q + " mensaje" + (q !== 1 ? "s" : "") + " enviado" + (q !== 1 ? "s" : "") + " por WhatsApp.";

                        // Links manuales plegables como respaldo
                        var conTel = (data.clientes || []).filter(function (c) { return c.telefono; });
                        if (conTel.length > 0) {
                            var horaBonita = formatHoraWA(hora);
                            msg += "<details style='margin-top:12px;'><summary style='cursor:pointer;font-size:13px;opacity:.55;'>Ver links manuales (respaldo)</summary><div style='margin-top:8px;'>";
                            conTel.forEach(function (cliente) {
                                var tel   = normalizarTelefono(cliente.telefono || "");
                                var texto = encodeURIComponent(
                                    "Hola *" + cliente.nombre + "* 📍 Tu pedido Zabisu *" + cliente.folio +
                                    "* ya llegó a *" + ubicacion + "*. ¡Pasa a recogerlo antes de las " + horaBonita + "! 🍱"
                                );
                                if (tel) {
                                    msg += "<a href='https://wa.me/" + tel + "?text=" + texto + "' target='_blank' style='display:block;margin:4px 0;padding:8px 12px;background:#25D366;color:#fff;border-radius:8px;text-decoration:none;font-size:13px;'>📱 " + cliente.nombre + " · " + cliente.folio + "</a>";
                                }
                            });
                            msg += "</div></details>";
                        }

                        mostrarResultadoNotif("exito", msg);
                    }
                })
                .catch(function () {
                    mostrarResultadoNotif("error", "No se pudo conectar con el servidor.");
                })
                .finally(function () {
                    btnEnviarNotif.disabled    = false;
                    btnEnviarNotif.textContent = "Notificar llegada por WhatsApp";
                });
        });
    }

    function mostrarResultadoNotif(tipo, html) {
        if (!resultadoNotif) return;
        resultadoNotif.className = "notificacion-resultado notificacion-resultado--" + tipo;
        resultadoNotif.innerHTML = html;
        resultadoNotif.style.display = "block";
    }

    // ============================================================
    // MARCAR PRODUCTO AGOTADO / RESTAURAR
    // ============================================================
    document.querySelectorAll(".tarjeta-produccion[data-id-producto]").forEach(function (tarjeta) {
        tarjeta.addEventListener("click", function () {
            var idProducto  = tarjeta.dataset.idProducto;
            var nombre      = tarjeta.dataset.nombre || "este producto";
            var disponible  = tarjeta.dataset.disponible === "1";
            var accion      = disponible ? "agotado" : "disponible";
            var msg         = disponible
                ? "¿Marcar \"" + nombre + "\" como AGOTADO?\nLos clientes ya no podrán seleccionarlo."
                : "¿Restaurar disponibilidad de \"" + nombre + "\"?\nLos clientes podrán volver a seleccionarlo.";

            if (!confirm(msg)) return;

            var fd = new FormData();
            fd.append("id_producto", idProducto);
            fd.append("accion", accion);

            fetch("agotado_ajax.php", { method: "POST", body: fd })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.ok) { alert("Error: " + (data.error || "No se pudo actualizar.")); return; }
                    tarjeta.dataset.disponible = data.disponible ? "1" : "0";
                    tarjeta.classList.toggle("tarjeta-produccion--agotado", !data.disponible);
                })
                .catch(function () { alert("Error de red al actualizar el producto."); });
        });
    });

    // ============================================================
    // AUTO-IMPRESIÓN DE TICKETS TAB- (desde tableta)
    // ============================================================
    var tabAutoprint = <?= $tabAutoprintJS ?>;
    if (tabAutoprint.length > 0 && iframeTicket) {
        tabAutoprint.forEach(function (orden, idx) {
            var key = "tab_impreso_" + orden.id;
            if (localStorage.getItem(key)) return;
            localStorage.setItem(key, "1");
            setTimeout(function () {
                fetch("imprimir_y_notificar.php?id=" + orden.id, { cache: "no-store" });
                iframeTicket.onload = function () {
                    iframeTicket.contentWindow.focus();
                    iframeTicket.contentWindow.print();
                };
                iframeTicket.src = "ticket.php?id=" + orden.id + (orden.num_menus > 1 ? "&separado=1" : "");
            }, idx * 3000);
        });
    }
});
</script>

</body>
</html>