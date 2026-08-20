<?php
/* ──────────────────────────────────────────────────────────────
   Aviso: sin servicio por un día (evento puntual)
   Popup grande y llamativo que aparece al entrar el cliente,
   avisando con anticipación que un día no habrá servicio.
   Se muestra una vez por día (localStorage) y deja de mostrarse
   solo una vez pasada la fecha del cierre.
   Para desactivar el aviso a mano: cambiar true por false.
   ────────────────────────────────────────────────────────────── */
if (true):
    date_default_timezone_set("America/Mexico_City");

    $ss_fechaCierre = "2026-08-20"; // día sin servicio

    if (date("Y-m-d") <= $ss_fechaCierre):
        $ss_diasSem = ['Sunday'=>'domingo','Monday'=>'lunes','Tuesday'=>'martes','Wednesday'=>'miércoles','Thursday'=>'jueves','Friday'=>'viernes','Saturday'=>'sábado'];
        $ss_meses   = ['01'=>'enero','02'=>'febrero','03'=>'marzo','04'=>'abril','05'=>'mayo','06'=>'junio','07'=>'julio','08'=>'agosto','09'=>'septiembre','10'=>'octubre','11'=>'noviembre','12'=>'diciembre'];

        $ss_tsCierre  = strtotime($ss_fechaCierre);
        $ss_tsRegreso = strtotime('+1 day', $ss_tsCierre);

        $ss_fechaCierreTexto  = $ss_diasSem[date('l', $ss_tsCierre)] . ' ' . (int)date('j', $ss_tsCierre) . ' de ' . $ss_meses[date('m', $ss_tsCierre)];
        $ss_fechaRegresoTexto = $ss_diasSem[date('l', $ss_tsRegreso)];

        $ss_esHoy = (date('Y-m-d') === $ss_fechaCierre);
        $ss_titulo = $ss_esHoy ? 'Hoy no tenemos servicio' : 'Mañana no tendremos servicio';

        $ss_clave = 'zb_aviso_sin_servicio_' . date('Y-m-d');
?>
<style>
.ss-overlay {
    position: fixed; inset: 0; z-index: 999;
    background: rgba(5,5,8,.75);
    backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px);
    display: flex; align-items: center; justify-content: center;
    padding: 20px;
    opacity: 0; pointer-events: none;
    transition: opacity .25s ease;
}
.ss-overlay.ss-visible { opacity: 1; pointer-events: all; }

.ss-card {
    position: relative;
    background: linear-gradient(160deg, rgba(255,255,255,.06), rgba(255,255,255,.02)), var(--zb-negro, #0a0908);
    backdrop-filter: blur(20px) saturate(160%);
    -webkit-backdrop-filter: blur(20px) saturate(160%);
    border: 1px solid rgba(var(--zb-rojo-rgb, 242,96,74), .35);
    border-radius: 28px;
    padding: 38px 28px 30px;
    max-width: 360px; width: 100%;
    text-align: center;
    box-shadow: inset 0 1px 0 rgba(255,255,255,.14), 0 24px 70px rgba(0,0,0,.65), 0 0 0 1px rgba(var(--zb-rojo-rgb, 242,96,74), .1);
    transform: scale(.88) translateY(18px); opacity: 0;
    transition: transform .45s cubic-bezier(.34,1.35,.64,1), opacity .3s ease;
    font-family: 'Instrument Sans', Arial, sans-serif;
    animation: ss-glow-pulse 2.6s ease-in-out infinite;
}
.ss-overlay.ss-visible .ss-card { transform: scale(1) translateY(0); opacity: 1; }
@keyframes ss-glow-pulse {
    0%, 100% { box-shadow: inset 0 1px 0 rgba(255,255,255,.14), 0 24px 70px rgba(0,0,0,.65), 0 0 0 1px rgba(var(--zb-rojo-rgb, 242,96,74), .1), 0 0 26px rgba(var(--zb-rojo-rgb, 242,96,74), .12); }
    50%      { box-shadow: inset 0 1px 0 rgba(255,255,255,.14), 0 24px 70px rgba(0,0,0,.65), 0 0 0 1px rgba(var(--zb-rojo-rgb, 242,96,74), .22), 0 0 46px rgba(var(--zb-rojo-rgb, 242,96,74), .28); }
}

.ss-cerrar {
    position: absolute; top: 14px; right: 16px;
    background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.1);
    color: rgba(255,255,255,.6);
    width: 28px; height: 28px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 14px; cursor: pointer; line-height: 1;
}
.ss-cerrar:active { transform: scale(.92); }

.ss-icono-wrap { display: flex; justify-content: center; margin-bottom: 18px; }
.ss-icono {
    width: 66px; height: 66px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    background: rgba(var(--zb-rojo-rgb, 242,96,74), .12);
    border: 1px solid rgba(var(--zb-rojo-rgb, 242,96,74), .32);
    color: var(--zb-rojo, #f2604a);
    animation: ss-icono-shake 3.2s ease-in-out infinite;
}
.ss-icono svg { width: 30px; height: 30px; }
@keyframes ss-icono-shake {
    0%, 92%, 100% { transform: rotate(0deg); }
    93% { transform: rotate(-8deg); }
    95% { transform: rotate(7deg); }
    97% { transform: rotate(-4deg); }
    99% { transform: rotate(2deg); }
}

.ss-eyebrow {
    font-size: 11px; font-weight: 700; letter-spacing: 2px; text-transform: uppercase;
    color: var(--zb-rojo, #f2604a); margin-bottom: 10px;
}
.ss-titulo {
    font-size: 27px; font-weight: 800; color: var(--zb-crema, #f7ecdc);
    margin-bottom: 14px; letter-spacing: -.02em; line-height: 1.2;
}
.ss-texto {
    font-size: 14.5px; color: rgba(247,236,220,.75); line-height: 1.7; margin-bottom: 8px;
}
.ss-texto strong { color: var(--zb-crema, #f7ecdc); }
.ss-disculpa {
    font-family: 'Instrument Serif', serif; font-style: italic;
    font-size: 15px; color: var(--zb-naranja, #ff7a00); margin: 14px 0 20px;
}

.ss-badge {
    display: inline-flex; align-items: center; gap: 7px;
    font-size: 12px; font-weight: 700; letter-spacing: .2px;
    color: var(--zb-crema, #f7ecdc); background: rgba(var(--zb-rojo-rgb, 242,96,74), .14);
    border: 1px solid rgba(var(--zb-rojo-rgb, 242,96,74), .32);
    border-radius: 99px; padding: 7px 16px; margin-bottom: 22px;
}

.ss-btn {
    display: block; width: 100%; border: none; cursor: pointer;
    background: var(--zb-rojo, #f2604a);
    color: #1a0805; font-weight: 700; font-size: 14px;
    padding: 14px 20px; border-radius: 14px; letter-spacing: .2px;
    font-family: inherit;
}
.ss-btn:active { transform: scale(.98); }

@media (prefers-reduced-motion: reduce) {
    .ss-card { animation: none; }
    .ss-icono { animation: none; }
}
</style>

<div class="ss-overlay" id="ss-overlay">
    <div class="ss-card">
        <button type="button" class="ss-cerrar" id="ss-cerrar" aria-label="Cerrar">✕</button>
        <div class="ss-icono-wrap">
            <span class="ss-icono">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9.2"/><path d="M8 8l8 8M16 8l-8 8"/></svg>
            </span>
        </div>
        <p class="ss-eyebrow">Aviso importante</p>
        <h2 class="ss-titulo"><?php echo htmlspecialchars($ss_titulo); ?></h2>
        <p class="ss-texto">
            Por causas de fuerza mayor, este <strong><?php echo htmlspecialchars($ss_fechaCierreTexto); ?></strong>
            no podremos tomar ni entregar pedidos.
        </p>
        <p class="ss-disculpa">Disculpa las molestias, regresamos el <?php echo htmlspecialchars($ss_fechaRegresoTexto); ?> con todo el sabor de siempre.</p>
        <span class="ss-badge">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3.5" y="5" width="17" height="15.5" rx="2.2"/><path d="M8 3v4M16 3v4M3.5 10h17"/></svg>
            Sin servicio: <?php echo htmlspecialchars($ss_fechaCierreTexto); ?>
        </span>
        <button type="button" class="ss-btn" id="ss-entendido">Entendido</button>
    </div>
</div>
<script>
(function () {
    var clave = <?php echo json_encode($ss_clave); ?>;
    try {
        if (localStorage.getItem(clave)) return;
    } catch (e) {}

    var overlay = document.getElementById('ss-overlay');
    if (!overlay) return;

    function cerrar() {
        overlay.classList.remove('ss-visible');
        try { localStorage.setItem(clave, '1'); } catch (e) {}
    }

    requestAnimationFrame(function () {
        setTimeout(function () { overlay.classList.add('ss-visible'); }, 250);
    });

    document.getElementById('ss-cerrar').addEventListener('click', cerrar);
    document.getElementById('ss-entendido').addEventListener('click', cerrar);
    overlay.addEventListener('click', function (e) {
        if (e.target === overlay) cerrar();
    });
})();
</script>
<?php endif; ?>
<?php endif; ?>
