<?php
require_once "../config/db.php";
require_once "auth_check.php";

$id_pedido = isset($_GET["id"]) ? (int)$_GET["id"] : 0;

if ($id_pedido <= 0) {
    die("No se recibió un pedido válido.");
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
    4. Modo separado: un ticket por menú
*/
$separado = isset($_GET["separado"]) && $_GET["separado"] === "1";

/* Precios por tipo de menú (para calcular el total individual) */
$preciosTicket = [];
$stmtPrecTick  = $conexion->query("SELECT nombre_menu, precio FROM tipos_menu");
foreach ($stmtPrecTick->fetchAll(PDO::FETCH_ASSOC) as $t) {
    $preciosTicket[$t["nombre_menu"]] = (float)$t["precio"];
}

/*
    5. Obtener extras del pedido
*/
$sqlExtras = "SELECT * FROM pedido_extras WHERE id_pedido = :id_pedido ORDER BY id_extra ASC";
$stmtExtras = $conexion->prepare($sqlExtras);
$stmtExtras->bindParam(":id_pedido", $id_pedido, PDO::PARAM_INT);
$stmtExtras->execute();
$extrasTicket = $stmtExtras->fetchAll(PDO::FETCH_ASSOC);

function agruparDetallePorCategoria($detalles)
{
    $agrupado = [];
    foreach ($detalles as $detalle) {
        $agrupado[$detalle["categoria"]][] = $detalle["nombre_producto"];
    }
    return $agrupado;
}

function obtenerTextoMetodoPagoTicket($metodoPago)
{
    return $metodoPago === "Transferencia" ? "Transferencia" : "Efectivo";
}

function obtenerTextoEstadoPagoTicket($estadoPago)
{
    switch ($estadoPago) {
        case "Pago en efectivo":
            return "Cobrar al recibir";
        case "Pagado":
            return "Pagado";
        case "Pendiente de validación":
            return "En validación";
        default:
            return $estadoPago ?: "Pendiente";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket <?php echo htmlspecialchars($pedido["folio"]); ?></title>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 0;
            background: #fff;
            color: #000;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 12px;
        }

        .ticket {
            width: 80mm;
            margin: 0 auto;
            padding: 6mm 4mm;
        }

        .center {
            text-align: center;
        }

        .ticket-header {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 4px;
        }

        .ticket-logo {
            width: 18mm;
            height: 18mm;
            object-fit: contain;
            flex-shrink: 0;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .ticket-header__info {
            flex: 1;
        }

        .brand {
            font-size: 18px;
            font-weight: 800;
            letter-spacing: 1px;
            margin-bottom: 2px;
        }

        .folio {
            font-size: 16px;
            font-weight: 800;
            margin-bottom: 2px;
        }

        .small {
            font-size: 11px;
            line-height: 1.35;
        }

        .line {
            border-top: 1px dashed #000;
            margin: 8px 0;
        }

        .section-title {
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            margin: 8px 0 5px;
        }

        .row {
            display: flex;
            justify-content: space-between;
            gap: 8px;
            margin: 2px 0;
            line-height: 1.35;
        }

        .row .left {
            font-weight: 700;
            min-width: 82px;
        }

        .menu-block {
            margin: 8px 0 10px;
        }

        .menu-title {
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            margin-bottom: 6px;
        }

        .item-group {
            margin-bottom: 5px;
        }

        .item-label {
            font-weight: 800;
            text-transform: uppercase;
            font-size: 11px;
            margin-bottom: 2px;
        }

        .item-value {
            line-height: 1.35;
            padding-left: 2px;
        }

        .obs {
            white-space: pre-line;
            line-height: 1.4;
        }

        .total {
            font-size: 14px;
            font-weight: 800;
        }

        .footer {
            text-align: center;
            margin-top: 10px;
            font-size: 11px;
        }

        .bloque-prioridad {
            border: 1px solid #000;
            padding: 8px 6px;
            margin: 8px 0;
        }

        .bloque-prioridad__titulo {
            text-align: center;
            font-size: 11px;
            font-weight: 800;
            margin-bottom: 6px;
            letter-spacing: 0.5px;
        }

        .bloque-prioridad__fila {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            gap: 8px;
            margin: 4px 0;
            line-height: 1.35;
        }

        .bloque-prioridad__label {
            font-size: 10px;
            font-weight: 800;
            min-width: 58px;
        }

        .bloque-prioridad__valor {
            text-align: right;
            font-size: 13px;
            font-weight: 700;
            flex: 1;
        }

        .bloque-prioridad__valor--grande {
            font-size: 16px;
            font-weight: 800;
        }

        .ticket-separado {
            page-break-after: always;
        }

        .ticket-separado:last-child {
            page-break-after: avoid;
        }

        .nombre-persona {
            font-size: 15px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .resaltado {
            background: #000;
            color: #fff;
            padding: 2px 5px;
            display: inline-block;
            font-weight: 800;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .bloque-obs-resaltado {
            background: #000;
            color: #fff;
            padding: 4px 5px;
            font-weight: 700;
            white-space: pre-line;
            line-height: 1.4;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        @media print {
            @page {
                size: 80mm auto;
                margin: 4mm;
            }

            html, body {
                width: 80mm;
            }

            body {
                padding: 0;
                margin: 0;
            }

            .ticket {
                width: 100%;
                margin: 0;
                padding: 0;
            }
        }
    </style>
</head>
<body>

<?php if ($separado): ?>

    <?php foreach ($menusPedido as $idx => $menu): ?>
    <?php
        $agrupado      = agruparDetallePorCategoria($detallePorMenu[$menu["id_pedido_menu"]] ?? []);
        $precioMenu    = $preciosTicket[$menu["tipo_menu"]] ?? 0;
        $nombrePersona = $menu["nombre_persona"] ?? "";
        $totalMenus    = count($menusPedido);
    ?>
    <div class="ticket ticket-separado">

        <div class="ticket-header">
            <img class="ticket-logo" src="../assets/img/dd.png" alt="Zabisu">
            <div class="ticket-header__info">
                <div class="brand">ZABISU</div>
                <div class="folio"><?php echo htmlspecialchars($pedido["folio"]); ?></div>
                <div class="small"><?php echo date("d/m/Y g:i A", strtotime($pedido["fecha_pedido"])); ?></div>
            </div>
        </div>

        <div class="line"></div>

        <div class="bloque-prioridad">
            <div class="bloque-prioridad__titulo">
                MENÚ <?php echo (int)$menu["numero_menu"]; ?> DE <?php echo $totalMenus; ?>
                — <?php echo htmlspecialchars($menu["tipo_menu"]); ?>
            </div>

            <?php if ($nombrePersona !== ""): ?>
            <div class="bloque-prioridad__fila">
                <span class="bloque-prioridad__label">PARA</span>
                <span class="bloque-prioridad__valor bloque-prioridad__valor--grande nombre-persona">
                    <?php echo htmlspecialchars($nombrePersona); ?>
                </span>
            </div>
            <?php endif; ?>

            <div class="bloque-prioridad__fila">
                <span class="bloque-prioridad__label">UBICACIÓN</span>
                <span class="bloque-prioridad__valor">
                    <?php echo htmlspecialchars($pedido["nombre_ubicacion"]); ?>
                </span>
            </div>

            <div class="bloque-prioridad__fila">
                <span class="bloque-prioridad__label">HORA</span>
                <span class="bloque-prioridad__valor bloque-prioridad__valor--grande">
                    <?php echo date("g:i A", strtotime($pedido["hora_entrega"])); ?>
                </span>
            </div>

            <div class="bloque-prioridad__fila">
                <span class="bloque-prioridad__label">PAGO</span>
                <span class="bloque-prioridad__valor">
                    <?php echo htmlspecialchars(obtenerTextoMetodoPagoTicket($pedido["metodo_pago"])); ?>
                </span>
            </div>
        </div>

        <div class="line"></div>

        <?php foreach ($agrupado as $categoria => $items): ?>
            <?php $esResaltado = in_array($categoria, ["Plato fuerte", "Complemento"]); ?>
            <div class="item-group">
                <div class="item-label"><?php echo htmlspecialchars($categoria); ?></div>
                <div class="item-value">
                    <?php if ($esResaltado): ?>
                        <span class="resaltado"><?php echo htmlspecialchars(implode(" / ", $items)); ?></span>
                    <?php else: ?>
                        <?php echo htmlspecialchars(implode(" / ", $items)); ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <div class="line"></div>

        <?php if ($precioMenu > 0): ?>
        <div class="row total">
            <div>Total</div>
            <div>$<?php echo number_format($precioMenu, 2); ?></div>
        </div>
        <?php endif; ?>

        <div class="footer">Preparar pedido</div>
    </div>
    <?php endforeach; ?>

<?php else: ?>

<div class="ticket">
    <div class="ticket-header">
        <img class="ticket-logo" src="../assets/img/dd.png" alt="Zabisu">
        <div class="ticket-header__info">
            <div class="brand">ZABISU</div>
            <div class="folio"><?php echo htmlspecialchars($pedido["folio"]); ?></div>
            <div class="small"><?php echo date("d/m/Y g:i A", strtotime($pedido["fecha_pedido"])); ?></div>
        </div>
    </div>

    <div class="line"></div>

    <div class="bloque-prioridad">
        <div class="bloque-prioridad__titulo">DATOS CLAVE</div>

        <div class="bloque-prioridad__fila">
            <span class="bloque-prioridad__label">CLIENTE</span>
            <span class="bloque-prioridad__valor bloque-prioridad__valor--grande">
                <?php echo htmlspecialchars($pedido["nombre_cliente"]); ?>
            </span>
        </div>

        <div class="bloque-prioridad__fila">
            <span class="bloque-prioridad__label">UBICACIÓN</span>
            <span class="bloque-prioridad__valor">
                <?php echo htmlspecialchars($pedido["nombre_ubicacion"]); ?>
            </span>
        </div>

        <div class="bloque-prioridad__fila">
            <span class="bloque-prioridad__label">TIPO</span>
            <span class="bloque-prioridad__valor">
                <?php echo htmlspecialchars(ucfirst($pedido["tipo_ubicacion"])); ?>
            </span>
        </div>

        <div class="bloque-prioridad__fila">
            <span class="bloque-prioridad__label">HORA</span>
            <span class="bloque-prioridad__valor bloque-prioridad__valor--grande">
                <?php echo date("g:i A", strtotime($pedido["hora_entrega"])); ?>
            </span>
        </div>

        <div class="bloque-prioridad__fila">
            <span class="bloque-prioridad__label">TEL.</span>
            <span class="bloque-prioridad__valor">
                <?php echo htmlspecialchars($pedido["telefono"]); ?>
            </span>
        </div>

        <div class="bloque-prioridad__fila">
            <span class="bloque-prioridad__label">PAGO</span>
            <span class="bloque-prioridad__valor">
                <?php echo htmlspecialchars(obtenerTextoMetodoPagoTicket($pedido["metodo_pago"])); ?>
            </span>
        </div>

        <div class="bloque-prioridad__fila">
            <span class="bloque-prioridad__label">ESTADO</span>
            <span class="bloque-prioridad__valor">
                <span class="resaltado"><?php echo htmlspecialchars(obtenerTextoEstadoPagoTicket($pedido["estado_pago"])); ?></span>
            </span>
        </div>
    </div>

    <div class="line"></div>

    <?php foreach ($menusPedido as $menu): ?>
        <?php $agrupado = agruparDetallePorCategoria($detallePorMenu[$menu["id_pedido_menu"]] ?? []); ?>
        <div class="menu-block">
            <div class="menu-title">
                Menú <?php echo (int)$menu["numero_menu"]; ?> — <?php echo htmlspecialchars($menu["tipo_menu"]); ?>
                <?php if (!empty($menu["nombre_persona"])): ?>
                    · <?php echo htmlspecialchars($menu["nombre_persona"]); ?>
                <?php endif; ?>
            </div>

            <?php foreach ($agrupado as $categoria => $items): ?>
                <?php $esResaltado = in_array($categoria, ["Plato fuerte", "Complemento"]); ?>
                <div class="item-group">
                    <div class="item-label"><?php echo htmlspecialchars($categoria); ?></div>
                    <div class="item-value">
                        <?php if ($esResaltado): ?>
                            <span class="resaltado"><?php echo htmlspecialchars(implode(" / ", $items)); ?></span>
                        <?php else: ?>
                            <?php echo htmlspecialchars(implode(" / ", $items)); ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="line"></div>
    <?php endforeach; ?>

    <?php if (!empty($extrasTicket)): ?>
    <div class="menu-block">
        <div class="menu-title">EXTRAS</div>
        <?php foreach ($extrasTicket as $extra): ?>
        <div class="item-group">
            <div class="item-label"><?php echo htmlspecialchars($extra["categoria"]); ?></div>
            <div class="item-value">
                <?php echo htmlspecialchars($extra["nombre"]); ?> ×<?php echo (int)$extra["cantidad"]; ?>
                — $<?php echo number_format($extra["cantidad"] * $extra["precio_unitario"], 2); ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <div class="line"></div>
    <?php endif; ?>

    <div class="section-title">Observaciones</div>
    <?php if ($pedido["observaciones"] !== ""): ?>
        <div class="bloque-obs-resaltado"><?php echo htmlspecialchars($pedido["observaciones"]); ?></div>
    <?php else: ?>
        <div class="obs">Sin observaciones</div>
    <?php endif; ?>

    <div class="line"></div>

    <div class="row total">
        <div>Total</div>
        <div>$<?php echo number_format((float)$pedido["total"], 2); ?></div>
    </div>

    <div class="footer">
        Preparar pedido
    </div>
</div>

<?php endif; ?>

<script>
window.onload = function () {
    window.print();
};
</script>

</body>
</html>