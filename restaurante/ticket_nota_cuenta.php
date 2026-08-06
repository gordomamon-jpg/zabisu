<?php
require_once "../config/db.php";
require_once "auth_check.php";

$id = isset($_GET["id"]) ? (int)$_GET["id"] : 0;
if ($id <= 0) { die("No se recibió una nota válida."); }

$stmtN = $conexion->prepare("SELECT * FROM notas_cuenta WHERE id_nota = :id LIMIT 1");
$stmtN->execute([":id" => $id]);
$nota = $stmtN->fetch(PDO::FETCH_ASSOC);
if (!$nota) { die("No se encontró la nota."); }

$stmtI = $conexion->prepare("SELECT * FROM notas_cuenta_items WHERE id_nota = :id ORDER BY fecha ASC, id_item ASC");
$stmtI->execute([":id" => $id]);
$items = $stmtI->fetchAll(PDO::FETCH_ASSOC);

$porDia = [];
foreach ($items as $item) {
    $porDia[$item["fecha"]]["items"][] = $item;
    $porDia[$item["fecha"]]["subtotal"] = ($porDia[$item["fecha"]]["subtotal"] ?? 0) + (float)$item["subtotal"];
}
ksort($porDia);

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
    <title>Ticket <?php echo htmlspecialchars($nota["folio"]); ?></title>
    <style>
        * { box-sizing: border-box; }
        body { margin:0; padding:0; background:#fff; color:#000; font-family:Arial, Helvetica, sans-serif; font-size:12px; }
        .ticket { width:80mm; margin:0 auto; padding:6mm 4mm; }
        .ticket-header { display:flex; align-items:center; gap:8px; margin-bottom:4px; }
        .ticket-logo { width:18mm; height:18mm; object-fit:contain; flex-shrink:0; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
        .ticket-header__info { flex:1; }
        .brand { font-size:18px; font-weight:800; letter-spacing:1px; margin-bottom:2px; }
        .folio { font-size:16px; font-weight:800; margin-bottom:2px; }
        .small { font-size:11px; line-height:1.35; }
        .line { border-top:1px dashed #000; margin:8px 0; }
        .bloque-prioridad { border:1px solid #000; padding:8px 6px; margin:8px 0; }
        .bloque-prioridad__titulo { text-align:center; font-size:11px; font-weight:800; margin-bottom:6px; letter-spacing:.5px; }
        .bloque-prioridad__fila { display:flex; justify-content:space-between; align-items:baseline; gap:8px; margin:4px 0; line-height:1.35; }
        .bloque-prioridad__label { font-size:10px; font-weight:800; min-width:58px; }
        .bloque-prioridad__valor { text-align:right; font-size:13px; font-weight:700; flex:1; }
        .bloque-prioridad__valor--grande { font-size:16px; font-weight:800; }
        .dia-title { font-size:12px; font-weight:800; text-transform:uppercase; background:#000; color:#fff; padding:3px 6px; margin:10px 0 6px; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
        .item-row { display:flex; justify-content:space-between; gap:8px; margin:2px 0; line-height:1.35; }
        .item-row .desc { flex:1; }
        .dia-sub { display:flex; justify-content:space-between; font-weight:800; margin-top:4px; padding-top:4px; border-top:1px dashed #000; }
        .total { font-size:15px; font-weight:800; }
        .row { display:flex; justify-content:space-between; gap:8px; margin:2px 0; line-height:1.35; }
        .footer { text-align:center; margin-top:10px; font-size:11px; }
        @media print {
            @page { size:80mm auto; margin:4mm; }
            html, body { width:80mm; }
            body { padding:0; margin:0; }
            .ticket { width:100%; margin:0; padding:0; }
        }
    </style>
</head>
<body>

<div class="ticket">
    <div class="ticket-header">
        <img class="ticket-logo" src="../assets/img/dd.png" alt="Zabisu">
        <div class="ticket-header__info">
            <div class="brand">ZABISU</div>
            <div class="folio"><?php echo htmlspecialchars($nota["folio"]); ?></div>
            <div class="small">Nota de cuenta</div>
        </div>
    </div>

    <div class="line"></div>

    <div class="bloque-prioridad">
        <div class="bloque-prioridad__titulo">CLIENTE DE CRÉDITO</div>
        <div class="bloque-prioridad__fila">
            <span class="bloque-prioridad__label">CLIENTE</span>
            <span class="bloque-prioridad__valor bloque-prioridad__valor--grande"><?php echo htmlspecialchars($nota["nombre_cliente"]); ?></span>
        </div>
        <?php if ($nota["telefono"]): ?>
        <div class="bloque-prioridad__fila">
            <span class="bloque-prioridad__label">TEL.</span>
            <span class="bloque-prioridad__valor"><?php echo htmlspecialchars($nota["telefono"]); ?></span>
        </div>
        <?php endif; ?>
        <div class="bloque-prioridad__fila">
            <span class="bloque-prioridad__label">ESTADO</span>
            <span class="bloque-prioridad__valor"><?php echo $nota["estado"] === "abierta" ? "Abierta" : "Cerrada / pagada"; ?></span>
        </div>
    </div>

    <?php foreach ($porDia as $fecha => $grupo): ?>
        <div class="dia-title"><?php echo fmtFecha($fecha); ?></div>
        <?php foreach ($grupo["items"] as $item): ?>
        <div class="item-row">
            <span class="desc"><?php echo htmlspecialchars($item["descripcion"]); ?> ×<?php echo number_format((float)$item["cantidad"], 0); ?></span>
            <span>$<?php echo number_format((float)$item["subtotal"], 2); ?></span>
        </div>
        <?php endforeach; ?>
        <div class="dia-sub">
            <span>Subtotal día</span>
            <span>$<?php echo number_format($grupo["subtotal"], 2); ?></span>
        </div>
    <?php endforeach; ?>

    <div class="line"></div>

    <div class="row total">
        <div>TOTAL</div>
        <div>$<?php echo number_format((float)$nota["total"], 2); ?></div>
    </div>

    <?php if ($nota["notas"]): ?>
    <div class="line"></div>
    <div class="small"><?php echo nl2br(htmlspecialchars($nota["notas"])); ?></div>
    <?php endif; ?>

    <div class="footer">Zabisu — Sabor y Servicio</div>
</div>

<script>
window.onload = function () {
    window.print();
};
</script>

</body>
</html>
