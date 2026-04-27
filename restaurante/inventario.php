<?php
require_once "../config/db.php";
require_once "auth_check.php";

$mensaje = "";
$tipoMensaje = "";

/*
    Manejar entradas / ajustes de stock
*/
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $accion    = $_POST["accion"]    ?? "";
    $id_item   = (int)($_POST["id_item"]   ?? 0);
    $cantidad  = (int)($_POST["cantidad"]  ?? 0);
    $notas     = trim($_POST["notas"]      ?? "");

    if ($id_item > 0 && $cantidad > 0 && in_array($accion, ["entrada", "ajuste_suma", "ajuste_resta"])) {

        if ($accion === "entrada" || $accion === "ajuste_suma") {
            $tipo  = ($accion === "entrada") ? "entrada" : "ajuste";
            $delta = $cantidad;
        } else {
            $tipo  = "ajuste";
            $delta = -$cantidad;
        }

        try {
            $conexion->beginTransaction();

            $stmtUpd = $conexion->prepare(
                "UPDATE inventario_desechable
                    SET stock_actual = stock_actual + :delta
                  WHERE id_item = :id_item"
            );
            $stmtUpd->execute([":delta" => $delta, ":id_item" => $id_item]);

            $stmtMov = $conexion->prepare(
                "INSERT INTO inventario_movimientos (id_item, tipo, cantidad, notas)
                 VALUES (:id_item, :tipo, :cantidad, :notas)"
            );
            $stmtMov->execute([
                ":id_item"  => $id_item,
                ":tipo"     => $tipo,
                ":cantidad" => $delta,
                ":notas"    => $notas !== "" ? $notas : null,
            ]);

            $conexion->commit();

            $mensaje     = "Stock actualizado correctamente.";
            $tipoMensaje = "ok";

        } catch (Exception $e) {
            $conexion->rollBack();
            $mensaje     = "Error al actualizar el stock.";
            $tipoMensaje = "error";
        }

    } else {
        $mensaje     = "Datos inválidos. Verifica el artículo y la cantidad.";
        $tipoMensaje = "error";
    }
}

/*
    Obtener todos los artículos
*/
$stmtItems = $conexion->prepare(
    "SELECT * FROM inventario_desechable ORDER BY id_item ASC"
);
$stmtItems->execute();
$items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

/*
    Items con stock bajo
*/
$itemsBajos = array_filter($items, function ($i) {
    return (int)$i["stock_actual"] <= (int)$i["stock_minimo"];
});

/*
    Últimos 30 movimientos
*/
$stmtMovs = $conexion->prepare(
    "SELECT m.*, i.nombre AS nombre_item
       FROM inventario_movimientos m
       INNER JOIN inventario_desechable i ON i.id_item = m.id_item
      ORDER BY m.creado_en DESC
      LIMIT 30"
);
$stmtMovs->execute();
$movimientos = $stmtMovs->fetchAll(PDO::FETCH_ASSOC);

function fmtFechaInv($ts) {
    return date("d/m/Y g:i A", strtotime($ts));
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventario desechable | Restaurante Zabisu</title>
    <link rel="icon" type="image/png" href="../assets/img/LOGO_NARA.png">
    <link rel="stylesheet" href="../assets/css/styles.css?v=<?php echo CSS_VERSION; ?>">
    <style>
        .inv-tabla { width:100%; border-collapse:collapse; font-size:14px; }
        .inv-tabla th {
            text-align:left; padding:10px 12px;
            font-size:11px; font-weight:700; letter-spacing:.06em; text-transform:uppercase;
            color:#888; border-bottom:2px solid #2a2a2e;
        }
        .inv-tabla td { padding:12px 12px; border-bottom:1px solid #1e1e22; vertical-align:middle; }
        .inv-tabla tr:last-child td { border-bottom:none; }
        .inv-tabla tr:hover td { background:rgba(255,122,0,.04); }

        .badge-stock {
            display:inline-flex; align-items:center; gap:5px;
            padding:4px 10px; border-radius:20px; font-size:12px; font-weight:700;
        }
        .badge-stock--ok    { background:rgba(74,222,128,.12); color:#4ade80; }
        .badge-stock--bajo  { background:rgba(251,146,60,.15); color:#fb923c; }
        .badge-stock--agotado { background:rgba(248,113,113,.15); color:#f87171; }

        .alerta-inv {
            background:rgba(251,146,60,.1); border:1px solid rgba(251,146,60,.35);
            border-radius:12px; padding:16px 20px; margin-bottom:20px;
        }
        .alerta-inv__titulo { font-weight:700; color:#fb923c; margin:0 0 8px; font-size:14px; }
        .alerta-inv__lista  { margin:0; padding-left:18px; color:#ccc; font-size:13px; line-height:1.8; }

        .inv-grid { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
        @media(max-width:700px){ .inv-grid{ grid-template-columns:1fr; } }

        .mov-tipo-entrada  { color:#4ade80; font-weight:700; font-size:12px; }
        .mov-tipo-descuento{ color:#f87171; font-weight:700; font-size:12px; }
        .mov-tipo-ajuste   { color:#60a5fa; font-weight:700; font-size:12px; }

        .mov-cantidad-pos { color:#4ade80; font-weight:700; }
        .mov-cantidad-neg { color:#f87171; font-weight:700; }

        .stock-numero { font-size:18px; font-weight:800; }
    </style>
</head>
<body>

<div class="contenedor">

    <div class="hero-zabisu">
        <div class="hero-zabisu__glow"></div>
        <div class="hero-zabisu__contenido">
            <p class="hero-zabisu__eyebrow">RESTAURANTE</p>
            <h1 class="hero-zabisu__titulo">Inventario desechable</h1>
            <p class="hero-zabisu__texto">
                Control de stock de desechable. Se descuenta automáticamente al confirmar cada pedido.
            </p>
            <a href="panel_general.php" class="btn-volver-panel">← Panel general</a>
        </div>
    </div>

    <?php if ($mensaje !== ""): ?>
        <div class="bloque-formulario" style="padding:14px 20px; margin-bottom:0;
             border-left:4px solid <?php echo $tipoMensaje === 'ok' ? '#4ade80' : '#f87171'; ?>;">
            <p style="margin:0; color:<?php echo $tipoMensaje === 'ok' ? '#4ade80' : '#f87171'; ?>; font-weight:700;">
                <?php echo htmlspecialchars($mensaje); ?>
            </p>
        </div>
    <?php endif; ?>

    <?php if (!empty($itemsBajos)): ?>
        <div class="alerta-inv">
            <p class="alerta-inv__titulo">⚠️ Stock bajo — hay que comprar pronto</p>
            <ul class="alerta-inv__lista">
                <?php foreach ($itemsBajos as $ib): ?>
                    <li>
                        <strong><?php echo htmlspecialchars($ib["nombre"]); ?></strong>
                        — quedan <strong><?php echo (int)$ib["stock_actual"]; ?></strong>
                        (mínimo: <?php echo (int)$ib["stock_minimo"]; ?>)
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <!-- TABLA DE STOCK -->
    <div class="bloque-formulario">
        <div class="cabecera-modulo">
            <h2>Stock actual</h2>
            <span style="font-size:13px;color:#888;"><?php echo count($items); ?> artículos</span>
        </div>
        <div style="overflow-x:auto;">
            <table class="inv-tabla">
                <thead>
                    <tr>
                        <th>Artículo</th>
                        <th>Stock actual</th>
                        <th>Mínimo</th>
                        <th>Estado</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <?php
                            $stock   = (int)$item["stock_actual"];
                            $minimo  = (int)$item["stock_minimo"];
                            if ($stock <= 0) {
                                $badgeClass = "badge-stock--agotado";
                                $badgeLabel = "Agotado";
                            } elseif ($stock <= $minimo) {
                                $badgeClass = "badge-stock--bajo";
                                $badgeLabel = "Stock bajo";
                            } else {
                                $badgeClass = "badge-stock--ok";
                                $badgeLabel = "OK";
                            }
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($item["nombre"]); ?></td>
                            <td><span class="stock-numero"><?php echo $stock; ?></span></td>
                            <td style="color:#888;"><?php echo $minimo; ?></td>
                            <td><span class="badge-stock <?php echo $badgeClass; ?>"><?php echo $badgeLabel; ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- FORMULARIOS -->
    <div class="inv-grid">

        <!-- Entrada de stock -->
        <div class="bloque-formulario">
            <h2>Agregar stock</h2>
            <form method="POST" action="">
                <input type="hidden" name="accion" value="entrada">
                <label for="id_item_entrada">Artículo</label>
                <select id="id_item_entrada" name="id_item" required>
                    <option value="">— Selecciona —</option>
                    <?php foreach ($items as $item): ?>
                        <option value="<?php echo (int)$item["id_item"]; ?>">
                            <?php echo htmlspecialchars($item["nombre"]); ?>
                            (<?php echo (int)$item["stock_actual"]; ?> en stock)
                        </option>
                    <?php endforeach; ?>
                </select>
                <label for="cantidad_entrada">Cantidad a agregar</label>
                <input type="number" id="cantidad_entrada" name="cantidad" min="1" required placeholder="Ej. 100">
                <label for="notas_entrada">Nota (opcional)</label>
                <input type="text" id="notas_entrada" name="notas" placeholder="Ej. Compra semanal">
                <button type="submit" class="btn-stepper btn-stepper--siguiente" style="margin-top:14px;">
                    Agregar stock
                </button>
            </form>
        </div>

        <!-- Ajuste manual -->
        <div class="bloque-formulario">
            <h2>Ajuste manual</h2>
            <p style="font-size:13px;color:#888;margin-top:0;">
                Usa esto si el conteo físico no coincide con el sistema.
            </p>
            <form method="POST" action="">
                <label for="id_item_ajuste">Artículo</label>
                <select id="id_item_ajuste" name="id_item" required>
                    <option value="">— Selecciona —</option>
                    <?php foreach ($items as $item): ?>
                        <option value="<?php echo (int)$item["id_item"]; ?>">
                            <?php echo htmlspecialchars($item["nombre"]); ?>
                            (<?php echo (int)$item["stock_actual"]; ?> en stock)
                        </option>
                    <?php endforeach; ?>
                </select>
                <label for="accion_ajuste">Tipo de ajuste</label>
                <select id="accion_ajuste" name="accion" required>
                    <option value="ajuste_suma">+ Sumar piezas</option>
                    <option value="ajuste_resta">− Restar piezas</option>
                </select>
                <label for="cantidad_ajuste">Cantidad</label>
                <input type="number" id="cantidad_ajuste" name="cantidad" min="1" required placeholder="Ej. 10">
                <label for="notas_ajuste">Motivo (opcional)</label>
                <input type="text" id="notas_ajuste" name="notas" placeholder="Ej. Corrección conteo">
                <button type="submit" class="btn-stepper btn-stepper--siguiente" style="margin-top:14px;">
                    Aplicar ajuste
                </button>
            </form>
        </div>

    </div>

    <!-- HISTORIAL -->
    <div class="bloque-formulario">
        <h2>Últimos movimientos</h2>
        <?php if (empty($movimientos)): ?>
            <p class="nota-formulario">No hay movimientos registrados todavía.</p>
        <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="inv-tabla">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Artículo</th>
                        <th>Tipo</th>
                        <th>Cantidad</th>
                        <th>Pedido</th>
                        <th>Nota</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($movimientos as $mov): ?>
                        <?php
                            $tipoLabel = match($mov["tipo"]) {
                                "entrada"   => ["label" => "Entrada",    "class" => "mov-tipo-entrada"],
                                "descuento" => ["label" => "Pedido",     "class" => "mov-tipo-descuento"],
                                "ajuste"    => ["label" => "Ajuste",     "class" => "mov-tipo-ajuste"],
                                default     => ["label" => $mov["tipo"], "class" => ""],
                            };
                            $cant    = (int)$mov["cantidad"];
                            $cantCls = $cant >= 0 ? "mov-cantidad-pos" : "mov-cantidad-neg";
                            $cantStr = $cant >= 0 ? "+$cant" : "$cant";
                        ?>
                        <tr>
                            <td style="color:#888;font-size:12px;white-space:nowrap;">
                                <?php echo fmtFechaInv($mov["creado_en"]); ?>
                            </td>
                            <td><?php echo htmlspecialchars($mov["nombre_item"]); ?></td>
                            <td><span class="<?php echo $tipoLabel['class']; ?>"><?php echo $tipoLabel['label']; ?></span></td>
                            <td><span class="<?php echo $cantCls; ?>"><?php echo $cantStr; ?></span></td>
                            <td style="color:#888;font-size:12px;">
                                <?php echo $mov["id_pedido"] ? "#" . (int)$mov["id_pedido"] : "—"; ?>
                            </td>
                            <td style="color:#888;font-size:12px;">
                                <?php echo $mov["notas"] ? htmlspecialchars($mov["notas"]) : "—"; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

</div><!-- /contenedor -->
</body>
</html>
