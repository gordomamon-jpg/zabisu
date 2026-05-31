<?php
require_once "../config/db.php";
require_once "auth_check.php";

$hoy = date("Y-m-d");

/*
    Auto-desactivar menús vencidos
*/
$conexion->exec("UPDATE menu_dia SET activo = 0 WHERE activo = 1 AND pedido_hasta < NOW()");

/*
    Pedidos de hoy
*/
$sqlHoy = "SELECT
               COUNT(*)                                  AS total_pedidos,
               COALESCE(SUM(total), 0)                   AS total_ventas,
               SUM(CASE WHEN visto = 0 THEN 1 ELSE 0 END) AS nuevos
           FROM pedidos
           WHERE DATE(fecha_pedido) = :hoy AND es_prueba = 0";
$stmtHoy = $conexion->prepare($sqlHoy);
$stmtHoy->execute([":hoy" => $hoy]);
$resumenHoy = $stmtHoy->fetch(PDO::FETCH_ASSOC);

/*
    Comidas de hoy (filas en pedido_menus)
*/
$sqlComidasHoy = "SELECT COUNT(*) FROM pedido_menus pm
                  INNER JOIN pedidos p ON pm.id_pedido = p.id_pedido
                  WHERE DATE(p.fecha_pedido) = :hoy AND p.es_prueba = 0";
$stmtComidasHoy = $conexion->prepare($sqlComidasHoy);
$stmtComidasHoy->execute([":hoy" => $hoy]);
$comidasHoy = (int)$stmtComidasHoy->fetchColumn();

/*
    Menú activo de hoy
*/
$sqlMenuHoy = "SELECT id_menu, fecha, activo, pedido_hasta
               FROM menu_dia
               WHERE fecha = :hoy
               LIMIT 1";
$stmtMenuHoy = $conexion->prepare($sqlMenuHoy);
$stmtMenuHoy->execute([":hoy" => $hoy]);
$menuHoy = $stmtMenuHoy->fetch(PDO::FETCH_ASSOC);

/*
    Próximos menús programados (fecha >= hoy, máximo 3)
*/
$sqlProximosMenus = "SELECT id_menu, fecha, activo, pedido_hasta
                     FROM menu_dia
                     WHERE fecha >= :hoy
                     ORDER BY fecha ASC
                     LIMIT 4";
$stmtProximosMenus = $conexion->prepare($sqlProximosMenus);
$stmtProximosMenus->execute([":hoy" => $hoy]);
$proximosMenus = $stmtProximosMenus->fetchAll(PDO::FETCH_ASSOC);

/*
    Últimos 5 pedidos
*/
$sqlUltimosPedidos = "SELECT
                          p.id_pedido,
                          p.folio,
                          p.nombre_cliente,
                          p.total,
                          p.estado_pago,
                          p.visto,
                          p.fecha_pedido,
                          u.nombre_ubicacion
                       FROM pedidos p
                       INNER JOIN horarios_ubicacion h ON p.id_horario = h.id_horario
                       INNER JOIN ubicaciones u ON h.id_ubicacion = u.id_ubicacion
                       WHERE p.es_prueba = 0
                       ORDER BY p.fecha_pedido DESC, p.id_pedido DESC
                       LIMIT 5";
$stmtUltimos = $conexion->prepare($sqlUltimosPedidos);
$stmtUltimos->execute();
$ultimosPedidos = $stmtUltimos->fetchAll(PDO::FETCH_ASSOC);

/*
    Ventas del mes actual
*/
$sqlMes = "SELECT
               COUNT(*)                AS total_pedidos,
               COALESCE(SUM(total), 0) AS total_ventas
           FROM pedidos
           WHERE DATE(fecha_pedido) BETWEEN :inicio AND :hoy AND es_prueba = 0";
$stmtMes = $conexion->prepare($sqlMes);
$stmtMes->execute([":inicio" => date("Y-m-01"), ":hoy" => $hoy]);
$resumenMes = $stmtMes->fetch(PDO::FETCH_ASSOC);

function fmtPeso($n)
{
    return "$" . number_format((float)$n, 2, ".", ",");
}

$mesesNombres = [
    '01'=>'enero','02'=>'febrero','03'=>'marzo','04'=>'abril',
    '05'=>'mayo','06'=>'junio','07'=>'julio','08'=>'agosto',
    '09'=>'septiembre','10'=>'octubre','11'=>'noviembre','12'=>'diciembre'
];
$mesActualNombre = $mesesNombres[date("m")] ?? date("m");

/*
    Modo prueba
*/
$stmtModoPrueba = $conexion->prepare("SELECT valor FROM configuracion WHERE clave = 'modo_prueba' LIMIT 1");
$stmtModoPrueba->execute();
$modoPrueba = (int)($stmtModoPrueba->fetchColumn() ?? 0);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel general | Restaurante Zabisu</title>
    <link rel="icon" type="image/png" href="../assets/img/LOGO_NARA.png">
    <link rel="stylesheet" href="../assets/css/styles.css?v=5">
</head>
<body>

<div class="contenedor">

    <div class="hero-zabisu">
        <div class="hero-zabisu__glow"></div>
        <div class="hero-zabisu__contenido">
            <p class="hero-zabisu__eyebrow">RESTAURANTE</p>
            <div class="hero-zabisu__marca-grupo">
                <img class="hero-zabisu__logo" src="../assets/img/LOGO_BLANCO.png" alt="Zabisu">
                <h1 class="hero-zabisu__titulo">Panel general</h1>
            </div>
            <p class="hero-zabisu__texto">
                <?php echo ucfirst($mesesNombres[date("m")]); ?> <?php echo date("Y"); ?>
                &mdash; <?php echo date("d") . " de " . $mesActualNombre; ?>
            </p>
            <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;margin-top:6px;">
                <span class="btn-volver-panel" style="cursor:default;">
                    👤 <?php echo htmlspecialchars($_SESSION["restaurante_nombre"] ?? "Admin"); ?>
                </span>
                <a href="logout.php" class="btn-volver-panel">Cerrar sesión →</a>
            </div>
            <div style="margin-top:14px;">
                <form method="POST" action="toggle_modo_prueba.php">
                    <button type="submit" class="btn-toggle-pedidos <?php echo $modoPrueba ? 'btn-toggle-pedidos--pausado' : 'btn-toggle-pedidos--activo'; ?>">
                        <?php if ($modoPrueba): ?>
                            🧪 Modo prueba activo — Desactivar
                        <?php else: ?>
                            🧪 Modo prueba inactivo — Activar
                        <?php endif; ?>
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- ACCESOS PRINCIPALES -->
    <div class="panel-accesos">

        <a href="pedidos.php" class="panel-acceso panel-acceso--pedidos">
            <div class="panel-acceso__icono">🧾</div>
            <div class="panel-acceso__contenido">
                <strong class="panel-acceso__titulo">Panel de pedidos</strong>
                <span class="panel-acceso__desc">Revisa y administra los pedidos del día</span>
            </div>
            <?php if ((int)$resumenHoy["nuevos"] > 0): ?>
                <span class="panel-acceso__badge"><?php echo (int)$resumenHoy["nuevos"]; ?> nuevo<?php echo (int)$resumenHoy["nuevos"] !== 1 ? "s" : ""; ?></span>
            <?php endif; ?>
        </a>

        <a href="estadisticas.php" class="panel-acceso panel-acceso--estadisticas">
            <div class="panel-acceso__icono">📊</div>
            <div class="panel-acceso__contenido">
                <strong class="panel-acceso__titulo">Estadísticas</strong>
                <span class="panel-acceso__desc">Ventas, métodos de pago y productos populares</span>
            </div>
        </a>

        <a href="gastos.php" class="panel-acceso panel-acceso--estadisticas">
            <div class="panel-acceso__icono">💸</div>
            <div class="panel-acceso__contenido">
                <strong class="panel-acceso__titulo">Registro de gastos</strong>
                <span class="panel-acceso__desc">Ingresos vs. egresos por semana operativa</span>
            </div>
        </a>

        <a href="menus.php" class="panel-acceso panel-acceso--menus">
            <div class="panel-acceso__icono">📋</div>
            <div class="panel-acceso__contenido">
                <strong class="panel-acceso__titulo">Gestión de menús</strong>
                <span class="panel-acceso__desc">Consulta y administra los menús registrados</span>
            </div>
        </a>

        <a href="nuevo_menu.php" class="panel-acceso panel-acceso--nuevo-menu">
            <div class="panel-acceso__icono">✏️</div>
            <div class="panel-acceso__contenido">
                <strong class="panel-acceso__titulo">Crear nuevo menú</strong>
                <span class="panel-acceso__desc">Programa el menú del día con productos y horarios</span>
            </div>
        </a>

        <a href="nuevo_pedido.php" class="panel-acceso panel-acceso--pedidos">
            <div class="panel-acceso__icono">➕</div>
            <div class="panel-acceso__contenido">
                <strong class="panel-acceso__titulo">Nuevo pedido manual</strong>
                <span class="panel-acceso__desc">Registra pedidos recibidos por WhatsApp, teléfono u otro medio</span>
            </div>
        </a>

        <a href="pedido_tableta.php" class="panel-acceso">
            <div class="panel-acceso__icono">📱</div>
            <div class="panel-acceso__contenido">
                <strong class="panel-acceso__titulo">Pedido tableta</strong>
                <span class="panel-acceso__desc">Interfaz táctil para registrar pedidos directo desde la tableta</span>
            </div>
        </a>

        <a href="../cliente/bigoton.php" class="panel-acceso panel-acceso--bigoton">
            <div class="panel-acceso__icono">🥸</div>
            <div class="panel-acceso__contenido">
                <strong class="panel-acceso__titulo">El Bigoton</strong>
                <span class="panel-acceso__desc">Registra pedidos del comedor interno</span>
            </div>
        </a>

        <a href="encuestas.php" class="panel-acceso panel-acceso--encuestas">
            <div class="panel-acceso__icono">⭐</div>
            <div class="panel-acceso__contenido">
                <strong class="panel-acceso__titulo">Encuestas</strong>
                <span class="panel-acceso__desc">Sugerencias y feedback de clientes</span>
            </div>
        </a>

        <a href="notitas.php" class="panel-acceso">
            <div class="panel-acceso__icono">🎈</div>
            <div class="panel-acceso__contenido">
                <strong class="panel-acceso__titulo">Notitas</strong>
                <span class="panel-acceso__desc">Imprime tickets personalizados en cantidad</span>
            </div>
        </a>

        <a href="cotizaciones.php" class="panel-acceso">
            <div class="panel-acceso__icono">📄</div>
            <div class="panel-acceso__contenido">
                <strong class="panel-acceso__titulo">Cotizaciones BBQ</strong>
                <span class="panel-acceso__desc">Genera cotizaciones profesionales para eventos y clientes</span>
            </div>
        </a>

        <a href="inventario.php" class="panel-acceso panel-acceso--inventario">
            <div class="panel-acceso__icono">📦</div>
            <div class="panel-acceso__contenido">
                <strong class="panel-acceso__titulo">Inventario desechable</strong>
                <span class="panel-acceso__desc">Control de stock de contenedores, cubiertos y empaques</span>
            </div>
            <?php
                $stmtAlertaInv = $conexion->prepare(
                    "SELECT COUNT(*) FROM inventario_desechable WHERE stock_actual <= stock_minimo"
                );
                $stmtAlertaInv->execute();
                $alertasInv = (int)$stmtAlertaInv->fetchColumn();
                if ($alertasInv > 0):
            ?>
                <span class="panel-acceso__badge"><?php echo $alertasInv; ?> alerta<?php echo $alertasInv !== 1 ? "s" : ""; ?></span>
            <?php endif; ?>
        </a>

    </div>

    <!-- RESUMEN DEL DÍA -->
    <div class="bloque-formulario">
        <h2>Resumen de hoy</h2>

        <div class="estadisticas-kpis">
            <div class="kpi-card kpi-card--principal">
                <span class="kpi-card__etiqueta">Ventas de hoy</span>
                <strong class="kpi-card__valor"><?php echo fmtPeso($resumenHoy["total_ventas"]); ?></strong>
                <span class="kpi-card__sub">
                    <?php echo (int)$resumenHoy["total_pedidos"]; ?> pedido<?php echo (int)$resumenHoy["total_pedidos"] !== 1 ? "s" : ""; ?>
                    &middot; <?php echo $comidasHoy; ?> comida<?php echo $comidasHoy !== 1 ? "s" : ""; ?>
                </span>
            </div>

            <div class="kpi-card">
                <span class="kpi-card__etiqueta">Pedidos nuevos</span>
                <strong class="kpi-card__valor"><?php echo (int)$resumenHoy["nuevos"]; ?></strong>
                <span class="kpi-card__sub">Sin revisar hoy</span>
            </div>

            <div class="kpi-card">
                <span class="kpi-card__etiqueta">Ventas del mes</span>
                <strong class="kpi-card__valor"><?php echo fmtPeso($resumenMes["total_ventas"]); ?></strong>
                <span class="kpi-card__sub">
                    <?php echo (int)$resumenMes["total_pedidos"]; ?> pedido<?php echo (int)$resumenMes["total_pedidos"] !== 1 ? "s" : ""; ?> en <?php echo $mesActualNombre; ?>
                </span>
            </div>

            <div class="kpi-card <?php echo $menuHoy ? "kpi-card--confirmado" : ""; ?>">
                <span class="kpi-card__etiqueta">Menú de hoy</span>
                <strong class="kpi-card__valor" style="font-size:18px;">
                    <?php if ($menuHoy): ?>
                        <?php echo (int)$menuHoy["activo"] ? "Activo" : "Inactivo"; ?>
                    <?php else: ?>
                        Sin menú
                    <?php endif; ?>
                </strong>
                <span class="kpi-card__sub">
                    <?php if ($menuHoy): ?>
                        Pedidos hasta <?php echo date("g:i A", strtotime($menuHoy["pedido_hasta"])); ?>
                        &mdash; <a href="productos_menu.php?id=<?php echo (int)$menuHoy["id_menu"]; ?>" style="color:var(--zabisu-orange);">Ver detalle</a>
                    <?php else: ?>
                        <a href="nuevo_menu.php" style="color:var(--zabisu-orange);">Crear menú para hoy</a>
                    <?php endif; ?>
                </span>
            </div>
        </div>
    </div>

    <!-- ÚLTIMOS PEDIDOS -->
    <?php if (!empty($ultimosPedidos)): ?>
    <div class="bloque-formulario">
        <div class="cabecera-modulo">
            <h2>Últimos pedidos</h2>
            <a href="pedidos.php" class="btn-tabla">Ver todos</a>
        </div>

        <div class="tabla-pedidos-wrapper">
            <table class="tabla-pedidos">
                <thead>
                    <tr>
                        <th>Folio</th>
                        <th>Cliente</th>
                        <th>Ubicación</th>
                        <th>Fecha</th>
                        <th>Estado pago</th>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ultimosPedidos as $p): ?>
                        <?php
                        $clases = [
                            "Pagado"                  => "estado estado-pagado",
                            "Pago en efectivo"        => "estado estado-efectivo",
                            "Pendiente de validación" => "estado estado-revision",
                        ];
                        $clEstado = $clases[$p["estado_pago"]] ?? "estado estado-pendiente";
                        $esHoy = date("Y-m-d", strtotime($p["fecha_pedido"])) === $hoy;
                        ?>
                        <tr class="<?php echo (int)$p["visto"] === 0 ? "fila-pedido-nuevo" : "fila-pedido-visto"; ?>">
                            <td>
                                <a class="btn-tabla" href="ver_pedido.php?id=<?php echo urlencode($p["id_pedido"]); ?>" style="padding:6px 12px;font-size:13px;">
                                    <?php echo htmlspecialchars($p["folio"]); ?>
                                </a>
                            </td>
                            <td><?php echo htmlspecialchars($p["nombre_cliente"]); ?></td>
                            <td><?php echo htmlspecialchars($p["nombre_ubicacion"]); ?></td>
                            <td>
                                <?php if ($esHoy): ?>
                                    <span style="color:var(--zabisu-orange);font-weight:700;">Hoy</span>
                                    <span class="texto-secundario"><?php echo date("g:i A", strtotime($p["fecha_pedido"])); ?></span>
                                <?php else: ?>
                                    <?php echo date("d/m/Y", strtotime($p["fecha_pedido"])); ?>
                                <?php endif; ?>
                            </td>
                            <td><span class="<?php echo $clEstado; ?>"><?php echo htmlspecialchars($p["estado_pago"] ?: "Pendiente"); ?></span></td>
                            <td><strong><?php echo fmtPeso($p["total"]); ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- PRÓXIMOS MENÚS -->
    <?php if (!empty($proximosMenus)): ?>
    <div class="bloque-formulario">
        <div class="cabecera-modulo">
            <h2>Próximos menús</h2>
            <a href="nuevo_menu.php" class="btn-tabla">Crear menú</a>
        </div>

        <div class="proximos-menus">
            <?php foreach ($proximosMenus as $m):
                $esMenuHoy  = $m["fecha"] === $hoy;
                $fechaTs    = strtotime($m["fecha"]);
                $diasSemana = ["Sunday"=>"Dom","Monday"=>"Lun","Tuesday"=>"Mar","Wednesday"=>"Mié","Thursday"=>"Jue","Friday"=>"Vie","Saturday"=>"Sáb"];
                $diaNombre  = $diasSemana[date("l", $fechaTs)] ?? date("l", $fechaTs);
            ?>
                <a href="productos_menu.php?id=<?php echo (int)$m["id_menu"]; ?>" class="proximo-menu-card <?php echo $esMenuHoy ? "proximo-menu-card--hoy" : ""; ?>">
                    <div class="proximo-menu-card__fecha">
                        <span class="proximo-menu-card__dia-semana"><?php echo $diaNombre; ?></span>
                        <span class="proximo-menu-card__dia-num"><?php echo date("j", $fechaTs); ?></span>
                    </div>
                    <div class="proximo-menu-card__info">
                        <span class="proximo-menu-card__label"><?php echo $esMenuHoy ? "Hoy" : date("d/m/Y", $fechaTs); ?></span>
                        <span class="proximo-menu-card__estado">
                            <?php if ((int)$m["activo"]): ?>
                                <span class="estado estado-pagado" style="font-size:11px;">Activo</span>
                            <?php else: ?>
                                <span class="estado estado-pendiente" style="font-size:11px;">Inactivo</span>
                            <?php endif; ?>
                        </span>
                        <span class="texto-secundario" style="font-size:12px;">
                            Pedidos hasta <?php echo date("g:i A", strtotime($m["pedido_hasta"])); ?>
                        </span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

</div>

</body>
</html>
