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
    <title>Nueva nota de cuenta | Zabisu</title>
    <link rel="icon" type="image/png" href="../assets/img/LOGO_NARA.png">
    <link rel="stylesheet" href="../assets/css/styles.css?v=5">
    <style>
        .cot-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
        @media(max-width:600px) { .cot-grid { grid-template-columns:1fr; } }

        .dia-bloque {
            border: 1px solid var(--borde,#292929);
            border-radius: 14px;
            padding: 16px 18px;
            margin-bottom: 16px;
            background: rgba(255,255,255,.02);
        }
        .dia-bloque__header {
            display: flex; align-items: center; gap: 12px; margin-bottom: 14px;
            flex-wrap: wrap;
        }
        .dia-bloque__header label { font-size:12px; color:var(--texto-secundario); font-weight:600; }
        .dia-bloque__header input[type="date"] {
            background: var(--fondo-input,#111); border:1px solid var(--borde,#333); border-radius:8px;
            color: var(--texto-principal,#eee); padding:8px 10px; font-size:14px;
        }
        .dia-bloque__remove {
            margin-left: auto; background:none; border:none; color:#e57373; cursor:pointer;
            font-size:13px; font-weight:600; padding:4px 8px;
        }
        .dia-bloque__remove:hover { color:#ff5252; }

        .dia-fila {
            display: grid; grid-template-columns: 1.6fr .8fr .8fr .8fr auto;
            gap: 8px; align-items: center; margin-bottom: 8px;
        }
        @media(max-width:640px) { .dia-fila { grid-template-columns: 1fr 1fr; } }
        .dia-fila__label { font-size: 13px; font-weight: 700; color: var(--texto-principal,#eee); }
        .dia-fila input {
            width: 100%; box-sizing: border-box; background: var(--fondo-input,#111);
            border: 1px solid var(--borde,#333); border-radius: 6px; color: var(--texto-principal,#eee);
            padding: 7px 9px; font-size: 13px;
        }
        .dia-fila input:focus { outline: none; border-color: var(--zabisu-orange); }
        .dia-fila__subtotal { font-size: 13px; font-weight: 700; color: var(--zabisu-orange); text-align:right; white-space:nowrap; }

        .dia-extras { margin-top: 6px; padding-top: 10px; border-top: 1px dashed var(--borde,#333); }
        .dia-extras__vacio { font-size:12px; color:var(--texto-secundario); font-style:italic; margin-bottom:8px; }
        .dia-extra-remove { background:none; border:none; color:#e57373; cursor:pointer; font-size:16px; line-height:1; padding:2px 6px; }

        .dia-add-extra { font-size: 12px; padding: 6px 12px; margin-top: 4px; }

        .dia-subtotal-row {
            display: flex; justify-content: flex-end; gap: 10px; align-items: baseline;
            margin-top: 10px; padding-top: 10px; border-top: 1px solid var(--borde,#292929);
        }
        .dia-subtotal-row span:first-child { font-size:12px; color:var(--texto-secundario); text-transform:uppercase; letter-spacing:.04em; }
        .dia-subtotal-row span:last-child { font-size:16px; font-weight:800; color:var(--zabisu-orange); }

        .cot-total-row { display:flex; justify-content:flex-end; align-items:center; gap:16px; margin-top:16px; padding-top:16px; border-top:1px solid var(--borde); }
        .cot-total-label { font-size:14px; color:var(--texto-secundario); }
        .cot-total-valor { font-size:22px; font-weight:800; color:var(--zabisu-orange); }
    </style>
</head>
<body>
<div class="contenedor">

    <div class="hero-zabisu">
        <div class="hero-zabisu__glow"></div>
        <div class="hero-zabisu__contenido">
            <p class="hero-zabisu__eyebrow">CLIENTES DE CRÉDITO</p>
            <h1 class="hero-zabisu__titulo">Nueva nota de cuenta</h1>
            <p class="hero-zabisu__texto">Para clientes que piden diario y pagan a fin de semana</p>
            <a href="notas_cuenta.php" class="btn-volver-panel">← Notas de cuenta</a>
        </div>
    </div>

    <?php if (!empty($errores)): ?>
    <div class="mensaje-error">
        <ul><?php foreach ($errores as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?></ul>
    </div>
    <?php endif; ?>

    <form method="POST" action="nueva_nota_cuenta.php" id="form-nota">

        <!-- DATOS DEL CLIENTE -->
        <div class="bloque-formulario nm-seccion">
            <div class="nm-seccion__header">
                <span class="nm-seccion__icono">👤</span>
                <div>
                    <h2>Datos del cliente</h2>
                    <p class="nota-formulario" style="margin:0;">Solo el nombre es obligatorio.</p>
                </div>
            </div>
            <div class="cot-grid" style="margin-top:20px;">
                <div class="nm-campo">
                    <label>Nombre del cliente <span style="color:#e57373;">*</span></label>
                    <input type="text" name="nombre_cliente" value="<?php echo htmlspecialchars($_POST["nombre_cliente"] ?? ""); ?>" placeholder="Ej. Comedor Industrial López" required>
                </div>
                <div class="nm-campo">
                    <label>Teléfono</label>
                    <input type="text" name="telefono" value="<?php echo htmlspecialchars($_POST["telefono"] ?? ""); ?>" placeholder="Opcional">
                </div>
            </div>
        </div>

        <!-- DÍAS -->
        <div class="bloque-formulario nm-seccion">
            <div class="nm-seccion__header">
                <span class="nm-seccion__icono">📅</span>
                <div>
                    <h2>Días y menús</h2>
                    <p class="nota-formulario" style="margin:0;">Agrega un bloque por cada día que pidió. Puedes seguir agregando días después desde la nota.</p>
                </div>
            </div>

            <div id="dias-body" style="margin-top:16px;"></div>

            <button type="button" id="btn-add-dia" class="btn-limpiar-filtros">+ Agregar día</button>

            <div class="cot-total-row">
                <span class="cot-total-label">TOTAL DE LA NOTA</span>
                <span class="cot-total-valor" id="total-display">$0.00</span>
            </div>
        </div>

        <!-- NOTAS -->
        <div class="bloque-formulario nm-seccion">
            <div class="nm-seccion__header">
                <span class="nm-seccion__icono">📝</span>
                <div><h2>Notas</h2></div>
            </div>
            <div class="nm-campo" style="margin-top:16px;">
                <textarea name="notas" rows="3" placeholder="Opcional"><?php echo htmlspecialchars($_POST["notas"] ?? ""); ?></textarea>
            </div>
        </div>

        <div class="bloque-formulario nm-seccion">
            <div class="nm-acciones">
                <button type="submit" class="btn-principal">Guardar nota</button>
                <a href="notas_cuenta.php" class="btn-link">Cancelar</a>
            </div>
        </div>

    </form>
</div>

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
