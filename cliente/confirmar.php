<?php
require_once "../config/db.php";

$folio = trim($_GET["folio"] ?? "");

if ($folio === "") {
    die("No se recibió un pedido válido.");
}

/*
    1. Obtener pedido principal con horario y ubicación
*/
$sqlPedido = "SELECT
                p.*,
                h.hora_entrega,
                u.nombre_ubicacion,
                u.tipo AS tipo_ubicacion
              FROM pedidos p
              INNER JOIN horarios_ubicacion h ON p.id_horario = h.id_horario
              INNER JOIN ubicaciones u ON h.id_ubicacion = u.id_ubicacion
              WHERE p.folio = :folio
              LIMIT 1";
$stmtPedido = $conexion->prepare($sqlPedido);
$stmtPedido->bindParam(":folio", $folio);
$stmtPedido->execute();
$pedido = $stmtPedido->fetch(PDO::FETCH_ASSOC);

if (!$pedido) {
    die("No se encontró el pedido.");
}

$id_pedido = (int)$pedido["id_pedido"];

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
    3. Obtener extras del pedido
*/
$sqlExtras = "SELECT * FROM pedido_extras WHERE id_pedido = :id_pedido ORDER BY id_extra ASC";
$stmtExtras = $conexion->prepare($sqlExtras);
$stmtExtras->bindParam(":id_pedido", $id_pedido, PDO::PARAM_INT);
$stmtExtras->execute();
$extrasConfirmar = $stmtExtras->fetchAll(PDO::FETCH_ASSOC);

/*
    4. Obtener detalle de cada menú
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

$sqlPrecios = "SELECT nombre_menu, precio FROM tipos_menu WHERE activo = 1";
$stmtPrecios = $conexion->prepare($sqlPrecios);
$stmtPrecios->execute();
$preciosMenus = [];
foreach ($stmtPrecios->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $preciosMenus[$r["nombre_menu"]] = (float)$r["precio"];
}

function agruparDetallePorCategoria($detalles)
{
    $agrupado = [];

    foreach ($detalles as $detalle) {
        $agrupado[$detalle["categoria"]][] = $detalle["nombre_producto"];
    }

    return $agrupado;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pedido confirmado | Zabisu</title>
    <link rel="icon" type="image/png" href="../assets/img/LOGO_NARA.png">
    <link rel="manifest" href="../manifest.json">
    <meta name="theme-color" content="#FF7A00">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Zabisu">
    <link rel="apple-touch-icon" href="../assets/img/LOGO_NARA.png">
    <link rel="stylesheet" href="../assets/css/styles.css?v=<?php echo CSS_VERSION; ?>">
</head>
<body>

<div class="contenedor">
    <div class="md-hero">
        <div class="md-hero__glow-top"></div>
        <div class="md-hero__glow-bottom"></div>
        <p class="md-hero__eyebrow">¡Listo!</p>
        <div class="md-hero__marca-grupo">
            <img class="md-hero__logo" src="../assets/img/LOGO_BLANCO.png" alt="Zabisu">
            <h1 class="md-hero__marca">Zabisu</h1>
        </div>
        <p class="md-hero__fecha">
            <?php if (($pedido["metodo_pago"] ?? "") === "Transferencia"): ?>
                Pedido registrado · pago pendiente de validación
            <?php else: ?>
                Pedido registrado · pagarás al recibir
            <?php endif; ?>
        </p>
    </div>

    <!-- Feedback -->
    <div class="bloque-formulario" id="bloque-feedback">
        <h2>¿Cómo fue tu experiencia?</h2>
        <p class="nota-formulario" style="margin-bottom:16px;">Tu opinión nos ayuda a mejorar.</p>
        <div class="zb-estrellas" id="estrellas">
            <?php for ($s = 1; $s <= 5; $s++): ?>
                <button type="button" class="zb-estrella" data-valor="<?php echo $s; ?>">★</button>
            <?php endfor; ?>
        </div>
        <textarea id="feedback-comentario" class="zb-modal__textarea" placeholder="Comentario opcional..." maxlength="500" rows="3" style="margin-top:14px;"></textarea>
        <button type="button" id="btn-enviar-feedback" class="btn-principal" style="margin-top:12px;width:100%;" disabled>Enviar opinión</button>
        <p class="zb-modal__gracias" id="feedback-gracias" style="display:none;">¡Gracias! Tu opinión fue registrada 💛</p>
    </div>

    <div class="bloque-formulario resumen-total">
        <h2>Resumen final</h2>

        <div class="ticket-resumen">
            <div class="ticket-menu">
                <div class="ticket-linea">
                    <span>Folio</span>
                    <span><?php echo htmlspecialchars($pedido["folio"]); ?></span>
                </div>

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
                    <span>Método de pago</span>
                    <span><?php echo htmlspecialchars($pedido["metodo_pago"]); ?></span>
                </div>

                <div class="ticket-linea">
                    <span>Estado de pago</span>
                    <span><?php echo htmlspecialchars($pedido["estado_pago"]); ?></span>
                </div>

                <div class="ticket-linea">
                    <span>Ubicación</span>
                    <span><?php echo htmlspecialchars($pedido["nombre_ubicacion"]); ?></span>
                </div>

                <div class="ticket-linea">
                    <span>Hora</span>
                    <span><?php echo date("g:i A", strtotime($pedido["hora_entrega"])); ?></span>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($menusPedido)): ?>
        <div class="bloque-formulario">
            <h2>Detalle del pedido</h2>

            <div class="ticket-resumen">
                <?php foreach ($menusPedido as $menu): ?>
                    <?php $agrupado = agruparDetallePorCategoria($detallePorMenu[$menu["id_pedido_menu"]] ?? []); ?>
                    <div class="ticket-menu" style="margin-bottom: 18px;">
                        <div class="ticket-menu__header">
                            <span>Menú <?php echo (int)$menu["numero_menu"]; ?> — <?php echo htmlspecialchars($menu["tipo_menu"]); ?></span>
                            <?php if (isset($preciosMenus[$menu["tipo_menu"]])): ?>
                                <strong style="color:var(--naranja);">$<?php echo number_format($preciosMenus[$menu["tipo_menu"]], 2); ?></strong>
                            <?php endif; ?>
                        </div>

                        <?php foreach ($agrupado as $categoria => $items): ?>
                            <div class="ticket-linea">
                                <span><?php echo htmlspecialchars($categoria); ?></span>
                                <span><?php echo htmlspecialchars(implode(", ", $items)); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($extrasConfirmar)): ?>
        <div class="bloque-formulario">
            <h2>Extras</h2>
            <div class="ticket-resumen">
                <div class="ticket-menu">
                    <?php $totalExtras = 0; ?>
                    <?php foreach ($extrasConfirmar as $extra): ?>
                        <?php $subtotalExtra = $extra["cantidad"] * $extra["precio_unitario"]; $totalExtras += $subtotalExtra; ?>
                        <div class="ticket-linea">
                            <span><?php echo htmlspecialchars($extra["categoria"]); ?></span>
                            <span>
                                <?php echo htmlspecialchars($extra["nombre"]); ?> ×<?php echo (int)$extra["cantidad"]; ?>
                                <strong style="color:var(--naranja);margin-left:8px;">$<?php echo number_format($subtotalExtra, 2); ?></strong>
                            </span>
                        </div>
                    <?php endforeach; ?>
                    <div class="ticket-subtotal">
                        <span>Total extras</span>
                        <strong style="color:var(--naranja);">$<?php echo number_format($totalExtras, 2); ?></strong>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="bloque-formulario">
        <h2>Total</h2>
        <p class="total-general">
            <strong>$<?php echo number_format((float)$pedido["total"], 2); ?></strong>
        </p>

        <?php if (!empty($pedido["observaciones"])): ?>
            <p class="nota-formulario">
                <strong>Observaciones:</strong> <?php echo htmlspecialchars($pedido["observaciones"]); ?>
            </p>
        <?php endif; ?>

        <div class="aviso-correo-confirmacion">
            <span class="aviso-correo-confirmacion__icono">✉️</span>
            <p>
                Recibirás un correo en <strong><?php echo htmlspecialchars($pedido["correo_cliente"]); ?></strong> con la información completa de tu pedido.
                Si no lo ves en unos minutos, revisa tu carpeta de spam.
            </p>
        </div>

        <div class="acciones-panel__botones">
            <a href="pedido.php" class="btn-link">Hacer otro pedido</a>
        </div>
    </div>

</div>

<script>
(function () {
    const folio = <?php echo json_encode($pedido["folio"] ?? ""); ?>;
    let calSeleccionada = 0;
    const estrellas = document.querySelectorAll(".zb-estrella");
    const btnEnviar = document.getElementById("btn-enviar-feedback");
    const gracias   = document.getElementById("feedback-gracias");
    const bloque    = document.getElementById("bloque-feedback");

    estrellas.forEach(function (btn) {
        btn.addEventListener("mouseenter", function () {
            const v = parseInt(this.dataset.valor);
            estrellas.forEach(function (b) {
                b.classList.toggle("zb-estrella--hover", parseInt(b.dataset.valor) <= v);
            });
        });
        btn.addEventListener("mouseleave", function () {
            estrellas.forEach(function (b) { b.classList.remove("zb-estrella--hover"); });
        });
        btn.addEventListener("click", function () {
            calSeleccionada = parseInt(this.dataset.valor);
            estrellas.forEach(function (b) {
                b.classList.toggle("zb-estrella--activa", parseInt(b.dataset.valor) <= calSeleccionada);
            });
            btnEnviar.disabled = false;
        });
    });

    btnEnviar.addEventListener("click", function () {
        if (!calSeleccionada) return;
        btnEnviar.disabled = true;
        const fd = new FormData();
        fd.append("calificacion", calSeleccionada);
        fd.append("comentario", document.getElementById("feedback-comentario").value.trim());
        fd.append("folio", folio);
        fetch("guardar_feedback.php", { method: "POST", body: fd })
            .then(function (r) { return r.json(); })
            .then(function () {
                bloque.querySelector(".zb-estrellas").style.display = "none";
                bloque.querySelector("#feedback-comentario").style.display = "none";
                btnEnviar.style.display = "none";
                bloque.querySelector(".nota-formulario").style.display = "none";
                gracias.style.display = "block";
            });
    });
})();
</script>

<footer class="cliente-footer">
    <span class="cliente-footer__slogan">© 2026 Zabisu - Sabor y Servicio. Todos los derechos reservados.</span>
</footer>

</body>
</html>