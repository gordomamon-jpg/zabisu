<?php
require_once "../config/db.php";
session_start();

if (empty($_SESSION["pedido_temporal"])) {
    die("No hay un pedido temporal disponible.");
}

$pedidoTemporal = $_SESSION["pedido_temporal"];

/*  Modo prueba  */
$stmtMP = $conexion->prepare("SELECT valor FROM configuracion WHERE clave = 'modo_prueba' LIMIT 1");
$stmtMP->execute();
$esPrueba = (int)($stmtMP->fetchColumn() ?? 0);

$nombre_cliente = $pedidoTemporal["nombre_cliente"] ?? "";
$telefono = $pedidoTemporal["telefono"] ?? "";
$correo_cliente = $pedidoTemporal["correo_cliente"] ?? "";
$observaciones = $pedidoTemporal["observaciones"] ?? "";
$id_horario = $pedidoTemporal["id_horario"] ?? "";
$menusRecibidos = $pedidoTemporal["menus"] ?? [];
$totalPedido    = (float)($pedidoTemporal["total"] ?? 0);
$extrasSession  = $pedidoTemporal["extras"] ?? [];

/*
    Obtener productos para reconstruir nombres/categorías
*/
$sqlProductos = "SELECT * FROM productos WHERE disponible = 1";
$stmtProductos = $conexion->prepare($sqlProductos);
$stmtProductos->execute();
$productos = $stmtProductos->fetchAll(PDO::FETCH_ASSOC);

$productosIndexados = [];
foreach ($productos as $producto) {
    $productosIndexados[$producto["id_producto"]] = $producto;
}

/*
    Obtener precios de tipos de menú
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
    Obtener datos de ubicación y horario del pedido temporal
*/
$sqlHorario = "SELECT
                  h.hora_entrega,
                  u.nombre_ubicacion,
                  u.tipo
               FROM horarios_ubicacion h
               INNER JOIN ubicaciones u ON h.id_ubicacion = u.id_ubicacion
               WHERE h.id_horario = :id_horario
               LIMIT 1";
$stmtHorario = $conexion->prepare($sqlHorario);
$stmtHorario->bindParam(":id_horario", $id_horario, PDO::PARAM_INT);
$stmtHorario->execute();
$datosHorario = $stmtHorario->fetch(PDO::FETCH_ASSOC);


$errores = [];
$mensajeExito = "";

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["finalizar_pedido"])) {
    $metodo_pago = trim($_POST["metodo_pago"] ?? "");

    if ($metodo_pago === "") {
        $errores[] = "Debes seleccionar un método de pago.";
    }

    if ($metodo_pago === "Transferencia") {
        if (!isset($_FILES["comprobante_pago"]) || $_FILES["comprobante_pago"]["error"] !== 0) {
            $errores[] = "Debes subir el comprobante de pago.";
        }
    }

    if (empty($errores)) {
        try {
            $conexion->beginTransaction();

            $folio = "ZAB-" . date("Ymd") . "-" . strtoupper(substr(md5(uniqid()), 0, 5));
            $referencia_pago = $folio;

            $estado_pago = "Pendiente de pago";
            $comprobante_pago = null;
            $fecha_pago = null;

            if ($metodo_pago === "Transferencia") {
                $nombreOriginal = basename($_FILES["comprobante_pago"]["name"]);
                $extension = pathinfo($nombreOriginal, PATHINFO_EXTENSION);
                $nombreArchivo = time() . "_" . preg_replace('/[^A-Za-z0-9_\-]/', '_', pathinfo($nombreOriginal, PATHINFO_FILENAME)) . "." . $extension;

                $carpetaDestino = __DIR__ . "/../uploads/comprobantes/";

                if (!is_dir($carpetaDestino)) {
                    if (!mkdir($carpetaDestino, 0777, true)) {
                        throw new Exception("No se pudo crear la carpeta de comprobantes.");
                    }
                }

                $rutaDestino = $carpetaDestino . $nombreArchivo;

                if (!move_uploaded_file($_FILES["comprobante_pago"]["tmp_name"], $rutaDestino)) {
                    throw new Exception("No se pudo guardar el comprobante de pago.");
                }

                $estado_pago = "Pendiente de validación";
                $comprobante_pago = $nombreArchivo;
                $fecha_pago = date("Y-m-d H:i:s");
            }

            if ($metodo_pago === "Efectivo") {
                $estado_pago = "Pago en efectivo";
            }

            /*
                Insertar pedido general
            */
            $sqlPedido = "INSERT INTO pedidos
              (folio, nombre_cliente, telefono, correo_cliente, id_horario, metodo_pago, observaciones, total, estado, id_repartidor, estado_pago, comprobante_pago, referencia_pago, fecha_pago, es_prueba)
              VALUES
              (:folio, :nombre_cliente, :telefono, :correo_cliente, :id_horario, :metodo_pago, :observaciones, :total, 'Pendiente', NULL, :estado_pago, :comprobante_pago, :referencia_pago, :fecha_pago, :es_prueba)";
            $stmtPedido = $conexion->prepare($sqlPedido);
            $stmtPedido->bindParam(":folio", $folio);
            $stmtPedido->bindParam(":nombre_cliente", $nombre_cliente);
            $stmtPedido->bindParam(":telefono", $telefono);
            $stmtPedido->bindParam(":correo_cliente", $correo_cliente);
            $stmtPedido->bindParam(":id_horario", $id_horario, PDO::PARAM_INT);
            $stmtPedido->bindParam(":metodo_pago", $metodo_pago);
            $stmtPedido->bindParam(":observaciones", $observaciones);
            $stmtPedido->bindParam(":total", $totalPedido);
            $stmtPedido->bindParam(":estado_pago", $estado_pago);
            $stmtPedido->bindParam(":comprobante_pago", $comprobante_pago);
            $stmtPedido->bindParam(":referencia_pago", $referencia_pago);
            $stmtPedido->bindParam(":fecha_pago", $fecha_pago);
            $stmtPedido->bindParam(":es_prueba", $esPrueba, PDO::PARAM_INT);
            $stmtPedido->execute();

            $id_pedido = $conexion->lastInsertId();

            /*
                Preparar inserciones para menús
            */
            $sqlPedidoMenu = "INSERT INTO pedido_menus
                              (id_pedido, tipo_menu, numero_menu)
                              VALUES
                              (:id_pedido, :tipo_menu, :numero_menu)";
            $stmtPedidoMenu = $conexion->prepare($sqlPedidoMenu);

            $sqlDetalle = "INSERT INTO detalle_pedido
                           (id_pedido_menu, id_producto, categoria, nombre_producto)
                           VALUES
                           (:id_pedido_menu, :id_producto, :categoria, :nombre_producto)";
            $stmtDetalle = $conexion->prepare($sqlDetalle);

            foreach ($menusRecibidos as $numeroMenu => $menu) {
                $tipo_menu = $menu["tipo_menu"] ?? "";
                $plato_fuerte = $menu["plato_fuerte"] ?? "";
                $sopa = $menu["sopa"] ?? "";
                $agua = $menu["agua"] ?? "";
                $postre = $menu["postre"] ?? "";
                $complementos = $menu["complementos"] ?? [];

                $stmtPedidoMenu->bindParam(":id_pedido", $id_pedido, PDO::PARAM_INT);
                $stmtPedidoMenu->bindParam(":tipo_menu", $tipo_menu);
                $stmtPedidoMenu->bindParam(":numero_menu", $numeroMenu, PDO::PARAM_INT);
                $stmtPedidoMenu->execute();

                $id_pedido_menu = $conexion->lastInsertId();

                $productosSeleccionados = array_merge(
                    [$plato_fuerte],
                    [$sopa],
                    $complementos,
                    [$agua],
                    [$postre]
                );

                foreach ($productosSeleccionados as $idProducto) {
                    if (!isset($productosIndexados[$idProducto])) {
                        continue;
                    }

                    $productoInfo = $productosIndexados[$idProducto];
                    $categoria = $productoInfo["categoria"];
                    $nombre_producto = $productoInfo["nombre"];

                    $stmtDetalle->bindParam(":id_pedido_menu", $id_pedido_menu, PDO::PARAM_INT);
                    $stmtDetalle->bindParam(":id_producto", $idProducto, PDO::PARAM_INT);
                    $stmtDetalle->bindParam(":categoria", $categoria);
                    $stmtDetalle->bindParam(":nombre_producto", $nombre_producto);
                    $stmtDetalle->execute();
                }
            }

            // Insertar extras
            if (!empty($extrasSession)) {
                $sqlExtra = "INSERT INTO pedido_extras
                             (id_pedido, id_producto, nombre, categoria, cantidad, precio_unitario)
                             VALUES (:id_pedido, :id_producto, :nombre, :categoria, :cantidad, :precio)";
                $stmtExtra = $conexion->prepare($sqlExtra);
                foreach ($extrasSession as $extra) {
                    $stmtExtra->execute([
                        ":id_pedido"  => $id_pedido,
                        ":id_producto"=> $extra["id_producto"],
                        ":nombre"     => $extra["nombre"],
                        ":categoria"  => $extra["categoria"],
                        ":cantidad"   => $extra["cantidad"],
                        ":precio"     => $extra["precio_unitario"],
                    ]);
                }
            }

            $conexion->commit();


            unset($_SESSION["pedido_temporal"]);

            header("Location: confirmar.php?folio=" . urlencode($folio));
            exit;

        } catch (Exception $e) {
            $conexion->rollBack();
            $errores[] = "Ocurrió un error al finalizar el pedido: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pago | Zabisu</title>
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
        <p class="md-hero__eyebrow">Último paso</p>
        <div class="md-hero__marca-grupo">
            <img class="md-hero__logo" src="../assets/img/LOGO_BLANCO.png" alt="Zabisu">
            <h1 class="md-hero__marca">Zabisu</h1>
        </div>
        <p class="md-hero__fecha">Elige cómo pagarás tu pedido</p>
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

    <form action="" method="POST" enctype="multipart/form-data" class="formulario-pedido">
        <section class="bloque-formulario">
            <h2>Método de pago</h2>

            <label class="opcion-producto">
                <input type="radio" name="metodo_pago" value="Transferencia" <?php echo (($_POST["metodo_pago"] ?? "") === "Transferencia") ? "checked" : ""; ?>>
                <strong>Transferencia</strong>
            </label>

            <label class="opcion-producto">
                <input type="radio" name="metodo_pago" value="Efectivo" <?php echo (($_POST["metodo_pago"] ?? "") === "Efectivo") ? "checked" : ""; ?>>
                <strong>Efectivo</strong>
            </label>

            <p class="nota-formulario">
                Si eliges transferencia, podrás ver los datos bancarios y subir tu comprobante.
                Si eliges efectivo, el pedido se generará para pagar al recibirlo.
            </p>
        </section>

        <section class="bloque-formulario bloque-transferencia" id="bloque-transferencia-datos">
            <h2>Transferencia bancaria</h2>

            <div class="ticket-resumen">
                <div class="ticket-menu">
                    <div class="ticket-linea">
                        <span>Banco</span>
                        <span>Mercado Pago</span>
                    </div>
                    <div class="ticket-linea">
                        <span>CLABE</span>
                        <button type="button" class="clabe-copiar" id="btn-copiar-clabe" title="Toca para copiar">
                            <span id="texto-clabe">722969014258283039</span>
                            <span class="clabe-copiar__icono">⎘</span>
                            <span class="clabe-copiar__copiado" id="msg-copiado-clabe">¡Copiado!</span>
                        </button>
                    </div>
                    <div class="ticket-linea">
                        <span>Titular</span>
                        <span>Diana Piña</span>
                    </div>
                    <div class="ticket-linea">
                        <span>Monto</span>
                        <span>$<?php echo number_format($totalPedido, 2); ?></span>
                    </div>
                </div>
            </div>

            <?php
                $conceptoEjemplo = strtoupper($nombre_cliente) . " " . date("d-m-Y");
            ?>
            <div class="aviso-concepto">
                <div class="aviso-concepto__header">
                    <span class="aviso-concepto__icono">⚠️</span>
                    <strong class="aviso-concepto__titulo">Importante: concepto de la transferencia</strong>
                </div>
                <p class="aviso-concepto__texto">
                    Para que tu pago pueda ser identificado y validado correctamente, debes escribir lo siguiente en el campo de <strong>concepto</strong> o <strong>referencia</strong> de tu transferencia:
                </p>
                <div class="aviso-concepto__ejemplo-wrapper">
                    <span class="aviso-concepto__ejemplo-label">Tu concepto debe ser:</span>
                    <button type="button" class="aviso-concepto__ejemplo" id="btn-copiar-concepto" title="Toca para copiar">
                        <span id="texto-concepto"><?php echo htmlspecialchars($conceptoEjemplo); ?></span>
                        <span class="aviso-concepto__copiar-icono">⎘</span>
                    </button>
                    <span class="aviso-concepto__copiado" id="msg-copiado">¡Copiado!</span>
                </div>
                <p class="aviso-concepto__formato">
                    Formato: <strong>NOMBRE COMPLETO EN MAYÚSCULAS · DÍA-MES-AÑO</strong><br>
                    Sin este concepto tu pago <strong>no podrá ser validado</strong>.
                </p>
            </div>
        </section>

        <section class="bloque-formulario bloque-transferencia" id="bloque-transferencia-comprobante">
            <h2>Subir comprobante</h2>

            <div class="bloque-comprobante">
                <div class="bloque-comprobante__header">
                    <label for="comprobante_pago" class="titulo-comprobante">Comprobante de pago</label>
                    <p class="texto-comprobante">
                        Sube una imagen o PDF donde se vea claramente el monto transferido y la referencia.
                    </p>
                </div>

                <div class="bloque-comprobante__acciones">
                    <label for="comprobante_pago" class="input-file-personalizado">
                        Elegir archivo
                    </label>

                    <span class="texto-ayuda-comprobante">Formatos permitidos: JPG, PNG o PDF</span>
                </div>

                <input type="file" name="comprobante_pago" id="comprobante_pago" accept=".jpg,.jpeg,.png,.pdf" hidden>

                <div class="archivo-seleccionado">
                    <span class="archivo-seleccionado__label">Archivo seleccionado:</span>
                    <span id="nombre-archivo" class="nombre-archivo">Ningún archivo seleccionado</span>
                </div>
            </div>
        </section>

        <section class="bloque-formulario">
            <h2>Finalizar</h2>
            <p class="nota-formulario">
                Al continuar, tu pedido se generará oficialmente.
            </p>

            <button type="submit" name="finalizar_pedido" value="1" class="btn-principal">
                Finalizar pedido
            </button>
        </section>
    </form>
</div>

<script>
document.addEventListener("DOMContentLoaded", function () {
    const inputArchivo = document.getElementById("comprobante_pago");
    const nombreArchivo = document.getElementById("nombre-archivo");
    const radiosMetodoPago = document.querySelectorAll('input[name="metodo_pago"]');
    const bloquesTransferencia = document.querySelectorAll(".bloque-transferencia");

    function actualizarVistaPago() {
        const metodoSeleccionado = document.querySelector('input[name="metodo_pago"]:checked');
        const esTransferencia = metodoSeleccionado && metodoSeleccionado.value === "Transferencia";

        bloquesTransferencia.forEach(function (bloque) {
            bloque.style.display = esTransferencia ? "block" : "none";
        });
    }

    if (inputArchivo && nombreArchivo) {
        inputArchivo.addEventListener("change", function () {
            if (this.files && this.files.length > 0) {
                nombreArchivo.textContent = this.files[0].name;
            } else {
                nombreArchivo.textContent = "Ningún archivo seleccionado";
            }
        });
    }

    radiosMetodoPago.forEach(function (radio) {
        radio.addEventListener("change", function () {
            actualizarVistaPago();
        });
    });

    actualizarVistaPago();

    // Copiar CLABE al portapapeles
    const btnCopiarClabe = document.getElementById("btn-copiar-clabe");
    const msgCopiadoClabe = document.getElementById("msg-copiado-clabe");

    if (btnCopiarClabe) {
        btnCopiarClabe.addEventListener("click", function () {
            const texto = document.getElementById("texto-clabe").textContent.trim();
            const copiar = function () {
                msgCopiadoClabe.classList.add("clabe-copiar__copiado--visible");
                setTimeout(function () {
                    msgCopiadoClabe.classList.remove("clabe-copiar__copiado--visible");
                }, 2000);
            };
            navigator.clipboard.writeText(texto).then(copiar).catch(function () {
                const ta = document.createElement("textarea");
                ta.value = texto;
                ta.style.position = "fixed";
                ta.style.opacity = "0";
                document.body.appendChild(ta);
                ta.select();
                document.execCommand("copy");
                document.body.removeChild(ta);
                copiar();
            });
        });
    }

    // Copiar concepto al portapapeles
    const btnCopiar = document.getElementById("btn-copiar-concepto");
    const msgCopiado = document.getElementById("msg-copiado");

    if (btnCopiar) {
        btnCopiar.addEventListener("click", function () {
            const texto = document.getElementById("texto-concepto").textContent.trim();
            navigator.clipboard.writeText(texto).then(function () {
                msgCopiado.classList.add("aviso-concepto__copiado--visible");
                setTimeout(function () {
                    msgCopiado.classList.remove("aviso-concepto__copiado--visible");
                }, 2000);
            }).catch(function () {
                // fallback para navegadores sin clipboard API
                const ta = document.createElement("textarea");
                ta.value = texto;
                ta.style.position = "fixed";
                ta.style.opacity = "0";
                document.body.appendChild(ta);
                ta.select();
                document.execCommand("copy");
                document.body.removeChild(ta);
                msgCopiado.classList.add("aviso-concepto__copiado--visible");
                setTimeout(function () {
                    msgCopiado.classList.remove("aviso-concepto__copiado--visible");
                }, 2000);
            });
        });
    }
});
</script>

<footer class="cliente-footer">
    <span class="cliente-footer__slogan">© 2026 Zabisu - Sabor y Servicio. Todos los derechos reservados.</span>
</footer>

</body>
</html>