<?php
/* ──────────────────────────────────────────────────────────────
   Bloqueo total: días sin servicio (mismo criterio que el aviso
   del mundial — página completa, sin menú, sin poder ordenar).
   Se activa automáticamente durante el rango de cierre y hace
   exit; para no ejecutar el resto de menu.php.
   Debe requerirse al INICIO de menu.php, antes de cualquier query.
   ────────────────────────────────────────────────────────────── */
$bs_fechaCierre  = "2026-08-20"; // primer día sin servicio
$bs_fechaRegreso = "2026-08-24"; // día en que se reanuda el servicio

if (date("Y-m-d") >= $bs_fechaCierre && date("Y-m-d") < $bs_fechaRegreso):
    $bs_diasSem = ['Sunday'=>'domingo','Monday'=>'lunes','Tuesday'=>'martes','Wednesday'=>'miércoles','Thursday'=>'jueves','Friday'=>'viernes','Saturday'=>'sábado'];
    $bs_meses   = ['01'=>'enero','02'=>'febrero','03'=>'marzo','04'=>'abril','05'=>'mayo','06'=>'junio','07'=>'julio','08'=>'agosto','09'=>'septiembre','10'=>'octubre','11'=>'noviembre','12'=>'diciembre'];

    $bs_formatoFecha = function ($ts) use ($bs_diasSem, $bs_meses) {
        return $bs_diasSem[date('l', $ts)] . ' ' . (int)date('j', $ts) . ' de ' . $bs_meses[date('m', $ts)];
    };

    $bs_tsCierre    = strtotime($bs_fechaCierre);
    $bs_tsRegreso   = strtotime($bs_fechaRegreso);
    $bs_tsUltimoDia = strtotime('-1 day', $bs_tsRegreso);

    $bs_rangoTexto = ($bs_tsCierre === $bs_tsUltimoDia)
        ? $bs_formatoFecha($bs_tsCierre)
        : $bs_diasSem[date('l', $bs_tsCierre)] . ' ' . (int)date('j', $bs_tsCierre) . ' – ' . $bs_formatoFecha($bs_tsUltimoDia);

    $bs_regresoTexto = $bs_formatoFecha($bs_tsRegreso);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sin servicio · Zabisu</title>
    <link rel="icon" type="image/png" href="../assets/img/LOGO_NARA.png">
    <link rel="manifest" href="../manifest.json">
    <meta name="theme-color" content="#0a0908">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Zabisu">
    <link rel="apple-touch-icon" href="../assets/img/LOGO_NARA.png">
    <link rel="stylesheet" href="../assets/css/cliente-rediseno.css?v=4">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: 'Instrument Sans', Arial, sans-serif;
    background: var(--zb-negro);
    min-height: 100dvh;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 24px 16px;
    overflow: hidden;
    position: relative;
}

.bs-glow-top {
    position: fixed;
    top: -120px; left: 50%;
    transform: translateX(-50%);
    width: 620px; height: 420px;
    background: radial-gradient(ellipse, rgba(var(--zb-rojo-rgb),.16) 0%, transparent 65%);
    pointer-events: none;
    animation: bs-glow-pulse 4s ease-in-out infinite alternate;
}
@keyframes bs-glow-pulse {
    from { opacity: .7; transform: translateX(-50%) scale(1); }
    to   { opacity: 1;  transform: translateX(-50%) scale(1.08); }
}

.bs-particles { position: fixed; inset: 0; pointer-events: none; overflow: hidden; z-index: 0; color: rgba(var(--zb-rojo-rgb),.35); }
.bs-particle { position: absolute; bottom: -30px; width: var(--sz); height: var(--sz); left: var(--x); opacity: 0; animation: bs-rise var(--dur) ease-in-out var(--delay) infinite; }
.bs-particle svg { width: 100%; height: 100%; display: block; }
@keyframes bs-rise {
    0%   { transform: translateY(0) rotate(0deg) scale(.8); opacity: 0; }
    8%   { opacity: .4; }
    85%  { opacity: .18; }
    100% { transform: translateY(-105vh) rotate(var(--rot)) scale(1.1); opacity: 0; }
}

.bs-logo-wrap {
    position: relative; z-index: 1;
    display: flex; align-items: center; gap: 14px;
    margin-bottom: 28px;
    animation: bs-fadein .5s var(--zb-ease) .05s both;
}
.bs-logo-wrap img {
    width: 52px; height: 52px; object-fit: contain;
    filter: drop-shadow(0 0 16px rgba(var(--zb-rojo-rgb),.5));
}
.bs-logo-wrap span {
    font-size: 44px; font-weight: 700; letter-spacing: -1.5px; line-height: 1;
    color: var(--zb-crema);
}

.bs-card {
    position: relative; z-index: 1;
    background: rgba(255,255,255,.045);
    backdrop-filter: blur(16px) saturate(150%);
    -webkit-backdrop-filter: blur(16px) saturate(150%);
    border: 1px solid rgba(var(--zb-rojo-rgb),.3);
    border-radius: 28px;
    padding: 40px 30px 34px;
    max-width: 400px; width: 100%;
    text-align: center;
    box-shadow: 0 24px 70px rgba(0,0,0,.65), 0 0 60px rgba(var(--zb-rojo-rgb),.1);
    animation: bs-popin .65s cubic-bezier(.34,1.35,.64,1) .12s both, bs-glow-card 2.6s ease-in-out .8s infinite;
}
@keyframes bs-popin {
    from { transform: scale(.85) translateY(24px); opacity: 0; }
    to   { transform: scale(1) translateY(0); opacity: 1; }
}
@keyframes bs-glow-card {
    0%, 100% { box-shadow: 0 24px 70px rgba(0,0,0,.65), 0 0 60px rgba(var(--zb-rojo-rgb),.1); }
    50%      { box-shadow: 0 24px 70px rgba(0,0,0,.65), 0 0 80px rgba(var(--zb-rojo-rgb),.24); }
}

.bs-icono-wrap { display: flex; flex-direction: column; align-items: center; margin-bottom: 24px; animation: bs-fadein .5s var(--zb-ease) .3s both; }
.bs-icono-bounce { animation: bs-shake 3.2s ease-in-out infinite; }
@keyframes bs-shake {
    0%, 88%, 100% { transform: rotate(0deg); }
    90% { transform: rotate(-8deg); }
    92% { transform: rotate(7deg); }
    94% { transform: rotate(-4deg); }
    96% { transform: rotate(2deg); }
}
.bs-icono {
    width: 78px; height: 78px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    background: rgba(var(--zb-rojo-rgb),.12);
    border: 1px solid rgba(var(--zb-rojo-rgb),.32);
    color: var(--zb-rojo);
    filter: drop-shadow(0 6px 20px rgba(0,0,0,.5));
}
.bs-icono svg { width: 36px; height: 36px; }

.bs-eyebrow {
    font-size: 11px; font-weight: 700; letter-spacing: 2.5px; text-transform: uppercase;
    color: var(--zb-rojo);
    margin-bottom: 10px;
    animation: bs-fadein .5s var(--zb-ease) .38s both;
}
.bs-titulo {
    font-size: 29px; font-weight: 800; color: var(--zb-crema);
    margin-bottom: 14px; letter-spacing: -.4px; line-height: 1.2;
    animation: bs-fadein .5s var(--zb-ease) .44s both;
}
.bs-texto {
    font-size: 14.5px; color: var(--zb-muted); line-height: 1.75; margin-bottom: 10px;
    animation: bs-fadein .5s var(--zb-ease) .5s both;
}
.bs-texto strong { color: var(--zb-crema); }
.bs-disculpa {
    font-family: 'Instrument Serif', serif; font-style: italic;
    font-size: 15.5px; color: var(--zb-naranja);
    margin: 14px 0 22px;
    animation: bs-fadein .5s var(--zb-ease) .56s both;
}

.bs-sep { width: 100%; height: 1px; background: var(--zb-glass-bd); margin: 4px 0 20px; animation: bs-fadein .5s var(--zb-ease) .6s both; }

.bs-badge {
    display: inline-flex; align-items: center; gap: 8px;
    background: rgba(var(--zb-rojo-rgb),.1);
    border: 1px solid rgba(var(--zb-rojo-rgb),.28);
    border-radius: 99px;
    padding: 8px 18px;
    font-size: 12.5px; font-weight: 700; color: var(--zb-crema);
    letter-spacing: .2px;
    animation: bs-fadein .5s var(--zb-ease) .66s both;
}
.bs-badge svg { width: 15px; height: 15px; flex-shrink: 0; color: var(--zb-rojo); }

.bs-footer {
    position: relative; z-index: 1;
    margin-top: 26px;
    font-size: 11px;
    color: var(--zb-muted);
    opacity: .6;
    letter-spacing: .3px;
    animation: bs-fadein .5s var(--zb-ease) .72s both;
}

@keyframes bs-fadein {
    from { opacity: 0; transform: translateY(10px); }
    to   { opacity: 1; transform: translateY(0); }
}

@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after { animation-duration: .01ms !important; }
}
</style>
</head>
<body>

<div class="bs-glow-top"></div>

<div class="bs-particles" aria-hidden="true">
    <span class="bs-particle" style="--x:8%;  --sz:18px; --dur:10s; --delay:0s;   --rot:25deg"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9.2"/><path d="M8 8l8 8M16 8l-8 8"/></svg></span>
    <span class="bs-particle" style="--x:24%; --sz:14px; --dur:12s; --delay:2s;   --rot:-30deg"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9.2"/><path d="M8 8l8 8M16 8l-8 8"/></svg></span>
    <span class="bs-particle" style="--x:48%; --sz:16px; --dur:9s;  --delay:1s;   --rot:40deg"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9.2"/><path d="M8 8l8 8M16 8l-8 8"/></svg></span>
    <span class="bs-particle" style="--x:70%; --sz:14px; --dur:11s; --delay:3.4s; --rot:-20deg"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9.2"/><path d="M8 8l8 8M16 8l-8 8"/></svg></span>
    <span class="bs-particle" style="--x:88%; --sz:18px; --dur:10.5s;--delay:.6s;  --rot:30deg"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9.2"/><path d="M8 8l8 8M16 8l-8 8"/></svg></span>
</div>

<div class="bs-logo-wrap">
    <img src="../assets/img/LOGO_BLANCO.png" alt="Zabisu">
    <span>Zabisu</span>
</div>

<div class="bs-card">
    <div class="bs-icono-wrap">
        <div class="bs-icono-bounce">
            <div class="bs-icono">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9.2"/><path d="M8 8l8 8M16 8l-8 8"/></svg>
            </div>
        </div>
    </div>

    <p class="bs-eyebrow">Aviso importante</p>
    <h1 class="bs-titulo">No tenemos servicio estos días</h1>
    <p class="bs-texto">
        Por causas de fuerza mayor, del <strong><?php echo htmlspecialchars($bs_rangoTexto); ?></strong>
        no podemos tomar ni entregar pedidos.
    </p>
    <p class="bs-disculpa">Disculpa las molestias, reanudamos el <?php echo htmlspecialchars($bs_regresoTexto); ?> con todo el sabor de siempre.</p>

    <div class="bs-sep"></div>

    <span class="bs-badge">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3.5" y="5" width="17" height="15.5" rx="2.2"/><path d="M8 3v4M16 3v4M3.5 10h17"/></svg>
        Regresamos: <?php echo htmlspecialchars($bs_regresoTexto); ?>
    </span>
</div>

<p class="bs-footer">© 2026 Zabisu · Sabor y Servicio</p>

</body>
</html>
<?php exit; endif;