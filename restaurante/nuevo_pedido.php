<?php
require_once "../config/db.php";
require_once "auth_check.php";

/* ── Menú más reciente (sin restricción de ventana) ── */
$stmtMenu = $conexion->prepare(
    "SELECT * FROM menu_dia ORDER BY fecha DESC LIMIT 1"
);
$stmtMenu->execute();
$menuActivo = $stmtMenu->fetch(PDO::FETCH_ASSOC);

/* ── Fecha formateada ── */
$fechaMenuFormateada = "";
if ($menuActivo) {
    $dias  = ['Sunday'=>'domingo','Monday'=>'lunes','Tuesday'=>'martes','Wednesday'=>'miércoles','Thursday'=>'jueves','Friday'=>'viernes','Saturday'=>'sábado'];
    $meses = ['01'=>'enero','02'=>'febrero','03'=>'marzo','04'=>'abril','05'=>'mayo','06'=>'junio','07'=>'julio','08'=>'agosto','09'=>'septiembre','10'=>'octubre','11'=>'noviembre','12'=>'diciembre'];
    $ts    = strtotime($menuActivo["fecha"]);
    $fechaMenuFormateada = "Menú del " . ($dias[date('l',$ts)]??'') . " " . date('j',$ts) . " de " . ($meses[date('m',$ts)]??'');
}

/* ── Productos organizados por tipo_menu y categoría ── */
$menusPorTipo    = [];
$productosIndexados = [];

if ($menuActivo) {
    $stmtProd = $conexion->prepare(
        "SELECT * FROM productos
         WHERE id_menu = :id_menu AND disponible = 1
         ORDER BY tipo_menu, categoria, nombre"
    );
    $stmtProd->execute([":id_menu" => $menuActivo["id_menu"]]);

    /* Conteos para agotado */
    $stmtCont = $conexion->prepare(
        "SELECT dp.id_producto, COUNT(*) AS total_pedidos
         FROM detalle_pedido dp
         INNER JOIN pedido_menus pm ON dp.id_pedido_menu = pm.id_pedido_menu
         INNER JOIN pedidos p       ON pm.id_pedido = p.id_pedido
         INNER JOIN productos pr    ON dp.id_producto = pr.id_producto
         WHERE p.estado != 'Cancelado' AND pr.id_menu = :id_menu
         GROUP BY dp.id_producto"
    );
    $stmtCont->execute([":id_menu" => $menuActivo["id_menu"]]);
    $conteos = [];
    foreach ($stmtCont->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $conteos[$row["id_producto"]] = (int)$row["total_pedidos"];
    }

    foreach ($stmtProd->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $idP = (int)$p["id_producto"];
        $p["agotado"] = (
            $p["categoria"] === "Plato fuerte" &&
            !empty($p["limite_pedidos"]) &&
            ($conteos[$idP] ?? 0) >= (int)$p["limite_pedidos"]
        );
        $menusPorTipo[$p["tipo_menu"]][$p["categoria"]][] = $p;
        $productosIndexados[$idP] = $p;
    }
}

/* ── Precios de tipos de menú ── */
$stmtTipos = $conexion->prepare("SELECT nombre_menu, precio FROM tipos_menu WHERE activo = 1");
$stmtTipos->execute();
$preciosMenus = [];
foreach ($stmtTipos->fetchAll(PDO::FETCH_ASSOC) as $t) {
    $preciosMenus[$t["nombre_menu"]] = (float)$t["precio"];
}

/* ── Ubicaciones y horarios ── */
$stmtUbic = $conexion->prepare("SELECT * FROM ubicaciones WHERE activo = 1 ORDER BY tipo, nombre_ubicacion");
$stmtUbic->execute();
$ubicaciones = $stmtUbic->fetchAll(PDO::FETCH_ASSOC);

$stmtHor = $conexion->prepare(
    "SELECT h.id_horario, h.hora_entrega, u.id_ubicacion, u.nombre_ubicacion, u.tipo
     FROM horarios_ubicacion h
     INNER JOIN ubicaciones u ON h.id_ubicacion = u.id_ubicacion
     WHERE h.activo = 1 AND u.activo = 1
     ORDER BY u.tipo, u.nombre_ubicacion, h.hora_entrega"
);
$stmtHor->execute();
$horarios = $stmtHor->fetchAll(PDO::FETCH_ASSOC);

/* ── Cantidad de menús ── */
$cantidadMenus = isset($_POST["cantidad_menus"]) ? (int)$_POST["cantidad_menus"] : 1;
if ($cantidadMenus < 1) $cantidadMenus = 1;
if ($cantidadMenus > 5) $cantidadMenus = 5;

/* ── Variables de control ── */
$errores      = [];
$erroresMenus = [];
$scrollDestino = "";
$exito        = false;
$folioCreado  = "";

/* ── Procesar POST ── */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["guardar_pedido"])) {
    $nombre_cliente = trim($_POST["nombre_cliente"] ?? "");
    $telefono       = trim($_POST["telefono"]       ?? "");
    $correo_cliente = trim($_POST["correo_cliente"] ?? "");
    $id_horario     = $_POST["id_horario"]          ?? "";
    $metodo_pago    = trim($_POST["metodo_pago"]    ?? "");
    $observaciones  = trim($_POST["observaciones"]  ?? "");
    $menusRecibidos = $_POST["menus"]               ?? [];

    /* Validar datos del cliente */
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

    if ($correo_cliente !== "" && !filter_var($correo_cliente, FILTER_VALIDATE_EMAIL)) {
        $errores[] = "El correo electrónico no tiene un formato válido.";
    }

    if (empty($menusRecibidos)) {
        $errores[] = "Debes capturar al menos un menú.";
    }

    if ($id_horario === "") {
        $errores[] = "Debes seleccionar un horario.";
        if ($scrollDestino === "") $scrollDestino = "bloque-entrega";
    }

    if ($metodo_pago === "") {
        $errores[] = "Selecciona el método de pago.";
    }

    /* Validar cada menú */
    foreach ($menusRecibidos as $numeroMenu => $menu) {
        $tipo_menu    = $menu["tipo_menu"]    ?? "";
        $plato_fuerte = $menu["plato_fuerte"] ?? "";
        $sopa         = $menu["sopa"]         ?? "";
        $agua         = $menu["agua"]         ?? "";
        $postre       = $menu["postre"]       ?? "";
        $complementos = $menu["complementos"] ?? [];

        if ($tipo_menu === "" || !in_array($tipo_menu, ["Zabisu", "Ejecutivo"], true)) {
            $erroresMenus[$numeroMenu][] = "Selecciona un tipo de menú válido.";
        }
        if ($plato_fuerte === "") {
            $erroresMenus[$numeroMenu][] = "Falta seleccionar el plato fuerte.";
        } elseif (isset($productosIndexados[$plato_fuerte]) && !empty($productosIndexados[$plato_fuerte]["agotado"])) {
            $erroresMenus[$numeroMenu][] = "El plato fuerte seleccionado está agotado.";
        }
        if ($sopa === "")         $erroresMenus[$numeroMenu][] = "Falta seleccionar la sopa.";
        if ($agua === "")         $erroresMenus[$numeroMenu][] = "Falta seleccionar el agua.";
        if ($postre === "")       $erroresMenus[$numeroMenu][] = "Falta seleccionar el postre.";
        if (empty($complementos)) $erroresMenus[$numeroMenu][] = "Falta seleccionar al menos un complemento.";
        if (count($complementos) > 2) $erroresMenus[$numeroMenu][] = "Solo puedes elegir hasta 2 complementos.";

        $todosIds = array_filter(array_merge([$plato_fuerte, $sopa, $agua, $postre], $complementos));
        foreach ($todosIds as $idP) {
            if (!isset($productosIndexados[$idP])) {
                $erroresMenus[$numeroMenu][] = "Uno de los productos no es válido.";
                break;
            }
            if ($productosIndexados[$idP]["tipo_menu"] !== $tipo_menu) {
                $erroresMenus[$numeroMenu][] = "Hay un producto que no corresponde al tipo de menú.";
                break;
            }
        }
    }

    if (!empty($erroresMenus)) {
        foreach ($erroresMenus as $nMenu => $lista) {
            foreach ($lista as $msg) {
                $errores[] = "Menú {$nMenu}: {$msg}";
            }
        }
        if ($scrollDestino === "") $scrollDestino = "menu-bloque-" . array_key_first($erroresMenus);
    }

    /* Guardar en BD */
    if (empty($errores)) {
        try {
            $conexion->beginTransaction();

            $totalPedido = 0.0;
            foreach ($menusRecibidos as $m) {
                $totalPedido += $preciosMenus[$m["tipo_menu"] ?? ""] ?? 0;
            }

            $folio       = "ZAB-" . date("Ymd") . "-" . strtoupper(substr(md5(uniqid()), 0, 5));
            $estado_pago = match($metodo_pago) {
                "Efectivo"      => "Pago en efectivo",
                "Transferencia" => "Pagado",
                default         => "Pendiente de pago",
            };

            $stmtPed = $conexion->prepare(
                "INSERT INTO pedidos
                 (folio, nombre_cliente, telefono, correo_cliente, id_horario, metodo_pago,
                  observaciones, total, estado, estado_pago, es_prueba, referencia_pago)
                 VALUES
                 (:folio, :nombre_cliente, :telefono, :correo_cliente, :id_horario, :metodo_pago,
                  :observaciones, :total, 'Pendiente', :estado_pago, 0, :folio2)"
            );
            $stmtPed->execute([
                ":folio"          => $folio,
                ":nombre_cliente" => $nombre_cliente,
                ":telefono"       => $telefono,
                ":correo_cliente" => $correo_cliente,
                ":id_horario"     => $id_horario,
                ":metodo_pago"    => $metodo_pago,
                ":observaciones"  => $observaciones,
                ":total"          => $totalPedido,
                ":estado_pago"    => $estado_pago,
                ":folio2"         => $folio,
            ]);
            $id_pedido = (int)$conexion->lastInsertId();

            $stmtPM  = $conexion->prepare(
                "INSERT INTO pedido_menus (id_pedido, tipo_menu, numero_menu)
                 VALUES (:id_pedido, :tipo_menu, :numero_menu)"
            );
            $stmtDet = $conexion->prepare(
                "INSERT INTO detalle_pedido (id_pedido_menu, id_producto, categoria, nombre_producto)
                 VALUES (:id_pedido_menu, :id_producto, :categoria, :nombre_producto)"
            );

            $nMenu = 1;
            foreach ($menusRecibidos as $m) {
                $stmtPM->execute([
                    ":id_pedido"   => $id_pedido,
                    ":tipo_menu"   => $m["tipo_menu"],
                    ":numero_menu" => $nMenu,
                ]);
                $id_pedido_menu = (int)$conexion->lastInsertId();

                $ids = array_filter(array_merge(
                    [(int)($m["plato_fuerte"] ?? 0), (int)($m["sopa"] ?? 0), (int)($m["agua"] ?? 0), (int)($m["postre"] ?? 0)],
                    array_map('intval', $m["complementos"] ?? [])
                ));

                foreach ($ids as $idP) {
                    if (!$idP || !isset($productosIndexados[$idP])) continue;
                    $stmtDet->execute([
                        ":id_pedido_menu"  => $id_pedido_menu,
                        ":id_producto"     => $idP,
                        ":categoria"       => $productosIndexados[$idP]["categoria"],
                        ":nombre_producto" => $productosIndexados[$idP]["nombre"],
                    ]);
                }
                $nMenu++;
            }

            $conexion->commit();
            $exito       = true;
            $folioCreado = $folio;

        } catch (Exception $e) {
            $conexion->rollBack();
            $errores[] = "Error al guardar el pedido: " . $e->getMessage();
        }
    }

    /* Paso inicial para el stepper en caso de error */
    if (!$exito) {
        if ($scrollDestino === "bloque-entrega") {
            $pasoInicial = 3;
        } elseif (!empty($erroresMenus) || strpos($scrollDestino, "menu-bloque-") === 0) {
            $pasoInicial = 2;
        } else {
            $pasoInicial = 1;
        }
    }
}

$pasoInicial = $pasoInicial ?? 1;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nuevo pedido | Restaurante Zabisu</title>
    <link rel="icon" type="image/png" href="../assets/img/LOGO_NARA.png">
    <link rel="stylesheet" href="../assets/css/styles.css">
</head>
<body>

<div id="pedido-toast-stack" class="pedido-toast-stack"></div>

<div class="contenedor">

    <div class="hero-zabisu">
        <div class="hero-zabisu__glow"></div>
        <div class="hero-zabisu__contenido">
            <p class="hero-zabisu__eyebrow">RESTAURANTE</p>
            <h1 class="hero-zabisu__titulo">Nuevo pedido</h1>
            <p class="hero-zabisu__texto"><?php echo htmlspecialchars($fechaMenuFormateada); ?></p>
            <a href="panel_general.php" class="btn-volver-panel">← Panel general</a>
        </div>
    </div>

    <?php if ($exito): ?>
        <div class="bloque-formulario resumen-total">
            <h2>✅ Pedido registrado</h2>
            <p style="font-size:22px;font-weight:800;color:#4ac86e;margin:6px 0 14px;">
                <?php echo htmlspecialchars($folioCreado); ?>
            </p>
            <p class="nota-formulario">El pedido ya aparece en el panel de pedidos.</p>
            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:14px;">
                <a href="pedidos.php" class="btn-principal" style="text-decoration:none;display:inline-block;">Ver pedidos</a>
                <a href="nuevo_pedido.php" class="btn-volver-panel" style="text-decoration:none;display:inline-block;">+ Otro pedido</a>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!$exito): ?>

    <?php if (!$menuActivo): ?>
        <div class="bloque-formulario">
            <p class="nota-formulario" style="color:#ff7a00;">No hay ningún menú registrado. Crea un menú primero.</p>
        </div>
    <?php else: ?>

    <?php if (!empty($errores)): ?>
        <div class="mensaje-error">
            <ul>
                <?php foreach ($errores as $err): ?>
                    <li><?php echo htmlspecialchars($err); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <!-- Stepper indicador -->
    <div class="stepper-indicador">
        <div class="stepper-paso-item" data-paso="1">
            <div class="stepper-circulo">1</div>
            <span class="stepper-etiqueta">Datos</span>
        </div>
        <div class="stepper-linea"></div>
        <div class="stepper-paso-item" data-paso="2">
            <div class="stepper-circulo">2</div>
            <span class="stepper-etiqueta">Menú</span>
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

        <!-- ====================================================
             PASO 1: DATOS DEL CLIENTE
        ==================================================== -->
        <div class="paso-stepper" data-paso="1">
            <div class="paso-stepper__errores"></div>

            <section class="bloque-formulario" id="bloque-datos-cliente">
                <h2>Datos del cliente</h2>

                <label for="nombre_cliente">Nombre completo</label>
                <input type="text" name="nombre_cliente" id="nombre_cliente"
                       maxlength="100" autocomplete="off"
                       value="<?php echo htmlspecialchars($_POST["nombre_cliente"] ?? ""); ?>">

                <label for="telefono">Teléfono</label>
                <input type="text" name="telefono" id="telefono"
                       maxlength="10" inputmode="numeric" autocomplete="off"
                       value="<?php echo htmlspecialchars($_POST["telefono"] ?? ""); ?>">

                <label for="correo_cliente">Correo electrónico <span class="nota-formulario" style="display:inline;font-size:12px;">(opcional)</span></label>
                <input type="email" name="correo_cliente" id="correo_cliente"
                       maxlength="150" autocomplete="off"
                       value="<?php echo htmlspecialchars($_POST["correo_cliente"] ?? ""); ?>">
            </section>

            <div class="stepper-nav">
                <div></div>
                <button type="button" class="btn-stepper btn-stepper--siguiente" data-siguiente="2">
                    Siguiente →
                </button>
            </div>
        </div>

        <!-- ====================================================
             PASO 2: MENÚ
        ==================================================== -->
        <div class="paso-stepper" data-paso="2">
            <div class="paso-stepper__errores"></div>

            <section class="bloque-formulario" id="bloque-cantidad-menus">
                <h2>Tu menú</h2>

                <label for="cantidad_menus">¿Cuántos menús?</label>
                <select name="cantidad_menus" id="cantidad_menus">
                    <?php for ($n = 1; $n <= 5; $n++): ?>
                        <option value="<?php echo $n; ?>" <?php echo ($cantidadMenus === $n) ? "selected" : ""; ?>>
                            <?php echo $n; ?> menú<?php echo $n > 1 ? "s" : ""; ?>
                        </option>
                    <?php endfor; ?>
                </select>
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
                                <?php foreach ($erroresMenus[$i] as $em): ?>
                                    <li><?php echo htmlspecialchars($em); ?></li>
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
                        <option value="Zabisu"    <?php echo ($tipoSeleccionado === "Zabisu")    ? "selected" : ""; ?>>Menú Zabisu</option>
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
                                            <strong><?php echo htmlspecialchars($item["nombre"]); ?></strong>
                                            — <?php echo htmlspecialchars($item["descripcion"]); ?>
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
                                                   <?php echo in_array($item["id_producto"], $_POST["menus"][$i]["complementos"] ?? []) ? "checked" : ""; ?>>
                                            <strong><?php echo htmlspecialchars($item["nombre"]); ?></strong>
                                            — <?php echo htmlspecialchars($item["descripcion"]); ?>
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
                                            <strong><?php echo htmlspecialchars($item["nombre"]); ?></strong>
                                            — <?php echo htmlspecialchars($item["descripcion"]); ?>
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
                                            <strong><?php echo htmlspecialchars($item["nombre"]); ?></strong>
                                            — <?php echo htmlspecialchars($item["descripcion"]); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                        </div>
                    <?php endforeach; ?>
                </section>
            <?php endfor; ?>

            <div class="stepper-nav">
                <button type="button" class="btn-stepper btn-stepper--anterior" data-anterior="1">← Anterior</button>
                <button type="button" class="btn-stepper btn-stepper--siguiente" data-siguiente="3">Siguiente →</button>
            </div>
        </div>

        <!-- ====================================================
             PASO 3: ENTREGA
        ==================================================== -->
        <div class="paso-stepper" data-paso="3">
            <div class="paso-stepper__errores"></div>

            <section class="bloque-formulario" id="bloque-entrega">
                <h2>Punto de entrega</h2>

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

        <!-- ====================================================
             PASO 4: CONFIRMAR Y GUARDAR
        ==================================================== -->
        <div class="paso-stepper" data-paso="4">

            <div class="bloque-formulario resumen-total" id="bloque-resumen">
                <h2>Resumen del pedido</h2>
                <div id="resumen-menus" class="ticket-resumen">
                    <p class="ticket-vacio">Selecciona tus menús para ver el resumen.</p>
                </div>
                <div class="ticket-total">
                    <span>Total</span>
                    <strong id="total-general">$0.00</strong>
                </div>
            </div>

            <section class="bloque-formulario" id="bloque-confirmar">
                <h2>Pago y confirmar</h2>

                <label>Método de pago</label>
                <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;">
                    <?php foreach (["Efectivo", "Transferencia"] as $metodo): ?>
                        <label class="opcion-producto" style="flex:1;min-width:140px;justify-content:center;">
                            <input type="radio" name="metodo_pago" value="<?php echo $metodo; ?>"
                                   <?php echo (($_POST["metodo_pago"] ?? "") === $metodo) ? "checked" : ""; ?>>
                            <?php echo $metodo; ?>
                        </label>
                    <?php endforeach; ?>
                </div>

                <label for="observaciones">Observaciones</label>
                <textarea name="observaciones" id="observaciones" rows="3"
                          placeholder="Notas especiales, alergias, instrucciones..."><?php echo htmlspecialchars($_POST["observaciones"] ?? ""); ?></textarea>

                <button type="submit" name="guardar_pedido" value="1" class="btn-principal" style="margin-top:18px;width:100%;">
                    Guardar pedido
                </button>
            </section>

            <div class="stepper-nav">
                <button type="button" class="btn-stepper btn-stepper--anterior" data-anterior="3">← Anterior</button>
                <div></div>
            </div>
        </div>

    </form>

    <?php endif; /* menuActivo */ ?>
    <?php endif; /* !exito */ ?>

</div><!-- /contenedor -->

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

    // ── Toast ────────────────────────────────────────────────────
    const stack = document.getElementById("pedido-toast-stack");

    function mostrarToast(mensajes) {
        if (!stack) return;
        stack.innerHTML = "";
        mensajes.forEach(function (m, idx) {
            const item = document.createElement("div");
            item.className = "pedido-toast-item";
            item.innerHTML = "<span class='pedido-toast-item__icono'>⚠️</span><span class='pedido-toast-item__texto'>" + m + "</span>";
            stack.appendChild(item);
            setTimeout(function () { item.classList.add("pedido-toast-item--visible"); }, idx * 80);
            setTimeout(function () {
                item.classList.remove("pedido-toast-item--visible");
                setTimeout(function () { if (item.parentNode) item.parentNode.removeChild(item); }, 250);
            }, 3500 + idx * 80);
        });
    }

    function mostrarErroresPaso(mensajes) {
        if (mensajes.length) mostrarToast(mensajes);
    }

    // ── Validaciones por paso ────────────────────────────────────
    function validarPaso(n) {
        const errores = [];

        if (n === 1) {
            const nombre   = (document.getElementById("nombre_cliente").value || "").trim();
            const telefono = (document.getElementById("telefono").value || "").trim();
            const correo   = (document.getElementById("correo_cliente").value || "").trim();

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

            if (correo && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(correo)) {
                errores.push("El correo electrónico no tiene un formato válido.");
            }
        }

        if (n === 2) {
            const cantidad = parseInt(document.getElementById("cantidad_menus").value) || 1;
            for (let i = 1; i <= cantidad; i++) {
                const bloque = document.getElementById("menu-bloque-" + i);
                if (!bloque || bloque.style.display === "none") continue;

                const plato  = bloque.querySelector("input[name='menus[" + i + "][plato_fuerte]']:checked:not([disabled])");
                const sopa   = bloque.querySelector("input[name='menus[" + i + "][sopa]']:checked");
                const agua   = bloque.querySelector("input[name='menus[" + i + "][agua]']:checked");
                const postre = bloque.querySelector("input[name='menus[" + i + "][postre]']:checked");
                const comps  = bloque.querySelectorAll("input[name='menus[" + i + "][complementos][]']:checked");

                if (!plato)          errores.push("Menú " + i + ": falta el plato fuerte.");
                if (!sopa)           errores.push("Menú " + i + ": falta la sopa.");
                if (!agua)           errores.push("Menú " + i + ": falta el agua.");
                if (!postre)         errores.push("Menú " + i + ": falta el postre.");
                if (comps.length === 0) errores.push("Menú " + i + ": falta al menos un complemento.");
                if (comps.length > 2)   errores.push("Menú " + i + ": máximo 2 complementos.");
            }
        }

        if (n === 3) {
            const horario = document.querySelector("input[name='id_horario']:checked");
            if (!horario) errores.push("Selecciona una ubicación y horario de entrega.");
        }

        if (n === 4) {
            const metodo = document.querySelector("input[name='metodo_pago']:checked");
            if (!metodo) errores.push("Selecciona el método de pago.");
        }

        mostrarErroresPaso(errores);
        return errores.length === 0;
    }

    document.querySelectorAll("[data-siguiente]").forEach(function (btn) {
        btn.addEventListener("click", function () {
            const siguiente = parseInt(this.dataset.siguiente);
            if (validarPaso(siguiente - 1)) irAPaso(siguiente);
        });
    });

    document.querySelectorAll("[data-anterior]").forEach(function (btn) {
        btn.addEventListener("click", function () {
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
                bloque.querySelectorAll("input, select").forEach(function (el) {
                    if (!el.disabled) { el.disabled = true; el.dataset.stepperDisabled = "1"; }
                });
            } else {
                bloque.querySelectorAll("[data-stepper-disabled]").forEach(function (el) {
                    el.disabled = false; delete el.dataset.stepperDisabled;
                });
            }
        }
        actualizarResumenTotal();
    }

    const selCantidad = document.getElementById("cantidad_menus");
    if (selCantidad) {
        selCantidad.addEventListener("change", function () {
            actualizarBloquesMenues(parseInt(this.value) || 1);
        });
    }

    // ============================================================
    // OPCIONES POR TIPO DE MENÚ
    // ============================================================
    window.actualizarOpcionesMenu = function (menuNum) {
        const sel = document.getElementById("tipo_menu_" + menuNum);
        if (!sel) return;
        const tipo = sel.value;

        document.querySelectorAll(".menu-" + menuNum + "-tipo").forEach(function (div) {
            const visible = div.dataset.tipo === tipo;
            div.style.display = visible ? "block" : "none";
            div.querySelectorAll("input").forEach(function (inp) {
                inp.disabled = !visible;
            });
        });
        actualizarResumenTotal();
    };

    // ============================================================
    // INPUTS: NOMBRE / TELÉFONO
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
    const formPedido = document.getElementById("formulario-pedido");
    if (formPedido) {
        formPedido.addEventListener("keydown", function (e) {
            if (e.key !== "Enter") return;
            if (pasoActual >= 4) return;
            e.preventDefault();
            const btnSig = document.querySelector(".paso-stepper[data-paso='" + pasoActual + "'] .btn-stepper--siguiente");
            if (btnSig) btnSig.click();
        });
    }

    // ============================================================
    // UBICACIONES Y HORARIOS
    // ============================================================
    function inicializarEntrega() {
        document.querySelectorAll(".horarios-ocultos").forEach(function (b) {
            b.style.display = "none";
        });

        document.querySelectorAll(".opcion-ubicacion").forEach(function (label) {
            label.onclick = function () {
                const radio = this.querySelector(".radio-ubicacion");
                if (radio) radio.checked = true;

                document.querySelectorAll(".opcion-ubicacion").forEach(function (l) {
                    l.classList.remove("ubicacion-activa");
                });
                this.classList.add("ubicacion-activa");

                document.querySelectorAll(".horarios-ocultos").forEach(function (b) {
                    b.style.display = "none";
                });
                document.querySelectorAll("input[name='id_horario']").forEach(function (r) {
                    r.required = false;
                });

                const grupo = this.closest(".grupo-ubicacion-selector");
                if (!grupo) return;
                const bloque = grupo.querySelector(".horarios-ocultos");
                if (!bloque) return;
                bloque.style.display = "block";
                bloque.querySelectorAll("input[name='id_horario']").forEach(function (r) {
                    r.required = true;
                });
            };
        });

        const horarioSel = document.querySelector("input[name='id_horario']:checked");
        if (horarioSel) {
            const contenedor = horarioSel.closest(".horarios-ocultos");
            if (contenedor) {
                contenedor.style.display = "block";
                contenedor.querySelectorAll("input[name='id_horario']").forEach(function (r) { r.required = true; });
                const grupoR = contenedor.closest(".grupo-ubicacion-selector");
                if (grupoR) {
                    const radioUb = grupoR.querySelector(".radio-ubicacion");
                    if (radioUb) radioUb.checked = true;
                    const labelR = grupoR.querySelector(".opcion-ubicacion");
                    if (labelR) labelR.classList.add("ubicacion-activa");
                }
            }
        }
    }

    if (<?php echo $pasoInicial; ?> === 3) inicializarEntrega();

    // ============================================================
    // RESUMEN Y TOTAL
    // ============================================================
    const preciosMenus = {
        Zabisu:    <?php echo json_encode($preciosMenus["Zabisu"]    ?? 0); ?>,
        Ejecutivo: <?php echo json_encode($preciosMenus["Ejecutivo"] ?? 0); ?>
    };

    const selectoresTipoMenu = document.querySelectorAll(".selector-tipo-menu");
    const resumenMenusEl     = document.getElementById("resumen-menus");
    const totalGeneralEl     = document.getElementById("total-general");

    function obtenerTextoSeleccionado(selector) {
        if (!selector) return "Sin seleccionar";
        const label  = selector.closest("label");
        if (!label)  return "Sin seleccionar";
        const strong = label.querySelector("strong");
        return (strong ? strong.textContent : label.textContent).replace(/\s+/g, " ").trim() || "Sin seleccionar";
    }

    function actualizarResumenTotal() {
        let total = 0;
        let htmlResumen = "";

        selectoresTipoMenu.forEach(function (sel) {
            const n     = sel.getAttribute("data-menu");
            const bloque = document.getElementById("menu-bloque-" + n);
            if (bloque && bloque.style.display === "none") return;

            const tipo   = sel.value;
            const precio = preciosMenus[tipo] || 0;
            total += precio;

            const precioEl = document.getElementById("precio-menu-" + n);
            if (precioEl) precioEl.innerHTML = "Precio de este menú: <strong>$" + precio.toFixed(2) + "</strong>";

            const platoSel  = document.querySelector("input[name='menus[" + n + "][plato_fuerte]']:checked");
            const sopaSel   = document.querySelector("input[name='menus[" + n + "][sopa]']:checked");
            const aguaSel   = document.querySelector("input[name='menus[" + n + "][agua]']:checked");
            const postreSel = document.querySelector("input[name='menus[" + n + "][postre]']:checked");
            const compsSel  = document.querySelectorAll("input[name='menus[" + n + "][complementos][]']:checked");

            let compsTexto = "Sin seleccionar";
            if (compsSel.length > 0) {
                const lista = [];
                compsSel.forEach(function (c) { lista.push(obtenerTextoSeleccionado(c)); });
                compsTexto = lista.join("<br>");
            }

            htmlResumen += `
                <div class="ticket-menu">
                    <div class="ticket-menu__header">
                        <span>Menú ${n}</span>
                        <strong>${tipo}</strong>
                    </div>
                    <div class="ticket-linea"><span>Plato fuerte</span><span>${obtenerTextoSeleccionado(platoSel)}</span></div>
                    <div class="ticket-linea"><span>Sopa</span><span>${obtenerTextoSeleccionado(sopaSel)}</span></div>
                    <div class="ticket-linea"><span>Complementos</span><span>${compsTexto}</span></div>
                    <div class="ticket-linea"><span>Agua</span><span>${obtenerTextoSeleccionado(aguaSel)}</span></div>
                    <div class="ticket-linea"><span>Postre</span><span>${obtenerTextoSeleccionado(postreSel)}</span></div>
                    <div class="ticket-subtotal"><span>Subtotal</span><strong>$${precio.toFixed(2)}</strong></div>
                </div>
            `;
        });

        if (resumenMenusEl) resumenMenusEl.innerHTML = htmlResumen || '<p class="ticket-vacio">Selecciona tus menús para ver el resumen.</p>';
        if (totalGeneralEl) totalGeneralEl.textContent = "$" + total.toFixed(2);
    }

    selectoresTipoMenu.forEach(function (sel) {
        sel.addEventListener("change", function () {
            actualizarOpcionesMenu(this.getAttribute("data-menu"));
        });
    });

    document.querySelectorAll("input[type='radio'][name*='menus['], input[type='checkbox'][name*='menus[']").forEach(function (inp) {
        inp.addEventListener("change", actualizarResumenTotal);
    });

    // Highlight opción seleccionada
    function actualizarSeleccionVisual() {
        document.querySelectorAll(".opcion-producto").forEach(function (label) {
            const inp = label.querySelector("input");
            if (inp) label.classList.toggle("opcion-seleccionada", inp.checked && !inp.disabled);
        });
    }

    document.querySelectorAll(".opcion-producto input").forEach(function (inp) {
        inp.addEventListener("change", function () {
            if (this.type === "radio") {
                document.querySelectorAll("input[name='" + this.name + "']").forEach(function (r) {
                    const lbl = r.closest(".opcion-producto");
                    if (lbl) lbl.classList.remove("opcion-seleccionada");
                });
            }
            const lbl = this.closest(".opcion-producto");
            if (lbl) lbl.classList.toggle("opcion-seleccionada", this.checked);
        });
    });

    actualizarSeleccionVisual();
    actualizarBloquesMenues(<?php echo (int)$cantidadMenus; ?>);
    actualizarResumenTotal();
});
</script>

</body>
</html>
