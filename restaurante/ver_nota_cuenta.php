<?php
require_once "../config/db.php";
require_once "auth_check.php";

$id = isset($_GET["id"]) ? (int)$_GET["id"] : 0;
if ($id <= 0) { header("Location: notas_cuenta.php"); exit; }

$stmtN = $conexion->prepare("SELECT * FROM notas_cuenta WHERE id_nota = :id LIMIT 1");
$stmtN->execute([":id" => $id]);
$nota = $stmtN->fetch(PDO::FETCH_ASSOC);
if (!$nota) { header("Location: notas_cuenta.php"); exit; }

$stmtI = $conexion->prepare("SELECT * FROM notas_cuenta_items WHERE id_nota = :id ORDER BY fecha ASC, id_item ASC");
$stmtI->execute([":id" => $id]);
$items = $stmtI->fetchAll(PDO::FETCH_ASSOC);

// Agrupar por día
$porDia = [];
foreach ($items as $item) {
    $porDia[$item["fecha"]]["items"][] = $item;
    $porDia[$item["fecha"]]["subtotal"] = ($porDia[$item["fecha"]]["subtotal"] ?? 0) + (float)$item["subtotal"];
}
ksort($porDia);

function fmtPeso($n) { return "$" . number_format((float)$n, 2, ".", ","); }
function fmtFecha($f) {
    $dias = ["Sunday"=>"Dom","Monday"=>"Lun","Tuesday"=>"Mar","Wednesday"=>"Mié","Thursday"=>"Jue","Friday"=>"Vie","Saturday"=>"Sáb"];
    $ts = strtotime($f);
    return ($dias[date("l", $ts)] ?? "") . " " . date("d/m/Y", $ts);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require __DIR__ . "/../includes/rz_app.php"; ?>
    <title><?php echo htmlspecialchars($nota["folio"]); ?> | Zabisu</title>
    <link rel="icon" type="image/png" href="../assets/img/LOGO_NARA.png">
    <meta name="theme-color" content="#151413">
    <link rel="preload" href="../assets/fonts/kaushan-script-400.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="../assets/fonts/alfa-slab-one-400.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="../assets/css/menu-pizarron.css?v=5">
    <link rel="stylesheet" href="../assets/css/notas-pizarron.css?v=1">
</head>
<body class="pz">
<?php require __DIR__ . "/../includes/pz_filtros.php"; ?>

<!-- PANTALLA (no se imprime) -->
<main class="nc no-print">
    <header class="nc-cab">
        <div class="pz-marca">
            <img src="../assets/img/LOGO_N_128.png" alt="">
            <div>
                <span class="pz-marca__nombre">Zabisu</span>
                <span class="pz-marca__lema">Sabor y Servicio</span>
            </div>
        </div>
        <h1 class="pz-titulo"><span class="pz-pincel"><span>Nota de cuenta</span></span></h1>
        <nav class="nc-cab__acc" aria-label="Navegación">
            <a href="notas_cuenta.php" class="nc-btn">← Notas de cuenta</a>
        </nav>
    </header>

    <div class="nc-folio">
        <b><?php echo htmlspecialchars($nota["folio"]); ?></b>
        <span class="estado <?php echo $nota["estado"] === "abierta" ? "estado-pendiente" : "estado-pagado"; ?>">
            <?php echo $nota["estado"] === "abierta" ? "Abierta" : "Cerrada / pagada"; ?>
        </span>
    </div>

    <?php if (isset($_GET["nueva"])): ?>
    <div class="nc-aviso">
        <span class="nc-aviso__icono">✓</span>
        <div>
            <strong>Nota creada correctamente</strong>
            <p>Puedes seguir agregando días conforme pasen, o imprimirla cuando el cliente pague.</p>
        </div>
    </div>
    <?php endif; ?>
    <?php if (isset($_GET["agregado"])): ?>
    <div class="nc-aviso">
        <span class="nc-aviso__icono">✓</span>
        <div><strong>Día(s) agregado(s) correctamente.</strong></div>
    </div>
    <?php endif; ?>
    <?php if (isset($_GET["editada"])): ?>
    <div class="nc-aviso">
        <span class="nc-aviso__icono">✓</span>
        <div><strong>Nota actualizada correctamente.</strong></div>
    </div>
    <?php endif; ?>

    <!-- ACCIONES -->
    <div class="nc-acciones">
        <a href="agregar_dias_nota.php?id=<?php echo $id; ?>" class="nc-btn--naranja">+ Agregar más días</a>
        <a href="editar_nota_cuenta.php?id=<?php echo $id; ?>" class="nc-btn">Editar nota</a>
        <a href="ticket_nota_cuenta.php?id=<?php echo $id; ?>" target="_blank" class="nc-btn nc-btn--estraza">Imprimir ticket</a>
        <button type="button" onclick="window.print();" class="nc-btn">Imprimir / PDF</button>
    </div>

    <!-- INFO CLIENTE -->
    <section class="nc-sec">
        <h2 class="pz-cinta pz-fibra">Cliente</h2>
        <div class="nc-tarjeta nc-cliente">
            <p class="nc-cliente__nombre"><?php echo htmlspecialchars($nota["nombre_cliente"]); ?></p>
            <?php if ($nota["telefono"]): ?><p class="nc-cliente__dato">Tel: <b><?php echo htmlspecialchars($nota["telefono"]); ?></b></p><?php endif; ?>
            <p class="nc-cliente__dato">Creada: <b><?php echo date("d/m/Y", strtotime($nota["created_at"])); ?></b></p>
        </div>
    </section>

    <!-- DESGLOSE POR DÍA -->
    <section class="nc-sec">
        <h2 class="pz-cinta pz-fibra">Desglose por día</h2>
        <?php if (empty($porDia)): ?>
            <p class="nc-tarjeta nota-formulario">Esta nota todavía no tiene días agregados.</p>
        <?php else: ?>
        <div class="nc-comanda">
            <table class="nc-tabla">
                <thead>
                    <tr>
                        <th>Descripción</th>
                        <th>Cantidad</th>
                        <th>Precio unitario</th>
                        <th>Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($porDia as $fecha => $grupo): ?>
                    <tr class="nc-dia-header"><td colspan="4"><?php echo fmtFecha($fecha); ?></td></tr>
                    <?php foreach ($grupo["items"] as $item): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($item["descripcion"]); ?></td>
                        <td class="td-num"><?php echo number_format((float)$item["cantidad"], 0); ?></td>
                        <td class="td-num"><?php echo fmtPeso($item["precio_unitario"]); ?></td>
                        <td class="td-subtotal"><?php echo fmtPeso($item["subtotal"]); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="nc-dia-subtotal"><td colspan="3">Subtotal del día</td><td><?php echo fmtPeso($grupo["subtotal"]); ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="nc-total-fila">
                <span>TOTAL DE LA NOTA</span>
                <span><?php echo fmtPeso($nota["total"]); ?></span>
            </div>
        </div>
        <?php endif; ?>
    </section>

    <?php if ($nota["notas"]): ?>
    <section class="nc-sec">
        <h2 class="pz-cinta pz-fibra">Notas</h2>
        <p class="nc-tarjeta nc-notas"><?php echo htmlspecialchars($nota["notas"]); ?></p>
    </section>
    <?php endif; ?>
</main><!-- /no-print -->

    <!-- ÁREA DE IMPRESIÓN (PDF) -->
    <div id="print-area">
        <div class="prt">
            <div class="prt-topbar"></div>
            <div class="prt-accent"></div>
            <div class="prt-body">

                <div class="prt-header">
                    <div class="prt-brand">
                        <img class="prt-brand__logo" src="../assets/img/dd.png" alt="Zabisu">
                        <div class="prt-brand__texto">
                            <h1>Zabisu</h1>
                            <p>Sabor y Servicio</p>
                        </div>
                    </div>
                    <div class="prt-doc">
                        <span class="prt-doc__etiqueta">Nota de cuenta</span>
                        <span class="prt-doc__folio"><?php echo htmlspecialchars($nota["folio"]); ?></span>
                        <p>Creada:&nbsp; <?php echo date("d/m/Y", strtotime($nota["created_at"])); ?></p>
                        <p>Estado:&nbsp; <strong><?php echo $nota["estado"] === "abierta" ? "Abierta" : "Cerrada / pagada"; ?></strong></p>
                    </div>
                </div>

                <div class="prt-info-bloque">
                    <h4>Cliente</h4>
                    <p><strong>Nombre </strong><?php echo htmlspecialchars($nota["nombre_cliente"]); ?></p>
                    <?php if ($nota["telefono"]): ?><p><strong>Tel. </strong><?php echo htmlspecialchars($nota["telefono"]); ?></p><?php endif; ?>
                </div>

                <table class="prt-tabla">
                    <thead>
                        <tr>
                            <th style="width:50%;">Descripción</th>
                            <th style="width:14%;text-align:right;">Cant.</th>
                            <th style="width:18%;text-align:right;">P. Unitario</th>
                            <th style="width:18%;text-align:right;">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($porDia as $fecha => $grupo): ?>
                        <tr class="prt-dia-header"><td colspan="4"><?php echo fmtFecha($fecha); ?></td></tr>
                        <?php foreach ($grupo["items"] as $item): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($item["descripcion"]); ?></td>
                            <td><?php echo number_format((float)$item["cantidad"], 0); ?></td>
                            <td><?php echo fmtPeso($item["precio_unitario"]); ?></td>
                            <td><?php echo fmtPeso($item["subtotal"]); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="prt-dia-subtotal"><td colspan="3">Subtotal del día</td><td><?php echo fmtPeso($grupo["subtotal"]); ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="prt-total-wrap">
                    <div class="prt-total-box">
                        <span class="prt-total-box__label">Total</span>
                        <span class="prt-total-box__valor"><?php echo fmtPeso($nota["total"]); ?></span>
                    </div>
                </div>

                <?php if ($nota["notas"]): ?>
                <div class="prt-notas">
                    <h4>Notas</h4>
                    <p><?php echo nl2br(htmlspecialchars($nota["notas"])); ?></p>
                </div>
                <?php endif; ?>

                <div class="prt-footer">
                    <p>Zabisu &mdash; Sabor y Servicio</p>
                </div>

            </div>
        </div>
    </div>

</body>
</html>
