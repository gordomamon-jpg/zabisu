<?php
require_once "../config/db.php";
require_once "auth_check.php";

/* ── Modo prueba ── */
$stmtMP = $conexion->prepare("SELECT valor FROM configuracion WHERE clave = 'modo_prueba' LIMIT 1");
$stmtMP->execute();
$esPrueba = (int)($stmtMP->fetchColumn() ?? 0);

/* ── Menú más reciente (sin restricción de ventana) ── */
$stmtMenu = $conexion->prepare("SELECT * FROM menu_dia ORDER BY fecha DESC LIMIT 1");
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
$menusPorTipo       = [];
$productosIndexados = [];

if ($menuActivo) {
    $stmtProd = $conexion->prepare(
        "SELECT * FROM productos
         WHERE id_menu = :id_menu AND disponible = 1
         ORDER BY tipo_menu, categoria, nombre"
    );
    $stmtProd->execute([":id_menu" => $menuActivo["id_menu"]]);

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

/* ── Precios ── */
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

/* ── Extras disponibles (deduplicados por nombre) ── */
$PRECIOS_EXTRA = ["Sopa" => 25, "Complemento" => 25, "Agua" => 20];
$extrasForm = [];
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

/* ── Cantidad de menús ── */
$cantidadMenus = isset($_POST["cantidad_menus"]) ? (int)$_POST["cantidad_menus"] : 1;
if ($cantidadMenus < 0) $cantidadMenus = 0;
if ($cantidadMenus > 5) $cantidadMenus = 5;

/* ── Procesar POST ── */
$errores      = [];
$erroresMenus = [];
$exito        = false;
$folioCreado  = "";

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["guardar_pedido"])) {
    $nombre_cliente = trim($_POST["nombre_cliente"] ?? "");
    $telefono       = trim($_POST["telefono"]       ?? "");
    $correo_cliente = trim($_POST["correo_cliente"] ?? "");
    $id_horario     = $_POST["id_horario"]          ?? "";
    $metodo_pago    = trim($_POST["metodo_pago"]    ?? "");
    $observaciones  = trim($_POST["observaciones"]  ?? "");
    $menusRecibidos = $_POST["menus"]               ?? [];

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
    if ($id_horario === "") $errores[] = "Debes seleccionar un horario de entrega.";
    if ($metodo_pago === "") $errores[] = "Selecciona el método de pago.";
    if ($cantidadMenus > 0 && empty($menusRecibidos)) $errores[] = "Agrega al menos un menú.";

    foreach ($menusRecibidos as $nMenu => $menu) {
        $tipo_menu    = $menu["tipo_menu"]    ?? "";
        $plato_fuerte = $menu["plato_fuerte"] ?? "";
        $sopa         = $menu["sopa"]         ?? "";
        $agua         = $menu["agua"]         ?? "";
        $postre       = $menu["postre"]       ?? "";
        $complementos = $menu["complementos"] ?? [];

        if ($tipo_menu === "" || !in_array($tipo_menu, ["Zabisu", "Ejecutivo"], true))
            $erroresMenus[$nMenu][] = "Selecciona un tipo de menú válido.";
        if ($plato_fuerte === "") {
            $erroresMenus[$nMenu][] = "Falta el plato fuerte.";
        } elseif (isset($productosIndexados[$plato_fuerte]) && !empty($productosIndexados[$plato_fuerte]["agotado"])) {
            $erroresMenus[$nMenu][] = "El plato fuerte seleccionado está agotado.";
        }
        if ($sopa === "")         $erroresMenus[$nMenu][] = "Falta la sopa.";
        if ($agua === "")         $erroresMenus[$nMenu][] = "Falta el agua.";
        if ($postre === "")       $erroresMenus[$nMenu][] = "Falta el postre.";
        if (empty($complementos)) $erroresMenus[$nMenu][] = "Falta al menos un complemento.";
        if (count($complementos) > 2) $erroresMenus[$nMenu][] = "Máximo 2 complementos.";

        foreach (array_filter(array_merge([$plato_fuerte, $sopa, $agua, $postre], $complementos)) as $idP) {
            if (!isset($productosIndexados[$idP])) { $erroresMenus[$nMenu][] = "Producto no válido."; break; }
            if ($productosIndexados[$idP]["tipo_menu"] !== $tipo_menu) { $erroresMenus[$nMenu][] = "Hay un producto que no corresponde al tipo de menú."; break; }
        }
    }

    foreach ($erroresMenus as $nMenu => $lista) {
        foreach ($lista as $msg) $errores[] = "Menú {$nMenu}: {$msg}";
    }

    if (empty($errores)) {
        try {
            $conexion->beginTransaction();

            $PRECIOS_EXTRA_SERVER = ["Sopa" => 25.00, "Complemento" => 25.00, "Agua" => 20.00];
            $extrasGuardar = [];
            foreach ($_POST["extras"] ?? [] as $idProducto => $cantidad) {
                $cantidad = min((int)$cantidad, 5);
                if ($cantidad <= 0 || !isset($productosIndexados[$idProducto])) continue;
                $prod = $productosIndexados[$idProducto];
                $precioExtra = $PRECIOS_EXTRA_SERVER[$prod["categoria"]] ?? 25.00;
                $extrasGuardar[] = [
                    "id_producto"     => (int)$idProducto,
                    "nombre"          => $prod["nombre"],
                    "categoria"       => $prod["categoria"],
                    "cantidad"        => $cantidad,
                    "precio_unitario" => $precioExtra,
                ];
            }

            $totalPedido = 0.0;
            if ($cantidadMenus === 0) {
                foreach ($extrasGuardar as $ex) $totalPedido += $ex["cantidad"] * $ex["precio_unitario"];
            } else {
                foreach ($menusRecibidos as $m) $totalPedido += $preciosMenus[$m["tipo_menu"] ?? ""] ?? 0;
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
                 VALUES (:folio,:nombre_cliente,:telefono,:correo_cliente,:id_horario,:metodo_pago,
                         :observaciones,:total,'Pendiente',:estado_pago,:es_prueba,:folio2)"
            );
            $stmtPed->execute([
                ":folio"=>$folio,":nombre_cliente"=>$nombre_cliente,":telefono"=>$telefono,
                ":correo_cliente"=>$correo_cliente,":id_horario"=>$id_horario,
                ":metodo_pago"=>$metodo_pago,":observaciones"=>$observaciones,
                ":total"=>$totalPedido,":estado_pago"=>$estado_pago,":es_prueba"=>$esPrueba,":folio2"=>$folio,
            ]);
            $id_pedido = (int)$conexion->lastInsertId();

            $stmtPM  = $conexion->prepare("INSERT INTO pedido_menus (id_pedido,tipo_menu,numero_menu) VALUES (:id_pedido,:tipo_menu,:numero_menu)");
            $stmtDet = $conexion->prepare("INSERT INTO detalle_pedido (id_pedido_menu,id_producto,categoria,nombre_producto) VALUES (:id_pedido_menu,:id_producto,:categoria,:nombre_producto)");

            $nMenu = 1;
            foreach ($menusRecibidos as $m) {
                $stmtPM->execute([":id_pedido"=>$id_pedido,":tipo_menu"=>$m["tipo_menu"],":numero_menu"=>$nMenu]);
                $id_pedido_menu = (int)$conexion->lastInsertId();
                $ids = array_filter(array_merge(
                    [(int)($m["plato_fuerte"]??0),(int)($m["sopa"]??0),(int)($m["agua"]??0),(int)($m["postre"]??0)],
                    array_map('intval', $m["complementos"]??[])
                ));
                foreach ($ids as $idP) {
                    if (!$idP || !isset($productosIndexados[$idP])) continue;
                    $stmtDet->execute([":id_pedido_menu"=>$id_pedido_menu,":id_producto"=>$idP,":categoria"=>$productosIndexados[$idP]["categoria"],":nombre_producto"=>$productosIndexados[$idP]["nombre"]]);
                }
                $nMenu++;
            }

            if (!empty($extrasGuardar)) {
                $stmtExtra = $conexion->prepare(
                    "INSERT INTO pedido_extras (id_pedido,id_producto,nombre,categoria,cantidad,precio_unitario)
                     VALUES (:id_pedido,:id_producto,:nombre,:categoria,:cantidad,:precio)"
                );
                foreach ($extrasGuardar as $ex) {
                    $stmtExtra->execute([
                        ":id_pedido"   => $id_pedido,
                        ":id_producto" => $ex["id_producto"],
                        ":nombre"      => $ex["nombre"],
                        ":categoria"   => $ex["categoria"],
                        ":cantidad"    => $ex["cantidad"],
                        ":precio"      => $ex["precio_unitario"],
                    ]);
                }
            }

            $conexion->commit();
            $exito = true; $folioCreado = $folio;

        } catch (Exception $e) {
            $conexion->rollBack();
            $errores[] = "Error al guardar: " . $e->getMessage();
        }
    }
}
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
            <p style="font-size:22px;font-weight:800;color:#4ac86e;margin:6px 0 14px;"><?php echo htmlspecialchars($folioCreado); ?></p>
            <p class="nota-formulario">El pedido ya aparece en el panel de pedidos.</p>
            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:14px;">
                <a href="pedidos.php" class="btn-principal" style="text-decoration:none;display:inline-block;">Ver pedidos</a>
                <a href="nuevo_pedido.php" class="btn-volver-panel" style="text-decoration:none;display:inline-block;">+ Otro pedido</a>
            </div>
        </div>

    <?php elseif (!$menuActivo): ?>
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

    <form action="" method="POST" class="formulario-pedido" id="formulario-pedido">

        <!-- DATOS DEL CLIENTE -->
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

        <!-- CANTIDAD DE MENÚS -->
        <section class="bloque-formulario">
            <h2>Menú</h2>

            <label for="cantidad_menus">¿Cuántos menús?</label>
            <select name="cantidad_menus" id="cantidad_menus">
                <option value="0" <?php echo ($cantidadMenus === 0) ? "selected" : ""; ?>>Sin menú / Solo extras</option>
                <?php for ($n = 1; $n <= 5; $n++): ?>
                    <option value="<?php echo $n; ?>" <?php echo ($cantidadMenus === $n) ? "selected" : ""; ?>>
                        <?php echo $n; ?> menú<?php echo $n > 1 ? "s" : ""; ?>
                    </option>
                <?php endfor; ?>
            </select>

        </section>

        <!-- BLOQUES DE MENÚ -->
        <?php for ($i = 1; $i <= 5; $i++):
            $tipoSel  = $_POST["menus"][$i]["tipo_menu"] ?? "Zabisu";
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
                    <option value="Zabisu"    <?php echo ($tipoSel === "Zabisu")    ? "selected" : ""; ?>>Menú Zabisu</option>
                    <option value="Ejecutivo" <?php echo ($tipoSel === "Ejecutivo") ? "selected" : ""; ?>>Menú Ejecutivo</option>
                </select>

                <p class="precio-menu" id="precio-menu-<?php echo $i; ?>">
                    Precio de este menú: <strong>$<?php echo number_format($preciosMenus[$tipoSel] ?? 0, 2); ?></strong>
                </p>

                <?php foreach (["Zabisu", "Ejecutivo"] as $tipoMenu): ?>
                    <div class="opciones-menu-tipo menu-<?php echo $i; ?>-tipo"
                         data-menu="<?php echo $i; ?>"
                         data-tipo="<?php echo $tipoMenu; ?>"
                         style="<?php echo ($tipoSel === $tipoMenu) ? 'display:block;' : 'display:none;'; ?>">

                        <?php if (!empty($menusPorTipo[$tipoMenu]["Plato fuerte"])): ?>
                            <div class="grupo-categoria">
                                <h3>🍽️ Plato fuerte <span class="cat-hint">· elige 1</span></h3>
                                <?php foreach ($menusPorTipo[$tipoMenu]["Plato fuerte"] as $item): ?>
                                    <label class="opcion-producto <?php echo !empty($item["agotado"]) ? 'producto-agotado' : ''; ?>">
                                        <input type="radio" name="menus[<?php echo $i; ?>][plato_fuerte]" value="<?php echo $item["id_producto"]; ?>"
                                               <?php echo (($_POST["menus"][$i]["plato_fuerte"] ?? "") == $item["id_producto"]) ? "checked" : ""; ?>
                                               <?php echo !empty($item["agotado"]) ? 'disabled' : ''; ?>>
                                        <strong><?php echo htmlspecialchars($item["nombre"]); ?></strong> — <?php echo htmlspecialchars($item["descripcion"]); ?>
                                        <?php if (!empty($item["agotado"])): ?><span class="badge-agotado">Agotado</span><?php endif; ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($menusPorTipo[$tipoMenu]["Sopa"])): ?>
                            <div class="grupo-categoria">
                                <h3>🥣 Sopa <span class="cat-hint">· elige 1</span></h3>
                                <?php foreach ($menusPorTipo[$tipoMenu]["Sopa"] as $item): ?>
                                    <label class="opcion-producto">
                                        <input type="radio" name="menus[<?php echo $i; ?>][sopa]" value="<?php echo $item["id_producto"]; ?>"
                                               <?php echo (($_POST["menus"][$i]["sopa"] ?? "") == $item["id_producto"]) ? "checked" : ""; ?>>
                                        <strong><?php echo htmlspecialchars($item["nombre"]); ?></strong> — <?php echo htmlspecialchars($item["descripcion"]); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($menusPorTipo[$tipoMenu]["Complemento"])): ?>
                            <div class="grupo-categoria">
                                <h3>🥗 Complementos <span class="cat-hint">· elige hasta 2</span></h3>
                                <?php foreach ($menusPorTipo[$tipoMenu]["Complemento"] as $item): ?>
                                    <label class="opcion-producto">
                                        <input type="checkbox" name="menus[<?php echo $i; ?>][complementos][]" value="<?php echo $item["id_producto"]; ?>"
                                               <?php echo in_array($item["id_producto"], $_POST["menus"][$i]["complementos"] ?? []) ? "checked" : ""; ?>>
                                        <strong><?php echo htmlspecialchars($item["nombre"]); ?></strong> — <?php echo htmlspecialchars($item["descripcion"]); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($menusPorTipo[$tipoMenu]["Agua"])): ?>
                            <div class="grupo-categoria">
                                <h3>💧 Agua <span class="cat-hint">· elige 1</span></h3>
                                <?php foreach ($menusPorTipo[$tipoMenu]["Agua"] as $item): ?>
                                    <label class="opcion-producto">
                                        <input type="radio" name="menus[<?php echo $i; ?>][agua]" value="<?php echo $item["id_producto"]; ?>"
                                               <?php echo (($_POST["menus"][$i]["agua"] ?? "") == $item["id_producto"]) ? "checked" : ""; ?>>
                                        <strong><?php echo htmlspecialchars($item["nombre"]); ?></strong> — <?php echo htmlspecialchars($item["descripcion"]); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($menusPorTipo[$tipoMenu]["Postre"])): ?>
                            <div class="grupo-categoria">
                                <h3>🍮 Postre <span class="cat-hint">· elige 1</span></h3>
                                <?php foreach ($menusPorTipo[$tipoMenu]["Postre"] as $item): ?>
                                    <label class="opcion-producto">
                                        <input type="radio" name="menus[<?php echo $i; ?>][postre]" value="<?php echo $item["id_producto"]; ?>"
                                               <?php echo (($_POST["menus"][$i]["postre"] ?? "") == $item["id_producto"]) ? "checked" : ""; ?>>
                                        <strong><?php echo htmlspecialchars($item["nombre"]); ?></strong> — <?php echo htmlspecialchars($item["descripcion"]); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                    </div>
                <?php endforeach; ?>
            </section>
        <?php endfor; ?>

        <!-- EXTRAS -->
        <section class="bloque-formulario" id="bloque-extras" style="<?php echo $cantidadMenus === 0 ? '' : 'display:none;'; ?>">
            <h2>Extras</h2>
            <?php if (!empty($extrasForm)): ?>
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
            <?php else: ?>
                <p class="nota-formulario">No hay productos disponibles para extras.</p>
            <?php endif; ?>
        </section>

        <!-- ENTREGA -->
        <section class="bloque-formulario" id="bloque-entrega">
            <h2>Punto de entrega</h2>

            <div class="grupo-tipo-ubicacion">
                <h3 class="titulo-tipo-ubicacion">Entregas</h3>
                <?php foreach ($ubicaciones as $ubicacion): ?>
                    <?php if ($ubicacion["tipo"] === "entrega"): ?>
                        <div class="grupo-ubicacion-selector">
                            <label class="opcion-ubicacion entrega <?php echo (($_POST["id_horario"] ?? "") !== "" && in_array($_POST["id_horario"] ?? "", array_column(array_filter($horarios, fn($h) => $h["id_ubicacion"] == $ubicacion["id_ubicacion"]), "id_horario"))) ? "ubicacion-activa" : ""; ?>">
                                <input type="radio" name="ubicacion_selector" value="<?php echo $ubicacion["id_ubicacion"]; ?>" class="radio-ubicacion">
                                <strong><?php echo htmlspecialchars($ubicacion["nombre_ubicacion"]); ?></strong>
                            </label>
                            <div class="horarios-ocultos" id="horarios-<?php echo $ubicacion["id_ubicacion"]; ?>">
                                <?php foreach ($horarios as $horario): ?>
                                    <?php if ($horario["id_ubicacion"] == $ubicacion["id_ubicacion"]): ?>
                                        <label class="opcion-horario">
                                            <input type="radio" name="id_horario" value="<?php echo $horario["id_horario"]; ?>"
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

        <!-- PAGO Y CONFIRMAR -->
        <section class="bloque-formulario" id="bloque-confirmar">
            <h2>Pago</h2>

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

            <div class="ticket-total" style="margin:18px 0 16px;">
                <span>Total</span>
                <strong id="total-general">$0.00</strong>
            </div>

            <button type="submit" name="guardar_pedido" value="1" class="btn-principal" style="width:100%;">
                Guardar pedido
            </button>
        </section>

    </form>

    <?php endif; ?>

</div>

<script>
document.addEventListener("DOMContentLoaded", function () {

    // ── Nombre / Teléfono ─────────────────────────────────────────
    const nombreInput = document.getElementById("nombre_cliente");
    if (nombreInput) nombreInput.addEventListener("input", function () {
        this.value = this.value.replace(/[^\p{L}\s]/gu, "").toUpperCase();
    });

    const telefonoInput = document.getElementById("telefono");
    if (telefonoInput) telefonoInput.addEventListener("input", function () {
        this.value = this.value.replace(/\D/g, "").slice(0, 10);
    });

    // ── Cantidad de menús ─────────────────────────────────────────
    function actualizarBloquesMenues(cantidad) {
        const bloqueExtras = document.getElementById("bloque-extras");
        if (bloqueExtras) bloqueExtras.style.display = cantidad === 0 ? "" : "none";

        for (let i = 1; i <= 5; i++) {
            const bloque = document.getElementById("menu-bloque-" + i);
            if (!bloque) continue;
            const visible = cantidad > 0 && i <= cantidad;
            bloque.style.display = visible ? "" : "none";
            bloque.querySelectorAll("input, select").forEach(function (el) {
                if (!visible) {
                    if (!el.disabled) { el.disabled = true; el.dataset.sd = "1"; }
                } else if (el.dataset.sd) {
                    el.disabled = false; delete el.dataset.sd;
                }
            });
        }
        actualizarTotal();
    }

    const selCantidad = document.getElementById("cantidad_menus");
    if (selCantidad) selCantidad.addEventListener("change", function () {
        actualizarBloquesMenues(parseInt(this.value) || 1);
    });

    // ── Opciones por tipo de menú ─────────────────────────────────
    window.actualizarOpcionesMenu = function (menuNum) {
        const sel = document.getElementById("tipo_menu_" + menuNum);
        if (!sel) return;
        const tipo = sel.value;
        document.querySelectorAll(".menu-" + menuNum + "-tipo").forEach(function (div) {
            const visible = div.dataset.tipo === tipo;
            div.style.display = visible ? "block" : "none";
            div.querySelectorAll("input").forEach(function (inp) { inp.disabled = !visible; });
        });
        actualizarTotal();
    };

    // ── Ubicaciones y horarios ────────────────────────────────────
    function inicializarEntrega() {
        document.querySelectorAll(".horarios-ocultos").forEach(function (b) { b.style.display = "none"; });

        document.querySelectorAll(".opcion-ubicacion").forEach(function (label) {
            label.onclick = function () {
                const radio = this.querySelector(".radio-ubicacion");
                if (radio) radio.checked = true;
                document.querySelectorAll(".opcion-ubicacion").forEach(function (l) { l.classList.remove("ubicacion-activa"); });
                this.classList.add("ubicacion-activa");
                document.querySelectorAll(".horarios-ocultos").forEach(function (b) { b.style.display = "none"; });
                const grupo = this.closest(".grupo-ubicacion-selector");
                if (!grupo) return;
                const bloque = grupo.querySelector(".horarios-ocultos");
                if (bloque) bloque.style.display = "block";
            };
        });

        // Restaurar selección previa (vuelta de POST con errores)
        const horarioSel = document.querySelector("input[name='id_horario']:checked");
        if (horarioSel) {
            const contenedor = horarioSel.closest(".horarios-ocultos");
            if (contenedor) {
                contenedor.style.display = "block";
                const grupo = contenedor.closest(".grupo-ubicacion-selector");
                if (grupo) {
                    const rb = grupo.querySelector(".radio-ubicacion");
                    if (rb) rb.checked = true;
                    const lb = grupo.querySelector(".opcion-ubicacion");
                    if (lb) lb.classList.add("ubicacion-activa");
                }
            }
        }
    }

    inicializarEntrega();

    // ── Total ─────────────────────────────────────────────────────
    const preciosMenus = {
        Zabisu:    <?php echo json_encode($preciosMenus["Zabisu"]    ?? 0); ?>,
        Ejecutivo: <?php echo json_encode($preciosMenus["Ejecutivo"] ?? 0); ?>
    };

    function actualizarTotal() {
        const cantidad = parseInt(document.getElementById("cantidad_menus").value) || 0;
        let total = 0;

        if (cantidad === 0) {
            document.querySelectorAll(".extra-item").forEach(function (item) {
                const cant   = parseInt(item.querySelector(".extra-cantidad").value) || 0;
                const precio = parseFloat(item.dataset.precio) || 0;
                total += cant * precio;
            });
        } else {
            document.querySelectorAll(".selector-tipo-menu").forEach(function (sel) {
                const n = sel.getAttribute("data-menu");
                const bloque = document.getElementById("menu-bloque-" + n);
                if (bloque && bloque.style.display === "none") return;
                const precio = preciosMenus[sel.value] || 0;
                total += precio;
                const precioEl = document.getElementById("precio-menu-" + n);
                if (precioEl) precioEl.innerHTML = "Precio de este menú: <strong>$" + precio.toFixed(2) + "</strong>";
            });
        }

        const totalEl = document.getElementById("total-general");
        if (totalEl) totalEl.textContent = "$" + total.toFixed(2);
    }

    document.querySelectorAll(".selector-tipo-menu").forEach(function (sel) {
        sel.addEventListener("change", function () { actualizarOpcionesMenu(this.getAttribute("data-menu")); });
    });

    // ── Highlight al seleccionar ──────────────────────────────────
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
                    const l = r.closest(".opcion-producto");
                    if (l) l.classList.remove("opcion-seleccionada");
                });
            }
            const l = this.closest(".opcion-producto");
            if (l) l.classList.toggle("opcion-seleccionada", this.checked);
            actualizarTotal();
        });
    });

    // ── Contadores de extras ─────────────────────────────────────
    document.querySelectorAll(".extra-item").forEach(function (item) {
        const menos = item.querySelector(".extra-btn-menos");
        const mas   = item.querySelector(".extra-btn-mas");
        const input = item.querySelector(".extra-cantidad");

        menos.addEventListener("click", function () {
            const v = parseInt(input.value) || 0;
            if (v > 0) {
                input.value = v - 1;
                item.classList.toggle("extra-item--activo", (v - 1) > 0);
                actualizarTotal();
            }
        });
        mas.addEventListener("click", function () {
            const v = parseInt(input.value) || 0;
            if (v < 5) {
                input.value = v + 1;
                item.classList.add("extra-item--activo");
                actualizarTotal();
            }
        });
    });

    actualizarSeleccionVisual();
    actualizarBloquesMenues(<?php echo (int)$cantidadMenus; ?>);
    actualizarTotal();
});
</script>

</body>
</html>
