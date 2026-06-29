<?php
require_once "../config/db.php";
require_once "auth_check.php";
date_default_timezone_set("America/Mexico_City");

$stmtMenu = $conexion->prepare("SELECT * FROM menu_dia WHERE activo = 1 ORDER BY fecha DESC LIMIT 1");
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
        $p["agotado"] = (
            $p["categoria"] === "Plato fuerte" &&
            !empty($p["limite_pedidos"]) &&
            ($conteos[$idP] ?? 0) >= (int)$p["limite_pedidos"]
        );
        $menusPorTipo[$p["tipo_menu"]][$p["categoria"]][] = $p;
        $productosIndexados[$idP] = $p;
    }
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
        if (!$hayExtras) $errores[] = "Agrega al menos un extra.";
    }

    foreach ($menusRecibidos as $nMenu => $menu) {
        $tipo_menu    = $menu["tipo_menu"]    ?? "";
        $plato_fuerte = $menu["plato_fuerte"] ?? "";
        $complementos = $menu["complementos"] ?? [];

        if (!in_array($tipo_menu, ["Zabisu","Ejecutivo"], true))
            $erroresMenus[$nMenu][] = "Selecciona el tipo de menú.";
        if ($plato_fuerte === "") {
            $erroresMenus[$nMenu][] = "Falta el guisado.";
        } elseif (isset($productosIndexados[(int)$plato_fuerte]) && !empty($productosIndexados[(int)$plato_fuerte]["agotado"])) {
            $erroresMenus[$nMenu][] = "El guisado está agotado.";
        }
        if (empty($complementos))      $erroresMenus[$nMenu][] = "Falta al menos un complemento.";
        if (count($complementos) > 2)  $erroresMenus[$nMenu][] = "Máximo 2 complementos.";
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

        foreach ($menusRecibidos as $num => &$menuData) {
            $tipo = $menuData["tipo_menu"] ?? "";
            foreach (["Sopa"=>"sopa","Agua"=>"agua","Cortesia"=>"cortesia"] as $cat => $key) {
                if (!empty($menusPorTipo[$tipo][$cat])) {
                    $menuData[$key] = (string)$menusPorTipo[$tipo][$cat][0]["id_producto"];
                }
            }
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
            $stmtDet = $conexion->prepare("INSERT INTO detalle_pedido (id_pedido_menu,id_producto,categoria,nombre_producto) VALUES (:id_pedido_menu,:id_producto,:categoria,:nombre_producto)");

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
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
<title>Pedido Tableta | Zabisu</title>
<link rel="icon" type="image/png" href="../assets/img/LOGO_NARA.png">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
    background: #0e0e12;
    color: #f0f0f0;
    min-height: 100vh;
    font-size: 18px;
    -webkit-tap-highlight-color: transparent;
    touch-action: manipulation;
}
.t-wrap { max-width: 900px; margin: 0 auto; padding: 0 20px 80px; }

/* HERO */
.t-hero {
    background: linear-gradient(135deg, #1a1a2e 0%, #16213e 60%, #0f3460 100%);
    padding: 36px 24px 28px;
    text-align: center;
    border-bottom: 3px solid #ff7a00;
    margin-bottom: 28px;
}
.t-hero__eye   { font-size: 12px; letter-spacing: 3px; color: #ff7a00; font-weight: 700; text-transform: uppercase; margin-bottom: 6px; }
.t-hero__title { font-size: 36px; font-weight: 900; color: #fff; }
.t-hero__fecha { font-size: 16px; color: #888; margin-top: 6px; }
.t-hero__back  { display: inline-block; margin-top: 14px; color: #ff7a00; text-decoration: none; font-size: 14px; font-weight: 600; opacity: .75; }
.t-hero__back:hover { opacity: 1; }

/* BLOQUE */
.t-bloque {
    background: #17171f;
    border-radius: 16px;
    padding: 26px 22px;
    margin-bottom: 18px;
    border: 1px solid #252535;
}
.t-bloque h2 { font-size: 22px; font-weight: 800; color: #fff; margin-bottom: 18px; }

/* NOMBRE */
.t-nombre {
    width: 100%;
    background: #1e1e2e;
    border: 2px solid #333345;
    border-radius: 12px;
    color: #fff;
    font-size: 26px;
    font-weight: 700;
    padding: 16px 18px;
    text-transform: uppercase;
    outline: none;
    transition: border-color .2s;
    caret-color: #ff7a00;
}
.t-nombre::placeholder { color: #444; font-weight: 400; font-size: 20px; text-transform: none; }
.t-nombre:focus { border-color: #ff7a00; }

/* CONTADOR */
.t-counter        { display: flex; align-items: center; justify-content: center; gap: 28px; }
.t-counter__btn   {
    width: 84px; height: 84px; border-radius: 50%; border: none;
    cursor: pointer; display: flex; align-items: center; justify-content: center;
    font-size: 40px; font-weight: 300; line-height: 1;
    transition: transform .08s; user-select: none;
}
.t-counter__btn:active { transform: scale(.9); }
.t-counter__minus { background: #3a1c1c; color: #ff6b6b; }
.t-counter__plus  { background: #1a3520; color: #4ac86e; }
.t-counter__num   { font-size: 80px; font-weight: 900; color: #fff; min-width: 110px; text-align: center; line-height: 1; }
.t-counter__hint  { text-align: center; font-size: 13px; color: #555; margin-top: 10px; }

/* MENU BLOQUE */
.t-menu {
    background: #1a1a28;
    border-radius: 14px;
    padding: 20px 18px;
    margin-bottom: 14px;
    border: 2px solid #2a2a3a;
}
.t-menu--error { border-color: #ff6b6b; }
.t-menu__num   { font-size: 18px; font-weight: 800; color: #ff7a00; margin-bottom: 14px; }

/* TIPO TOGGLE */
.t-tipo     { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 18px; }
.t-tipo__btn {
    padding: 16px 8px; border-radius: 12px; border: 2px solid #2e2e42;
    background: #12121e; color: #666; font-size: 17px; font-weight: 700;
    cursor: pointer; text-align: center;
    transition: background .15s, border-color .15s, color .15s;
    user-select: none; line-height: 1.3;
}
.t-tipo__btn small { display: block; font-size: 12px; font-weight: 400; opacity: .7; margin-top: 3px; }
.t-tipo__btn--activo { background: rgba(255,122,0,.18); border-color: #ff7a00; color: #ff7a00; }

/* CATEGORIA LABEL */
.t-cat-label { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 1.5px; color: #555; margin-bottom: 10px; display: block; }

/* GRID PRODUCTOS */
.t-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(155px, 1fr)); gap: 10px; margin-bottom: 16px; }

/* CARD */
.t-card {
    position: relative;
    background: #111120; border: 2px solid #252535; border-radius: 12px;
    padding: 16px 12px; cursor: pointer; text-align: center;
    user-select: none; min-height: 76px;
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    transition: background .12s, border-color .12s;
}
.t-card:has(input:checked):not(.t-card--ago) { background: rgba(255,122,0,.15); border-color: #ff7a00; }
.t-card input { position: absolute; opacity: 0; pointer-events: none; width: 0; height: 0; }
.t-card__name { font-size: 16px; font-weight: 700; color: #ccc; line-height: 1.2; }
.t-card:has(input:checked):not(.t-card--ago) .t-card__name { color: #ff7a00; }
.t-card--ago { opacity: .35; cursor: not-allowed; }
.t-card--ago .t-card__name { text-decoration: line-through; }
.t-ago-badge { font-size: 11px; color: #ff6b6b; font-weight: 600; margin-top: 4px; }

/* INCLUYE */
.t-incluye {
    background: #111118; border-radius: 10px; padding: 12px 14px;
    display: flex; gap: 16px; flex-wrap: wrap; margin-top: 6px;
}
.t-incluye__title { width: 100%; font-size: 11px; color: #444; text-transform: uppercase; letter-spacing: 1px; font-weight: 700; margin-bottom: 4px; }
.t-incluye__item  { font-size: 14px; color: #666; }

/* UBICACIONES */
.t-ub-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 10px; }
.t-ub-card {
    background: #111120; border: 2px solid #252535; border-radius: 12px;
    padding: 18px 14px; cursor: pointer; text-align: center; user-select: none;
    transition: background .12s, border-color .12s; position: relative;
}
.t-ub-card input { position: absolute; opacity: 0; pointer-events: none; width: 0; height: 0; }
.t-ub-card:has(input:checked) { background: rgba(255,122,0,.15); border-color: #ff7a00; }
.t-ub-card__name { font-size: 17px; font-weight: 700; color: #ddd; }
.t-ub-card:has(input:checked) .t-ub-card__name { color: #ff7a00; }

/* HORARIOS */
.t-horarios    { display: none; flex-wrap: wrap; gap: 10px; margin-top: 16px; }
.t-horarios--on { display: flex; }
.t-hor-btn {
    position: relative; background: #111120; border: 2px solid #252535; border-radius: 10px;
    padding: 16px 22px; cursor: pointer; font-size: 22px; font-weight: 700;
    color: #ddd; user-select: none;
    transition: background .12s, border-color .12s, color .12s;
}
.t-hor-btn:has(input:checked) { background: rgba(255,122,0,.15); border-color: #ff7a00; color: #ff7a00; }
.t-hor-btn input { position: absolute; opacity: 0; pointer-events: none; width: 0; height: 0; }

/* TOTAL */
.t-total-row { display: flex; align-items: center; justify-content: space-between; background: #1a1a2a; border-radius: 12px; padding: 18px 20px; margin-bottom: 14px; }
.t-total-row__label { font-size: 18px; color: #888; }
.t-total-row__val   { font-size: 40px; font-weight: 900; color: #ff7a00; }

/* SUBMIT */
.t-submit {
    width: 100%; background: #ff7a00; border: none; border-radius: 14px;
    color: #fff; font-size: 24px; font-weight: 800; padding: 22px;
    cursor: pointer; transition: transform .08s, opacity .2s; user-select: none;
}
.t-submit:active   { transform: scale(.98); }
.t-submit:disabled { background: #444; cursor: not-allowed; opacity: .7; }

/* ERRORES */
.t-errores {
    background: rgba(255,107,107,.08); border: 2px solid #ff6b6b;
    border-radius: 12px; padding: 16px 18px; margin-bottom: 16px;
}
.t-errores ul { padding-left: 18px; }
.t-errores li { color: #ff6b6b; font-size: 16px; margin-bottom: 5px; }

/* ÉXITO */
.t-exito         { text-align: center; padding: 50px 20px; }
.t-exito__icon   { font-size: 64px; margin-bottom: 12px; }
.t-exito__title  { font-size: 38px; font-weight: 900; color: #4ac86e; margin-bottom: 10px; }
.t-exito__folio  {
    display: inline-block; background: #1a1a2e; border: 2px solid #ff7a00;
    border-radius: 12px; padding: 14px 28px; font-size: 26px; font-weight: 900;
    color: #ff7a00; letter-spacing: 2px; margin-bottom: 10px;
}
.t-exito__total  { font-size: 20px; color: #888; margin-bottom: 28px; }
.t-btn-nuevo {
    display: inline-block; background: #ff7a00; color: #fff;
    text-decoration: none; font-size: 22px; font-weight: 800;
    padding: 18px 36px; border-radius: 12px; transition: transform .08s;
}
.t-btn-nuevo:active { transform: scale(.97); }

/* TOGGLE BLOQUE (observaciones y extras) */
.t-toggle-header {
    display: flex; align-items: center; justify-content: space-between;
    cursor: pointer; user-select: none; padding: 2px 0;
}
.t-toggle-header h2 { margin-bottom: 0; }
.t-toggle-arrow {
    font-size: 22px; color: #555; transition: transform .25s;
    line-height: 1; flex-shrink: 0;
}
.t-toggle-header--abierto .t-toggle-arrow { transform: rotate(180deg); color: #ff7a00; }
.t-toggle-body {
    overflow: hidden;
    max-height: 0;
    transition: max-height .3s cubic-bezier(.4,0,.2,1), opacity .25s;
    opacity: 0;
}
.t-toggle-body--abierto { max-height: 800px; opacity: 1; }
.t-toggle-body > * { margin-top: 16px; }

/* BADGE de cantidad activa en header */
.t-toggle-badge {
    font-size: 12px; font-weight: 700; background: #ff7a00;
    color: #000; border-radius: 99px; padding: 2px 9px;
    display: none; margin-left: 8px;
}
.t-toggle-badge--on { display: inline-block; }

/* OBSERVACIONES */
.t-obs {
    width: 100%;
    background: #1e1e2e;
    border: 2px solid #333345;
    border-radius: 12px;
    color: #fff;
    font-size: 18px;
    padding: 12px 14px;
    outline: none;
    resize: none;
    font-family: inherit;
    line-height: 1.5;
    transition: border-color .2s;
    caret-color: #ff7a00;
}
.t-obs::placeholder { color: #444; font-size: 16px; }
.t-obs:focus { border-color: #ff7a00; }

/* EXTRAS */
.t-extra-grid { display: flex; flex-direction: column; gap: 10px; }
.t-extra-cat-label {
    font-size: 13px; font-weight: 700; text-transform: uppercase;
    letter-spacing: 1.5px; color: #555; margin: 14px 0 8px; display: block;
}
.t-extra-cat-label:first-child { margin-top: 0; }
.t-extra-card {
    display: flex; align-items: center; justify-content: space-between;
    background: #111120; border: 2px solid #252535; border-radius: 14px;
    padding: 14px 16px; gap: 12px;
    transition: border-color .15s;
}
.t-extra-card--activo { border-color: #ff7a00; background: rgba(255,122,0,.1); }
.t-extra-card__info { flex: 1; }
.t-extra-card__nombre { font-size: 17px; font-weight: 700; color: #ccc; display: block; }
.t-extra-card--activo .t-extra-card__nombre { color: #ff7a00; }
.t-extra-card__precio { font-size: 13px; color: #555; margin-top: 2px; display: block; }
.t-extra-contador { display: flex; align-items: center; gap: 0; flex-shrink: 0; }
.t-extra-btn {
    width: 48px; height: 48px; border-radius: 50%; border: none; cursor: pointer;
    font-size: 26px; font-weight: 300; line-height: 1;
    display: flex; align-items: center; justify-content: center;
    transition: transform .08s; user-select: none;
}
.t-extra-btn:active { transform: scale(.88); }
.t-extra-btn--menos { background: #2a1a1a; color: #ff6b6b; }
.t-extra-btn--mas   { background: #1a2a1a; color: #4ac86e; }
.t-extra-num {
    font-size: 24px; font-weight: 900; color: #fff;
    min-width: 42px; text-align: center;
}
.t-extra-num--cero { color: #333; }

/* RESPONSIVE */
@media (max-width: 540px) {
    .t-counter__num  { font-size: 60px; min-width: 80px; }
    .t-counter__btn  { width: 70px; height: 70px; font-size: 34px; }
    .t-grid          { grid-template-columns: 1fr 1fr; }
    .t-hero__title   { font-size: 28px; }
}
</style>
</head>
<body>
<div class="t-wrap">

<div class="t-hero">
    <p class="t-hero__eye">RESTAURANTE ZABISU</p>
    <h1 class="t-hero__title">Pedido rápido</h1>
    <?php if ($fechaMenuFormateada): ?>
    <p class="t-hero__fecha"><?= htmlspecialchars($fechaMenuFormateada) ?></p>
    <?php endif; ?>
    <a href="panel_general.php" class="t-hero__back">← Panel general</a>
</div>

<?php if ($exito): ?>
<div class="t-exito">
    <div class="t-exito__icon">✅</div>
    <h2 class="t-exito__title">¡Pedido registrado!</h2>
    <div class="t-exito__folio"><?= htmlspecialchars($folioCreado) ?></div>
    <p class="t-exito__total">Total: $<?= number_format($totalCreado, 2) ?></p>
    <a href="pedido_tableta.php" class="t-btn-nuevo">+ Nuevo pedido</a>
</div>

<?php elseif (!$menuActivo): ?>
<div class="t-bloque">
    <p style="color:#ff7a00;font-size:20px;">No hay menú disponible. Crea un menú primero.</p>
</div>

<?php else: ?>

<?php if (!empty($errores)): ?>
<div class="t-errores"><ul>
    <?php foreach ($errores as $e): ?>
    <li><?= htmlspecialchars($e) ?></li>
    <?php endforeach; ?>
</ul></div>
<?php endif; ?>

<form method="POST" action="pedido_tableta.php" id="form-tableta">
<input type="hidden" name="guardar_pedido" value="1">

<!-- NOMBRE -->
<div class="t-bloque">
    <h2>Nombre del cliente</h2>
    <input type="text" class="t-nombre" name="nombre_cliente" id="t-nombre"
           maxlength="80" autocomplete="off" autocorrect="off" spellcheck="false"
           placeholder="Escribe el nombre..."
           value="<?= htmlspecialchars($_POST["nombre_cliente"] ?? "") ?>">
</div>

<!-- OBSERVACIONES -->
<?php $obsVal = htmlspecialchars($_POST["observaciones"] ?? ""); ?>
<div class="t-bloque">
    <div class="t-toggle-header <?= $obsVal ? 't-toggle-header--abierto' : '' ?>" id="t-obs-header">
        <h2>Observaciones<span class="t-toggle-badge <?= $obsVal ? 't-toggle-badge--on' : '' ?>" id="t-obs-badge">✓</span></h2>
        <span class="t-toggle-arrow">▾</span>
    </div>
    <div class="t-toggle-body <?= $obsVal ? 't-toggle-body--abierto' : '' ?>" id="t-obs-body">
        <textarea class="t-obs" name="observaciones" id="t-obs-textarea" rows="3"
                  placeholder="Sin cebolla, alergia a..., para llevar..."
        ><?= $obsVal ?></textarea>
    </div>
</div>

<!-- CANTIDAD -->
<div class="t-bloque">
    <h2>¿Cuántas comidas?</h2>
    <div class="t-counter">
        <button type="button" class="t-counter__btn t-counter__minus" id="t-menos">−</button>
        <span class="t-counter__num" id="t-num-display"><?= (int)$cantidadMenus ?></span>
        <input type="hidden" name="cantidad_menus" id="t-cantidad" value="<?= (int)$cantidadMenus ?>">
        <button type="button" class="t-counter__btn t-counter__plus"  id="t-mas">+</button>
    </div>
    <p class="t-counter__hint" id="t-hint-max" <?= $cantidadMenus === 0 ? 'style="display:none;"' : '' ?>>máximo 10 comidas por ticket</p>
    <p class="t-counter__hint" id="t-hint-extras" style="<?= $cantidadMenus === 0 ? '' : 'display:none;' ?>color:#ff7a00;font-weight:700;">Solo extras</p>
</div>

<!-- BLOQUES DE MENÚ -->
<?php for ($i = 1; $i <= 10; $i++):
    $tipoSel  = $_POST["menus"][$i]["tipo_menu"] ?? "Zabisu";
    $tieneErr = !empty($erroresMenus[$i]);
?>
<div class="t-menu <?= $tieneErr ? 't-menu--error' : '' ?>"
     id="t-menu-<?= $i ?>"
     <?= $i > $cantidadMenus ? 'style="display:none;"' : '' ?>>

    <div class="t-menu__num">Comida <?= $i ?></div>

    <!-- Tipo de menú -->
    <div class="t-tipo">
        <?php foreach (["Zabisu","Ejecutivo"] as $tipo): ?>
        <button type="button"
                class="t-tipo__btn <?= $tipoSel === $tipo ? 't-tipo__btn--activo' : '' ?>"
                data-menu="<?= $i ?>"
                data-tipo="<?= $tipo ?>">
            <?= $tipo === "Zabisu" ? "🍽 ZABISU" : "⚡ EJECUTIVO" ?>
            <small>$<?= number_format($preciosMenus[$tipo] ?? 0, 0) ?></small>
        </button>
        <?php endforeach; ?>
    </div>
    <input type="hidden" name="menus[<?= $i ?>][tipo_menu]" id="t-tipo-val-<?= $i ?>" value="<?= htmlspecialchars($tipoSel) ?>">

    <!-- Opciones por tipo -->
    <?php foreach (["Zabisu","Ejecutivo"] as $tipoMenu): ?>
    <div id="t-ops-<?= $i ?>-<?= $tipoMenu ?>"
         class="t-opciones"
         data-menu="<?= $i ?>"
         data-tipo="<?= $tipoMenu ?>"
         <?= $tipoSel !== $tipoMenu ? 'style="display:none;"' : '' ?>>

        <?php if (!empty($menusPorTipo[$tipoMenu]["Plato fuerte"])): ?>
        <span class="t-cat-label">🍽 Guisado · elige 1</span>
        <div class="t-grid">
            <?php foreach ($menusPorTipo[$tipoMenu]["Plato fuerte"] as $prod): ?>
            <?php $chk = (($_POST["menus"][$i]["plato_fuerte"] ?? "") == $prod["id_producto"] && $tipoSel === $tipoMenu); ?>
            <?php $ago = !empty($prod["agotado"]); ?>
            <label class="t-card <?= $ago ? 't-card--ago' : '' ?>">
                <input type="radio"
                       name="menus[<?= $i ?>][plato_fuerte]"
                       value="<?= (int)$prod["id_producto"] ?>"
                       <?= $chk ? 'checked' : '' ?>
                       <?= ($ago || $tipoSel !== $tipoMenu) ? 'disabled' : '' ?>>
                <span class="t-card__name"><?= htmlspecialchars($prod["nombre"]) ?></span>
                <?php if ($ago): ?><span class="t-ago-badge">Agotado</span><?php endif; ?>
            </label>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($menusPorTipo[$tipoMenu]["Complemento"])): ?>
        <span class="t-cat-label">🥗 Complementos · elige hasta 2</span>
        <div class="t-grid">
            <?php foreach ($menusPorTipo[$tipoMenu]["Complemento"] as $comp): ?>
            <?php $chk = (in_array($comp["id_producto"], array_map('intval', $_POST["menus"][$i]["complementos"] ?? [])) && $tipoSel === $tipoMenu); ?>
            <label class="t-card" data-menu="<?= $i ?>" data-tipo="<?= $tipoMenu ?>">
                <input type="checkbox"
                       name="menus[<?= $i ?>][complementos][]"
                       value="<?= (int)$comp["id_producto"] ?>"
                       <?= $chk ? 'checked' : '' ?>
                       <?= $tipoSel !== $tipoMenu ? 'disabled' : '' ?>>
                <span class="t-card__name"><?= htmlspecialchars($comp["nombre"]) ?></span>
            </label>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php
        $sopaN   = $menusPorTipo[$tipoMenu]["Sopa"][0]["nombre"]   ?? "";
        $aguaN   = $menusPorTipo[$tipoMenu]["Agua"][0]["nombre"]   ?? "";
        $cortesiaN = $menusPorTipo[$tipoMenu]["Cortesia"][0]["nombre"] ?? "";
        ?>
        <?php if ($sopaN || $aguaN || $cortesiaN): ?>
        <div class="t-incluye">
            <span class="t-incluye__title">También incluye</span>
            <?php if ($sopaN):     ?><span class="t-incluye__item">🥣 <?= htmlspecialchars($sopaN) ?></span><?php endif; ?>
            <?php if ($aguaN):     ?><span class="t-incluye__item">💧 <?= htmlspecialchars($aguaN) ?></span><?php endif; ?>
            <?php if ($cortesiaN): ?><span class="t-incluye__item">🍬 <?= htmlspecialchars($cortesiaN) ?></span><?php endif; ?>
        </div>
        <?php endif; ?>

    </div>
    <?php endforeach; ?>

</div>
<?php endfor; ?>

<!-- EXTRAS -->
<?php if (!empty($extrasForm)):
    $hayExtrasPrev = false;
    foreach ($_POST["extras"] ?? [] as $cant) { if ((int)$cant > 0) { $hayExtrasPrev = true; break; } }
?>
<div class="t-bloque">
    <div class="t-toggle-header <?= $hayExtrasPrev ? 't-toggle-header--abierto' : '' ?>" id="t-ex-header">
        <h2>Extras<span class="t-toggle-badge <?= $hayExtrasPrev ? 't-toggle-badge--on' : '' ?>" id="t-ex-badge"></span></h2>
        <span class="t-toggle-arrow">▾</span>
    </div>
    <div class="t-toggle-body <?= $hayExtrasPrev ? 't-toggle-body--abierto' : '' ?>" id="t-ex-body">
    <div class="t-extra-grid">
        <?php foreach ($extrasForm as $cat => $items): ?>
        <?php $precioMostrar = ($cat === "Postre") ? number_format((float)($items[0]["precio"] ?? 0), 0) : $PRECIOS_EXTRA[$cat]; ?>
        <span class="t-extra-cat-label"><?= $iconosExtra[$cat] ?> <?= htmlspecialchars($cat) ?> extra · $<?= $precioMostrar ?> c/u</span>
        <?php foreach ($items as $prod):
            $cantPrev = (int)($_POST["extras"][$prod["id_producto"]] ?? 0);
            $precioCard = ($cat === "Postre") ? (float)($prod["precio"] ?? 0) : ($PRECIOS_EXTRA[$cat] ?? 25);
        ?>
        <div class="t-extra-card <?= $cantPrev > 0 ? 't-extra-card--activo' : '' ?>"
             id="t-ex-card-<?= (int)$prod["id_producto"] ?>"
             data-precio="<?= $precioCard ?>">
            <div class="t-extra-card__info">
                <span class="t-extra-card__nombre"><?= htmlspecialchars($prod["nombre"]) ?></span>
                <span class="t-extra-card__precio">$<?= number_format($precioCard, 0) ?> por pieza</span>
            </div>
            <div class="t-extra-contador">
                <button type="button" class="t-extra-btn t-extra-btn--menos"
                        data-id="<?= (int)$prod["id_producto"] ?>">−</button>
                <span class="t-extra-num <?= $cantPrev === 0 ? 't-extra-num--cero' : '' ?>"
                      id="t-ex-num-<?= (int)$prod["id_producto"] ?>"><?= $cantPrev ?></span>
                <button type="button" class="t-extra-btn t-extra-btn--mas"
                        data-id="<?= (int)$prod["id_producto"] ?>">+</button>
            </div>
            <input type="hidden"
                   name="extras[<?= (int)$prod["id_producto"] ?>]"
                   id="t-ex-val-<?= (int)$prod["id_producto"] ?>"
                   value="<?= $cantPrev ?>">
        </div>
        <?php endforeach; ?>
        <?php endforeach; ?>
    </div>
    </div><!-- /.t-toggle-body -->
</div>
<?php endif; ?>

<!-- ENTREGA -->
<div class="t-bloque">
    <h2>Punto de entrega</h2>
    <div class="t-ub-grid">
        <?php foreach ($ubicaciones as $ub): ?>
        <?php if ($ub["tipo"] !== "entrega") continue; ?>
        <label class="t-ub-card">
            <input type="radio" name="ubicacion_sel" value="<?= (int)$ub["id_ubicacion"] ?>">
            <span class="t-ub-card__name">📍 <?= htmlspecialchars($ub["nombre_ubicacion"]) ?></span>
        </label>
        <?php endforeach; ?>
    </div>

    <?php foreach ($ubicaciones as $ub): ?>
    <?php if ($ub["tipo"] !== "entrega") continue; ?>
    <div class="t-horarios" id="t-hors-<?= (int)$ub["id_ubicacion"] ?>">
        <?php foreach ($horarios as $h): ?>
        <?php if ($h["id_ubicacion"] != $ub["id_ubicacion"]) continue; ?>
        <label class="t-hor-btn">
            <input type="radio" name="id_horario" value="<?= (int)$h["id_horario"] ?>"
                   <?= (($_POST["id_horario"] ?? "") == $h["id_horario"]) ? 'checked' : '' ?>>
            <?= date("g:i A", strtotime($h["hora_entrega"])) ?>
        </label>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
</div>

<!-- TOTAL + SUBMIT -->
<div class="t-bloque">
    <div class="t-total-row">
        <span class="t-total-row__label">Total</span>
        <span class="t-total-row__val" id="t-total">$0</span>
    </div>
    <button type="submit" name="guardar_pedido" value="1" class="t-submit" id="t-submit">
        Guardar pedido →
    </button>
</div>

</form>
<?php endif; ?>
</div>

<script>
(function () {
    var preciosMenus   = <?= json_encode($preciosMenus) ?>;
    var cantidadActual = <?= (int)$cantidadMenus ?>;
    var MAX = 10;

    /* ── Toggles (observaciones y extras) ── */
    function initToggle(headerId, bodyId) {
        var header = document.getElementById(headerId);
        var body   = document.getElementById(bodyId);
        if (!header || !body) return;
        header.addEventListener("click", function () {
            var abierto = body.classList.toggle("t-toggle-body--abierto");
            header.classList.toggle("t-toggle-header--abierto", abierto);
        });
    }
    initToggle("t-obs-header", "t-obs-body");
    initToggle("t-ex-header",  "t-ex-body");

    /* Badge observaciones: aparece cuando hay texto */
    var obsTextarea = document.getElementById("t-obs-textarea");
    var obsBadge    = document.getElementById("t-obs-badge");
    if (obsTextarea && obsBadge) {
        obsTextarea.addEventListener("input", function () {
            obsBadge.classList.toggle("t-toggle-badge--on", this.value.trim() !== "");
        });
    }

    /* ── Nombre → MAYÚSCULAS ── */
    var nombreInput = document.getElementById("t-nombre");
    if (nombreInput) {
        nombreInput.addEventListener("input", function () {
            var pos    = this.selectionStart;
            var limpio = this.value.replace(/[^\p{L}\s]/gu, "").toUpperCase();
            if (this.value !== limpio) {
                this.value = limpio;
                try { this.setSelectionRange(pos, pos); } catch (e) {}
            }
        });
    }

    /* ── Contador ── */
    var numDisplay = document.getElementById("t-num-display");
    var inputCant  = document.getElementById("t-cantidad");

    function setCantidad(n) {
        if (n < 0) n = 0;
        if (n > MAX) n = MAX;
        cantidadActual = n;
        numDisplay.textContent = n;
        inputCant.value = n;
        actualizarBloques();
        actualizarTotal();

        var hintMax    = document.getElementById("t-hint-max");
        var hintExtras = document.getElementById("t-hint-extras");
        if (hintMax)    hintMax.style.display    = n === 0 ? "none" : "";
        if (hintExtras) hintExtras.style.display = n === 0 ? "" : "none";

        if (n === 0) {
            var exBody   = document.getElementById("t-ex-body");
            var exHeader = document.getElementById("t-ex-header");
            if (exBody && !exBody.classList.contains("t-toggle-body--abierto")) {
                exBody.classList.add("t-toggle-body--abierto");
                if (exHeader) exHeader.classList.add("t-toggle-header--abierto");
            }
        }
    }

    document.getElementById("t-menos").addEventListener("click", function () { setCantidad(cantidadActual - 1); });
    document.getElementById("t-mas").addEventListener("click",   function () { setCantidad(cantidadActual + 1); });

    function actualizarBloques() {
        for (var i = 1; i <= MAX; i++) {
            var bloque = document.getElementById("t-menu-" + i);
            if (!bloque) continue;
            var visible = i <= cantidadActual;
            bloque.style.display = visible ? "" : "none";
            bloque.querySelectorAll("input").forEach(function (el) {
                if (!visible) {
                    if (!el.disabled) { el.disabled = true; el.dataset.sd = "1"; }
                } else if (el.dataset.sd) {
                    el.disabled = false; delete el.dataset.sd;
                }
            });
        }
    }

    /* ── Tipo de menú ── */
    document.querySelectorAll(".t-tipo__btn").forEach(function (btn) {
        btn.addEventListener("click", function () {
            cambiarTipo(parseInt(this.dataset.menu), this.dataset.tipo);
        });
    });

    function cambiarTipo(menuNum, tipo) {
        var hidden = document.getElementById("t-tipo-val-" + menuNum);
        if (hidden) hidden.value = tipo;

        document.querySelectorAll(".t-tipo__btn[data-menu='" + menuNum + "']").forEach(function (b) {
            b.classList.toggle("t-tipo__btn--activo", b.dataset.tipo === tipo);
        });

        document.querySelectorAll(".t-opciones[data-menu='" + menuNum + "']").forEach(function (div) {
            var visible = div.dataset.tipo === tipo;
            div.style.display = visible ? "" : "none";
            div.querySelectorAll("input").forEach(function (inp) {
                inp.disabled = !visible;
                if (!visible) inp.checked = false;
            });
        });
        actualizarTotal();
    }

    /* ── Complementos: máx 2 ── */
    document.querySelectorAll(".t-card input[type='checkbox']").forEach(function (cb) {
        cb.addEventListener("change", function () {
            if (!this.checked) { actualizarTotal(); return; }
            var contenedor = this.closest(".t-opciones");
            if (!contenedor) { actualizarTotal(); return; }
            var checks = Array.from(document.querySelectorAll(
                "#t-ops-" + contenedor.dataset.menu + "-" + contenedor.dataset.tipo + " input[type='checkbox']:checked"
            ));
            if (checks.length > 2) checks[0].checked = false;
            actualizarTotal();
        });
    });

    document.querySelectorAll(".t-card input[type='radio']").forEach(function (r) {
        r.addEventListener("change", actualizarTotal);
    });

    /* ── Ubicaciones → mostrar horarios ── */
    document.querySelectorAll(".t-ub-card input[type='radio']").forEach(function (r) {
        r.addEventListener("change", function () {
            document.querySelectorAll(".t-horarios").forEach(function (d) { d.classList.remove("t-horarios--on"); });
            var hDiv = document.getElementById("t-hors-" + this.value);
            if (hDiv) hDiv.classList.add("t-horarios--on");
            document.querySelectorAll("input[name='id_horario']").forEach(function (rh) { rh.checked = false; });
        });
    });

    /* Restaurar selección ubicación/horario desde POST con errores */
    (function () {
        var horSel = document.querySelector("input[name='id_horario']:checked");
        if (!horSel) return;
        var hDiv = horSel.closest(".t-horarios");
        if (!hDiv) return;
        hDiv.classList.add("t-horarios--on");
        var ubId = hDiv.id.replace("t-hors-", "");
        var ubRadio = document.querySelector("input[name='ubicacion_sel'][value='" + ubId + "']");
        if (ubRadio) ubRadio.checked = true;
    })();

    /* ── Extras ── */
    var exBadge = document.getElementById("t-ex-badge");

    function actualizarBadgeExtras() {
        if (!exBadge) return;
        var total = 0;
        document.querySelectorAll(".t-extra-card").forEach(function (card) {
            var id  = card.id.replace("t-ex-card-", "");
            total += parseInt(document.getElementById("t-ex-val-" + id)?.value) || 0;
        });
        exBadge.textContent = total > 0 ? "+" + total : "";
        exBadge.classList.toggle("t-toggle-badge--on", total > 0);
    }

    document.querySelectorAll(".t-extra-btn").forEach(function (btn) {
        btn.addEventListener("click", function () {
            var id      = this.dataset.id;
            var valEl   = document.getElementById("t-ex-val-"  + id);
            var numEl   = document.getElementById("t-ex-num-"  + id);
            var cardEl  = document.getElementById("t-ex-card-" + id);
            if (!valEl || !numEl || !cardEl) return;
            var n = parseInt(valEl.value) || 0;
            if (this.classList.contains("t-extra-btn--mas"))   n = Math.min(n + 1, 5);
            if (this.classList.contains("t-extra-btn--menos")) n = Math.max(n - 1, 0);
            valEl.value       = n;
            numEl.textContent = n;
            numEl.classList.toggle("t-extra-num--cero", n === 0);
            cardEl.classList.toggle("t-extra-card--activo", n > 0);
            actualizarBadgeExtras();
            actualizarTotal();
        });
    });
    actualizarBadgeExtras();

    /* ── Total ── */
    function actualizarTotal() {
        var total = 0;
        for (var i = 1; i <= cantidadActual; i++) {
            var bloque = document.getElementById("t-menu-" + i);
            if (!bloque || bloque.style.display === "none") continue;
            var hidden = document.getElementById("t-tipo-val-" + i);
            total += preciosMenus[hidden ? hidden.value : "Zabisu"] || 0;
        }
        document.querySelectorAll(".t-extra-card").forEach(function (card) {
            var id    = card.id.replace("t-ex-card-", "");
            var val   = parseInt(document.getElementById("t-ex-val-" + id)?.value) || 0;
            var precio = parseFloat(card.dataset.precio) || 25;
            total += val * precio;
        });
        var el = document.getElementById("t-total");
        if (el) el.textContent = "$" + total.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ",");
    }

    /* ── Submit: deshabilitar para evitar doble envío ── */
    var form   = document.getElementById("form-tableta");
    var submit   = document.getElementById("t-submit");
    var enviando = false;
    if (form && submit) {
        form.addEventListener("submit", function (e) {
            if (enviando) { e.preventDefault(); return; }
            enviando = true;
            setTimeout(function () {
                submit.disabled    = true;
                submit.textContent = "Guardando...";
            }, 50);
        });
    }

    /* ── Init ── */
    actualizarBloques();
    actualizarTotal();
})();
</script>

</body>
</html>
