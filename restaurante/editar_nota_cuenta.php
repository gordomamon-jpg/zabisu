<?php
require_once "../config/db.php";
require_once "auth_check.php";

$id = isset($_GET["id"]) ? (int)$_GET["id"] : 0;
if ($id <= 0) { header("Location: notas_cuenta.php"); exit; }

$stmtN = $conexion->prepare("SELECT * FROM notas_cuenta WHERE id_nota = :id LIMIT 1");
$stmtN->execute([":id" => $id]);
$nota = $stmtN->fetch(PDO::FETCH_ASSOC);
if (!$nota) { header("Location: notas_cuenta.php"); exit; }

$stmtPrecios = $conexion->query("SELECT nombre_menu, precio FROM tipos_menu WHERE activo = 1");
$precios = [];
foreach ($stmtPrecios->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $precios[$r["nombre_menu"]] = (float)$r["precio"];
}
$precioZabisu    = $precios["Zabisu"]    ?? 0;
$precioEjecutivo = $precios["Ejecutivo"] ?? 0;

$errores = [];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $nombreCliente = trim($_POST["nombre_cliente"] ?? "");
    $telefono      = trim($_POST["telefono"] ?? "");
    $notasTexto    = trim($_POST["notas"] ?? "");
    $diasRaw       = $_POST["dias"] ?? [];

    if ($nombreCliente === "") {
        $errores[] = "El nombre del cliente es obligatorio.";
    }

    $itemsValidos = [];
    foreach ($diasRaw as $dia) {
        $fecha = trim($dia["fecha"] ?? "");
        if ($fecha === "") continue;

        $zCant   = (float)str_replace(",", ".", $dia["zabisu_cantidad"] ?? "0");
        $zPrecio = (float)str_replace(",", ".", $dia["zabisu_precio"]   ?? "0");
        if ($zCant > 0) {
            $itemsValidos[] = ["fecha" => $fecha, "descripcion" => "Menú Zabisu", "cantidad" => $zCant, "precio_unitario" => $zPrecio, "subtotal" => round($zCant * $zPrecio, 2)];
        }

        $eCant   = (float)str_replace(",", ".", $dia["ejecutivo_cantidad"] ?? "0");
        $ePrecio = (float)str_replace(",", ".", $dia["ejecutivo_precio"]   ?? "0");
        if ($eCant > 0) {
            $itemsValidos[] = ["fecha" => $fecha, "descripcion" => "Menú Ejecutivo", "cantidad" => $eCant, "precio_unitario" => $ePrecio, "subtotal" => round($eCant * $ePrecio, 2)];
        }

        foreach (($dia["extras"] ?? []) as $extra) {
            $desc   = trim($extra["descripcion"] ?? "");
            $cant   = (float)str_replace(",", ".", $extra["cantidad"] ?? "0");
            $precio = (float)str_replace(",", ".", $extra["precio"]   ?? "0");
            if ($desc !== "" && $cant > 0) {
                $itemsValidos[] = ["fecha" => $fecha, "descripcion" => $desc, "cantidad" => $cant, "precio_unitario" => $precio, "subtotal" => round($cant * $precio, 2)];
            }
        }
    }

    if (empty($errores)) {
        try {
            $conexion->beginTransaction();

            $total = array_sum(array_column($itemsValidos, "subtotal"));

            $conexion->prepare(
                "UPDATE notas_cuenta SET nombre_cliente = :nombre_cliente, telefono = :telefono, notas = :notas, total = :total
                 WHERE id_nota = :id"
            )->execute([
                ":nombre_cliente" => $nombreCliente,
                ":telefono"       => $telefono ?: null,
                ":notas"          => $notasTexto ?: null,
                ":total"          => $total,
                ":id"             => $id,
            ]);

            $conexion->prepare("DELETE FROM notas_cuenta_items WHERE id_nota = :id")
                     ->execute([":id" => $id]);

            $stmtItem = $conexion->prepare(
                "INSERT INTO notas_cuenta_items (id_nota, fecha, descripcion, cantidad, precio_unitario, subtotal)
                 VALUES (:id_nota, :fecha, :descripcion, :cantidad, :precio_unitario, :subtotal)"
            );
            foreach ($itemsValidos as $item) {
                $stmtItem->execute([
                    ":id_nota"          => $id,
                    ":fecha"            => $item["fecha"],
                    ":descripcion"      => $item["descripcion"],
                    ":cantidad"         => $item["cantidad"],
                    ":precio_unitario"  => $item["precio_unitario"],
                    ":subtotal"         => $item["subtotal"],
                ]);
            }

            $conexion->commit();
            header("Location: ver_nota_cuenta.php?id=" . $id . "&editada=1");
            exit;

        } catch (Exception $e) {
            $conexion->rollBack();
            $errores[] = "Error al guardar: " . $e->getMessage();
        }
    }

    // Si hubo error, mantener lo que escribió el usuario
    $nota["nombre_cliente"] = $nombreCliente;
    $nota["telefono"]       = $telefono;
    $nota["notas"]          = $notasTexto;
    $diasParaJs = array_values($diasRaw);
} else {
    // Cargar días existentes agrupados, en el mismo formato que espera el editor
    $stmtI = $conexion->prepare("SELECT * FROM notas_cuenta_items WHERE id_nota = :id ORDER BY fecha ASC, id_item ASC");
    $stmtI->execute([":id" => $id]);
    $itemsExistentes = $stmtI->fetchAll(PDO::FETCH_ASSOC);

    $diasAgrupados = [];
    foreach ($itemsExistentes as $it) {
        $f = $it["fecha"];
        if (!isset($diasAgrupados[$f])) {
            $diasAgrupados[$f] = [
                "fecha"              => $f,
                "zabisu_cantidad"    => 0,
                "zabisu_precio"      => $precioZabisu,
                "ejecutivo_cantidad" => 0,
                "ejecutivo_precio"   => $precioEjecutivo,
                "extras"             => [],
            ];
        }
        if ($it["descripcion"] === "Menú Zabisu") {
            $diasAgrupados[$f]["zabisu_cantidad"] = (float)$it["cantidad"];
            $diasAgrupados[$f]["zabisu_precio"]   = (float)$it["precio_unitario"];
        } elseif ($it["descripcion"] === "Menú Ejecutivo") {
            $diasAgrupados[$f]["ejecutivo_cantidad"] = (float)$it["cantidad"];
            $diasAgrupados[$f]["ejecutivo_precio"]   = (float)$it["precio_unitario"];
        } else {
            $diasAgrupados[$f]["extras"][] = [
                "descripcion" => $it["descripcion"],
                "cantidad"    => (float)$it["cantidad"],
                "precio"      => (float)$it["precio_unitario"],
            ];
        }
    }
    ksort($diasAgrupados);
    $diasParaJs = array_values($diasAgrupados);
}

$diasJson = json_encode($diasParaJs, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require __DIR__ . "/../includes/rz_app.php"; ?>
    <title>Editar <?php echo htmlspecialchars($nota["folio"]); ?> | Zabisu</title>
    <link rel="icon" type="image/png" href="../assets/img/LOGO_NARA.png">
    <meta name="theme-color" content="#151413">
    <link rel="preload" href="../assets/fonts/kaushan-script-400.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="../assets/fonts/alfa-slab-one-400.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="../assets/css/menu-pizarron.css?v=5">
    <link rel="stylesheet" href="../assets/css/notas-pizarron.css?v=1">
</head>
<body class="pz">
<?php require __DIR__ . "/../includes/pz_filtros.php"; ?>

<main class="nc">
    <header class="nc-cab">
        <div class="pz-marca">
            <img src="../assets/img/LOGO_N_128.png" alt="">
            <div>
                <span class="pz-marca__nombre">Zabisu</span>
                <span class="pz-marca__lema">Sabor y Servicio</span>
            </div>
        </div>
        <h1 class="pz-titulo"><span class="pz-pincel"><span>Editar nota</span></span></h1>
        <nav class="nc-cab__acc" aria-label="Acciones">
            <a href="ver_nota_cuenta.php?id=<?php echo $id; ?>" class="nc-btn">← Ver nota</a>
        </nav>
    </header>
    <p class="nc-sub"><?php echo htmlspecialchars($nota["folio"]); ?> · Corrige los datos del cliente o cualquier día/ítem ya agregado</p>

    <?php if (!empty($errores)): ?>
    <div class="mensaje-error">
        <ul><?php foreach ($errores as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?></ul>
    </div>
    <?php endif; ?>

    <form method="POST" action="editar_nota_cuenta.php?id=<?php echo $id; ?>" id="form-nota">

        <!-- DATOS DEL CLIENTE -->
        <section class="nc-sec">
            <h2 class="pz-cinta pz-fibra">Datos del cliente</h2>
            <p class="nota-formulario">Solo el nombre es obligatorio.</p>
            <div class="nc-tarjeta nc-grid">
                <div class="nm-campo">
                    <label>Nombre del cliente <span>*</span></label>
                    <input type="text" name="nombre_cliente" value="<?php echo htmlspecialchars($nota["nombre_cliente"]); ?>" placeholder="Ej. Comedor Industrial López" required>
                </div>
                <div class="nm-campo">
                    <label>Teléfono</label>
                    <input type="text" name="telefono" value="<?php echo htmlspecialchars($nota["telefono"] ?? ""); ?>" placeholder="Opcional">
                </div>
            </div>
        </section>

        <!-- DÍAS -->
        <section class="nc-sec">
            <h2 class="pz-cinta pz-fibra">Días y menús</h2>
            <p class="nota-formulario">Corrige cantidades/precios, quita un día completo con "Quitar día", o agrega uno nuevo. Al guardar se reemplaza todo el desglose.</p>

            <div id="dias-body"></div>

            <button type="button" id="btn-add-dia" class="btn-limpiar-filtros">+ Agregar día</button>

        </section>

        <!-- NOTAS -->
        <section class="nc-sec">
            <h2 class="pz-cinta pz-fibra">Notas</h2>
            <div class="nm-campo">
                <textarea name="notas" rows="3" placeholder="Opcional"><?php echo htmlspecialchars($nota["notas"] ?? ""); ?></textarea>
            </div>
        </section>

        <div class="nc-pie">
            <div class="cot-total-row">
                <span class="cot-total-label">Total de la nota</span>
                <span class="cot-total-valor" id="total-display">$0.00</span>
            </div>
            <div class="nm-acciones">
                <button type="submit" class="btn-principal">Guardar cambios</button>
                <a href="ver_nota_cuenta.php?id=<?php echo $id; ?>" class="btn-link">Cancelar</a>
            </div>
        </div>

    </form>
</main>

<script>
var PRECIO_ZABISU    = <?php echo json_encode($precioZabisu); ?>;
var PRECIO_EJECUTIVO = <?php echo json_encode($precioEjecutivo); ?>;
var HOY               = <?php echo json_encode(date("Y-m-d")); ?>;
var DIAS_INICIALES    = <?php echo $diasJson; ?>;

var diaIndex = 0;

function fmtPeso(n) {
    return "$" + n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ",");
}

function recalcDia(bloque) {
    var zCant = parseFloat(bloque.querySelector(".zabisu-cant").value) || 0;
    var zPrecio = parseFloat(bloque.querySelector(".zabisu-precio").value) || 0;
    var eCant = parseFloat(bloque.querySelector(".ejecutivo-cant").value) || 0;
    var ePrecio = parseFloat(bloque.querySelector(".ejecutivo-precio").value) || 0;

    var sub = zCant * zPrecio + eCant * ePrecio;

    bloque.querySelectorAll(".extra-fila").forEach(function (fila) {
        var cant = parseFloat(fila.querySelector(".extra-cant").value) || 0;
        var precio = parseFloat(fila.querySelector(".extra-precio").value) || 0;
        var extraSub = cant * precio;
        fila.querySelector(".extra-subtotal").textContent = fmtPeso(extraSub);
        sub += extraSub;
    });

    bloque.querySelector(".dia-subtotal-valor").textContent = fmtPeso(sub);
    recalcTotal();
}

function recalcTotal() {
    var total = 0;
    document.querySelectorAll(".dia-bloque").forEach(function (bloque) {
        var texto = bloque.querySelector(".dia-subtotal-valor").textContent.replace(/[$,]/g, "");
        total += parseFloat(texto) || 0;
    });
    document.getElementById("total-display").textContent = fmtPeso(total);
}

function addExtraFila(bloque, dIdx, extraInicial) {
    var cont = bloque.querySelector(".dia-extras-body");
    var vacio = bloque.querySelector(".dia-extras__vacio");
    if (vacio) vacio.style.display = "none";

    var eIdx = bloque.dataset.extraIndex ? parseInt(bloque.dataset.extraIndex) : 0;
    bloque.dataset.extraIndex = eIdx + 1;

    var desc   = extraInicial ? (extraInicial.descripcion || "") : "";
    var cant   = extraInicial ? (extraInicial.cantidad || "")    : "";
    var precio = extraInicial ? (extraInicial.precio || "")      : "";

    var base = "dias[" + dIdx + "][extras][" + eIdx + "]";
    var fila = document.createElement("div");
    fila.className = "dia-fila extra-fila";
    fila.innerHTML =
        '<input type="text" name="' + base + '[descripcion]" placeholder="Ej. Huevo extra" value="' + String(desc).replace(/"/g, "&quot;") + '">' +
        '<input type="number" class="extra-cant" name="' + base + '[cantidad]" min="0" step="1" placeholder="Cant." value="' + cant + '">' +
        '<input type="number" class="extra-precio" name="' + base + '[precio]" min="0" step="0.01" placeholder="Precio" value="' + precio + '">' +
        '<span class="extra-subtotal dia-fila__subtotal">$0.00</span>' +
        '<button type="button" class="dia-extra-remove" title="Quitar">×</button>';

    fila.querySelector(".extra-cant").addEventListener("input", function () { recalcDia(bloque); });
    fila.querySelector(".extra-precio").addEventListener("input", function () { recalcDia(bloque); });
    fila.querySelector(".dia-extra-remove").addEventListener("click", function () {
        fila.remove();
        recalcDia(bloque);
    });

    cont.appendChild(fila);
}

function addDiaBloque(dia) {
    dia = dia || {};
    var dIdx = diaIndex++;
    var bloque = document.createElement("div");
    bloque.className = "dia-bloque";
    bloque.dataset.extraIndex = 0;

    var fecha       = dia.fecha || HOY;
    var zCant       = dia.zabisu_cantidad    || 0;
    var zPrecio     = (dia.zabisu_precio     !== undefined && dia.zabisu_precio    !== null) ? dia.zabisu_precio    : PRECIO_ZABISU;
    var eCant       = dia.ejecutivo_cantidad || 0;
    var ePrecio     = (dia.ejecutivo_precio  !== undefined && dia.ejecutivo_precio !== null) ? dia.ejecutivo_precio : PRECIO_EJECUTIVO;

    bloque.innerHTML =
        '<div class="dia-bloque__header">' +
            '<label>Fecha</label>' +
            '<input type="date" name="dias[' + dIdx + '][fecha]" value="' + fecha + '" required>' +
            '<button type="button" class="dia-bloque__remove">Quitar día</button>' +
        '</div>' +

        '<div class="dia-fila">' +
            '<span class="dia-fila__label">Menú Zabisu</span>' +
            '<input type="number" class="zabisu-cant" name="dias[' + dIdx + '][zabisu_cantidad]" min="0" step="1" placeholder="Cant." value="' + zCant + '">' +
            '<input type="number" class="zabisu-precio" name="dias[' + dIdx + '][zabisu_precio]" min="0" step="0.01" value="' + Number(zPrecio).toFixed(2) + '">' +
            '<span></span>' +
            '<span></span>' +
        '</div>' +
        '<div class="dia-fila">' +
            '<span class="dia-fila__label">Menú Ejecutivo</span>' +
            '<input type="number" class="ejecutivo-cant" name="dias[' + dIdx + '][ejecutivo_cantidad]" min="0" step="1" placeholder="Cant." value="' + eCant + '">' +
            '<input type="number" class="ejecutivo-precio" name="dias[' + dIdx + '][ejecutivo_precio]" min="0" step="0.01" value="' + Number(ePrecio).toFixed(2) + '">' +
            '<span></span>' +
            '<span></span>' +
        '</div>' +

        '<div class="dia-extras">' +
            '<p class="dia-extras__vacio">Sin extras</p>' +
            '<div class="dia-extras-body"></div>' +
            '<button type="button" class="btn-limpiar-filtros dia-add-extra">+ Agregar extra</button>' +
        '</div>' +

        '<div class="dia-subtotal-row">' +
            '<span>Subtotal del día</span>' +
            '<span class="dia-subtotal-valor">$0.00</span>' +
        '</div>';

    bloque.querySelector(".dia-bloque__remove").addEventListener("click", function () {
        bloque.remove();
        recalcTotal();
    });
    bloque.querySelector(".zabisu-cant").addEventListener("input", function () { recalcDia(bloque); });
    bloque.querySelector(".zabisu-precio").addEventListener("input", function () { recalcDia(bloque); });
    bloque.querySelector(".ejecutivo-cant").addEventListener("input", function () { recalcDia(bloque); });
    bloque.querySelector(".ejecutivo-precio").addEventListener("input", function () { recalcDia(bloque); });
    bloque.querySelector(".dia-add-extra").addEventListener("click", function () { addExtraFila(bloque, dIdx); });

    document.getElementById("dias-body").appendChild(bloque);

    (dia.extras || []).forEach(function (extra) {
        addExtraFila(bloque, dIdx, extra);
    });

    recalcDia(bloque);
    return bloque;
}

document.getElementById("btn-add-dia").addEventListener("click", function () {
    var fechas = Array.from(document.querySelectorAll('input[name^="dias"][name$="[fecha]"]')).map(function (i) { return i.value; }).filter(Boolean);
    if (!fechas.length) { addDiaBloque({ fecha: HOY }); return; }
    var ultima = fechas[fechas.length - 1];
    var siguiente = new Date(ultima + "T00:00:00");
    siguiente.setDate(siguiente.getDate() + 1);
    addDiaBloque({ fecha: siguiente.toISOString().slice(0, 10) });
});

// Cargar días existentes; si no hay ninguno, empezar con un bloque vacío
if (DIAS_INICIALES.length > 0) {
    DIAS_INICIALES.forEach(function (dia) { addDiaBloque(dia); });
} else {
    addDiaBloque({ fecha: HOY });
}
</script>

</body>
</html>
