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

function fechaBonita(string $fecha): string
{
    $dias  = ["domingo", "lunes", "martes", "miércoles", "jueves", "viernes", "sábado"];
    $meses = ["", "enero", "febrero", "marzo", "abril", "mayo", "junio", "julio",
              "agosto", "septiembre", "octubre", "noviembre", "diciembre"];
    $ts = strtotime($fecha);
    return ucfirst($dias[(int)date("w", $ts)]) . " " . date("j", $ts) . " de " . $meses[(int)date("n", $ts)];
}

function etiquetaPago(array $p): string
{
    $mapa = [
        "Pendiente de validación" => ["rd-pago--revision", "En revisión"],
        "Pago en efectivo"        => ["rd-pago--efectivo", "Efectivo"],
        "Pagado"                  => ["rd-pago--pagado", "Pagado"],
        "Pago con tarjeta"        => ["rd-pago--pagado", "Tarjeta"],
    ];
    [$clase, $texto] = $mapa[$p["estado_pago"]] ?? ["rd-pago--pendiente", "Sin pago"];
    return '<span class="rd-pago ' . $clase . '">' . $texto . '</span>';
}

$anterior  = date("Y-m-d", strtotime("$fecha -1 day"));
$siguiente = date("Y-m-d", strtotime("$fecha +1 day"));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resumen del día | Restaurante Zabisu</title>
    <link rel="icon" type="image/png" href="../assets/img/LOGO_NARA.png">
    <meta name="theme-color" content="#0f0f12">
    <link rel="stylesheet" href="../assets/css/styles.css?v=8">
</head>
<body class="rd">

<div class="rd-pagina">

    <header class="rd-cab">
        <a class="rd-volver" href="panel_general.php" aria-label="Volver al panel general">←</a>
        <div class="rd-cab__titulo">
            <span class="rd-cab__eyebrow">Resumen del día</span>
            <h1><?php echo htmlspecialchars(fechaBonita($fecha)); ?></h1>
        </div>
        <nav class="rd-dias" aria-label="Cambiar de día">
            <a href="?fecha=<?php echo $anterior; ?>" aria-label="Día anterior">‹</a>
            <?php if (!$esHoy): ?><a class="rd-dias__hoy" href="resumen_dia.php">Hoy</a><?php endif; ?>
            <a href="?fecha=<?php echo $siguiente; ?>" aria-label="Día siguiente">›</a>
        </nav>
    </header>

    <p class="rd-nota">
        Solo lectura: aquí no se imprime nada.
        <?php if ($esHoy): ?>Se actualiza solo cada minuto · <?php echo date("g:i"); ?><?php endif; ?>
    </p>

    <?php if (!$pedidos): ?>
        <div class="rd-vacio">No hay pedidos para este día.</div>
    <?php else: ?>

    <section class="rd-numeros" aria-label="Totales">
        <div class="rd-num rd-num--principal">
            <b><?php echo $tot["pedidos"]; ?></b><span>pedidos</span>
        </div>
        <div class="rd-num rd-num--principal">
            <b><?php echo $tot["comidas"]; ?></b><span>comidas</span>
        </div>
        <div class="rd-num">
            <b>$<?php echo number_format($tot["venta"], 0); ?></b><span>venta</span>
        </div>
        <div class="rd-num">
            <b><?php echo $tot["efectivo"]; ?> · <?php echo $tot["transferencia"]; ?></b><span>efectivo · transf.</span>
        </div>
    </section>

    <?php if ($tot["en_revision"] || $tot["sin_pago"] || $tot["sin_imprimir"]): ?>
    <section class="rd-alertas">
        <?php if ($tot["sin_imprimir"]): ?>
            <span class="rd-alerta rd-alerta--nuevo"><?php echo $tot["sin_imprimir"]; ?> sin imprimir</span>
        <?php endif; ?>
        <?php if ($tot["en_revision"]): ?>
            <span class="rd-alerta rd-alerta--revision"><?php echo $tot["en_revision"]; ?> transferencia<?php echo $tot["en_revision"] > 1 ? "s" : ""; ?> por revisar</span>
        <?php endif; ?>
        <?php if ($tot["sin_pago"]): ?>
            <span class="rd-alerta"><?php echo $tot["sin_pago"]; ?> sin pago</span>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <h2 class="rd-h2">Ruta por horario</h2>
    <?php foreach ($porHora as $hora => $puntos): ?>
        <?php $comidasHora = array_sum(array_column($puntos, "comidas")); ?>
        <section class="rd-hora">
            <div class="rd-hora__cab">
                <span class="rd-hora__hora"><?php echo horaBonita($hora); ?></span>
                <span class="rd-hora__total"><?php echo $comidasHora; ?> comida<?php echo $comidasHora !== 1 ? "s" : ""; ?></span>
            </div>

            <?php foreach ($puntos as $punto => $g): ?>
                <?php $aviso = $avisoPorHorario[$g["id_horario"]] ?? null; ?>
                <details class="rd-punto">
                    <summary>
                        <span class="rd-punto__nombre"><?php echo htmlspecialchars($punto); ?></span>
                        <span class="rd-punto__datos">
                            <b><?php echo $g["comidas"]; ?></b>
                            <?php if ($aviso): ?>
                                <span class="rd-aviso" title="Aviso de llegada mandado">✓ Aviso <?php echo horaBonita($aviso); ?></span>
                            <?php endif; ?>
                        </span>
                        <?php if (!empty($g["extras"])): ?>
                            <span class="rd-punto__extras">
                                <?php foreach ($g["extras"] as $nombre => $cant): ?>
                                    <span>+<?php echo $cant; ?> <?php echo htmlspecialchars($nombre); ?></span>
                                <?php endforeach; ?>
                            </span>
                        <?php endif; ?>
                    </summary>

                    <ul class="rd-lista">
                        <?php foreach ($g["pedidos"] as $p): ?>
                            <li>
                                <div class="rd-lista__fila">
                                    <span class="rd-lista__nombre"><?php echo htmlspecialchars($p["nombre_cliente"]); ?></span>
                                    <span class="rd-lista__menus"><?php echo (int)$p["num_menus"]; ?></span>
                                </div>
                                <div class="rd-lista__fila rd-lista__fila--sec">
                                    <span><?php echo htmlspecialchars($p["folio"]); ?> · $<?php echo number_format((float)$p["total"], 0); ?></span>
                                    <?php echo etiquetaPago($p); ?>
                                </div>
                                <div class="wa-estados" data-wa-id="<?php echo (int)$p["id_pedido"]; ?>"><?php
                                    echo etiquetaWA("Confirmación", $p["wa_confirmacion"]) . etiquetaWA("Llegada", $p["wa_llegada"]);
                                ?></div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </details>
            <?php endforeach; ?>
        </section>
    <?php endforeach; ?>

    <h2 class="rd-h2">Producción</h2>
    <section class="rd-prod">
        <?php foreach ($platos as $nombre => $pl): ?>
            <div class="rd-prod__fila">
                <span>
                    <?php echo htmlspecialchars($nombre); ?>
                    <?php if (!empty($pl["opciones"])): ?>
                        <small><?php
                            arsort($pl["opciones"]);
                            echo htmlspecialchars(implode(" · ", array_map(fn($o, $n) => "$o $n", array_keys($pl["opciones"]), $pl["opciones"])));
                        ?></small>
                    <?php endif; ?>
                </span>
                <b><?php echo $pl["total"]; ?></b>
            </div>
        <?php endforeach; ?>
    </section>

    <?php if ($complementos): ?>
    <h3 class="rd-h3">Complementos y aguas</h3>
    <section class="rd-prod rd-prod--sec">
        <?php foreach ($complementos as $nombre => $cant): ?>
            <div class="rd-prod__fila">
                <span><?php echo htmlspecialchars($nombre); ?></span>
                <b><?php echo $cant; ?></b>
            </div>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>

    <?php endif; ?>

</div>

<?php if ($esHoy): ?>
<script>
// Actualiza cada minuto sin perder el lugar ni los puntos abiertos
(function () {
    var CLAVE = "rd_estado";
    try {
        var guardado = JSON.parse(sessionStorage.getItem(CLAVE) || "null");
        if (guardado) {
            sessionStorage.removeItem(CLAVE);
            document.querySelectorAll("details.rd-punto").forEach(function (d, i) {
                if (guardado.abiertos.indexOf(i) !== -1) d.open = true;
            });
            window.scrollTo(0, guardado.y || 0);
        }
    } catch (e) {}

    function recargar() {
        try {
            var abiertos = [];
            document.querySelectorAll("details.rd-punto").forEach(function (d, i) { if (d.open) abiertos.push(i); });
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
