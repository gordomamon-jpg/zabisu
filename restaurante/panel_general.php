<?php
require_once "../config/db.php";
require_once "../includes/menu_programado.php";
menuActivarProgramado($conexion);
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

/* Alertas de inventario (antes se consultaban dentro del HTML) */
$stmtAlertaInv = $conexion->prepare("SELECT COUNT(*) FROM inventario_desechable WHERE stock_actual <= stock_minimo");
$stmtAlertaInv->execute();
$alertasInv = (int)$stmtAlertaInv->fetchColumn();

$diasNombres = ["domingo", "lunes", "martes", "miércoles", "jueves", "viernes", "sábado"];
$tituloDia   = ucfirst($diasNombres[(int)date("w")]) . " " . date("j");
$nuevosHoy   = (int)$resumenHoy["nuevos"];

/*
    Gastos anotados en la semana (misma semana que abre gastos_semana.php:
    el fin de semana ya cuenta la siguiente). Si está en cero, el botón lo avisa.
*/
$lunesGastos = (int)date("N") >= 6 ? date("Y-m-d", strtotime("next monday")) : date("Y-m-d", strtotime("monday this week"));
$stmtGastos  = $conexion->prepare("SELECT COALESCE(SUM(monto), 0) FROM gastos WHERE semana_destino = :sd");
$stmtGastos->execute([":sd" => $lunesGastos]);
$gastosSemana = (float)$stmtGastos->fetchColumn();

$horaMenuHoy = $menuHoy ? date("g:i a", strtotime($menuHoy["pedido_hasta"])) : "";

/*
    Botones agrupados: [archivo, título, ícono, dato pequeño, aviso naranja]
*/
$grupos = [
    "El día" => [
        ["pedidos.php", "Panel de pedidos", "pedidos", (int)$resumenHoy["total_pedidos"] . " hoy", $nuevosHoy ? $nuevosHoy . " nuevo" . ($nuevosHoy !== 1 ? "s" : "") : ""],
        ["resumen_dia.php", "Resumen del día", "resumen", $comidasHoy . " comida" . ($comidasHoy !== 1 ? "s" : ""), ""],
        ["pedido_tableta.php", "Pedido tableta", "tableta", "", ""],
        ["../cliente/bigoton.php", "El Bigotón", "bigoton", "", ""],
    ],
    "Menús" => [
        ["menus.php", "Gestión de menús", "menus", $menuHoy ? ((int)$menuHoy["activo"] ? "Hoy hasta las " . $horaMenuHoy : "Hoy inactivo") : "", ""],
        ["nuevo_menu.php", "Crear nuevo menú", "nuevo", "", $menuHoy ? "" : "Falta el de hoy"],
        ["historico_menus.php", "Historial de menús", "historial", "", ""],
    ],
    "Dinero" => [
        ["gastos_semana.php", "Gastos de la semana", "gastos", $gastosSemana > 0 ? "$" . number_format($gastosSemana, 0) . " anotado" : "", $gastosSemana > 0 ? "" : "Sin anotar"],
        ["finanzas.php", "Finanzas", "finanzas", "", ""],
        ["notas_cuenta.php", "Notas de cuenta", "notas", "", ""],
        ["cotizaciones.php", "Cotizaciones Foodtruck", "cotiz", "", ""],
    ],
    "Más" => [
        ["encuestas.php", "Encuestas", "encuestas", "", ""],
        ["inventario.php", "Inventario desechable", "inv", "", $alertasInv ? $alertasInv . " por surtir" : ""],
    ],
];

/* Íconos de línea de cada botón (mismo grosor), sobre el cuadro de estraza */
$iconos = [
    "pedidos"   => '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2z"/><path d="M9 8h6M9 12h6M9 16h4"/>',
    "resumen"   => '<path d="M9 3h6v3H9z"/><path d="M8 4.5H5V21h14V4.5h-3"/><path d="M8 11h8M8 15h8M8 19h5"/>',
    "tableta"   => '<rect x="5" y="2.5" width="14" height="19" rx="2.5"/><path d="M11 18h2"/>',
    "bigoton"   => '<path d="M12 12.5c-1.6-3-5.6-3.6-8.5-1 1.6 2.8 5.8 3.4 8.5 1z"/><path d="M12 12.5c1.6-3 5.6-3.6 8.5-1-1.6 2.8-5.8 3.4-8.5 1z"/>',
    "menus"     => '<path d="M3 5.5c3-1.2 6-1 9 1v13.5c-3-2-6-2.2-9-1z"/><path d="M21 5.5c-3-1.2-6-1-9 1v13.5c3-2 6-2.2 9-1z"/>',
    "nuevo"     => '<path d="M4 20l1-4.5L16 4.5l3.5 3.5L8.5 19z"/><path d="M13.5 7.5l3 3"/>',
    "historial" => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
    "gastos"    => '<path d="M3 4h2.2l2.3 11h10.8l2-8H6.4"/><circle cx="9.5" cy="19" r="1.4"/><circle cx="17" cy="19" r="1.4"/>',
    "finanzas"  => '<path d="M4 20V11M10 20V5M16 20v-6"/><path d="M2.5 20.5h19"/>',
    "notas"     => '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 3v18M12.5 8h3.5M12.5 12h3.5M12.5 16h2"/>',
    "cotiz"     => '<path d="M2.5 6.5h11v9.5h-11z"/><path d="M13.5 9.5h4l3 3.5v3h-7"/><circle cx="6.5" cy="17.5" r="1.6"/><circle cx="17" cy="17.5" r="1.6"/>',
    "encuestas" => '<path d="M12 3.5l2.5 5.3 5.8.7-4.3 4 1.1 5.8-5.1-2.9-5.1 2.9 1.1-5.8-4.3-4 5.8-.7z"/>',
    "inv"       => '<path d="M3 7.5l9-4 9 4v9.5l-9 4-9-4z"/><path d="M3 7.5l9 4 9-4M12 11.5V21"/>',
];

$etiquetasPago = [
    "Pagado"                  => ["Pagado", ""],
    "Pago en efectivo"        => ["Efectivo", ""],
    "Pendiente de validación" => ["Por revisar", "pn-pago--revision"],
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php require __DIR__ . "/../includes/rz_app.php"; ?>
    <title>Panel general | Restaurante Zabisu</title>
    <link rel="icon" type="image/png" href="../assets/img/LOGO_NARA.png">
    <meta name="theme-color" content="#151413">
    <link rel="preload" href="../assets/fonts/kaushan-script-400.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="../assets/fonts/alfa-slab-one-400.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="../assets/css/menu-pizarron.css?v=2">
    <link rel="stylesheet" href="../assets/css/restaurante-pizarron.css?v=5">
</head>
<body class="pz rz">
<?php require __DIR__ . "/../includes/pz_filtros.php"; ?>

<main class="pz-pagina pn-pagina">

    <div class="pn-top">
        <header class="pz-cab">
            <div class="pz-marca">
                <img src="../assets/img/LOGO_N_128.png" alt="">
                <div>
                    <span class="pz-marca__nombre">Zabisu</span>
                    <span class="pz-marca__lema">Sabor y Servicio</span>
                </div>
            </div>
            <div class="pn-sesion">
                <span class="pn-sesion__nombre"><?php echo htmlspecialchars($_SESSION["restaurante_nombre"] ?? "Admin"); ?></span>
                <a class="pn-sesion__salir" href="logout.php">Salir</a>
            </div>
        </header>

        <p class="rz-eyebrow">Panel general</p>
        <h1 class="pz-titulo"><span class="pz-pincel"><span><?php echo htmlspecialchars($tituloDia); ?></span></span></h1>
        <p class="pn-sub">de <?php echo $mesActualNombre; ?> de <?php echo date("Y"); ?></p>

        <form method="POST" action="toggle_modo_prueba.php" class="pn-prueba<?php echo $modoPrueba ? " pn-prueba--activo" : ""; ?>">
            <?php if ($modoPrueba): ?>
                <span class="pz-falta pz-falta--urgente">Modo prueba activo</span>
                <span class="pn-prueba__txt">Los pedidos nuevos se guardan como prueba y no cuentan en las ventas.</span>
                <button type="submit" class="pn-prueba__btn">Desactivar</button>
            <?php else: ?>
                <button type="submit" class="pn-prueba__btn">Activar modo prueba</button>
            <?php endif; ?>
        </form>
    </div>

    <!-- Hoy: los números que se revisan a cada rato -->
    <section class="pn-kpis" aria-label="Hoy">
        <div class="pn-kpi"><b><?php echo "$" . number_format((float)$resumenHoy["total_ventas"], 0); ?></b><span>vendido hoy</span></div>
        <div class="pn-kpi"><b><?php echo (int)$resumenHoy["total_pedidos"]; ?></b><span>pedidos</span></div>
        <div class="pn-kpi"><b><?php echo $comidasHoy; ?></b><span>comidas</span></div>
        <a class="pn-kpi<?php echo $nuevosHoy ? " pn-kpi--alerta" : ""; ?>" href="pedidos.php"><b><?php echo $nuevosHoy; ?></b><span>sin revisar</span></a>
    </section>

    <!-- Botones de los módulos -->
    <nav class="pn-grupos" aria-label="Módulos">
        <?php foreach ($grupos as $nombreGrupo => $botones): ?>
            <section class="pz-sec pn-grupo">
                <h2 class="pz-cinta pz-fibra"><?php echo $nombreGrupo; ?></h2>
                <div class="pn-tiles">
                    <?php foreach ($botones as [$href, $titulo, $icono, $dato, $aviso]): ?>
                        <a class="pn-tile" href="<?php echo $href; ?>">
                            <span class="pn-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><?php echo $iconos[$icono]; ?></svg></span>
                            <strong><?php echo htmlspecialchars($titulo); ?></strong>
                            <?php if ($dato): ?><small><?php echo htmlspecialchars($dato); ?></small><?php endif; ?>
                            <?php if ($aviso): ?><span class="pn-aviso"><?php echo htmlspecialchars($aviso); ?></span><?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </nav>

    <div class="pn-abajo">

    <!-- Últimos pedidos -->
    <?php if (!empty($ultimosPedidos)): ?>
    <section class="pz-sec pn-ultimos">
        <div class="pn-cab-sec">
            <h2 class="pz-cinta pz-fibra">Últimos pedidos</h2>
            <a class="pn-vertodo" href="pedidos.php">Ver todos</a>
        </div>
        <ul class="pz-grupo">
            <?php foreach ($ultimosPedidos as $p):
                $esHoy = date("Y-m-d", strtotime($p["fecha_pedido"])) === $hoy;
                [$pagoTxt, $pagoClase] = $etiquetasPago[$p["estado_pago"]] ?? ["Sin pago", "pn-pago--falta"];
            ?>
                <li>
                    <a class="pn-pedido<?php echo (int)$p["visto"] === 0 ? " pn-pedido--nuevo" : ""; ?>" href="ver_pedido.php?id=<?php echo urlencode($p["id_pedido"]); ?>">
                        <span class="pn-pedido__txt">
                            <span class="pn-pedido__nombre"><?php echo htmlspecialchars(mb_convert_case(mb_strtolower($p["nombre_cliente"]), MB_CASE_TITLE)); ?></span>
                            <span class="pn-pedido__sec">
                                <?php echo htmlspecialchars($p["nombre_ubicacion"]); ?>,
                                <?php echo $esHoy ? "hoy " . date("g:i a", strtotime($p["fecha_pedido"])) : date("d/m/Y", strtotime($p["fecha_pedido"])); ?>
                            </span>
                        </span>
                        <span class="pn-pedido__der">
                            <b><?php echo "$" . number_format((float)$p["total"], 0); ?></b>
                            <span class="pn-pago <?php echo $pagoClase; ?>"><?php echo $pagoTxt; ?></span>
                        </span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>

    <!-- Próximos menús -->
    <section class="pz-sec pn-proximos">
        <div class="pn-cab-sec">
            <h2 class="pz-cinta pz-fibra">Próximos menús</h2>
            <a class="pn-vertodo" href="nuevo_menu.php">Crear menú</a>
        </div>
        <?php if (empty($proximosMenus)): ?>
            <p class="pn-vacio">No hay menús programados.</p>
        <?php else: ?>
        <ul class="pz-grupo">
            <?php foreach ($proximosMenus as $m):
                $fechaTs = strtotime($m["fecha"]);
            ?>
                <li>
                    <a class="pn-menu" href="productos_menu.php?id=<?php echo (int)$m["id_menu"]; ?>">
                        <span class="pn-menu__fecha">
                            <span><?php echo $m["fecha"] === $hoy ? "Hoy" : ucfirst(mb_substr($diasNombres[(int)date("w", $fechaTs)], 0, 3)); ?></span>
                            <b><?php echo date("j", $fechaTs); ?></b>
                        </span>
                        <span class="pn-menu__txt">
                            <span class="pn-menu__estado<?php echo (int)$m["activo"] ? " pn-menu__estado--activo" : ""; ?>"><?php echo (int)$m["activo"] ? "Activo" : "Inactivo"; ?></span>
                            <span class="pn-menu__hasta">Pedidos hasta las <?php echo date("g:i a", strtotime($m["pedido_hasta"])); ?></span>
                        </span>
                        <svg class="pn-flecha" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path d="M9 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </section>

    </div>

    <p class="pz-pie pn-pie">© 2026 Zabisu - Sabor y Servicio. Todos los derechos reservados.</p>
</main>

</body>
</html>
