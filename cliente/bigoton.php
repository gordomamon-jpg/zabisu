<?php
require_once "../config/db.php";
require_once "../includes/menu_programado.php";
require_once "../includes/limite_platos.php";
require_once "../includes/opciones_plato.php";
require_once "../includes/agua_natural.php";
date_default_timezone_set("America/Mexico_City");

/* ── Modo prueba ── */
$stmtMP = $conexion->prepare("SELECT valor FROM configuracion WHERE clave = 'modo_prueba' LIMIT 1");
$stmtMP->execute();
$esPrueba = (int)($stmtMP->fetchColumn() ?? 0);

/* ── Horario fijo de El Bigoton ── */
$stmtH = $conexion->prepare(
    "SELECT h.id_horario, h.hora_entrega, u.nombre_ubicacion
     FROM horarios_ubicacion h
     INNER JOIN ubicaciones u ON h.id_ubicacion = u.id_ubicacion
     WHERE u.nombre_ubicacion = 'El Bigoton' AND h.activo = 1 AND u.activo = 1
     ORDER BY h.hora_entrega ASC
     LIMIT 1"
);
$stmtH->execute();
$horarioBigoton = $stmtH->fetch(PDO::FETCH_ASSOC);

/* ── Menú activo ── */
// Menú activo; si no hay, el último publicado (ver includes/menu_programado.php)
$menuActivo = menuActualRestaurante($conexion);

/* ── Fecha formateada ── */
$fechaMenuFormateada = "";
if ($menuActivo) {
    $dias  = ['Sunday'=>'domingo','Monday'=>'lunes','Tuesday'=>'martes','Wednesday'=>'miércoles','Thursday'=>'jueves','Friday'=>'viernes','Saturday'=>'sábado'];
    $meses = ['01'=>'enero','02'=>'febrero','03'=>'marzo','04'=>'abril','05'=>'mayo','06'=>'junio','07'=>'julio','08'=>'agosto','09'=>'septiembre','10'=>'octubre','11'=>'noviembre','12'=>'diciembre'];
    $ts    = strtotime($menuActivo["fecha"]);
    $fechaMenuFormateada = $dias[date('l', $ts)] . " " . date('j', $ts) . " de " . ($meses[date('m', $ts)] ?? "");
}

/* ── Productos organizados por tipo_menu ── */
$platosPorTipo       = [];
$complementosPorTipo = [];
$autoIncluirPorTipo  = []; // Sopa, Agua, Cortesia → se insertan automáticamente
$productosIndexados  = [];

if ($menuActivo) {
    $stmtProd = $conexion->prepare(
        "SELECT * FROM productos
         WHERE id_menu = :id_menu AND disponible = 1
           AND categoria IN ('Plato fuerte', 'Complemento', 'Sopa', 'Agua', 'Cortesia')
         ORDER BY tipo_menu, categoria, nombre"
    );
    $stmtProd->execute([":id_menu" => $menuActivo["id_menu"]]);

    $stmtCont = $conexion->prepare(
        "SELECT dp.id_producto, COUNT(*) AS total_pedidos
         FROM detalle_pedido dp
         INNER JOIN pedido_menus pm ON dp.id_pedido_menu = pm.id_pedido_menu
         INNER JOIN pedidos p       ON pm.id_pedido = p.id_pedido
         INNER JOIN productos pr    ON dp.id_producto = pr.id_producto
         WHERE p.estado != 'Cancelado'
           AND p.es_prueba = 0
           AND pr.id_menu = :id_menu
         GROUP BY dp.id_producto"
    );
    $stmtCont->execute([":id_menu" => $menuActivo["id_menu"]]);
    $conteos = [];
    foreach ($stmtCont->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $conteos[$row["id_producto"]] = (int)$row["total_pedidos"];
    }

    foreach ($stmtProd->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $idP = (int)$p["id_producto"];
        $p["agotado"] = !empty($p["limite_pedidos"]) && ($conteos[$idP] ?? 0) >= (int)$p["limite_pedidos"];
        $productosIndexados[$idP] = $p;
        if ($p["categoria"] === "Plato fuerte") {
            $platosPorTipo[$p["tipo_menu"]][] = $p;
        } elseif ($p["categoria"] === "Complemento") {
            $complementosPorTipo[$p["tipo_menu"]][] = $p;
        } elseif (in_array($p["categoria"], ["Sopa", "Agua", "Cortesia"])) {
            if (aguaEsNatural($p)) continue; // El Bigotón lleva el agua del día
            if (!isset($autoIncluirPorTipo[$p["tipo_menu"]][$p["categoria"]])) {
                $autoIncluirPorTipo[$p["tipo_menu"]][$p["categoria"]] = $p;
            }
        }
    }
}

/* ── Precios de tipos de menú ── */
$stmtTipos = $conexion->prepare("SELECT nombre_menu, precio FROM tipos_menu WHERE activo = 1");
$stmtTipos->execute();
$preciosMenus = [];
foreach ($stmtTipos->fetchAll(PDO::FETCH_ASSOC) as $t) {
    $preciosMenus[$t["nombre_menu"]] = (float)$t["precio"];
}
$preciosMenusJSON = json_encode($preciosMenus);

/* ── Cantidad de personas ── */
$cantidadPersonas = isset($_POST["cantidad_personas"]) ? max(1, min(15, (int)$_POST["cantidad_personas"])) : 1;

/* ── Resultado ── */
$errores             = [];
$exito               = false;
$folioGenerado       = "";
$nombresConfirmacion = [];

/* ════════════════════════════════════════════════════
   Procesar POST
   ════════════════════════════════════════════════════ */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["ordenar"])) {

    if (!$horarioBigoton) {
        $errores[] = "No hay horario configurado para El Bigoton. Avisa al administrador.";
    }
    if (!$menuActivo) {
        $errores[] = "No hay menú disponible por el momento.";
    }

    $correo = trim($_POST["correo_cliente"] ?? "");
    if ($correo !== "" && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        $errores[] = "El correo electrónico no es válido.";
    }

    $personas       = $_POST["personas"] ?? [];
    $erroresPersonas = [];
    $opcionesPersonas = []; // opción validada del guisado por persona (p. ej. aderezo)

    for ($i = 0; $i < $cantidadPersonas; $i++) {
        $persona      = $personas[$i] ?? [];
        $nPersona     = $i + 1;
        $nombre       = strtoupper(trim(preg_replace('/[^A-Za-záéíóúÁÉÍÓÚüÜñÑ\s]/u', '', $persona["nombre"] ?? "")));
        $tipoMenu     = trim($persona["tipo_menu"]    ?? "");
        $platoFuerte  = (int)($persona["plato_fuerte"] ?? 0);
        $compIds      = array_map('intval', array_filter($persona["complementos"] ?? [], fn($v) => (int)$v > 0));

        if ($nombre === "")                                $erroresPersonas[$nPersona][] = "Falta el nombre.";
        if (!in_array($tipoMenu, ["Zabisu", "Ejecutivo"])) $erroresPersonas[$nPersona][] = "Selecciona el tipo de menú.";
        if ($platoFuerte <= 0)                             $erroresPersonas[$nPersona][] = "Selecciona el guisado.";

        if ($platoFuerte > 0) {
            if (!isset($productosIndexados[$platoFuerte])) {
                $erroresPersonas[$nPersona][] = "Guisado no válido.";
            } else {
                $prod = $productosIndexados[$platoFuerte];
                if ($prod["tipo_menu"] !== $tipoMenu) $erroresPersonas[$nPersona][] = "El guisado no corresponde al tipo de menú elegido.";
                $resOpcion = validarOpcionPlato($prod, $persona["opcion"] ?? "");
                if ($resOpcion["error"]) $erroresPersonas[$nPersona][] = $resOpcion["error"];
                $opcionesPersonas[$i] = $resOpcion["opcion"];
            }
        }

        // Complementos opcionales: validar que pertenezcan al menú y tipo correcto
        $maxComp = ($platoFuerte > 0 && !empty($productosIndexados[$platoFuerte]["complementos_max"]))
            ? (int)$productosIndexados[$platoFuerte]["complementos_max"] : 2;
        if (count($compIds) > $maxComp) {
            $erroresPersonas[$nPersona][] = "Máximo {$maxComp} complemento(s) para este plato.";
        }
        foreach ($compIds as $cid) {
            if (!isset($productosIndexados[$cid]) || $productosIndexados[$cid]["categoria"] !== "Complemento") {
                $erroresPersonas[$nPersona][] = "Complemento no válido.";
                break;
            }
        }
    }

    // Cupo: sumar cuántas personas de este pedido llevan el mismo guisado
    $platosPedido = [];
    for ($i = 0; $i < $cantidadPersonas; $i++) {
        $idPlato = (int)($personas[$i]["plato_fuerte"] ?? 0);
        if ($idPlato) $platosPedido[$idPlato] = ($platosPedido[$idPlato] ?? 0) + 1;
    }
    foreach (validarCupoPlatos($conexion, $platosPedido) as $idPlato => $info) {
        for ($i = 0; $i < $cantidadPersonas; $i++) {
            if ((int)($personas[$i]["plato_fuerte"] ?? 0) === $idPlato) {
                $erroresPersonas[$i + 1][] = mensajeCupoPlato($info);
            }
        }
    }

    foreach ($erroresPersonas as $n => $errs) {
        foreach ($errs as $e) $errores[] = "Persona {$n}: {$e}";
    }

    if (empty($errores) && $horarioBigoton && $menuActivo) {
        try {
            $conexion->beginTransaction();

            // Re-validar el cupo con las filas bloqueadas (evita que dos
            // pedidos simultáneos por los últimos lugares pasen los dos)
            $sinCupo = validarCupoPlatos($conexion, $platosPedido, true);
            if (!empty($sinCupo)) {
                throw new RuntimeException(mensajeCupoPlato(reset($sinCupo)));
            }

            /* Total */
            $total = 0.0;
            for ($i = 0; $i < $cantidadPersonas; $i++) {
                $total += $preciosMenus[trim($personas[$i]["tipo_menu"] ?? "")] ?? 0;
            }

            /* Observaciones: lista de nombres */
            $nombresObs = [];
            for ($i = 0; $i < $cantidadPersonas; $i++) {
                $nombresObs[] = "Menú " . ($i + 1) . " – " . trim($personas[$i]["nombre"]);
            }
            $observaciones = implode(" / ", $nombresObs);

            /* Folio */
            $folio = "BIG-" . strtoupper(substr(uniqid(), -6));

            /* Insertar pedido */
            $stmtIns = $conexion->prepare(
                "INSERT INTO pedidos
                     (folio, nombre_cliente, telefono, correo_cliente, id_horario,
                      metodo_pago, estado_pago, total, observaciones, es_prueba, fecha_pedido)
                 VALUES
                     (:folio, 'El Bigoton', '0000000000', :correo, :id_horario,
                      'Efectivo', 'Pago en efectivo', :total, :obs, :es_prueba, NOW())"
            );
            $stmtIns->execute([
                ":folio"      => $folio,
                ":correo"     => $correo,
                ":id_horario" => $horarioBigoton["id_horario"],
                ":total"      => $total,
                ":obs"        => $observaciones,
                ":es_prueba"  => $esPrueba,
            ]);
            $id_pedido = (int)$conexion->lastInsertId();

            /* Insertar menús y detalle */
            $stmtPM = $conexion->prepare(
                "INSERT INTO pedido_menus (id_pedido, numero_menu, tipo_menu, nombre_persona)
                 VALUES (:id_pedido, :num, :tipo, :nombre_persona)"
            );
            $stmtDP = $conexion->prepare(
                "INSERT INTO detalle_pedido (id_pedido_menu, id_producto, categoria, nombre_producto, opcion)
                 VALUES (:id_pm, :id_prod, :categoria, :nombre, :opcion)"
            );

            for ($i = 0; $i < $cantidadPersonas; $i++) {
                $tipoMenu    = trim($personas[$i]["tipo_menu"]);
                $platoFuerte = (int)$personas[$i]["plato_fuerte"];
                $compIds     = array_map('intval', array_filter($personas[$i]["complementos"] ?? [], fn($v) => (int)$v > 0));

                $nombrePersona = trim($personas[$i]["nombre"]);
                $stmtPM->execute([":id_pedido" => $id_pedido, ":num" => $i + 1, ":tipo" => $tipoMenu, ":nombre_persona" => $nombrePersona]);
                $id_pedido_menu = (int)$conexion->lastInsertId();

                // Plato fuerte
                $prod = $productosIndexados[$platoFuerte];
                $stmtDP->execute([
                    ":id_pm"     => $id_pedido_menu,
                    ":id_prod"   => $platoFuerte,
                    ":categoria" => "Plato fuerte",
                    ":nombre"    => $prod["nombre"],
                    ":opcion"    => $opcionesPersonas[$i] ?? null,
                ]);

                // Complementos opcionales
                $nombresComp = [];
                foreach ($compIds as $cid) {
                    if (!isset($productosIndexados[$cid])) continue;
                    $stmtDP->execute([
                        ":id_pm"     => $id_pedido_menu,
                        ":id_prod"   => $cid,
                        ":categoria" => "Complemento",
                        ":nombre"    => $productosIndexados[$cid]["nombre"],
                        ":opcion"    => null,
                    ]);
                    $nombresComp[] = $productosIndexados[$cid]["nombre"];
                }

                // Sopa, Agua y Cortesia (auto-incluir según tipo de menú)
                foreach (["Sopa", "Agua", "Cortesia"] as $catAuto) {
                    $prodAuto = $autoIncluirPorTipo[$tipoMenu][$catAuto] ?? null;
                    if (!$prodAuto) continue;
                    $stmtDP->execute([
                        ":id_pm"     => $id_pedido_menu,
                        ":id_prod"   => (int)$prodAuto["id_producto"],
                        ":categoria" => $catAuto,
                        ":nombre"    => $prodAuto["nombre"],
                        ":opcion"    => null,
                    ]);
                }

                $lineaConf = trim($personas[$i]["nombre"]) . " — " . $prod["nombre"]
                           . (!empty($opcionesPersonas[$i]) ? " (" . $opcionesPersonas[$i] . ")" : "");
                if (!empty($nombresComp)) {
                    $lineaConf .= " (+ " . implode(", ", $nombresComp) . ")";
                }
                $nombresConfirmacion[] = $lineaConf;
            }

            $conexion->commit();
            $exito         = true;
            $folioGenerado = $folio;
        } catch (Exception $e) {
            if ($conexion->inTransaction()) $conexion->rollBack();
            $nombresConfirmacion = [];
            $errores[] = $e instanceof PDOException ? "No se pudo guardar el pedido. Intenta de nuevo." : $e->getMessage();
        }
    }
}

/* ── Sin horario o sin menú: letrero de aviso del diseño Pizarrón ── */
if (!$horarioBigoton || !$menuActivo || empty($platosPorTipo)) {
    require_once "../includes/pz_aviso.php";
    pzPantallaAviso(!$horarioBigoton ? [
        'titulo'   => 'Falta el horario',
        'letrero'  => 'Pausa',
        'letrero2' => 'Avisa a Zabisu',
        'texto'    => 'No hay horario configurado para El Bigotón. Avisa al administrador.',
    ] : [
        'titulo'   => 'Todavía no hay menú',
        'letrero'  => 'Sin menú',
        'letrero2' => 'Vuelve en un rato',
        'texto'    => 'En cuanto se publique el menú del día podrás hacer el pedido aquí.',
    ]);
    exit;
}

$horaEntrega = date("g:i a", strtotime($horarioBigoton["hora_entrega"]));
[$diaMenu, $restoFecha] = array_pad(explode(" de ", $fechaMenuFormateada, 2), 2, "");
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Pedido El Bigotón | Zabisu</title>
    <link rel="icon" type="image/png" href="../assets/img/LOGO_NARA.png">
    <meta name="theme-color" content="#151413">
    <link rel="preload" href="../assets/fonts/kaushan-script-400.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="../assets/fonts/alfa-slab-one-400.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="../assets/css/menu-pizarron.css?v=2">
    <link rel="stylesheet" href="../assets/css/bigoton-pizarron.css?v=2">
</head>
<body class="pz bg">
<?php require __DIR__ . "/../includes/pz_filtros.php"; ?>

<main class="pz-pagina bg-pagina<?php echo $exito ? " bg-pagina--listo" : ""; ?>">

    <div class="bg-top">
        <header class="pz-cab">
            <div class="pz-marca">
                <img src="../assets/img/LOGO_N_128.png" alt="">
                <div>
                    <span class="pz-marca__nombre">Zabisu</span>
                    <span class="pz-marca__lema">Sabor y Servicio</span>
                </div>
            </div>
        </header>

        <?php if ($exito): ?>
            <p class="bg-eyebrow">Pedido de El Bigotón</p>
            <h1 class="pz-titulo"><span class="pz-pincel"><span>¡Pedido recibido!</span></span></h1>
            <p class="bg-sub">Lo entregamos a las <?php echo $horaEntrega; ?>. Pago en efectivo al recibir.</p>
        <?php else: ?>
            <p class="bg-eyebrow">Pedido de El Bigotón</p>
            <h1 class="pz-titulo"><span class="pz-pincel"><span>Menú del <?php echo htmlspecialchars($diaMenu); ?></span></span></h1>
            <p class="bg-sub">de <?php echo htmlspecialchars($restoFecha); ?>. Entrega a las <?php echo $horaEntrega; ?>, pago en efectivo.</p>
        <?php endif; ?>
    </div>

    <?php if ($exito): ?>
    <!-- ── CONFIRMACIÓN ─────────────────────────── -->
    <section class="bg-listo">
        <div class="pz-boleto bg-boleto">
            <div class="pz-boleto__papel">
                <span class="pz-boleto__ojo" aria-hidden="true"></span>
                <p class="bg-boleto__folio"><span>Folio</span><b><?php echo htmlspecialchars($folioGenerado); ?></b></p>
                <hr class="pz-boleto__corte">
                <ol class="bg-resumen">
                    <?php foreach ($nombresConfirmacion as $linea): ?>
                        <li><?php echo htmlspecialchars($linea); ?></li>
                    <?php endforeach; ?>
                </ol>
                <hr class="pz-boleto__corte">
                <p class="bg-boleto__entrega">El Bigotón, <?php echo $horaEntrega; ?></p>
            </div>
        </div>
        <a href="bigoton.php" class="pz-btn bg-otro">Hacer otro pedido</a>
    </section>

    <?php else: ?>
    <!-- ── FORMULARIO ───────────────────────────── -->
    <form method="POST" action="" id="form-bigoton" class="bg-form" novalidate>
        <input type="hidden" name="ordenar" value="1">

        <div class="bg-col">
            <?php if (!empty($errores)): ?>
                <div class="bg-errores" role="alert">
                    <p>Revisa esto antes de enviar:</p>
                    <ul>
                        <?php foreach ($errores as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <!-- Cuántos menús -->
            <section class="bg-cuantos" aria-label="Cuántos menús">
                <span class="bg-cuantos__txt">¿Cuántos menús?</span>
                <div class="bg-stepper">
                    <button type="button" class="bg-stepper__btn" id="bg-menos" aria-label="Un menú menos">
                        <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M5 12h14" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/></svg>
                    </button>
                    <output class="bg-stepper__num" id="bg-num" aria-live="polite"><?php echo $cantidadPersonas; ?></output>
                    <button type="button" class="bg-stepper__btn" id="bg-mas" aria-label="Un menú más">
                        <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M12 5v14M5 12h14" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/></svg>
                    </button>
                </div>
                <input type="hidden" name="cantidad_personas" id="cantidad_personas" value="<?php echo $cantidadPersonas; ?>">
            </section>

            <!-- Una tarjeta por persona -->
            <div class="bg-personas">
            <?php for ($i = 0; $i < 15; $i++):
                $oculta   = $i >= $cantidadPersonas;
                $tipoSel  = $_POST["personas"][$i]["tipo_menu"] ?? "Zabisu";
                $platoSel = (int)($_POST["personas"][$i]["plato_fuerte"] ?? 0);
                $compSel  = array_map('intval', array_filter($_POST["personas"][$i]["complementos"] ?? [], fn($v) => (int)$v > 0));
                $dis      = $oculta ? " disabled" : "";
            ?>
                <section class="bg-persona" id="persona-<?php echo $i; ?>" data-persona="<?php echo $i; ?>"<?php echo $oculta ? " hidden" : ""; ?>>
                    <div class="bg-persona__cab">
                        <h2 class="pz-cinta pz-fibra">Persona <?php echo $i + 1; ?></h2>
                        <span class="bg-falta" hidden></span>
                    </div>

                    <label class="bg-campo">
                        <span>Nombre</span>
                        <input type="text" name="personas[<?php echo $i; ?>][nombre]" maxlength="80" autocomplete="off"
                               class="bg-nombre" data-persona="<?php echo $i; ?>"
                               value="<?php echo htmlspecialchars($_POST["personas"][$i]["nombre"] ?? ""); ?>"<?php echo $dis; ?>>
                    </label>

                    <div class="bg-tipos" role="radiogroup" aria-label="Tipo de menú de la persona <?php echo $i + 1; ?>">
                        <?php foreach (["Zabisu", "Ejecutivo"] as $tipo): ?>
                            <label class="bg-tipo">
                                <input type="radio" name="personas[<?php echo $i; ?>][tipo_menu]" value="<?php echo $tipo; ?>"
                                       class="radio-tipo-menu" data-persona="<?php echo $i; ?>"
                                       <?php echo $tipoSel === $tipo ? "checked" : ""; ?><?php echo $dis; ?>>
                                <span class="bg-tipo__nombre">Menú <?php echo $tipo; ?></span>
                                <span class="bg-tipo__precio">$<?php echo number_format($preciosMenus[$tipo] ?? 0, 0); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <?php foreach (["Zabisu", "Ejecutivo"] as $tipo): $visible = $tipoSel === $tipo; ?>
                    <div class="bg-platos" id="platos-<?php echo $i; ?>-<?php echo $tipo; ?>"<?php echo $visible ? "" : " hidden"; ?>>
                        <p class="bg-titulo-sec">Guisado</p>
                        <?php if (!empty($platosPorTipo[$tipo])): ?>
                        <div class="bg-lista">
                            <?php foreach ($platosPorTipo[$tipo] as $prod): $idP = (int)$prod["id_producto"]; ?>
                                <label class="bg-opcion<?php echo $prod["agotado"] ? " bg-opcion--agotado" : ""; ?>">
                                    <input type="radio" name="personas[<?php echo $i; ?>][plato_fuerte]" value="<?php echo $idP; ?>"
                                           class="radio-plato" data-persona="<?php echo $i; ?>"
                                           data-max="<?php echo !empty($prod["complementos_max"]) ? (int)$prod["complementos_max"] : 2; ?>"
                                           data-nombre="<?php echo htmlspecialchars($prod["nombre"]); ?>"
                                           <?php echo $prod["agotado"] ? 'data-agotado="1" disabled' : ($oculta || !$visible ? "disabled" : ""); ?>
                                           <?php echo $platoSel === $idP ? "checked" : ""; ?>>
                                    <span class="bg-opcion__marca" aria-hidden="true"></span>
                                    <span class="bg-opcion__nombre"><?php echo htmlspecialchars($prod["nombre"]); ?></span>
                                    <?php if ($prod["agotado"]): ?><span class="bg-agotado">Agotado</span><?php endif; ?>
                                </label>
                                <?php echo htmlOpcionesPlato($prod, "personas[{$i}]", $_POST["personas"][$i]["opcion"] ?? null); ?>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                            <p class="bg-nota">Sin guisados para este menú.</p>
                        <?php endif; ?>

                        <?php if (!empty($complementosPorTipo[$tipo])): ?>
                        <p class="bg-titulo-sec">Complementos <small>opcional, hasta <span class="bg-max">2</span></small></p>
                        <div class="bg-chips">
                            <?php foreach ($complementosPorTipo[$tipo] as $comp): $idC = (int)$comp["id_producto"]; ?>
                                <label class="bg-chip">
                                    <input type="checkbox" name="personas[<?php echo $i; ?>][complementos][]" value="<?php echo $idC; ?>"
                                           class="check-complemento" data-persona="<?php echo $i; ?>" data-tipo="<?php echo $tipo; ?>"
                                           data-nombre="<?php echo htmlspecialchars($comp["nombre"]); ?>"
                                           <?php echo in_array($idC, $compSel, true) ? "checked" : ""; ?><?php echo $oculta || !$visible ? " disabled" : ""; ?>>
                                    <span><?php echo htmlspecialchars($comp["nombre"]); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>

                    <button type="button" class="bg-quitar" data-persona="<?php echo $i; ?>"<?php echo $i === 0 ? " hidden" : ""; ?>>Quitar esta persona</button>
                </section>
            <?php endfor; ?>
            </div>

            <button type="button" class="bg-agregar" id="bg-agregar">
                <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M12 5v14M5 12h14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg>
                Agregar otra persona
            </button>
        </div>

        <!-- La cuenta: resumen en vivo, correo y enviar -->
        <aside class="bg-cuenta" aria-label="Resumen del pedido">
            <div class="pz-boleto bg-boleto">
                <div class="pz-boleto__papel">
                    <span class="pz-boleto__ojo" aria-hidden="true"></span>
                    <p class="pz-boleto__titulo">Tu pedido</p>
                    <ol class="bg-resumen" id="bg-resumen"></ol>
                    <hr class="pz-boleto__corte">
                    <div class="bg-total"><span>Total, en efectivo</span><b id="total-bigoton">$0</b></div>
                </div>
            </div>

            <label class="bg-campo bg-correo">
                <span>Correo para la confirmación <small>(opcional)</small></span>
                <input type="email" name="correo_cliente" id="correo_cliente" maxlength="150" autocomplete="off"
                       value="<?php echo htmlspecialchars($_POST["correo_cliente"] ?? ""); ?>">
                <small class="bg-correo__error" id="bg-correo-error" hidden>Ingresa un correo válido.</small>
            </label>

            <div class="pz-barra bg-barra">
                <button type="submit" name="ordenar" value="1" class="pz-btn bg-enviar" id="bg-enviar">
                    <span>Enviar pedido</span>
                    <span id="bg-total-btn">$0</span>
                </button>
            </div>
        </aside>
    </form>
    <?php endif; ?>

</main>

<?php if (!$exito): ?>
<script>
(function () {
    var MAX      = 15;
    var precios  = <?php echo $preciosMenusJSON; ?>;
    var form     = document.getElementById("form-bigoton");
    var cantidad = document.getElementById("cantidad_personas");

    function persona(i) { return document.getElementById("persona-" + i); }
    function n() { return parseInt(cantidad.value, 10) || 1; }
    function pesos(v) { return "$" + v.toLocaleString("es-MX", { maximumFractionDigits: 2 }); }

    // Activa o apaga los campos de un grupo; los agotados y los aderezos (los maneja
    // scriptOpcionesPlato) no se tocan
    function activar(contenedor, si) {
        contenedor.querySelectorAll("input").forEach(function (el) {
            if (el.closest(".opciones-plato") || el.dataset.agotado) return;
            el.disabled = !si;
        });
    }

    /* ── Tipo de menú: muestra sus guisados y complementos ── */
    function mostrarTipo(i) {
        var p = persona(i);
        var marcado = p.querySelector(".radio-tipo-menu:checked");
        var tipo = marcado ? marcado.value : "Zabisu";
        ["Zabisu", "Ejecutivo"].forEach(function (t) {
            var g = document.getElementById("platos-" + i + "-" + t);
            if (!g) return;
            var ver = t === tipo && !p.hidden;
            g.hidden = t !== tipo;
            activar(g, ver);
            if (t !== tipo) g.querySelectorAll("input").forEach(function (el) { if (!el.closest(".opciones-plato")) el.checked = false; });
        });
        limiteComplementos(i);
    }

    /* ── Cuántas personas ── */
    function ponerCantidad(c) {
        c = Math.max(1, Math.min(MAX, c));
        cantidad.value = c;
        document.getElementById("bg-num").textContent = c;
        for (var i = 0; i < MAX; i++) {
            var p = persona(i);
            var ver = i < c;
            p.hidden = !ver;
            p.querySelectorAll(".bg-nombre, .radio-tipo-menu").forEach(function (el) { el.disabled = !ver; });
            mostrarTipo(i);
            var q = p.querySelector(".bg-quitar");
            if (q) q.hidden = !(ver && i === c - 1 && c > 1);
        }
        document.getElementById("bg-menos").disabled = c <= 1;
        document.getElementById("bg-mas").disabled = c >= MAX;
        document.getElementById("bg-agregar").hidden = c >= MAX;
        actualizar();
    }

    document.getElementById("bg-menos").addEventListener("click", function () { ponerCantidad(n() - 1); });
    document.getElementById("bg-mas").addEventListener("click", function () { ponerCantidad(n() + 1); });
    document.getElementById("bg-agregar").addEventListener("click", function () {
        ponerCantidad(n() + 1);
        var nueva = persona(n() - 1);
        nueva.scrollIntoView({ behavior: "smooth", block: "start" });
        setTimeout(function () { nueva.querySelector(".bg-nombre").focus({ preventScroll: true }); }, 300);
    });
    form.addEventListener("click", function (e) {
        var q = e.target.closest(".bg-quitar");
        if (q) ponerCantidad(n() - 1);
    });

    /* ── Complementos: el máximo depende del guisado elegido ── */
    function limiteComplementos(i, recien) {
        var p = persona(i);
        var plato = p.querySelector(".radio-plato:checked");
        var max = plato ? parseInt(plato.dataset.max, 10) || 2 : 2;
        p.querySelectorAll(".bg-max").forEach(function (s) { s.textContent = max; });
        var grupo = p.querySelector(".bg-platos:not([hidden])");
        if (!grupo) return;
        var marcados = Array.prototype.slice.call(grupo.querySelectorAll(".check-complemento:checked"));
        while (marcados.length > max) {
            var fuera = marcados[0] === recien ? marcados[1] : marcados[0];
            fuera.checked = false;
            marcados.splice(marcados.indexOf(fuera), 1);
        }
    }

    /* ── Resumen en vivo y total ── */
    function actualizar() {
        var total = 0;
        var ol = document.getElementById("bg-resumen");
        ol.innerHTML = "";
        for (var i = 0; i < n(); i++) {
            var p = persona(i);
            var tipo = p.querySelector(".radio-tipo-menu:checked");
            if (tipo) total += precios[tipo.value] || 0;
            var nombre = p.querySelector(".bg-nombre").value.trim();
            var plato = p.querySelector(".radio-plato:checked");
            var comps = Array.prototype.map.call(p.querySelectorAll(".bg-platos:not([hidden]) .check-complemento:checked"), function (c) { return c.dataset.nombre; });
            var li = document.createElement("li");
            var b = document.createElement("b");
            b.textContent = nombre || "Persona " + (i + 1);
            li.appendChild(b);
            var span = document.createElement("span");
            var aderezo = p.querySelector(".opciones-plato input:checked");
            span.textContent = plato
                ? plato.dataset.nombre + (aderezo ? " (" + aderezo.value + ")" : "") + (comps.length ? ", con " + comps.join(" y ") : "")
                : "Falta el guisado";
            if (!plato) span.className = "bg-resumen__falta";
            li.appendChild(span);
            ol.appendChild(li);
        }
        document.getElementById("total-bigoton").textContent = pesos(total);
        document.getElementById("bg-total-btn").textContent = pesos(total);
    }

    form.addEventListener("change", function (e) {
        var t = e.target;
        var i = parseInt(t.dataset.persona, 10);
        if (t.classList.contains("radio-tipo-menu")) mostrarTipo(i);
        if (t.classList.contains("radio-plato")) limiteComplementos(i);
        if (t.classList.contains("check-complemento") && t.checked) limiteComplementos(i, t);
        if (!isNaN(i)) persona(i).classList.remove("bg-persona--falta");
        actualizar();
    });

    /* ── Nombres: solo letras y espacios, en MAYÚSCULAS ── */
    form.addEventListener("input", function (e) {
        var input = e.target;
        if (!input.classList.contains("bg-nombre")) return;
        var pos = input.selectionStart;
        var limpio = input.value.replace(/[^A-Za-záéíóúÁÉÍÓÚüÜñÑ\s]/g, "").toUpperCase();
        if (input.value !== limpio) {
            input.value = limpio;
            input.setSelectionRange(pos, pos);
        }
        actualizar();
    });

    /* ── Correo ── */
    var correo = document.getElementById("correo_cliente");
    var correoError = document.getElementById("bg-correo-error");
    function correoValido() {
        var v = correo.value.trim();
        return v === "" || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v);
    }
    correo.addEventListener("blur", function () { correoError.hidden = correoValido(); });
    correo.addEventListener("input", function () { correoError.hidden = true; });

    /* ── Antes de enviar: marcar a quién le falta algo ── */
    form.addEventListener("submit", function (e) {
        var primera = null;
        for (var i = 0; i < n(); i++) {
            var p = persona(i);
            var faltas = [];
            if (!p.querySelector(".bg-nombre").value.trim()) faltas.push("nombre");
            if (!p.querySelector(".radio-plato:checked")) faltas.push("guisado");
            if (window.opcionesPlatoPendientes && window.opcionesPlatoPendientes(p).length) faltas.push("aderezo");
            var aviso = p.querySelector(".bg-falta");
            aviso.hidden = !faltas.length;
            aviso.textContent = faltas.length ? "Falta: " + faltas.join(", ") : "";
            p.classList.toggle("bg-persona--falta", faltas.length > 0);
            if (faltas.length && !primera) primera = p;
        }
        if (!correoValido()) { correoError.hidden = false; primera = primera || correo; }
        if (primera) {
            e.preventDefault();
            primera.scrollIntoView({ behavior: "smooth", block: "start" });
            return;
        }
        var b = document.getElementById("bg-enviar");
        b.disabled = true;
        b.firstElementChild.textContent = "Enviando…";
    });

    ponerCantidad(n());
})();
</script>
<?php endif; ?>

<?php echo scriptOpcionesPlato(); ?>
</body>
</html>
