<?php
require_once "../config/db.php";
date_default_timezone_set("America/Mexico_City");

/* ── Aviso día mundialista (activar: cambiar false por in_array(date('Y-m-d'), ['YYYY-MM-DD', ...])) ── */
if (false): ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Zabisu</title>
    <link rel="icon" type="image/png" href="../assets/img/LOGO_NARA.png">
    <link rel="manifest" href="../manifest.json">
    <meta name="theme-color" content="#FF7A00">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Zabisu">
    <link rel="apple-touch-icon" href="../assets/img/LOGO_NARA.png">
    <link rel="stylesheet" href="../assets/css/styles.css?v=5">
    <style>
    *, *::before, *::after { box-sizing: border-box; margin:0; padding:0; }
    body {
        font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
        background: #0a0e0a;
        display: flex; flex-direction: column;
        align-items: center; justify-content: center;
        min-height: 100vh; padding: 20px;
    }
    body::before {
        content:''; position:fixed; inset:0; pointer-events:none;
        background:
            radial-gradient(ellipse at 50% -10%, rgba(255,122,0,.18) 0%, transparent 55%),
            radial-gradient(ellipse at 50% 110%, rgba(74,200,110,.12) 0%, transparent 55%);
    }
    .mun-bg { position:fixed; inset:0; pointer-events:none; overflow:hidden; z-index:0; }
    .mun-confetti { position:absolute; bottom:-10px; left:var(--x); width:8px; height:8px; border-radius:2px; background:var(--c); animation:mun-rise 3.5s ease-in-out var(--d) infinite; opacity:0; }
    @keyframes mun-rise { 0%{transform:translateY(0) rotate(0deg);opacity:0} 10%{opacity:.9} 90%{opacity:.6} 100%{transform:translateY(-110vh) rotate(540deg);opacity:0} }

    .mun-marca {
        position: relative; z-index:1;
        display: flex; align-items: center; gap: 10px;
        margin-bottom: 28px;
        animation: mun-fadein .4s ease both;
    }
    .mun-marca img { width: 60px; height: 60px; object-fit:contain; filter: drop-shadow(0 0 14px rgba(255,122,0,.55)); }
    .mun-marca span { font-size: 52px; font-weight: 900; color: #fff; letter-spacing: -2px; text-shadow: 0 0 40px rgba(255,122,0,.35); }
    @keyframes mun-fadein { from{opacity:0;transform:translateY(-8px)} to{opacity:1;transform:translateY(0)} }

    .mun-card {
        position: relative; z-index:1;
        background: linear-gradient(160deg, #111a11 0%, #0e0e14 55%, #0e0e12 100%);
        border: 1px solid rgba(255,122,0,.2);
        border-radius: 28px;
        padding: 36px 26px 32px;
        max-width: 380px; width: 100%;
        text-align: center;
        box-shadow:
            0 0 0 1px rgba(74,200,110,.1),
            0 0 50px rgba(255,122,0,.08),
            0 20px 60px rgba(0,0,0,.7);
        animation: mun-popin .55s cubic-bezier(.34,1.4,.64,1) .15s both;
    }
    @keyframes mun-popin { from{transform:scale(.85) translateY(20px);opacity:0} to{transform:scale(1) translateY(0);opacity:1} }

    .mun-ball-wrap { display:flex; flex-direction:column; align-items:center; margin-bottom:22px; }
    .mun-ball-bounce { animation:mun-bounce 1s cubic-bezier(.4,0,.2,1) infinite alternate; }
    @keyframes mun-bounce { from{transform:translateY(0)} to{transform:translateY(-26px)} }
    .mun-ball { font-size:76px; line-height:1; animation:mun-spin 1.8s linear infinite; display:inline-block; filter:drop-shadow(0 6px 18px rgba(0,0,0,.6)); }
    @keyframes mun-spin { from{transform:rotate(0deg)} to{transform:rotate(360deg)} }
    .mun-shadow { width:56px; height:12px; background:radial-gradient(ellipse,rgba(0,0,0,.55) 0%,transparent 70%); border-radius:50%; margin-top:6px; animation:mun-shpulse 1s ease-in-out infinite alternate; }
    @keyframes mun-shpulse { from{transform:scaleX(.55);opacity:.6} to{transform:scaleX(1.1);opacity:.25} }

    .mun-eyebrow {
        font-size:10px; font-weight:700; letter-spacing:2.5px;
        text-transform:uppercase;
        background: linear-gradient(90deg, #ff7a00, #4ac86e);
        -webkit-background-clip: text; -webkit-text-fill-color: transparent;
        background-clip: text;
        margin-bottom:8px;
    }
    .mun-titulo { font-size:23px; font-weight:900; color:#fff; margin-bottom:12px; letter-spacing:-.3px; line-height:1.25; }
    .mun-texto { font-size:14px; color:rgba(255,255,255,.55); line-height:1.75; margin-bottom:18px; }
    .mun-texto strong { color:#fff; }
    .mun-vuelta {
        display: inline-block;
        font-size:13px; font-weight:700;
        color: #ff7a00;
        background: rgba(255,122,0,.1);
        border: 1px solid rgba(255,122,0,.25);
        border-radius: 99px;
        padding: 5px 14px;
        margin-bottom: 18px;
        letter-spacing: .3px;
    }
    .mun-flags { font-size:24px; letter-spacing:6px; animation:mun-wave 2s ease-in-out infinite; }
    @keyframes mun-wave { 0%,100%{transform:scale(1)} 50%{transform:scale(1.08)} }
    </style>
</head>
<body>
<div class="mun-bg">
    <span class="mun-confetti" style="--x:5%;--d:0s;--c:#ff7a00"></span>
    <span class="mun-confetti" style="--x:15%;--d:.5s;--c:#4ac86e"></span>
    <span class="mun-confetti" style="--x:27%;--d:.9s;--c:#facc15"></span>
    <span class="mun-confetti" style="--x:40%;--d:.2s;--c:#ff7a00"></span>
    <span class="mun-confetti" style="--x:55%;--d:.7s;--c:#4ac86e"></span>
    <span class="mun-confetti" style="--x:67%;--d:1.1s;--c:#fff"></span>
    <span class="mun-confetti" style="--x:78%;--d:.4s;--c:#ff7a00"></span>
    <span class="mun-confetti" style="--x:90%;--d:.6s;--c:#facc15"></span>
</div>

<div class="mun-marca">
    <img src="../assets/img/LOGO_BLANCO.png" alt="Zabisu">
    <span>Zabisu</span>
</div>

<div class="mun-card">
    <div class="mun-ball-wrap">
        <div class="mun-ball-bounce"><div class="mun-ball">⚽</div></div>
        <div class="mun-shadow"></div>
    </div>
    <p class="mun-eyebrow">🏆 FIFA World Cup 2026</p>
    <h2 class="mun-titulo">¡Hoy es día mundialista!</h2>
    <p class="mun-texto"><strong>No contamos con servicio.</strong></p>
    <span class="mun-vuelta">📅 ¡Los esperamos el miércoles con todo el sabor!</span>
    <div class="mun-flags">🇲🇽 🌍 🇺🇸 🇨🇦</div>
</div>
</body>
</html>
<?php exit; endif;


$sqlMenu = "SELECT * FROM menu_dia WHERE activo = 1 ORDER BY fecha DESC LIMIT 1";
$stmtMenu = $conexion->prepare($sqlMenu);
$stmtMenu->execute();
$menuActivo = $stmtMenu->fetch(PDO::FETCH_ASSOC);

if (!$menuActivo) {
    // Buscar próximo menú programado
    $stmtNext = $conexion->prepare(
        "SELECT fecha FROM menu_dia WHERE fecha >= CURDATE() ORDER BY fecha ASC LIMIT 1"
    );
    $stmtNext->execute();
    $nextMenu = $stmtNext->fetch(PDO::FETCH_ASSOC);

    $proximoTexto = '';
    if ($nextMenu) {
        $dias   = ['Sunday'=>'Dom','Monday'=>'Lun','Tuesday'=>'Mar','Wednesday'=>'Mié','Thursday'=>'Jue','Friday'=>'Vie','Saturday'=>'Sáb'];
        $mesesC = ['01'=>'ene','02'=>'feb','03'=>'mar','04'=>'abr','05'=>'may','06'=>'jun','07'=>'jul','08'=>'ago','09'=>'sep','10'=>'oct','11'=>'nov','12'=>'dic'];
        $ts     = strtotime($nextMenu['fecha']);
        $proximoTexto = ($dias[date('l',$ts)] ?? '') . ' ' . date('j',$ts) . ' ' . ($mesesC[date('m',$ts)] ?? '');
    }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Zabisu</title>
    <link rel="icon" type="image/png" href="../assets/img/LOGO_NARA.png">
    <link rel="manifest" href="../manifest.json">
    <meta name="theme-color" content="#FF7A00">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Zabisu">
    <link rel="apple-touch-icon" href="../assets/img/LOGO_NARA.png">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
    background: #09090f;
    min-height: 100dvh;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 24px 16px;
    overflow: hidden;
    position: relative;
}

/* ── Glow de fondo ────────────────────────────────────── */
.nm-glow-top {
    position: fixed;
    top: -120px; left: 50%;
    transform: translateX(-50%);
    width: 600px; height: 400px;
    background: radial-gradient(ellipse, rgba(255,122,0,.18) 0%, transparent 65%);
    pointer-events: none;
    animation: nm-glow-pulse 4s ease-in-out infinite alternate;
}
@keyframes nm-glow-pulse {
    from { opacity: .7; transform: translateX(-50%) scale(1); }
    to   { opacity: 1;  transform: translateX(-50%) scale(1.08); }
}

/* ── Partículas flotantes ─────────────────────────────── */
.nm-particles {
    position: fixed;
    inset: 0;
    pointer-events: none;
    overflow: hidden;
    z-index: 0;
}
.nm-particle {
    position: absolute;
    bottom: -30px;
    font-size: var(--sz);
    left: var(--x);
    opacity: 0;
    animation: nm-rise var(--dur) ease-in-out var(--delay) infinite;
    filter: blur(.3px);
    user-select: none;
}
@keyframes nm-rise {
    0%   { transform: translateY(0)      rotate(0deg)   scale(.8); opacity: 0;   }
    8%   {                                                           opacity: .45; }
    85%  {                                                           opacity: .2;  }
    100% { transform: translateY(-105vh) rotate(var(--rot))  scale(1.1); opacity: 0; }
}

/* ── Logo ─────────────────────────────────────────────── */
.nm-logo-wrap {
    position: relative;
    z-index: 1;
    display: flex;
    align-items: center;
    gap: 14px;
    margin-bottom: 26px;
    animation: nm-fadein .5s cubic-bezier(.25,0,.1,1) .05s both;
}
.nm-logo-wrap img {
    width: 52px; height: 52px;
    object-fit: contain;
    animation: nm-logo-glow 3s ease-in-out infinite alternate;
}
@keyframes nm-logo-glow {
    from { filter: drop-shadow(0 0 10px rgba(255,122,0,.45)); }
    to   { filter: drop-shadow(0 0 26px rgba(255,122,0,.85)); }
}
.nm-logo-wrap span {
    font-size: 62px;
    font-weight: 900;
    letter-spacing: -3px;
    line-height: 1;
    /* Gradiente naranja con shimmer */
    background: linear-gradient(
        105deg,
        #ff6a00 0%,
        #ff9a40 30%,
        #ffe0b0 50%,
        #ff9a40 70%,
        #ff6a00 100%
    );
    background-size: 220% auto;
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    animation: nm-shimmer 3.2s linear infinite, nm-texto-entrada .7s cubic-bezier(.34,1.4,.64,1) .08s both;
}
@keyframes nm-shimmer {
    0%   { background-position: 0%   center; }
    100% { background-position: 220% center; }
}
@keyframes nm-texto-entrada {
    from { opacity: 0; transform: translateY(12px) scale(.88); filter: blur(6px); }
    to   { opacity: 1; transform: translateY(0)    scale(1);   filter: blur(0); }
}

/* ── Card ─────────────────────────────────────────────── */
.nm-card {
    position: relative;
    z-index: 1;
    background: linear-gradient(160deg, rgba(255,255,255,.04) 0%, rgba(255,255,255,.02) 100%);
    border: 1px solid rgba(255,255,255,.08);
    border-radius: 28px;
    padding: 38px 28px 34px;
    max-width: 370px;
    width: 100%;
    text-align: center;
    box-shadow:
        0 0 0 1px rgba(255,122,0,.07),
        0 24px 70px rgba(0,0,0,.65),
        0 0 50px rgba(255,122,0,.05);
    animation: nm-popin .65s cubic-bezier(.34,1.35,.64,1) .12s both;
}
@keyframes nm-popin {
    from { transform: scale(.82) translateY(28px); opacity: 0; }
    to   { transform: scale(1)   translateY(0);    opacity: 1; }
}

/* ── Icono principal ──────────────────────────────────── */
.nm-icono-wrap {
    display: flex;
    flex-direction: column;
    align-items: center;
    margin-bottom: 22px;
    animation: nm-fadein .5s cubic-bezier(.25,0,.1,1) .3s both;
}
.nm-icono-bounce {
    animation: nm-bounce 2.2s cubic-bezier(.4,0,.2,1) infinite alternate;
}
@keyframes nm-bounce {
    from { transform: translateY(0); }
    to   { transform: translateY(-14px); }
}
.nm-icono {
    font-size: 70px;
    line-height: 1;
    filter: drop-shadow(0 6px 20px rgba(0,0,0,.55));
}
/* ── Vapor ────────────────────────────────────────────── */
.nm-steam {
    display: flex;
    gap: 10px;
    margin-top: 8px;
    height: 28px;
    align-items: flex-end;
    justify-content: center;
}
.nm-steam__line {
    width: 3px;
    border-radius: 99px;
    background: rgba(255,255,255,.25);
    animation: nm-vapor var(--vdur) ease-in-out var(--vdelay) infinite;
    transform-origin: bottom center;
}
.nm-steam__line--1 { --vdur: 1.6s; --vdelay: 0s;    height: 18px; }
.nm-steam__line--2 { --vdur: 2s;   --vdelay: .35s;  height: 24px; }
.nm-steam__line--3 { --vdur: 1.8s; --vdelay: .15s;  height: 16px; }
@keyframes nm-vapor {
    0%   { transform: scaleX(1)    translateY(0);   opacity: .5; }
    40%  { transform: scaleX(1.5)  translateY(-6px); opacity: .3; }
    70%  { transform: scaleX(.8)   translateY(-14px); opacity: .15; }
    100% { transform: scaleX(1.2)  translateY(-22px); opacity: 0; }
}

/* ── Textos ───────────────────────────────────────────── */
.nm-eyebrow {
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 2.5px;
    text-transform: uppercase;
    color: rgba(255,122,0,.75);
    margin-bottom: 8px;
    animation: nm-fadein .5s cubic-bezier(.25,0,.1,1) .38s both;
}
.nm-titulo {
    font-size: 26px;
    font-weight: 900;
    color: #fff;
    margin-bottom: 10px;
    letter-spacing: -.4px;
    line-height: 1.2;
    animation: nm-fadein .5s cubic-bezier(.25,0,.1,1) .44s both;
}
.nm-texto {
    font-size: 14px;
    color: rgba(255,255,255,.5);
    line-height: 1.75;
    margin-bottom: 16px;
    animation: nm-fadein .5s cubic-bezier(.25,0,.1,1) .50s both;
}

/* ── Próximo menú ─────────────────────────────────────── */
.nm-proximo {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    background: rgba(255,122,0,.1);
    border: 1px solid rgba(255,122,0,.25);
    border-radius: 99px;
    padding: 7px 16px;
    font-size: 13px;
    font-weight: 700;
    color: #ff9a40;
    letter-spacing: .2px;
    margin-bottom: 20px;
    animation: nm-fadein .5s cubic-bezier(.25,0,.1,1) .56s both;
}

/* ── Separador ────────────────────────────────────────── */
.nm-sep {
    width: 100%;
    height: 1px;
    background: rgba(255,255,255,.07);
    margin: 18px 0;
    animation: nm-fadein .5s cubic-bezier(.25,0,.1,1) .58s both;
}

/* ── Auto-refresh indicator ───────────────────────────── */
.nm-refresh {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    margin-bottom: 18px;
    animation: nm-fadein .5s cubic-bezier(.25,0,.1,1) .62s both;
}
.nm-refresh__txt {
    font-size: 12px;
    color: rgba(255,255,255,.3);
    letter-spacing: .2px;
}
.nm-dots {
    display: flex;
    gap: 4px;
    align-items: center;
}
.nm-dot {
    width: 4px; height: 4px;
    border-radius: 50%;
    background: rgba(255,122,0,.5);
    animation: nm-dot-bounce .9s ease-in-out infinite;
}
.nm-dot:nth-child(1) { animation-delay: 0s; }
.nm-dot:nth-child(2) { animation-delay: .18s; }
.nm-dot:nth-child(3) { animation-delay: .36s; }
@keyframes nm-dot-bounce {
    0%,80%,100% { transform: scale(.7); opacity: .4; }
    40%          { transform: scale(1.2); opacity: 1; }
}

/* ── Botón de pedido ──────────────────────────────────── */
.nm-btn {
    display: block;
    width: 100%;
    background: rgba(255,255,255,.06);
    border: 1px solid rgba(255,255,255,.1);
    border-radius: 14px;
    color: rgba(255,255,255,.7);
    font-size: 14px;
    font-weight: 600;
    padding: 13px 20px;
    text-decoration: none;
    letter-spacing: .2px;
    transition: background .15s, color .15s, border-color .15s;
    animation: nm-fadein .5s cubic-bezier(.25,0,.1,1) .66s both;
}
.nm-btn:hover {
    background: rgba(255,255,255,.1);
    color: #fff;
    border-color: rgba(255,255,255,.18);
}
.nm-btn:active { transform: scale(.98); }

/* ── Footer ───────────────────────────────────────────── */
.nm-footer {
    position: relative;
    z-index: 1;
    margin-top: 24px;
    font-size: 11px;
    color: rgba(255,255,255,.2);
    letter-spacing: .3px;
    animation: nm-fadein .5s cubic-bezier(.25,0,.1,1) .72s both;
}

/* ── Fade-in base ─────────────────────────────────────── */
@keyframes nm-fadein {
    from { opacity: 0; transform: translateY(10px); }
    to   { opacity: 1; transform: translateY(0); }
}

@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after { animation-duration: .01ms !important; }
}
</style>
</head>
<body>

<div class="nm-glow-top"></div>

<!-- Partículas -->
<div class="nm-particles" aria-hidden="true">
    <span class="nm-particle" style="--x:6%;  --sz:20px; --dur:9s;  --delay:0s;    --rot:30deg">🍽️</span>
    <span class="nm-particle" style="--x:17%; --sz:16px; --dur:11s; --delay:1.4s;  --rot:-20deg">🥄</span>
    <span class="nm-particle" style="--x:29%; --sz:18px; --dur:8s;  --delay:3.1s;  --rot:45deg">🥗</span>
    <span class="nm-particle" style="--x:42%; --sz:14px; --dur:12s; --delay:0.7s;  --rot:-15deg">🍲</span>
    <span class="nm-particle" style="--x:56%; --sz:20px; --dur:10s; --delay:2.2s;  --rot:60deg">🍴</span>
    <span class="nm-particle" style="--x:68%; --sz:16px; --dur:9.5s;--delay:1.0s;  --rot:-40deg">🥘</span>
    <span class="nm-particle" style="--x:80%; --sz:18px; --dur:11s; --delay:3.8s;  --rot:25deg">🧂</span>
    <span class="nm-particle" style="--x:91%; --sz:14px; --dur:8.5s;--delay:2.6s;  --rot:-30deg">🥣</span>
</div>

<!-- Logo -->
<div class="nm-logo-wrap">
    <img src="../assets/img/LOGO_BLANCO.png" alt="Zabisu">
    <span>Zabisu</span>
</div>

<!-- Card -->
<div class="nm-card">

    <div class="nm-icono-wrap">
        <div class="nm-icono-bounce">
            <div class="nm-icono">🍽️</div>
        </div>
        <div class="nm-steam">
            <span class="nm-steam__line nm-steam__line--1"></span>
            <span class="nm-steam__line nm-steam__line--2"></span>
            <span class="nm-steam__line nm-steam__line--3"></span>
        </div>
    </div>

    <p class="nm-eyebrow">Restaurante Zabisu</p>
    <h1 class="nm-titulo">Sin menú hoy</h1>
    <p class="nm-texto">
        El menú del día aún no está publicado.<br>
        Vuelve pronto, algo delicioso está en camino.
    </p>

    <?php if ($proximoTexto): ?>
    <div class="nm-proximo">
        <span>📅</span> Próximo: <?php echo htmlspecialchars($proximoTexto); ?>
    </div>
    <?php endif; ?>

    <div class="nm-sep"></div>

    <div class="nm-refresh">
        <div class="nm-dots">
            <span class="nm-dot"></span>
            <span class="nm-dot"></span>
            <span class="nm-dot"></span>
        </div>
        <span class="nm-refresh__txt" id="nm-refresh-txt">Revisando automáticamente…</span>
    </div>

    <a href="estado_pedido.php" class="nm-btn">
        Consultar mi pedido anterior
    </a>
</div>

<p class="nm-footer">© 2026 Zabisu · Sabor y Servicio</p>

<script>
// Auto-refresh silencioso cada 2 minutos
(function () {
    var intervalo = 120; // segundos
    var restante  = intervalo;
    var txtEl     = document.getElementById('nm-refresh-txt');

    var timer = setInterval(function () {
        restante--;
        if (restante <= 0) {
            restante = intervalo;
            // Comprobar si hay menú activo sin recargar la página
            fetch('menu.php', { method: 'HEAD' })
                .then(function (r) {
                    // Si el servidor ya no devuelve la vista de "sin menú",
                    // recargamos para que el cliente vea el menú
                    if (r.redirected || r.ok) {
                        // Verificamos con un GET silencioso
                        fetch('check_menu.php?_=' + Date.now())
                            .then(function (r2) { return r2.text(); })
                            .then(function (txt) {
                                if (txt.trim() === '1') location.reload();
                            })
                            .catch(function () {});
                    }
                })
                .catch(function () {});
        }
        if (txtEl) {
            if (restante <= 10) {
                txtEl.textContent = 'Revisando en ' + restante + 's…';
            } else {
                txtEl.textContent = 'Revisando automáticamente…';
            }
        }
    }, 1000);
})();
</script>
</body>
</html>
<?php exit; }

$sqlProductos = "SELECT * FROM productos WHERE id_menu = :id_menu AND disponible = 1
                 ORDER BY tipo_menu, categoria, nombre";
$stmtProductos = $conexion->prepare($sqlProductos);
$stmtProductos->bindParam(":id_menu", $menuActivo["id_menu"], PDO::PARAM_INT);
$stmtProductos->execute();

$sqlConteos = "SELECT dp.id_producto, COUNT(*) AS total_pedidos
               FROM detalle_pedido dp
               INNER JOIN pedido_menus pm ON dp.id_pedido_menu = pm.id_pedido_menu
               INNER JOIN pedidos p ON pm.id_pedido = p.id_pedido
               INNER JOIN productos pr ON dp.id_producto = pr.id_producto
               WHERE p.estado != 'Cancelado'
                 AND p.es_prueba = 0
                 AND pr.id_menu = :id_menu
               GROUP BY dp.id_producto";
$stmtConteos = $conexion->prepare($sqlConteos);
$stmtConteos->bindParam(":id_menu", $menuActivo["id_menu"], PDO::PARAM_INT);
$stmtConteos->execute();
$conteosProductos = [];
foreach ($stmtConteos->fetchAll(PDO::FETCH_ASSOC) as $fila) {
    $conteosProductos[$fila["id_producto"]] = (int)$fila["total_pedidos"];
}

$menusPorTipo = [];
foreach ($stmtProductos->fetchAll(PDO::FETCH_ASSOC) as $p) {
    $totalActual = $conteosProductos[(int)$p["id_producto"]] ?? 0;
    $limite = isset($p["limite_pedidos"]) ? (int)$p["limite_pedidos"] : 0;
    $p["agotado"] = ($p["categoria"] === "Plato fuerte" && $limite > 0 && $totalActual >= $limite)
                 || !empty($p["agotado_manual"]);
    $menusPorTipo[$p["tipo_menu"]][$p["categoria"]][] = $p;
}

$sqlPrecios = "SELECT nombre_menu, precio FROM tipos_menu WHERE activo = 1";
$stmtPrecios = $conexion->prepare($sqlPrecios);
$stmtPrecios->execute();
$precios = [];
foreach ($stmtPrecios->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $precios[$r["nombre_menu"]] = (float)$r["precio"];
}

$dias   = ['Sunday'=>'Domingo','Monday'=>'Lunes','Tuesday'=>'Martes','Wednesday'=>'Miércoles','Thursday'=>'Jueves','Friday'=>'Viernes','Saturday'=>'Sábado'];
$meses  = ['01'=>'enero','02'=>'febrero','03'=>'marzo','04'=>'abril','05'=>'mayo','06'=>'junio','07'=>'julio','08'=>'agosto','09'=>'septiembre','10'=>'octubre','11'=>'noviembre','12'=>'diciembre'];
$ts         = strtotime($menuActivo["fecha"]);
$fechaBonita = ($dias[date("l",$ts)] ?? "") . " " . date("j",$ts) . " de " . ($meses[date("m",$ts)] ?? "");

$ordenCat = ["Plato fuerte","Sopa","Complemento","Agua","Cortesia"];
$iconosCat = ["Plato fuerte"=>"🍽️","Sopa"=>"🥣","Complemento"=>"🥗","Agua"=>"💧","Cortesia"=>"🍬"];

/* ── Productos populares (platos fuertes y complementos) ── */
$popularCats = ["Plato fuerte", "Complemento"];
$maxConteoPorCat = [];
foreach ($popularCats as $catPop) {
    $max = 0;
    foreach ($menusPorTipo as $cats) {
        foreach ($cats[$catPop] ?? [] as $p) {
            $c = $conteosProductos[(int)$p["id_producto"]] ?? 0;
            if ($c > $max) $max = $c;
        }
    }
    $maxConteoPorCat[$catPop] = $max;
}
$popularIds = [];
foreach ($popularCats as $catPop) {
    $max = $maxConteoPorCat[$catPop];
    if ($max < 3) continue;
    foreach ($menusPorTipo as $cats) {
        foreach ($cats[$catPop] ?? [] as $p) {
            if (($conteosProductos[(int)$p["id_producto"]] ?? 0) === $max) {
                $popularIds[(int)$p["id_producto"]] = true;
            }
        }
    }
}

/* ── Postre del día ── */
$stmtPostre = $conexion->prepare(
    "SELECT * FROM productos WHERE id_menu = :id_menu AND categoria = 'Postre' AND disponible = 1 LIMIT 1"
);
$stmtPostre->bindParam(":id_menu", $menuActivo["id_menu"], PDO::PARAM_INT);
$stmtPostre->execute();
$postreDelDia = $stmtPostre->fetch(PDO::FETCH_ASSOC);

/* ── Timestamp de cierre para el countdown ── */
$cierre_ts = (int)strtotime($menuActivo["pedido_hasta"]);

/* ── Calificaciones públicas ── */
$stmtRatings = $conexion->prepare(
    "SELECT COUNT(*) AS total, ROUND(AVG(calificacion), 1) AS promedio FROM feedback_pedidos"
);
$stmtRatings->execute();
$ratingsGlobal = $stmtRatings->fetch(PDO::FETCH_ASSOC);
$ratingsTotal  = (int)($ratingsGlobal["total"]    ?? 0);
$ratingsProm   = (float)($ratingsGlobal["promedio"] ?? 0);

$stmtRatingsDist = $conexion->prepare(
    "SELECT calificacion, COUNT(*) AS total
     FROM feedback_pedidos
     GROUP BY calificacion
     ORDER BY calificacion DESC"
);
$stmtRatingsDist->execute();
$ratingsDist = [];
foreach ($stmtRatingsDist->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $ratingsDist[(int)$row["calificacion"]] = (int)$row["total"];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Menú del día · Zabisu</title>
    <link rel="icon" type="image/png" href="../assets/img/LOGO_NARA.png">
    <link rel="manifest" href="../manifest.json">
    <meta name="theme-color" content="#FF7A00">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Zabisu">
    <link rel="apple-touch-icon" href="../assets/img/LOGO_NARA.png">
    <link rel="stylesheet" href="../assets/css/styles.css?v=5">
</head>
<style>
    .hamburguer-btn {
        position: fixed;
        top: 16px;
        right: 16px;
        z-index: 200;
        background: rgba(12,12,15,.75);
        border: 1px solid rgba(255,255,255,.12);
        backdrop-filter: blur(8px);
        -webkit-backdrop-filter: blur(8px);
        border-radius: 10px;
        width: 42px;
        height: 42px;
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        gap: 5px;
        cursor: pointer;
        padding: 0;
    }
    .hamburguer-btn span {
        display: block;
        width: 20px;
        height: 2px;
        background: #fff;
        border-radius: 2px;
        transition: transform .25s, opacity .25s;
    }
    .hamburguer-btn.abierto span:nth-child(1) { transform: translateY(7px) rotate(45deg); }
    .hamburguer-btn.abierto span:nth-child(2) { opacity: 0; }
    .hamburguer-btn.abierto span:nth-child(3) { transform: translateY(-7px) rotate(-45deg); }

    .nav-overlay {
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,.5);
        z-index: 190;
        opacity: 0;
        pointer-events: none;
        transition: opacity .25s;
    }
    .nav-overlay.visible { opacity: 1; pointer-events: all; }

    .nav-drawer {
        position: fixed;
        top: 0;
        right: 0;
        bottom: 0;
        width: 260px;
        max-width: 85vw;
        background: #0c0c0f;
        z-index: 195;
        transform: translateX(100%);
        transition: transform .28s cubic-bezier(.4,0,.2,1);
        display: flex;
        flex-direction: column;
        padding: 72px 0 32px;
    }
    .nav-drawer.abierto { transform: translateX(0); }

    .nav-drawer__titulo {
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 3px;
        color: #ff7a00;
        text-transform: uppercase;
        padding: 0 24px 16px;
        border-bottom: 1px solid rgba(255,255,255,.08);
        margin-bottom: 8px;
    }

    .nav-drawer__item {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 14px 24px;
        color: #fff;
        text-decoration: none;
        font-size: 15px;
        font-weight: 600;
        transition: background .15s;
    }
    .nav-drawer__item:hover { background: rgba(255,255,255,.06); }
    .nav-drawer__item__icono {
        font-size: 20px;
        width: 28px;
        text-align: center;
        flex-shrink: 0;
    }

    /* ── Día del Niño (30 abr) ────────────────────────────── */
    .dnino-confetti {
        position: absolute;
        inset: 0;
        pointer-events: none;
        overflow: hidden;
    }
    .dnino-confetti span {
        position: absolute;
        bottom: -8px;
        border-radius: 50%;
        opacity: 0;
        animation: dnino-float linear infinite;
    }
    .dnino-confetti span:nth-child(1)  { width:5px;  height:5px;  background:#ff6b9d; left:8%;  animation-duration:7s;   animation-delay:0s;    }
    .dnino-confetti span:nth-child(2)  { width:6px;  height:6px;  background:#4ecdc4; left:18%; animation-duration:9s;   animation-delay:1.3s;  }
    .dnino-confetti span:nth-child(3)  { width:5px;  height:5px;  background:#ffe66d; left:30%; animation-duration:8s;   animation-delay:0.5s;  }
    .dnino-confetti span:nth-child(4)  { width:7px;  height:7px;  background:#a29bfe; left:44%; animation-duration:10s;  animation-delay:2.1s;  }
    .dnino-confetti span:nth-child(5)  { width:5px;  height:5px;  background:#ff6b9d; left:57%; animation-duration:7.5s; animation-delay:0.9s;  }
    .dnino-confetti span:nth-child(6)  { width:6px;  height:6px;  background:#ffe66d; left:70%; animation-duration:9.5s; animation-delay:1.8s;  }
    .dnino-confetti span:nth-child(7)  { width:5px;  height:5px;  background:#4ecdc4; left:82%; animation-duration:8.5s; animation-delay:3.2s;  }
    .dnino-confetti span:nth-child(8)  { width:6px;  height:6px;  background:#c7f2a4; left:93%; animation-duration:7s;   animation-delay:2.6s;  }
    @keyframes dnino-float {
        0%   { transform: translateY(0)      scale(0.8); opacity: 0;   }
        12%  {                                            opacity: 0.55; }
        88%  {                                            opacity: 0.4;  }
        100% { transform: translateY(-280px) scale(1.1); opacity: 0;   }
    }
    /* ── Animación de entrada ── */
    .md-seccion__cabecera,
    .md-plato,
    .md-chip { will-change: opacity, transform; }

    /* ── Countdown ── */
    .md-hero__countdown {
        font-size: 13px;
        font-weight: 700;
        letter-spacing: .3px;
        margin-top: 5px;
        min-height: 18px;
    }
    .md-hero__countdown--ok      { color: rgba(255,255,255,.7); }
    .md-hero__countdown--pronto  { color: #facc15; }
    .md-hero__countdown--urgente { color: #f87171; animation: md-pulso .9s ease-in-out infinite; }
    @keyframes md-pulso { 0%,100% { opacity:1; } 50% { opacity:.45; } }

    /* ── Badge popular ── */
    .md-badge-popular {
        display: inline-flex;
        align-items: center;
        gap: 3px;
        font-size: 11px;
        font-weight: 700;
        color: #ff7a00;
        background: rgba(255,122,0,.12);
        border: 1px solid rgba(255,122,0,.3);
        border-radius: 99px;
        padding: 2px 8px;
        letter-spacing: .2px;
        flex-shrink: 0;
        white-space: nowrap;
    }

    /* ── Rating pill en el hero ── */
    .md-hero__rating {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        margin-top: 14px;
        padding: 7px 16px;
        border-radius: 999px;
        background: rgba(255,255,255,0.06);
        border: 1px solid rgba(255,255,255,0.11);
        backdrop-filter: blur(4px);
    }
    .md-hero__rating__estrellas {
        display: flex;
        gap: 2px;
    }
    .md-hero__rating__estrella {
        font-size: 13px;
        color: rgba(255,255,255,.18);
    }
    .md-hero__rating__estrella--llena { color: #ff7a00; }
    .md-hero__rating__estrella--parcial {
        position: relative;
        display: inline-block;
    }
    .md-hero__rating__estrella--parcial::after {
        content: '★';
        position: absolute;
        left: 0;
        top: 0;
        color: #ff7a00;
        width: var(--star-pct, 50%);
        overflow: hidden;
        white-space: nowrap;
    }
    .md-hero__rating__score {
        font-size: 14px;
        font-weight: 800;
        color: #fff;
        letter-spacing: -.3px;
    }
    .md-hero__rating__sep {
        width: 1px;
        height: 12px;
        background: rgba(255,255,255,.18);
        flex-shrink: 0;
    }
    .md-hero__rating__label {
        font-size: 12px;
        font-weight: 700;
        color: #ff7a00;
        letter-spacing: .3px;
    }
    .md-hero__rating__total {
        font-size: 11px;
        color: rgba(255,255,255,.35);
        font-weight: 500;
    }

    .dnino-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        margin-top: 14px;
        padding: 6px 15px;
        border-radius: 999px;
        background: rgba(255,255,255,0.07);
        border: 1px solid rgba(255,255,255,0.13);
        font-size: 13px;
        font-weight: 600;
        color: rgba(255,255,255,0.8);
        letter-spacing: 0.2px;
    }

    /* ── Animación de entrada en carga inicial ── */
    @keyframes md-entrada {
        from { opacity: 0; transform: translateY(12px); }
        to   { opacity: 1; transform: translateY(0); }
    }

    .hamburguer-btn       { animation: md-entrada .45s cubic-bezier(.25,0,.1,1) .05s both; }
    .md-hero__eyebrow     { animation: md-entrada .5s  cubic-bezier(.25,0,.1,1) .10s both; }
    .md-hero__marca-grupo { animation: md-entrada .55s cubic-bezier(.25,0,.1,1) .18s both; }
    .md-hero__fecha       { animation: md-entrada .5s  cubic-bezier(.25,0,.1,1) .28s both; }
    .md-hero__precios     { animation: md-entrada .5s  cubic-bezier(.25,0,.1,1) .36s both; }
    .md-hero__cierre      { animation: md-entrada .5s  cubic-bezier(.25,0,.1,1) .43s both; }
    #md-countdown         { animation: md-entrada .45s cubic-bezier(.25,0,.1,1) .46s both; }
    .md-hero__rating      { animation: md-entrada .5s  cubic-bezier(.25,0,.1,1) .51s both; }
    .md-tabs              { animation: md-entrada .5s  cubic-bezier(.25,0,.1,1) .58s both; }
    .md-cta               { animation: md-entrada .5s  cubic-bezier(.25,0,.1,1) .70s both; }
    .cliente-footer       { animation: md-entrada .5s  cubic-bezier(.25,0,.1,1) .75s both; }

    /* Items del panel: ocultos hasta que JS los anime en sincronía con el hero */
    .md-tab-panel .md-seccion__cabecera,
    .md-tab-panel .md-plato,
    .md-tab-panel .md-chip {
        opacity: 0;
        transform: translateY(16px);
    }

    @media (prefers-reduced-motion: reduce) {
        .hamburguer-btn, .md-hero__eyebrow, .md-hero__marca-grupo,
        .md-hero__fecha, .md-hero__precios, .md-hero__cierre,
        #md-countdown, .md-hero__rating, .md-tabs, .md-cta, .cliente-footer {
            animation: none;
        }
        .md-tab-panel .md-seccion__cabecera,
        .md-tab-panel .md-plato,
        .md-tab-panel .md-chip {
            opacity: 1;
            transform: none;
        }
    }

    /* ── Zabisu: gradiente naranja + shimmer ── */
    .md-hero__marca {
        font-weight: 900;
        letter-spacing: -3px;
        color: unset;
        text-shadow: none;
        background: linear-gradient(
            105deg,
            #ff6a00 0%,
            #ff9a40 30%,
            #ffe0b0 50%,
            #ff9a40 70%,
            #ff6a00 100%
        );
        background-size: 220% auto;
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        animation:
            md-marca-shimmer 3.2s linear infinite,
            md-marca-entrada .7s cubic-bezier(.34,1.4,.64,1) .18s both;
    }
    @keyframes md-marca-shimmer {
        0%   { background-position: 0%   center; }
        100% { background-position: 220% center; }
    }
    @keyframes md-marca-entrada {
        from { opacity: 0; transform: translateY(12px) scale(.88); filter: blur(6px); }
        to   { opacity: 1; transform: translateY(0)    scale(1);   filter: blur(0);   }
    }
</style>

<body>

<!-- ── Hamburger ── -->
<button class="hamburguer-btn" id="hamburguer-btn" aria-label="Menú">
    <span></span><span></span><span></span>
</button>

<!-- ── Overlay ── -->
<div class="nav-overlay" id="nav-overlay"></div>

<!-- ── Drawer ── -->
<nav class="nav-drawer" id="nav-drawer">
    <div class="nav-drawer__titulo">Zabisu</div>
    <a class="nav-drawer__item" href="estado_pedido.php">
        <span class="nav-drawer__item__icono">🔍</span>
        Consultar mi pedido
    </a>
    <a class="nav-drawer__item" href="menu.php">
        <span class="nav-drawer__item__icono">🍽️</span>
        Ver menú del día
    </a>
    <a class="nav-drawer__item" href="pedido.php">
        <span class="nav-drawer__item__icono">🛒</span>
        Hacer un pedido
    </a>
</nav>

<div class="md-pagina">

    <!-- ══ HERO ══════════════════════════════════════════════ -->
    <div class="md-hero">
        <div class="md-hero__glow-top"></div>
        <div class="md-hero__glow-bottom"></div>

        <?php if (in_array(date('m-d'), ['04-29','04-30'])): ?>
        <div class="dnino-confetti" aria-hidden="true">
            <span></span><span></span><span></span><span></span>
            <span></span><span></span><span></span><span></span>
        </div>
        <?php endif; ?>

        <p class="md-hero__eyebrow">Menú del día</p>
        <div class="md-hero__marca-grupo">
            <img class="md-hero__logo" src="../assets/img/LOGO_BLANCO.png" alt="Zabisu">
            <h1 class="md-hero__marca">Zabisu</h1>
        </div>
        <p class="md-hero__fecha"><?php echo htmlspecialchars($fechaBonita); ?></p>

        <div class="md-hero__precios">
            <?php foreach (["Zabisu","Ejecutivo"] as $t): ?>
                <?php if (isset($precios[$t])): ?>
                <div class="md-precio-pill">
                    <span class="md-precio-pill__tipo"><?php echo $t; ?></span>
                    <span class="md-precio-pill__sep">·</span>
                    <span class="md-precio-pill__valor">$<?php echo number_format($precios[$t],2); ?></span>
                </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>

        <div class="md-hero__cierre">
            Pedidos hasta las <strong><?php echo date("g:i A", strtotime($menuActivo["pedido_hasta"])); ?></strong>
        </div>
        <div class="md-hero__countdown md-hero__countdown--ok" id="md-countdown"></div>

        <?php if ($ratingsTotal >= 3):
            $promR    = (float)$ratingsProm;
            $promPiso = (int)floor($promR);
            $fraccion = $promR - $promPiso;
            $pctEstr  = round($fraccion * 100);
            $etqR = match(true) {
                $promR >= 4.5 => "Excelente",
                $promR >= 3.5 => "Bueno",
                $promR >= 2.5 => "Regular",
                default       => "Mejorable",
            };
        ?>
        <div class="md-hero__rating">
            <div class="md-hero__rating__estrellas">
                <?php for ($s = 1; $s <= 5; $s++):
                    if ($s <= $promPiso): ?>
                        <span class="md-hero__rating__estrella md-hero__rating__estrella--llena">★</span>
                    <?php elseif ($s === $promPiso + 1 && $fraccion > 0): ?>
                        <span class="md-hero__rating__estrella md-hero__rating__estrella--parcial"
                              style="--star-pct:<?php echo $pctEstr; ?>%">★</span>
                    <?php else: ?>
                        <span class="md-hero__rating__estrella">★</span>
                    <?php endif;
                endfor; ?>
            </div>
            <span class="md-hero__rating__score"><?php echo number_format($promR, 1); ?></span>
            <span class="md-hero__rating__sep"></span>
            <span class="md-hero__rating__label"><?php echo $etqR; ?></span>
            <span class="md-hero__rating__total"><?php echo $ratingsTotal; ?> opiniones</span>
        </div>
        <?php endif; ?>

        <?php if (in_array(date('m-d'), ['04-29','04-30'])): ?>
        <div class="dnino-badge">🎈 ¡Feliz Día del Niño!</div>
        <?php endif; ?>
    </div>

    <!-- ══ TABS ═══════════════════════════════════════════════ -->
    <div class="md-contenido">

        <div class="md-tabs" role="tablist">
            <?php foreach (["Zabisu","Ejecutivo"] as $i => $t): ?>
                <?php if (empty($menusPorTipo[$t])) continue; ?>
                <button class="md-tab <?php echo $i === 0 ? 'md-tab--activo' : ''; ?>"
                        data-tab="<?php echo $t; ?>" type="button" role="tab">
                    <span class="md-tab__nombre">Menú <?php echo $t; ?></span>
                    <?php if (isset($precios[$t])): ?>
                        <span class="md-tab__precio">$<?php echo number_format($precios[$t],2); ?></span>
                    <?php endif; ?>
                </button>
            <?php endforeach; ?>
        </div>

        <!-- ══ CONTENIDO POR TAB ═════════════════════════════ -->
        <?php foreach (["Zabisu","Ejecutivo"] as $i => $tipoMenu):
            if (empty($menusPorTipo[$tipoMenu])) continue;
        ?>
        <div class="md-tab-panel <?php echo $i === 0 ? 'md-tab-panel--activo' : ''; ?>"
             id="panel-<?php echo $tipoMenu; ?>">

            <?php
            // Renderizar Agua y Cortesia juntos en una fila
            $tieneAgua     = !empty($menusPorTipo[$tipoMenu]["Agua"]);
            $tieneCortesia = !empty($menusPorTipo[$tipoMenu]["Cortesia"]);
            $simplesMostradas = false;
            ?>

            <?php foreach ($ordenCat as $cat):
                if (empty($menusPorTipo[$tipoMenu][$cat])) continue;
                $items   = $menusPorTipo[$tipoMenu][$cat];
                $icono   = $iconosCat[$cat] ?? "•";
                $esPlato = ($cat === "Plato fuerte");
                $esChip  = ($cat === "Complemento");
                $esSimple = in_array($cat, ["Agua","Cortesia"]);

                // Agua y Cortesia se renderizan juntas, solo una vez
                if ($esSimple && $simplesMostradas) continue;
                if ($esSimple) $simplesMostradas = true;
            ?>

            <?php if ($esSimple): ?>
            <div class="md-seccion">
                <div class="md-fila-simple">
                    <?php foreach (["Agua","Cortesia"] as $catSimple):
                        if (empty($menusPorTipo[$tipoMenu][$catSimple])) continue;
                    ?>
                    <div class="md-fila-simple__col">
                        <div class="md-seccion__cabecera">
                            <span class="md-seccion__icono"><?php echo $iconosCat[$catSimple]; ?></span>
                            <h2 class="md-seccion__titulo"><?php echo $catSimple; ?></h2>
                        </div>
                        <div class="md-chips">
                            <?php foreach ($menusPorTipo[$tipoMenu][$catSimple] as $item): ?>
                                <span class="md-chip <?php echo !empty($item['agotado']) ? 'md-chip--agotado' : ''; ?>">
                                    <?php echo htmlspecialchars($item["nombre"]); ?>
                                    <?php if (!empty($item["descripcion"])): ?>
                                        <span class="md-chip__desc"><?php echo htmlspecialchars($item["descripcion"]); ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($item["agotado"])): ?><span class="md-badge-agotado">Agotado</span><?php endif; ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php else: ?>
            <div class="md-seccion">
                <div class="md-seccion__cabecera">
                    <span class="md-seccion__icono"><?php echo $icono; ?></span>
                    <h2 class="md-seccion__titulo">
                        <?php echo $cat === "Complemento" ? "Complementos" : htmlspecialchars($cat); ?>
                    </h2>
                </div>

                <?php if ($esChip): ?>
                    <div class="md-chips">
                        <?php foreach ($items as $item): ?>
                            <span class="md-chip <?php echo !empty($item['agotado']) ? 'md-chip--agotado' : ''; ?>">
                                <?php echo htmlspecialchars($item["nombre"]); ?>
                                <?php if (!empty($item["descripcion"])): ?>
                                    <span class="md-chip__desc"><?php echo htmlspecialchars($item["descripcion"]); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($item["agotado"])): ?>
                                    <span class="md-badge-agotado">Agotado</span>
                                <?php elseif (!empty($popularIds[(int)$item["id_producto"]])): ?>
                                    <span class="md-badge-popular">🔥 Popular</span>
                                <?php endif; ?>
                            </span>
                        <?php endforeach; ?>
                    </div>

                <?php elseif ($esPlato): ?>
                    <div class="md-platos">
                        <?php foreach ($items as $j => $item): ?>
                            <div class="md-plato <?php echo !empty($item['agotado']) ? 'md-plato--agotado' : ''; ?>">
                                <span class="md-plato__num"><?php echo $j+1; ?></span>
                                <div class="md-plato__info">
                                    <strong class="md-plato__nombre"><?php echo htmlspecialchars($item["nombre"]); ?></strong>
                                    <?php if (!empty($item["descripcion"])): ?>
                                        <p class="md-plato__desc"><?php echo htmlspecialchars($item["descripcion"]); ?></p>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($item["agotado"])): ?>
                                    <span class="md-badge-agotado">Agotado</span>
                                <?php elseif (!empty($popularIds[(int)$item["id_producto"]])): ?>
                                    <span class="md-badge-popular">🔥 Popular</span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                <?php else: ?>
                    <div class="md-platos">
                        <?php foreach ($items as $item): ?>
                            <div class="md-plato md-plato--sin-num <?php echo !empty($item['agotado']) ? 'md-plato--agotado' : ''; ?>">
                                <div class="md-plato__info">
                                    <strong class="md-plato__nombre"><?php echo htmlspecialchars($item["nombre"]); ?></strong>
                                    <?php if (!empty($item["descripcion"])): ?>
                                        <p class="md-plato__desc"><?php echo htmlspecialchars($item["descripcion"]); ?></p>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($item["agotado"])): ?>
                                    <span class="md-badge-agotado">Agotado</span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php endforeach; ?>

        </div>
        <?php endforeach; ?>

    </div><!-- /.md-contenido -->

    <?php if ($postreDelDia): ?>
    <div class="md-contenido">
        <div class="md-seccion">
            <div class="md-seccion__cabecera">
                <span class="md-seccion__icono">🍮</span>
                <h2 class="md-seccion__titulo">Postre del día
                    <span style="font-size:13px;font-weight:700;color:#ff7a00;margin-left:6px;">· Extra</span>
                </h2>
            </div>
            <div class="md-platos">
                <div class="md-plato">
                    <div class="md-plato__info">
                        <strong class="md-plato__nombre"><?php echo htmlspecialchars($postreDelDia["nombre"]); ?></strong>
                        <?php if (!empty($postreDelDia["descripcion"])): ?>
                        <p class="md-plato__desc"><?php echo htmlspecialchars($postreDelDia["descripcion"]); ?></p>
                        <?php endif; ?>
                    </div>
                    <?php if ((float)$postreDelDia["precio"] > 0): ?>
                    <span style="color:#ff7a00;font-weight:800;font-size:14px;flex-shrink:0;">+$<?php echo number_format((float)$postreDelDia["precio"], 2); ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="md-contenido">
        <!-- ══ CTA ════════════════════════════════════════════ -->
        <a href="pedido.php" class="md-cta">
            Ordenar ahora
            <span class="md-cta__flecha">→</span>
        </a>
    </div>
</div>

<script>
function animarPanel(panel) {
    if (!panel) return;
    var items = Array.from(panel.querySelectorAll(".md-seccion__cabecera, .md-plato, .md-chip"));
    var EASE = "cubic-bezier(.2,0,.1,1)";
    var DUR  = ".4s";
    var GAP  = 45;

    items.forEach(function (el) {
        el.style.transition = "none";
        el.style.opacity    = "0";
        el.style.transform  = "translateY(16px)";
    });

    requestAnimationFrame(function () {
        requestAnimationFrame(function () {
            items.forEach(function (el, i) {
                var d = (i * GAP) + "ms";
                el.style.transition = "opacity " + DUR + " " + EASE + " " + d +
                                      ", transform " + DUR + " " + EASE + " " + d;
                el.style.opacity   = "1";
                el.style.transform = "translateY(0)";
            });
        });
    });
}

document.querySelectorAll(".md-tab").forEach(function (tab) {
    tab.addEventListener("click", function () {
        document.querySelectorAll(".md-tab").forEach(function (t) { t.classList.remove("md-tab--activo"); });
        document.querySelectorAll(".md-tab-panel").forEach(function (p) { p.classList.remove("md-tab-panel--activo"); });
        this.classList.add("md-tab--activo");
        var panel = document.getElementById("panel-" + this.dataset.tab);
        if (panel) { panel.classList.add("md-tab-panel--activo"); animarPanel(panel); }
    });
});

(function () {
    var reducido = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    if (reducido) {
        animarPanel(document.querySelector(".md-tab-panel--activo"));
    } else {
        setTimeout(function () {
            animarPanel(document.querySelector(".md-tab-panel--activo"));
        }, 620);
    }
})();

// Al tocar cualquier ítem del menú, bajar al botón y animarlo
var cta = document.querySelector(".md-cta");

function llamarCta() {
    if (!cta) return;
    cta.scrollIntoView({ behavior: "smooth", block: "center" });
    cta.classList.remove("md-cta--pulso");
    void cta.offsetWidth; // forzar reflow para reiniciar animación
    cta.classList.add("md-cta--pulso");
}

document.querySelectorAll(".md-plato:not(.md-plato--agotado), .md-chip:not(.md-chip--agotado)").forEach(function (el) {
    el.addEventListener("click", llamarCta);
});
</script>

<script>
(function () {
    var btn     = document.getElementById("hamburguer-btn");
    var drawer  = document.getElementById("nav-drawer");
    var overlay = document.getElementById("nav-overlay");

    function abrir() {
        drawer.classList.add("abierto");
        overlay.classList.add("visible");
        btn.classList.add("abierto");
        document.body.style.overflow = "hidden";
    }
    function cerrar() {
        drawer.classList.remove("abierto");
        overlay.classList.remove("visible");
        btn.classList.remove("abierto");
        document.body.style.overflow = "";
    }

    btn.addEventListener("click", function () {
        drawer.classList.contains("abierto") ? cerrar() : abrir();
    });
    overlay.addEventListener("click", cerrar);
    document.addEventListener("keydown", function (e) {
        if (e.key === "Escape") cerrar();
    });
})();
</script>

<script>
(function () {
    var el     = document.getElementById("md-countdown");
    if (!el) return;
    var cierre = <?= $cierre_ts ?> * 1000;

    function actualizar() {
        var diff = cierre - Date.now();
        if (diff <= 0) {
            el.textContent = "Pedidos cerrados";
            el.className = "md-hero__countdown md-hero__countdown--urgente";
            return;
        }
        var h = Math.floor(diff / 3600000);
        var m = Math.floor((diff % 3600000) / 60000);
        var s = Math.floor((diff % 60000) / 1000);
        var texto;
        if (h > 0)      texto = "Cierra en " + h + "h " + m + "m";
        else if (m >= 10) texto = "Cierra en " + m + "m";
        else              texto = "Cierra en " + (m > 0 ? m + "m " : "") + s + "s";

        el.textContent = texto;
        el.className = "md-hero__countdown " + (
            diff < 600000  ? "md-hero__countdown--urgente" :
            diff < 1800000 ? "md-hero__countdown--pronto"  :
                             "md-hero__countdown--ok"
        );
    }

    actualizar();
    setInterval(actualizar, 1000);
})();
</script>

<footer class="cliente-footer">
    <span class="cliente-footer__slogan">© 2026 Zabisu - Sabor y Servicio. Todos los derechos reservados.</span>
</footer>

<!-- ══ AVISO MUNDIAL (bloque eliminado, ver inicio del archivo) ══
<div id="mundial-overlay">
    <div class="mun-bg">
        <span class="mun-confetti" style="--x:8%;--d:0s;--c:#4ac86e"></span>
        <span class="mun-confetti" style="--x:18%;--d:.4s;--c:#ff7a00"></span>
        <span class="mun-confetti" style="--x:30%;--d:.9s;--c:#facc15"></span>
        <span class="mun-confetti" style="--x:44%;--d:.2s;--c:#4ac86e"></span>
        <span class="mun-confetti" style="--x:58%;--d:.7s;--c:#ff7a00"></span>
        <span class="mun-confetti" style="--x:70%;--d:1.1s;--c:#fff"></span>
        <span class="mun-confetti" style="--x:82%;--d:.5s;--c:#facc15"></span>
        <span class="mun-confetti" style="--x:92%;--d:.3s;--c:#4ac86e"></span>
    </div>

    <div class="mun-card">
        <button class="mun-close" id="mun-close">✕</button>

        <div class="mun-ball-wrap">
            <div class="mun-ball-bounce">
                <div class="mun-ball">⚽</div>
            </div>
            <div class="mun-shadow"></div>
        </div>

        <p class="mun-eyebrow">🏆 FIFA World Cup 2026</p>
        <h2 class="mun-titulo">¡Hoy es día mundialista!</h2>
        <p class="mun-texto">
            Nuestro equipo se une a la celebración.<br>
            <strong>Hoy no contamos con servicio.</strong><br>
            Nos vemos mañana con todo el sabor de siempre. ⚡
        </p>

        <div class="mun-flags">🇲🇽 🌍 🇺🇸 🇨🇦</div>

        <button class="mun-btn" id="mun-btn">¡Entendido, buen partido!</button>
    </div>
</div>

<style>
#mundial-overlay {
    position: fixed; inset: 0; z-index: 9998;
    display: flex; align-items: center; justify-content: center;
    background: rgba(0,0,0,.85);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    animation: mun-fadein .5s ease both;
}
@keyframes mun-fadein { from { opacity:0; } to { opacity:1; } }

/* confetti */
.mun-bg { position: absolute; inset:0; pointer-events:none; overflow:hidden; }
.mun-confetti {
    position: absolute; bottom: -10px; left: var(--x);
    width: 8px; height: 8px; border-radius: 2px;
    background: var(--c);
    animation: mun-rise 3.5s ease-in-out var(--d) infinite;
    opacity: 0;
}
@keyframes mun-rise {
    0%   { transform: translateY(0) rotate(0deg); opacity:0; }
    10%  { opacity:.9; }
    90%  { opacity:.6; }
    100% { transform: translateY(-110vh) rotate(540deg); opacity:0; }
}

/* card */
.mun-card {
    position: relative; z-index: 1;
    background: linear-gradient(160deg, #0d1f0d 0%, #0e0e12 60%);
    border: 1px solid rgba(74,200,110,.25);
    border-radius: 28px;
    padding: 36px 28px 32px;
    max-width: 380px; width: calc(100% - 32px);
    text-align: center;
    box-shadow: 0 0 60px rgba(74,200,110,.1), 0 20px 60px rgba(0,0,0,.6);
    animation: mun-popin .55s cubic-bezier(.34,1.4,.64,1) .15s both;
}
@keyframes mun-popin {
    from { transform: scale(.8) translateY(30px); opacity:0; }
    to   { transform: scale(1) translateY(0);     opacity:1; }
}

.mun-close {
    position:absolute; top:14px; right:16px;
    background: rgba(255,255,255,.07); border:none; border-radius:50%;
    width:30px; height:30px; color:rgba(255,255,255,.4);
    font-size:13px; cursor:pointer; display:flex;
    align-items:center; justify-content:center;
    transition: background .15s, color .15s;
}
.mun-close:hover { background:rgba(255,255,255,.15); color:#fff; }

/* balón */
.mun-ball-wrap {
    position: relative; display:flex;
    flex-direction:column; align-items:center;
    margin-bottom: 20px;
}
.mun-ball-bounce {
    animation: mun-bounce 1s cubic-bezier(.4,0,.2,1) infinite alternate;
}
@keyframes mun-bounce {
    from { transform: translateY(0); }
    to   { transform: translateY(-28px); }
}
.mun-ball {
    font-size: 72px; line-height: 1;
    animation: mun-spin 1.8s linear infinite;
    display: inline-block;
    filter: drop-shadow(0 4px 16px rgba(0,0,0,.5));
}
@keyframes mun-spin {
    from { transform: rotate(0deg); }
    to   { transform: rotate(360deg); }
}
.mun-shadow {
    width: 60px; height: 14px;
    background: radial-gradient(ellipse, rgba(0,0,0,.5) 0%, transparent 70%);
    border-radius: 50%;
    margin-top: 4px;
    animation: mun-shadow-pulse 1s ease-in-out infinite alternate;
}
@keyframes mun-shadow-pulse {
    from { transform: scaleX(.6); opacity:.7; }
    to   { transform: scaleX(1.1); opacity:.3; }
}

.mun-eyebrow {
    font-size: 11px; font-weight:700; letter-spacing:2px;
    text-transform:uppercase; color: #4ac86e; margin:0 0 8px;
}
.mun-titulo {
    font-size: 24px; font-weight:900; color:#fff;
    margin:0 0 12px; letter-spacing:-.3px; line-height:1.2;
}
.mun-texto {
    font-size: 14px; color:rgba(255,255,255,.6);
    line-height:1.7; margin:0 0 18px;
}
.mun-texto strong { color:#fff; }
.mun-flags {
    font-size: 26px; letter-spacing: 6px; margin-bottom:20px;
    animation: mun-wave 2s ease-in-out infinite;
}
@keyframes mun-wave {
    0%,100% { transform: scale(1); }
    50%      { transform: scale(1.1); }
}
.mun-btn {
    width:100%; background: linear-gradient(135deg,#4ac86e,#22a855);
    border:none; border-radius:14px; color:#fff;
    font-size:15px; font-weight:800; padding:14px 20px;
    cursor:pointer; transition: transform .1s, opacity .15s;
    letter-spacing:.2px;
}
.mun-btn:active { transform: scale(.97); opacity:.9; }
</style>

<script>
(function () {
    var overlay = document.getElementById("mundial-overlay");
    function cerrar() {
        overlay.style.transition = "opacity .35s";
        overlay.style.opacity = "0";
        setTimeout(function () { overlay.style.display = "none"; }, 350);
    }
══ fin bloque eliminado ══ -->

<?php if (false): // ══ PWA MODAL en pausa ══ ?>
<div id="pwa-modal" style="display:none;" aria-modal="true" role="dialog">
    <div class="pwa-overlay" id="pwa-overlay"></div>
    <div class="pwa-sheet">
        <button class="pwa-close" id="pwa-close" aria-label="Cerrar">✕</button>

        <p class="pwa-eyebrow">Sin descargas · Gratis</p>
        <h2 class="pwa-titulo">Instala Zabisu</h2>

        <!-- Teléfono 3D -->
        <div class="pwa-phone-scene">
            <div class="pwa-phone-float">
                <div class="pwa-phone">
                    <div class="pwa-phone__side pwa-phone__side--left"></div>
                    <div class="pwa-phone__side pwa-phone__side--right"></div>
                    <div class="pwa-phone__face">
                        <div class="pwa-phone__island"></div>
                        <div class="pwa-phone__screen">
                            <div class="pwa-phone__grid">
                                <span style="background:#ff6b6b"></span><span style="background:#ff7a00"></span><span style="background:#facc15"></span><span style="background:#4ac86e"></span>
                                <span style="background:#38bdf8"></span><span style="background:#a78bfa"></span><span style="background:#f472b6"></span><span style="background:#34d399"></span>
                                <span style="background:#fb923c"></span><span style="background:#60a5fa"></span><span style="background:#e879f9"></span><span style="background:#4ade80"></span>
                            </div>
                            <div class="pwa-phone__app">
                                <div class="pwa-phone__app-icon">
                                    <img src="../assets/img/LOGO_NARA.png" alt="Zabisu">
                                </div>
                                <span class="pwa-phone__app-label">Zabisu</span>
                            </div>
                        </div>
                        <div class="pwa-phone__home"></div>
                    </div>
                </div>
            </div>
            <div class="pwa-phone__glow"></div>
        </div>

        <!-- Pasos -->
        <div class="pwa-steps" id="pwa-steps"></div>
    </div>
</div>

<style>
/* ── Modal overlay ── */
#pwa-modal {
    position: fixed; inset: 0; z-index: 9999;
    display: flex; align-items: flex-end; justify-content: center;
}
.pwa-overlay {
    position: absolute; inset: 0;
    background: rgba(0,0,0,.7);
    backdrop-filter: blur(6px);
    -webkit-backdrop-filter: blur(6px);
    opacity: 0; transition: opacity .35s;
}
#pwa-modal.visible .pwa-overlay { opacity: 1; }

/* ── Sheet ── */
.pwa-sheet {
    position: relative; z-index: 1;
    width: 100%; max-width: 480px;
    background: #0f0f18;
    border-radius: 28px 28px 0 0;
    padding: 28px 24px 40px;
    text-align: center;
    transform: translateY(100%);
    transition: transform .5s cubic-bezier(.34,1.18,.64,1);
    box-shadow: 0 -2px 60px rgba(255,122,0,.12);
    border-top: 1px solid rgba(255,122,0,.2);
}
#pwa-modal.visible .pwa-sheet { transform: translateY(0); }

.pwa-close {
    position: absolute; top: 16px; right: 18px;
    background: rgba(255,255,255,.08); border: none;
    color: rgba(255,255,255,.5); border-radius: 50%;
    width: 32px; height: 32px; font-size: 14px;
    cursor: pointer; display: flex; align-items: center; justify-content: center;
    transition: background .15s, color .15s;
}
.pwa-close:hover { background: rgba(255,255,255,.15); color: #fff; }

.pwa-eyebrow {
    font-size: 10px; font-weight: 700; letter-spacing: 2.5px;
    text-transform: uppercase; color: rgba(255,122,0,.6); margin: 0 0 6px;
}
.pwa-titulo {
    font-size: 22px; font-weight: 900; color: #fff;
    margin: 0 0 20px; letter-spacing: -.3px;
}

/* ── Teléfono 3D ── */
.pwa-phone-scene {
    position: relative; display: flex;
    justify-content: center; align-items: center;
    height: 200px; margin-bottom: 8px;
}
.pwa-phone-float {
    animation: pwa-float 3s ease-in-out infinite alternate;
}
@keyframes pwa-float {
    from { transform: translateY(0); }
    to   { transform: translateY(-10px); }
}
.pwa-phone {
    position: relative; width: 100px; height: 178px;
    animation: pwa-spin 4s ease-in-out infinite alternate;
    transform-style: preserve-3d;
    transform: perspective(600px) rotateY(-18deg) rotateX(-5deg);
}
@keyframes pwa-spin {
    from { transform: perspective(600px) rotateY(-22deg) rotateX(-5deg); }
    to   { transform: perspective(600px) rotateY(22deg)  rotateX(-5deg); }
}

/* Cara frontal */
.pwa-phone__face {
    position: absolute; inset: 0;
    background: linear-gradient(160deg, #1e1e2e 0%, #12121c 100%);
    border-radius: 22px;
    border: 2px solid rgba(255,255,255,.18);
    overflow: hidden;
    display: flex; flex-direction: column; align-items: center;
    box-shadow:
        inset 0 1px 0 rgba(255,255,255,.12),
        inset -1px 0 0 rgba(255,255,255,.06);
}
/* Lados del teléfono para dar volumen */
.pwa-phone__side {
    position: absolute; top: 8px; bottom: 8px; width: 4px;
    background: linear-gradient(180deg, #2a2a3e, #111120);
    border-radius: 2px;
}
.pwa-phone__side--left  { left: -3px;  box-shadow: -1px 0 4px rgba(0,0,0,.4); }
.pwa-phone__side--right { right: -3px; box-shadow:  1px 0 4px rgba(0,0,0,.4); }

/* Dynamic island */
.pwa-phone__island {
    width: 32px; height: 9px;
    background: #000; border-radius: 99px;
    margin: 8px auto 6px; flex-shrink: 0;
}
/* Pantalla */
.pwa-phone__screen {
    flex: 1; width: 100%; padding: 0 8px 6px;
    display: flex; flex-direction: column;
    align-items: center; gap: 6px;
}
/* Grid de apps */
.pwa-phone__grid {
    display: grid; grid-template-columns: repeat(4, 1fr); gap: 4px;
    width: 100%;
}
.pwa-phone__grid span {
    display: block; aspect-ratio: 1;
    border-radius: 5px; opacity: .85;
}
/* App destacada */
.pwa-phone__app {
    display: flex; flex-direction: column; align-items: center; gap: 3px;
    margin-top: 2px;
}
.pwa-phone__app-icon {
    width: 36px; height: 36px; border-radius: 10px;
    background: #ff7a00;
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 2px 10px rgba(255,122,0,.5);
    overflow: hidden;
}
.pwa-phone__app-icon img { width: 28px; height: 28px; object-fit: contain; }
.pwa-phone__app-label {
    font-size: 8px; color: rgba(255,255,255,.7); font-weight: 600;
}
/* Barra home */
.pwa-phone__home {
    width: 36px; height: 4px; border-radius: 99px;
    background: rgba(255,255,255,.25); margin: 4px auto 6px; flex-shrink: 0;
}
/* Glow debajo del teléfono */
.pwa-phone__glow {
    position: absolute; bottom: -10px; left: 50%;
    transform: translateX(-50%);
    width: 80px; height: 20px;
    background: radial-gradient(ellipse, rgba(255,122,0,.35) 0%, transparent 70%);
    filter: blur(6px);
    animation: pwa-glow 3s ease-in-out infinite alternate;
}
@keyframes pwa-glow {
    from { opacity: .5; transform: translateX(-50%) scaleX(.8); }
    to   { opacity: 1;  transform: translateX(-50%) scaleX(1.2); }
}

/* ── Pasos ── */
.pwa-steps {
    display: flex; flex-direction: column;
    gap: 10px; margin-top: 18px; text-align: left;
}
.pwa-step {
    display: flex; align-items: center; gap: 12px;
    background: rgba(255,255,255,.04);
    border: 1px solid rgba(255,255,255,.07);
    border-radius: 14px; padding: 10px 14px;
}
.pwa-step__num {
    width: 26px; height: 26px; border-radius: 50%; flex-shrink: 0;
    background: rgba(255,122,0,.15); border: 1px solid rgba(255,122,0,.35);
    color: #ff7a00; font-size: 12px; font-weight: 800;
    display: flex; align-items: center; justify-content: center;
}
.pwa-step__ico { font-size: 20px; flex-shrink: 0; }
.pwa-step__txt { font-size: 13px; color: rgba(255,255,255,.7); line-height: 1.4; }
.pwa-step__txt strong { color: #fff; font-weight: 700; }
</style>

<script>
(function () {
    if ("serviceWorker" in navigator) navigator.serviceWorker.register("../sw.js");

    var yaInstalada = window.navigator.standalone === true ||
                      window.matchMedia("(display-mode: standalone)").matches;
    if (yaInstalada) return;
    try { if (localStorage.getItem("pwa_dismissed")) return; } catch(e) {}

    var ua      = navigator.userAgent;
    var ios     = /iPhone|iPad|iPod/.test(ua) && !/CriOS|FxiOS/.test(ua);
    var android = /Android/.test(ua) && /Chrome\//.test(ua) && !/SamsungBrowser/.test(ua);
    if (!ios && !android) return;

    var modal   = document.getElementById("pwa-modal");
    var stepsEl = document.getElementById("pwa-steps");

    var pasos = ios ? [
        { ico: "⬆️", txt: "Abre Safari y toca el botón de <strong>Compartir</strong> en la parte inferior" },
        { ico: "➕", txt: "Desplázate y elige <strong>\"Agregar a pantalla de inicio\"</strong>" },
        { ico: "✅", txt: "Toca <strong>Agregar</strong> — aparecerá el ícono de Zabisu" }
    ] : [
        { ico: "⋮",  txt: "Toca los <strong>tres puntos</strong> en la esquina superior derecha de Chrome" },
        { ico: "📲", txt: "Selecciona <strong>\"Instalar app\"</strong> o <strong>\"Agregar a pantalla de inicio\"</strong>" },
        { ico: "✅", txt: "Confirma — aparecerá el ícono de Zabisu" }
    ];

    stepsEl.innerHTML = pasos.map(function (p, i) {
        return '<div class="pwa-step">' +
            '<span class="pwa-step__num">' + (i+1) + '</span>' +
            '<span class="pwa-step__ico">' + p.ico + '</span>' +
            '<span class="pwa-step__txt">' + p.txt + '</span>' +
        '</div>';
    }).join("");

    modal.style.display = "flex";
    setTimeout(function () { modal.classList.add("visible"); }, 2800);

    function cerrar() {
        modal.classList.remove("visible");
        setTimeout(function () { modal.style.display = "none"; }, 500);
        try { localStorage.setItem("pwa_dismissed", "1"); } catch(e) {}
    }

    document.getElementById("pwa-close").addEventListener("click", cerrar);
    document.getElementById("pwa-overlay").addEventListener("click", cerrar);
})();
</script>

<?php endif; ?>

<script>
if ("serviceWorker" in navigator) navigator.serviceWorker.register("../sw.js");
</script>

</body>
</html>
