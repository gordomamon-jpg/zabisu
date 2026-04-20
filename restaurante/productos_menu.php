<?php
require_once "../config/db.php";
require_once "auth_check.php";

$id_menu = isset($_GET["id_menu"]) ? (int)$_GET["id_menu"] : 0;

if ($id_menu <= 0) {
    header("Location: menus.php");
    exit;
}

$sqlMenu = "SELECT * FROM menu_dia WHERE id_menu = :id_menu LIMIT 1";
$stmtMenu = $conexion->prepare($sqlMenu);
$stmtMenu->bindParam(":id_menu", $id_menu, PDO::PARAM_INT);
$stmtMenu->execute();
$menu = $stmtMenu->fetch(PDO::FETCH_ASSOC);

if (!$menu) {
    header("Location: menus.php");
    exit;
}

/*
    Cargar precios actuales de tipos_menu
*/
$stmtPrecios = $conexion->prepare("SELECT nombre_menu, precio FROM tipos_menu WHERE activo = 1 ORDER BY nombre_menu");
$stmtPrecios->execute();
$preciosActuales = [];
foreach ($stmtPrecios->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $preciosActuales[$r["nombre_menu"]] = (float)$r["precio"];
}

$errores       = [];
$mensajeExito  = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $productos         = $_POST["productos"] ?? [];
    $activarMenu       = isset($_POST["activar_menu"]) ? 1 : 0;
    $nuevaFecha        = trim($_POST["fecha"] ?? "");
    $nuevoPublicado    = trim($_POST["publicado_desde"] ?? "");
    $nuevoPedidoHasta  = trim($_POST["pedido_hasta"] ?? "");
    $nuevosPrecios     = $_POST["precios"] ?? [];

    // Validar fechas y horarios
    if ($nuevaFecha === "" || !strtotime($nuevaFecha)) {
        $errores[] = "La fecha del menú es obligatoria.";
    }
    if ($nuevoPublicado === "" || !strtotime($nuevoPublicado)) {
        $errores[] = "La fecha y hora de publicación son obligatorias.";
    }
    if ($nuevoPedidoHasta === "" || !strtotime($nuevoPedidoHasta)) {
        $errores[] = "La fecha y hora de cierre de pedidos son obligatorias.";
    }
    if (empty($errores) && strtotime($nuevoPedidoHasta) <= strtotime($nuevoPublicado)) {
        $errores[] = "El cierre de pedidos debe ser posterior a la hora de publicación.";
    }

    // Validar que al menos un producto tenga nombre
    $hayAlguno = false;
    foreach ($productos as $categorias) {
        foreach ($categorias as $items) {
            foreach ($items as $item) {
                if (trim($item["nombre"] ?? "") !== "") {
                    $hayAlguno = true;
                    break 3;
                }
            }
        }
    }

    if (!$hayAlguno) {
        $errores[] = "Debes capturar al menos un producto.";
    }

    if (empty($errores)) {
        try {
            $conexion->beginTransaction();

            // Cargar productos existentes ordenados por posición de inserción
            $stmtEx = $conexion->prepare(
                "SELECT id_producto, tipo_menu, categoria, nombre, descripcion, limite_pedidos
                 FROM productos WHERE id_menu = :id_menu
                 ORDER BY tipo_menu, categoria, id_producto"
            );
            $stmtEx->execute([":id_menu" => $id_menu]);
            $existentesRaw = $stmtEx->fetchAll(PDO::FETCH_ASSOC);

            // Mapa posicional: [tipo_menu][categoria][índice] => fila completa
            $exMap = [];
            foreach ($existentesRaw as $e) {
                $exMap[$e["tipo_menu"]][$e["categoria"]][] = $e;
            }

            // IDs referenciados en detalle_pedido (no se pueden eliminar)
            $stmtRef = $conexion->prepare(
                "SELECT DISTINCT dp.id_producto FROM detalle_pedido dp
                 INNER JOIN productos pr ON dp.id_producto = pr.id_producto
                 WHERE pr.id_menu = :id_menu"
            );
            $stmtRef->execute([":id_menu" => $id_menu]);
            $idsRef = array_flip(array_column($stmtRef->fetchAll(PDO::FETCH_ASSOC), "id_producto"));

            $idsUsados = [];

            $stmtUpdate = $conexion->prepare(
                "UPDATE productos SET nombre=:nombre, descripcion=:descripcion,
                 disponible=:disponible, limite_pedidos=:limite_pedidos,
                 complementos_max=:complementos_max
                 WHERE id_producto=:id"
            );
            $stmtInsert = $conexion->prepare(
                "INSERT INTO productos (id_menu, tipo_menu, categoria, nombre, descripcion, disponible, limite_pedidos, complementos_max)
                 VALUES (:id_menu, :tipo_menu, :categoria, :nombre, :descripcion, 1, :limite_pedidos, :complementos_max)"
            );

            foreach ($productos as $tipo_menu => $categorias) {
                foreach ($categorias as $categoria => $items) {
                    foreach ($items as $i => $item) {
                        $nombre      = trim($item["nombre"]         ?? "");
                        $descripcion = trim($item["descripcion"]    ?? "");
                        $limite      = trim($item["limite_pedidos"] ?? "");
                        $compMax     = trim($item["complementos_max"] ?? "");

                        $limiteFinal  = null;
                        $compMaxFinal = null;
                        if ($categoria === "Plato fuerte") {
                            if ($limite !== "" && is_numeric($limite)) {
                                $limiteFinal = (int)$limite;
                            }
                            if ($compMax !== "" && is_numeric($compMax) && (int)$compMax >= 1) {
                                $compMaxFinal = (int)$compMax;
                            }
                        }

                        $exDato = $exMap[$tipo_menu][$categoria][$i] ?? null;
                        $idEx   = $exDato ? (int)$exDato["id_producto"] : null;

                        if ($idEx !== null) {
                            if ($nombre !== "") {
                                // Actualizar con los nuevos valores
                                $idsUsados[] = $idEx;
                                $stmtUpdate->execute([
                                    ":nombre"          => $nombre,
                                    ":descripcion"     => $descripcion,
                                    ":disponible"      => 1,
                                    ":limite_pedidos"  => $limiteFinal,
                                    ":complementos_max"=> $compMaxFinal,
                                    ":id"              => $idEx,
                                ]);
                            } elseif (isset($idsRef[$idEx])) {
                                // Slot vacío pero referenciado: marcar no disponible con nombre original
                                $idsUsados[] = $idEx;
                                $stmtUpdate->execute([
                                    ":nombre"          => $exDato["nombre"],
                                    ":descripcion"     => $exDato["descripcion"],
                                    ":disponible"      => 0,
                                    ":limite_pedidos"  => $exDato["limite_pedidos"],
                                    ":complementos_max"=> $exDato["complementos_max"] ?? null,
                                    ":id"              => $idEx,
                                ]);
                            }
                            // Si slot vacío y no referenciado: no se agrega a $idsUsados → se elimina abajo
                        } elseif ($nombre !== "") {
                            // Insertar nuevo producto
                            $stmtInsert->execute([
                                ":id_menu"         => $id_menu,
                                ":tipo_menu"       => $tipo_menu,
                                ":categoria"       => $categoria,
                                ":nombre"          => $nombre,
                                ":descripcion"     => $descripcion,
                                ":limite_pedidos"  => $limiteFinal,
                                ":complementos_max"=> $compMaxFinal,
                            ]);
                        }
                    }
                }
            }

            // Eliminar solo los que no se usaron y no están referenciados
            foreach ($existentesRaw as $e) {
                $id = (int)$e["id_producto"];
                if (!in_array($id, $idsUsados) && !isset($idsRef[$id])) {
                    $conexion->prepare("DELETE FROM productos WHERE id_producto = :id")
                             ->execute([":id" => $id]);
                }
            }

            // Actualizar precios de tipos_menu
            $stmtPrecio = $conexion->prepare(
                "UPDATE tipos_menu SET precio = :precio WHERE nombre_menu = :nombre AND activo = 1"
            );
            foreach ($nuevosPrecios as $nombreMenu => $precio) {
                $precioFloat = (float)str_replace(",", ".", $precio);
                if ($precioFloat > 0) {
                    $stmtPrecio->execute([":precio" => $precioFloat, ":nombre" => $nombreMenu]);
                }
            }

            // Refrescar precios para mostrar valores actualizados
            $stmtPrecios2 = $conexion->prepare("SELECT nombre_menu, precio FROM tipos_menu WHERE activo = 1");
            $stmtPrecios2->execute();
            $preciosActuales = [];
            foreach ($stmtPrecios2->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $preciosActuales[$r["nombre_menu"]] = (float)$r["precio"];
            }

            // Actualizar estado y fechas del menú
            $conexion->prepare(
                "UPDATE menu_dia SET activo = :activo, fecha = :fecha,
                 publicado_desde = :publicado_desde, pedido_hasta = :pedido_hasta
                 WHERE id_menu = :id_menu"
            )->execute([
                ":activo"          => $activarMenu,
                ":fecha"           => date("Y-m-d", strtotime($nuevaFecha)),
                ":publicado_desde" => date("Y-m-d H:i:s", strtotime($nuevoPublicado)),
                ":pedido_hasta"    => date("Y-m-d H:i:s", strtotime($nuevoPedidoHasta)),
                ":id_menu"         => $id_menu,
            ]);

            // Refrescar datos del menú para mostrar valores actualizados
            $stmtRef = $conexion->prepare("SELECT * FROM menu_dia WHERE id_menu = :id_menu LIMIT 1");
            $stmtRef->bindParam(":id_menu", $id_menu, PDO::PARAM_INT);
            $stmtRef->execute();
            $menu = $stmtRef->fetch(PDO::FETCH_ASSOC);

            $conexion->commit();
            $mensajeExito = "true";

        } catch (Exception $e) {
            $conexion->rollBack();
            $errores[] = "Error al guardar los productos: " . $e->getMessage();
        }
    }
}

/*
    Cargar productos ya guardados (para edición)
*/
$productosGuardados = [];
$sqlGuardados = "SELECT * FROM productos WHERE id_menu = :id_menu ORDER BY tipo_menu, categoria, nombre";
$stmtGuardados = $conexion->prepare($sqlGuardados);
$stmtGuardados->bindParam(":id_menu", $id_menu, PDO::PARAM_INT);
$stmtGuardados->execute();
foreach ($stmtGuardados->fetchAll(PDO::FETCH_ASSOC) as $p) {
    $productosGuardados[$p["tipo_menu"]][$p["categoria"]][] = $p;
}

/*
    Estructura fija del menú
*/
$estructura = [
    "Zabisu"   => ["Plato fuerte" => 2, "Sopa" => 1, "Complemento" => 5, "Agua" => 1, "Postre" => 1],
    "Ejecutivo"=> ["Plato fuerte" => 4, "Sopa" => 1, "Complemento" => 5, "Agua" => 1, "Postre" => 1],
];

$iconosCategoria = [
    "Plato fuerte" => "🍽️",
    "Sopa"         => "🥣",
    "Complemento"  => "🥗",
    "Agua"         => "💧",
    "Postre"       => "🍮",
];

$meses = [
    '01'=>'enero','02'=>'febrero','03'=>'marzo','04'=>'abril',
    '05'=>'mayo','06'=>'junio','07'=>'julio','08'=>'agosto',
    '09'=>'septiembre','10'=>'octubre','11'=>'noviembre','12'=>'diciembre'
];

$fechaTs     = strtotime($menu["fecha"]);
$diasES      = ["Sunday"=>"Domingo","Monday"=>"Lunes","Tuesday"=>"Martes","Wednesday"=>"Miércoles","Thursday"=>"Jueves","Friday"=>"Viernes","Saturday"=>"Sábado"];
$fechaBonita = ($diasES[date("l", $fechaTs)] ?? "") . " " . date("j", $fechaTs) . " de " . ($meses[date("m", $fechaTs)] ?? "") . " de " . date("Y", $fechaTs);

// ¿Es una creación nueva (viene de nuevo_menu) o edición?
$esCreacion = !isset($_GET["editar"]) && empty($productosGuardados);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Productos del menú | Zabisu</title>
    <link rel="icon" type="image/png" href="../assets/img/LOGO_NARA.png">
    <link rel="stylesheet" href="../assets/css/styles.css">
</head>
<body>
<div class="contenedor">

    <div class="hero-zabisu">
        <div class="hero-zabisu__glow"></div>
        <div class="hero-zabisu__contenido">
            <p class="hero-zabisu__eyebrow">RESTAURANTE</p>
            <h1 class="hero-zabisu__titulo">Productos del menú</h1>
            <p class="hero-zabisu__texto"><?php echo htmlspecialchars($fechaBonita); ?></p>
            <a href="panel_general.php" class="btn-volver-panel">← Panel general</a>
        </div>
    </div>

    <?php if ($esCreacion): ?>
    <!-- Indicador de pasos solo en flujo de creación -->
    <div class="nm-pasos">
        <div class="nm-paso nm-paso--completado">
            <span class="nm-paso__circulo">✓</span>
            <span class="nm-paso__etiqueta">Configuración</span>
        </div>
        <div class="nm-pasos__linea nm-pasos__linea--completada"></div>
        <div class="nm-paso nm-paso--activo">
            <span class="nm-paso__circulo">2</span>
            <span class="nm-paso__etiqueta">Productos</span>
        </div>
    </div>
    <?php endif; ?>

    <!-- Info del menú -->
    <div class="nm-info-menu">
        <div class="nm-info-menu__item">
            <span class="nm-info-menu__label">Fecha</span>
            <strong><?php echo htmlspecialchars($fechaBonita); ?></strong>
        </div>
        <div class="nm-info-menu__item">
            <span class="nm-info-menu__label">Publicación</span>
            <strong><?php echo date("d/m H:i", strtotime($menu["publicado_desde"])); ?></strong>
        </div>
        <div class="nm-info-menu__item">
            <span class="nm-info-menu__label">Cierre</span>
            <strong><?php echo date("d/m H:i", strtotime($menu["pedido_hasta"])); ?></strong>
        </div>
        <div class="nm-info-menu__item">
            <span class="nm-info-menu__label">Estado</span>
            <span class="estado <?php echo (int)$menu["activo"] ? 'estado-pagado' : 'estado-pendiente'; ?>">
                <?php echo (int)$menu["activo"] ? "Activo" : "Inactivo"; ?>
            </span>
        </div>
    </div>

    <?php if (!empty($errores)): ?>
        <div class="mensaje-error">
            <ul>
                <?php foreach ($errores as $err): ?>
                    <li><?php echo htmlspecialchars($err); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($mensajeExito): ?>
        <div class="nm-exito">
            <span class="nm-exito__icono">✓</span>
            <div>
                <strong>Productos guardados correctamente</strong>
                <p>El menú del <?php echo htmlspecialchars($fechaBonita); ?> se guardó con todos sus productos.</p>
            </div>
            <div class="nm-exito__acciones">
                <a href="menus.php" class="btn-tabla">Ver todos los menús</a>
                <a href="panel_general.php" class="btn-limpiar-filtros">Panel general</a>
            </div>
        </div>
    <?php endif; ?>

    <form action="" method="POST" id="form-productos">

        <!-- FECHA Y HORARIOS -->
        <div class="bloque-formulario nm-seccion">
            <div class="nm-seccion__header">
                <span class="nm-seccion__icono">📅</span>
                <div>
                    <h2>Fecha y horarios</h2>
                    <p class="nota-formulario" style="margin:0;">Modifica la fecha del menú y el horario de publicación y cierre.</p>
                </div>
            </div>

            <div class="nm-campos-fecha">
                <div class="nm-campo">
                    <label for="campo-fecha">Fecha del menú</label>
                    <input type="date" id="campo-fecha" name="fecha"
                           value="<?php echo htmlspecialchars($_POST["fecha"] ?? date("Y-m-d", strtotime($menu["fecha"]))); ?>">
                </div>
                <div class="nm-campo">
                    <label for="campo-publicado">Publicado desde</label>
                    <input type="datetime-local" id="campo-publicado" name="publicado_desde"
                           value="<?php echo htmlspecialchars($_POST["publicado_desde"] ?? date("Y-m-d\TH:i", strtotime($menu["publicado_desde"]))); ?>">
                </div>
                <div class="nm-campo">
                    <label for="campo-cierre">Pedidos hasta</label>
                    <input type="datetime-local" id="campo-cierre" name="pedido_hasta"
                           value="<?php echo htmlspecialchars($_POST["pedido_hasta"] ?? date("Y-m-d\TH:i", strtotime($menu["pedido_hasta"]))); ?>">
                </div>
            </div>

            <h3 style="font-size:14px;color:var(--texto-secundario);margin:20px 0 10px;font-weight:600;">Precios por tipo de menú</h3>
            <div class="nm-campos-fecha">
                <?php foreach ($preciosActuales as $nombreMenu => $precio): ?>
                <div class="nm-campo">
                    <label>Precio — <?php echo htmlspecialchars($nombreMenu); ?></label>
                    <input type="number" min="1" step="0.50"
                           name="precios[<?php echo htmlspecialchars($nombreMenu); ?>]"
                           value="<?php echo number_format(isset($_POST["precios"][$nombreMenu]) ? (float)$_POST["precios"][$nombreMenu] : $precio, 2, '.', ''); ?>">
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- TABS -->
        <div class="pm-tabs">
            <button type="button" class="pm-tab pm-tab--activo" data-tab="Zabisu">
                Menú Zabisu
                <span class="pm-tab__sub">2 platos fuertes</span>
            </button>
            <button type="button" class="pm-tab" data-tab="Ejecutivo">
                Menú Ejecutivo
                <span class="pm-tab__sub">4 platos fuertes</span>
            </button>
        </div>

        <!-- CONTENIDO POR TAB -->
        <?php foreach ($estructura as $tipoMenu => $categorias): ?>
        <div class="pm-tab-contenido <?php echo $tipoMenu === 'Zabisu' ? 'pm-tab-contenido--activo' : ''; ?>"
             id="tab-<?php echo $tipoMenu; ?>">

            <?php if ($tipoMenu === "Ejecutivo"): ?>
            <div class="pm-copiar-aviso">
                <p>¿Los productos de Sopa, Complementos, Agua y Postre son los mismos que en Zabisu?</p>
                <button type="button" class="btn-limpiar-filtros" id="btn-copiar-zabisu">
                    Copiar desde Menú Zabisu
                </button>
            </div>
            <?php endif; ?>

            <?php foreach ($categorias as $categoria => $cantidad):
                $icono = $iconosCategoria[$categoria] ?? "•";
                $guardadosCategoria = $productosGuardados[$tipoMenu][$categoria] ?? [];
            ?>
            <div class="pm-categoria">
                <div class="pm-categoria__header">
                    <span class="pm-categoria__icono"><?php echo $icono; ?></span>
                    <div class="pm-categoria__info">
                        <h3><?php echo htmlspecialchars($categoria); ?></h3>
                        <span class="pm-categoria__cantidad">
                            <?php echo $cantidad; ?> opción<?php echo $cantidad > 1 ? "es" : ""; ?> disponible<?php echo $cantidad > 1 ? "s" : ""; ?> para elegir
                        </span>
                    </div>
                </div>

                <div class="pm-productos-lista">
                    <?php for ($i = 0; $i < $cantidad; $i++):
                        $guardado    = $guardadosCategoria[$i] ?? null;
                        $nombreVal   = $guardado["nombre"] ?? "";
                        $descVal     = $guardado["descripcion"] ?? "";
                        $limiteVal   = $guardado["limite_pedidos"] ?? "";
                        $compMaxVal  = $guardado["complementos_max"] ?? "";
                        $inputBase = "productos[" . htmlspecialchars($tipoMenu) . "][" . htmlspecialchars($categoria) . "][" . $i . "]";
                    ?>
                    <div class="pm-producto-card">
                        <div class="pm-producto-card__num"><?php echo $i + 1; ?></div>
                        <div class="pm-producto-card__campos">
                            <input type="text"
                                   name="<?php echo $inputBase; ?>[nombre]"
                                   class="pm-input-nombre <?php echo $tipoMenu === 'Zabisu' ? 'zabisu-nombre-' . $i . '-' . urlencode($categoria) : ''; ?>"
                                   placeholder="Nombre<?php echo $categoria === 'Plato fuerte' ? ' del plato' : ($categoria === 'Sopa' ? ' de la sopa' : ($categoria === 'Agua' ? ' del agua' : ($categoria === 'Postre' ? ' del postre' : ' del complemento'))); ?>…"
                                   value="<?php echo htmlspecialchars($nombreVal); ?>">

                            <textarea name="<?php echo $inputBase; ?>[descripcion]"
                                      class="pm-input-desc <?php echo $tipoMenu === 'Zabisu' ? 'zabisu-desc-' . $i . '-' . urlencode($categoria) : ''; ?>"
                                      rows="2"
                                      placeholder="Descripción opcional…"><?php echo htmlspecialchars($descVal); ?></textarea>

                            <?php if ($categoria === "Plato fuerte"): ?>
                            <div class="pm-limite">
                                <label>Límite de pedidos</label>
                                <input type="number" min="1"
                                       name="<?php echo $inputBase; ?>[limite_pedidos]"
                                       placeholder="Sin límite"
                                       value="<?php echo htmlspecialchars($limiteVal); ?>">
                                <span class="nm-campo__ayuda">Deja vacío si no hay límite para este plato</span>
                            </div>
                            <div class="pm-limite">
                                <label>Máx. complementos</label>
                                <input type="number" min="1" max="5"
                                       name="<?php echo $inputBase; ?>[complementos_max]"
                                       placeholder="Sin límite (usa el máx. del menú)"
                                       value="<?php echo htmlspecialchars($compMaxVal); ?>">
                                <span class="nm-campo__ayuda">Deja vacío para permitir hasta 2. Pon 1 si este plato solo incluye un complemento.</span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endfor; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>

        <!-- GUARDAR -->
        <div class="bloque-formulario nm-seccion">
            <div class="nm-seccion__header">
                <span class="nm-seccion__icono">💾</span>
                <div>
                    <h2>Guardar menú</h2>
                    <p class="nota-formulario" style="margin:0;">Los productos vacíos se omitirán automáticamente al guardar.</p>
                </div>
            </div>

            <label class="pm-activar-label">
                <input type="checkbox" name="activar_menu" value="1"
                       <?php echo (int)$menu["activo"] ? "checked" : ""; ?>>
                <span>Activar menú al guardar</span>
                <span class="nm-campo__ayuda">Los clientes podrán ver y pedir este menú una vez que esté activo y dentro del horario de publicación.</span>
            </label>

            <div class="nm-acciones" style="margin-top:20px;">
                <button type="submit" class="btn-principal">Guardar productos</button>
                <a href="menus.php" class="btn-link">Cancelar</a>
            </div>
        </div>

    </form>
</div>

<script>
document.addEventListener("DOMContentLoaded", function () {

    // ── TABS ──────────────────────────────────────────────────
    document.querySelectorAll(".pm-tab").forEach(function (tab) {
        tab.addEventListener("click", function () {
            document.querySelectorAll(".pm-tab").forEach(function (t) {
                t.classList.remove("pm-tab--activo");
            });
            document.querySelectorAll(".pm-tab-contenido").forEach(function (c) {
                c.classList.remove("pm-tab-contenido--activo");
            });

            this.classList.add("pm-tab--activo");
            var contenido = document.getElementById("tab-" + this.dataset.tab);
            if (contenido) contenido.classList.add("pm-tab-contenido--activo");
        });
    });

    // ── COPIAR ZABISU → EJECUTIVO ─────────────────────────────
    var btnCopiar = document.getElementById("btn-copiar-zabisu");
    if (btnCopiar) {
        btnCopiar.addEventListener("click", function () {

            var categoriasCopiar = ["Sopa", "Complemento", "Agua", "Postre"];

            var tabZabisu   = document.getElementById("tab-Zabisu");
            var tabEjecutivo = document.getElementById("tab-Ejecutivo");

            var inputsZabisu    = Array.from(tabZabisu.querySelectorAll("input[type='text']"));
            var textareasZabisu = Array.from(tabZabisu.querySelectorAll("textarea"));
            var inputsEjec      = Array.from(tabEjecutivo.querySelectorAll("input[type='text']"));
            var textareasEjec   = Array.from(tabEjecutivo.querySelectorAll("textarea"));

            categoriasCopiar.forEach(function (cat) {
                var marcador = "[" + cat + "]";

                var zNombres = inputsZabisu.filter(function (el) { return el.name.indexOf(marcador) !== -1; });
                var zDescs   = textareasZabisu.filter(function (el) { return el.name.indexOf(marcador) !== -1; });
                var eNombres = inputsEjec.filter(function (el) { return el.name.indexOf(marcador) !== -1; });
                var eDescs   = textareasEjec.filter(function (el) { return el.name.indexOf(marcador) !== -1; });

                zNombres.forEach(function (input, idx) {
                    if (eNombres[idx]) eNombres[idx].value = input.value;
                });
                zDescs.forEach(function (textarea, idx) {
                    if (eDescs[idx]) eDescs[idx].value = textarea.value;
                });
            });

            // Cambiar al tab Ejecutivo para que el usuario vea el resultado
            document.querySelector(".pm-tab[data-tab='Ejecutivo']").click();
        });
    }

});
</script>

</body>
</html>
