<?php
require_once "../config/db.php";
require_once "auth_check.php";

$errores = [];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $nombreCliente   = trim($_POST["nombre_cliente"]   ?? "");
    $empresa         = trim($_POST["empresa"]          ?? "");
    $telefono        = trim($_POST["telefono"]         ?? "");
    $correo          = trim($_POST["correo"]           ?? "");
    $fechaEvento     = trim($_POST["fecha_evento"]     ?? "");
    $lugarEvento     = trim($_POST["lugar_evento"]     ?? "");
    $fechaCotizacion = trim($_POST["fecha_cotizacion"] ?? date("Y-m-d"));
    $vigenciaDias    = max(1, (int)($_POST["vigencia_dias"] ?? 15));
    $notas           = trim($_POST["notas"]            ?? "");
    $itemsRaw        = $_POST["items"] ?? [];

    if ($nombreCliente === "") {
        $errores[] = "El nombre del cliente es obligatorio.";
    }

    $itemsValidos = [];
    foreach ($itemsRaw as $item) {
        $desc   = trim($item["descripcion"]    ?? "");
        $qty    = (float)str_replace(",", ".", $item["cantidad"]        ?? "0");
        $precio = (float)str_replace(",", ".", $item["precio_unitario"] ?? "0");
        if ($desc !== "" && $qty > 0 && $precio > 0) {
            $itemsValidos[] = [
                "descripcion"     => $desc,
                "cantidad"        => $qty,
                "precio_unitario" => $precio,
                "subtotal"        => round($qty * $precio, 2),
            ];
        }
    }

    if (empty($itemsValidos)) {
        $errores[] = "Agrega al menos un producto con descripción, cantidad y precio.";
    }

    if (empty($errores)) {
        try {
            $conexion->beginTransaction();

            $anio = date("Y");
            $stmtCount = $conexion->prepare("SELECT COUNT(*) FROM cotizaciones WHERE YEAR(created_at) = :anio");
            $stmtCount->execute([":anio" => $anio]);
            $num   = (int)$stmtCount->fetchColumn() + 1;
            $folio = "COT-" . $anio . "-" . str_pad($num, 3, "0", STR_PAD_LEFT);

            $total = array_sum(array_column($itemsValidos, "subtotal"));

            $stmtIns = $conexion->prepare(
                "INSERT INTO cotizaciones
                 (folio, nombre_cliente, empresa, telefono, correo,
                  fecha_evento, lugar_evento, fecha_cotizacion, vigencia_dias, notas, total)
                 VALUES
                 (:folio, :nombre_cliente, :empresa, :telefono, :correo,
                  :fecha_evento, :lugar_evento, :fecha_cotizacion, :vigencia_dias, :notas, :total)"
            );
            $stmtIns->execute([
                ":folio"            => $folio,
                ":nombre_cliente"   => $nombreCliente,
                ":empresa"          => $empresa      ?: null,
                ":telefono"         => $telefono      ?: null,
                ":correo"           => $correo        ?: null,
                ":fecha_evento"     => $fechaEvento   ?: null,
                ":lugar_evento"     => $lugarEvento   ?: null,
                ":fecha_cotizacion" => $fechaCotizacion,
                ":vigencia_dias"    => $vigenciaDias,
                ":notas"            => $notas         ?: null,
                ":total"            => $total,
            ]);
            $idNueva = (int)$conexion->lastInsertId();

            $stmtItem = $conexion->prepare(
                "INSERT INTO cotizacion_items (id_cotizacion, descripcion, cantidad, precio_unitario, subtotal)
                 VALUES (:id_cotizacion, :descripcion, :cantidad, :precio_unitario, :subtotal)"
            );
            foreach ($itemsValidos as $item) {
                $stmtItem->execute([
                    ":id_cotizacion"    => $idNueva,
                    ":descripcion"      => $item["descripcion"],
                    ":cantidad"         => $item["cantidad"],
                    ":precio_unitario"  => $item["precio_unitario"],
                    ":subtotal"         => $item["subtotal"],
                ]);
            }

            $conexion->commit();
            header("Location: ver_cotizacion.php?id=" . $idNueva . "&nueva=1");
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
    <title>Nueva cotización | Zabisu</title>
    <link rel="icon" type="image/png" href="../assets/img/LOGO_NARA.png">
    <link rel="stylesheet" href="../assets/css/styles.css?v=5">
    <style>
        .cot-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
        .cot-grid--3 { grid-template-columns:1fr 1fr 1fr; }
        @media(max-width:600px) { .cot-grid, .cot-grid--3 { grid-template-columns:1fr; } }

        .cot-items-tabla { width:100%; border-collapse:collapse; margin-top:12px; }
        .cot-items-tabla th { font-size:12px; font-weight:600; color:var(--texto-secundario); text-align:left; padding:6px 8px; border-bottom:1px solid var(--borde); text-transform:uppercase; letter-spacing:.04em; }
        .cot-items-tabla td { padding:6px 8px; vertical-align:middle; }
        .cot-items-tabla input { width:100%; box-sizing:border-box; background:var(--fondo-input,#111); border:1px solid var(--borde,#333); border-radius:6px; color:var(--texto-principal,#eee); padding:8px 10px; font-size:14px; }
        .cot-items-tabla input:focus { outline:none; border-color:var(--zabisu-orange); }
        .cot-item-subtotal { font-size:14px; font-weight:700; color:var(--zabisu-orange); white-space:nowrap; min-width:90px; }
        .cot-item-remove { background:none; border:none; color:#e57373; cursor:pointer; font-size:18px; padding:4px 8px; line-height:1; }
        .cot-item-remove:hover { color:#ff5252; }
        .cot-total-row { display:flex; justify-content:flex-end; align-items:center; gap:16px; margin-top:16px; padding-top:16px; border-top:1px solid var(--borde); }
        .cot-total-label { font-size:14px; color:var(--texto-secundario); }
        .cot-total-valor { font-size:22px; font-weight:800; color:var(--zabisu-orange); }
        .cot-add-row { margin-top:12px; }
    </style>
</head>
<body>
<div class="contenedor">

    <div class="hero-zabisu">
        <div class="hero-zabisu__glow"></div>
        <div class="hero-zabisu__contenido">
            <p class="hero-zabisu__eyebrow">BBQ — NUEVA COTIZACIÓN</p>
            <h1 class="hero-zabisu__titulo">Nueva cotización</h1>
            <p class="hero-zabisu__texto">Captura los datos del cliente y los productos del evento</p>
            <a href="cotizaciones.php" class="btn-volver-panel">← Cotizaciones</a>
        </div>
    </div>

    <?php if (!empty($errores)): ?>
    <div class="mensaje-error">
        <ul><?php foreach ($errores as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?></ul>
    </div>
    <?php endif; ?>

    <form method="POST" action="nueva_cotizacion.php" id="form-cotizacion">

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
                    <input type="text" name="nombre_cliente" value="<?php echo htmlspecialchars($_POST["nombre_cliente"] ?? ""); ?>" placeholder="Nombre completo" required>
                </div>
                <div class="nm-campo">
                    <label>Empresa / Organización</label>
                    <input type="text" name="empresa" value="<?php echo htmlspecialchars($_POST["empresa"] ?? ""); ?>" placeholder="Opcional">
                </div>
                <div class="nm-campo">
                    <label>Teléfono</label>
                    <input type="text" name="telefono" value="<?php echo htmlspecialchars($_POST["telefono"] ?? ""); ?>" placeholder="Opcional">
                </div>
                <div class="nm-campo">
                    <label>Correo electrónico</label>
                    <input type="email" name="correo" value="<?php echo htmlspecialchars($_POST["correo"] ?? ""); ?>" placeholder="Opcional">
                </div>
            </div>
        </div>

        <!-- DATOS DEL EVENTO -->
        <div class="bloque-formulario nm-seccion">
            <div class="nm-seccion__header">
                <span class="nm-seccion__icono">📅</span>
                <div>
                    <h2>Detalles del evento</h2>
                    <p class="nota-formulario" style="margin:0;">¿Cuándo y dónde es el evento?</p>
                </div>
            </div>
            <div class="cot-grid" style="margin-top:20px;">
                <div class="nm-campo">
                    <label>Fecha del evento</label>
                    <input type="date" name="fecha_evento" value="<?php echo htmlspecialchars($_POST["fecha_evento"] ?? ""); ?>">
                </div>
                <div class="nm-campo">
                    <label>Lugar del evento</label>
                    <input type="text" name="lugar_evento" value="<?php echo htmlspecialchars($_POST["lugar_evento"] ?? ""); ?>" placeholder="Dirección o nombre del lugar">
                </div>
            </div>
        </div>

        <!-- PRODUCTOS -->
        <div class="bloque-formulario nm-seccion">
            <div class="nm-seccion__header">
                <span class="nm-seccion__icono">🍖</span>
                <div>
                    <h2>Productos y servicios</h2>
                    <p class="nota-formulario" style="margin:0;">Agrega cada producto con su cantidad y precio unitario.</p>
                </div>
            </div>

            <div style="overflow-x:auto;">
                <table class="cot-items-tabla">
                    <thead>
                        <tr>
                            <th style="width:45%;">Descripción</th>
                            <th style="width:12%;">Cantidad</th>
                            <th style="width:18%;">Precio unitario</th>
                            <th style="width:15%;">Subtotal</th>
                            <th style="width:10%;"></th>
                        </tr>
                    </thead>
                    <tbody id="items-body">
                        <!-- Rows inserted by JS -->
                    </tbody>
                </table>
            </div>

            <div class="cot-add-row">
                <button type="button" id="btn-add-item" class="btn-limpiar-filtros">+ Agregar producto</button>
            </div>

            <div class="cot-total-row">
                <span class="cot-total-label">TOTAL</span>
                <span class="cot-total-valor" id="total-display">$0.00</span>
            </div>
        </div>

        <!-- NOTAS Y CONFIGURACIÓN -->
        <div class="bloque-formulario nm-seccion">
            <div class="nm-seccion__header">
                <span class="nm-seccion__icono">📝</span>
                <div>
                    <h2>Notas y condiciones</h2>
                    <p class="nota-formulario" style="margin:0;">Condiciones de pago, notas adicionales, etc.</p>
                </div>
            </div>
            <div class="cot-grid cot-grid--3" style="margin-top:20px;">
                <div class="nm-campo">
                    <label>Fecha de cotización</label>
                    <input type="date" name="fecha_cotizacion" value="<?php echo htmlspecialchars($_POST["fecha_cotizacion"] ?? $fechaHoy); ?>">
                </div>
                <div class="nm-campo">
                    <label>Vigencia (días)</label>
                    <input type="number" name="vigencia_dias" min="1" value="<?php echo htmlspecialchars($_POST["vigencia_dias"] ?? "15"); ?>">
                    <span class="nm-campo__ayuda">La cotización expira N días después de la fecha de cotización.</span>
                </div>
            </div>
            <div class="nm-campo" style="margin-top:16px;">
                <label>Notas / condiciones de pago</label>
                <textarea name="notas" rows="4" placeholder="Ej: 50% de anticipo para confirmar el evento. Precio no incluye traslado..."><?php echo htmlspecialchars($_POST["notas"] ?? ""); ?></textarea>
            </div>
        </div>

        <!-- GUARDAR -->
        <div class="bloque-formulario nm-seccion">
            <div class="nm-acciones">
                <button type="submit" class="btn-principal">Guardar cotización</button>
                <a href="cotizaciones.php" class="btn-link">Cancelar</a>
            </div>
        </div>

    </form>
</div>

<script>
var itemIndex = 0;

function fmtPeso(n) {
    return "$" + n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ",");
}

function recalcTotal() {
    var filas = document.querySelectorAll("#items-body tr");
    var total = 0;
    filas.forEach(function (fila) {
        var qty    = parseFloat(fila.querySelector(".cot-qty").value)   || 0;
        var precio = parseFloat(fila.querySelector(".cot-precio").value) || 0;
        var sub    = qty * precio;
        fila.querySelector(".cot-subtotal-span").textContent = fmtPeso(sub);
        fila.querySelector(".cot-subtotal-hidden").value = sub.toFixed(2);
        total += sub;
    });
    document.getElementById("total-display").textContent = fmtPeso(total);
}

function addRow(desc, qty, precio) {
    var idx  = itemIndex++;
    var tr   = document.createElement("tr");
    var base = "items[" + idx + "]";
    tr.innerHTML =
        '<td><input type="text" name="' + base + '[descripcion]" placeholder="Ej: Alitas BBQ x 20 piezas" value="' + (desc || "") + '"></td>' +
        '<td><input type="number" class="cot-qty" name="' + base + '[cantidad]" min="0.01" step="0.01" placeholder="1" value="' + (qty || "") + '"></td>' +
        '<td><input type="number" class="cot-precio" name="' + base + '[precio_unitario]" min="0.01" step="0.01" placeholder="0.00" value="' + (precio || "") + '"></td>' +
        '<td class="cot-item-subtotal"><span class="cot-subtotal-span">$0.00</span><input type="hidden" class="cot-subtotal-hidden" name="' + base + '[subtotal]"></td>' +
        '<td><button type="button" class="cot-item-remove" title="Eliminar">×</button></td>';

    tr.querySelector(".cot-item-remove").addEventListener("click", function () {
        tr.remove();
        recalcTotal();
    });
    tr.querySelector(".cot-qty").addEventListener("input", recalcTotal);
    tr.querySelector(".cot-precio").addEventListener("input", recalcTotal);

    document.getElementById("items-body").appendChild(tr);
    recalcTotal();
}

document.getElementById("btn-add-item").addEventListener("click", function () {
    addRow("", "", "");
    var rows = document.querySelectorAll("#items-body tr");
    rows[rows.length - 1].querySelector("input").focus();
});

// Start with 3 empty rows
addRow(); addRow(); addRow();
</script>

</body>
</html>
