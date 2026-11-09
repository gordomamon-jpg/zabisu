<?php
require_once "../config/db.php";
require_once "auth_check.php";
require_once "../includes/opciones_plato.php";
require_once "../includes/agua_natural.php";
date_default_timezone_set("America/Mexico_City");

// Menú más reciente CON productos (si se duplicó un menú y uno quedó vacío,
// no debe ganar el vacío); en empate, el activo y el más nuevo.
$stmtMenu = $conexion->prepare("SELECT * FROM menu_dia m WHERE EXISTS (SELECT 1 FROM productos p WHERE p.id_menu = m.id_menu) ORDER BY m.fecha DESC, m.activo DESC, m.id_menu DESC LIMIT 1");
$stmtMenu->execute();
$menuActivo = $stmtMenu->fetch(PDO::FETCH_ASSOC);

$fechaMenuFormateada = "";
if ($menuActivo) {
    $dias  = ['Sunday'=>'domingo','Monday'=>'lunes','Tuesday'=>'martes','Wednesday'=>'miércoles','Thursday'=>'jueves','Friday'=>'viernes','Saturday'=>'sábado'];
    $meses = ['01'=>'enero','02'=>'febrero','03'=>'marzo','04'=>'abril','05'=>'mayo','06'=>'junio','07'=>'julio','08'=>'agosto','09'=>'septiembre','10'=>'octubre','11'=>'noviembre','12'=>'diciembre'];
    $ts    = strtotime($menuActivo["fecha"]);
    $fechaMenuFormateada = $dias[date('l',$ts)] . " " . date('j',$ts) . " de " . ($meses[date('m',$ts)] ?? "");
}

$menusPorTipo       = [];
$productosIndexados = [];

if ($menuActivo) {
    aguaNaturalAsegurar($conexion, (int)$menuActivo["id_menu"]);
    $stmtProd = $conexion->prepare(
        "SELECT * FROM productos WHERE id_menu = :id_menu AND disponible = 1
         ORDER BY tipo_menu, categoria, nombre"
    );
    $stmtProd->execute([":id_menu" => $menuActivo["id_menu"]]);

    $stmtCont = $conexion->prepare(
        "SELECT dp.id_producto, COUNT(*) AS total_pedidos
         FROM detalle_pedido dp
         INNER JOIN pedido_menus pm ON dp.id_pedido_menu = pm.id_pedido_menu
         INNER JOIN pedidos p       ON pm.id_pedido = p.id_pedido
         INNER JOIN productos pr    ON dp.id_producto = pr.id_producto
         WHERE p.estado != 'Cancelado' AND p.es_prueba = 0 AND pr.id_menu = :id_menu
         GROUP BY dp.id_producto"
    );
    $stmtCont->execute([":id_menu" => $menuActivo["id_menu"]]);
    $conteos = [];
    foreach ($stmtCont->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $conteos[$row["id_producto"]] = (int)$row["total_pedidos"];
    }

    foreach ($stmtProd->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $idP = (int)$p["id_producto"];
        $p["agotado"] = false; // la tableta ignora agotado — sin restricciones
        $menusPorTipo[$p["tipo_menu"]][$p["categoria"]][] = $p;
        $productosIndexados[$idP] = $p;
    }
    aguaOrdenarMenus($menusPorTipo); // el agua del día primero, la natural después
}

$stmtTipos = $conexion->prepare("SELECT nombre_menu, precio FROM tipos_menu WHERE activo = 1");
$stmtTipos->execute();
$preciosMenus = [];
foreach ($stmtTipos->fetchAll(PDO::FETCH_ASSOC) as $t) {
    $preciosMenus[$t["nombre_menu"]] = (float)$t["precio"];
}

/* ── Extras disponibles ── */
$PRECIOS_EXTRA = ["Sopa" => 25, "Complemento" => 25, "Agua" => 20];
$extrasForm = [];
$nombresExtrasForm = [];
foreach (["Sopa", "Complemento", "Agua"] as $catEx) {
    foreach ($menusPorTipo as $tipoM => $cats) {
        if (!empty($cats[$catEx])) {
            foreach ($cats[$catEx] as $prod) {
                if (aguaEsNatural($prod)) continue; // la natural no se vende como extra
                if (!in_array($prod["nombre"], $nombresExtrasForm)) {
                    $nombresExtrasForm[] = $prod["nombre"];
                    $extrasForm[$catEx][] = $prod;
                }
            }
        }
    }
}

/* ── Postre extra (precio desde la BD) ── */
$postreExtra = $menusPorTipo["Zabisu"]["Postre"][0] ?? null;
if ($postreExtra && (float)($postreExtra["precio"] ?? 0) > 0) {
    $extrasForm["Postre"][] = $postreExtra;
}

$iconosExtra = ["Sopa" => "🥣", "Complemento" => "🥗", "Agua" => "💧", "Postre" => "🍮"];

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

$cantidadMenus = isset($_POST["cantidad_menus"]) ? max(0, min(10, (int)$_POST["cantidad_menus"])) : 1;

$errores      = [];
$erroresMenus = [];
$exito        = false;
$folioCreado  = "";
$totalCreado  = 0.0;

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["guardar_pedido"])) {
    $nombre_cliente = strtoupper(trim(preg_replace('/[^\p{L}\s]/u', '', $_POST["nombre_cliente"] ?? "")));
    $id_horario     = $_POST["id_horario"] ?? "";
    $observaciones  = trim($_POST["observaciones"] ?? "");
    $menusRecibidos = $_POST["menus"] ?? [];

    if ($nombre_cliente === "") $errores[] = "El nombre del cliente es obligatorio.";
    if ($id_horario === "")    $errores[] = "Selecciona un punto de entrega y horario.";
    if ($cantidadMenus > 0 && empty($menusRecibidos)) $errores[] = "Agrega al menos una comida.";
    if ($cantidadMenus === 0) {
        $hayExtras = false;
        foreach ($_POST["extras"] ?? [] as $cant) { if ((int)$cant > 0) { $hayExtras = true; break; } }
        if (!$hayExtras) {
            foreach ($_POST["extras_custom"] ?? [] as $c) {
                if (trim($c["nombre"] ?? "") !== "" && (float)($c["precio"] ?? 0) > 0) { $hayExtras = true; break; }
            }
        }
        if (!$hayExtras) $errores[] = "Agrega al menos un extra.";
    }

    foreach ($menusRecibidos as $nMenu => $menu) {
        $tipo_menu    = $menu["tipo_menu"]    ?? "";
        $plato_fuerte = $menu["plato_fuerte"] ?? "";
        // Solo cuentan los complementos que de verdad son de este menú y de este tipo
        $complementos = array_values(array_filter(array_map('intval', (array)($menu["complementos"] ?? [])), fn($id) =>
            isset($productosIndexados[$id]) && $productosIndexados[$id]["categoria"] === "Complemento" && $productosIndexados[$id]["tipo_menu"] === $tipo_menu));
        $menusRecibidos[$nMenu]["complementos"] = $complementos;

        if (!in_array($tipo_menu, ["Zabisu","Ejecutivo"], true))
            $erroresMenus[$nMenu][] = "Selecciona el tipo de menú.";
        if ($plato_fuerte === "") {
            $erroresMenus[$nMenu][] = "Falta el guisado.";
        } elseif (!ctype_digit((string)$plato_fuerte) || !isset($productosIndexados[$plato_fuerte])
                  || $productosIndexados[$plato_fuerte]["categoria"] !== "Plato fuerte" || $productosIndexados[$plato_fuerte]["tipo_menu"] !== $tipo_menu) {
            $erroresMenus[$nMenu][] = "Ese guisado ya no está en el menú (se subió uno nuevo). Vuelve a elegirlo.";
            $plato_fuerte = "";
        }

        // Opción sin costo del plato (p. ej. aderezo de la ensalada)
        $menusRecibidos[$nMenu]["opcion"] = null;
        if ($plato_fuerte !== "" && isset($productosIndexados[$plato_fuerte])) {
            $resOpcion = validarOpcionPlato($productosIndexados[$plato_fuerte], $menu["opcion"] ?? "");
            if ($resOpcion["error"]) $erroresMenus[$nMenu][] = $resOpcion["error"];
            $menusRecibidos[$nMenu]["opcion"] = $resOpcion["opcion"];
        }
        // El máximo lo pone el guisado (la ensalada ejecutiva lleva 1), igual que en el cliente y el Bigotón
        $maxComp = ($plato_fuerte !== "" && !empty($productosIndexados[$plato_fuerte]["complementos_max"]))
            ? (int)$productosIndexados[$plato_fuerte]["complementos_max"] : 2;
        if (empty($complementos))            $erroresMenus[$nMenu][] = "Falta al menos un complemento.";
        if (count($complementos) > $maxComp) $erroresMenus[$nMenu][] = "Máximo {$maxComp} complemento" . ($maxComp !== 1 ? "s" : "") . " con este guisado.";
    }

    foreach ($erroresMenus as $nMenu => $lista) {
        foreach ($lista as $msg) $errores[] = "Comida {$nMenu}: {$msg}";
    }

    if (empty($errores)) {
        /* Procesar extras */
        $extrasGuardar = [];
        foreach ($_POST["extras"] ?? [] as $idProducto => $cantidad) {
            $cantidad = min((int)$cantidad, 5);
            if ($cantidad <= 0 || !isset($productosIndexados[$idProducto])) continue;
            $prod = $productosIndexados[$idProducto];
            $precioExtra = ($prod["categoria"] === "Postre")
                ? (float)($prod["precio"] ?? 0)
                : ($PRECIOS_EXTRA[$prod["categoria"]] ?? 25.00);
            $extrasGuardar[] = [
                "id_producto"     => (int)$idProducto,
                "nombre"          => $prod["nombre"],
                "categoria"       => $prod["categoria"],
                "cantidad"        => $cantidad,
                "precio_unitario" => $precioExtra,
            ];
        }

        /* Extras personalizados */
        foreach ($_POST["extras_custom"] ?? [] as $custom) {
            $nombre   = trim($custom["nombre"] ?? "");
            $precio   = max(0.0, (float)str_replace(",", ".", $custom["precio"] ?? "0"));
            $cantidad = max(1, min(10, (int)($custom["cantidad"] ?? 1)));
            if ($nombre === "" || $precio <= 0) continue;
            $extrasGuardar[] = [
                "id_producto"     => null,
                "nombre"          => $nombre,
                "categoria"       => "Personalizado",
                "cantidad"        => $cantidad,
                "precio_unitario" => $precio,
            ];
        }

        foreach ($menusRecibidos as $num => &$menuData) {
            $tipo = $menuData["tipo_menu"] ?? "";
            foreach (["Sopa"=>"sopa","Cortesia"=>"cortesia"] as $cat => $key) {
                if (!empty($menusPorTipo[$tipo][$cat])) {
                    $menuData[$key] = (string)$menusPorTipo[$tipo][$cat][0]["id_producto"];
                }
            }
            // Agua: la que se tocó (del día o natural)
            $menuData["agua"] = aguaElegida($menusPorTipo[$tipo]["Agua"] ?? [], $menuData["agua_tipo"][$tipo] ?? "");
            unset($menuData["agua_tipo"]);
        }
        unset($menuData);

        try {
            $conexion->beginTransaction();

            $totalPedido = 0.0;
            foreach ($menusRecibidos as $m) {
                $totalPedido += $preciosMenus[$m["tipo_menu"] ?? ""] ?? 0;
            }
            foreach ($extrasGuardar as $ex) {
                $totalPedido += $ex["cantidad"] * $ex["precio_unitario"];
            }

            $folio = "TAB-" . date("Ymd") . "-" . strtoupper(substr(md5(uniqid()), 0, 5));

            $stmtPed = $conexion->prepare(
                "INSERT INTO pedidos
                 (folio, nombre_cliente, telefono, correo_cliente, id_horario, metodo_pago,
                  observaciones, total, estado, estado_pago, es_prueba, referencia_pago)
                 VALUES (:folio,:nombre_cliente,'0000000000','',:id_horario,'Efectivo',
                         :observaciones,:total,'Pendiente','Pago en efectivo',:es_prueba,:folio2)"
            );
            $stmtPed->execute([
                ":folio"          => $folio,
                ":nombre_cliente" => $nombre_cliente,
                ":id_horario"     => $id_horario,
                ":observaciones"  => $observaciones,
                ":total"          => $totalPedido,
                ":es_prueba"      => 0,
                ":folio2"         => $folio,
            ]);
            $id_pedido = (int)$conexion->lastInsertId();

            $stmtPM  = $conexion->prepare("INSERT INTO pedido_menus (id_pedido,tipo_menu,numero_menu) VALUES (:id_pedido,:tipo_menu,:numero_menu)");
            $stmtDet = $conexion->prepare("INSERT INTO detalle_pedido (id_pedido_menu,id_producto,categoria,nombre_producto,opcion) VALUES (:id_pedido_menu,:id_producto,:categoria,:nombre_producto,:opcion)");

            $nMenu = 1;
            foreach ($menusRecibidos as $m) {
                $stmtPM->execute([":id_pedido"=>$id_pedido,":tipo_menu"=>$m["tipo_menu"],":numero_menu"=>$nMenu]);
                $id_pedido_menu = (int)$conexion->lastInsertId();
                $ids = array_filter(array_merge(
                    [(int)($m["plato_fuerte"]??0),(int)($m["sopa"]??0),(int)($m["agua"]??0),(int)($m["cortesia"]??0)],
                    array_map('intval', $m["complementos"]??[])
                ));
                foreach ($ids as $idP) {
                    if (!$idP || !isset($productosIndexados[$idP])) continue;
                    $stmtDet->execute([
                        ":id_pedido_menu"  => $id_pedido_menu,
                        ":id_producto"     => $idP,
                        ":categoria"       => $productosIndexados[$idP]["categoria"],
                        ":nombre_producto" => $productosIndexados[$idP]["nombre"],
                        ":opcion"          => ($idP === (int)($m["plato_fuerte"] ?? 0)) ? ($m["opcion"] ?? null) : null,
                    ]);
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
            $exito       = true;
            $folioCreado = $folio;
            $totalCreado = $totalPedido;

        } catch (Exception $e) {
            $conexion->rollBack();
            $errores[] = "Error al guardar: " . $e->getMessage();
        }
    }
}

/* ── Entrega: todos los puntos con todos sus horarios; el más usado desde la tableta va primero ── */
$stmtUso = $conexion->prepare(
    "SELECT h.id_ubicacion, COUNT(*) AS n
     FROM pedidos p
     INNER JOIN horarios_ubicacion h ON p.id_horario = h.id_horario
     WHERE p.folio LIKE 'TAB-%' AND p.es_prueba = 0
       AND p.fecha_pedido >= DATE_SUB(NOW(), INTERVAL 60 DAY)
     GROUP BY h.id_ubicacion"
);
$stmtUso->execute();
$usoPorPunto = array_map('intval', $stmtUso->fetchAll(PDO::FETCH_KEY_PAIR));

$horariosPorId = [];
$horasPorPunto = [];
foreach ($horarios as $h) {
    $horariosPorId[(int)$h["id_horario"]] = $h;
    $horasPorPunto[(int)$h["id_ubicacion"]][] = $h;
}

$puntosEntrega = array_values(array_filter($ubicaciones, fn($u) => $u["tipo"] === "entrega" && !empty($horasPorPunto[(int)$u["id_ubicacion"]])));
usort($puntosEntrega, fn($a, $b) => ($usoPorPunto[(int)$b["id_ubicacion"]] ?? 0) <=> ($usoPorPunto[(int)$a["id_ubicacion"]] ?? 0)
                                    ?: strcmp($a["nombre_ubicacion"], $b["nombre_ubicacion"]));
$horaBonita = fn($hora) => date("g:i a", strtotime($hora));

/* Para la pantalla de éxito: a dónde va el pedido */
$entregaCreada = "";
if ($exito && isset($horariosPorId[(int)$id_horario])) {
    $hc = $horariosPorId[(int)$id_horario];
    $entregaCreada = $hc["nombre_ubicacion"] . ", " . $horaBonita($hc["hora_entrega"]);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<?php require __DIR__ . "/../includes/rz_app.php"; ?>
<title>Pedido tableta | Zabisu</title>
<link rel="icon" type="image/png" href="../assets/img/LOGO_NARA.png">
<meta name="theme-color" content="#151413">
<link rel="preload" href="../assets/fonts/kaushan-script-400.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="../assets/fonts/alfa-slab-one-400.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="../assets/css/menu-pizarron.css?v=2">
<link rel="stylesheet" href="../assets/css/tableta-pizarron.css?v=5">
</head>
<body class="pz tb">
<?php require __DIR__ . "/../includes/pz_filtros.php"; ?>

<header class="tb-cab">
    <a class="pz-marca" href="panel_general.php" aria-label="Zabisu, volver al panel general">
        <img src="../assets/img/LOGO_N_128.png" alt="">
        <div>
            <span class="pz-marca__nombre">Zabisu</span>
            <span class="pz-marca__lema">Sabor y Servicio</span>
        </div>
    </a>
    <div class="tb-cab__titulo">
        <h1 class="pz-titulo"><span class="pz-pincel"><span>Pedido rápido</span></span></h1>
        <?php if ($fechaMenuFormateada): ?><p>Menú del <?= htmlspecialchars($fechaMenuFormateada) ?></p><?php endif; ?>
    </div>
    <a class="tb-cab__panel" href="panel_general.php">Panel</a>
</header>

<?php if ($exito): ?>
<!-- ══ PEDIDO GUARDADO ══ -->
<main class="tb-listo">
    <p class="tb-listo__mano">¡Listo!</p>
    <div class="pz-boleto tb-boleto">
        <div class="pz-boleto__papel">
            <span class="pz-boleto__ojo" aria-hidden="true"></span>
            <p class="tb-boleto__folio"><span>Folio</span><b><?= htmlspecialchars($folioCreado) ?></b></p>
            <hr class="pz-boleto__corte">
            <dl class="tb-boleto__lineas">
                <div><dt>Cliente</dt><dd><?= htmlspecialchars($nombre_cliente) ?></dd></div>
                <?php if ($entregaCreada): ?><div><dt>Entrega</dt><dd><?= htmlspecialchars($entregaCreada) ?></dd></div><?php endif; ?>
                <div><dt>Comidas</dt><dd><?= count($menusRecibidos) ?></dd></div>
                <div class="tb-boleto__total"><dt>Total</dt><dd>$<?= number_format($totalCreado, 0) ?></dd></div>
            </dl>
        </div>
    </div>
    <a href="pedido_tableta.php" class="pz-btn tb-nuevo" id="tb-nuevo" autofocus>Nuevo pedido</a>
    <a href="pedidos.php" class="tb-listo__liga">Ver en el panel de pedidos</a>
</main>

<?php elseif (!$menuActivo): ?>
<main class="tb-listo">
    <p class="tb-listo__mano">Sin menú</p>
    <p class="tb-listo__txt">No hay menú disponible. Crea un menú primero.</p>
    <a href="nuevo_menu.php" class="pz-btn tb-nuevo">Crear menú</a>
</main>

<?php else: ?>
<form method="POST" action="pedido_tableta.php" id="form-tableta" class="tb-form" novalidate>
<input type="hidden" name="guardar_pedido" value="1">

<!-- ══ LA COMANDA: quién, a dónde, nota, total y guardar ══ -->
<aside class="tb-lado" aria-label="Datos del pedido">

    <?php if (!empty($errores)): ?>
    <div class="tb-errores" role="alert">
        <p>No se guardó. Revisa:</p>
        <ul><?php foreach ($errores as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
    </div>
    <?php endif; ?>

    <label class="tb-campo">
        <span>Nombre del cliente</span>
        <input type="text" class="tb-nombre" name="nombre_cliente" id="t-nombre"
               maxlength="80" autocomplete="off" autocorrect="off" spellcheck="false" autocapitalize="characters"
               value="<?= htmlspecialchars($_POST["nombre_cliente"] ?? "") ?>"<?= empty($_POST) ? " autofocus" : "" ?>>
    </label>

    <section class="tb-entrega" id="tb-entrega">
        <p class="tb-titulo">Entrega</p>
        <div class="tb-puntos">
            <?php foreach ($puntosEntrega as $ub): ?>
            <div class="tb-punto">
                <p class="tb-punto__nombre"><?= htmlspecialchars($ub["nombre_ubicacion"]) ?></p>
                <div class="tb-horas">
                    <?php foreach ($horasPorPunto[(int)$ub["id_ubicacion"]] as $h): ?>
                    <label class="tb-hora">
                        <input type="radio" name="id_horario" value="<?= (int)$h["id_horario"] ?>"
                               aria-label="<?= htmlspecialchars($ub["nombre_ubicacion"] . ", " . $horaBonita($h["hora_entrega"])) ?>"
                               <?= (($_POST["id_horario"] ?? "") == $h["id_horario"]) ? "checked" : "" ?>>
                        <span><?= date("g:i", strtotime($h["hora_entrega"])) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </section>

    <?php $obsVal = $_POST["observaciones"] ?? ""; ?>
    <details class="tb-nota"<?= $obsVal !== "" ? " open" : "" ?>>
        <summary>Nota para cocina <small>(opcional)</small></summary>
        <textarea name="observaciones" id="t-obs-textarea" rows="2" placeholder="Sin cebolla, alergia a..."><?= htmlspecialchars($obsVal) ?></textarea>
    </details>

    <div class="pz-barra tb-barra">
        <div class="tb-total"><span id="tb-resumen">1 comida</span><b id="t-total">$0</b></div>
        <button type="submit" name="guardar_pedido" value="1" class="pz-btn tb-guardar" id="t-submit">Guardar pedido</button>
    </div>
</aside>

<!-- ══ LAS COMIDAS ══ -->
<div class="tb-main">

    <div class="tb-cuantas">
        <span>Comidas</span>
        <div class="tb-stepper">
            <button type="button" id="t-menos" aria-label="Una comida menos"><svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path d="M5 12h14" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"/></svg></button>
            <output id="t-num-display" aria-live="polite"><?= (int)$cantidadMenus ?></output>
            <button type="button" id="t-mas" aria-label="Una comida más"><svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path d="M12 5v14M5 12h14" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"/></svg></button>
        </div>
        <input type="hidden" name="cantidad_menus" id="t-cantidad" value="<?= (int)$cantidadMenus ?>">
    </div>
    <p class="tb-solo-extras" id="t-hint-extras" hidden>Solo extras: agrega abajo lo que lleva.</p>

    <?php for ($i = 1; $i <= 10; $i++):
        $tipoSel  = $_POST["menus"][$i]["tipo_menu"] ?? "Zabisu";
        $tieneErr = !empty($erroresMenus[$i]);
    ?>
    <section class="tb-comida<?= $tieneErr ? " tb-comida--error" : "" ?>" id="t-menu-<?= $i ?>" data-menu="<?= $i ?>"<?= $i > $cantidadMenus ? " hidden" : "" ?>>
        <div class="tb-comida__cab">
            <h2 class="pz-cinta pz-fibra">Comida <?= $i ?></h2>
            <span class="tb-comida__estado" aria-live="polite"></span>
            <button type="button" class="tb-duplicar" data-menu="<?= $i ?>">Duplicar</button>
        </div>

        <div class="tb-tipos">
            <?php foreach (["Zabisu","Ejecutivo"] as $tipo): ?>
            <button type="button" class="tb-tipo<?= $tipoSel === $tipo ? " tb-tipo--activo" : "" ?>" data-menu="<?= $i ?>" data-tipo="<?= $tipo ?>" aria-pressed="<?= $tipoSel === $tipo ? "true" : "false" ?>">
                <span><?= $tipo ?></span><b>$<?= number_format($preciosMenus[$tipo] ?? 0, 0) ?></b>
            </button>
            <?php endforeach; ?>
        </div>
        <input type="hidden" name="menus[<?= $i ?>][tipo_menu]" id="t-tipo-val-<?= $i ?>" value="<?= htmlspecialchars($tipoSel) ?>">

        <?php foreach (["Zabisu","Ejecutivo"] as $tipoMenu): $vis = $tipoSel === $tipoMenu; ?>
        <div class="t-opciones tb-ops" id="t-ops-<?= $i ?>-<?= $tipoMenu ?>" data-menu="<?= $i ?>" data-tipo="<?= $tipoMenu ?>"<?= $vis ? "" : " hidden" ?>>

            <?php if (!empty($menusPorTipo[$tipoMenu]["Plato fuerte"])): ?>
            <p class="tb-titulo">Guisado</p>
            <div class="tb-grid">
                <?php foreach ($menusPorTipo[$tipoMenu]["Plato fuerte"] as $prod):
                    $chk = (($_POST["menus"][$i]["plato_fuerte"] ?? "") == $prod["id_producto"] && $vis); ?>
                <label class="tb-card">
                    <input type="radio" name="menus[<?= $i ?>][plato_fuerte]" value="<?= (int)$prod["id_producto"] ?>"
                           data-max="<?= !empty($prod["complementos_max"]) ? (int)$prod["complementos_max"] : 2 ?>"
                           <?= $chk ? "checked" : "" ?><?= $vis ? "" : " disabled" ?>>
                    <span><?= htmlspecialchars($prod["nombre"]) ?></span>
                </label>
                <?php endforeach; ?>
            </div>
            <?php foreach ($menusPorTipo[$tipoMenu]["Plato fuerte"] as $prod): ?>
                <?php echo htmlOpcionesPlato($prod, "menus[{$i}]", $_POST["menus"][$i]["opcion"] ?? null); ?>
            <?php endforeach; ?>
            <?php endif; ?>

            <?php if (!empty($menusPorTipo[$tipoMenu]["Complemento"])): ?>
            <p class="tb-titulo">Complementos <small class="tb-max">1 o 2</small></p>
            <div class="tb-grid tb-grid--comp">
                <?php foreach ($menusPorTipo[$tipoMenu]["Complemento"] as $comp):
                    $chk = (in_array((int)$comp["id_producto"], array_map('intval', $_POST["menus"][$i]["complementos"] ?? []), true) && $vis); ?>
                <label class="tb-card tb-card--comp">
                    <input type="checkbox" name="menus[<?= $i ?>][complementos][]" value="<?= (int)$comp["id_producto"] ?>"
                           <?= $chk ? "checked" : "" ?><?= $vis ? "" : " disabled" ?>>
                    <span><?= htmlspecialchars($comp["nombre"]) ?></span>
                </label>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php $aguasTipo = $menusPorTipo[$tipoMenu]["Agua"] ?? []; ?>
            <?php if (count($aguasTipo) > 1):
                $aguaSel = aguaElegida($aguasTipo, $_POST["menus"][$i]["agua_tipo"][$tipoMenu] ?? ""); ?>
            <p class="tb-titulo">Agua</p>
            <div class="tb-aguas">
                <?php foreach ($aguasTipo as $agua): ?>
                <label class="tb-agua<?= aguaEsNatural($agua) ? " tb-agua--natural" : "" ?>">
                    <input type="radio" name="menus[<?= $i ?>][agua_tipo][<?= $tipoMenu ?>]" value="<?= (int)$agua["id_producto"] ?>"
                           <?= $aguaSel === (string)$agua["id_producto"] ? "checked" : "" ?><?= $vis ? "" : " disabled" ?>>
                    <span><?= htmlspecialchars($agua["nombre"]) ?></span>
                </label>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php
            $incluye = array_filter([
                $menusPorTipo[$tipoMenu]["Sopa"][0]["nombre"] ?? "",
                count($aguasTipo) > 1 ? "" : ($aguasTipo[0]["nombre"] ?? ""),
                $menusPorTipo[$tipoMenu]["Cortesia"][0]["nombre"] ?? "",
            ]);
            ?>
            <?php if ($incluye): ?>
            <p class="tb-incluye">Incluye <?= htmlspecialchars(implode(", ", $incluye)) ?></p>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </section>
    <?php endfor; ?>

    <button type="button" class="tb-agregar" id="tb-agregar">
        <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M12 5v14M5 12h14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg>
        Agregar comida
    </button>

    <!-- EXTRAS (plegados: casi nunca se usan) -->
    <?php if (!empty($extrasForm)):
        $hayExtrasPrev = false;
        foreach ($_POST["extras"] ?? [] as $cant) { if ((int)$cant > 0) { $hayExtrasPrev = true; break; } }
    ?>
    <details class="tb-extras" id="t-ex"<?= $hayExtrasPrev || $cantidadMenus === 0 ? " open" : "" ?>>
        <summary>Extras <span class="tb-extras__badge" id="t-ex-badge" hidden></span></summary>
        <div class="tb-extras__lista">
            <?php foreach ($extrasForm as $cat => $items):
                foreach ($items as $prod):
                    $cantPrev   = (int)($_POST["extras"][$prod["id_producto"]] ?? 0);
                    $precioCard = ($cat === "Postre") ? (float)($prod["precio"] ?? 0) : ($PRECIOS_EXTRA[$cat] ?? 25);
            ?>
            <div class="tb-extra<?= $cantPrev > 0 ? " tb-extra--activo" : "" ?>" id="t-ex-card-<?= (int)$prod["id_producto"] ?>" data-precio="<?= $precioCard ?>">
                <span class="tb-extra__txt"><b><?= htmlspecialchars($prod["nombre"]) ?></b><small><?= htmlspecialchars($cat) ?> extra, $<?= number_format($precioCard, 0) ?></small></span>
                <span class="tb-mini">
                    <button type="button" class="t-extra-btn t-extra-btn--menos" data-id="<?= (int)$prod["id_producto"] ?>" aria-label="Menos <?= htmlspecialchars($prod["nombre"]) ?>">−</button>
                    <output id="t-ex-num-<?= (int)$prod["id_producto"] ?>"><?= $cantPrev ?></output>
                    <button type="button" class="t-extra-btn t-extra-btn--mas" data-id="<?= (int)$prod["id_producto"] ?>" aria-label="Más <?= htmlspecialchars($prod["nombre"]) ?>">+</button>
                </span>
                <input type="hidden" name="extras[<?= (int)$prod["id_producto"] ?>]" id="t-ex-val-<?= (int)$prod["id_producto"] ?>" value="<?= $cantPrev ?>">
            </div>
            <?php endforeach; endforeach; ?>

            <div id="t-custom-list"></div>
            <button type="button" id="t-add-custom" class="tb-add-custom">Agregar extra con precio libre</button>
        </div>
    </details>
    <?php endif; ?>
</div>

</form>
<?php endif; ?>

<?php if (!$exito && $menuActivo): ?>
<script>
(function () {
    var preciosMenus = <?= json_encode($preciosMenus) ?>;
    var MAX = 10;
    var form = document.getElementById("form-tableta");
    var cantidadActual = parseInt(document.getElementById("t-cantidad").value, 10) || 0;
    function $(id) { return document.getElementById(id); }
    function pesos(v) { return "$" + v.toLocaleString("es-MX", { maximumFractionDigits: 2 }); }

    /* ── Nombre → MAYÚSCULAS, solo letras ── */
    $("t-nombre").addEventListener("input", function () {
        var pos = this.selectionStart;
        var limpio = this.value.replace(/[^\p{L}\s]/gu, "").toUpperCase();
        if (this.value !== limpio) { this.value = limpio; try { this.setSelectionRange(pos, pos); } catch (e) {} }
        this.closest(".tb-campo").classList.remove("tb-falta");
    });

    /* ── Cuántas comidas (0 = solo extras) ── */
    function setCantidad(n) {
        cantidadActual = Math.max(0, Math.min(MAX, n));
        $("t-num-display").textContent = cantidadActual;
        $("t-cantidad").value = cantidadActual;
        for (var i = 1; i <= MAX; i++) {
            var bloque = $("t-menu-" + i);
            var visible = i <= cantidadActual;
            bloque.hidden = !visible;
            // Las comidas ocultas no se envían; al volver, solo se reactiva lo del tipo elegido
            $("t-tipo-val-" + i).disabled = !visible;
            bloque.querySelectorAll(".tb-ops").forEach(function (ops) {
                var activo = visible && !ops.hidden;
                ops.querySelectorAll(".tb-card input, .tb-agua input").forEach(function (inp) { inp.disabled = !activo; });
            });
        }
        $("t-menos").disabled = cantidadActual <= 0;
        $("t-mas").disabled = cantidadActual >= MAX;
        $("tb-agregar").hidden = cantidadActual >= MAX;
        $("t-hint-extras").hidden = cantidadActual !== 0;
        if (cantidadActual === 0 && $("t-ex")) $("t-ex").open = true;
        actualizarTotal();
    }
    $("t-menos").addEventListener("click", function () { setCantidad(cantidadActual - 1); });
    $("t-mas").addEventListener("click", function () { setCantidad(cantidadActual + 1); });
    $("tb-agregar").addEventListener("click", function () {
        setCantidad(cantidadActual + 1);
        $("t-menu-" + cantidadActual).scrollIntoView({ behavior: "smooth", block: "start" });
    });

    /* ── Tipo de menú ── */
    function cambiarTipo(i, tipo) {
        $("t-tipo-val-" + i).value = tipo;
        document.querySelectorAll(".tb-tipo[data-menu='" + i + "']").forEach(function (b) {
            var on = b.dataset.tipo === tipo;
            b.classList.toggle("tb-tipo--activo", on);
            b.setAttribute("aria-pressed", on);
        });
        document.querySelectorAll(".tb-ops[data-menu='" + i + "']").forEach(function (ops) {
            var visible = ops.dataset.tipo === tipo;
            ops.hidden = !visible;
            ops.querySelectorAll(".tb-card input").forEach(function (inp) {
                inp.disabled = !visible;
                if (!visible) inp.checked = false;
            });
            // El agua no se desmarca: al volver a ese tipo sigue la que estaba
            ops.querySelectorAll(".tb-agua input").forEach(function (inp) { inp.disabled = !visible; });
        });
        estadoComida(i);
        actualizarTotal();
    }
    form.addEventListener("click", function (e) {
        var t = e.target.closest(".tb-tipo");
        if (t) cambiarTipo(parseInt(t.dataset.menu, 10), t.dataset.tipo);
        var d = e.target.closest(".tb-duplicar");
        if (d) duplicar(parseInt(d.dataset.menu, 10));
    });

    /* ── ¿Ya está completa la comida? (guisado, 1 o 2 complementos y aderezo si aplica) ── */
    function faltasComida(i) {
        var ops = document.querySelector(".tb-ops[data-menu='" + i + "']:not([hidden])");
        var faltas = [];
        if (!ops) return ["tipo"];
        if (!ops.querySelector("input[name$='[plato_fuerte]']:checked")) faltas.push("guisado");
        else {
            var ad = ops.querySelector(".opciones-plato[style*='block']");
            if (ad && !ad.querySelector("input:checked")) faltas.push("aderezo");
        }
        if (ops.querySelector(".tb-grid--comp") && !ops.querySelector("input[type=checkbox]:checked")) faltas.push("complemento");
        return faltas;
    }
    function estadoComida(i) {
        var c = $("t-menu-" + i);
        var faltas = faltasComida(i);
        c.classList.toggle("tb-comida--lista", faltas.length === 0);
        c.querySelector(".tb-comida__estado").textContent = faltas.length ? "" : "Lista";
        if (!faltas.length) c.classList.remove("tb-comida--error");
    }

    /* ── Complementos: el máximo lo pone el guisado (2, o 1 en la ensalada); se suelta el más viejo ── */
    function limiteComplementos(ops, recien) {
        var plato = ops.querySelector(".tb-card input[type=radio]:checked");
        var max = plato ? parseInt(plato.dataset.max, 10) || 2 : 2;
        var txt = ops.querySelector(".tb-max");
        if (txt) txt.textContent = max === 1 ? "solo 1 con este guisado" : "1 o " + max;
        var marcados = Array.prototype.slice.call(ops.querySelectorAll(".tb-grid--comp input:checked"));
        while (marcados.length > max) {
            var fuera = marcados[0] === recien ? marcados[1] : marcados[0];
            fuera.checked = false;
            marcados.splice(marcados.indexOf(fuera), 1);
        }
    }
    form.addEventListener("change", function (e) {
        var t = e.target;
        var card = t.closest(".tb-comida");
        var ops = t.closest(".tb-ops");
        if (ops && (t.type === "radio" || (t.type === "checkbox" && t.checked))) limiteComplementos(ops, t.type === "checkbox" ? t : null);
        if (card) setTimeout(function () { estadoComida(parseInt(card.dataset.menu, 10)); }, 0);
        actualizarTotal();
    });

    /* ── Duplicar: copia la comida en una nueva al final ── */
    function duplicar(i) {
        if (cantidadActual >= MAX) return;
        setCantidad(cantidadActual + 1);
        var j = cantidadActual;
        var tipo = $("t-tipo-val-" + i).value;
        cambiarTipo(j, tipo);
        var origen = document.getElementById("t-ops-" + i + "-" + tipo);
        var destino = document.getElementById("t-ops-" + j + "-" + tipo);
        var plato = origen.querySelector(".tb-card input[type=radio]:checked");
        if (plato) {
            var p = destino.querySelector(".tb-card input[value='" + plato.value + "']");
            p.checked = true;
            p.dispatchEvent(new Event("change", { bubbles: true }));
        }
        origen.querySelectorAll(".tb-grid--comp input:checked").forEach(function (c) {
            destino.querySelector(".tb-grid--comp input[value='" + c.value + "']").checked = true;
        });
        var aguaOrigen = origen.querySelector(".tb-agua input:checked");
        if (aguaOrigen) {
            var aguaDestino = destino.querySelector(".tb-agua input[value='" + aguaOrigen.value + "']");
            if (aguaDestino) aguaDestino.checked = true;
        }
        // El aderezo aparece un instante después (lo maneja scriptOpcionesPlato)
        setTimeout(function () {
            var op = origen.querySelector(".opciones-plato input:checked");
            if (op) {
                var od = destino.querySelector(".opciones-plato input[value='" + CSS.escape(op.value) + "']");
                if (od) { od.disabled = false; od.checked = true; od.dispatchEvent(new Event("change", { bubbles: true })); }
            }
            estadoComida(j);
            actualizarTotal();
            $("t-menu-" + j).scrollIntoView({ behavior: "smooth", block: "nearest" });
        }, 30);
    }

    /* ── Entrega: un toque en la hora elige punto y hora ── */
    document.querySelectorAll("input[name='id_horario']").forEach(function (r) {
        r.addEventListener("change", function () { $("tb-entrega").classList.remove("tb-falta"); });
    });

    /* ── Extras ── */
    function actualizarBadgeExtras() {
        var badge = $("t-ex-badge");
        if (!badge) return;
        var total = 0;
        document.querySelectorAll(".tb-extra input[type=hidden]").forEach(function (inp) { total += parseInt(inp.value, 10) || 0; });
        document.querySelectorAll(".t-custom-cant-hidden").forEach(function (inp) { total += parseInt(inp.value, 10) || 0; });
        badge.textContent = "+" + total;
        badge.hidden = total === 0;
    }
    document.querySelectorAll(".tb-extra .t-extra-btn").forEach(function (btn) {
        btn.addEventListener("click", function () {
            var id = this.dataset.id;
            var val = $("t-ex-val-" + id);
            var n = parseInt(val.value, 10) || 0;
            n = this.classList.contains("t-extra-btn--mas") ? Math.min(n + 1, 5) : Math.max(n - 1, 0);
            val.value = n;
            $("t-ex-num-" + id).textContent = n;
            $("t-ex-card-" + id).classList.toggle("tb-extra--activo", n > 0);
            actualizarBadgeExtras();
            actualizarTotal();
        });
    });

    var customCount = 0;
    function crearFilaCustom(idx) {
        var row = document.createElement("div");
        row.className = "t-custom-row tb-custom";
        row.innerHTML =
            '<input type="text" class="t-custom-nombre" name="extras_custom[' + idx + '][nombre]" placeholder="¿Qué es?" autocomplete="off">' +
            '<input type="number" class="t-custom-precio" name="extras_custom[' + idx + '][precio]" placeholder="$" min="0" step="1" inputmode="numeric">' +
            '<span class="tb-mini"><button type="button" class="tb-c-menos" aria-label="Menos">−</button><output>1</output><button type="button" class="tb-c-mas" aria-label="Más">+</button></span>' +
            '<input type="hidden" class="t-custom-cant-hidden" name="extras_custom[' + idx + '][cantidad]" value="1">' +
            '<button type="button" class="tb-c-quitar" aria-label="Quitar extra">✕</button>';
        var cant = row.querySelector(".t-custom-cant-hidden");
        var out = row.querySelector("output");
        row.querySelector(".tb-c-menos").addEventListener("click", function () { cant.value = Math.max(1, cant.value - 1); out.textContent = cant.value; actualizarBadgeExtras(); actualizarTotal(); });
        row.querySelector(".tb-c-mas").addEventListener("click", function () { cant.value = Math.min(10, +cant.value + 1); out.textContent = cant.value; actualizarBadgeExtras(); actualizarTotal(); });
        row.querySelector(".tb-c-quitar").addEventListener("click", function () { row.remove(); actualizarBadgeExtras(); actualizarTotal(); });
        row.querySelector(".t-custom-precio").addEventListener("input", actualizarTotal);
        return row;
    }
    if ($("t-add-custom")) $("t-add-custom").addEventListener("click", function () {
        var row = crearFilaCustom(customCount++);
        $("t-custom-list").appendChild(row);
        actualizarBadgeExtras();
        row.querySelector(".t-custom-nombre").focus();
    });

    /* ── Total ── */
    function actualizarTotal() {
        var total = 0;
        for (var i = 1; i <= cantidadActual; i++) total += preciosMenus[$("t-tipo-val-" + i).value] || 0;
        document.querySelectorAll(".tb-extra").forEach(function (card) {
            var id = card.id.replace("t-ex-card-", "");
            total += (parseInt($("t-ex-val-" + id).value, 10) || 0) * (parseFloat(card.dataset.precio) || 0);
        });
        document.querySelectorAll(".t-custom-row").forEach(function (row) {
            total += (parseFloat(row.querySelector(".t-custom-precio").value) || 0) * (parseInt(row.querySelector(".t-custom-cant-hidden").value, 10) || 1);
        });
        $("t-total").textContent = pesos(total);
        $("tb-resumen").textContent = cantidadActual === 0 ? "Solo extras" : cantidadActual + " comida" + (cantidadActual !== 1 ? "s" : "");
    }

    /* ── Guardar: revisar antes y evitar doble envío ── */
    var enviando = false;
    form.addEventListener("submit", function (e) {
        if (enviando) { e.preventDefault(); return; }
        var primero = null;
        var nombre = $("t-nombre");
        if (!nombre.value.trim()) { nombre.closest(".tb-campo").classList.add("tb-falta"); primero = nombre; }
        if (!document.querySelector("input[name='id_horario']:checked")) {
            $("tb-entrega").classList.add("tb-falta");
            primero = primero || $("tb-entrega");
        }
        for (var i = 1; i <= cantidadActual; i++) {
            var faltas = faltasComida(i);
            var c = $("t-menu-" + i);
            c.classList.toggle("tb-comida--error", faltas.length > 0);
            c.querySelector(".tb-comida__estado").textContent = faltas.length ? "Falta: " + faltas.join(", ") : "Lista";
            if (faltas.length) primero = primero || c;
        }
        if (primero) {
            e.preventDefault();
            primero.scrollIntoView({ behavior: "smooth", block: "center" });
            if (primero === nombre) nombre.focus({ preventScroll: true });
            return;
        }
        enviando = true;
        var b = $("t-submit");
        setTimeout(function () { b.disabled = true; b.textContent = "Guardando…"; }, 30);
    });

    /* ── Inicio ── */
    setCantidad(cantidadActual);
    document.querySelectorAll(".tb-ops").forEach(function (ops) { limiteComplementos(ops); });
    for (var i = 1; i <= MAX; i++) estadoComida(i);
    actualizarBadgeExtras();
})();
</script>
<?php endif; ?>

<?php echo scriptOpcionesPlato(); ?>
</body>
</html>
