<?php
/* ──────────────────────────────────────────────────────────────
   Aviso de lanzamiento: "¡Zabisu se reinventa!" + los avisos del
   pedido ahora llegan por WhatsApp. Estilo Pizarrón de fonda
   (DESIGN.md): pincel naranja, boleto de estraza y botón derecho.
   Popup cerrable al entrar el cliente. Solo se muestra con la API
   de WhatsApp encendida (MODO_NOTIFICACION = 'api') y hasta
   $aw_vigenciaHasta. Sin localStorage: aparece en cada visita hasta
   que lo cierren, para que una prueba no lo esconda.
   Trae sus propios colores, letras y filtro, porque también lo
   incluye el menú con el diseño anterior (sin menu-pizarron.css).
   ────────────────────────────────────────────────────────────── */
require_once __DIR__ . "/modo_notificacion.php";
date_default_timezone_set("America/Mexico_City");

$aw_vigenciaHasta = "2026-10-31";

if (MODO_NOTIFICACION === 'api' && date("Y-m-d") <= $aw_vigenciaHasta):
?>
<style>
@font-face{font-family:'Alfa Slab One';font-weight:400;font-style:normal;font-display:swap;
  src:url('../assets/fonts/alfa-slab-one-400.woff2') format('woff2')}
@font-face{font-family:'Kaushan Script';font-weight:400;font-style:normal;font-display:swap;
  src:url('../assets/fonts/kaushan-script-400.woff2') format('woff2')}

.aw-overlay{
  --aw-pizarra:#151413;--aw-linea:#2F2C29;--aw-gis:#ECEBE7;--aw-gris:#A5A29B;
  --aw-naranja:#FF7A00;--aw-estraza-cl:#D9B88F;--aw-tinta:#22180F;--aw-tinta2:#3B2A18;
  --aw-sys:system-ui,-apple-system,"SF Pro Text",Roboto,"Segoe UI",sans-serif;
  position:fixed;inset:0;z-index:999;display:flex;align-items:center;justify-content:center;
  padding:16px;background:rgba(10,9,8,.8);overflow-y:auto;
  opacity:0;pointer-events:none;transition:opacity .25s ease;
}
.aw-overlay.aw-visible{opacity:1;pointer-events:all}

.aw-card{position:relative;width:100%;max-width:360px;margin:auto;padding:26px 20px 20px;
  background:var(--aw-pizarra);border:1px solid var(--aw-linea);border-radius:16px 18px 15px 17px;
  color:var(--aw-gis);font-family:var(--aw-sys);text-align:left;-webkit-font-smoothing:antialiased}

.aw-cerrar{position:absolute;top:8px;right:8px;width:44px;height:44px;border:0;border-radius:12px;
  background:none;color:var(--aw-gris);cursor:pointer;display:flex;align-items:center;justify-content:center}
.aw-cerrar:focus-visible,.aw-btn:focus-visible{outline:2px solid var(--aw-naranja);outline-offset:3px}

/* Pincel naranja con el título */
.aw-pincel{position:relative;display:inline-block;margin:4px 0 0 -2px;padding:2px 20px 8px 10px}
.aw-pincel::before{content:"";position:absolute;inset:4px -4px 0 -8px;background:var(--aw-naranja);
  border-radius:3px 40px 6px 30px/30px 6px 40px 3px;transform:rotate(-1.6deg);filter:url(#aw-borde)}
.aw-pincel span{position:relative;font:400 31px/1.1 'Kaushan Script',cursive;color:#111}

.aw-texto{margin:14px 0 0;font:400 16px/1.45 var(--aw-sys);color:var(--aw-gris)}
.aw-texto b{color:var(--aw-gis);font-weight:600}

/* Boleto de estraza con los avisos por WhatsApp */
.aw-boleto{position:relative;margin:20px 2px 0;color:var(--aw-tinta);transform:rotate(-1deg);isolation:isolate}
.aw-boleto__papel{position:relative;padding:16px 16px 14px 18px;
  -webkit-mask:radial-gradient(circle at 0 50%,transparent 8px,#000 8.5px) left/51% 100% no-repeat,radial-gradient(circle at 100% 50%,transparent 8px,#000 8.5px) right/51% 100% no-repeat;
          mask:radial-gradient(circle at 0 50%,transparent 8px,#000 8.5px) left/51% 100% no-repeat,radial-gradient(circle at 100% 50%,transparent 8px,#000 8.5px) right/51% 100% no-repeat}
.aw-boleto__papel::before{content:"";position:absolute;inset:0;z-index:-1;border-radius:6px;filter:url(#aw-borde);
  background:var(--aw-estraza-cl) repeating-linear-gradient(92deg,rgba(0,0,0,.05) 0 1px,transparent 1px 5px)}
.aw-boleto__titulo{display:flex;align-items:center;gap:8px;margin:0;font:400 16px/1.25 'Alfa Slab One',Georgia,serif}
.aw-boleto__titulo svg{flex-shrink:0}
.aw-lista{list-style:none;margin:10px 0 0;padding:0}
.aw-lista li{display:flex;gap:9px;align-items:flex-start;padding:4px 0;font:400 15px/1.4 var(--aw-sys);color:var(--aw-tinta2)}
.aw-lista li b{color:var(--aw-tinta);font-weight:600}
.aw-lista svg{flex-shrink:0;margin-top:2px}
.aw-corte{border:0;border-top:1.5px dashed rgba(34,24,15,.35);margin:10px 0}
.aw-numero{margin:0;font:400 14px/1.45 var(--aw-sys);color:var(--aw-tinta2)}
.aw-numero b{color:var(--aw-tinta);font-weight:700;white-space:nowrap}

.aw-dudas{margin:16px 0 0;font:400 14px/1.45 var(--aw-sys);color:var(--aw-gris)}
.aw-dudas b{color:var(--aw-gis);font-weight:600;white-space:nowrap}

.aw-btn{display:block;width:100%;margin-top:18px;min-height:52px;border:0;border-radius:14px;cursor:pointer;
  background:var(--aw-naranja);color:#111;font:600 17px var(--aw-sys);transition:background .2s}
.aw-btn:hover{background:#ff8a1f}
.aw-btn:active{transform:scale(.985)}

@media (prefers-reduced-motion:reduce){.aw-overlay,.aw-btn{transition:none}}
@media (max-width:360px){.aw-pincel span{font-size:27px}}
</style>

<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
    <filter id="aw-borde"><feTurbulence type="fractalNoise" baseFrequency=".05" numOctaves="3" seed="2" result="r"/><feDisplacementMap in="SourceGraphic" in2="r" scale="3"/></filter>
</svg>

<div class="aw-overlay" id="aw-overlay" role="dialog" aria-modal="true" aria-labelledby="aw-titulo">
    <div class="aw-card">
        <button type="button" class="aw-cerrar" id="aw-cerrar" aria-label="Cerrar">
            <svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg>
        </button>

        <h2 class="aw-pincel" id="aw-titulo"><span>¡Zabisu se reinventa!</span></h2>

        <p class="aw-texto">Estrenamos nueva cara: <b>el mismo sabor de siempre</b>, ahora con una página más fácil para hacer tu pedido.</p>

        <div class="aw-boleto">
            <div class="aw-boleto__papel">
                <p class="aw-boleto__titulo">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.5 14.4c-.3-.1-1.8-.9-2-1s-.5-.1-.7.1-.8 1-1 1.2-.4.2-.7.1a8.2 8.2 0 0 1-4-3.5c-.3-.5.3-.5.9-1.6.1-.2 0-.4 0-.5l-.9-2.2c-.2-.6-.5-.5-.7-.5h-.6c-.2 0-.5.1-.8.4s-1 1-1 2.5 1 2.9 1.2 3.1 2 3.1 5 4.4c1.9.8 2.6.9 3.5.7.6-.1 1.8-.7 2-1.4s.3-1.3.2-1.4l-.4-.4ZM12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2Zm0 18.2a8.2 8.2 0 0 1-4.2-1.2l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2Z"/></svg>
                    Tus avisos ahora llegan por WhatsApp
                </p>
                <ul class="aw-lista">
                    <li>
                        <svg width="16" height="16" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12.5l5 5L20 6.5" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        <span><b>Cuando confirmamos</b> tu pedido</span>
                    </li>
                    <li>
                        <svg width="16" height="16" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21Z" fill="none" stroke="currentColor" stroke-width="2.2"/><circle cx="12" cy="9.5" r="2.5" fill="currentColor"/></svg>
                        <span><b>Cuando llegamos</b> a tu punto de entrega</span>
                    </li>
                </ul>
                <hr class="aw-corte">
                <p class="aw-numero">Al pedir, deja tu WhatsApp. Te escribimos como <b>Zabisu</b> desde el <b>220 680 9490</b>.</p>
            </div>
        </div>

        <p class="aw-dudas">Ese número solo envía avisos. Tus dudas, al <b>55 6090 8778</b>.</p>

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
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') cerrar(); });
})();
</script>
<?php endif; ?>
