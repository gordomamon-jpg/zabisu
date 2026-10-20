<?php
/*
    Pantalla de aviso del diseño "Pizarrón de fonda" (ver DESIGN.md):
    sin menú, pedidos cerrados, pedidos pausados y pedido vencido.
    Imprime la página completa; quien la llama hace exit después.

    $a = [
        'titulo'    => '¡Ya cerramos por hoy!',   // en pincel
        'letrero'   => 'Cerrado',                // rótulo del letrero colgado
        'letrero2'  => 'Volvemos mañana',        // línea a mano del letrero
        'texto'     => 'Texto corto que explica qué pasa (HTML permitido)',
        'boleto'    => ['titulo' => '...', 'lineas' => ['HTML', ...]] o null,
        'alerta'    => 'Texto importante en recuadro (HTML) o null',
        'botones'   => [['texto' => '...', 'url' => '...', 'tipo' => 'principal|contorno|liga', 'nueva' => bool], ...],
        'canal'     => true,                     // invitación al Canal
        'revisar'   => false,                    // recarga sola cuando se publica el menú
    ]
*/
require_once __DIR__ . "/canal_whatsapp.php";

function pzPantallaAviso(array $a): void
{
    $titulo = $a['titulo'] ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?php echo htmlspecialchars(strip_tags($titulo)); ?> | Zabisu</title>
    <link rel="icon" type="image/png" href="../assets/img/LOGO_NARA.png">
    <link rel="manifest" href="../manifest.json">
    <meta name="theme-color" content="#151413">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Zabisu">
    <link rel="apple-touch-icon" href="../assets/img/LOGO_NARA.png">
    <link rel="preload" href="../assets/fonts/kaushan-script-400.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="../assets/fonts/alfa-slab-one-400.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="../assets/css/menu-pizarron.css?v=2">
    <link rel="stylesheet" href="../assets/css/cliente-pizarron.css?v=4">
</head>
<body class="pz pz-cliente pz-aviso">
<?php require __DIR__ . "/pz_filtros.php"; ?>

<main class="pa-pagina">
    <header class="pz-cab">
        <a class="pz-marca" href="menu.php" aria-label="Zabisu, ir al menú">
            <img src="../assets/img/LOGO_N_128.png" alt="">
            <div>
                <span class="pz-marca__nombre">Zabisu</span>
                <span class="pz-marca__lema">Sabor y Servicio</span>
            </div>
        </a>
    </header>

    <?php if (!empty($a['letrero'])): ?>
    <div class="pa-letrero" aria-hidden="true">
        <span class="pa-letrero__clavo"></span>
        <svg class="pa-letrero__hilo" viewBox="0 0 200 60" preserveAspectRatio="none"><path d="M100 4 L22 56 M100 4 L178 56" stroke="#8d8a84" stroke-width="2" fill="none"/></svg>
        <div class="pa-letrero__carton">
            <span class="pa-letrero__rotulo"><?php echo htmlspecialchars($a['letrero']); ?></span>
            <?php if (!empty($a['letrero2'])): ?>
            <span class="pa-letrero__mano"><?php echo htmlspecialchars($a['letrero2']); ?></span>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <h1 class="pz-titulo"><span class="pz-pincel"><span><?php echo $titulo; ?></span></span></h1>
    <?php if (!empty($a['texto'])): ?>
    <p class="pa-texto"><?php echo $a['texto']; ?></p>
    <?php endif; ?>

    <?php if (!empty($a['alerta'])): ?>
    <div class="pa-alerta" role="note"><?php echo $a['alerta']; ?></div>
    <?php endif; ?>

    <?php if (!empty($a['boleto'])): ?>
    <div class="pz-boleto pa-boleto">
        <div class="pz-boleto__papel">
            <span class="pz-boleto__ojo" aria-hidden="true"></span>
            <p class="pz-boleto__titulo"><?php echo $a['boleto']['titulo']; ?></p>
            <hr class="pz-boleto__corte">
            <?php foreach ($a['boleto']['lineas'] as $linea): ?>
            <p><?php echo $linea; ?></p>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($a['botones'])): ?>
    <div class="pa-botones">
        <?php foreach ($a['botones'] as $b): ?>
        <a class="pa-btn pa-btn--<?php echo htmlspecialchars($b['tipo'] ?? 'contorno'); ?>"
           href="<?php echo htmlspecialchars($b['url']); ?>"<?php echo !empty($b['nueva']) ? ' target="_blank" rel="noopener"' : ''; ?>>
            <?php echo htmlspecialchars($b['texto']); ?>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($a['canal'])): ?>
    <a class="pc-canal pa-canal" href="<?php echo htmlspecialchars(CANAL_WHATSAPP_URL); ?>" target="_blank" rel="noopener">
        <span class="pc-canal__ico" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 11v2a1 1 0 0 0 1 1h2l5 4V6L6 10H4a1 1 0 0 0-1 1Z"/><path d="M15.5 8.5a5 5 0 0 1 0 7M18.5 5.5a9 9 0 0 1 0 13"/></svg></span>
        <span class="pc-canal__txt">
            <b>Entérate en cuanto salga el menú</b>
            <span>Únete a nuestro Canal de WhatsApp: ahí lo publicamos de lunes a jueves.</span>
        </span>
        <span class="pc-canal__btn">Unirme al Canal</span>
    </a>
    <?php endif; ?>

    <p class="pz-pie">© <?php echo date("Y"); ?> Zabisu, Sabor y Servicio</p>
</main>

<?php if (!empty($a['revisar'])): ?>
<script>
// Cuando se publica el menú, la página se actualiza sola (revisa cada 2 min)
setInterval(function () {
    fetch("check_menu.php?_=" + Date.now())
        .then(function (r) { return r.text(); })
        .then(function (t) { if (t.trim() === "1") location.reload(); })
        .catch(function () {});
}, 120000);
</script>
<?php endif; ?>
</body>
</html>
<?php
}
