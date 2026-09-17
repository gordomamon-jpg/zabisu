<?php
/* ──────────────────────────────────────────────────────────────
   Aviso previo: avisa CON ANTICIPACIÓN (antes de que empiece el
   cierre) que se acercan días sin servicio. Es un popup que se
   puede cerrar, para no bloquear pedidos de hoy. Siempre aparece
   al cargar la página (sin recordar cierres anteriores) hasta que
   el cliente lo cierra en esa vista.
   Para desactivar el aviso a mano: cambiar true por false.
   ────────────────────────────────────────────────────────────── */
if (true):
    date_default_timezone_set("America/Mexico_City");

    $ss_fechaCierre  = "2026-09-17"; // primer día sin servicio
    $ss_fechaRegreso = "2026-09-21"; // día en que se reanuda el servicio

    if (date("Y-m-d") < $ss_fechaCierre):
        $ss_diasSem = ['Sunday'=>'domingo','Monday'=>'lunes','Tuesday'=>'martes','Wednesday'=>'miércoles','Thursday'=>'jueves','Friday'=>'viernes','Saturday'=>'sábado'];
        $ss_meses   = ['01'=>'enero','02'=>'febrero','03'=>'marzo','04'=>'abril','05'=>'mayo','06'=>'junio','07'=>'julio','08'=>'agosto','09'=>'septiembre','10'=>'octubre','11'=>'noviembre','12'=>'diciembre'];

        $ss_tsCierre    = strtotime($ss_fechaCierre);
        $ss_tsRegreso   = strtotime($ss_fechaRegreso);
        $ss_tsUltimoDia = strtotime('-1 day', $ss_tsRegreso);

        // El mes solo se menciona una vez: al final del rango, y en el
        // regreso solo si cae en un mes distinto al del cierre.
        $ss_rangoTexto = $ss_diasSem[date('l', $ss_tsCierre)] . ' ' . (int)date('j', $ss_tsCierre)
            . ' al ' . $ss_diasSem[date('l', $ss_tsUltimoDia)] . ' ' . (int)date('j', $ss_tsUltimoDia)
            . ' de ' . $ss_meses[date('m', $ss_tsUltimoDia)];

        $ss_regresoTexto = $ss_diasSem[date('l', $ss_tsRegreso)] . ' ' . (int)date('j', $ss_tsRegreso);
        if (date('m', $ss_tsRegreso) !== date('m', $ss_tsUltimoDia)) {
            $ss_regresoTexto .= ' de ' . $ss_meses[date('m', $ss_tsRegreso)];
        }

        $ss_esManana = (date('Y-m-d', strtotime('+1 day')) === $ss_fechaCierre);
        $ss_titulo   = $ss_esManana ? 'Mañana cerramos' : 'Cerramos unos días';
?>
<style>
.ss-overlay {
    position: fixed; inset: 0; z-index: 999;
    background: rgba(5,5,8,.72);
    backdrop-filter: blur(3px); -webkit-backdrop-filter: blur(3px);
    display: flex; align-items: center; justify-content: center;
    padding: 20px;
    opacity: 0; pointer-events: none;
    transition: opacity .25s ease;
}
.ss-overlay.ss-visible { opacity: 1; pointer-events: all; }

.ss-card {
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
    font-family: 'Instrument Sans', Arial, sans-serif;
}
.ss-overlay.ss-visible .ss-card { transform: scale(1) translateY(0); opacity: 1; }

.ss-cerrar {
    position: absolute; top: 12px; right: 14px;
    background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.1);
    color: rgba(255,255,255,.6);
    width: 28px; height: 28px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 14px; cursor: pointer; line-height: 1;
}
.ss-cerrar:active { transform: scale(.92); }

.ss-emoji-wrap { margin-bottom: 16px; }
.ss-emoji { font-size: 56px; display: inline-block; line-height: 1; filter: drop-shadow(0 8px 16px rgba(0,0,0,.5)); }

.ss-eyebrow {
    font-size: 11px; font-weight: 800; letter-spacing: 2px; text-transform: uppercase;
    color: #ff9a40; margin-bottom: 8px;
}
.ss-titulo { font-size: 24px; font-weight: 900; color: #fff; margin-bottom: 10px; letter-spacing: -.3px; line-height: 1.2; }
.ss-texto { font-size: 14px; color: rgba(255,255,255,.6); line-height: 1.65; margin-bottom: 20px; }
.ss-texto strong { color: #fff; font-weight: 700; }

.ss-btn {
    display: block; width: 100%; border: none; cursor: pointer;
    background: linear-gradient(90deg,#ff7a00,#ff9a40);
    color: #17130f; font-weight: 800; font-size: 14px;
    padding: 13px 20px; border-radius: 14px; letter-spacing: .2px;
    font-family: inherit;
}
.ss-btn:active { transform: scale(.98); }
</style>

<div class="ss-overlay" id="ss-overlay">
    <div class="ss-card">
        <button type="button" class="ss-cerrar" id="ss-cerrar" aria-label="Cerrar">✕</button>
        <div class="ss-emoji-wrap"><span class="ss-emoji">😴</span></div>
        <p class="ss-eyebrow">Aviso</p>
        <h2 class="ss-titulo"><?php echo htmlspecialchars($ss_titulo); ?></h2>
        <p class="ss-texto">
            Del <strong><?php echo htmlspecialchars($ss_rangoTexto); ?></strong> no tomaremos ni entregaremos pedidos.
            Te esperamos de vuelta el <strong><?php echo htmlspecialchars($ss_regresoTexto); ?></strong>.
        </p>
        <button type="button" class="ss-btn" id="ss-entendido">Entendido</button>
    </div>
</div>
<script>
(function () {
    var overlay = document.getElementById('ss-overlay');
    if (!overlay) return;

    function cerrar() {
        overlay.classList.remove('ss-visible');
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
