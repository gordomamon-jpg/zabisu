<?php
/* ──────────────────────────────────────────────────────────────
   Aviso: Birria los jueves
   Popup llamativo que aparece al entrar el cliente para avisar
   que los jueves hay birria. Se muestra una vez por día (localStorage).
   Activado puntualmente para jueves 27 de agosto 2026 (y el día
   previo, avisando con anticipación). Después de esa fecha el
   aviso se apaga solo — para reactivarlo otra semana, actualizar
   las fechas del in_array de abajo.
   ────────────────────────────────────────────────────────────── */
if (in_array(date('Y-m-d'), ['2026-08-26', '2026-08-27'])):
    date_default_timezone_set("America/Mexico_City");

    $diaHoyISO = (int)date('N'); // 1=Lunes ... 7=Domingo
    $esJueves  = ($diaHoyISO === 4);
    $diasHastaJueves = $esJueves ? 0 : ((4 - $diaHoyISO + 7) % 7);
    $tsJueves = strtotime("+{$diasHastaJueves} days");

    $mesesBirria = ['01'=>'ene','02'=>'feb','03'=>'mar','04'=>'abr','05'=>'may','06'=>'jun',
                    '07'=>'jul','08'=>'ago','09'=>'sep','10'=>'oct','11'=>'nov','12'=>'dic'];
    $fechaJueves = date('j', $tsJueves) . ' de ' . ($mesesBirria[date('m', $tsJueves)] ?? '');

    if ($esJueves) {
        $ab_eyebrow = '🌮 ¡Hoy es jueves!';
        $ab_titulo  = '¡Hoy hay birria!';
        $ab_texto   = 'Aprovecha, la birria solo está disponible los jueves.';
    } elseif ($diasHastaJueves === 1) {
        $ab_eyebrow = '🔥 Aviso especial';
        $ab_titulo  = '¡Mañana hay birria!';
        $ab_texto   = 'Este jueves ' . $fechaJueves . ' tendremos birria en el menú. ¡No te la pierdas!';
    } else {
        $ab_eyebrow = '🔥 Aviso especial';
        $ab_titulo  = '¡Este jueves hay birria!';
        $ab_texto   = 'El jueves ' . $fechaJueves . ' tendremos birria en el menú. ¡No te la pierdas!';
    }

    $ab_clave = 'zb_aviso_birria_' . date('Y-m-d');
?>
<style>
.ab-overlay {
    position: fixed; inset: 0; z-index: 999;
    background: rgba(5,5,8,.72);
    backdrop-filter: blur(3px); -webkit-backdrop-filter: blur(3px);
    display: flex; align-items: center; justify-content: center;
    padding: 20px;
    opacity: 0; pointer-events: none;
    transition: opacity .25s ease;
}
.ab-overlay.ab-visible { opacity: 1; pointer-events: all; }

.ab-card {
    position: relative;
    background: linear-gradient(160deg, #171310 0%, #14100e 55%, #120e0c 100%);
    border: 1px solid rgba(255,122,0,.28);
    border-radius: 26px;
    padding: 34px 26px 28px;
    max-width: 340px; width: 100%;
    text-align: center;
    box-shadow: 0 0 0 1px rgba(255,122,0,.08), 0 0 60px rgba(255,122,0,.12), 0 24px 60px rgba(0,0,0,.75);
    transform: scale(.85) translateY(20px); opacity: 0;
    transition: transform .4s cubic-bezier(.34,1.4,.64,1), opacity .3s ease;
}
.ab-overlay.ab-visible .ab-card { transform: scale(1) translateY(0); opacity: 1; }

.ab-cerrar {
    position: absolute; top: 12px; right: 14px;
    background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.1);
    color: rgba(255,255,255,.6);
    width: 28px; height: 28px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 14px; cursor: pointer; line-height: 1;
}
.ab-cerrar:active { transform: scale(.92); }

.ab-emoji-wrap { margin-bottom: 16px; }
.ab-emoji {
    font-size: 62px; display: inline-block; line-height: 1;
    animation: ab-bounce 1.1s ease-in-out infinite alternate;
    filter: drop-shadow(0 8px 16px rgba(0,0,0,.5));
}
@keyframes ab-bounce { from{transform:translateY(0) rotate(-4deg)} to{transform:translateY(-12px) rotate(4deg)} }

.ab-eyebrow {
    font-size: 11px; font-weight: 800; letter-spacing: 2px; text-transform: uppercase;
    color: #ff9a40; margin-bottom: 8px;
}
.ab-titulo { font-size: 24px; font-weight: 900; color: #fff; margin-bottom: 10px; letter-spacing: -.3px; line-height: 1.2; }
.ab-texto { font-size: 14px; color: rgba(255,255,255,.6); line-height: 1.65; margin-bottom: 20px; }

.ab-badge {
    display: inline-block; font-size: 12px; font-weight: 800; letter-spacing: .3px;
    color: #ff7a00; background: rgba(255,122,0,.12); border: 1px solid rgba(255,122,0,.3);
    border-radius: 99px; padding: 6px 16px; margin-bottom: 20px;
}

.ab-btn {
    display: block; width: 100%; border: none; cursor: pointer;
    background: linear-gradient(90deg,#ff7a00,#ff9a40);
    color: #17130f; font-weight: 800; font-size: 14px;
    padding: 13px 20px; border-radius: 14px; letter-spacing: .2px;
}
.ab-btn:active { transform: scale(.98); }

@media (prefers-reduced-motion: reduce) {
    .ab-emoji { animation: none; }
}
</style>

<div class="ab-overlay" id="ab-overlay">
    <div class="ab-card">
        <button type="button" class="ab-cerrar" id="ab-cerrar" aria-label="Cerrar">✕</button>
        <div class="ab-emoji-wrap"><span class="ab-emoji">🌮</span></div>
        <p class="ab-eyebrow"><?php echo htmlspecialchars($ab_eyebrow); ?></p>
        <h2 class="ab-titulo"><?php echo htmlspecialchars($ab_titulo); ?></h2>
        <p class="ab-texto"><?php echo htmlspecialchars($ab_texto); ?></p>
        <span class="ab-badge">📅 Solo los jueves</span>
        <button type="button" class="ab-btn" id="ab-entendido">¡Entendido!</button>
    </div>
</div>
<script>
(function () {
    var clave = <?php echo json_encode($ab_clave); ?>;
    try {
        if (localStorage.getItem(clave)) return;
    } catch (e) {}

    var overlay = document.getElementById('ab-overlay');
    if (!overlay) return;

    function cerrar() {
        overlay.classList.remove('ab-visible');
        try { localStorage.setItem(clave, '1'); } catch (e) {}
    }

    requestAnimationFrame(function () {
        setTimeout(function () { overlay.classList.add('ab-visible'); }, 250);
    });

    document.getElementById('ab-cerrar').addEventListener('click', cerrar);
    document.getElementById('ab-entendido').addEventListener('click', cerrar);
    overlay.addEventListener('click', function (e) {
        if (e.target === overlay) cerrar();
    });
})();
</script>
<?php endif; ?>
