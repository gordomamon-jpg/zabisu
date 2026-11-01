<?php
require_once "../config/db.php";
require_once "auth_check.php";
require_once "../includes/gastos_frecuentes.php";

/*
    Hoja de gastos para imprimir y llenar a mano durante la semana.
    Las compras van por día, de domingo a jueves (una casilla por día).
    Los sueldos no van aquí. Al cerrar la semana se pasa a
    gastos_semana.php.

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
    // Igual que gastos_semana.php: el fin de semana ya es la siguiente (el domingo se surte para ella)
    $lunes = (int)date('N') >= 6 ? date('Y-m-d', strtotime('next monday')) : lunesDe($hoy);
}
$anterior  = date('Y-m-d', strtotime($lunes . ' -7 days'));
$siguiente = date('Y-m-d', strtotime($lunes . ' +7 days'));

$meses = ['ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];
$dias  = [];
// La semana de compras va del domingo (el día del surtido grande) al jueves (último día de venta)
foreach (['Dom', 'Lun', 'Mar', 'Mié', 'Jue'] as $i => $d) {
    $ts = strtotime($lunes . ' ' . ($i - 1) . ' days');
    $dias[] = ['nombre' => $d, 'num' => date('j', $ts)];
}
$iniTs  = strtotime($lunes . ' -1 day');
$finTs  = strtotime($lunes . ' +3 days');
$titulo = date('j', $iniTs) . (date('n', $iniTs) !== date('n', $finTs) ? ' ' . $meses[(int)date('n', $iniTs) - 1] : '')
        . ' al ' . date('j', $finTs) . ' de ' . $meses[(int)date('n', $finTs) - 1] . ' ' . date('Y', $finTs);

// Los sueldos no van en la hoja: se pagan una vez por semana y se anotan directo en gastos_semana.php
[, $compras, , $listaEditada] = gastosFrecuentes($conexion);

// La lista de siempre más renglones en blanco para lo demás
$filasBase = array_merge(array_column(array_values($compras), 'nombre'), array_fill(0, 5, ''));
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
tfoot td{height:10mm;font-weight:700;background:#eee}
tfoot td:first-child{padding-left:2mm}

/* ── Editar renglones antes de imprimir (solo en pantalla) ── */
td.hg-nombre{position:relative}
.hg-texto{display:block;min-height:1.3em;outline:none;overflow:hidden;white-space:nowrap;border-radius:3px}
.hg-texto:focus{background:#fff4e8;box-shadow:0 0 0 2px #FF7A00}
.hg-texto:empty::before{content:"Escribe o deja en blanco";color:#aaa;font-weight:400;font-size:9pt}
.hg-quitar{position:absolute;left:-11mm;top:50%;width:8mm;height:8mm;margin-top:-4mm;border:0;border-radius:50%;
  background:#ffe1e1;color:#b42318;font:700 14px/1 system-ui;cursor:pointer}
.hg-quitar:hover{background:#ffc9c9}
.hg-acciones{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:3mm}
.hg-acciones button{min-height:40px;padding:0 14px;border-radius:10px;font:600 14px system-ui;cursor:pointer}
.hg-agregar{border:1.5px dashed #FF7A00;background:#fff;color:#B84A00}
.hg-restablecer{border:0;background:none;color:#555;text-decoration:underline}
.hg-aviso{font-size:9.5pt;color:#B84A00}

.hg-pie{margin-top:4mm;font-size:9pt;color:#333}

@media print{
  body{background:#fff}
  .hg-barra,.hg-quitar,.hg-acciones{display:none}
  .hg-texto:empty::before{content:none}
  .hg-texto:focus{background:none;box-shadow:none;caret-color:transparent}
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
        <tbody id="hg-filas">
            <?php foreach ($filasBase as $nombre): ?>
            <tr>
                <td class="hg-nombre"><?php echo htmlspecialchars($nombre); ?></td>
                <?php foreach ($dias as $d): ?><td></td><?php endforeach; ?>
                <td class="hg-total"></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td>Total del día</td>
                <?php foreach ($dias as $d): ?><td></td><?php endforeach; ?>
                <td></td>
            </tr>
        </tfoot>
    </table>

    <div class="hg-acciones">
        <button type="button" class="hg-agregar" id="hg-agregar">+ Agregar renglón</button>
        <button type="button" class="hg-restablecer" id="hg-restablecer"<?php echo $listaEditada ? '' : ' hidden'; ?>>Volver a la lista automática</button>
        <span class="hg-aviso" id="hg-aviso" hidden>Con tantos renglones se imprime en dos hojas.</span>
    </div>

    <p class="hg-pie">Al terminar la semana, pásalo en el panel: Gastos de la semana.</p>
</main>

<script>
/*
    Antes de imprimir se pueden quitar renglones (la ✕), agregar más y
    cambiarles el nombre (tocando el nombre). Los renglones con nombre se
    guardan en la lista de "lo de siempre" (gastos_lista.php), la misma de
    Gastos de la semana; los renglones en blanco son solo para esta hoja.
*/
(function () {
    const BASE   = <?php echo json_encode($filasBase, JSON_UNESCAPED_UNICODE); ?>;
    const DIAS   = <?php echo count($dias); ?>;
    const CABEN  = 21; // renglones que caben en una carta
    const tbody  = document.getElementById('hg-filas');
    let espera   = null;
    let ultimaGuardada = JSON.stringify(BASE.filter(Boolean));

    function contar() {
        document.getElementById('hg-aviso').hidden = tbody.children.length <= CABEN;
    }

    // Manda la lista solo si cambió algún nombre (no por renglones en blanco)
    function guardar() {
        clearTimeout(espera);
        contar();
        const compras = [...tbody.querySelectorAll('.hg-texto')].map(s => s.textContent.trim()).filter(Boolean);
        const json = JSON.stringify(compras);
        if (json === ultimaGuardada) return;
        fetch('gastos_lista.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ compras }),
        })
        .then(r => r.json())
        .then(d => {
            if (!d.ok) throw new Error(d.mensaje);
            ultimaGuardada = json;
            document.getElementById('hg-restablecer').hidden = false;
        })
        .catch(() => alert('No se pudo guardar el cambio en la lista. Revisa la conexión e intenta de nuevo.'));
    }

    function renglon(nombre) {
        const tr = document.createElement('tr');
        const td = document.createElement('td');
        td.className = 'hg-nombre';

        // La ✕ queda en el margen izquierdo de la hoja, fuera del texto editable
        const quitar = document.createElement('button');
        quitar.type = 'button';
        quitar.className = 'hg-quitar';
        quitar.textContent = '✕';
        quitar.setAttribute('aria-label', 'Quitar renglón ' + (nombre || 'en blanco'));

        const texto = document.createElement('span');
        texto.className = 'hg-texto';
        texto.contentEditable = 'plaintext-only';
        if (texto.contentEditable !== 'plaintext-only') texto.contentEditable = 'true';
        texto.setAttribute('role', 'textbox');
        texto.setAttribute('aria-label', 'Nombre del gasto');
        texto.textContent = nombre;

        td.append(quitar, texto);
        tr.append(td);
        for (let i = 0; i < DIAS; i++) tr.append(document.createElement('td'));
        const total = document.createElement('td');
        total.className = 'hg-total';
        tr.append(total);
        return tr;
    }

    tbody.addEventListener('click', e => {
        const b = e.target.closest('.hg-quitar');
        if (!b) return;
        b.closest('tr').remove();
        guardar();
    });
    // Al escribir un nombre se guarda cuando dejan de teclear o salen del renglón
    tbody.addEventListener('input', () => { clearTimeout(espera); espera = setTimeout(guardar, 900); contar(); });
    tbody.addEventListener('focusout', guardar);
    tbody.addEventListener('keydown', e => {
        if (e.key === 'Enter') { e.preventDefault(); e.target.blur(); }
    });

    document.getElementById('hg-agregar').addEventListener('click', () => {
        const tr = renglon('');
        tbody.append(tr);
        contar();
        tr.querySelector('.hg-texto').focus();
    });

    document.getElementById('hg-restablecer').addEventListener('click', () => {
        if (!confirm('¿Regresar a la lista automática (lo más capturado)? También cambia en Gastos de la semana.')) return;
        fetch('gastos_lista.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ restablecer: true }),
        })
        .then(r => r.json())
        .then(d => { if (!d.ok) throw new Error(d.mensaje); location.reload(); })
        .catch(() => alert('No se pudo restablecer. Intenta de nuevo.'));
    });

    tbody.replaceChildren(...BASE.map(renglon));
    contar();
})();
</script>

</body>
</html>
