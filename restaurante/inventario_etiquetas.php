<?php
/*
    Etiquetas con código de barras para los paquetes que no traen uno.
    Se imprimen en la impresora de tickets (papel de 80 mm) para pegarlas
    en el paquete o en el anaquel. Al imprimir, el código queda registrado
    y el escáner ya lo reconoce.
*/
require_once "../config/db.php";
require_once "auth_check.php";
require_once "../includes/inventario.php";

inventarioPrepararTablas($conexion);

$articulos = $conexion->query("SELECT id_item, nombre FROM inventario_desechable ORDER BY id_item")->fetchAll(PDO::FETCH_ASSOC);
$porId = [];
foreach ($articulos as $a) $porId[(int)$a["id_item"]] = $a["nombre"];

$errores   = [];
$etiqueta  = null;
$idItem    = (int)($_POST["id_item"] ?? $_GET["id_item"] ?? 0);
$piezas    = (int)($_POST["piezas"] ?? $_GET["piezas"] ?? 0);
$copias    = (int)($_POST["copias"] ?? 1);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!isset($porId[$idItem]))           $errores[] = "Elige el producto.";
    if ($piezas < 1 || $piezas > 9999)     $errores[] = "Pon cuántas piezas trae el paquete (1 a 9,999).";
    if ($copias < 1 || $copias > 30)       $errores[] = "Las copias van de 1 a 30.";

    if (empty($errores)) {
        $codigo = inventarioCodigoPropio($idItem, $piezas);
        $conexion->prepare(
            "INSERT INTO inventario_codigos (codigo, id_item, piezas, propio) VALUES (:c, :i, :p, 1)
             ON DUPLICATE KEY UPDATE id_item = VALUES(id_item), piezas = VALUES(piezas), propio = 1"
        )->execute([":c" => $codigo, ":i" => $idItem, ":p" => $piezas]);
        $etiqueta = ["codigo" => $codigo, "nombre" => $porId[$idItem], "piezas" => $piezas, "copias" => $copias];
    }
}

// Etiquetas propias ya impresas, para reimprimir rápido
$propias = $conexion->query(
    "SELECT c.codigo, c.id_item, c.piezas, i.nombre FROM inventario_codigos c
       JOIN inventario_desechable i ON i.id_item = c.id_item
      WHERE c.propio = 1 ORDER BY i.id_item, c.piezas"
)->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Etiquetas de inventario | Zabisu</title>
    <link rel="icon" type="image/png" href="../assets/img/LOGO_NARA.png">
    <meta name="theme-color" content="#151413">
    <link rel="preload" href="../assets/fonts/kaushan-script-400.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="../assets/fonts/alfa-slab-one-400.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="../assets/css/menu-pizarron.css?v=5">
    <link rel="stylesheet" href="../assets/css/inventario-pizarron.css?v=2">
    <?php if ($etiqueta): ?>
    <!-- Igual que ticket.php: el rollo de 80 mm lo pone el controlador de la impresora -->
    <style>@media print { @page { margin: 4mm; } }</style>
    <?php endif; ?>
</head>
<body class="pz<?php echo $etiqueta ? ' iv-imprimiendo' : ''; ?>">
<?php require __DIR__ . "/../includes/pz_filtros.php"; ?>

<main class="iv iv-pantalla">
    <header class="iv-cab">
        <div class="pz-marca">
            <img src="../assets/img/LOGO_N_128.png" alt="">
            <div>
                <span class="pz-marca__nombre">Zabisu</span>
                <span class="pz-marca__lema">Sabor y Servicio</span>
            </div>
        </div>
        <h1 class="pz-titulo"><span class="pz-pincel"><span>Etiquetas</span></span></h1>
        <nav class="iv-cab__acc"><a href="inventario.php" class="iv-btn">← Inventario</a></nav>
    </header>
    <p class="iv-sub">Para los paquetes que no traen código de barras. Se imprimen en la impresora de tickets; pégala en el paquete o en el anaquel y escanéala igual que las demás.</p>

    <?php if ($etiqueta): ?>
    <div class="iv-aviso">
        <span>✓</span>
        <div>
            <strong><?php echo $etiqueta["copias"]; ?> etiqueta<?php echo $etiqueta["copias"] !== 1 ? "s" : ""; ?> lista<?php echo $etiqueta["copias"] !== 1 ? "s" : ""; ?>: <?php echo htmlspecialchars($etiqueta["nombre"]); ?>, paquete de <?php echo number_format($etiqueta["piezas"]); ?></strong>
            <p>Si no se abrió la impresión, toca "Imprimir otra vez". El código <?php echo $etiqueta["codigo"]; ?> ya lo reconoce el escáner.</p>
        </div>
        <button type="button" class="iv-btn iv-btn--estraza" onclick="window.print()">Imprimir otra vez</button>
    </div>
    <?php endif; ?>

    <?php if (!empty($errores)): ?>
    <div class="iv-error"><?php foreach ($errores as $e): ?><p><?php echo htmlspecialchars($e); ?></p><?php endforeach; ?></div>
    <?php endif; ?>

    <form method="POST" action="inventario_etiquetas.php" class="iv-sec">
        <h2 class="pz-cinta pz-fibra">1. ¿De qué producto?</h2>
        <div class="iv-opciones">
            <?php foreach ($articulos as $a): ?>
            <label class="iv-opcion">
                <input type="radio" name="id_item" value="<?php echo (int)$a["id_item"]; ?>" <?php echo $idItem === (int)$a["id_item"] ? "checked" : ""; ?> required>
                <span><?php echo htmlspecialchars($a["nombre"]); ?></span>
            </label>
            <?php endforeach; ?>
        </div>

        <h2 class="pz-cinta pz-fibra iv-sec__sig">2. ¿Cuántas piezas trae el paquete?</h2>
        <div class="iv-tarjeta iv-campos">
            <label class="iv-campo">
                <span>Piezas por paquete</span>
                <input type="number" name="piezas" min="1" max="9999" inputmode="numeric" value="<?php echo $piezas ?: ""; ?>" placeholder="Ej. 100" required>
            </label>
            <label class="iv-campo">
                <span>Copias a imprimir</span>
                <input type="number" name="copias" min="1" max="30" inputmode="numeric" value="<?php echo max(1, $copias); ?>" required>
            </label>
        </div>

        <button type="submit" class="iv-btn iv-btn--naranja iv-btn--grande iv-sec__sig">Imprimir etiquetas</button>
    </form>

    <?php if (!empty($propias)): ?>
    <section class="iv-sec">
        <h2 class="pz-cinta pz-fibra">Etiquetas que ya existen</h2>
        <ul class="iv-codigos">
            <?php foreach ($propias as $p): ?>
            <li>
                <span><b><?php echo htmlspecialchars($p["nombre"]); ?></b> · paquete de <?php echo number_format($p["piezas"]); ?><small><?php echo htmlspecialchars($p["codigo"]); ?></small></span>
                <form method="POST" action="inventario_etiquetas.php">
                    <input type="hidden" name="id_item" value="<?php echo (int)$p["id_item"]; ?>">
                    <input type="hidden" name="piezas" value="<?php echo (int)$p["piezas"]; ?>">
                    <input type="hidden" name="copias" value="1">
                    <button type="submit" class="iv-btn iv-btn--chico">Reimprimir</button>
                </form>
            </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>
</main>

<?php if ($etiqueta): ?>
<!-- Lo que sale en la impresora de tickets (80 mm) -->
<div class="iv-etiquetas">
    <?php $svg = inventarioCode128Svg($etiqueta["codigo"], 60); ?>
    <?php for ($i = 0; $i < $etiqueta["copias"]; $i++): ?>
    <div class="iv-etiqueta">
        <p class="iv-etiqueta__marca">ZABISU · INVENTARIO</p>
        <p class="iv-etiqueta__nombre"><?php echo htmlspecialchars($etiqueta["nombre"]); ?></p>
        <p class="iv-etiqueta__piezas">Paquete de <?php echo number_format($etiqueta["piezas"]); ?> piezas</p>
        <div class="iv-etiqueta__barras"><?php echo $svg; ?></div>
        <p class="iv-etiqueta__codigo"><?php echo htmlspecialchars($etiqueta["codigo"]); ?></p>
    </div>
    <?php endfor; ?>
</div>
<script>window.addEventListener("load", function () { setTimeout(function () { window.print(); }, 300); });</script>
<?php endif; ?>
</body>
</html>
