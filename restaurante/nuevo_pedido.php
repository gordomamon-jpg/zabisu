<?php
require_once "../config/db.php";
require_once "auth_check.php";

/* ── Menú más reciente (sin restricción de ventana de pedidos) ── */
$stmtMenu = $conexion->prepare("SELECT * FROM menu_dia ORDER BY fecha DESC LIMIT 1");
$stmtMenu->execute();
$menuActivo = $stmtMenu->fetch(PDO::FETCH_ASSOC);

/* ── Productos del menú ── */
$productosPorCategoria = [];
$productosIndexados    = [];

if ($menuActivo) {
    $stmtProd = $conexion->prepare(
        "SELECT id_producto, nombre, categoria
         FROM productos
         WHERE id_menu = :id_menu AND disponible = 1
         ORDER BY nombre ASC"
    );
    $stmtProd->execute([":id_menu" => $menuActivo["id_menu"]]);
    foreach ($stmtProd->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $productosPorCategoria[$p["categoria"]][] = $p;
        $productosIndexados[$p["id_producto"]]    = $p;
    }
}

/* ── Tipos de menú con precio ── */
$stmtTipos = $conexion->prepare("SELECT nombre_menu, precio FROM tipos_menu WHERE activo = 1 ORDER BY precio ASC");
$stmtTipos->execute();
$tiposMenu = $stmtTipos->fetchAll(PDO::FETCH_ASSOC);
$preciosPorTipo = [];
foreach ($tiposMenu as $t) {
    $preciosPorTipo[$t["nombre_menu"]] = (float)$t["precio"];
}

/* ── Horarios con ubicación ── */
$stmtHorarios = $conexion->prepare(
    "SELECT h.id_horario, h.hora_entrega, u.nombre_ubicacion
     FROM horarios_ubicacion h
     INNER JOIN ubicaciones u ON h.id_ubicacion = u.id_ubicacion
     ORDER BY u.nombre_ubicacion ASC, h.hora_entrega ASC"
);
$stmtHorarios->execute();
$horarios = $stmtHorarios->fetchAll(PDO::FETCH_ASSOC);

/* ── Procesar POST ── */
$errores      = [];
$exito        = false;
$folioCreado  = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $nombre_cliente = trim($_POST["nombre_cliente"] ?? "");
    $telefono       = trim($_POST["telefono"]       ?? "");
    $correo_cliente = trim($_POST["correo_cliente"] ?? "");
    $id_horario     = (int)($_POST["id_horario"]    ?? 0);
    $metodo_pago    = trim($_POST["metodo_pago"]    ?? "");
    $observaciones  = trim($_POST["observaciones"]  ?? "");
    $menus_raw      = $_POST["menus"]               ?? [];

    if ($nombre_cliente === "") $errores[] = "El nombre del cliente es obligatorio.";
    if ($telefono === "")       $errores[] = "El teléfono es obligatorio.";
    if ($id_horario === 0)      $errores[] = "Selecciona una ubicación y horario.";
    if ($metodo_pago === "")    $errores[] = "Selecciona el método de pago.";
    if (empty($menus_raw))      $errores[] = "Agrega al menos un menú.";

    if (empty($errores)) {
        try {
            $conexion->beginTransaction();

            $folio        = "ZAB-" . date("Ymd") . "-" . strtoupper(substr(md5(uniqid()), 0, 5));
            $estado_pago  = match($metodo_pago) {
                "Efectivo"     => "Pago en efectivo",
                "Transferencia"=> "Pagado",
                default        => "Pendiente de pago",
            };

            /* Calcular total */
            $total = 0.0;
            foreach ($menus_raw as $m) {
                $tipo = $m["tipo_menu"] ?? "";
                $total += $preciosPorTipo[$tipo] ?? 0;
            }

            $stmtPedido = $conexion->prepare(
                "INSERT INTO pedidos
                 (folio, nombre_cliente, telefono, correo_cliente, id_horario, metodo_pago,
                  observaciones, total, estado, estado_pago, es_prueba, referencia_pago)
                 VALUES
                 (:folio, :nombre_cliente, :telefono, :correo_cliente, :id_horario, :metodo_pago,
                  :observaciones, :total, 'Pendiente', :estado_pago, 0, :folio2)"
            );
            $stmtPedido->execute([
                ":folio"          => $folio,
                ":nombre_cliente" => $nombre_cliente,
                ":telefono"       => $telefono,
                ":correo_cliente" => $correo_cliente,
                ":id_horario"     => $id_horario,
                ":metodo_pago"    => $metodo_pago,
                ":observaciones"  => $observaciones,
                ":total"          => $total,
                ":estado_pago"    => $estado_pago,
                ":folio2"         => $folio,
            ]);
            $id_pedido = (int)$conexion->lastInsertId();

            $stmtPM = $conexion->prepare(
                "INSERT INTO pedido_menus (id_pedido, tipo_menu, numero_menu)
                 VALUES (:id_pedido, :tipo_menu, :numero_menu)"
            );
            $stmtDet = $conexion->prepare(
                "INSERT INTO detalle_pedido (id_pedido_menu, id_producto, categoria, nombre_producto)
                 VALUES (:id_pedido_menu, :id_producto, :categoria, :nombre_producto)"
            );

            $numeroMenu = 1;
            foreach ($menus_raw as $m) {
                $tipo_menu = $m["tipo_menu"] ?? "";
                $stmtPM->execute([
                    ":id_pedido"   => $id_pedido,
                    ":tipo_menu"   => $tipo_menu,
                    ":numero_menu" => $numeroMenu,
                ]);
                $id_pedido_menu = (int)$conexion->lastInsertId();

                $ids = array_filter([
                    $m["plato_fuerte"] ?? "",
                    $m["sopa"]         ?? "",
                    $m["agua"]         ?? "",
                    $m["postre"]       ?? "",
                ]);
                foreach (($m["complementos"] ?? []) as $c) {
                    $ids[] = $c;
                }

                foreach ($ids as $idProd) {
                    $idProd = (int)$idProd;
                    if (!$idProd || !isset($productosIndexados[$idProd])) continue;
                    $stmtDet->execute([
                        ":id_pedido_menu"  => $id_pedido_menu,
                        ":id_producto"     => $idProd,
                        ":categoria"       => $productosIndexados[$idProd]["categoria"],
                        ":nombre_producto" => $productosIndexados[$idProd]["nombre"],
                    ]);
                }

                $numeroMenu++;
            }

            $conexion->commit();
            $exito       = true;
            $folioCreado = $folio;

        } catch (Exception $e) {
            $conexion->rollBack();
            $errores[] = "Error al guardar el pedido: " . $e->getMessage();
        }
    }
}

/* ── JSON para JS ── */
$jsonProductos  = json_encode($productosPorCategoria, JSON_UNESCAPED_UNICODE);
$jsonPrecios    = json_encode($preciosPorTipo,         JSON_UNESCAPED_UNICODE);
$jsonTiposMenu  = json_encode(array_column($tiposMenu, "nombre_menu"), JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nuevo pedido | Restaurante Zabisu</title>
    <link rel="icon" type="image/png" href="../assets/img/LOGO_NARA.png">
    <link rel="stylesheet" href="../assets/css/styles.css">
    <style>
        .menu-bloque {
            border: 1px solid rgba(255,122,0,0.2);
            border-radius: 10px;
            padding: 18px;
            margin-bottom: 14px;
            background: rgba(255,122,0,0.03);
        }
        .menu-bloque__header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 14px;
        }
        .menu-bloque__titulo {
            font-weight: 700;
            color: var(--naranja);
            font-size: 15px;
        }
        .btn-quitar-menu {
            background: rgba(255,60,60,0.1);
            border: 1px solid rgba(255,60,60,0.3);
            color: #ff4444;
            border-radius: 6px;
            padding: 4px 12px;
            font-size: 13px;
            cursor: pointer;
        }
        .btn-agregar-menu {
            background: rgba(255,122,0,0.1);
            border: 1px solid rgba(255,122,0,0.35);
            color: var(--naranja);
            border-radius: 8px;
            padding: 10px 20px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
            margin-top: 4px;
        }
        .campo-grupo { margin-bottom: 14px; }
        .campo-grupo label { display: block; font-size: 13px; color: #aaa; margin-bottom: 5px; font-weight: 600; }
        .campo-grupo select,
        .campo-grupo input[type="text"],
        .campo-grupo input[type="tel"],
        .campo-grupo input[type="email"],
        .campo-grupo textarea {
            width: 100%;
            background: #1a1a1f;
            border: 1px solid rgba(255,255,255,0.12);
            border-radius: 8px;
            color: #fff;
            padding: 10px 12px;
            font-size: 14px;
            box-sizing: border-box;
        }
        .campo-grupo textarea { resize: vertical; min-height: 70px; }
        .productos-opciones { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 6px; }
        .opcion-prod {
            display: flex;
            align-items: center;
            gap: 6px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 7px;
            padding: 6px 12px;
            cursor: pointer;
            font-size: 13px;
        }
        .opcion-prod:has(input:checked) {
            background: rgba(255,122,0,0.12);
            border-color: rgba(255,122,0,0.45);
            color: var(--naranja);
        }
        .total-resumen {
            font-size: 22px;
            font-weight: 800;
            color: var(--naranja);
            margin: 4px 0 0;
        }
        .exito-bloque {
            background: rgba(74,200,110,0.08);
            border: 1px solid rgba(74,200,110,0.35);
            border-radius: 10px;
            padding: 20px 22px;
            margin-bottom: 20px;
        }
        .exito-bloque__folio {
            font-size: 20px;
            font-weight: 800;
            color: #4ac86e;
            margin: 6px 0 0;
        }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        @media (max-width: 540px) { .grid-2 { grid-template-columns: 1fr; } }
    </style>
</head>
<body>

<div class="contenedor">

    <div class="hero-zabisu">
        <div class="hero-zabisu__glow"></div>
        <div class="hero-zabisu__contenido">
            <p class="hero-zabisu__eyebrow">RESTAURANTE</p>
            <h1 class="hero-zabisu__titulo">Nuevo pedido</h1>
            <p class="hero-zabisu__texto">Registra un pedido recibido por otro medio.</p>
            <a href="panel_general.php" class="btn-volver-panel">← Panel general</a>
        </div>
    </div>

    <?php if ($exito): ?>
        <div class="exito-bloque">
            <p style="color:#4ac86e;font-weight:700;margin:0;">✅ Pedido registrado correctamente</p>
            <p class="exito-bloque__folio"><?php echo htmlspecialchars($folioCreado); ?></p>
            <p style="margin:10px 0 14px;color:#aaa;font-size:14px;">El pedido ya aparece en el panel de pedidos.</p>
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <a href="pedidos.php" class="btn-principal" style="text-decoration:none;display:inline-block;">Ver pedidos</a>
                <a href="nuevo_pedido.php" class="btn-volver-panel" style="text-decoration:none;display:inline-block;">+ Otro pedido</a>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($errores)): ?>
        <div class="mensaje-error" style="margin-bottom:18px;">
            <ul style="margin:0;padding-left:18px;">
                <?php foreach ($errores as $e): ?>
                    <li><?php echo htmlspecialchars($e); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if (!$menuActivo): ?>
        <div class="bloque-formulario">
            <p class="nota-formulario" style="color:#ff7a00;">No hay ningún menú registrado. Crea un menú primero para poder asignar productos al pedido.</p>
        </div>
    <?php endif; ?>

    <form method="POST" action="" id="form-nuevo-pedido">

        <!-- ── DATOS DEL CLIENTE ── -->
        <div class="bloque-formulario">
            <h2>Datos del cliente</h2>
            <div class="grid-2">
                <div class="campo-grupo">
                    <label for="nombre_cliente">Nombre *</label>
                    <input type="text" name="nombre_cliente" id="nombre_cliente"
                           value="<?php echo htmlspecialchars($_POST["nombre_cliente"] ?? ""); ?>"
                           placeholder="Nombre completo" autocomplete="off">
                </div>
                <div class="campo-grupo">
                    <label for="telefono">Teléfono *</label>
                    <input type="tel" name="telefono" id="telefono"
                           value="<?php echo htmlspecialchars($_POST["telefono"] ?? ""); ?>"
                           placeholder="10 dígitos" autocomplete="off">
                </div>
            </div>
            <div class="campo-grupo">
                <label for="correo_cliente">Correo electrónico <span style="color:#666;">(opcional)</span></label>
                <input type="email" name="correo_cliente" id="correo_cliente"
                       value="<?php echo htmlspecialchars($_POST["correo_cliente"] ?? ""); ?>"
                       placeholder="correo@ejemplo.com" autocomplete="off">
            </div>
        </div>

        <!-- ── UBICACIÓN Y HORARIO ── -->
        <div class="bloque-formulario">
            <h2>Ubicación y horario</h2>
            <div class="campo-grupo">
                <label for="id_horario">Punto de entrega y horario *</label>
                <select name="id_horario" id="id_horario">
                    <option value="">Selecciona una opción</option>
                    <?php foreach ($horarios as $h): ?>
                        <option value="<?php echo (int)$h["id_horario"]; ?>"
                            <?php echo ((int)($_POST["id_horario"] ?? 0) === (int)$h["id_horario"]) ? "selected" : ""; ?>>
                            <?php echo htmlspecialchars($h["nombre_ubicacion"]); ?> —
                            <?php echo date("g:i A", strtotime($h["hora_entrega"])); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <!-- ── MENÚS ── -->
        <div class="bloque-formulario">
            <h2>Menús pedidos
                <?php if ($menuActivo): ?>
                    <span style="font-size:12px;font-weight:400;color:#666;margin-left:8px;">
                        (menú del <?php echo date("d/m/Y", strtotime($menuActivo["fecha"])); ?>)
                    </span>
                <?php endif; ?>
            </h2>

            <div id="contenedor-menus"></div>

            <button type="button" class="btn-agregar-menu" id="btn-agregar-menu">
                + Agregar otro menú
            </button>
        </div>

        <!-- ── PAGO ── -->
        <div class="bloque-formulario">
            <h2>Método de pago</h2>
            <div class="campo-grupo">
                <label>Método *</label>
                <div class="productos-opciones">
                    <label class="opcion-prod">
                        <input type="radio" name="metodo_pago" value="Efectivo"
                            <?php echo (($_POST["metodo_pago"] ?? "") === "Efectivo") ? "checked" : ""; ?>>
                        Efectivo
                    </label>
                    <label class="opcion-prod">
                        <input type="radio" name="metodo_pago" value="Transferencia"
                            <?php echo (($_POST["metodo_pago"] ?? "") === "Transferencia") ? "checked" : ""; ?>>
                        Transferencia
                    </label>
                </div>
            </div>
            <div class="campo-grupo">
                <label for="observaciones">Observaciones <span style="color:#666;">(opcional)</span></label>
                <textarea name="observaciones" id="observaciones"
                          placeholder="Notas especiales, alergias, etc."><?php echo htmlspecialchars($_POST["observaciones"] ?? ""); ?></textarea>
            </div>
        </div>

        <!-- ── TOTAL ── -->
        <div class="bloque-formulario">
            <h2>Total</h2>
            <p class="total-resumen" id="total-display">$0.00</p>
            <p style="font-size:13px;color:#666;margin-top:6px;">Se calcula automáticamente según los tipos de menú seleccionados.</p>
            <button type="submit" class="btn-principal" style="margin-top:18px;width:100%;">
                Guardar pedido
            </button>
        </div>

    </form>

</div>

<script>
(function () {
    const PRODUCTOS   = <?php echo $jsonProductos; ?>;
    const PRECIOS     = <?php echo $jsonPrecios; ?>;
    const TIPOS_MENU  = <?php echo $jsonTiposMenu; ?>;

    const CATEGORIAS_RADIO = ["Plato fuerte", "Sopa", "Agua", "Postre"];
    const CATEGORIA_CHECK  = "Complemento";

    let contadorMenus = 0;

    function crearBloqueMenu(index) {
        const div = document.createElement("div");
        div.className = "menu-bloque";
        div.dataset.index = index;

        /* Header */
        const header = document.createElement("div");
        header.className = "menu-bloque__header";
        header.innerHTML = `<span class="menu-bloque__titulo">Menú ${index + 1}</span>`;

        if (index > 0) {
            const btnQuitar = document.createElement("button");
            btnQuitar.type = "button";
            btnQuitar.className = "btn-quitar-menu";
            btnQuitar.textContent = "Quitar";
            btnQuitar.addEventListener("click", function () {
                div.remove();
                recalcularNumeros();
                calcularTotal();
            });
            header.appendChild(btnQuitar);
        }
        div.appendChild(header);

        /* Tipo de menú */
        const campoTipo = document.createElement("div");
        campoTipo.className = "campo-grupo";
        campoTipo.innerHTML = `<label>Tipo de menú *</label>`;
        const selectTipo = document.createElement("select");
        selectTipo.name  = `menus[${index}][tipo_menu]`;
        selectTipo.innerHTML = `<option value="">Selecciona tipo</option>`;
        TIPOS_MENU.forEach(function (tipo) {
            const opt = document.createElement("option");
            opt.value       = tipo;
            opt.textContent = tipo + (PRECIOS[tipo] ? " — $" + PRECIOS[tipo].toFixed(2) : "");
            selectTipo.appendChild(opt);
        });
        selectTipo.addEventListener("change", calcularTotal);
        campoTipo.appendChild(selectTipo);
        div.appendChild(campoTipo);

        /* Productos por categoría */
        CATEGORIAS_RADIO.forEach(function (cat) {
            const prods = PRODUCTOS[cat] || [];
            if (prods.length === 0) return;

            const campo = document.createElement("div");
            campo.className = "campo-grupo";
            campo.innerHTML = `<label>${cat}</label>`;

            const wrap = document.createElement("div");
            wrap.className = "productos-opciones";

            prods.forEach(function (p) {
                const lbl = document.createElement("label");
                lbl.className = "opcion-prod";
                lbl.innerHTML = `
                    <input type="radio" name="menus[${index}][${camelize(cat)}]" value="${p.id_producto}">
                    ${p.nombre}
                `;
                wrap.appendChild(lbl);
            });

            campo.appendChild(wrap);
            div.appendChild(campo);
        });

        /* Complementos */
        const complementos = PRODUCTOS[CATEGORIA_CHECK] || [];
        if (complementos.length > 0) {
            const campo = document.createElement("div");
            campo.className = "campo-grupo";
            campo.innerHTML = `<label>Complementos <span style="color:#666;">(opcional)</span></label>`;

            const wrap = document.createElement("div");
            wrap.className = "productos-opciones";

            complementos.forEach(function (p) {
                const lbl = document.createElement("label");
                lbl.className = "opcion-prod";
                lbl.innerHTML = `
                    <input type="checkbox" name="menus[${index}][complementos][]" value="${p.id_producto}">
                    ${p.nombre}
                `;
                wrap.appendChild(lbl);
            });

            campo.appendChild(wrap);
            div.appendChild(campo);
        }

        return div;
    }

    function camelize(cat) {
        const map = {
            "Plato fuerte": "plato_fuerte",
            "Sopa":         "sopa",
            "Agua":         "agua",
            "Postre":       "postre",
        };
        return map[cat] || cat.toLowerCase().replace(/\s+/g, "_");
    }

    function recalcularNumeros() {
        document.querySelectorAll(".menu-bloque").forEach(function (bloque, i) {
            bloque.querySelector(".menu-bloque__titulo").textContent = "Menú " + (i + 1);
        });
    }

    function calcularTotal() {
        let total = 0;
        document.querySelectorAll(".menu-bloque").forEach(function (bloque) {
            const sel = bloque.querySelector("select");
            if (sel && sel.value && PRECIOS[sel.value]) {
                total += PRECIOS[sel.value];
            }
        });
        document.getElementById("total-display").textContent =
            "$" + total.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ",");
    }

    function agregarMenu() {
        const contenedor = document.getElementById("contenedor-menus");
        const bloque = crearBloqueMenu(contadorMenus);
        contenedor.appendChild(bloque);
        contadorMenus++;
    }

    document.getElementById("btn-agregar-menu").addEventListener("click", agregarMenu);

    /* Iniciar con un menú */
    agregarMenu();
})();
</script>

</body>
</html>
