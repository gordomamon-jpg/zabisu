<?php
require_once "../config/db.php";
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

/* ── Menú más reciente ── */
$stmtMenu = $conexion->prepare("SELECT * FROM menu_dia ORDER BY fecha DESC LIMIT 1");
$stmtMenu->execute();
$menuActivo = $stmtMenu->fetch(PDO::FETCH_ASSOC);

/* ── Fecha formateada ── */
$fechaMenuFormateada = "";
if ($menuActivo) {
    $dias  = ['Sunday'=>'domingo','Monday'=>'lunes','Tuesday'=>'martes','Wednesday'=>'miércoles','Thursday'=>'jueves','Friday'=>'viernes','Saturday'=>'sábado'];
    $meses = ['01'=>'enero','02'=>'febrero','03'=>'marzo','04'=>'abril','05'=>'mayo','06'=>'junio','07'=>'julio','08'=>'agosto','09'=>'septiembre','10'=>'octubre','11'=>'noviembre','12'=>'diciembre'];
    $ts    = strtotime($menuActivo["fecha"]);
    $fechaMenuFormateada = $dias[date('l', $ts)] . " " . date('j', $ts) . " de " . ($meses[date('m', $ts)] ?? "");
}

/* ── Platos fuertes organizados por tipo_menu ── */
$platosPorTipo      = [];
$productosIndexados = [];

if ($menuActivo) {
    $stmtProd = $conexion->prepare(
        "SELECT * FROM productos
         WHERE id_menu = :id_menu AND disponible = 1 AND categoria = 'Plato fuerte'
         ORDER BY tipo_menu, nombre"
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
        $idP       = (int)$p["id_producto"];
        $p["agotado"] = !empty($p["limite_pedidos"]) && ($conteos[$idP] ?? 0) >= (int)$p["limite_pedidos"];
        $platosPorTipo[$p["tipo_menu"]][] = $p;
        $productosIndexados[$idP]         = $p;
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

    for ($i = 0; $i < $cantidadPersonas; $i++) {
        $persona     = $personas[$i] ?? [];
        $nPersona    = $i + 1;
        $nombre      = trim($persona["nombre"]       ?? "");
        $tipoMenu    = trim($persona["tipo_menu"]    ?? "");
        $platoFuerte = (int)($persona["plato_fuerte"] ?? 0);

        if ($nombre === "")                                $erroresPersonas[$nPersona][] = "Falta el nombre.";
        if (!in_array($tipoMenu, ["Zabisu", "Ejecutivo"])) $erroresPersonas[$nPersona][] = "Selecciona el tipo de menú.";
        if ($platoFuerte <= 0)                             $erroresPersonas[$nPersona][] = "Selecciona el guisado.";

        if ($platoFuerte > 0) {
            if (!isset($productosIndexados[$platoFuerte])) {
                $erroresPersonas[$nPersona][] = "Guisado no válido.";
            } else {
                $prod = $productosIndexados[$platoFuerte];
                if ($prod["tipo_menu"] !== $tipoMenu) $erroresPersonas[$nPersona][] = "El guisado no corresponde al tipo de menú elegido.";
                if ($prod["agotado"])                 $erroresPersonas[$nPersona][] = "El guisado seleccionado está agotado.";
            }
        }
    }

    foreach ($erroresPersonas as $n => $errs) {
        foreach ($errs as $e) $errores[] = "Persona {$n}: {$e}";
    }

    if (empty($errores) && $horarioBigoton && $menuActivo) {

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
            "INSERT INTO detalle_pedido (id_pedido_menu, id_producto, categoria, nombre_producto)
             VALUES (:id_pm, :id_prod, 'Plato fuerte', :nombre)"
        );

        for ($i = 0; $i < $cantidadPersonas; $i++) {
            $tipoMenu    = trim($personas[$i]["tipo_menu"]);
            $platoFuerte = (int)$personas[$i]["plato_fuerte"];

            $nombrePersona = trim($personas[$i]["nombre"]);
            $stmtPM->execute([":id_pedido" => $id_pedido, ":num" => $i + 1, ":tipo" => $tipoMenu, ":nombre_persona" => $nombrePersona]);
            $id_pedido_menu = (int)$conexion->lastInsertId();

            $prod = $productosIndexados[$platoFuerte];
            $stmtDP->execute([
                ":id_pm"   => $id_pedido_menu,
                ":id_prod" => $platoFuerte,
                ":nombre"  => $prod["nombre"],
            ]);

            $nombresConfirmacion[] = trim($personas[$i]["nombre"]) . " — " . $prod["nombre"];
        }

        $exito         = true;
        $folioGenerado = $folio;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pedido El Bigoton · Zabisu</title>
    <link rel="icon" type="image/png" href="../assets/img/LOGO_NARA.png">
    <meta name="theme-color" content="#FF7A00">
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
            <h1 class="md-hero__marca">El Bigoton</h1>
        </div>
        <?php if ($fechaMenuFormateada): ?>
            <p class="md-hero__fecha">Menú del <?php echo $fechaMenuFormateada; ?></p>
        <?php endif; ?>
        <?php if ($horarioBigoton): ?>
            <p class="md-hero__fecha" style="margin-top:4px;opacity:.8;">
                Entrega: <?php echo date("g:i A", strtotime($horarioBigoton["hora_entrega"])); ?>
            </p>
        <?php endif; ?>
    </div>

    <?php if (!$horarioBigoton): ?>
        <div class="bloque-formulario">
            <p class="nota-formulario" style="color:#ff6b6b;">
                No hay horario configurado para El Bigoton. Avisa al administrador.
            </p>
        </div>

    <?php elseif (!$menuActivo || empty($platosPorTipo)): ?>
        <div class="bloque-formulario">
            <p class="nota-formulario">No hay menú disponible por el momento.</p>
        </div>

    <?php elseif ($exito): ?>
        <!-- ── CONFIRMACIÓN ─────────────────────────── -->
        <div class="bloque-formulario resumen-total">
            <h2>¡Pedido recibido!</h2>
            <p class="nota-formulario">Tu pedido fue enviado correctamente.</p>

            <div class="ticket-resumen">
                <div class="ticket-menu">
                    <div class="ticket-linea">
                        <span>Folio</span>
                        <strong><?php echo htmlspecialchars($folioGenerado); ?></strong>
                    </div>
                    <div class="ticket-linea">
                        <span>Punto de entrega</span>
                        <span>El Bigoton</span>
                    </div>
                    <div class="ticket-linea">
                        <span>Horario</span>
                        <span><?php echo date("g:i A", strtotime($horarioBigoton["hora_entrega"])); ?></span>
                    </div>
                </div>
            </div>

            <h3 style="margin-top:20px;margin-bottom:10px;">Resumen de pedidos</h3>
            <div class="ticket-resumen">
                <div class="ticket-menu">
                    <?php foreach ($nombresConfirmacion as $i => $linea): ?>
                    <div class="ticket-linea">
                        <span>Menú <?php echo $i + 1; ?></span>
                        <span><?php echo htmlspecialchars($linea); ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <a href="bigoton.php" class="btn-principal" style="display:block;text-align:center;margin-top:24px;text-decoration:none;">
                Hacer otro pedido
            </a>
        </div>

    <?php else: ?>
        <!-- ── FORMULARIO ───────────────────────────── -->

        <?php if (!empty($errores)): ?>
        <div class="bloque-formulario" style="border-left:4px solid #ff6b6b;">
            <ul style="margin:0;padding-left:18px;color:#ff6b6b;">
                <?php foreach ($errores as $e): ?>
                    <li><?php echo htmlspecialchars($e); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <form method="POST" action="" id="form-bigoton">
            <input type="hidden" name="ordenar" value="1">

            <!-- Correo opcional -->
            <div class="bloque-formulario">
                <h2>Contacto <span class="nota-formulario" style="display:inline;font-size:13px;">(opcional)</span></h2>
                <label for="correo_cliente">Correo electrónico</label>
                <input type="email" name="correo_cliente" id="correo_cliente"
                       maxlength="150" autocomplete="off"
                       placeholder="Para recibir confirmación"
                       value="<?php echo htmlspecialchars($_POST["correo_cliente"] ?? ""); ?>">
            </div>

            <!-- Cantidad de personas -->
            <div class="bloque-formulario">
                <h2>¿Cuántas personas?</h2>
                <label for="cantidad_personas">Número de menús</label>
                <select name="cantidad_personas" id="cantidad_personas">
                    <?php for ($n = 1; $n <= 15; $n++): ?>
                        <option value="<?php echo $n; ?>" <?php echo $cantidadPersonas === $n ? "selected" : ""; ?>>
                            <?php echo $n; ?> persona<?php echo $n > 1 ? "s" : ""; ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>

            <!-- Bloques por persona -->
            <?php for ($i = 0; $i < 15; $i++): ?>
            <?php
                $tipoSel  = $_POST["personas"][$i]["tipo_menu"]    ?? "Zabisu";
                $platoSel = (int)($_POST["personas"][$i]["plato_fuerte"] ?? 0);
            ?>
            <div class="bloque-formulario bloque-persona" id="persona-<?php echo $i; ?>"
                 style="<?php echo $i >= $cantidadPersonas ? 'display:none;' : ''; ?>">

                <h2>Persona <?php echo $i + 1; ?></h2>

                <label>Nombre</label>
                <input type="text" name="personas[<?php echo $i; ?>][nombre]"
                       maxlength="80" autocomplete="off"
                       value="<?php echo htmlspecialchars($_POST["personas"][$i]["nombre"] ?? ""); ?>"
                       <?php echo $i >= $cantidadPersonas ? 'disabled' : ''; ?>>

                <!-- Tipo de menú -->
                <label style="margin-top:14px;display:block;">Tipo de menú</label>
                <div class="grupo-tipo-ubicacion" style="margin-top:6px;">
                    <?php foreach (["Zabisu", "Ejecutivo"] as $tipo): ?>
                    <?php $precio = $preciosMenus[$tipo] ?? 0; ?>
                    <label class="opcion-ubicacion entrega tipo-menu-opcion <?php echo $tipoSel === $tipo ? 'ubicacion-activa' : ''; ?>"
                           data-persona="<?php echo $i; ?>" data-tipo="<?php echo $tipo; ?>">
                        <input type="radio" name="personas[<?php echo $i; ?>][tipo_menu]"
                               value="<?php echo $tipo; ?>"
                               class="radio-tipo-menu" data-persona="<?php echo $i; ?>"
                               <?php echo $tipoSel === $tipo ? 'checked' : ''; ?>
                               <?php echo $i >= $cantidadPersonas ? 'disabled' : ''; ?>>
                        <strong>Menú <?php echo $tipo; ?></strong>
                        <span style="font-size:13px;opacity:.75;"> · $<?php echo number_format($precio, 0); ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>

                <!-- Platos fuertes (filtrados por tipo) -->
                <?php foreach (["Zabisu", "Ejecutivo"] as $tipo): ?>
                <div class="grupo-categoria platos-grupo"
                     id="platos-<?php echo $i; ?>-<?php echo $tipo; ?>"
                     style="<?php echo $tipoSel !== $tipo ? 'display:none;' : ''; ?>margin-top:14px;">
                    <h3>🍽 Guisado</h3>
                    <?php if (!empty($platosPorTipo[$tipo])): ?>
                        <?php foreach ($platosPorTipo[$tipo] as $prod): ?>
                        <label class="opcion-producto <?php echo $prod["agotado"] ? "opcion-agotada" : ""; ?> <?php echo $platoSel === (int)$prod["id_producto"] ? "opcion-seleccionada" : ""; ?>">
                            <input type="radio"
                                   name="personas[<?php echo $i; ?>][plato_fuerte]"
                                   value="<?php echo (int)$prod["id_producto"]; ?>"
                                   class="radio-plato"
                                   data-persona="<?php echo $i; ?>"
                                   <?php echo $platoSel === (int)$prod["id_producto"] ? 'checked' : ''; ?>
                                   <?php echo $prod["agotado"] ? 'disabled' : ''; ?>
                                   <?php echo $i >= $cantidadPersonas ? 'disabled' : ''; ?>>
                            <span><?php echo htmlspecialchars($prod["nombre"]); ?></span>
                            <?php if ($prod["agotado"]): ?>
                                <small class="badge-agotado">Agotado</small>
                            <?php endif; ?>
                        </label>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="nota-formulario">Sin opciones para este tipo de menú.</p>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>

            </div>
            <?php endfor; ?>

            <!-- Total y envío -->
            <div class="bloque-formulario resumen-total">
                <div class="ticket-subtotal" style="font-size:18px;">
                    <span>Total estimado</span>
                    <strong id="total-bigoton">$0.00</strong>
                </div>
                <p class="nota-formulario" style="margin-top:8px;">Pago en efectivo al recibir.</p>
                <button type="submit" name="ordenar" value="1" class="btn-principal" style="margin-top:20px;width:100%;">
                    Enviar pedido
                </button>
            </div>

        </form>
    <?php endif; ?>

</div>

<script>
(function () {
    var cantidadSel = document.getElementById("cantidad_personas");
    var precios     = <?php echo $preciosMenusJSON; ?>;

    /* ── Mostrar/ocultar bloques de persona ── */
    function actualizarPersonas(cantidad) {
        for (var i = 0; i < 15; i++) {
            var bloque = document.getElementById("persona-" + i);
            if (!bloque) continue;
            var visible = i < cantidad;
            bloque.style.display = visible ? "" : "none";
            bloque.querySelectorAll("input, select").forEach(function (el) {
                el.disabled = !visible;
            });
        }
        actualizarTotal();
    }

    /* ── Mostrar/ocultar platos por tipo de menú ── */
    function actualizarPlatos(personaIdx, tipoSeleccionado) {
        ["Zabisu", "Ejecutivo"].forEach(function (tipo) {
            var grupo = document.getElementById("platos-" + personaIdx + "-" + tipo);
            if (!grupo) return;
            var visible = tipo === tipoSeleccionado;
            grupo.style.display = visible ? "" : "none";
            grupo.querySelectorAll("input[type=radio]").forEach(function (r) {
                r.disabled = !visible;
                if (!visible) r.checked = false;
            });
        });
        actualizarTotal();
    }

    /* ── Calcular total ── */
    function actualizarTotal() {
        var cantidad = parseInt(cantidadSel.value) || 1;
        var total    = 0;
        for (var i = 0; i < cantidad; i++) {
            var radios = document.querySelectorAll("input[name='personas[" + i + "][tipo_menu]']");
            radios.forEach(function (r) {
                if (r.checked) total += precios[r.value] || 0;
            });
        }
        var el = document.getElementById("total-bigoton");
        if (el) el.textContent = "$" + total.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ",");
    }

    /* ── Activar clases visuales en opciones seleccionadas ── */
    document.querySelectorAll(".radio-plato").forEach(function (radio) {
        radio.addEventListener("change", function () {
            var persona = this.dataset.persona;
            document.querySelectorAll("input[name='personas[" + persona + "][plato_fuerte]']").forEach(function (r) {
                var label = r.closest(".opcion-producto");
                if (label) label.classList.toggle("opcion-seleccionada", r.checked);
            });
            actualizarTotal();
        });
    });

    /* ── Cambio de tipo de menú ── */
    document.querySelectorAll(".radio-tipo-menu").forEach(function (radio) {
        radio.addEventListener("change", function () {
            var persona = this.dataset.persona;
            /* Actualizar clases visuales */
            document.querySelectorAll(".tipo-menu-opcion[data-persona='" + persona + "']").forEach(function (lbl) {
                lbl.classList.toggle("ubicacion-activa", lbl.dataset.tipo === radio.value);
            });
            actualizarPlatos(parseInt(persona), radio.value);
        });
    });

    /* ── Cambio de cantidad ── */
    cantidadSel.addEventListener("change", function () {
        actualizarPersonas(parseInt(this.value) || 1);
    });

    /* ── Inicializar ── */
    actualizarPersonas(parseInt(cantidadSel.value) || 1);
    for (var i = 0; i < 15; i++) {
        var checked = document.querySelector("input[name='personas[" + i + "][tipo_menu]']:checked");
        if (checked) actualizarPlatos(i, checked.value);
    }
    actualizarTotal();
})();
</script>

</body>
</html>
