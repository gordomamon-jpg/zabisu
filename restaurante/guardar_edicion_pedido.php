<?php
require_once "../config/db.php";
require_once "auth_check.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: pedidos.php");
    exit;
}

$id_pedido = (int)($_POST["id_pedido"] ?? 0);
$accion    = $_POST["accion"] ?? "";

if ($id_pedido <= 0) {
    die("Pedido no válido.");
}

/* Verificar que el pedido existe */
$stmtPed = $conexion->prepare("SELECT id_pedido, estado_pago FROM pedidos WHERE id_pedido = :id LIMIT 1");
$stmtPed->execute([":id" => $id_pedido]);
$pedido = $stmtPed->fetch(PDO::FETCH_ASSOC);
if (!$pedido) die("Pedido no encontrado.");

/* ── Acción 1: Cambiar método de pago ── */
if ($accion === "metodo_pago") {
    $metodo = $_POST["metodo_pago"] ?? "";
    if (!in_array($metodo, ["Efectivo", "Transferencia"])) {
        die("Método de pago no válido.");
    }

    /* Si ya está pagado, solo cambia el método; no se toca el estado */
    if ($pedido["estado_pago"] === "Pagado") {
        $nuevoEstado = "Pagado";
    } elseif ($metodo === "Efectivo") {
        $nuevoEstado = "Pago en efectivo";
    } else {
        $nuevoEstado = "Pendiente de validación";
    }

    $stmtU = $conexion->prepare(
        "UPDATE pedidos SET metodo_pago = :metodo, estado_pago = :estado WHERE id_pedido = :id"
    );
    $stmtU->execute([":metodo" => $metodo, ":estado" => $nuevoEstado, ":id" => $id_pedido]);
}

/* ── Acción 2: Cambiar horario ── */
elseif ($accion === "horario") {
    $id_horario_nuevo = (int)($_POST["id_horario"] ?? 0);
    if ($id_horario_nuevo <= 0) die("Horario no válido.");

    /* Verificar que el horario existe y está activo */
    $stmtHV = $conexion->prepare(
        "SELECT h.id_horario FROM horarios_ubicacion h
         INNER JOIN ubicaciones u ON h.id_ubicacion = u.id_ubicacion
         WHERE h.id_horario = :id AND h.activo = 1 AND u.activo = 1 LIMIT 1"
    );
    $stmtHV->execute([":id" => $id_horario_nuevo]);
    if (!$stmtHV->fetchColumn()) die("Horario no encontrado.");

    $stmtUH = $conexion->prepare("UPDATE pedidos SET id_horario = :id_horario WHERE id_pedido = :id");
    $stmtUH->execute([":id_horario" => $id_horario_nuevo, ":id" => $id_pedido]);
}

/* ── Acción 3: Agregar extras ── */
elseif ($accion === "extras") {
    $PRECIOS_EXTRA = ["Sopa" => 25.00, "Complemento" => 25.00, "Agua" => 20.00];
    $extrasPost    = $_POST["extras"] ?? [];
    $delta         = 0.0;

    $stmtProd = $conexion->prepare(
        "SELECT id_producto, nombre, categoria FROM productos WHERE id_producto = :id LIMIT 1"
    );
    $stmtCheck = $conexion->prepare(
        "SELECT id_extra FROM pedido_extras
         WHERE id_pedido = :id_pedido AND id_producto = :id_prod LIMIT 1"
    );
    $stmtUpd = $conexion->prepare(
        "UPDATE pedido_extras SET cantidad = cantidad + :cant WHERE id_extra = :id_extra"
    );
    $stmtIns = $conexion->prepare(
        "INSERT INTO pedido_extras (id_pedido, id_producto, nombre, categoria, cantidad, precio_unitario)
         VALUES (:id_pedido, :id_prod, :nombre, :cat, :cantidad, :precio)"
    );

    foreach ($extrasPost as $idProd => $cantidad) {
        $idProd   = (int)$idProd;
        $cantidad = (int)$cantidad;
        if ($cantidad <= 0 || $idProd <= 0) continue;

        $stmtProd->execute([":id" => $idProd]);
        $prod = $stmtProd->fetch(PDO::FETCH_ASSOC);
        if (!$prod) continue;

        $cat    = $prod["categoria"];
        $precio = $PRECIOS_EXTRA[$cat] ?? 25.00;

        $stmtCheck->execute([":id_pedido" => $id_pedido, ":id_prod" => $idProd]);
        $existingId = $stmtCheck->fetchColumn();

        if ($existingId) {
            $stmtUpd->execute([":cant" => $cantidad, ":id_extra" => $existingId]);
        } else {
            $stmtIns->execute([
                ":id_pedido" => $id_pedido,
                ":id_prod"   => $idProd,
                ":nombre"    => $prod["nombre"],
                ":cat"       => $cat,
                ":cantidad"  => $cantidad,
                ":precio"    => $precio,
            ]);
        }

        $delta += $precio * $cantidad;
    }

    if ($delta > 0) {
        $stmtTotal = $conexion->prepare(
            "UPDATE pedidos SET total = total + :delta WHERE id_pedido = :id"
        );
        $stmtTotal->execute([":delta" => $delta, ":id" => $id_pedido]);
    }
}

header("Location: ver_pedido.php?id={$id_pedido}&editado=1");
exit;
