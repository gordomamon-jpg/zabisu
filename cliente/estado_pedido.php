<?php
require_once "../config/db.php";

$pedido         = null;
$menusPedido    = [];
$detallePorMenu = [];
$extrasVer      = [];
$buscado        = false;
$folio          = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $buscado = true;
    $folio   = strtoupper(trim($_POST["folio"] ?? ""));

    if ($folio !== "") {
        $stmtP = $conexion->prepare(
            "SELECT p.folio, p.nombre_cliente, p.metodo_pago, p.estado_pago,
                    p.total, p.observaciones, p.fecha_pedido,
                    h.hora_entrega, u.nombre_ubicacion
             FROM pedidos p
             INNER JOIN horarios_ubicacion h ON p.id_horario = h.id_horario
             INNER JOIN ubicaciones u        ON h.id_ubicacion = u.id_ubicacion
             WHERE p.folio = :folio AND p.es_prueba = 0
             LIMIT 1"
        );
        $stmtP->execute([":folio" => $folio]);
        $pedido = $stmtP->fetch(PDO::FETCH_ASSOC);

        if ($pedido) {
            /* Menús */
            $stmtM = $conexion->prepare(
                "SELECT * FROM pedido_menus WHERE id_pedido = (
                     SELECT id_pedido FROM pedidos WHERE folio = :folio LIMIT 1
                 ) ORDER BY numero_menu ASC"
            );
            $stmtM->execute([":folio" => $folio]);
            $menusPedido = $stmtM->fetchAll(PDO::FETCH_ASSOC);

            /* Detalle por menú */
            $stmtD = $conexion->prepare(
                "SELECT * FROM detalle_pedido WHERE id_pedido_menu = :id ORDER BY id_detalle ASC"
            );
            foreach ($menusPedido as $menu) {
                $stmtD->execute([":id" => $menu["id_pedido_menu"]]);
                $detallePorMenu[$menu["id_pedido_menu"]] = $stmtD->fetchAll(PDO::FETCH_ASSOC);
            }

            /* Extras */
            $stmtE = $conexion->prepare(
                "SELECT * FROM pedido_extras WHERE id_pedido = (
                     SELECT id_pedido FROM pedidos WHERE folio = :folio LIMIT 1
                 ) ORDER BY id_extra ASC"
            );
            $stmtE->execute([":folio" => $folio]);
            $extrasVer = $stmtE->fetchAll(PDO::FETCH_ASSOC);
        }
    }
}

function textoEstado($estado) {
    switch ($estado) {
        case "Pendiente de validación": return ["Pago en revisión",   "estado-revision"];
        case "Pago en efectivo":        return ["Pago al recibir",    "estado-efectivo"];
        case "Pagado":                  return ["Pago confirmado",    "estado-pagado"];
        default:                        return [$estado ?: "Pendiente", "estado-pendiente"];
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Estado de pedido · Zabisu</title>
    <link rel="icon" type="image/png" href="../assets/img/LOGO_NARA.png">
    <meta name="theme-color" content="#FF7A00">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <link rel="apple-touch-icon" href="../assets/img/LOGO_NARA.png">
    <link rel="stylesheet" href="../assets/css/styles.css?v=5">
</head>
<style>
    .hamburguer-btn {
        position: fixed;
        top: 16px;
        right: 16px;
        z-index: 200;
        background: rgba(12,12,15,.75);
        border: 1px solid rgba(255,255,255,.12);
        backdrop-filter: blur(8px);
        -webkit-backdrop-filter: blur(8px);
        border-radius: 10px;
        width: 42px;
        height: 42px;
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        gap: 5px;
        cursor: pointer;
        padding: 0;
    }
    .hamburguer-btn span {
        display: block;
        width: 20px;
        height: 2px;
        background: #fff;
        border-radius: 2px;
        transition: transform .25s, opacity .25s;
    }
    .hamburguer-btn.abierto span:nth-child(1) { transform: translateY(7px) rotate(45deg); }
    .hamburguer-btn.abierto span:nth-child(2) { opacity: 0; }
    .hamburguer-btn.abierto span:nth-child(3) { transform: translateY(-7px) rotate(-45deg); }

    .nav-overlay {
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,.5);
        z-index: 190;
        opacity: 0;
        pointer-events: none;
        transition: opacity .25s;
    }
    .nav-overlay.visible { opacity: 1; pointer-events: all; }

    .nav-drawer {
        position: fixed;
        top: 0;
        right: 0;
        bottom: 0;
        width: 260px;
        max-width: 85vw;
        background: #0c0c0f;
        z-index: 195;
        transform: translateX(100%);
        transition: transform .28s cubic-bezier(.4,0,.2,1);
        display: flex;
        flex-direction: column;
        padding: 72px 0 32px;
    }
    .nav-drawer.abierto { transform: translateX(0); }

    .nav-drawer__titulo {
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 3px;
        color: #ff7a00;
        text-transform: uppercase;
        padding: 0 24px 16px;
        border-bottom: 1px solid rgba(255,255,255,.08);
        margin-bottom: 8px;
    }

    .nav-drawer__item {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 14px 24px;
        color: #fff;
        text-decoration: none;
        font-size: 15px;
        font-weight: 600;
        transition: background .15s;
    }
    .nav-drawer__item:hover { background: rgba(255,255,255,.06); }
    .nav-drawer__item__icono {
        font-size: 20px;
        width: 28px;
        text-align: center;
        flex-shrink: 0;
    }
</style>

<body>

<!-- ── Hamburger ── -->
<button class="hamburguer-btn" id="hamburguer-btn" aria-label="Menú">
    <span></span><span></span><span></span>
</button>

<!-- ── Overlay ── -->
<div class="nav-overlay" id="nav-overlay"></div>

<!-- ── Drawer ── -->
<nav class="nav-drawer" id="nav-drawer">
    <div class="nav-drawer__titulo">Zabisu</div>
    <a class="nav-drawer__item" href="estado_pedido.php">
        <span class="nav-drawer__item__icono">🔍</span>
        Consultar mi pedido
    </a>
    <a class="nav-drawer__item" href="menu.php">
        <span class="nav-drawer__item__icono">🍽️</span>
        Ver menú del día
    </a>
    <a class="nav-drawer__item" href="pedido.php">
        <span class="nav-drawer__item__icono">🛒</span>
        Hacer un pedido
    </a>
</nav>

<div class="contenedor">

    <div class="md-hero">
        <div class="md-hero__glow-top"></div>
        <div class="md-hero__glow-bottom"></div>
        <p class="md-hero__eyebrow">ZABISU</p>
        <div class="md-hero__marca-grupo">
            <img class="md-hero__logo" src="../assets/img/LOGO_BLANCO.png" alt="Zabisu">
            <h1 class="md-hero__marca">Mi pedido</h1>
        </div>
        <p class="md-hero__fecha">Consulta el estado de tu orden</p>
    </div>

    <!-- ── Formulario de búsqueda ── -->
    <div class="bloque-formulario">
        <h2>Buscar pedido</h2>
        <form method="POST" action="">
            <label for="folio">Folio del pedido</label>
            <input type="text" name="folio" id="folio"
                   maxlength="30" autocomplete="off" autocapitalize="characters"
                   placeholder="Ej. ZAB-A1B2C3"
                   value="<?php echo htmlspecialchars($folio); ?>">
            <p class="nota-formulario">Lo encuentras en el correo de confirmación que recibiste.</p>
            <button type="submit" class="btn-principal" style="margin-top:16px;width:100%;">
                Buscar
            </button>
        </form>
    </div>

    <?php if ($buscado): ?>

        <?php if (!$pedido): ?>
            <!-- No encontrado -->
            <div class="bloque-formulario">
                <p class="nota-formulario" style="color:#ff6b6b;font-size:15px;">
                    No encontramos ningún pedido con el folio <strong><?php echo htmlspecialchars($folio); ?></strong>.
                    Verifica que lo hayas escrito correctamente.
                </p>
            </div>

        <?php else: ?>
            <?php [$textoE, $claseE] = textoEstado($pedido["estado_pago"]); ?>

            <!-- ── Resumen del pedido ── -->
            <div class="bloque-formulario resumen-total">
                <h2>Tu pedido</h2>

                <div class="ticket-resumen">
                    <div class="ticket-menu">

                        <div class="ticket-linea">
                            <span>Folio</span>
                            <strong><?php echo htmlspecialchars($pedido["folio"]); ?></strong>
                        </div>

                        <div class="ticket-linea">
                            <span>Nombre</span>
                            <span><?php echo htmlspecialchars($pedido["nombre_cliente"]); ?></span>
                        </div>

                        <div class="ticket-linea">
                            <span>Fecha</span>
                            <span><?php echo date("d/m/Y g:i A", strtotime($pedido["fecha_pedido"])); ?></span>
                        </div>

                        <div class="ticket-linea">
                            <span>Punto de entrega</span>
                            <span><?php echo htmlspecialchars($pedido["nombre_ubicacion"]); ?></span>
                        </div>

                        <div class="ticket-linea">
                            <span>Horario</span>
                            <span><?php echo date("g:i A", strtotime($pedido["hora_entrega"])); ?></span>
                        </div>

                        <div class="ticket-linea">
                            <span>Método de pago</span>
                            <span><?php echo htmlspecialchars($pedido["metodo_pago"]); ?></span>
                        </div>

                        <div class="ticket-linea">
                            <span>Estado del pago</span>
                            <span class="<?php echo $claseE; ?>"><?php echo htmlspecialchars($textoE); ?></span>
                        </div>

                        <div class="ticket-subtotal">
                            <span>Total</span>
                            <strong>$<?php echo number_format((float)$pedido["total"], 2); ?></strong>
                        </div>

                    </div>
                </div>
            </div>

            <!-- ── Detalle de menús ── -->
            <?php foreach ($menusPedido as $menu): ?>
            <div class="bloque-formulario">
                <h2>
                    Menú <?php echo (int)$menu["numero_menu"]; ?> — <?php echo htmlspecialchars($menu["tipo_menu"]); ?>
                    <?php if (!empty($menu["nombre_persona"])): ?>
                        <span style="font-size:14px;font-weight:500;"> · <?php echo htmlspecialchars($menu["nombre_persona"]); ?></span>
                    <?php endif; ?>
                </h2>
                <div class="ticket-resumen">
                    <div class="ticket-menu">
                        <?php foreach ($detallePorMenu[$menu["id_pedido_menu"]] ?? [] as $d): ?>
                        <div class="ticket-linea">
                            <span><?php echo htmlspecialchars($d["categoria"]); ?></span>
                            <span><?php echo htmlspecialchars($d["nombre_producto"]); ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>

            <!-- ── Extras ── -->
            <?php if (!empty($extrasVer)): ?>
            <div class="bloque-formulario">
                <h2>Extras</h2>
                <div class="ticket-resumen">
                    <div class="ticket-menu">
                        <?php foreach ($extrasVer as $extra): ?>
                        <div class="ticket-linea">
                            <span><?php echo htmlspecialchars($extra["nombre"]); ?> ×<?php echo (int)$extra["cantidad"]; ?></span>
                            <span>$<?php echo number_format($extra["cantidad"] * $extra["precio_unitario"], 2); ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- ── Observaciones ── -->
            <?php if (!empty($pedido["observaciones"])): ?>
            <div class="bloque-formulario">
                <h2>Observaciones</h2>
                <p class="nota-formulario" style="font-size:14px;line-height:1.6;">
                    <?php echo nl2br(htmlspecialchars($pedido["observaciones"])); ?>
                </p>
            </div>
            <?php endif; ?>

            <div style="height:24px;"></div>

        <?php endif; ?>
    <?php endif; ?>

</div>

<script>
document.getElementById("folio").addEventListener("input", function () {
    this.value = this.value.toUpperCase();
});

(function () {
    var btn     = document.getElementById("hamburguer-btn");
    var drawer  = document.getElementById("nav-drawer");
    var overlay = document.getElementById("nav-overlay");

    function abrir() {
        drawer.classList.add("abierto");
        overlay.classList.add("visible");
        btn.classList.add("abierto");
        document.body.style.overflow = "hidden";
    }
    function cerrar() {
        drawer.classList.remove("abierto");
        overlay.classList.remove("visible");
        btn.classList.remove("abierto");
        document.body.style.overflow = "";
    }

    btn.addEventListener("click", function () {
        drawer.classList.contains("abierto") ? cerrar() : abrir();
    });
    overlay.addEventListener("click", cerrar);
    document.addEventListener("keydown", function (e) {
        if (e.key === "Escape") cerrar();
    });
})();
</script>

</body>
</html>
