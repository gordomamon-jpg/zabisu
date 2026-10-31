<?php
require_once "../config/db.php";
require_once "auth_check.php";
require_once "../includes/gastos_frecuentes.php";

/*
    Hoja de gastos para imprimir y llenar a mano durante la semana.
    Las compras van por día (una casilla por día); los sueldos son por
    semana (una sola casilla). Al cerrar la semana se pasa a
    gastos_semana.php, que tiene la misma lista en el mismo orden.

    ?semana=AAAA-MM-DD para otra semana (cualquier día; se toma su lunes).
*/

function lunesDe(string $date): string {
    $ts = strtotime($date);
    return date('Y-m-d', strtotime('-' . ((int)date('N', $ts) - 1) . ' days', $ts));
}

$hoy = date('Y-m-d');
if (isset($_GET['semana']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['semana']) && strtotime($_GET['semana'])) {
    $lunes = lunesDe($_GET['semana']);
} else {
    // Igual que gastos_semana.php: el fin de semana ya es la siguiente
    $lunes = (int)date('N') >= 6 ? date('Y-m-d', strtotime('next monday')) : lunesDe($hoy);
}
$anterior  = date('Y-m-d', strtotime($lunes . ' -7 days'));
$siguiente = date('Y-m-d', strtotime($lunes . ' +7 days'));

$meses = ['ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];
$dias  = [];
foreach (['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'] as $i => $d) {
    $ts = strtotime($lunes . " +$i days");
    $dias[] = ['nombre' => $d, 'num' => date('j', $ts)];
}
$sabTs  = strtotime($lunes . ' +5 days');
$lunTs  = strtotime($lunes);
$titulo = date('j', $lunTs) . (date('n', $lunTs) !== date('n', $sabTs) ? ' ' . $meses[(int)date('n', $lunTs) - 1] : '')
        . ' al ' . date('j', $sabTs) . ' de ' . $meses[(int)date('n', $sabTs) - 1] . ' ' . date('Y', $sabTs);

[, $compras, $sueldos] = gastosFrecuentes($conexion);

// Renglones en blanco para lo que no está en la lista; la hoja cabe en una carta
$enBlanco = max(4, 17 - count($compras));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hoja de gastos <?php echo htmlspecialchars($titulo); ?> | Zabisu</title>
    <link rel="icon" type="image/png" href="../assets/img/LOGO_NARA.png">
<style>
@font-face{font-family:'Alfa Slab One';src:url(../assets/fonts/alfa-slab-one-400.woff2) format('woff2');font-display:swap}
@page{size:letter portrait;margin:10mm}
*{box-sizing:border-box}
body{margin:0;background:#3a3633;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:#111}

/* ── Barra de pantalla (no se imprime) ── */
.hg-barra{display:flex;align-items:center;gap:8px;flex-wrap:wrap;max-width:216mm;margin:0 auto;padding:16px}
.hg-barra a,.hg-barra button{display:inline-flex;align-items:center;justify-content:center;min-height:44px;padding:0 16px;
  border-radius:12px;border:0;background:#201E1C;color:#ECEBE7;font:600 15px inherit;text-decoration:none;cursor:pointer}
.hg-barra .hg-imprimir{margin-left:auto;background:#FF7A00;color:#111}

/* ── La hoja ── */
.hg-hoja{width:216mm;min-height:279mm;margin:0 auto 24px;padding:10mm;background:#fff}
.hg-cab{display:flex;align-items:flex-end;gap:12px;padding-bottom:3mm;border-bottom:2px solid #111}
.hg-cab img{width:13mm;height:13mm}
.hg-cab h1{margin:0;font:400 20pt/1 'Alfa Slab One',Georgia,serif}
.hg-cab p{margin:1mm 0 0;font-size:11pt}
.hg-firma{margin-left:auto;font-size:10pt;white-space:nowrap}
.hg-firma span{display:inline-block;width:45mm;border-bottom:1px solid #111}
.hg-ayuda{margin:2.5mm 0 3mm;font-size:9pt;color:#333}

table{width:100%;border-collapse:collapse;table-layout:fixed}
th,td{border:1px solid #111;font-size:10pt}
thead th{height:10mm;background:#eee;font-weight:700;text-align:center;line-height:1.1}
thead th small{display:block;font-weight:400;font-size:8.5pt}
thead th:first-child{width:36mm;text-align:left;padding-left:2mm}
thead th:last-child{width:22mm}
tbody td{height:9.6mm}
tbody td:first-child{padding:0 2mm;font-weight:600}
td.hg-total{background:#f6f6f6}
tr.hg-grupo td{height:6mm;background:#111;color:#fff;font-size:9pt;font-weight:700;letter-spacing:.5px;text-transform:uppercase;padding-left:2mm}
tr.hg-grupo td small{text-transform:none;letter-spacing:0;font-weight:400;margin-left:2mm}
td.hg-semana{background:#fff}
tfoot td{height:10mm;font-weight:700;background:#eee}
tfoot td:first-child{padding-left:2mm}

.hg-pie{margin-top:4mm;font-size:9pt;color:#333}

@media print{
  body{background:#fff}
  .hg-barra{display:none}
  .hg-hoja{width:auto;min-height:0;margin:0;padding:0}
  *{-webkit-print-color-adjust:exact;print-color-adjust:exact}
}
</style>
</head>
<body>

<nav class="hg-barra" aria-label="Cambiar de semana">
    <a href="gastos_semana.php?semana=<?php echo $lunes; ?>">← Gastos</a>
    <a href="?semana=<?php echo $anterior; ?>" aria-label="Semana anterior">‹</a>
    <a href="?semana=<?php echo $siguiente; ?>" aria-label="Semana siguiente">›</a>
    <button type="button" class="hg-imprimir" onclick="window.print()">Imprimir</button>
</nav>

<main class="hg-hoja">
    <header class="hg-cab">
        <img src="../assets/img/LOGO_NARA.png" alt="">
        <div>
            <h1>Gastos de la semana</h1>
            <p><?php echo htmlspecialchars($titulo); ?></p>
        </div>
        <p class="hg-firma">Anotó: <span></span></p>
    </header>

    <p class="hg-ayuda">Anota lo que se gastó en la casilla del día. Si hubo varios tickets, escríbelos todos en la misma casilla (ej. 138 + 906).</p>

    <table>
        <thead>
            <tr>
                <th>Gasto</th>
                <?php foreach ($dias as $d): ?>
                    <th><?php echo $d['nombre']; ?><small><?php echo $d['num']; ?></small></th>
                <?php endforeach; ?>
                <th>Total</th>
            </tr>
        </thead>
        <tbody>
            <tr class="hg-grupo"><td colspan="8">Compras <small>por día</small></td></tr>
            <?php foreach ($compras as $c): ?>
            <tr>
                <td><?php echo htmlspecialchars($c['nombre']); ?></td>
                <?php foreach ($dias as $d): ?><td></td><?php endforeach; ?>
                <td class="hg-total"></td>
            </tr>
            <?php endforeach; ?>
            <?php for ($i = 0; $i < $enBlanco; $i++): ?>
            <tr>
                <td></td>
                <?php foreach ($dias as $d): ?><td></td><?php endforeach; ?>
                <td class="hg-total"></td>
            </tr>
            <?php endfor; ?>

            <?php if ($sueldos): ?>
            <tr class="hg-grupo"><td colspan="8">Sueldos <small>uno por semana</small></td></tr>
            <?php foreach ($sueldos as $c): ?>
            <tr>
                <td><?php echo htmlspecialchars($c['nombre']); ?></td>
                <td colspan="6" class="hg-semana"></td>
                <td class="hg-total"></td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
        <tfoot>
            <tr>
                <td>Total del día</td>
                <?php foreach ($dias as $d): ?><td></td><?php endforeach; ?>
                <td></td>
            </tr>
        </tfoot>
    </table>

    <p class="hg-pie">Al terminar la semana, pásalo en el panel: Gastos de la semana. Ahí viene la misma lista en el mismo orden.</p>
</main>

</body>
</html>
