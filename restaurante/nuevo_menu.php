<?php
require_once "../config/db.php";
require_once "auth_check.php";

$errores = [];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $fecha            = $_POST["fecha"]            ?? "";
    $publicado_desde  = $_POST["publicado_desde"]  ?? "";
    $pedido_hasta     = $_POST["pedido_hasta"]     ?? "";
    $precio_zabisu    = trim($_POST["precio_zabisu"]    ?? "");
    $precio_ejecutivo = trim($_POST["precio_ejecutivo"] ?? "");

    if ($fecha === "")
        $errores[] = "Debes seleccionar la fecha del menú.";
    if ($publicado_desde === "")
        $errores[] = "Debes indicar cuándo se publica el menú.";
    if ($pedido_hasta === "")
        $errores[] = "Debes indicar hasta cuándo se reciben pedidos.";
    if ($precio_zabisu === "" || !is_numeric($precio_zabisu))
        $errores[] = "Captura un precio válido para Menú Zabisu.";
    if ($precio_ejecutivo === "" || !is_numeric($precio_ejecutivo))
        $errores[] = "Captura un precio válido para Menú Ejecutivo.";

    if ($publicado_desde !== "" && $pedido_hasta !== "") {
        if (strtotime($publicado_desde) >= strtotime($pedido_hasta))
            $errores[] = "La publicación debe ser anterior al cierre de pedidos.";
    }

    if (empty($errores)) {
        try {
            $conexion->beginTransaction();

            $sqlMenu = "INSERT INTO menu_dia (fecha, publicado_desde, pedido_hasta, activo)
                        VALUES (:fecha, :publicado_desde, :pedido_hasta, 0)";
            $stmtMenu = $conexion->prepare($sqlMenu);
            $stmtMenu->execute([
                ":fecha"           => $fecha,
                ":publicado_desde" => $publicado_desde,
                ":pedido_hasta"    => $pedido_hasta,
            ]);
            $id_menu_nuevo = $conexion->lastInsertId();

            $pz = (float)$precio_zabisu;
            $pe = (float)$precio_ejecutivo;

            $conexion->prepare("UPDATE tipos_menu SET precio = :p WHERE nombre_menu = 'Zabisu'")
                     ->execute([":p" => $pz]);
            $conexion->prepare("UPDATE tipos_menu SET precio = :p WHERE nombre_menu = 'Ejecutivo'")
                     ->execute([":p" => $pe]);

            $conexion->commit();

            header("Location: productos_menu.php?id_menu=" . urlencode($id_menu_nuevo));
            exit;

        } catch (Exception $e) {
            $conexion->rollBack();
            $errores[] = "Error al crear el menú: " . $e->getMessage();
        }
    }
}

$fechaDefault     = $_POST["fecha"]            ?? date("Y-m-d");
$pubDefault       = $_POST["publicado_desde"]  ?? date("Y-m-d") . "T17:00";
$hastaDefault     = $_POST["pedido_hasta"]     ?? date("Y-m-d") . "T11:50";
$pzDefault        = $_POST["precio_zabisu"]    ?? "100";
$peDefault        = $_POST["precio_ejecutivo"] ?? "110";
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nuevo menú | Restaurante Zabisu</title>
    <link rel="icon" type="image/png" href="../assets/img/LOGO_NARA.png">
    <link rel="stylesheet" href="../assets/css/styles.css?v=5">
</head>
<body>
<div class="contenedor">

    <div class="hero-zabisu">
        <div class="hero-zabisu__glow"></div>
        <div class="hero-zabisu__contenido">
            <p class="hero-zabisu__eyebrow">RESTAURANTE</p>
            <h1 class="hero-zabisu__titulo">Crear menú del día</h1>
            <p class="hero-zabisu__texto">Paso 1 de 2 — Configuración general del menú</p>
            <a href="panel_general.php" class="btn-volver-panel">← Panel general</a>
        </div>
    </div>

    <!-- Indicador de pasos -->
    <div class="nm-pasos">
        <div class="nm-paso nm-paso--activo">
            <span class="nm-paso__circulo">1</span>
            <span class="nm-paso__etiqueta">Configuración</span>
        </div>
        <div class="nm-pasos__linea"></div>
        <div class="nm-paso nm-paso--pendiente">
            <span class="nm-paso__circulo">2</span>
            <span class="nm-paso__etiqueta">Productos</span>
        </div>
    </div>

    <?php if (!empty($errores)): ?>
        <div class="mensaje-error">
            <ul>
                <?php foreach ($errores as $e): ?>
                    <li><?php echo htmlspecialchars($e); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form action="" method="POST">

        <!-- FECHA Y HORARIOS -->
        <div class="bloque-formulario nm-seccion">
            <div class="nm-seccion__header">
                <span class="nm-seccion__icono">📅</span>
                <div>
                    <h2>Fecha y ventana de pedidos</h2>
                    <p class="nota-formulario" style="margin:0;">Define para qué día aplica el menú y en qué horario pueden hacerse pedidos.</p>
                </div>
            </div>

            <div class="nm-campo-grupo">
                <div class="nm-campo">
                    <label for="fecha">Fecha del menú</label>
                    <input type="date" name="fecha" id="fecha"
                           value="<?php echo htmlspecialchars($fechaDefault); ?>" required>
                    <span class="nm-campo__ayuda">Día al que corresponde este menú</span>
                </div>
            </div>

            <div class="nm-campo-grupo nm-campo-grupo--dos">
                <div class="nm-campo">
                    <label for="publicado_desde">Publicar desde</label>
                    <input type="datetime-local" name="publicado_desde" id="publicado_desde"
                           value="<?php echo htmlspecialchars($pubDefault); ?>" required>
                    <span class="nm-campo__ayuda">Desde cuándo verán el menú los clientes</span>
                </div>
                <div class="nm-campo">
                    <label for="pedido_hasta">Cerrar pedidos a las</label>
                    <input type="datetime-local" name="pedido_hasta" id="pedido_hasta"
                           value="<?php echo htmlspecialchars($hastaDefault); ?>" required>
                    <span class="nm-campo__ayuda">Después de esta hora no se aceptan pedidos</span>
                </div>
            </div>
        </div>

        <!-- PRECIOS -->
        <div class="bloque-formulario nm-seccion">
            <div class="nm-seccion__header">
                <span class="nm-seccion__icono">💰</span>
                <div>
                    <h2>Precios de los menús</h2>
                    <p class="nota-formulario" style="margin:0;">Estos precios se aplicarán a todos los pedidos del menú activo.</p>
                </div>
            </div>

            <div class="nm-campo-grupo nm-campo-grupo--dos">
                <div class="nm-precio-card nm-precio-card--zabisu">
                    <div class="nm-precio-card__header">
                        <strong>Menú Zabisu</strong>
                        <span class="nm-precio-card__desc">2 platos fuertes · Sopa · 5 complementos · Agua · Postre</span>
                    </div>
                    <div class="nm-precio-card__input">
                        <span class="nm-precio-card__simbolo">$</span>
                        <input type="number" step="0.01" min="0" name="precio_zabisu" id="precio_zabisu"
                               value="<?php echo htmlspecialchars($pzDefault); ?>" required>
                    </div>
                </div>

                <div class="nm-precio-card nm-precio-card--ejecutivo">
                    <div class="nm-precio-card__header">
                        <strong>Menú Ejecutivo</strong>
                        <span class="nm-precio-card__desc">3 platos fuertes · Sopa · 5 complementos · Agua · Postre</span>
                    </div>
                    <div class="nm-precio-card__input">
                        <span class="nm-precio-card__simbolo">$</span>
                        <input type="number" step="0.01" min="0" name="precio_ejecutivo" id="precio_ejecutivo"
                               value="<?php echo htmlspecialchars($peDefault); ?>" required>
                    </div>
                </div>
            </div>
        </div>

        <!-- ACCIONES -->
        <div class="bloque-formulario">
            <div class="nm-acciones">
                <button type="submit" class="btn-principal">Continuar a productos →</button>
                <a href="panel_general.php" class="btn-link">Cancelar</a>
            </div>
            <p class="nota-formulario" style="margin-top:12px;">
                El menú quedará <strong>inactivo</strong> hasta que captures sus productos y lo actives manualmente.
            </p>
        </div>

    </form>
</div>
</body>
</html>
