<?php
require_once "../config/db.php";
require_once "auth_check.php";

$id = isset($_GET["id"]) ? (int)$_GET["id"] : 0;
if ($id <= 0) { header("Location: cotizaciones.php"); exit; }

$stmtC = $conexion->prepare("SELECT * FROM cotizaciones WHERE id_cotizacion = :id LIMIT 1");
$stmtC->execute([":id" => $id]);
$cot = $stmtC->fetch(PDO::FETCH_ASSOC);
if (!$cot) { header("Location: cotizaciones.php"); exit; }

$stmtI = $conexion->prepare("SELECT * FROM cotizacion_items WHERE id_cotizacion = :id ORDER BY id_item");
$stmtI->execute([":id" => $id]);
$items = $stmtI->fetchAll(PDO::FETCH_ASSOC);

// Cambiar estado
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["nuevo_estado"])) {
    $estadosValidos = ["borrador", "enviada", "aceptada", "rechazada"];
    $nuevoEstado = $_POST["nuevo_estado"] ?? "";
    if (in_array($nuevoEstado, $estadosValidos)) {
        $conexion->prepare("UPDATE cotizaciones SET estado = :estado WHERE id_cotizacion = :id")
                 ->execute([":estado" => $nuevoEstado, ":id" => $id]);
        $cot["estado"] = $nuevoEstado;
    }
}

function fmtPeso($n) { return "$" . number_format((float)$n, 2, ".", ","); }

$estadoClase = [
    "borrador"  => "estado estado-pendiente",
    "enviada"   => "estado estado-revision",
    "aceptada"  => "estado estado-pagado",
    "rechazada" => "estado",
];
$estadoLabel = [
    "borrador" => "Borrador", "enviada" => "Enviada",
    "aceptada" => "Aceptada", "rechazada" => "Rechazada",
];

$vigenciaFecha = date("d/m/Y", strtotime($cot["fecha_cotizacion"] . " +" . (int)$cot["vigencia_dias"] . " days"));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($cot["folio"]); ?> | Zabisu</title>
    <link rel="icon" type="image/png" href="../assets/img/LOGO_NARA.png">
    <link rel="stylesheet" href="../assets/css/styles.css?v=5">
    <style>
        /* ── PANTALLA ── */
        .cot-tabla { width:100%; border-collapse:collapse; }
        .cot-tabla th { font-size:12px; font-weight:600; color:var(--texto-secundario); text-align:left; padding:8px 12px; border-bottom:1px solid var(--borde); text-transform:uppercase; letter-spacing:.04em; }
        .cot-tabla td { padding:10px 12px; border-bottom:1px solid var(--borde,#222); font-size:14px; }
        .cot-tabla tr:last-child td { border-bottom:none; }
        .cot-tabla .td-subtotal { font-weight:700; text-align:right; }
        .cot-tabla .td-num { text-align:right; color:var(--texto-secundario); }
        .cot-total-fila { display:flex; justify-content:flex-end; gap:20px; align-items:baseline; padding:16px 12px 0; border-top:2px solid var(--borde); }
        .cot-info-grid { display:grid; grid-template-columns:1fr 1fr; gap:24px; }
        @media(max-width:600px) { .cot-info-grid { grid-template-columns:1fr; } }
        .cot-info-bloque h4 { font-size:12px; font-weight:600; color:var(--texto-secundario); text-transform:uppercase; letter-spacing:.06em; margin:0 0 10px; }
        .cot-info-bloque p { font-size:14px; margin:0 0 6px; }
        .cot-info-bloque p span { color:var(--texto-secundario); }

        /* ── IMPRESIÓN ── */
        @media print {
            .no-print { display:none !important; }
            body { background:#fff !important; color:#111 !important; margin:0; }
            .contenedor { max-width:100% !important; padding:0 !important; }
            .hero-zabisu, .bloque-formulario { display:none !important; }
            #print-area { display:block !important; }
        }
        #print-area { display:none; }

        /* ── LAYOUT IMPRESIÓN ── */
        .prt { font-family: 'Helvetica Neue', Arial, sans-serif; color:#111; max-width:800px; margin:0 auto; padding:32px 40px; }
        .prt-header { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:28px; }
        .prt-header__logo { height:56px; }
        .prt-header__marca { }
        .prt-header__marca h1 { font-size:28px; font-weight:900; color:#111; margin:0; letter-spacing:-.5px; }
        .prt-header__marca p { font-size:13px; color:#666; margin:2px 0 0; }
        .prt-header__doc { text-align:right; }
        .prt-header__doc h2 { font-size:22px; font-weight:800; color:#111; margin:0 0 4px; letter-spacing:.05em; }
        .prt-header__doc p { font-size:13px; color:#444; margin:2px 0; }
        .prt-divider { border:none; border-top:2px solid #111; margin:0 0 20px; }
        .prt-info { display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:24px; }
        .prt-info-bloque { border:1px solid #ddd; border-radius:6px; padding:14px 16px; }
        .prt-info-bloque h4 { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:#888; margin:0 0 8px; }
        .prt-info-bloque p { font-size:13px; margin:0 0 4px; }
        .prt-info-bloque p strong { display:inline-block; min-width:60px; color:#888; font-weight:400; }
        .prt-tabla { width:100%; border-collapse:collapse; margin-bottom:16px; }
        .prt-tabla th { background:#111; color:#fff; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.06em; padding:8px 12px; text-align:left; }
        .prt-tabla th:last-child, .prt-tabla td:last-child { text-align:right; }
        .prt-tabla td { padding:10px 12px; border-bottom:1px solid #eee; font-size:13px; }
        .prt-tabla tr:last-child td { border-bottom:2px solid #111; }
        .prt-total { display:flex; justify-content:flex-end; margin-bottom:24px; }
        .prt-total-box { border:2px solid #111; border-radius:6px; padding:12px 20px; text-align:right; }
        .prt-total-box span { font-size:12px; text-transform:uppercase; letter-spacing:.06em; color:#666; display:block; }
        .prt-total-box strong { font-size:26px; font-weight:900; }
        .prt-notas { border:1px solid #ddd; border-radius:6px; padding:14px 16px; margin-bottom:24px; }
        .prt-notas h4 { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:#888; margin:0 0 8px; }
        .prt-notas p { font-size:13px; margin:0; white-space:pre-wrap; }
        .prt-footer { display:flex; justify-content:space-between; align-items:flex-end; padding-top:20px; border-top:1px solid #ddd; }
        .prt-footer p { font-size:12px; color:#666; margin:0 0 4px; }
        .prt-firma { text-align:center; }
        .prt-firma__linea { border-top:1px solid #999; width:180px; margin:40px auto 6px; }
        .prt-firma__label { font-size:11px; color:#666; }
    </style>
</head>
<body>
<div class="contenedor">

    <!-- CABECERA PANEL (no se imprime) -->
    <div class="no-print">
        <div class="hero-zabisu">
            <div class="hero-zabisu__glow"></div>
            <div class="hero-zabisu__contenido">
                <p class="hero-zabisu__eyebrow">BBQ — COTIZACIÓN</p>
                <h1 class="hero-zabisu__titulo"><?php echo htmlspecialchars($cot["folio"]); ?></h1>
                <p class="hero-zabisu__texto"><?php echo htmlspecialchars($cot["nombre_cliente"]); ?><?php if ($cot["empresa"]): ?> &mdash; <?php echo htmlspecialchars($cot["empresa"]); ?><?php endif; ?></p>
                <a href="cotizaciones.php" class="btn-volver-panel">← Cotizaciones</a>
            </div>
        </div>

        <?php if (isset($_GET["nueva"])): ?>
        <div class="nm-exito" style="margin-bottom:0;">
            <span class="nm-exito__icono">✓</span>
            <div>
                <strong>Cotización creada correctamente</strong>
                <p>Revísala y cuando estés listo presiona Imprimir para generar el PDF.</p>
            </div>
        </div>
        <?php endif; ?>

        <!-- ACCIONES -->
        <div class="bloque-formulario">
            <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;">
                <button onclick="window.print();" class="btn-principal">🖨️ Imprimir / PDF</button>
                <a href="cotizaciones.php" class="btn-limpiar-filtros">← Volver a lista</a>
                <div style="margin-left:auto;display:flex;gap:10px;align-items:center;">
                    <span style="font-size:13px;color:var(--texto-secundario);">Estado:</span>
                    <form method="POST" style="margin:0;display:flex;gap:8px;align-items:center;">
                        <select name="nuevo_estado" style="background:var(--fondo-input,#111);border:1px solid var(--borde);border-radius:6px;color:var(--texto-principal);padding:7px 10px;font-size:13px;">
                            <option value="borrador"  <?php echo $cot["estado"]==="borrador"  ? "selected" : ""; ?>>Borrador</option>
                            <option value="enviada"   <?php echo $cot["estado"]==="enviada"   ? "selected" : ""; ?>>Enviada</option>
                            <option value="aceptada"  <?php echo $cot["estado"]==="aceptada"  ? "selected" : ""; ?>>Aceptada</option>
                            <option value="rechazada" <?php echo $cot["estado"]==="rechazada" ? "selected" : ""; ?>>Rechazada</option>
                        </select>
                        <button type="submit" class="btn-tabla" style="font-size:13px;">Guardar</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- INFO CLIENTE + EVENTO -->
        <div class="bloque-formulario">
            <div class="cot-info-grid">
                <div class="cot-info-bloque">
                    <h4>Cliente</h4>
                    <p><span>Nombre:</span> <strong><?php echo htmlspecialchars($cot["nombre_cliente"]); ?></strong></p>
                    <?php if ($cot["empresa"]): ?><p><span>Empresa:</span> <?php echo htmlspecialchars($cot["empresa"]); ?></p><?php endif; ?>
                    <?php if ($cot["telefono"]): ?><p><span>Tel:</span> <?php echo htmlspecialchars($cot["telefono"]); ?></p><?php endif; ?>
                    <?php if ($cot["correo"]): ?><p><span>Correo:</span> <?php echo htmlspecialchars($cot["correo"]); ?></p><?php endif; ?>
                </div>
                <div class="cot-info-bloque">
                    <h4>Evento</h4>
                    <?php if ($cot["fecha_evento"]): ?><p><span>Fecha:</span> <strong><?php echo date("d/m/Y", strtotime($cot["fecha_evento"])); ?></strong></p><?php endif; ?>
                    <?php if ($cot["lugar_evento"]): ?><p><span>Lugar:</span> <?php echo htmlspecialchars($cot["lugar_evento"]); ?></p><?php endif; ?>
                    <p><span>Cotización:</span> <?php echo date("d/m/Y", strtotime($cot["fecha_cotizacion"])); ?></p>
                    <p><span>Válida hasta:</span> <?php echo $vigenciaFecha; ?></p>
                </div>
            </div>
        </div>

        <!-- PRODUCTOS -->
        <div class="bloque-formulario">
            <h2 style="margin-bottom:16px;">Productos</h2>
            <div style="overflow-x:auto;">
                <table class="cot-tabla">
                    <thead>
                        <tr>
                            <th>Descripción</th>
                            <th style="text-align:right;">Cantidad</th>
                            <th style="text-align:right;">Precio unitario</th>
                            <th style="text-align:right;">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($item["descripcion"]); ?></td>
                            <td class="td-num"><?php echo number_format((float)$item["cantidad"], 2, ".", ","); ?></td>
                            <td class="td-num"><?php echo fmtPeso($item["precio_unitario"]); ?></td>
                            <td class="td-subtotal"><?php echo fmtPeso($item["subtotal"]); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="cot-total-fila">
                <span style="font-size:14px;color:var(--texto-secundario);">TOTAL</span>
                <span style="font-size:26px;font-weight:800;color:var(--zabisu-orange);"><?php echo fmtPeso($cot["total"]); ?></span>
            </div>
        </div>

        <?php if ($cot["notas"]): ?>
        <div class="bloque-formulario">
            <h2 style="margin-bottom:12px;">Notas y condiciones</h2>
            <p style="font-size:14px;white-space:pre-wrap;margin:0;"><?php echo htmlspecialchars($cot["notas"]); ?></p>
        </div>
        <?php endif; ?>
    </div><!-- /no-print -->

    <!-- ÁREA DE IMPRESIÓN -->
    <div id="print-area">
        <div class="prt">
            <div class="prt-header">
                <div style="display:flex;align-items:center;gap:14px;">
                    <img class="prt-header__logo" src="../assets/img/LOGO_NARA.png" alt="Zabisu">
                    <div class="prt-header__marca">
                        <h1>ZABISU</h1>
                        <p>BBQ &amp; Ahumados</p>
                    </div>
                </div>
                <div class="prt-header__doc">
                    <h2>COTIZACIÓN</h2>
                    <p><strong><?php echo htmlspecialchars($cot["folio"]); ?></strong></p>
                    <p>Fecha: <?php echo date("d/m/Y", strtotime($cot["fecha_cotizacion"])); ?></p>
                    <p>Válida hasta: <?php echo $vigenciaFecha; ?></p>
                </div>
            </div>
            <hr class="prt-divider">

            <div class="prt-info">
                <div class="prt-info-bloque">
                    <h4>Datos del cliente</h4>
                    <p><strong>Nombre: </strong><?php echo htmlspecialchars($cot["nombre_cliente"]); ?></p>
                    <?php if ($cot["empresa"]): ?><p><strong>Empresa: </strong><?php echo htmlspecialchars($cot["empresa"]); ?></p><?php endif; ?>
                    <?php if ($cot["telefono"]): ?><p><strong>Tel: </strong><?php echo htmlspecialchars($cot["telefono"]); ?></p><?php endif; ?>
                    <?php if ($cot["correo"]): ?><p><strong>Correo: </strong><?php echo htmlspecialchars($cot["correo"]); ?></p><?php endif; ?>
                </div>
                <div class="prt-info-bloque">
                    <h4>Detalles del evento</h4>
                    <?php if ($cot["fecha_evento"]): ?><p><strong>Fecha: </strong><?php echo date("d/m/Y", strtotime($cot["fecha_evento"])); ?></p><?php endif; ?>
                    <?php if ($cot["lugar_evento"]): ?><p><strong>Lugar: </strong><?php echo htmlspecialchars($cot["lugar_evento"]); ?></p><?php endif; ?>
                    <?php if (!$cot["fecha_evento"] && !$cot["lugar_evento"]): ?><p style="color:#aaa;">Sin detalles de evento</p><?php endif; ?>
                </div>
            </div>

            <table class="prt-tabla">
                <thead>
                    <tr>
                        <th style="width:50%;">Descripción</th>
                        <th style="width:12%;text-align:right;">Cant.</th>
                        <th style="width:18%;text-align:right;">P. Unitario</th>
                        <th style="width:20%;text-align:right;">Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($item["descripcion"]); ?></td>
                        <td style="text-align:right;"><?php echo number_format((float)$item["cantidad"], 2, ".", ","); ?></td>
                        <td style="text-align:right;"><?php echo fmtPeso($item["precio_unitario"]); ?></td>
                        <td style="text-align:right;font-weight:700;"><?php echo fmtPeso($item["subtotal"]); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="prt-total">
                <div class="prt-total-box">
                    <span>Total</span>
                    <strong><?php echo fmtPeso($cot["total"]); ?></strong>
                </div>
            </div>

            <?php if ($cot["notas"]): ?>
            <div class="prt-notas">
                <h4>Notas y condiciones</h4>
                <p><?php echo nl2br(htmlspecialchars($cot["notas"])); ?></p>
            </div>
            <?php endif; ?>

            <div class="prt-footer">
                <div>
                    <p>Esta cotización es válida hasta el <strong><?php echo $vigenciaFecha; ?></strong>.</p>
                    <p>Para más información: Zabisu BBQ &amp; Ahumados</p>
                </div>
                <div class="prt-firma">
                    <div class="prt-firma__linea"></div>
                    <p class="prt-firma__label">Autorizado por</p>
                </div>
            </div>
        </div>
    </div>

</div>
</body>
</html>
