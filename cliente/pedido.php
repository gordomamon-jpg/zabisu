<?php
require_once "../config/db.php";
session_start();

/*
    Modo prueba: omite restricción de horario para poder simular pedidos
*/
$stmtMP = $conexion->prepare("SELECT valor FROM configuracion WHERE clave = 'modo_prueba' LIMIT 1");
$stmtMP->execute();
$modoPrueba = (int)($stmtMP->fetchColumn() ?? 0);

/*
    1. Obtener menú activo
*/
if ($modoPrueba) {
    $sqlMenu = "SELECT *
                FROM menu_dia
                WHERE activo = 1
                ORDER BY fecha DESC
                LIMIT 1";
} else {
    $sqlMenu = "SELECT *
                FROM menu_dia
                WHERE activo = 1
                  AND publicado_desde IS NOT NULL
                  AND pedido_hasta IS NOT NULL
                  AND NOW() BETWEEN publicado_desde AND pedido_hasta
                ORDER BY fecha ASC
                LIMIT 1";
}
$stmtMenu = $conexion->prepare($sqlMenu);
$stmtMenu->execute();
$menuActivo = $stmtMenu->fetch(PDO::FETCH_ASSOC);

if (!$menuActivo) {
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Sin menú disponible | Zabisu</title>
        <link rel="icon" type="image/png" href="../assets/img/LOGO_NARA.png">
    <link rel="manifest" href="../manifest.json">
    <meta name="theme-color" content="#FF7A00">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Zabisu">
    <link rel="apple-touch-icon" href="../assets/img/LOGO_NARA.png">
    <link rel="stylesheet" href="../assets/css/styles.css">
    </head>
    <body>
        <div class="contenedor">
            <div class="hero-zabisu">
                <div class="hero-zabisu__glow"></div>
                <div class="hero-zabisu__contenido">
                    <p class="hero-zabisu__eyebrow">ZABISU</p>
                    <h1 class="hero-zabisu__titulo">Por el momento no hay menú disponible</h1>
                    <p class="hero-zabisu__texto">
                        Los pedidos están disponibles únicamente cuando el menú del día se encuentra publicado dentro del horario correspondiente.
                    </p>
                </div>
            </div>

            <div class="bloque-formulario resumen-total">
                <h2>Horario de pedidos</h2>
                <p class="nota-formulario">
                    Los menús se publican conforme al calendario operativo de Zabisu. Revisa nuevamente dentro del horario de apertura del siguiente menú.
                </p>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

/*
    Verificar si se están recibiendo pedidos
*/
$stmtConfig = $conexion->prepare("SELECT valor FROM configuracion WHERE clave = 'recibir_pedidos' LIMIT 1");
$stmtConfig->execute();
$recibiendoPedidos = (int)($stmtConfig->fetchColumn() ?? 1);

if (!$recibiendoPedidos) {
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Pedidos pausados | Zabisu</title>
        <link rel="icon" type="image/png" href="../assets/img/LOGO_NARA.png">
    <link rel="manifest" href="../manifest.json">
    <meta name="theme-color" content="#FF7A00">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Zabisu">
    <link rel="apple-touch-icon" href="../assets/img/LOGO_NARA.png">
    <link rel="stylesheet" href="../assets/css/styles.css">
    </head>
    <body>
    <div class="contenedor">
        <div class="md-hero">
            <div class="md-hero__glow-top"></div>
            <div class="md-hero__glow-bottom"></div>
            <p class="md-hero__eyebrow">ZABISU</p>
            <div class="md-hero__marca-grupo">
                <img class="md-hero__logo" src="../assets/img/LOGO_BLANCO.png" alt="Zabisu">
                <h1 class="md-hero__marca">Zabisu</h1>
            </div>
            <p class="md-hero__fecha">Pedidos temporalmente pausados</p>
        </div>
        <div class="bloque-formulario resumen-total">
            <h2>No estamos recibiendo pedidos</h2>
            <p class="nota-formulario">
                En este momento no estamos aceptando nuevos pedidos. Por favor intenta más tarde o contáctanos directamente.
            </p>
            <a href="menu.php" class="btn-link">← Ver menú del día</a>
        </div>
    </div>
    </body>
    </html>
    <?php
    exit;
}

setlocale(LC_TIME, 'es_MX.UTF-8', 'es_ES.UTF-8', 'spanish');

$fechaMenuFormateada = "";
if (!empty($menuActivo["fecha"])) {
    $timestampMenu = strtotime($menuActivo["fecha"]);

    $dias = [
        'Sunday' => 'domingo', 'Monday' => 'lunes', 'Tuesday' => 'martes',
        'Wednesday' => 'miércoles', 'Thursday' => 'jueves', 'Friday' => 'viernes', 'Saturday' => 'sábado'
    ];

    $meses = [
        '01' => 'enero', '02' => 'febrero', '03' => 'marzo', '04' => 'abril',
        '05' => 'mayo', '06' => 'junio', '07' => 'julio', '08' => 'agosto',
        '09' => 'septiembre', '10' => 'octubre', '11' => 'noviembre', '12' => 'diciembre'
    ];

    $diaSemanaIngles = date('l', $timestampMenu);
    $diaSemana = $dias[$diaSemanaIngles] ?? strtolower($diaSemanaIngles);
    $dia = date('j', $timestampMenu);
    $mes = $meses[date('m', $timestampMenu)] ?? date('m', $timestampMenu);

    $fechaMenuFormateada = "Menú del día: {$diaSemana} {$dia} de {$mes}.";
}

/*
    2. Obtener productos del menú activo
*/
$sqlProductos = "SELECT * FROM productos
                 WHERE id_menu = :id_menu
                 AND disponible = 1
                 ORDER BY tipo_menu, categoria, nombre";
$stmtProductos = $conexion->prepare($sqlProductos);
$stmtProductos->bindParam(":id_menu", $menuActivo["id_menu"], PDO::PARAM_INT);
$stmtProductos->execute();
$productos = $stmtProductos->fetchAll(PDO::FETCH_ASSOC);

/*
    2.1 Obtener conteo de pedidos por producto del menú activo
*/
$sqlConteoProductos = "SELECT
                           dp.id_producto,
                           COUNT(*) AS total_pedidos
                       FROM detalle_pedido dp
                       INNER JOIN pedido_menus pm ON dp.id_pedido_menu = pm.id_pedido_menu
                       INNER JOIN pedidos p ON pm.id_pedido = p.id_pedido
                       INNER JOIN productos pr ON dp.id_producto = pr.id_producto
                       WHERE p.estado != 'Cancelado'
                         AND pr.id_menu = :id_menu_activo
                       GROUP BY dp.id_producto";
$stmtConteoProductos = $conexion->prepare($sqlConteoProductos);
$stmtConteoProductos->bindParam(":id_menu_activo", $menuActivo["id_menu"], PDO::PARAM_INT);
$stmtConteoProductos->execute();
$conteosProductosDB = $stmtConteoProductos->fetchAll(PDO::FETCH_ASSOC);

$conteosProductos = [];
foreach ($conteosProductosDB as $fila) {
    $conteosProductos[$fila["id_producto"]] = (int)$fila["total_pedidos"];
}

/*
    3. Organizar productos por tipo de menú y categoría
*/
$menusPorTipo = [];
$productosIndexados = [];

foreach ($productos as $producto) {
    $idProducto = (int)$producto["id_producto"];
    $tipoMenu = $producto["tipo_menu"];
    $categoria = $producto["categoria"];

    $totalPedidosActuales = $conteosProductos[$idProducto] ?? 0;
    $limitePedidos = isset($producto["limite_pedidos"]) ? (int)$producto["limite_pedidos"] : 0;

    $producto["agotado"] = false;

    if (
        $categoria === "Plato fuerte" &&
        !empty($producto["limite_pedidos"]) &&
        $totalPedidosActuales >= $limitePedidos
    ) {
        $producto["agotado"] = true;
    }

    $menusPorTipo[$tipoMenu][$categoria][] = $producto;
    $productosIndexados[$idProducto] = $producto;
}

/*
    4. Obtener ubicaciones activas
*/
$sqlUbicaciones = "SELECT * FROM ubicaciones WHERE activo = 1 ORDER BY tipo, nombre_ubicacion";
$stmtUbicaciones = $conexion->prepare($sqlUbicaciones);
$stmtUbicaciones->execute();
$ubicaciones = $stmtUbicaciones->fetchAll(PDO::FETCH_ASSOC);

/*
    5. Obtener horarios activos
*/
$sqlHorarios = "SELECT
                    h.id_horario,
                    h.hora_entrega,
                    u.id_ubicacion,
                    u.nombre_ubicacion,
                    u.tipo
                FROM horarios_ubicacion h
                INNER JOIN ubicaciones u ON h.id_ubicacion = u.id_ubicacion
                WHERE h.activo = 1 AND u.activo = 1
                ORDER BY u.tipo, u.nombre_ubicacion, h.hora_entrega";
$stmtHorarios = $conexion->prepare($sqlHorarios);
$stmtHorarios->execute();
$horarios = $stmtHorarios->fetchAll(PDO::FETCH_ASSOC);

/*
    6. Obtener precios de tipos de menú
*/
$sqlTiposMenu = "SELECT nombre_menu, precio FROM tipos_menu WHERE activo = 1";
$stmtTiposMenu = $conexion->prepare($sqlTiposMenu);
$stmtTiposMenu->execute();
$tiposMenuDB = $stmtTiposMenu->fetchAll(PDO::FETCH_ASSOC);

$preciosMenus = [];
foreach ($tiposMenuDB as $tipo) {
    $preciosMenus[$tipo["nombre_menu"]] = (float)$tipo["precio"];
}

/*
    7. Cantidad de menús
*/
$cantidadMenus = isset($_POST["cantidad_menus"]) ? (int)$_POST["cantidad_menus"] : 1;
if ($cantidadMenus < 1) $cantidadMenus = 1;
if ($cantidadMenus > 5) $cantidadMenus = 5;

/*
    8. Variables de control
*/
$errores = [];
$erroresMenus = [];
$scrollDestino = "";

/*
    9. Procesar formulario
*/
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["guardar_pedido"])) {
    $nombre_cliente  = trim($_POST["nombre_cliente"] ?? "");
    $telefono        = trim($_POST["telefono"] ?? "");
    $correo_cliente  = trim($_POST["correo_cliente"] ?? "");
    $observaciones   = trim($_POST["observaciones"] ?? "");
    $id_horario      = $_POST["id_horario"] ?? "";
    $menusRecibidos  = $_POST["menus"] ?? [];

    if ($nombre_cliente === "") {
        $errores[] = "El nombre del cliente es obligatorio.";
    } elseif (!preg_match('/^[\p{L}\s]+$/u', $nombre_cliente)) {
        $errores[] = "El nombre solo puede contener letras y espacios.";
    }

    if ($telefono === "") {
        $errores[] = "El teléfono es obligatorio.";
    } elseif (!preg_match('/^\d{10}$/', $telefono)) {
        $errores[] = "El teléfono debe contener exactamente 10 dígitos.";
    }

    if ($correo_cliente === "") {
        $errores[] = "El correo electrónico es obligatorio.";
    } elseif (!filter_var($correo_cliente, FILTER_VALIDATE_EMAIL)) {
        $errores[] = "Debes capturar un correo electrónico válido.";
    }

    if (empty($menusRecibidos)) {
        $errores[] = "Debes capturar al menos un menú.";
    }

    if ($id_horario === "") {
        $errores[] = "Debes seleccionar un horario.";
        if ($scrollDestino === "") $scrollDestino = "bloque-entrega";
    }

    foreach ($menusRecibidos as $numeroMenu => $menu) {
        $tipo_menu   = $menu["tipo_menu"] ?? "";
        $plato_fuerte = $menu["plato_fuerte"] ?? "";
        $sopa        = $menu["sopa"] ?? "";
        $agua        = $menu["agua"] ?? "";
        $postre      = $menu["postre"] ?? "";
        $complementos = $menu["complementos"] ?? [];

        if ($tipo_menu === "" || !in_array($tipo_menu, ["Zabisu", "Ejecutivo"], true)) {
            $erroresMenus[$numeroMenu][] = "Selecciona un tipo de menú válido.";
        }
        if ($plato_fuerte === "") {
            $erroresMenus[$numeroMenu][] = "Falta seleccionar el plato fuerte.";
        } elseif (isset($productosIndexados[$plato_fuerte]) && !empty($productosIndexados[$plato_fuerte]["agotado"])) {
            $erroresMenus[$numeroMenu][] = "El plato fuerte seleccionado ya está agotado. Por favor elige otro.";
        }
        if ($sopa === "")         $erroresMenus[$numeroMenu][] = "Falta seleccionar la sopa.";
        if ($agua === "")         $erroresMenus[$numeroMenu][] = "Falta seleccionar el agua.";
        if ($postre === "")       $erroresMenus[$numeroMenu][] = "Falta seleccionar el postre.";
        if (empty($complementos)) $erroresMenus[$numeroMenu][] = "Falta seleccionar al menos un complemento.";
        if (count($complementos) > 2) $erroresMenus[$numeroMenu][] = "Solo puedes elegir hasta 2 complementos.";

        $productosAValidar = array_filter(
            array_merge([$plato_fuerte, $sopa, $agua, $postre], $complementos),
            fn($v) => $v !== null && $v !== ""
        );

        foreach ($productosAValidar as $idProducto) {
            if (!isset($productosIndexados[$idProducto])) {
                $erroresMenus[$numeroMenu][] = "Uno de los productos seleccionados no es válido.";
                continue;
            }
            if ($productosIndexados[$idProducto]["tipo_menu"] !== $tipo_menu) {
                $erroresMenus[$numeroMenu][] = "Hay un producto que no corresponde al tipo de menú seleccionado.";
            }
        }
    }

    if (!empty($erroresMenus)) {
        foreach ($erroresMenus as $numeroMenu => $listaErrores) {
            foreach ($listaErrores as $mensaje) {
                $errores[] = "Menú {$numeroMenu}: {$mensaje}";
            }
        }
        if ($scrollDestino === "") {
            $scrollDestino = "menu-bloque-" . array_key_first($erroresMenus);
        }
    }

    if (empty($errores)) {
        $totalPedido = 0.00;
        foreach ($menusRecibidos as $menu) {
            $totalPedido += $preciosMenus[$menu["tipo_menu"] ?? ""] ?? 0;
        }

        $extrasSeleccionados = [];
        $PRECIOS_EXTRA_SERVER = ["Sopa" => 25.00, "Complemento" => 25.00, "Agua" => 20.00];
        foreach ($_POST["extras"] ?? [] as $idProducto => $cantidad) {
            $cantidad = min((int)$cantidad, 5);
            if ($cantidad <= 0 || !isset($productosIndexados[$idProducto])) continue;
            $prod        = $productosIndexados[$idProducto];
            $precioExtra = $PRECIOS_EXTRA_SERVER[$prod["categoria"]] ?? 25.00;
            $extrasSeleccionados[] = [
                "id_producto"     => (int)$idProducto,
                "nombre"          => $prod["nombre"],
                "categoria"       => $prod["categoria"],
                "cantidad"        => $cantidad,
                "precio_unitario" => $precioExtra,
            ];
            $totalPedido += $cantidad * $precioExtra;
        }

        $_SESSION["pedido_temporal"] = [
            "nombre_cliente"  => $nombre_cliente,
            "telefono"        => $telefono,
            "correo_cliente"  => $correo_cliente,
            "observaciones"   => $observaciones,
            "id_horario"      => $id_horario,
            "menus"           => $menusRecibidos,
            "extras"          => $extrasSeleccionados,
            "total"           => $totalPedido,
            "fecha_menu"      => $menuActivo["fecha"] ?? null,
            "creado_en"       => date("Y-m-d H:i:s")
        ];

        header("Location: pago.php");
        exit;
    }
}

/*
    10. Determinar paso inicial del stepper
*/
$pasoInicial = 1;
if ($scrollDestino === "bloque-entrega") {
    $pasoInicial = 3;
} elseif (!empty($erroresMenus) || strpos($scrollDestino, "menu-bloque-") === 0) {
    $pasoInicial = 2;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Realizar pedido | Zabisu</title>
    <link rel="icon" type="image/png" href="../assets/img/LOGO_NARA.png">
    <link rel="manifest" href="../manifest.json">
    <meta name="theme-color" content="#FF7A00">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Zabisu">
    <link rel="apple-touch-icon" href="../assets/img/LOGO_NARA.png">
    <link rel="stylesheet" href="../assets/css/styles.css">
</head>
<body>

<div class="contenedor">

    <div class="md-hero">
        <div class="md-hero__glow-top"></div>
        <div class="md-hero__glow-bottom"></div>
        <p class="md-hero__eyebrow">Pedido del día</p>
        <div class="md-hero__marca-grupo">
            <img class="md-hero__logo" src="../assets/img/LOGO_BLANCO.png" alt="Zabisu">
            <h1 class="md-hero__marca">Zabisu</h1>
        </div>
        <p class="md-hero__fecha"><?php echo htmlspecialchars($fechaMenuFormateada); ?></p>
        <a href="menu.php" class="btn-volver-panel">← Ver menú del día</a>
    </div>

    <?php if (!empty($errores)): ?>
        <div class="mensaje-error">
            <ul>
                <?php foreach ($errores as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <!-- Stepper indicator -->
    <div class="stepper-indicador">
        <div class="stepper-paso-item" data-paso="1">
            <div class="stepper-circulo">1</div>
            <span class="stepper-etiqueta">Tus datos</span>
        </div>
        <div class="stepper-linea"></div>
        <div class="stepper-paso-item" data-paso="2">
            <div class="stepper-circulo">2</div>
            <span class="stepper-etiqueta">Tu menú</span>
        </div>
        <div class="stepper-linea"></div>
        <div class="stepper-paso-item" data-paso="3">
            <div class="stepper-circulo">3</div>
            <span class="stepper-etiqueta">Entrega</span>
        </div>
        <div class="stepper-linea"></div>
        <div class="stepper-paso-item" data-paso="4">
            <div class="stepper-circulo">4</div>
            <span class="stepper-etiqueta">Confirmar</span>
        </div>
    </div>

    <form action="" method="POST" class="formulario-pedido" id="formulario-pedido">

        <!-- ======================================================
             PASO 1: DATOS DEL CLIENTE
        ====================================================== -->
        <div class="paso-stepper" data-paso="1">
            <div class="paso-stepper__errores"></div>

            <section class="bloque-formulario" id="bloque-datos-cliente">
                <h2>Tus datos</h2>

                <label for="nombre_cliente">Nombre completo</label>
                <input type="text" name="nombre_cliente" id="nombre_cliente"
                       maxlength="100" autocomplete="name"
                       value="<?php echo htmlspecialchars($_POST["nombre_cliente"] ?? ""); ?>">

                <label for="telefono">Teléfono</label>
                <input type="text" name="telefono" id="telefono"
                       maxlength="10" inputmode="numeric" autocomplete="tel"
                       value="<?php echo htmlspecialchars($_POST["telefono"] ?? ""); ?>">

                <label for="correo_cliente">Correo electrónico</label>
                <input type="email" name="correo_cliente" id="correo_cliente"
                       maxlength="150" autocomplete="email"
                       value="<?php echo htmlspecialchars($_POST["correo_cliente"] ?? ""); ?>">
                <p class="nota-formulario nota-correo">
                    ✉️ Aquí recibirás la confirmación y todos los detalles de tu pedido. Asegúrate de que sea correcto.
                </p>
            </section>

            <div class="stepper-nav">
                <div></div>
                <button type="button" class="btn-stepper btn-stepper--siguiente" data-siguiente="2">
                    Siguiente →
                </button>
            </div>
        </div>

        <!-- ======================================================
             PASO 2: MENÚS
        ====================================================== -->
        <div class="paso-stepper" data-paso="2">
            <div class="paso-stepper__errores"></div>

            <section class="bloque-formulario" id="bloque-cantidad-menus">
                <h2>Tu menú</h2>

                <label for="cantidad_menus">¿Cuántos menús vas a pedir?</label>
                <select name="cantidad_menus" id="cantidad_menus">
                    <?php for ($n = 1; $n <= 5; $n++): ?>
                        <option value="<?php echo $n; ?>" <?php echo ($cantidadMenus === $n) ? "selected" : ""; ?>>
                            <?php echo $n; ?> menú<?php echo $n > 1 ? "s" : ""; ?>
                        </option>
                    <?php endfor; ?>
                </select>

                <p class="nota-formulario">
                    Elige cuántos menús quieres incluir en este pedido. Después podrás configurar cada uno por separado.
                </p>
            </section>

            <?php for ($i = 1; $i <= 5; $i++):
                $tipoSeleccionado = $_POST["menus"][$i]["tipo_menu"] ?? "Zabisu";
                $precioMenuActual = $preciosMenus[$tipoSeleccionado] ?? 0;
                $esVisible = $i <= $cantidadMenus;
            ?>
                <section class="bloque-formulario bloque-menu-individual <?php echo !empty($erroresMenus[$i]) ? 'bloque-con-error' : ''; ?>"
                         id="menu-bloque-<?php echo $i; ?>"
                         <?php echo !$esVisible ? 'style="display:none;"' : ''; ?>>

                    <h2>Menú <?php echo $i; ?></h2>

                    <?php if (!empty($erroresMenus[$i])): ?>
                        <div class="mensaje-error-menu">
                            <ul>
                                <?php foreach ($erroresMenus[$i] as $errorMenu): ?>
                                    <li><?php echo htmlspecialchars($errorMenu); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <label for="tipo_menu_<?php echo $i; ?>">Tipo de menú</label>
                    <select name="menus[<?php echo $i; ?>][tipo_menu]"
                            id="tipo_menu_<?php echo $i; ?>"
                            class="selector-tipo-menu"
                            data-menu="<?php echo $i; ?>"
                            onchange="actualizarOpcionesMenu(<?php echo $i; ?>)">
                        <option value="Zabisu" <?php echo ($tipoSeleccionado === "Zabisu") ? "selected" : ""; ?>>Menú Zabisu</option>
                        <option value="Ejecutivo" <?php echo ($tipoSeleccionado === "Ejecutivo") ? "selected" : ""; ?>>Menú Ejecutivo</option>
                    </select>

                    <p class="precio-menu" id="precio-menu-<?php echo $i; ?>">
                        Precio de este menú: <strong>$<?php echo number_format($precioMenuActual, 2); ?></strong>
                    </p>

                    <?php foreach (["Zabisu", "Ejecutivo"] as $tipoMenu): ?>
                        <div class="opciones-menu-tipo menu-<?php echo $i; ?>-tipo"
                             data-menu="<?php echo $i; ?>"
                             data-tipo="<?php echo $tipoMenu; ?>"
                             style="<?php echo ($tipoSeleccionado === $tipoMenu) ? 'display:block;' : 'display:none;'; ?>">

                            <?php if (!empty($menusPorTipo[$tipoMenu]["Plato fuerte"])): ?>
                                <div class="grupo-categoria">
                                    <h3>🍽️ Plato fuerte <span class="cat-hint">· elige 1</span></h3>
                                    <?php foreach ($menusPorTipo[$tipoMenu]["Plato fuerte"] as $item): ?>
                                        <label class="opcion-producto <?php echo !empty($item["agotado"]) ? 'producto-agotado' : ''; ?>">
                                            <input type="radio"
                                                   name="menus[<?php echo $i; ?>][plato_fuerte]"
                                                   value="<?php echo $item["id_producto"]; ?>"
                                                   <?php echo (($_POST["menus"][$i]["plato_fuerte"] ?? "") == $item["id_producto"]) ? "checked" : ""; ?>
                                                   <?php echo !empty($item["agotado"]) ? 'disabled' : ''; ?>>
                                            <strong><?php echo htmlspecialchars($item["nombre"]); ?></strong>
                                            — <?php echo htmlspecialchars($item["descripcion"]); ?>
                                            <?php if (!empty($item["agotado"])): ?>
                                                <span class="badge-agotado">Agotado</span>
                                            <?php endif; ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($menusPorTipo[$tipoMenu]["Sopa"])): ?>
                                <div class="grupo-categoria">
                                    <h3>🥣 Sopa <span class="cat-hint">· elige 1</span></h3>
                                    <?php foreach ($menusPorTipo[$tipoMenu]["Sopa"] as $item): ?>
                                        <label class="opcion-producto">
                                            <input type="radio"
                                                   name="menus[<?php echo $i; ?>][sopa]"
                                                   value="<?php echo $item["id_producto"]; ?>"
                                                   <?php echo (($_POST["menus"][$i]["sopa"] ?? "") == $item["id_producto"]) ? "checked" : ""; ?>>
                                            <strong><?php echo htmlspecialchars($item["nombre"]); ?></strong> —
                                            <?php echo htmlspecialchars($item["descripcion"]); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($menusPorTipo[$tipoMenu]["Complemento"])): ?>
                                <div class="grupo-categoria">
                                    <h3>🥗 Complementos <span class="cat-hint">· elige hasta 2</span></h3>
                                    <?php foreach ($menusPorTipo[$tipoMenu]["Complemento"] as $item): ?>
                                        <label class="opcion-producto">
                                            <input type="checkbox"
                                                   name="menus[<?php echo $i; ?>][complementos][]"
                                                   value="<?php echo $item["id_producto"]; ?>"
                                                   <?php echo (in_array($item["id_producto"], $_POST["menus"][$i]["complementos"] ?? [])) ? "checked" : ""; ?>>
                                            <strong><?php echo htmlspecialchars($item["nombre"]); ?></strong> —
                                            <?php echo htmlspecialchars($item["descripcion"]); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($menusPorTipo[$tipoMenu]["Agua"])): ?>
                                <div class="grupo-categoria">
                                    <h3>💧 Agua <span class="cat-hint">· elige 1</span></h3>
                                    <?php foreach ($menusPorTipo[$tipoMenu]["Agua"] as $item): ?>
                                        <label class="opcion-producto">
                                            <input type="radio"
                                                   name="menus[<?php echo $i; ?>][agua]"
                                                   value="<?php echo $item["id_producto"]; ?>"
                                                   <?php echo (($_POST["menus"][$i]["agua"] ?? "") == $item["id_producto"]) ? "checked" : ""; ?>>
                                            <strong><?php echo htmlspecialchars($item["nombre"]); ?></strong> —
                                            <?php echo htmlspecialchars($item["descripcion"]); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($menusPorTipo[$tipoMenu]["Postre"])): ?>
                                <div class="grupo-categoria">
                                    <h3>🍮 Postre <span class="cat-hint">· elige 1</span></h3>
                                    <?php foreach ($menusPorTipo[$tipoMenu]["Postre"] as $item): ?>
                                        <label class="opcion-producto">
                                            <input type="radio"
                                                   name="menus[<?php echo $i; ?>][postre]"
                                                   value="<?php echo $item["id_producto"]; ?>"
                                                   <?php echo (($_POST["menus"][$i]["postre"] ?? "") == $item["id_producto"]) ? "checked" : ""; ?>>
                                            <strong><?php echo htmlspecialchars($item["nombre"]); ?></strong> —
                                            <?php echo htmlspecialchars($item["descripcion"]); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                        </div>
                    <?php endforeach; ?>
                </section>
            <?php endfor; ?>

            <?php
            // Extras disponibles (deduplicados por nombre)
            $PRECIOS_EXTRA = ["Sopa" => 25, "Complemento" => 25, "Agua" => 20];
            $extrasForm        = [];
            $nombresExtrasForm = [];
            foreach (["Sopa", "Complemento", "Agua"] as $catEx) {
                foreach ($menusPorTipo as $tipoMenu => $cats) {
                    if (!empty($cats[$catEx])) {
                        foreach ($cats[$catEx] as $prod) {
                            if (!in_array($prod["nombre"], $nombresExtrasForm)) {
                                $nombresExtrasForm[] = $prod["nombre"];
                                $extrasForm[$catEx][] = $prod;
                            }
                        }
                    }
                }
            }
            $iconosExtra = ["Sopa" => "🥣", "Complemento" => "🥗", "Agua" => "💧"];
            ?>

            <section class="bloque-formulario" id="bloque-extras">
                <h2>Extras</h2>
                <button type="button" class="extras-toggle" id="btn-extras-toggle">
                    <span class="extras-toggle__icono">+</span>
                    <span class="extras-toggle__texto">¿Quieres agregar algo extra?</span>
                </button>

                <div class="extras-contenido" id="extras-contenido" style="display:none;">
                    <?php foreach ($extrasForm as $cat => $items): ?>
                    <div class="grupo-categoria">
                        <h3><?php echo $iconosExtra[$cat]; ?> <?php echo htmlspecialchars($cat === "Complemento" ? "Complemento extra" : $cat . " extra"); ?></h3>
                        <?php foreach ($items as $item): ?>
                        <?php $precioItem = $PRECIOS_EXTRA[$cat] ?? 25; ?>
                        <div class="extra-item <?php echo (int)($_POST["extras"][$item["id_producto"]] ?? 0) > 0 ? 'extra-item--activo' : ''; ?>" data-precio="<?php echo $precioItem; ?>">
                            <span class="extra-item__nombre"><?php echo htmlspecialchars($item["nombre"]); ?></span>
                            <span class="extra-item__precio-hint">$<?php echo $precioItem; ?> c/u</span>
                            <div class="extra-item__contador">
                                <button type="button" class="extra-btn-menos">−</button>
                                <input type="number"
                                       name="extras[<?php echo (int)$item["id_producto"]; ?>]"
                                       class="extra-cantidad"
                                       value="<?php echo (int)($_POST["extras"][$item["id_producto"]] ?? 0); ?>"
                                       min="0" max="5" readonly>
                                <button type="button" class="extra-btn-mas">+</button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <div class="stepper-nav">
                <button type="button" class="btn-stepper btn-stepper--anterior" data-anterior="1">← Anterior</button>
                <button type="button" class="btn-stepper btn-stepper--siguiente" data-siguiente="3">Siguiente →</button>
            </div>
        </div>

        <!-- ======================================================
             PASO 3: ENTREGA
        ====================================================== -->
        <div class="paso-stepper" data-paso="3">
            <div class="paso-stepper__errores"></div>

            <section class="bloque-formulario" id="bloque-entrega">
                <h2>¿Cómo recibirás tu pedido?</h2>

                <?php /* PICKUP TEMPORALMENTE DESHABILITADO — para reactivar, cambia "if (false)" por "if (true)" */ ?>
                <?php if (false): ?>
                <div class="grupo-tipo-ubicacion">
                    <h3 class="titulo-tipo-ubicacion">Pickup</h3>
                    <?php foreach ($ubicaciones as $ubicacion): ?>
                        <?php if ($ubicacion["tipo"] === "pickup"): ?>
                            <div class="grupo-ubicacion-selector">
                                <label class="opcion-ubicacion pickup">
                                    <input type="radio"
                                           name="ubicacion_selector"
                                           value="<?php echo $ubicacion["id_ubicacion"]; ?>"
                                           class="radio-ubicacion">
                                    <strong><?php echo htmlspecialchars($ubicacion["nombre_ubicacion"]); ?></strong>
                                </label>
                                <div class="horarios-ocultos oculto" id="horarios-<?php echo $ubicacion["id_ubicacion"]; ?>">
                                    <?php foreach ($horarios as $horario): ?>
                                        <?php if ($horario["id_ubicacion"] == $ubicacion["id_ubicacion"]): ?>
                                            <label class="opcion-horario">
                                                <input type="radio"
                                                       name="id_horario"
                                                       value="<?php echo $horario["id_horario"]; ?>"
                                                       <?php echo (($_POST["id_horario"] ?? "") == $horario["id_horario"]) ? "checked" : ""; ?>>
                                                <?php echo date("g:i A", strtotime($horario["hora_entrega"])); ?>
                                            </label>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <div class="grupo-tipo-ubicacion">
                    <h3 class="titulo-tipo-ubicacion">Entregas</h3>
                    <?php foreach ($ubicaciones as $ubicacion): ?>
                        <?php if ($ubicacion["tipo"] === "entrega"): ?>
                            <div class="grupo-ubicacion-selector">
                                <label class="opcion-ubicacion entrega">
                                    <input type="radio"
                                           name="ubicacion_selector"
                                           value="<?php echo $ubicacion["id_ubicacion"]; ?>"
                                           class="radio-ubicacion">
                                    <strong><?php echo htmlspecialchars($ubicacion["nombre_ubicacion"]); ?></strong>
                                </label>
                                <div class="horarios-ocultos oculto" id="horarios-<?php echo $ubicacion["id_ubicacion"]; ?>">
                                    <?php foreach ($horarios as $horario): ?>
                                        <?php if ($horario["id_ubicacion"] == $ubicacion["id_ubicacion"]): ?>
                                            <label class="opcion-horario">
                                                <input type="radio"
                                                       name="id_horario"
                                                       value="<?php echo $horario["id_horario"]; ?>"
                                                       <?php echo (($_POST["id_horario"] ?? "") == $horario["id_horario"]) ? "checked" : ""; ?>>
                                                <?php echo date("g:i A", strtotime($horario["hora_entrega"])); ?>
                                            </label>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </section>

            <div class="stepper-nav">
                <button type="button" class="btn-stepper btn-stepper--anterior" data-anterior="2">← Anterior</button>
                <button type="button" class="btn-stepper btn-stepper--siguiente" data-siguiente="4">Siguiente →</button>
            </div>
        </div>

        <!-- ======================================================
             PASO 4: RESUMEN Y CONFIRMAR
        ====================================================== -->
        <div class="paso-stepper" data-paso="4">

            <div class="bloque-formulario resumen-total" id="bloque-resumen">
                <h2>Resumen del pedido</h2>
                <div id="resumen-menus" class="ticket-resumen">
                    <p class="ticket-vacio">Selecciona tus menús para ver el resumen.</p>
                </div>
                <div class="ticket-total">
                    <span>Total estimado</span>
                    <strong id="total-general">$0.00</strong>
                </div>
            </div>

            <section class="bloque-formulario" id="bloque-continuar">
                <h2>Observaciones y confirmar</h2>

                <label for="observaciones">Observaciones</label>
                <textarea name="observaciones" id="observaciones" rows="4"
                          placeholder="Ej. sin cubiertos..."><?php echo htmlspecialchars($_POST["observaciones"] ?? ""); ?></textarea>

                <p class="nota-formulario">
                    En el siguiente paso podrás seleccionar tu método de pago y finalizar tu pedido.
                </p>

                <button type="submit" name="guardar_pedido" value="1" class="btn-principal">
                    Continuar al pago
                </button>
            </section>

            <div class="stepper-nav">
                <button type="button" class="btn-stepper btn-stepper--anterior" data-anterior="3">← Anterior</button>
                <div></div>
            </div>
        </div>

    </form>
</div>

<script>
document.addEventListener("DOMContentLoaded", function () {

    // ============================================================
    // STEPPER
    // ============================================================
    let pasoActual = <?php echo (int)$pasoInicial; ?>;

    function irAPaso(n) {
        document.querySelectorAll(".paso-stepper").forEach(function (p) {
            p.classList.remove("paso-stepper--activo");
        });

        const pasoEl = document.querySelector(".paso-stepper[data-paso='" + n + "']");
        if (pasoEl) pasoEl.classList.add("paso-stepper--activo");

        document.querySelectorAll(".stepper-paso-item").forEach(function (item) {
            const num = parseInt(item.dataset.paso);
            const circulo = item.querySelector(".stepper-circulo");
            item.classList.remove("activo", "completado");

            if (num === n) {
                item.classList.add("activo");
                circulo.textContent = num;
            } else if (num < n) {
                item.classList.add("completado");
                circulo.textContent = "✓";
            } else {
                circulo.textContent = num;
            }
        });

        pasoActual = n;
        window.scrollTo({ top: 0, behavior: "smooth" });

        if (n === 3) inicializarEntrega();
        if (n === 4) actualizarResumenTotal();
    }

    // ── Toast ─────────────────────────────────────────────────
    var stack = document.getElementById("pedido-toast-stack");

    function mostrarToast(mensajes) {
        if (!stack) return;

        // Limpiar toasts anteriores
        stack.innerHTML = "";

        mensajes.forEach(function (m, idx) {
            var item = document.createElement("div");
            item.className = "pedido-toast-item";
            item.innerHTML =
                "<span class='pedido-toast-item__icono'>⚠️</span>" +
                "<span class='pedido-toast-item__texto'>" + m + "</span>";
            stack.appendChild(item);

            // Animar entrada con pequeño delay escalonado
            setTimeout(function () {
                item.classList.add("pedido-toast-item--visible");
            }, idx * 80);

            // Salida automática
            setTimeout(function () {
                item.classList.remove("pedido-toast-item--visible");
                setTimeout(function () { if (item.parentNode) item.parentNode.removeChild(item); }, 250);
            }, 3500 + idx * 80);
        });
    }

    function mostrarErroresPaso(mensajes, pasoNum) {
        const contenedor = document.querySelector(".paso-stepper[data-paso='" + pasoNum + "'] .paso-stepper__errores");
        if (contenedor) {
            contenedor.style.display = "none";
            contenedor.innerHTML = "";
        }
        if (mensajes.length) mostrarToast(mensajes);
    }

    function validarPaso(n) {
        const errores = [];

        if (n === 1) {
            const nombre = (document.getElementById("nombre_cliente").value || "").trim();
            const telefono = (document.getElementById("telefono").value || "").trim();
            const correo = (document.getElementById("correo_cliente").value || "").trim();

            if (!nombre) {
                errores.push("El nombre es obligatorio.");
            } else if (!/^[\p{L}\s]+$/u.test(nombre)) {
                errores.push("El nombre solo puede contener letras y espacios.");
            }

            if (!telefono) {
                errores.push("El teléfono es obligatorio.");
            } else if (!/^\d{10}$/.test(telefono)) {
                errores.push("El teléfono debe tener exactamente 10 dígitos.");
            }

            if (!correo) {
                errores.push("El correo electrónico es obligatorio.");
            } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(correo)) {
                errores.push("Captura un correo electrónico válido.");
            }
        }

        if (n === 2) {
            const cantidad = parseInt(document.getElementById("cantidad_menus").value) || 1;

            for (let i = 1; i <= cantidad; i++) {
                const bloque = document.getElementById("menu-bloque-" + i);
                if (!bloque || bloque.style.display === "none") continue;

                const plato = bloque.querySelector("input[name='menus[" + i + "][plato_fuerte]']:checked:not([disabled])");
                const sopa = bloque.querySelector("input[name='menus[" + i + "][sopa]']:checked:not([disabled])");
                const agua = bloque.querySelector("input[name='menus[" + i + "][agua]']:checked:not([disabled])");
                const postre = bloque.querySelector("input[name='menus[" + i + "][postre]']:checked:not([disabled])");
                const complementos = bloque.querySelectorAll("input[name='menus[" + i + "][complementos][]']:checked:not([disabled])");

                if (!plato) errores.push("Menú " + i + ": falta el plato fuerte.");
                if (!sopa) errores.push("Menú " + i + ": falta la sopa.");
                if (!agua) errores.push("Menú " + i + ": falta el agua.");
                if (!postre) errores.push("Menú " + i + ": falta el postre.");
                if (complementos.length === 0) errores.push("Menú " + i + ": falta al menos un complemento.");
                if (complementos.length > 2) errores.push("Menú " + i + ": máximo 2 complementos.");
            }
        }

        if (n === 3) {
            const horario = document.querySelector("input[name='id_horario']:checked");
            if (!horario) {
                errores.push("Selecciona una ubicación y horario de entrega.");
            }
        }

        mostrarErroresPaso(errores, n);
        return errores.length === 0;
    }

    document.querySelectorAll("[data-siguiente]").forEach(function (btn) {
        btn.addEventListener("click", function () {
            const siguiente = parseInt(this.dataset.siguiente);
            if (validarPaso(siguiente - 1)) {
                mostrarErroresPaso([], siguiente - 1);
                irAPaso(siguiente);
            }
        });
    });

    document.querySelectorAll("[data-anterior]").forEach(function (btn) {
        btn.addEventListener("click", function () {
            mostrarErroresPaso([], pasoActual);
            irAPaso(parseInt(this.dataset.anterior));
        });
    });

    irAPaso(pasoActual);

    // ============================================================
    // CANTIDAD DE MENÚS
    // ============================================================
    function actualizarBloquesMenues(cantidad) {
        for (let i = 1; i <= 5; i++) {
            const bloque = document.getElementById("menu-bloque-" + i);
            if (!bloque) continue;

            const visible = i <= cantidad;
            bloque.style.display = visible ? "" : "none";

            if (!visible) {
                bloque.querySelectorAll("input, select").forEach(function (input) {
                    if (!input.disabled) {
                        input.disabled = true;
                        input.dataset.stepperDisabled = "1";
                    }
                });
            } else {
                bloque.querySelectorAll("[data-stepper-disabled]").forEach(function (input) {
                    input.disabled = false;
                    delete input.dataset.stepperDisabled;
                });
            }
        }

        actualizarResumenTotal();
    }

    const selectorCantidadMenus = document.getElementById("cantidad_menus");
    if (selectorCantidadMenus) {
        selectorCantidadMenus.addEventListener("change", function () {
            actualizarBloquesMenues(parseInt(this.value) || 1);
        });
    }

    // ============================================================
    // NOMBRE / TELÉFONO
    // ============================================================
    const nombreInput = document.getElementById("nombre_cliente");
    if (nombreInput) {
        nombreInput.addEventListener("input", function () {
            this.value = this.value.replace(/[^\p{L}\s]/gu, "").toUpperCase();
        });
    }

    const telefonoInput = document.getElementById("telefono");
    if (telefonoInput) {
        telefonoInput.addEventListener("input", function () {
            this.value = this.value.replace(/\D/g, "").slice(0, 10);
        });
    }

    // Evitar que Enter envíe el form en pasos intermedios
    document.getElementById("formulario-pedido").addEventListener("keydown", function (e) {
        if (e.key !== "Enter") return;
        if (pasoActual >= 4) return; // en el último paso sí se permite submit
        e.preventDefault();
        var btnSig = document.querySelector(".paso-stepper[data-paso='" + pasoActual + "'] .btn-stepper--siguiente");
        if (btnSig) btnSig.click();
    });

    // ============================================================
    // UBICACIONES Y HORARIOS
    // Se inicializa al llegar al paso 3 (elementos ya visibles en ese momento)
    // ============================================================
    function inicializarEntrega() {
        // Ocultar todos via inline style (máxima especificidad, nada lo sobreescribe)
        document.querySelectorAll(".horarios-ocultos").forEach(function (b) {
            b.style.display = "none";
        });

        // Asignar onclick directamente en cada label de ubicación
        document.querySelectorAll(".opcion-ubicacion").forEach(function (label) {
            label.onclick = function () {
                // Marcar el radio asociado
                var radio = this.querySelector(".radio-ubicacion");
                if (radio) radio.checked = true;

                // Quitar estado activo de todos los labels
                document.querySelectorAll(".opcion-ubicacion").forEach(function (l) {
                    l.classList.remove("ubicacion-activa");
                });
                this.classList.add("ubicacion-activa");

                // Ocultar todos los horarios
                document.querySelectorAll(".horarios-ocultos").forEach(function (b) {
                    b.style.display = "none";
                });
                document.querySelectorAll("input[name='id_horario']").forEach(function (r) {
                    r.required = false;
                });

                // Mostrar solo el bloque de esta ubicación
                var grupo = this.closest(".grupo-ubicacion-selector");
                if (!grupo) return;
                var bloque = grupo.querySelector(".horarios-ocultos");
                if (!bloque) return;
                bloque.style.display = "block";
                bloque.querySelectorAll("input[name='id_horario']").forEach(function (r) {
                    r.required = true;
                });
            };
        });

        // Restaurar selección previa si hay datos de POST
        var horarioSeleccionado = document.querySelector("input[name='id_horario']:checked");
        if (horarioSeleccionado) {
            var contenedor = horarioSeleccionado.closest(".horarios-ocultos");
            if (contenedor) {
                contenedor.style.display = "block";
                contenedor.querySelectorAll("input[name='id_horario']").forEach(function (r) {
                    r.required = true;
                });
                var grupoRestore = contenedor.closest(".grupo-ubicacion-selector");
                if (grupoRestore) {
                    var radioUbicacion = grupoRestore.querySelector(".radio-ubicacion");
                    if (radioUbicacion) radioUbicacion.checked = true;
                    var labelRestore = grupoRestore.querySelector(".opcion-ubicacion");
                    if (labelRestore) labelRestore.classList.add("ubicacion-activa");
                }
            }
        }
    }

    // Si el paso inicial ya es 3 (vuelta al formulario por errores), inicializar de inmediato
    if (<?php echo $pasoInicial; ?> === 3) inicializarEntrega();

    // ============================================================
    // RESUMEN Y TOTAL
    // ============================================================
    const preciosMenus = {
        Zabisu: <?php echo json_encode($preciosMenus["Zabisu"] ?? 0); ?>,
        Ejecutivo: <?php echo json_encode($preciosMenus["Ejecutivo"] ?? 0); ?>
    };

    const selectoresTipoMenu = document.querySelectorAll(".selector-tipo-menu");
    const resumenMenus = document.getElementById("resumen-menus");
    const totalGeneral = document.getElementById("total-general");

    actualizarBloquesMenues(<?php echo (int)$cantidadMenus; ?>);

    function obtenerTextoSeleccionado(selector) {
        if (!selector) return "Sin seleccionar";

        const label = selector.closest("label");
        if (!label) return "Sin seleccionar";

        const strong = label.querySelector("strong");
        if (strong) {
            return strong.textContent.replace(/\s+/g, " ").trim();
        }

        const texto = label.textContent.replace(/\s+/g, " ").trim();
        return texto || "Sin seleccionar";
    }

    function actualizarResumenTotal() {
        let total = 0;
        let htmlResumen = "";

        selectoresTipoMenu.forEach(function (selector) {
            const numeroMenu = selector.getAttribute("data-menu");
            const bloque = document.getElementById("menu-bloque-" + numeroMenu);
            if (bloque && bloque.style.display === "none") return;

            const tipo = selector.value;
            const precio = preciosMenus[tipo] || 0;
            total += precio;

            const precioElemento = document.getElementById("precio-menu-" + numeroMenu);
            if (precioElemento) {
                precioElemento.innerHTML = "Precio de este menú: <strong>$" + precio.toFixed(2) + "</strong>";
            }

            const platoSel = document.querySelector("input[name='menus[" + numeroMenu + "][plato_fuerte]']:checked");
            const sopaSel = document.querySelector("input[name='menus[" + numeroMenu + "][sopa]']:checked");
            const aguaSel = document.querySelector("input[name='menus[" + numeroMenu + "][agua]']:checked");
            const postreSel = document.querySelector("input[name='menus[" + numeroMenu + "][postre]']:checked");
            const compSel = document.querySelectorAll("input[name='menus[" + numeroMenu + "][complementos][]']:checked");

            let complementosTexto = "Sin seleccionar";
            if (compSel.length > 0) {
                const lista = [];
                compSel.forEach(function (item) {
                    lista.push(obtenerTextoSeleccionado(item));
                });
                complementosTexto = lista.join("<br>");
            }

            htmlResumen += `
                <div class="ticket-menu">
                    <div class="ticket-menu__header">
                        <span>Menú ${numeroMenu}</span>
                        <strong>${tipo}</strong>
                    </div>

                    <div class="ticket-linea">
                        <span>Plato fuerte</span>
                        <span>${obtenerTextoSeleccionado(platoSel)}</span>
                    </div>

                    <div class="ticket-linea">
                        <span>Sopa</span>
                        <span>${obtenerTextoSeleccionado(sopaSel)}</span>
                    </div>

                    <div class="ticket-linea">
                        <span>Complementos</span>
                        <span>${complementosTexto}</span>
                    </div>

                    <div class="ticket-linea">
                        <span>Agua</span>
                        <span>${obtenerTextoSeleccionado(aguaSel)}</span>
                    </div>

                    <div class="ticket-linea">
                        <span>Postre</span>
                        <span>${obtenerTextoSeleccionado(postreSel)}</span>
                    </div>

                    <div class="ticket-subtotal">
                        <span>Subtotal</span>
                        <strong>$${precio.toFixed(2)}</strong>
                    </div>
                </div>
            `;
        });

        // Extras
        var totalExtras = 0;
        var htmlExtras  = "";
        document.querySelectorAll(".extra-item").forEach(function (item) {
            var cant   = parseInt(item.querySelector(".extra-cantidad").value) || 0;
            if (cant > 0) {
                var precio = parseFloat(item.dataset.precio) || 25;
                var nombre = item.querySelector(".extra-item__nombre").textContent;
                totalExtras += cant * precio;
                htmlExtras  += "<div class='ticket-linea'><span>" + nombre + " ×" + cant + "</span><span>$" + (cant * precio).toFixed(2) + "</span></div>";
            }
        });
        if (htmlExtras) {
            htmlResumen += "<div class='ticket-menu'><div class='ticket-menu__header'><span>Extras</span><strong>$" + totalExtras.toFixed(2) + "</strong></div>" + htmlExtras + "</div>";
        }
        total += totalExtras;

        if (resumenMenus) {
            resumenMenus.innerHTML = htmlResumen || '<p class="ticket-vacio">Selecciona tus menús para ver el resumen.</p>';
        }

        if (totalGeneral) {
            totalGeneral.textContent = "$" + total.toFixed(2);
        }
    }

    selectoresTipoMenu.forEach(function (selector) {
        selector.addEventListener("change", function () {
            actualizarOpcionesMenu(this.getAttribute("data-menu"));
            actualizarResumenTotal();
        });
    });

    document.querySelectorAll("input[type='radio'][name*='menus['], input[type='checkbox'][name*='menus[']").forEach(function (input) {
        input.addEventListener("change", actualizarResumenTotal);
    });

    // Highlight opción seleccionada
    function actualizarSeleccionVisual() {
        document.querySelectorAll(".opcion-producto").forEach(function (label) {
            var input = label.querySelector("input");
            if (input) label.classList.toggle("opcion-seleccionada", input.checked && !input.disabled);
        });
    }

    document.querySelectorAll(".opcion-producto input").forEach(function (input) {
        input.addEventListener("change", function () {
            if (this.type === "radio") {
                document.querySelectorAll("input[name='" + this.name + "']").forEach(function (r) {
                    var lbl = r.closest(".opcion-producto");
                    if (lbl) lbl.classList.remove("opcion-seleccionada");
                });
            }
            var lbl = this.closest(".opcion-producto");
            if (lbl) lbl.classList.toggle("opcion-seleccionada", this.checked);
        });
    });

    actualizarSeleccionVisual();


    actualizarResumenTotal();

    // ── EXTRAS ──────────────────────────────────────────────────────
    var btnExtrasToggle  = document.getElementById("btn-extras-toggle");
    var extrasContenido  = document.getElementById("extras-contenido");

    if (btnExtrasToggle && extrasContenido) {
        btnExtrasToggle.addEventListener("click", function () {
            var abierto = extrasContenido.style.display !== "none";
            extrasContenido.style.display = abierto ? "none" : "block";
            btnExtrasToggle.querySelector(".extras-toggle__icono").textContent = abierto ? "+" : "−";
        });

        // Si hay extras pre-seleccionados (vuelta de POST con errores), abrir el panel
        var hayExtras = false;
        document.querySelectorAll(".extra-cantidad").forEach(function (inp) {
            if (parseInt(inp.value) > 0) hayExtras = true;
        });
        if (hayExtras) {
            extrasContenido.style.display = "block";
            btnExtrasToggle.querySelector(".extras-toggle__icono").textContent = "−";
        }
    }

    document.querySelectorAll(".extra-item").forEach(function (item) {
        var menos = item.querySelector(".extra-btn-menos");
        var mas   = item.querySelector(".extra-btn-mas");
        var input = item.querySelector(".extra-cantidad");

        menos.addEventListener("click", function () {
            var v = parseInt(input.value) || 0;
            if (v > 0) {
                input.value = v - 1;
                item.classList.toggle("extra-item--activo", (v - 1) > 0);
                actualizarResumenTotal();
            }
        });
        mas.addEventListener("click", function () {
            var v = parseInt(input.value) || 0;
            if (v < 5) {
                input.value = v + 1;
                item.classList.add("extra-item--activo");
                actualizarResumenTotal();
            }
        });
    });
});

function actualizarOpcionesMenu(numeroMenu) {
    const selector = document.getElementById("tipo_menu_" + numeroMenu);
    const tipoSeleccionado = selector.value;

    document.querySelectorAll(".menu-" + numeroMenu + "-tipo").forEach(function (bloque) {
        if (bloque.getAttribute("data-tipo") === tipoSeleccionado) {
            bloque.style.display = "block";
        } else {
            bloque.style.display = "none";
            bloque.querySelectorAll("input").forEach(function (input) {
                input.checked = false;
            });
        }
    });
}
</script>

<div id="pedido-toast-stack"></div>

<footer class="cliente-footer">
    <span class="cliente-footer__slogan">© 2026 Zabisu - Sabor y Servicio. Todos los derechos reservados.</span>
</footer>

</body>
</html>
