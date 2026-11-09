<?php
require_once "../config/db.php";
require_once "auth_check.php";

$mensaje = "";
$tipoMensaje = "";

/*
    Manejar entradas / ajustes de stock
*/
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $accion    = $_POST["accion"]    ?? "";
    $id_item   = (int)($_POST["id_item"]   ?? 0);
    $cantidad  = (int)($_POST["cantidad"]  ?? 0);
    $notas     = trim($_POST["notas"]      ?? "");

    if ($id_item > 0 && $cantidad > 0 && in_array($accion, ["entrada", "ajuste_suma", "ajuste_resta"])) {

        if ($accion === "entrada" || $accion === "ajuste_suma") {
            $tipo  = ($accion === "entrada") ? "entrada" : "ajuste";
            $delta = $cantidad;
        } else {
            $tipo  = "ajuste";
            $delta = -$cantidad;
        }

        try {
            $conexion->beginTransaction();

            $stmtUpd = $conexion->prepare(
                "UPDATE inventario_desechable
                    SET stock_actual = stock_actual + :delta
                  WHERE id_item = :id_item"
            );
            $stmtUpd->execute([":delta" => $delta, ":id_item" => $id_item]);

            $stmtMov = $conexion->prepare(
                "INSERT INTO inventario_movimientos (id_item, tipo, cantidad, notas)
                 VALUES (:id_item, :tipo, :cantidad, :notas)"
            );
            $stmtMov->execute([
                ":id_item"  => $id_item,
                ":tipo"     => $tipo,
                ":cantidad" => $delta,
                ":notas"    => $notas !== "" ? $notas : null,
            ]);

            $conexion->commit();

            $mensaje     = "Stock actualizado correctamente.";
            $tipoMensaje = "ok";

        } catch (Exception $e) {
            $conexion->rollBack();
            $mensaje     = "Error al actualizar el stock.";
            $tipoMensaje = "error";
        }

    } else {
        $mensaje     = "Datos inválidos. Verifica el artículo y la cantidad.";
        $tipoMensaje = "error";
    }
}

/*
    Obtener todos los artículos
*/
$stmtItems = $conexion->prepare(
    "SELECT * FROM inventario_desechable ORDER BY id_item ASC"
);
$stmtItems->execute();
$items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

/*
    Items con stock bajo
*/
$itemsBajos = array_filter($items, function ($i) {
    return (int)$i["stock_actual"] <= (int)$i["stock_minimo"];
});

/*
    Códigos de barras registrados (escáner) y descuentos automáticos de hoy
*/
require_once "../includes/inventario.php";
inventarioPrepararTablas($conexion);
$codigos = $conexion->query(
    "SELECT c.codigo, c.piezas, c.propio, i.nombre
       FROM inventario_codigos c JOIN inventario_desechable i ON i.id_item = c.id_item
      ORDER BY i.id_item, c.piezas"
)->fetchAll(PDO::FETCH_ASSOC);
$enNegativo = count(array_filter($items, fn($i) => (int)$i["stock_actual"] < 0));
$ultimoConteo = $conexion->query(
    "SELECT MAX(creado_en) FROM inventario_movimientos WHERE tipo = 'ajuste' AND notas LIKE 'Conteo físico%'"
)->fetchColumn();
$descuentosHoy = (int)$conexion->query(
    "SELECT COUNT(DISTINCT id_pedido) FROM inventario_movimientos WHERE tipo = 'descuento' AND DATE(creado_en) = CURDATE()"
)->fetchColumn();

/*
    Últimos 30 movimientos hechos a mano o con el escáner
    (los descuentos automáticos por pedido son miles y no dejaban verlos)
*/
$stmtMovs = $conexion->prepare(
    "SELECT m.*, i.nombre AS nombre_item
       FROM inventario_movimientos m
       INNER JOIN inventario_desechable i ON i.id_item = m.id_item
      WHERE m.tipo IN ('entrada', 'ajuste')
      ORDER BY m.creado_en DESC, m.id_movimiento DESC
      LIMIT 30"
);
$stmtMovs->execute();
$movimientos = $stmtMovs->fetchAll(PDO::FETCH_ASSOC);

// Aviso después de cambiar la cifra desde un cuadro
$ajustado = null;
if (isset($_GET["ajustado"])) {
    foreach ($items as $i) {
        if ((int)$i["id_item"] === (int)$_GET["ajustado"]) $ajustado = $i;
    }
}

function fmtFechaInv($ts) {
    return date("d/m/Y g:i A", strtotime($ts));
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require __DIR__ . "/../includes/rz_app.php"; ?>
    <title>Inventario | Restaurante Zabisu</title>
    <link rel="icon" type="image/png" href="../assets/img/LOGO_NARA.png">
    <meta name="theme-color" content="#151413">
    <link rel="preload" href="../assets/fonts/kaushan-script-400.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="../assets/fonts/alfa-slab-one-400.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="../assets/css/menu-pizarron.css?v=5">
    <link rel="stylesheet" href="../assets/css/inventario-pizarron.css?v=3">
</head>
<body class="pz">
<?php require __DIR__ . "/../includes/pz_filtros.php"; ?>

<main class="iv">
    <header class="iv-cab">
        <div class="pz-marca">
            <img src="../assets/img/LOGO_N_128.png" alt="">
            <div>
                <span class="pz-marca__nombre">Zabisu</span>
                <span class="pz-marca__lema">Sabor y Servicio</span>
            </div>
        </div>
        <h1 class="pz-titulo"><span class="pz-pincel"><span>Inventario</span></span></h1>
        <nav class="iv-cab__acc"><a href="panel_general.php" class="iv-btn">← Panel general</a></nav>
    </header>
    <p class="iv-sub">Desechable. Se descuenta solo con cada pedido; aquí se anota lo que llega.</p>

    <?php if ($mensaje !== ""): ?>
        <div class="<?php echo $tipoMensaje === 'ok' ? 'iv-aviso' : 'iv-error'; ?>"><p><?php echo htmlspecialchars($mensaje); ?></p></div>
    <?php endif; ?>

    <?php if ($ajustado): ?>
        <div class="iv-aviso"><span>✓</span><div><strong><?php echo htmlspecialchars($ajustado["nombre"]); ?> quedó en <?php echo number_format((int)$ajustado["stock_actual"]); ?>.</strong><p>Quedó anotado en "Entradas y conteos".</p></div></div>
    <?php endif; ?>

    <!-- Lo que se hace aquí: grande y a la mano -->
    <nav class="iv-acciones" aria-label="Acciones de inventario">
        <a href="inventario_escanear.php?modo=recibir" class="iv-accion iv-accion--naranja">
            <span class="iv-accion__icono" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7V5a2 2 0 0 1 2-2h2M17 3h2a2 2 0 0 1 2 2v2M21 17v2a2 2 0 0 1-2 2h-2M7 21H5a2 2 0 0 1-2-2v-2"/><path d="M7 8v8M10 8v8M13 8v8M16.5 8v8"/></svg></span>
            <strong>Recibir mercancía</strong>
            <small>Escanea los paquetes que llegaron</small>
        </a>
        <a href="inventario_escanear.php?modo=contar" class="iv-accion iv-accion--estraza">
            <span class="iv-accion__icono" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M9 4h6M9 4a2 2 0 0 0-2 2h10a2 2 0 0 0-2-2M7 6H5.5A1.5 1.5 0 0 0 4 7.5v12A1.5 1.5 0 0 0 5.5 21h13a1.5 1.5 0 0 0 1.5-1.5v-12A1.5 1.5 0 0 0 18.5 6H17"/><path d="M8 12h8M8 16h5"/></svg></span>
            <strong>Contar lo que hay</strong>
            <small>Deja el stock en lo que hay en el almacén</small>
        </a>
        <a href="inventario_etiquetas.php" class="iv-accion">
            <span class="iv-accion__icono" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M7 17H5a2 2 0 0 1-2-2v-4a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2h-2"/><path d="M7 9V3h10v6"/><rect x="7" y="14" width="10" height="7" rx="1"/></svg></span>
            <strong>Etiquetas</strong>
            <small>Imprime códigos para lo que no trae</small>
        </a>
    </nav>

    <?php if ($enNegativo > 0): ?>
    <div class="iv-alerta">
        <p><strong><?php echo $enNegativo; ?> producto<?php echo $enNegativo !== 1 ? "s" : ""; ?> en negativo.</strong>
        El sistema descontó más de lo que se anotó que llegó<?php echo $ultimoConteo ? "" : " (nunca se ha hecho un conteo)"; ?>. Haz un conteo para arrancar con lo que de verdad hay.</p>
        <a href="inventario_escanear.php?modo=contar" class="iv-btn iv-btn--estraza">Contar ahora</a>
    </div>
    <?php endif; ?>

    <!-- STOCK -->
    <section class="iv-sec">
        <div class="iv-sec__cab">
            <h2 class="pz-cinta pz-fibra">Stock actual</h2>
            <span class="iv-sec__dato"><?php echo count($items); ?> artículos<?php echo $descuentosHoy ? " · hoy se descontaron " . $descuentosHoy . " pedido" . ($descuentosHoy !== 1 ? "s" : "") : ""; ?></span>
        </div>
        <div class="iv-stock">
            <?php foreach ($items as $item): ?>
                <?php
                    $stock  = (int)$item["stock_actual"];
                    $minimo = (int)$item["stock_minimo"];
                    if ($stock <= 0) {
                        $estado = "agotado"; $label = $stock < 0 ? "En negativo" : "Agotado";
                    } elseif ($stock <= $minimo) {
                        $estado = "bajo"; $label = "Stock bajo";
                    } else {
                        $estado = "ok"; $label = "OK";
                    }
                ?>
                <button type="button" class="iv-art iv-art--<?php echo $estado; ?>"
                        data-id="<?php echo (int)$item["id_item"]; ?>" data-nombre="<?php echo htmlspecialchars($item["nombre"]); ?>" data-stock="<?php echo $stock; ?>"
                        aria-label="<?php echo htmlspecialchars($item["nombre"]); ?>: <?php echo $stock; ?>. Toca para cambiar la cifra">
                    <span class="iv-art__nombre"><?php echo htmlspecialchars($item["nombre"]); ?></span>
                    <strong class="iv-art__stock"><?php echo number_format($stock); ?></strong>
                    <span class="iv-art__pie"><span class="iv-art__estado"><?php echo $label; ?></span> mínimo <?php echo number_format($minimo); ?></span>
                    <svg class="iv-art__lapiz" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                </button>
            <?php endforeach; ?>
        </div>
        <p class="iv-nota">Toca un cuadro para cambiar su cifra a mano (por ejemplo, piezas sueltas).</p>
    </section>

    <!-- CÓDIGOS -->
    <section class="iv-sec">
        <details class="iv-plegable">
            <summary><span class="pz-cinta pz-fibra">Códigos registrados</span><span class="iv-sec__dato"><?php echo count($codigos); ?></span></summary>
            <?php if (empty($codigos)): ?>
                <p class="iv-vacio">Todavía no hay códigos. Se guardan solos la primera vez que escaneas un paquete.</p>
            <?php else: ?>
            <ul class="iv-codigos" id="lista-codigos">
                <?php foreach ($codigos as $c): ?>
                <li>
                    <span><b><?php echo htmlspecialchars($c["nombre"]); ?></b> · paquete de <?php echo number_format($c["piezas"]); ?>
                        <small><?php echo htmlspecialchars($c["codigo"]); ?><?php echo (int)$c["propio"] ? " · etiqueta Zabisu" : ""; ?></small></span>
                    <button type="button" class="iv-btn iv-btn--chico iv-btn--rojo" data-borrar="<?php echo htmlspecialchars($c["codigo"]); ?>">Quitar</button>
                </li>
                <?php endforeach; ?>
            </ul>
            <p class="iv-nota">Si un código quedó con el producto o las piezas equivocadas, quítalo y vuelve a escanearlo.</p>
            <?php endif; ?>
        </details>
    </section>

    <!-- HISTORIAL -->
    <section class="iv-sec">
        <h2 class="pz-cinta pz-fibra">Entradas y conteos</h2>
        <?php if (empty($movimientos)): ?>
            <p class="iv-vacio">Todavía no hay entradas registradas.</p>
        <?php else: ?>
        <ul class="iv-historial">
            <?php foreach ($movimientos as $mov): ?>
                <?php $cant = (int)$mov["cantidad"]; ?>
                <li>
                    <span class="iv-historial__tipo iv-historial__tipo--<?php echo $mov["tipo"]; ?>"><?php echo $mov["tipo"] === "entrada" ? "Entrada" : "Ajuste"; ?></span>
                    <span class="iv-historial__txt">
                        <b><?php echo htmlspecialchars($mov["nombre_item"]); ?></b>
                        <small><?php echo fmtFechaInv($mov["creado_en"]); ?><?php echo $mov["notas"] ? " · " . htmlspecialchars($mov["notas"]) : ""; ?></small>
                    </span>
                    <strong class="iv-historial__cant <?php echo $cant >= 0 ? 'iv-historial__cant--mas' : 'iv-historial__cant--menos'; ?>"><?php echo ($cant >= 0 ? "+" : "−") . number_format(abs($cant)); ?></strong>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </section>
</main>

<!-- Hoja: cambiar la cifra de un producto -->
<div class="iv-hoja-fondo" id="hoja-cifra" hidden>
    <form class="iv-hoja" id="form-cifra" role="dialog" aria-modal="true" aria-labelledby="cifra-nombre">
        <p class="iv-hoja__eyebrow">Cambiar la cifra</p>
        <h2 class="iv-hoja__titulo" id="cifra-nombre"></h2>
        <p class="iv-hoja__nota">En el sistema: <b id="cifra-antes"></b>. Pon cuántas hay de verdad.</p>
        <div class="iv-cifra">
            <button type="button" class="iv-cifra__btn" data-paso="-1" aria-label="Una menos">−</button>
            <input type="number" id="cifra-total" min="0" max="10000000" inputmode="numeric" required aria-label="Cuántas hay">
            <button type="button" class="iv-cifra__btn iv-cifra__btn--mas" data-paso="1" aria-label="Una más">+</button>
        </div>
        <div class="iv-hoja__chips">
            <button type="button" class="iv-chip" data-paso="-10">−10</button>
            <button type="button" class="iv-chip" data-paso="-5">−5</button>
            <button type="button" class="iv-chip" data-paso="5">+5</button>
            <button type="button" class="iv-chip" data-paso="10">+10</button>
        </div>
        <p class="iv-cifra__dif" id="cifra-dif"></p>
        <label class="iv-campo iv-campo--texto">
            <span>Motivo (opcional)</span>
            <input type="text" id="cifra-motivo" maxlength="120" placeholder="Ej. piezas sueltas, se rompieron" autocomplete="off">
        </label>
        <div class="iv-hecho__acc">
            <button type="button" class="iv-btn" id="cifra-cancelar">Cancelar</button>
            <button type="submit" class="iv-btn iv-btn--naranja" id="cifra-guardar">Guardar</button>
        </div>
    </form>
</div>

<script>
// Cambiar la cifra de un producto tocando su cuadro
(function () {
    var hoja = document.getElementById("hoja-cifra");
    var input = document.getElementById("cifra-total");
    var dif = document.getElementById("cifra-dif");
    var guardar = document.getElementById("cifra-guardar");
    var actual = null;

    function valor() { var n = parseInt(input.value, 10); return isNaN(n) ? null : n; }
    function pintarDif() {
        var n = valor();
        if (n === null || n === actual.stock) { dif.textContent = ""; dif.className = "iv-cifra__dif"; return; }
        var d = n - actual.stock;
        dif.textContent = (d > 0 ? "+" : "−") + Math.abs(d).toLocaleString("es-MX") + " contra lo que hay en el sistema";
        dif.className = "iv-cifra__dif " + (d > 0 ? "iv-cifra__dif--mas" : "iv-cifra__dif--menos");
    }
    function abrir(card) {
        actual = { id: card.dataset.id, nombre: card.dataset.nombre, stock: parseInt(card.dataset.stock, 10) };
        document.getElementById("cifra-nombre").textContent = actual.nombre;
        document.getElementById("cifra-antes").textContent = actual.stock.toLocaleString("es-MX");
        input.value = Math.max(0, actual.stock);
        document.getElementById("cifra-motivo").value = "";
        guardar.disabled = false;
        pintarDif();
        hoja.hidden = false;
        input.focus();
        input.select();
    }
    function cerrar() { hoja.hidden = true; actual = null; }

    document.querySelectorAll(".iv-art[data-id]").forEach(function (card) {
        card.addEventListener("click", function () { abrir(card); });
    });
    hoja.querySelectorAll("[data-paso]").forEach(function (b) {
        b.addEventListener("click", function () {
            input.value = Math.max(0, (valor() || 0) + parseInt(b.dataset.paso, 10));
            pintarDif();
        });
    });
    input.addEventListener("input", pintarDif);
    document.getElementById("cifra-cancelar").addEventListener("click", cerrar);
    hoja.addEventListener("click", function (e) { if (e.target === hoja) cerrar(); });
    document.addEventListener("keydown", function (e) { if (e.key === "Escape" && !hoja.hidden) cerrar(); });

    document.getElementById("form-cifra").addEventListener("submit", function (e) {
        e.preventDefault();
        var n = valor();
        if (n === null || n < 0) { input.focus(); return; }
        if (n === actual.stock) { cerrar(); return; }
        guardar.disabled = true;
        fetch("inventario_api.php", {
            method: "POST",
            headers: { "Accept": "application/json", "Content-Type": "application/x-www-form-urlencoded" },
            body: "accion=fijar&id_item=" + encodeURIComponent(actual.id) + "&total=" + n +
                  "&motivo=" + encodeURIComponent(document.getElementById("cifra-motivo").value)
        }).then(function (r) { return r.json(); }).then(function (r) {
            if (!r.ok) throw new Error(r.mensaje || "");
            location.href = "inventario.php?ajustado=" + encodeURIComponent(actual.id);
        }).catch(function (err) {
            guardar.disabled = false;
            alert(err.message || "No se pudo guardar. Intenta de nuevo.");
        });
    });
})();

// Quitar un código mal registrado
document.querySelectorAll("[data-borrar]").forEach(function (btn) {
    btn.addEventListener("click", function () {
        if (!confirm("¿Quitar el código " + btn.dataset.borrar + "? La próxima vez que lo escanees te preguntará de nuevo qué producto es.")) return;
        btn.disabled = true;
        fetch("inventario_api.php", {
            method: "POST",
            headers: { "Accept": "application/json", "Content-Type": "application/x-www-form-urlencoded" },
            body: "accion=borrar_codigo&codigo=" + encodeURIComponent(btn.dataset.borrar)
        }).then(function (r) { return r.json(); }).then(function (r) {
            if (!r.ok) throw new Error();
            btn.closest("li").remove();
        }).catch(function () { btn.disabled = false; alert("No se pudo quitar. Intenta de nuevo."); });
    });
});
</script>
</body>
</html>
