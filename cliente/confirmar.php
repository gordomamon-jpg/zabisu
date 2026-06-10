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
    <link rel="stylesheet" href="../assets/css/styles.css?v=5">
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

        <div style="margin:20px 0 8px;padding:16px;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.07);border-radius:14px;text-align:center;">
            <p style="font-size:13px;color:rgba(255,255,255,.45);margin-bottom:10px;">¿Tienes alguna duda sobre tu pedido?</p>
            <a href="https://wa.me/525560908778?text=Hola%2C%20tengo%20una%20duda%20sobre%20mi%20pedido%20Zabisu%20%F0%9F%8D%BD%EF%B8%8F"
               target="_blank" rel="noopener"
               style="display:inline-flex;align-items:center;gap:7px;background:#25d366;color:#fff;text-decoration:none;font-size:14px;font-weight:700;padding:10px 20px;border-radius:999px;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
                </svg>
                Escríbenos por WhatsApp
            </a>
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