<?php
require_once "../config/db.php";
require_once "auth_check.php";

$id_pedido = isset($_GET["id"]) ? (int)$_GET["id"] : 0;

if ($id_pedido <= 0) {
    die("No se recibió un pedido válido.");
}

/* ── Eliminar pedido ── */
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["accion"] ?? "") === "eliminar_pedido") {
    $idEliminar = (int)($_POST["id_pedido"] ?? 0);
    if ($idEliminar > 0) {
        try {
            $conexion->beginTransaction();
            // Borrar detalle_pedido de cada pedido_menu
            $stmtPM = $conexion->prepare("SELECT id_pedido_menu FROM pedido_menus WHERE id_pedido = :id");
            $stmtPM->execute([":id" => $idEliminar]);
            foreach ($stmtPM->fetchAll(PDO::FETCH_COLUMN) as $pmId) {
                $conexion->prepare("DELETE FROM detalle_pedido WHERE id_pedido_menu = :id")->execute([":id" => $pmId]);
            }
            $conexion->prepare("DELETE FROM pedido_menus   WHERE id_pedido = :id")->execute([":id" => $idEliminar]);
            $conexion->prepare("DELETE FROM pedido_extras  WHERE id_pedido = :id")->execute([":id" => $idEliminar]);
            $conexion->prepare("DELETE FROM feedback_pedidos WHERE id_pedido = :id")->execute([":id" => $idEliminar]);
            $conexion->prepare("DELETE FROM pedidos        WHERE id_pedido = :id")->execute([":id" => $idEliminar]);
            $conexion->commit();
            header("Location: pedidos.php?eliminado=1");
            exit;
        } catch (Exception $e) {
            $conexion->rollBack();
        }
    }
}

/*
    1. Obtener pedido principal
*/
$sqlPedido = "SELECT
                p.*,
                h.hora_entrega,
                u.nombre_ubicacion,
                u.tipo AS tipo_ubicacion
              FROM pedidos p
              INNER JOIN horarios_ubicacion h ON p.id_horario = h.id_horario
              INNER JOIN ubicaciones u ON h.id_ubicacion = u.id_ubicacion
              WHERE p.id_pedido = :id_pedido
              LIMIT 1";
$stmtPedido = $conexion->prepare($sqlPedido);
$stmtPedido->bindParam(":id_pedido", $id_pedido, PDO::PARAM_INT);
$stmtPedido->execute();
$pedido = $stmtPedido->fetch(PDO::FETCH_ASSOC);

if (!$pedido) {
    die("No se encontró el pedido.");
}

/*
    2. Obtener menús del pedido
*/
$sqlMenus = "SELECT *
             FROM pedido_menus
             WHERE id_pedido = :id_pedido
             ORDER BY numero_menu ASC";
$stmtMenus = $conexion->prepare($sqlMenus);
$stmtMenus->bindParam(":id_pedido", $id_pedido, PDO::PARAM_INT);
$stmtMenus->execute();
$menusPedido = $stmtMenus->fetchAll(PDO::FETCH_ASSOC);

/*
    3. Obtener detalle por menú
*/
$detallePorMenu = [];

$sqlDetalle = "SELECT *
               FROM detalle_pedido
               WHERE id_pedido_menu = :id_pedido_menu
               ORDER BY id_detalle ASC";
$stmtDetalle = $conexion->prepare($sqlDetalle);

foreach ($menusPedido as $menu) {
    $stmtDetalle->bindParam(":id_pedido_menu", $menu["id_pedido_menu"], PDO::PARAM_INT);
    $stmtDetalle->execute();
    $detallePorMenu[$menu["id_pedido_menu"]] = $stmtDetalle->fetchAll(PDO::FETCH_ASSOC);
}

/*
    4. Obtener extras del pedido
*/
$sqlExtras = "SELECT * FROM pedido_extras WHERE id_pedido = :id_pedido ORDER BY id_extra ASC";

$stmtExtras = $conexion->prepare($sqlExtras);
$stmtExtras->bindParam(":id_pedido", $id_pedido, PDO::PARAM_INT);
$stmtExtras->execute();
$extrasVer = $stmtExtras->fetchAll(PDO::FETCH_ASSOC);

/*
    5. Extras disponibles para agregar (del menú más reciente)
*/
$PRECIOS_EXTRA_ED  = ["Sopa" => 25, "Complemento" => 25, "Agua" => 20];
$iconosExtra       = ["Sopa" => "🥣", "Complemento" => "🥗", "Agua" => "💧"];
$extrasDisponibles = [];

$menuRecienteId = $conexion->query("SELECT id_menu FROM menu_dia ORDER BY fecha DESC LIMIT 1")->fetchColumn();
if ($menuRecienteId) {
    $stmtExtrasDisp = $conexion->prepare(
        "SELECT id_producto, nombre, categoria FROM productos
         WHERE id_menu = :id_menu AND categoria IN ('Sopa','Complemento','Agua') AND disponible = 1
         ORDER BY FIELD(categoria,'Sopa','Complemento','Agua'), nombre"
    );
    $stmtExtrasDisp->execute([":id_menu" => $menuRecienteId]);
    $nombresUsados = [];
    foreach ($stmtExtrasDisp->fetchAll(PDO::FETCH_ASSOC) as $p) {
        if (!in_array($p["nombre"], $nombresUsados)) {
            $nombresUsados[]                          = $p["nombre"];
            $extrasDisponibles[$p["categoria"]][]     = $p;
        }
    }
}

/*
    6. Horarios disponibles para cambiar
*/
$stmtHorarios = $conexion->prepare(
    "SELECT h.id_horario, h.hora_entrega
     FROM horarios_ubicacion h
     WHERE h.id_ubicacion = (
         SELECT id_ubicacion FROM horarios_ubicacion WHERE id_horario = :id_horario LIMIT 1
     ) AND h.activo = 1
     ORDER BY h.hora_entrega"
);
$stmtHorarios->bindParam(":id_horario", $pedido["id_horario"], PDO::PARAM_INT);
$stmtHorarios->execute();
$horariosDisponibles = $stmtHorarios->fetchAll(PDO::FETCH_ASSOC);

/* Flash message */
$editadoOk = isset($_GET["editado"]);

function obtenerTextoEstadoPago($estadoPago)
{
    switch ($estadoPago) {
        case "Pendiente de validación":
            return "Pago en revisión";
        case "Pago en efectivo":
            return "Pago al recibir";
        case "Pagado":
            return "Pago confirmado";
        default:
            return $estadoPago ?: "Pendiente";
    }
}

function obtenerClaseEstadoPago($estadoPago)
{
    switch ($estadoPago) {
        case "Pendiente de validación":
            return "estado estado-revision";
        case "Pago en efectivo":
            return "estado estado-efectivo";
        case "Pagado":
            return "estado estado-pagado";
        default:
            return "estado estado-pendiente";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detalle del pedido | Zabisu</title>
    <link rel="icon" type="image/png" href="../assets/img/LOGO_NARA.png">
    <link rel="stylesheet" href="../assets/css/styles.css?v=<?php echo CSS_VERSION; ?>">
</head>
<body>

<div class="contenedor">
    <div class="hero-zabisu">
        <div class="hero-zabisu__glow"></div>
        <div class="hero-zabisu__contenido">
            <p class="hero-zabisu__eyebrow">RESTAURANTE</p>
            <h1 class="hero-zabisu__titulo">Detalle del pedido</h1>
            <p class="hero-zabisu__texto">
                Folio: <?php echo htmlspecialchars($pedido["folio"]); ?>
            </p>
        </div>
    </div>

    <div class="bloque-formulario resumen-total">
        <h2>Información general</h2>

        <div class="ticket-resumen">
            <div class="ticket-menu">
                <div class="ticket-linea">
                    <span>Cliente</span>
                    <span><?php echo htmlspecialchars($pedido["nombre_cliente"]); ?></span>
                </div>

                <div class="ticket-linea">
                    <span>Teléfono</span>
                    <span><?php echo htmlspecialchars($pedido["telefono"]); ?></span>
                </div>

                <?php if (!empty($pedido["correo_cliente"])): ?>
                    <div class="ticket-linea">
                        <span>Correo</span>
                        <span><?php echo htmlspecialchars($pedido["correo_cliente"]); ?></span>
                    </div>
                <?php endif; ?>

                <div class="ticket-linea">
                    <span>Ubicación</span>
                    <span><?php echo htmlspecialchars($pedido["nombre_ubicacion"]); ?> (<?php echo htmlspecialchars($pedido["tipo_ubicacion"]); ?>)</span>
                </div>

                <div class="ticket-linea">
                    <span>Hora</span>
                    <span><?php echo date("g:i A", strtotime($pedido["hora_entrega"])); ?></span>
                </div>

                <div class="ticket-linea">
                    <span>Método de pago</span>
                    <span><?php echo htmlspecialchars($pedido["metodo_pago"]); ?></span>
                </div>

                <div class="ticket-linea">
                    <span>Estado del pago</span>
                    <span class="<?php echo obtenerClaseEstadoPago($pedido["estado_pago"]); ?>">
                        <?php echo htmlspecialchars(obtenerTextoEstadoPago($pedido["estado_pago"])); ?>
                    </span>
                </div>

                <?php if (isset($pedido["correo_enviado"])): ?>
                    <div class="ticket-linea">
                        <span>Correo enviado</span>
                        <span><?php echo ((int)$pedido["correo_enviado"] === 1) ? "Sí" : "No"; ?></span>
                    </div>
                <?php endif; ?>

                <div class="ticket-linea">
                    <span>Observaciones</span>
                    <span>
                        <?php echo $pedido["observaciones"] !== "" ? htmlspecialchars($pedido["observaciones"]) : "Sin observaciones"; ?>
                    </span>
                </div>

                <div class="ticket-subtotal">
                    <span>Total</span>
                    <strong>$<?php echo number_format((float)$pedido["total"], 2); ?></strong>
                </div>
            </div>
        </div>
    </div>

    <?php foreach ($menusPedido as $menu): ?>
        <div class="bloque-formulario">
            <h2>Menú <?php echo (int)$menu["numero_menu"]; ?> — <?php echo htmlspecialchars($menu["tipo_menu"]); ?></h2>

            <div class="ticket-resumen">
                <div class="ticket-menu">
                    <?php if (!empty($detallePorMenu[$menu["id_pedido_menu"]])): ?>
                        <?php foreach ($detallePorMenu[$menu["id_pedido_menu"]] as $detalle): ?>
                            <div class="ticket-linea">
                                <span><?php echo htmlspecialchars($detalle["categoria"]); ?></span>
                                <span><?php echo htmlspecialchars($detalle["nombre_producto"]); ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="ticket-vacio">No hay productos registrados en este menú.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if (!empty($extrasVer)): ?>
        <div class="bloque-formulario">
            <h2>Extras</h2>
            <div class="ticket-resumen">
                <div class="ticket-menu">
                    <?php foreach ($extrasVer as $extra): ?>
                        <div class="ticket-linea">
                            <span><?php echo htmlspecialchars($extra["categoria"]); ?> — <?php echo htmlspecialchars($extra["nombre"]); ?> ×<?php echo (int)$extra["cantidad"]; ?></span>
                            <span>$<?php echo number_format($extra["cantidad"] * $extra["precio_unitario"], 2); ?></span>
                        </div>
                    <?php endforeach; ?>
                    <div class="ticket-subtotal">
                        <span>Subtotal extras</span>
                        <strong>$<?php echo number_format(array_sum(array_map(fn($e) => $e["cantidad"] * $e["precio_unitario"], $extrasVer)), 2); ?></strong>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($pedido["comprobante_pago"])): ?>
        <?php
        $rutaComprobanteFisica = __DIR__ . "/../uploads/comprobantes/" . $pedido["comprobante_pago"];
        $rutaComprobanteWeb = "../uploads/comprobantes/" . rawurlencode($pedido["comprobante_pago"]);
        ?>
        <div class="bloque-formulario">
            <h2>Comprobante de pago</h2>

            <p class="nota-formulario">
                Archivo cargado por el cliente:
            </p>

            <?php if (file_exists($rutaComprobanteFisica)): ?>
                <a class="btn-link" target="_blank" href="<?php echo htmlspecialchars($rutaComprobanteWeb); ?>">
                    Ver comprobante
                </a>
            <?php else: ?>
                <p class="nota-formulario" style="color:#ffb3b3;">
                    El comprobante no se encontró en la carpeta de archivos.
                </p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($editadoOk): ?>
    <div class="bloque-formulario" style="border-left:4px solid #2ecc71;">
        <p style="margin:0;color:#2ecc71;font-weight:700;">✓ Pedido actualizado correctamente.</p>
    </div>
    <?php endif; ?>

    <!-- ── Editar pedido ─────────────────────────────────── -->
    <div class="bloque-formulario">
        <h2>Cambiar horario de entrega</h2>

        <form method="POST" action="guardar_edicion_pedido.php">
            <input type="hidden" name="id_pedido" value="<?php echo $id_pedido; ?>">
            <input type="hidden" name="accion"    value="horario">

            <label for="id_horario_sel">Horario de entrega</label>
            <select name="id_horario" id="id_horario_sel">
                <?php foreach ($horariosDisponibles as $h): ?>
                <option value="<?php echo (int)$h["id_horario"]; ?>"
                    <?php echo (int)$h["id_horario"] === (int)$pedido["id_horario"] ? "selected" : ""; ?>>
                    <?php echo date("g:i A", strtotime($h["hora_entrega"])); ?>
                </option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="btn-tabla" style="margin-top:16px;">Guardar cambio</button>
        </form>
    </div>

    <div class="bloque-formulario">
        <h2>Cambiar método de pago</h2>

        <form method="POST" action="guardar_edicion_pedido.php">
            <input type="hidden" name="id_pedido" value="<?php echo $id_pedido; ?>">
            <input type="hidden" name="accion"    value="metodo_pago">

            <label for="metodo_pago_sel">Método de pago</label>
            <select name="metodo_pago" id="metodo_pago_sel">
                <option value="Efectivo"      <?php echo $pedido["metodo_pago"] === "Efectivo"      ? "selected" : ""; ?>>Efectivo</option>
                <option value="Transferencia" <?php echo $pedido["metodo_pago"] === "Transferencia" ? "selected" : ""; ?>>Transferencia</option>
                <option value="Tarjeta"       <?php echo $pedido["metodo_pago"] === "Tarjeta"       ? "selected" : ""; ?>>Tarjeta</option>
            </select>

            <?php if ($pedido["estado_pago"] === "Pagado"): ?>
                <p class="nota-formulario">El estado del pago no cambiará porque ya fue confirmado.</p>
            <?php endif; ?>

            <button type="submit" class="btn-tabla" style="margin-top:16px;">Guardar cambio</button>
        </form>
    </div>

    <?php if (!empty($extrasDisponibles)): ?>
    <div class="bloque-formulario">
        <h2>Agregar extras</h2>

        <form method="POST" action="guardar_edicion_pedido.php" id="form-agregar-extras">
            <input type="hidden" name="id_pedido" value="<?php echo $id_pedido; ?>">
            <input type="hidden" name="accion"    value="extras">

            <?php foreach ($extrasDisponibles as $cat => $items): ?>
            <div class="grupo-categoria">
                <h3><?php echo $iconosExtra[$cat]; ?> <?php echo htmlspecialchars($cat === "Complemento" ? "Complemento extra" : $cat . " extra"); ?></h3>
                <?php foreach ($items as $item): ?>
                <?php $precioEd = $PRECIOS_EXTRA_ED[$cat] ?? 25; ?>
                <div class="extra-item" data-precio="<?php echo $precioEd; ?>">
                    <span class="extra-item__nombre"><?php echo htmlspecialchars($item["nombre"]); ?></span>
                    <span class="extra-item__precio-hint">$<?php echo $precioEd; ?> c/u</span>
                    <div class="extra-item__contador">
                        <button type="button" class="extra-btn-menos">−</button>
                        <input type="number" name="extras[<?php echo (int)$item["id_producto"]; ?>]"
                               class="extra-cantidad" value="0" min="0" max="5" readonly>
                        <button type="button" class="extra-btn-mas">+</button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>

            <p class="nota-formulario" id="nota-extras-vacio">Selecciona al menos un extra para continuar.</p>
            <button type="submit" class="btn-tabla" id="btn-agregar-extras" disabled style="margin-top:8px;">
                Agregar extras al pedido
            </button>
        </form>
    </div>
    <?php endif; ?>

    <div class="bloque-formulario acciones-panel">
        <h2>Acciones</h2>

        <div class="acciones-panel__botones">
            <?php if ($pedido["metodo_pago"] === "Transferencia" && $pedido["estado_pago"] === "Pendiente de validación"): ?>
                <a class="btn-tabla" href="confirmar_pago.php?id=<?php echo (int)$pedido["id_pedido"]; ?>">
                    Confirmar pago
                </a>
            <?php endif; ?>

            <?php if (in_array($pedido["metodo_pago"], ["Efectivo", "Tarjeta"]) || $pedido["estado_pago"] === "Pagado"): ?>
                <a class="btn-tabla" target="_blank" href="imprimir_y_notificar.php?id=<?php echo (int)$pedido["id_pedido"]; ?>">
                    Imprimir ticket
                </a>
            <?php endif; ?>
            <?php if (count($menusPedido) > 1): ?>
                <a class="btn-tabla btn-tabla--imprimir" target="_blank" href="ticket.php?id=<?php echo (int)$pedido["id_pedido"]; ?>&separado=1">
                    Imprimir separado
                </a>
            <?php endif; ?>

            <a class="btn-link" href="pedidos.php">Volver a pedidos</a>
            <button type="button" class="btn-eliminar-pedido" id="btn-eliminar-pedido">🗑 Eliminar pedido</button>
        </div>
    </div>
</div>

<!-- Modal confirmación eliminar -->
<div id="modal-eliminar" class="zb-modal-overlay" style="display:none;">
    <div class="zb-modal" style="max-width:360px;">
        <p class="zb-modal__eyebrow" style="color:#f87171;">⚠️ Eliminar pedido</p>
        <h2 class="zb-modal__titulo" style="font-size:17px;">¿Seguro que quieres eliminar este pedido?</h2>
        <p class="zb-modal__desc">
            Se borrará el pedido <strong style="color:var(--zabisu-orange);"><?php echo htmlspecialchars($pedido["folio"]); ?></strong> de forma permanente. Esta acción no se puede deshacer.
        </p>
        <form method="POST" action="">
            <input type="hidden" name="accion"    value="eliminar_pedido">
            <input type="hidden" name="id_pedido" value="<?php echo (int)$pedido["id_pedido"]; ?>">
            <div class="zb-modal__acciones" style="margin-top:8px;">
                <button type="submit" class="btn-principal" style="background:#ef4444;flex:1;">Sí, eliminar</button>
                <button type="button" id="btn-cancelar-eliminar" class="btn-volver-panel" style="flex:1;">Cancelar</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function () {

    // ── Modal eliminar ────────────────────────────────────
    const btnEliminar  = document.getElementById("btn-eliminar-pedido");
    const modalElim    = document.getElementById("modal-eliminar");
    const btnCancelar  = document.getElementById("btn-cancelar-eliminar");

    if (btnEliminar) btnEliminar.addEventListener("click", function () {
        modalElim.style.display = "flex";
    });
    if (btnCancelar) btnCancelar.addEventListener("click", function () {
        modalElim.style.display = "none";
    });
    if (modalElim) modalElim.addEventListener("click", function (e) {
        if (e.target === modalElim) modalElim.style.display = "none";
    });

    const btnAgregar = document.getElementById("btn-agregar-extras");
    const notaVacio  = document.getElementById("nota-extras-vacio");

    function actualizarBotonExtras() {
        const hayAlguno = Array.from(document.querySelectorAll(".extra-cantidad"))
            .some(function (i) { return parseInt(i.value) > 0; });
        if (btnAgregar) btnAgregar.disabled = !hayAlguno;
        if (notaVacio)  notaVacio.style.display = hayAlguno ? "none" : "";
    }

    document.querySelectorAll(".extra-item").forEach(function (item) {
        const menos = item.querySelector(".extra-btn-menos");
        const mas   = item.querySelector(".extra-btn-mas");
        const input = item.querySelector(".extra-cantidad");

        menos.addEventListener("click", function () {
            const v = parseInt(input.value) || 0;
            if (v > 0) {
                input.value = v - 1;
                item.classList.toggle("extra-item--activo", (v - 1) > 0);
                actualizarBotonExtras();
            }
        });
        mas.addEventListener("click", function () {
            const v = parseInt(input.value) || 0;
            if (v < 5) {
                input.value = v + 1;
                item.classList.add("extra-item--activo");
                actualizarBotonExtras();
            }
        });
    });

    actualizarBotonExtras();
});
</script>

</body>
</html>