<?php
require_once "../config/db.php";
require_once "auth_check.php";

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
    $notas         = trim($_POST["notas"] ?? "");
    $diasRaw       = $_POST["dias"] ?? [];

    if ($nombreCliente === "") {
        $errores[] = "El nombre del cliente es obligatorio.";
    }

    $itemsValidos = [];
    foreach ($diasRaw as $dia) {
        $fecha = trim($dia["fecha"] ?? "");
        if ($fecha === "") continue;

        $zCant   = (float)str_replace(",", ".", $dia["zabisu_cantidad"]    ?? "0");
        $zPrecio = (float)str_replace(",", ".", $dia["zabisu_precio"]      ?? "0");
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

    if (empty($itemsValidos)) {
        $errores[] = "Agrega al menos un día con menús o algún extra.";
    }

    if (empty($errores)) {
        try {
            $conexion->beginTransaction();

            $anio = date("Y");
            $stmtCount = $conexion->prepare("SELECT COUNT(*) FROM notas_cuenta WHERE YEAR(created_at) = :anio");
            $stmtCount->execute([":anio" => $anio]);
            $num   = (int)$stmtCount->fetchColumn() + 1;
            $folio = "NOTA-" . $anio . "-" . str_pad($num, 3, "0", STR_PAD_LEFT);

            $total = array_sum(array_column($itemsValidos, "subtotal"));

            $stmtIns = $conexion->prepare(
                "INSERT INTO notas_cuenta (folio, nombre_cliente, telefono, notas, total)
                 VALUES (:folio, :nombre_cliente, :telefono, :notas, :total)"
            );
            $stmtIns->execute([
                ":folio"          => $folio,
                ":nombre_cliente" => $nombreCliente,
                ":telefono"       => $telefono ?: null,
                ":notas"          => $notas ?: null,
                ":total"          => $total,
            ]);
            $idNueva = (int)$conexion->lastInsertId();

            $stmtItem = $conexion->prepare(
                "INSERT INTO notas_cuenta_items (id_nota, fecha, descripcion, cantidad, precio_unitario, subtotal)
                 VALUES (:id_nota, :fecha, :descripcion, :cantidad, :precio_unitario, :subtotal)"
            );
            foreach ($itemsValidos as $item) {
                $stmtItem->execute([
                    ":id_nota"          => $idNueva,
                    ":fecha"            => $item["fecha"],
                    ":descripcion"      => $item["descripcion"],
                    ":cantidad"         => $item["cantidad"],
                    ":precio_unitario"  => $item["precio_unitario"],
                    ":subtotal"         => $item["subtotal"],
                ]);
            }

            $conexion->commit();
            header("Location: ver_nota_cuenta.php?id=" . $idNueva . "&nueva=1");
            exit;

        } catch (Exception $e) {
            $conexion->rollBack();
            $errores[] = "Error al guardar: " . $e->getMessage();
        }
    }
}

$fechaHoy = date("Y-m-d");
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require __DIR__ . "/../includes/rz_app.php"; ?>
    <title>Nueva nota de cuenta | Zabisu</title>
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
        <h1 class="pz-titulo"><span class="pz-pincel"><span>Nueva nota</span></span></h1>
        <nav class="nc-cab__acc" aria-label="Acciones">
            <a href="notas_cuenta.php" class="nc-btn">← Notas de cuenta</a>
        </nav>
    </header>
    <p class="nc-sub">Para clientes que piden diario y pagan a fin de semana</p>

    <?php if (!empty($errores)): ?>
    <div class="mensaje-error">
        <ul><?php foreach ($errores as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?></ul>
    </div>
    <?php endif; ?>

    <form method="POST" action="nueva_nota_cuenta.php" id="form-nota">

        <!-- DATOS DEL CLIENTE -->
        <section class="nc-sec">
            <h2 class="pz-cinta pz-fibra">Datos del cliente</h2>
            <p class="nota-formulario">Solo el nombre es obligatorio.</p>
            <div class="nc-tarjeta nc-grid">
                <div class="nm-campo">
                    <label>Nombre del cliente <span>*</span></label>
                    <input type="text" name="nombre_cliente" value="<?php echo htmlspecialchars($_POST["nombre_cliente"] ?? ""); ?>" placeholder="Ej. Comedor Industrial López" required>
                </div>
                <div class="nm-campo">
                    <label>Teléfono</label>
                    <input type="text" name="telefono" value="<?php echo htmlspecialchars($_POST["telefono"] ?? ""); ?>" placeholder="Opcional">
                </div>
            </div>
        </section>

        <!-- DÍAS -->
        <section class="nc-sec">
            <h2 class="pz-cinta pz-fibra">Días y menús</h2>
            <p class="nota-formulario">Agrega un bloque por cada día que pidió. Puedes seguir agregando días después desde la nota.</p>

            <div id="dias-body"></div>

            <button type="button" id="btn-add-dia" class="btn-limpiar-filtros">+ Agregar día</button>

        </section>

        <!-- NOTAS -->
        <section class="nc-sec">
            <h2 class="pz-cinta pz-fibra">Notas</h2>
            <div class="nm-campo">
                <textarea name="notas" rows="3" placeholder="Opcional"><?php echo htmlspecialchars($_POST["notas"] ?? ""); ?></textarea>
            </div>
        </section>

        <div class="nc-pie">
            <div class="cot-total-row">
                <span class="cot-total-label">Total de la nota</span>
                <span class="cot-total-valor" id="total-display">$0.00</span>
            </div>
            <div class="nm-acciones">
                <button type="submit" class="btn-principal">Guardar nota</button>
                <a href="notas_cuenta.php" class="btn-link">Cancelar</a>
            </div>
        </div>

    </form>
</main>

<script>
var PRECIO_ZABISU    = <?php echo json_encode($precioZabisu); ?>;
var PRECIO_EJECUTIVO = <?php echo json_encode($precioEjecutivo); ?>;
var HOY               = <?php echo json_encode($fechaHoy); ?>;

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

function addExtraFila(bloque, dIdx) {
    var cont = bloque.querySelector(".dia-extras-body");
    var vacio = bloque.querySelector(".dia-extras__vacio");
    if (vacio) vacio.style.display = "none";

    var eIdx = bloque.dataset.extraIndex ? parseInt(bloque.dataset.extraIndex) : 0;
    bloque.dataset.extraIndex = eIdx + 1;

    var base = "dias[" + dIdx + "][extras][" + eIdx + "]";
    var fila = document.createElement("div");
    fila.className = "dia-fila extra-fila";
    fila.innerHTML =
        '<input type="text" name="' + base + '[descripcion]" placeholder="Ej. Huevo extra">' +
        '<input type="number" class="extra-cant" name="' + base + '[cantidad]" min="0" step="1" placeholder="Cant.">' +
        '<input type="number" class="extra-precio" name="' + base + '[precio]" min="0" step="0.01" placeholder="Precio">' +
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

function addDiaBloque(fechaDefault) {
    var dIdx = diaIndex++;
    var bloque = document.createElement("div");
    bloque.className = "dia-bloque";
    bloque.dataset.extraIndex = 0;

    bloque.innerHTML =
        '<div class="dia-bloque__header">' +
            '<label>Fecha</label>' +
            '<input type="date" name="dias[' + dIdx + '][fecha]" value="' + fechaDefault + '" required>' +
            '<button type="button" class="dia-bloque__remove">Quitar día</button>' +
        '</div>' +

        '<div class="dia-fila">' +
            '<span class="dia-fila__label">Menú Zabisu</span>' +
            '<input type="number" class="zabisu-cant" name="dias[' + dIdx + '][zabisu_cantidad]" min="0" step="1" placeholder="Cant." value="0">' +
            '<input type="number" class="zabisu-precio" name="dias[' + dIdx + '][zabisu_precio]" min="0" step="0.01" value="' + PRECIO_ZABISU.toFixed(2) + '">' +
            '<span></span>' +
            '<span></span>' +
        '</div>' +
        '<div class="dia-fila">' +
            '<span class="dia-fila__label">Menú Ejecutivo</span>' +
            '<input type="number" class="ejecutivo-cant" name="dias[' + dIdx + '][ejecutivo_cantidad]" min="0" step="1" placeholder="Cant." value="0">' +
            '<input type="number" class="ejecutivo-precio" name="dias[' + dIdx + '][ejecutivo_precio]" min="0" step="0.01" value="' + PRECIO_EJECUTIVO.toFixed(2) + '">' +
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
    recalcDia(bloque);
    return bloque;
}

document.getElementById("btn-add-dia").addEventListener("click", function () {
    var fechas = Array.from(document.querySelectorAll('input[name^="dias"][name$="[fecha]"]')).map(function (i) { return i.value; }).filter(Boolean);
    var ultima = fechas.length ? fechas[fechas.length - 1] : HOY;
    var siguiente = new Date(ultima + "T00:00:00");
    if (fechas.length) siguiente.setDate(siguiente.getDate() + 1);
    var iso = siguiente.toISOString().slice(0, 10);
    addDiaBloque(fechas.length ? iso : HOY);
});

// Empieza con el día de hoy ya agregado
addDiaBloque(HOY);
</script>

</body>
</html>
