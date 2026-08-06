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
    <title><?php echo htmlspecialchars($nota["folio"]); ?> | Zabisu</title>
    <link rel="icon" type="image/png" href="../assets/img/LOGO_NARA.png">
    <link rel="stylesheet" href="../assets/css/styles.css?v=5">
    <style>
        /* ── PANTALLA ── */
        .nc-tabla { width:100%; border-collapse:collapse; }
        .nc-tabla th { font-size:12px; font-weight:600; color:var(--texto-secundario); text-align:left; padding:8px 12px; border-bottom:1px solid var(--borde); text-transform:uppercase; letter-spacing:.04em; }
        .nc-tabla td { padding:9px 12px; border-bottom:1px solid var(--borde,#222); font-size:14px; }
        .nc-tabla .td-num { text-align:right; color:var(--texto-secundario); }
        .nc-tabla .td-subtotal { font-weight:700; text-align:right; }
        .nc-dia-header td { background:rgba(255,122,0,.06); font-weight:800; color:var(--zabisu-orange); border-bottom:1px solid var(--borde); padding-top:12px; padding-bottom:12px; }
        .nc-dia-subtotal td { font-weight:700; border-bottom:2px solid var(--borde); text-align:right; }
        .nc-total-fila { display:flex; justify-content:flex-end; gap:20px; align-items:baseline; padding:16px 12px 0; border-top:2px solid var(--borde); margin-top:8px; }

        /* ── IMPRESIÓN (PDF) ── */
        @page { margin: 0; }
        @media print {
            .no-print { display:none !important; }
            body { background:#fff !important; color:#111 !important; margin:0; padding:0; }
            html { background:#fff !important; }
            .contenedor { max-width:100% !important; padding:0 !important; margin:0 !important; background:#fff !important; border:none !important; box-shadow:none !important; }
            .contenedor::before, .contenedor::after { display:none !important; }
            .hero-zabisu, .bloque-formulario { display:none !important; }
            #print-area { display:block !important; }
            .prt { max-width:100% !important; width:100%; }
        }
        #print-area { display:none; }

        .prt { font-family:'Helvetica Neue',Arial,sans-serif; color:#111; max-width:780px; margin:0 auto; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
        .prt-topbar { background:#111; height:5px; }
        .prt-accent { background:#FF7A00; height:3px; }
        .prt-body { padding:20px 36px 26px; }
        .prt-header { display:flex; align-items:flex-start; gap:16px; padding-bottom:16px; border-bottom:1px solid #222; margin-bottom:16px; }
        .prt-brand { display:flex; align-items:center; gap:14px; flex:none; }
        .prt-brand__logo { height:58px; width:auto; object-fit:contain; display:block; }
        .prt-brand__texto h1 { font-size:17px; font-weight:900; color:#111; margin:0 0 3px; letter-spacing:-.2px; white-space:nowrap; }
        .prt-brand__texto p { font-size:10px; color:#555; margin:0; letter-spacing:.06em; text-transform:uppercase; white-space:nowrap; }
        .prt-doc { text-align:right; flex:none; margin-left:auto; }
        .prt-doc__etiqueta { font-size:9px; font-weight:600; text-transform:uppercase; letter-spacing:.16em; color:#777; display:block; margin-bottom:4px; }
        .prt-doc__folio { font-size:19px; font-weight:900; color:#111; display:block; margin-bottom:5px; }
        .prt-doc p { font-size:11px; color:#444; margin:2px 0; }
        .prt-info-bloque { border:1px solid #ccc; border-top:3px solid #111; padding:10px 14px; margin-bottom:16px; }
        .prt-info-bloque h4 { font-size:9px; font-weight:700; text-transform:uppercase; letter-spacing:.12em; color:#555; margin:0 0 7px; }
        .prt-info-bloque p { font-size:12px; margin:0 0 3px; color:#111; }
        .prt-info-bloque p strong { font-weight:400; color:#777; min-width:52px; display:inline-block; }
        .prt-tabla { width:100%; border-collapse:collapse; font-size:12px; margin-bottom:0; }
        .prt-tabla thead tr { background:#111; }
        .prt-tabla th { color:#fff; padding:8px 10px; font-size:9px; font-weight:600; text-transform:uppercase; letter-spacing:.09em; text-align:left; }
        .prt-tabla th:not(:first-child) { text-align:right; }
        .prt-tabla td { padding:7px 10px; border-bottom:1px solid #ddd; color:#111; }
        .prt-tabla td:not(:first-child) { text-align:right; }
        .prt-dia-header td { background:#f2f2f2; font-weight:800; border-bottom:1px solid #111; }
        .prt-dia-subtotal td { font-weight:700; border-bottom:2px solid #111; }
        .prt-total-wrap { display:flex; justify-content:flex-end; padding:14px 0 16px; }
        .prt-total-box { padding:10px 20px; text-align:right; min-width:190px; border-top:1px solid #ccc; border-bottom:1px solid #ccc; }
        .prt-total-box__label { font-size:9px; font-weight:600; text-transform:uppercase; letter-spacing:.14em; color:#555; display:block; margin-bottom:4px; }
        .prt-total-box__valor { font-size:28px; font-weight:900; color:#FF7A00; display:block; }
        .prt-notas { border:1px solid #ccc; border-top:3px solid #111; padding:10px 14px; margin-bottom:16px; }
        .prt-notas h4 { font-size:9px; font-weight:700; text-transform:uppercase; letter-spacing:.12em; color:#555; margin:0 0 6px; }
        .prt-notas p { font-size:12px; margin:0; line-height:1.6; color:#222; }
        .prt-footer { text-align:center; padding-top:14px; border-top:1px solid #ccc; }
        .prt-footer p { font-size:11px; color:#555; margin:0; }
    </style>
</head>
<body>
<div class="contenedor">

    <!-- CABECERA PANEL (no se imprime) -->
    <div class="no-print">
        <div class="hero-zabisu">
            <div class="hero-zabisu__glow"></div>
            <div class="hero-zabisu__contenido">
                <p class="hero-zabisu__eyebrow">CLIENTES DE CRÉDITO</p>
                <h1 class="hero-zabisu__titulo"><?php echo htmlspecialchars($nota["folio"]); ?></h1>
                <p class="hero-zabisu__texto"><?php echo htmlspecialchars($nota["nombre_cliente"]); ?></p>
                <a href="notas_cuenta.php" class="btn-volver-panel">← Notas de cuenta</a>
            </div>
        </div>

        <?php if (isset($_GET["nueva"])): ?>
        <div class="nm-exito" style="margin-bottom:0;">
            <span class="nm-exito__icono">✓</span>
            <div>
                <strong>Nota creada correctamente</strong>
                <p>Puedes seguir agregando días conforme pasen, o imprimirla cuando el cliente pague.</p>
            </div>
        </div>
        <?php endif; ?>
        <?php if (isset($_GET["agregado"])): ?>
        <div class="nm-exito" style="margin-bottom:0;">
            <span class="nm-exito__icono">✓</span>
            <div><strong>Día(s) agregado(s) correctamente.</strong></div>
        </div>
        <?php endif; ?>

        <!-- ACCIONES -->
        <div class="bloque-formulario">
            <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;">
                <a href="agregar_dias_nota.php?id=<?php echo $id; ?>" class="btn-principal">+ Agregar más días</a>
                <a href="ticket_nota_cuenta.php?id=<?php echo $id; ?>" target="_blank" class="btn-limpiar-filtros">🖨️ Imprimir ticket</a>
                <button onclick="window.print();" class="btn-limpiar-filtros">📄 Imprimir / PDF</button>
                <div style="margin-left:auto;">
                    <span class="estado <?php echo $nota["estado"] === "abierta" ? "estado-pendiente" : "estado-pagado"; ?>">
                        <?php echo $nota["estado"] === "abierta" ? "Abierta" : "Cerrada / pagada"; ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- INFO CLIENTE -->
        <div class="bloque-formulario">
            <div class="prt-info-bloque" style="border:1px solid var(--borde,#292929);border-top:3px solid var(--zabisu-orange);">
                <h4 style="color:var(--texto-secundario);">Cliente</h4>
                <p style="color:var(--texto-principal,#eee);"><span style="color:var(--texto-secundario);">Nombre:</span> <strong><?php echo htmlspecialchars($nota["nombre_cliente"]); ?></strong></p>
                <?php if ($nota["telefono"]): ?><p style="color:var(--texto-principal,#eee);"><span style="color:var(--texto-secundario);">Tel:</span> <?php echo htmlspecialchars($nota["telefono"]); ?></p><?php endif; ?>
                <p style="color:var(--texto-principal,#eee);"><span style="color:var(--texto-secundario);">Creada:</span> <?php echo date("d/m/Y", strtotime($nota["created_at"])); ?></p>
            </div>
        </div>

        <!-- DESGLOSE POR DÍA -->
        <div class="bloque-formulario">
            <h2 style="margin-bottom:16px;">Desglose por día</h2>
            <?php if (empty($porDia)): ?>
                <p style="color:var(--texto-secundario);">Esta nota todavía no tiene días agregados.</p>
            <?php else: ?>
            <div style="overflow-x:auto;">
                <table class="nc-tabla">
                    <thead>
                        <tr>
                            <th>Descripción</th>
                            <th style="text-align:right;">Cantidad</th>
                            <th style="text-align:right;">Precio unitario</th>
                            <th style="text-align:right;">Subtotal</th>
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
            </div>
            <div class="nc-total-fila">
                <span style="font-size:14px;color:var(--texto-secundario);">TOTAL DE LA NOTA</span>
                <span style="font-size:26px;font-weight:800;color:var(--zabisu-orange);"><?php echo fmtPeso($nota["total"]); ?></span>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($nota["notas"]): ?>
        <div class="bloque-formulario">
            <h2 style="margin-bottom:12px;">Notas</h2>
            <p style="font-size:14px;white-space:pre-wrap;margin:0;"><?php echo htmlspecialchars($nota["notas"]); ?></p>
        </div>
        <?php endif; ?>
    </div><!-- /no-print -->

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

</div>
</body>
</html>
