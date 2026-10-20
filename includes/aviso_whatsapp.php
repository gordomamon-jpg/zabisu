<?php
/* ──────────────────────────────────────────────────────────────
   Aviso: los avisos del pedido vuelven a llegar por WhatsApp
   Popup cerrable al entrar el cliente. Solo se muestra con la API
   de WhatsApp encendida (MODO_NOTIFICACION = 'api') y hasta
   $aw_vigenciaHasta. Sin localStorage: aparece en cada visita hasta
   que lo cierren, para que una prueba no lo esconda.
   ────────────────────────────────────────────────────────────── */
require_once __DIR__ . "/modo_notificacion.php";
date_default_timezone_set("America/Mexico_City");

$aw_vigenciaHasta = "2026-10-31";

if (MODO_NOTIFICACION === 'api' && date("Y-m-d") <= $aw_vigenciaHasta):
?>
<style>
.aw-overlay {
    position: fixed; inset: 0; z-index: 999;
    background: rgba(5,5,8,.65);
    backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px);
    display: flex; align-items: center; justify-content: center;
    padding: 20px;
    opacity: 0; pointer-events: none;
    transition: opacity .25s ease;
}
.aw-overlay.aw-visible { opacity: 1; pointer-events: all; }

.aw-card {
    position: relative;
    background: linear-gradient(160deg, rgba(255,255,255,.06), rgba(255,255,255,.02)), var(--zb-negro, #0a0908);
    border: 1px solid var(--zb-glass-bd, rgba(247,236,220,.14));
    border-radius: 26px;
    padding: 32px 26px 26px;
    max-width: 340px; width: 100%;
    text-align: center;
    box-shadow: inset 0 1px 0 rgba(255,255,255,.14), 0 24px 60px rgba(0,0,0,.6);
    transform: scale(.9) translateY(16px); opacity: 0;
    transition: transform .4s cubic-bezier(.34,1.3,.64,1), opacity .3s ease;
    font-family: 'Instrument Sans', -apple-system, Arial, sans-serif;
}
.aw-overlay.aw-visible .aw-card { transform: scale(1) translateY(0); opacity: 1; }

.aw-cerrar {
    position: absolute; top: 12px; right: 14px;
    background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.1);
    color: rgba(255,255,255,.6);
    width: 28px; height: 28px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 14px; cursor: pointer; line-height: 1;
}

.aw-icono {
    width: 52px; height: 52px; border-radius: 50%; margin: 0 auto 16px;
    display: flex; align-items: center; justify-content: center;
    background: rgba(37,211,102,.12); border: 1px solid rgba(37,211,102,.35); color: #25d366;
}
.aw-eyebrow {
    font-size: 11px; font-weight: 700; letter-spacing: 2px; text-transform: uppercase;
    color: var(--zb-naranja, #ff7a00); margin: 0 0 10px;
}
.aw-titulo {
    font-size: 22px; font-weight: 800; color: var(--zb-crema, #f7ecdc);
    margin: 0 0 12px; letter-spacing: -.02em; line-height: 1.25;
}
.aw-texto { font-size: 14px; color: rgba(247,236,220,.72); line-height: 1.6; margin: 0 0 14px; }
.aw-texto strong { color: var(--zb-crema, #f7ecdc); }

.aw-lista { list-style: none; padding: 0; margin: 0 0 16px; text-align: left; }
.aw-lista li {
    display: flex; gap: 10px; align-items: flex-start;
    font-size: 13.5px; color: rgba(247,236,220,.8); line-height: 1.45;
    padding: 9px 0; border-top: 1px solid rgba(247,236,220,.08);
}
.aw-lista li:first-child { border-top: none; }
.aw-lista b { color: var(--zb-crema, #f7ecdc); }
.aw-lista span[aria-hidden] { flex-shrink: 0; width: 20px; text-align: center; }

.aw-numero {
    display: block; font-size: 12.5px; color: rgba(247,236,220,.6);
    background: rgba(255,255,255,.04); border: 1px solid rgba(247,236,220,.1);
    border-radius: 12px; padding: 10px 12px; margin: 0 0 18px; line-height: 1.5;
}
.aw-numero b { color: var(--zb-crema, #f7ecdc); font-size: 14px; letter-spacing: .3px; }

.aw-btn {
    display: block; width: 100%; border: none; cursor: pointer;
    background: var(--zb-naranja, #ff7a00);
    color: #1a1000; font-weight: 700; font-size: 14px;
    padding: 13px 20px; border-radius: 14px; font-family: inherit;
}
.aw-btn:active { transform: scale(.98); }
</style>

<div class="aw-overlay" id="aw-overlay" role="dialog" aria-modal="true" aria-labelledby="aw-titulo">
    <div class="aw-card">
        <button type="button" class="aw-cerrar" id="aw-cerrar" aria-label="Cerrar">✕</button>
        <div class="aw-icono" aria-hidden="true">
            <svg width="26" height="26" viewBox="0 0 24 24" fill="currentColor"><path d="M17.5 14.4c-.3-.1-1.8-.9-2-1s-.5-.1-.7.1-.8 1-1 1.2-.4.2-.7.1a8.2 8.2 0 0 1-4-3.5c-.3-.5.3-.5.9-1.6.1-.2 0-.4 0-.5l-.9-2.2c-.2-.6-.5-.5-.7-.5h-.6c-.2 0-.5.1-.8.4s-1 1-1 2.5 1 2.9 1.2 3.1 2 3.1 5 4.4c1.9.8 2.6.9 3.5.7.6-.1 1.8-.7 2-1.4s.3-1.3.2-1.4l-.4-.4ZM12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2Zm0 18.2a8.2 8.2 0 0 1-4.2-1.2l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2Z"/></svg>
        </div>
        <p class="aw-eyebrow">Novedad</p>
        <h2 class="aw-titulo" id="aw-titulo">Tus avisos ahora llegan por WhatsApp</h2>
        <p class="aw-texto">Ya no tienes que revisar tu correo. Al pedir, solo deja <strong>tu WhatsApp</strong> y te avisamos:</p>
        <ul class="aw-lista">
            <li><span aria-hidden="true">✅</span><span><b>Cuando confirmamos tu pedido</b></span></li>
            <li><span aria-hidden="true">📍</span><span><b>Cuando llegamos a tu punto de entrega</b></span></li>
        </ul>
        <span class="aw-numero">Te escribimos como <b>Zabisu</b> desde el<br><b>220 680 9490</b><br>Para dudas, escríbenos al 55 6090 8778.</span>
        <button type="button" class="aw-btn" id="aw-entendido">Entendido</button>
    </div>
</div>
<script>
(function () {
    var overlay = document.getElementById('aw-overlay');
    if (!overlay) return;
    function cerrar() { overlay.classList.remove('aw-visible'); }
    requestAnimationFrame(function () {
        setTimeout(function () { overlay.classList.add('aw-visible'); }, 250);
    });
    document.getElementById('aw-cerrar').addEventListener('click', cerrar);
    document.getElementById('aw-entendido').addEventListener('click', cerrar);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) cerrar(); });
})();
</script>
<?php endif; ?>
