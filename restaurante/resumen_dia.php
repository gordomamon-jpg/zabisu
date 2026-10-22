<?php
require_once "../config/db.php";
require_once "auth_check.php";
require_once "../includes/wa_etiqueta.php";

/*
    Resumen del día: solo lectura, pensado para revisarlo desde el celular.
    A diferencia de pedidos.php NO imprime tickets, NO marca pedidos como
    vistos y NO suena, así que abrirlo no le quita la impresión a la
    tableta de cocina. Se actualiza solo cada minuto.

    ?fecha=AAAA-MM-DD para ver otro día (por defecto hoy). El día de un
    pedido es el del menú que pidió, igual que en pedidos.php.
*/
$fecha = $_GET["fecha"] ?? date("Y-m-d");
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || !strtotime($fecha)) {
    $fecha = date("Y-m-d");
}
$esHoy = $fecha === date("Y-m-d");

$fechaMenuSQL = "LEFT JOIN (
                     SELECT pm2.id_pedido, MIN(md2.fecha) AS fecha_menu
                     FROM pedido_menus pm2
                     INNER JOIN detalle_pedido dp2 ON dp2.id_pedido_menu = pm2.id_pedido_menu
                     INNER JOIN productos pr2 ON pr2.id_producto = dp2.id_producto
                     INNER JOIN menu_dia md2 ON md2.id_menu = pr2.id_menu
                     GROUP BY pm2.id_pedido
                 ) AS mi ON mi.id_pedido = p.id_pedido";
$delDia = "p.es_prueba = 0 AND COALESCE(mi.fecha_menu, DATE(p.fecha_pedido)) = :fecha";

/*
    Pedidos del día
*/
$stmt = $conexion->prepare(
    "SELECT p.id_pedido, p.folio, p.nombre_cliente, p.total, p.metodo_pago,
            p.estado_pago, p.visto, p.id_horario,
            h.hora_entrega, u.nombre_ubicacion,
            COALESCE(mc.num_menus, 0) AS num_menus,
            (SELECT w.estado FROM wa_mensajes w WHERE w.id_pedido = p.id_pedido AND w.tipo = 'confirmacion' ORDER BY w.id DESC LIMIT 1) AS wa_confirmacion,
            (SELECT w.estado FROM wa_mensajes w WHERE w.id_pedido = p.id_pedido AND w.tipo = 'llegada' ORDER BY w.id DESC LIMIT 1) AS wa_llegada
     FROM pedidos p
     INNER JOIN horarios_ubicacion h ON p.id_horario = h.id_horario
     INNER JOIN ubicaciones u ON h.id_ubicacion = u.id_ubicacion
     LEFT JOIN (SELECT id_pedido, COUNT(*) AS num_menus FROM pedido_menus GROUP BY id_pedido) mc
            ON mc.id_pedido = p.id_pedido
     $fechaMenuSQL
     WHERE $delDia
     ORDER BY h.hora_entrega, u.nombre_ubicacion, p.fecha_pedido"
);
$stmt->execute([":fecha" => $fecha]);
$pedidos = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
    Extras (aguas, etc.) por pedido
*/
$stmtExtras = $conexion->prepare(
    "SELECT pe.id_pedido, pe.nombre, SUM(pe.cantidad) AS cantidad
     FROM pedido_extras pe
     INNER JOIN pedidos p ON p.id_pedido = pe.id_pedido
     $fechaMenuSQL
     WHERE $delDia
     GROUP BY pe.id_pedido, pe.nombre"
);
$stmtExtras->execute([":fecha" => $fecha]);
$extrasPorPedido = [];
foreach ($stmtExtras->fetchAll(PDO::FETCH_ASSOC) as $e) {
    $extrasPorPedido[(int)$e["id_pedido"]][$e["nombre"]] = (int)$e["cantidad"];
}

/*
    Producción: platos fuertes (con sus opciones) y complementos/aguas
*/
$stmtProd = $conexion->prepare(
    "SELECT dp.categoria, dp.nombre_producto, COALESCE(dp.opcion, '') AS opcion, COUNT(*) AS total
     FROM detalle_pedido dp
     INNER JOIN pedido_menus pm ON dp.id_pedido_menu = pm.id_pedido_menu
     INNER JOIN pedidos p ON pm.id_pedido = p.id_pedido
     $fechaMenuSQL
     WHERE $delDia AND dp.categoria IN ('Plato fuerte', 'Complemento', 'Agua')
     GROUP BY dp.categoria, dp.nombre_producto, COALESCE(dp.opcion, '')"
);
$stmtProd->execute([":fecha" => $fecha]);
$platos = [];
$complementos = [];
foreach ($stmtProd->fetchAll(PDO::FETCH_ASSOC) as $f) {
    $nombre = $f["nombre_producto"];
    if ($f["categoria"] === "Plato fuerte") {
        $platos[$nombre]["total"] = ($platos[$nombre]["total"] ?? 0) + (int)$f["total"];
        if ($f["opcion"] !== "") $platos[$nombre]["opciones"][$f["opcion"]] = (int)$f["total"];
    } else {
        $complementos[$nombre] = ($complementos[$nombre] ?? 0) + (int)$f["total"];
    }
}
foreach ($extrasPorPedido as $extras) {
    foreach ($extras as $nombre => $cant) {
        $complementos[$nombre] = ($complementos[$nombre] ?? 0) + $cant;
    }
}
uasort($platos, fn($a, $b) => $b["total"] <=> $a["total"]);
arsort($complementos);

/*
    Avisos de llegada mandados ese día, por horario
*/
$stmtAvisos = $conexion->prepare(
    "SELECT id_horario, MAX(enviado_en) AS enviado_en
     FROM notificaciones_ruta WHERE fecha_menu = :fecha GROUP BY id_horario"
);
$stmtAvisos->execute([":fecha" => $fecha]);
$avisoPorHorario = array_column($stmtAvisos->fetchAll(PDO::FETCH_ASSOC), "enviado_en", "id_horario");

/*
    Totales y agrupación por hora → punto de entrega
*/
$tot = ["pedidos" => count($pedidos), "comidas" => 0, "venta" => 0.0,
        "efectivo" => 0, "transferencia" => 0, "en_revision" => 0, "sin_pago" => 0, "sin_imprimir" => 0];
$porHora = [];
foreach ($pedidos as $p) {
    $tot["comidas"] += (int)$p["num_menus"];
    $tot["venta"]   += (float)$p["total"];
    if ($p["metodo_pago"] === "Efectivo") $tot["efectivo"]++;
    elseif ($p["metodo_pago"] === "Transferencia") $tot["transferencia"]++;
    if ($p["estado_pago"] === "Pendiente de validación") $tot["en_revision"]++;
    if ($p["estado_pago"] === "Pendiente de pago") $tot["sin_pago"]++;
    if ((int)$p["visto"] === 0) $tot["sin_imprimir"]++;

    $hora  = $p["hora_entrega"];
    $punto = $p["nombre_ubicacion"];
    $grupo = &$porHora[$hora][$punto];
    $grupo["id_horario"] = (int)$p["id_horario"];
    $grupo["comidas"]    = ($grupo["comidas"] ?? 0) + (int)$p["num_menus"];
    $grupo["pedidos"][]  = $p;
    foreach ($extrasPorPedido[(int)$p["id_pedido"]] ?? [] as $nombre => $cant) {
        $grupo["extras"][$nombre] = ($grupo["extras"][$nombre] ?? 0) + $cant;
    }
    unset($grupo);
}

function horaBonita(string $hora): string
{
    $ts = strtotime($hora);
    return date("g:i", $ts) . " " . (date("A", $ts) === "AM" ? "am" : "pm");
}

$dias  = ["domingo", "lunes", "martes", "miércoles", "jueves", "viernes", "sábado"];
$meses = ["", "enero", "febrero", "marzo", "abril", "mayo", "junio", "julio",
          "agosto", "septiembre", "octubre", "noviembre", "diciembre"];
$tsFecha   = strtotime($fecha);
$tituloDia = ucfirst($dias[(int)date("w", $tsFecha)]) . " " . date("j", $tsFecha);
$subMes    = "de " . $meses[(int)date("n", $tsFecha)] . ($esHoy ? ", hoy" : "");

function etiquetaPago(array $p): string
{
    $mapa = [
        "Pendiente de validación" => ["rp-pago--revision", "Por revisar"],
        "Pago en efectivo"        => ["", "Efectivo"],
        "Pagado"                  => ["", "Pagado"],
        "Pago con tarjeta"        => ["", "Tarjeta"],
    ];
    [$clase, $texto] = $mapa[$p["estado_pago"]] ?? ["rp-pago--falta", "Sin pago"];
    return '<span class="rp-pago ' . $clase . '">' . $texto . '</span>';
}

$maxPlato = $platos ? max(array_column($platos, "total")) : 1;
$platoTop = $platos ? array_key_first($platos) : null;

$anterior  = date("Y-m-d", strtotime("$fecha -1 day"));
$siguiente = date("Y-m-d", strtotime("$fecha +1 day"));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Resumen del día | Zabisu</title>
    <link rel="icon" type="image/png" href="../assets/img/LOGO_NARA.png">
    <meta name="theme-color" content="#151413">
    <link rel="preload" href="../assets/fonts/kaushan-script-400.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="../assets/fonts/alfa-slab-one-400.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="../assets/css/menu-pizarron.css?v=2">
    <link rel="stylesheet" href="../assets/css/resumen-pizarron.css?v=1">
</head>
<body class="pz rp">
<?php require __DIR__ . "/../includes/pz_filtros.php"; ?>

<main class="pz-pagina rp-pagina">

    <header class="pz-cab">
        <a class="pz-marca" href="panel_general.php" aria-label="Zabisu, volver al panel general">
            <img src="../assets/img/LOGO_N_128.png" alt="">
            <div>
                <span class="pz-marca__nombre">Zabisu</span>
                <span class="pz-marca__lema">Sabor y Servicio</span>
            </div>
        </a>
        <nav class="rp-dias" aria-label="Cambiar de día">
            <a href="?fecha=<?php echo $anterior; ?>" aria-label="Día anterior">
                <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M15 5l-7 7 7 7" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </a>
            <?php if (!$esHoy): ?><a class="rp-dias__hoy" href="resumen_dia.php">Hoy</a><?php endif; ?>
            <a href="?fecha=<?php echo $siguiente; ?>" aria-label="Día siguiente">
                <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M9 5l7 7-7 7" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </a>
        </nav>
    </header>

    <p class="rp-eyebrow">Resumen del día</p>
    <h1 class="pz-titulo rp-titulo"><span class="pz-pincel"><span><?php echo htmlspecialchars($tituloDia); ?></span></span></h1>
    <p class="rp-sub"><?php echo htmlspecialchars($subMes); ?></p>

    <?php if (!$pedidos): ?>
        <div class="rp-vacio">
            <p class="rp-vacio__mano">Pizarrón limpio</p>
            <p>No hay pedidos para este día.</p>
        </div>
    <?php else: ?>

    <!-- La comanda del día: los números grandes -->
    <section class="pz-boleto rp-comanda" aria-label="Totales del día">
        <div class="pz-boleto__papel">
            <span class="pz-boleto__ojo" aria-hidden="true"></span>
            <div class="rp-comanda__grandes">
                <div><b><?php echo $tot["pedidos"]; ?></b><span>pedidos</span></div>
                <div><b><?php echo $tot["comidas"]; ?></b><span>comidas</span></div>
            </div>
            <hr class="pz-boleto__corte">
            <dl class="rp-comanda__lineas">
                <div><dt>Venta</dt><dd>$<?php echo number_format($tot["venta"], 0); ?></dd></div>
                <div><dt>Efectivo</dt><dd><?php echo $tot["efectivo"]; ?></dd></div>
                <div><dt>Transferencia</dt><dd><?php echo $tot["transferencia"]; ?></dd></div>
            </dl>
        </div>
    </section>

    <?php if ($tot["en_revision"] || $tot["sin_pago"] || $tot["sin_imprimir"]): ?>
    <div class="rp-pendientes">
        <?php if ($tot["sin_imprimir"]): ?>
            <span class="pz-falta pz-falta--urgente"><?php echo $tot["sin_imprimir"]; ?> sin imprimir</span>
        <?php endif; ?>
        <?php if ($tot["en_revision"]): ?>
            <span class="pz-falta"><?php echo $tot["en_revision"]; ?> por revisar pago</span>
        <?php endif; ?>
        <?php if ($tot["sin_pago"]): ?>
            <span class="pz-falta pz-falta--cerrado"><?php echo $tot["sin_pago"]; ?> sin pago</span>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <h2 class="rp-h2">La ruta</h2>
    <?php foreach ($porHora as $hora => $puntos): ?>
        <?php $comidasHora = array_sum(array_column($puntos, "comidas")); ?>
        <section class="pz-sec rp-hora">
            <div class="rp-hora__cab">
                <span class="pz-cinta"><?php echo horaBonita($hora); ?></span>
                <span class="rp-hora__total"><b><?php echo $comidasHora; ?></b> comida<?php echo $comidasHora !== 1 ? "s" : ""; ?></span>
            </div>

            <div class="pz-grupo">
                <?php foreach ($puntos as $punto => $g): ?>
                    <?php $aviso = $avisoPorHorario[$g["id_horario"]] ?? null; ?>
                    <details class="rp-punto">
                        <summary>
                            <span class="pz-tira" aria-hidden="true"></span>
                            <span class="pz-fila__txt">
                                <span class="pz-fila__nombre"><?php echo htmlspecialchars($punto); ?></span>
                                <?php if (!empty($g["extras"])): ?>
                                    <span class="pz-fila__desc">
                                        <?php echo htmlspecialchars(implode(", ", array_map(fn($n, $c) => "+$c $n", array_keys($g["extras"]), $g["extras"]))); ?>
                                    </span>
                                <?php endif; ?>
                            </span>
                            <?php if ($aviso): ?>
                                <span class="rp-avisado" title="Aviso de llegada mandado">Avisado <?php echo date("g:i", strtotime($aviso)); ?></span>
                            <?php endif; ?>
                            <b class="rp-punto__num"><?php echo $g["comidas"]; ?></b>
                            <svg class="rp-punto__flecha" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path d="M9 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </summary>

                        <ul class="rp-clientes">
                            <?php foreach ($g["pedidos"] as $p): ?>
                                <li>
                                    <div class="rp-cliente__fila">
                                        <span class="rp-cliente__nombre"><?php echo htmlspecialchars(mb_convert_case(mb_strtolower($p["nombre_cliente"]), MB_CASE_TITLE)); ?></span>
                                        <span class="rp-cliente__menus"><?php echo (int)$p["num_menus"]; ?></span>
                                    </div>
                                    <div class="rp-cliente__fila rp-cliente__fila--sec">
                                        <span><?php echo htmlspecialchars($p["folio"]); ?></span>
                                        <span>$<?php echo number_format((float)$p["total"], 0); ?> <?php echo etiquetaPago($p); ?></span>
                                    </div>
                                    <div class="wa-estados" data-wa-id="<?php echo (int)$p["id_pedido"]; ?>"><?php
                                        echo etiquetaWA("Confirmación", $p["wa_confirmacion"]) . etiquetaWA("Llegada", $p["wa_llegada"]);
                                    ?></div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </details>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>

    <h2 class="rp-h2">En la cocina</h2>
    <section class="pz-sec rp-cocina">
        <span class="pz-cinta">Platos fuertes</span>
        <ul class="pz-grupo rp-prod">
            <?php foreach ($platos as $nombre => $pl): ?>
                <li class="rp-prod__fila<?php echo $nombre === $platoTop && count($platos) > 1 ? ' rp-prod__fila--top' : ''; ?>">
                    <?php if ($nombre === $platoTop && count($platos) > 1): ?>
                        <span class="pz-nota rp-nota" aria-hidden="true">el más pedido
                            <svg viewBox="0 0 30 22"><path d="M28 4c-6 1-14 4-20 12M8 16l1-7M8 16l7-2" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                        </span>
                    <?php endif; ?>
                    <div class="rp-prod__txt">
                        <span class="rp-prod__nombre"><?php echo htmlspecialchars($nombre); ?></span>
                        <?php if (!empty($pl["opciones"])): ?>
                            <?php arsort($pl["opciones"]); ?>
                            <span class="rp-prod__opc"><?php
                                echo htmlspecialchars(implode(", ", array_map(fn($o, $n) => "$o $n", array_keys($pl["opciones"]), $pl["opciones"])));
                            ?></span>
                        <?php endif; ?>
                        <span class="rp-barra" aria-hidden="true"><i style="width:<?php echo round($pl["total"] / $maxPlato * 100); ?>%"></i></span>
                    </div>
                    <b class="rp-prod__num"><?php echo $pl["total"]; ?></b>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>

    <?php if ($complementos): ?>
    <section class="pz-sec rp-cocina">
        <span class="pz-cinta">Complementos y aguas</span>
        <ul class="pz-grupo rp-comp">
            <?php foreach ($complementos as $nombre => $cant): ?>
                <li><span><?php echo htmlspecialchars($nombre); ?></span><b><?php echo $cant; ?></b></li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>

    <?php endif; ?>

    <p class="pz-pie rp-pie">
        Solo lectura: desde aquí no se imprime nada.<br>
        <?php if ($esHoy): ?>Se actualiza solo cada minuto, última vez a las <?php echo date("g:i"); ?>.<?php endif; ?>
    </p>
</main>

<?php if ($esHoy): ?>
<script>
// Actualiza cada minuto sin perder el lugar ni los puntos abiertos
(function () {
    var CLAVE = "rd_estado";
    try {
        var guardado = JSON.parse(sessionStorage.getItem(CLAVE) || "null");
        if (guardado) {
            sessionStorage.removeItem(CLAVE);
            document.querySelectorAll("details.rp-punto").forEach(function (d, i) {
                if (guardado.abiertos.indexOf(i) !== -1) d.open = true;
            });
            window.scrollTo(0, guardado.y || 0);
        }
    } catch (e) {}

    function recargar() {
        try {
            var abiertos = [];
            document.querySelectorAll("details.rp-punto").forEach(function (d, i) { if (d.open) abiertos.push(i); });
            sessionStorage.setItem(CLAVE, JSON.stringify({ y: window.scrollY, abiertos: abiertos }));
        } catch (e) {}
        location.reload();
    }

    setInterval(function () { if (!document.hidden) recargar(); }, 60000);

    // Al volver a la pestaña después de un rato, actualizar de inmediato
    var oculto = 0;
    document.addEventListener("visibilitychange", function () {
        if (document.hidden) { oculto = Date.now(); return; }
        if (oculto && Date.now() - oculto > 60000) recargar();
    });
})();
</script>
<?php endif; ?>
</body>
</html>
