<?php
/* ──────────────────────────────────────────────────────────────
   Aviso: incremento de precios desde el 3 de agosto de 2026
   Popup que aparece al entrar el cliente, una vez por día
   (localStorage). Deja de mostrarse solo una vez pasada la fecha
   de vigencia (ver $ai_vigenciaHasta abajo).
   Para desactivar el aviso a mano: cambiar true por false.
   ────────────────────────────────────────────────────────────── */
if (true):
    date_default_timezone_set("America/Mexico_City");

    $ai_efectivo      = "2026-08-03";
    $ai_vigenciaHasta = "2026-08-10"; // deja de mostrarse automáticamente después de esta fecha

    if (date("Y-m-d") <= $ai_vigenciaHasta):
        $ai_clave = "zb_aviso_incremento_" . date("Y-m-d");
?>
<style>
.ai-overlay {
    position: fixed; inset: 0; z-index: 999;
    background: rgba(5,5,8,.65);
    backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px);
    display: flex; align-items: center; justify-content: center;
    padding: 20px;
    opacity: 0; pointer-events: none;
    transition: opacity .25s ease;
}
.ai-overlay.ai-visible { opacity: 1; pointer-events: all; }

.ai-card {
    position: relative;
    background: linear-gradient(160deg, rgba(255,255,255,.06), rgba(255,255,255,.02)), var(--zb-negro, #0a0908);
    backdrop-filter: blur(20px) saturate(160%);
    -webkit-backdrop-filter: blur(20px) saturate(160%);
    border: 1px solid var(--zb-glass-bd, rgba(247,236,220,.14));
    border-radius: 26px;
    padding: 32px 26px 26px;
    max-width: 340px; width: 100%;
    text-align: center;
    box-shadow: inset 0 1px 0 rgba(255,255,255,.14), 0 24px 60px rgba(0,0,0,.6);
    transform: scale(.9) translateY(16px); opacity: 0;
    transition: transform .4s cubic-bezier(.34,1.3,.64,1), opacity .3s ease;
    font-family: 'Instrument Sans', Arial, sans-serif;
}
.ai-overlay.ai-visible .ai-card { transform: scale(1) translateY(0); opacity: 1; }

.ai-cerrar {
    position: absolute; top: 12px; right: 14px;
    background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.1);
    color: rgba(255,255,255,.6);
    width: 28px; height: 28px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 14px; cursor: pointer; line-height: 1;
}
.ai-cerrar:active { transform: scale(.92); }

.ai-icono-wrap { display: flex; justify-content: center; margin-bottom: 16px; }
.ai-icono {
    width: 52px; height: 52px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    background: rgba(var(--zb-naranja-rgb, 255,122,0), .12);
    border: 1px solid rgba(var(--zb-naranja-rgb, 255,122,0), .3);
    color: var(--zb-naranja, #ff7a00);
}

.ai-eyebrow {
    font-size: 11px; font-weight: 700; letter-spacing: 2px; text-transform: uppercase;
    color: var(--zb-naranja, #ff7a00); margin-bottom: 10px;
}
.ai-titulo {
    font-size: 22px; font-weight: 800; color: var(--zb-crema, #f7ecdc);
    margin-bottom: 12px; letter-spacing: -.02em; line-height: 1.25;
}
.ai-texto {
    font-size: 14px; color: rgba(247,236,220,.72); line-height: 1.65; margin-bottom: 10px;
}
.ai-texto strong { color: var(--zb-crema, #f7ecdc); }
.ai-firma {
    font-family: 'Instrument Serif', serif; font-style: italic;
    font-size: 14px; color: var(--zb-naranja, #ff7a00); margin-bottom: 20px;
}

.ai-badge {
    display: inline-block; font-size: 11.5px; font-weight: 700; letter-spacing: .3px;
    color: var(--zb-crema, #f7ecdc); background: rgba(var(--zb-naranja-rgb, 255,122,0), .14);
    border: 1px solid rgba(var(--zb-naranja-rgb, 255,122,0), .32);
    border-radius: 99px; padding: 6px 16px; margin-bottom: 20px;
}

.ai-btn {
    display: block; width: 100%; border: none; cursor: pointer;
    background: var(--zb-naranja, #ff7a00);
    color: #1a1000; font-weight: 700; font-size: 14px;
    padding: 13px 20px; border-radius: 14px; letter-spacing: .2px;
    font-family: inherit;
}
.ai-btn:active { transform: scale(.98); }
</style>

<div class="ai-overlay" id="ai-overlay">
    <div class="ai-card">
        <button type="button" class="ai-cerrar" id="ai-cerrar" aria-label="Cerrar">✕</button>
        <div class="ai-icono-wrap">
            <span class="ai-icono">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12.6 2.5 21 10.9c.8.8.8 2 0 2.8l-7.3 7.3c-.8.8-2 .8-2.8 0L2.5 12.6V4.5A2 2 0 0 1 4.5 2.5h8.1Z"/><circle cx="8.3" cy="8.3" r="1.6"/></svg>
            </span>
        </div>
        <p class="ai-eyebrow">Aviso importante</p>
        <h2 class="ai-titulo">Un pequeño ajuste en<br>nuestros precios</h2>
        <p class="ai-texto">
            A partir del <strong>lunes 3 de agosto</strong>, nuestros menús tendrán
            un incremento de <strong>$10</strong>.
        </p>
        <p class="ai-firma">Para seguir ofreciéndote el mismo servicio y calidad de siempre.</p>
        <span class="ai-badge">Vigente desde el 3 de agosto</span>
        <button type="button" class="ai-btn" id="ai-entendido">Entendido</button>
    </div>
</div>
<script>
(function () {
    var clave = <?php echo json_encode($ai_clave); ?>;
    try {
        if (localStorage.getItem(clave)) return;
    } catch (e) {}

    var overlay = document.getElementById('ai-overlay');
    if (!overlay) return;

    function cerrar() {
        overlay.classList.remove('ai-visible');
        try { localStorage.setItem(clave, '1'); } catch (e) {}
    }

    requestAnimationFrame(function () {
        setTimeout(function () { overlay.classList.add('ai-visible'); }, 250);
    });

    document.getElementById('ai-cerrar').addEventListener('click', cerrar);
    document.getElementById('ai-entendido').addEventListener('click', cerrar);
    overlay.addEventListener('click', function (e) {
        if (e.target === overlay) cerrar();
    });
})();
</script>
<?php endif; ?>
<?php endif; ?>
