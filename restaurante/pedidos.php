<?php
require_once "../config/db.php";
require_once "auth_check.php";

/*
    Obtener pedidos con ubicación y horario
*/
$sqlPedidos = "SELECT
                    p.id_pedido,
                    p.folio,
                    p.nombre_cliente,
                    p.telefono,
                    p.total,
                    p.metodo_pago,
                    p.estado,
                    p.estado_pago,
                    p.fecha_pedido,
                    p.visto,
                    h.hora_entrega,
                    u.nombre_ubicacion,
                    u.tipo AS tipo_ubicacion
               FROM pedidos p
               INNER JOIN horarios_ubicacion h ON p.id_horario = h.id_horario
               INNER JOIN ubicaciones u ON h.id_ubicacion = u.id_ubicacion
               ORDER BY DATE(p.fecha_pedido) DESC, p.visto ASC, p.fecha_pedido DESC, p.id_pedido DESC";
$stmtPedidos = $conexion->prepare($sqlPedidos);
$stmtPedidos->execute();
$pedidos = $stmtPedidos->fetchAll(PDO::FETCH_ASSOC);

/*
    Contar pedidos nuevos actuales
*/
$totalNuevosActual = count(array_filter($pedidos, function ($pedido) {
    return (int)$pedido["visto"] === 0;
}));

/*
    Agrupar pedidos por fecha
*/
$pedidosPorFecha = [];

foreach ($pedidos as $pedido) {
    $fechaGrupo = date("Y-m-d", strtotime($pedido["fecha_pedido"]));
    $pedidosPorFecha[$fechaGrupo][] = $pedido;
}

/*
    Obtener resumen de platos fuertes por fecha
*/
$sqlResumenPlatos = "SELECT
                        DATE(p.fecha_pedido) AS fecha_grupo,
                        dp.nombre_producto,
                        COUNT(*) AS total
                     FROM detalle_pedido dp
                     INNER JOIN pedido_menus pm ON dp.id_pedido_menu = pm.id_pedido_menu
                     INNER JOIN pedidos p ON pm.id_pedido = p.id_pedido
                     WHERE dp.categoria = 'Plato fuerte'
                     GROUP BY DATE(p.fecha_pedido), dp.nombre_producto
                     ORDER BY DATE(p.fecha_pedido) DESC, dp.nombre_producto ASC";
$stmtResumenPlatos = $conexion->prepare($sqlResumenPlatos);
$stmtResumenPlatos->execute();
$resumenPlatosDB = $stmtResumenPlatos->fetchAll(PDO::FETCH_ASSOC);

$resumenPlatosPorFecha = [];

foreach ($resumenPlatosDB as $fila) {
    $fechaGrupo = $fila["fecha_grupo"];
    $resumenPlatosPorFecha[$fechaGrupo][] = [
        "nombre_producto" => $fila["nombre_producto"],
        "total" => (int)$fila["total"]
    ];
}

/*
    Obtener resumen de complementos por fecha
*/
$sqlResumenComplementos = "SELECT
                              DATE(p.fecha_pedido) AS fecha_grupo,
                              dp.nombre_producto,
                              COUNT(*) AS total
                           FROM detalle_pedido dp
                           INNER JOIN pedido_menus pm ON dp.id_pedido_menu = pm.id_pedido_menu
                           INNER JOIN pedidos p ON pm.id_pedido = p.id_pedido
                           WHERE dp.categoria = 'Complemento'
                           GROUP BY DATE(p.fecha_pedido), dp.nombre_producto
                           ORDER BY DATE(p.fecha_pedido) DESC, dp.nombre_producto ASC";
$stmtResumenComplementos = $conexion->prepare($sqlResumenComplementos);
$stmtResumenComplementos->execute();
$resumenComplementosDB = $stmtResumenComplementos->fetchAll(PDO::FETCH_ASSOC);

$resumenComplementosPorFecha = [];

foreach ($resumenComplementosDB as $fila) {
    $fechaGrupo = $fila["fecha_grupo"];
    $resumenComplementosPorFecha[$fechaGrupo][] = [
        "nombre_producto" => $fila["nombre_producto"],
        "total" => (int)$fila["total"]
    ];
}

/*
    Totales por fecha (comidas = suma de platos fuertes; pedidos = conteo de pedidos)
*/
$totalComidasPorFecha = [];
foreach ($resumenPlatosPorFecha as $fecha => $platos) {
    $totalComidasPorFecha[$fecha] = array_sum(array_column($platos, "total"));
}

/*
    Opciones para filtros
*/
$ubicacionesFiltro = array_values(array_unique(array_column($pedidos, "nombre_ubicacion")));
sort($ubicacionesFiltro);

$horasFiltro = array_values(array_unique(array_map(function ($p) {
    return $p["hora_entrega"];
}, $pedidos)));
sort($horasFiltro);

/*
    Funciones auxiliares
*/
function obtenerClaseEstadoPago($estadoPago)
{
    switch ($estadoPago) {
        case "Pendiente de validación":
            return "estado estado-revision";
        case "Pago en efectivo":
            return "estado estado-efectivo";
        case "Pagado":
            return "estado estado-pagado";
        default:
            return "estado estado-pendiente";
    }
}

function obtenerTextoEstadoPago($estadoPago)
{
    switch ($estadoPago) {
        case "Pendiente de validación":
            return "Pago en revisión";
        case "Pago en efectivo":
            return "Pago al recibir";
        case "Pagado":
            return "Pago confirmado";
        default:
            return $estadoPago ?: "Pendiente";
    }
}

function formatearFechaBonita($fecha)
{
    $timestamp = strtotime($fecha);

    $dias = [
        'Sunday' => 'domingo',
        'Monday' => 'lunes',
        'Tuesday' => 'martes',
        'Wednesday' => 'miércoles',
        'Thursday' => 'jueves',
        'Friday' => 'viernes',
        'Saturday' => 'sábado'
    ];

    $meses = [
        '01' => 'enero',
        '02' => 'febrero',
        '03' => 'marzo',
        '04' => 'abril',
        '05' => 'mayo',
        '06' => 'junio',
        '07' => 'julio',
        '08' => 'agosto',
        '09' => 'septiembre',
        '10' => 'octubre',
        '11' => 'noviembre',
        '12' => 'diciembre'
    ];

    $diaSemana = $dias[date('l', $timestamp)] ?? date('l', $timestamp);
    $dia = date('j', $timestamp);
    $mes = $meses[date('m', $timestamp)] ?? date('m', $timestamp);
    $anio = date('Y', $timestamp);

    return ucfirst($diaSemana) . " " . $dia . " de " . $mes . " de " . $anio;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pedidos | Restaurante Zabisu</title>
    <link rel="stylesheet" href="../assets/css/styles.css">
</head>
<body>

<div class="contenedor">
    <div class="hero-zabisu">
        <div class="hero-zabisu__glow"></div>
        <div class="hero-zabisu__contenido">
            <p class="hero-zabisu__eyebrow">RESTAURANTE</p>
            <h1 class="hero-zabisu__titulo">Panel de pedidos</h1>
            <p class="hero-zabisu__texto">
                Visualiza, revisa y administra los pedidos registrados.
            </p>
            <a href="panel_general.php" class="btn-volver-panel">← Panel general</a>
            <button type="button" id="btn-toggle-notificacion" class="btn-notificar-ruta">
                📍 Notificar llegada al punto
            </button>
        </div>
    </div>

    <!-- ══ PANEL NOTIFICACIÓN DE RUTA ══════════════════════════ -->
    <div class="bloque-formulario bloque-notificacion-ruta" id="bloque-notificacion-ruta" style="display:none;">
        <h2>📍 Notificar llegada al punto</h2>
        <p class="nota-formulario">
            Envía un correo a todos los clientes de una ubicación y horario específico para avisarles que su pedido ya llegó.
            Solo se notificará a quienes registraron correo electrónico.
        </p>

        <div class="notificacion-ruta__campos">
            <div class="notificacion-ruta__campo">
                <label for="notif-fecha">Fecha</label>
                <input type="date" id="notif-fecha" value="<?php echo date('Y-m-d'); ?>">
            </div>
            <div class="notificacion-ruta__campo">
                <label for="notif-ubicacion">Ubicación</label>
                <select id="notif-ubicacion">
                    <option value="">Selecciona una ubicación</option>
                    <?php foreach ($ubicacionesFiltro as $ub): ?>
                        <option value="<?php echo htmlspecialchars($ub); ?>">
                            <?php echo htmlspecialchars($ub); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="notificacion-ruta__campo">
                <label for="notif-hora">Horario</label>
                <select id="notif-hora">
                    <option value="">Selecciona un horario</option>
                    <?php foreach ($horasFiltro as $hora): ?>
                        <option value="<?php echo htmlspecialchars($hora); ?>">
                            <?php echo date("g:i A", strtotime($hora)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <button type="button" id="btn-enviar-notificacion" class="btn-principal" style="margin-top:20px;">
            Enviar notificación
        </button>

        <div id="notificacion-resultado" class="notificacion-resultado" style="display:none;"></div>
    </div>

    <?php if (!empty($pedidos)): ?>
    <div class="bloque-formulario filtros-pedidos">
        <div class="filtros-pedidos__fila">
            <div class="filtros-pedidos__busqueda">
                <input type="text" id="filtro-busqueda" placeholder="Buscar por folio o ID…" autocomplete="off">
            </div>
            <div class="filtros-pedidos__selects">
                <select id="filtro-ubicacion">
                    <option value="">Todas las ubicaciones</option>
                    <?php foreach ($ubicacionesFiltro as $ub): ?>
                        <option value="<?php echo htmlspecialchars($ub); ?>">
                            <?php echo htmlspecialchars($ub); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select id="filtro-hora">
                    <option value="">Todos los horarios</option>
                    <?php foreach ($horasFiltro as $hora): ?>
                        <option value="<?php echo htmlspecialchars($hora); ?>">
                            <?php echo date("g:i A", strtotime($hora)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="button" id="btn-limpiar-filtros" class="btn-limpiar-filtros">Limpiar</button>
            </div>
        </div>
        <p id="filtros-sin-resultados" class="filtros-sin-resultados" style="display:none;">
            No se encontraron pedidos con los filtros aplicados.
        </p>
    </div>
    <?php endif; ?>

    <div class="bloque-formulario">
        <h2>Pedidos registrados</h2>

        <?php if (empty($pedidosPorFecha)): ?>
            <p class="nota-formulario">No hay pedidos registrados por el momento.</p>
        <?php else: ?>
            <?php foreach ($pedidosPorFecha as $fecha => $listaPedidos): ?>
                <div class="grupo-fecha-pedidos">
                    <div class="cabecera-fecha-pedidos">
                        <h3 class="titulo-fecha-pedidos">
                            <?php echo htmlspecialchars(formatearFechaBonita($fecha)); ?>
                        </h3>
                        <div class="stats-dia">
                            <div class="stat-dia">
                                <span class="stat-dia__numero">
                                    <?php echo $totalComidasPorFecha[$fecha] ?? 0; ?>
                                </span>
                                <span class="stat-dia__etiqueta">
                                    <?php echo ($totalComidasPorFecha[$fecha] ?? 0) === 1 ? "comida" : "comidas"; ?>
                                </span>
                            </div>
                            <div class="stat-dia stat-dia--secundario">
                                <span class="stat-dia__numero">
                                    <?php echo count($listaPedidos); ?>
                                </span>
                                <span class="stat-dia__etiqueta">
                                    <?php echo count($listaPedidos) === 1 ? "pedido" : "pedidos"; ?>
                                </span>
                            </div>
                        </div>
                    </div>

                    <?php if (!empty($resumenPlatosPorFecha[$fecha])): ?>
                        <div class="resumen-produccion-dia">
                            <h4 class="resumen-produccion-dia__titulo">Producción de platos fuertes</h4>

                            <div class="resumen-produccion-dia__grid">
                                <?php foreach ($resumenPlatosPorFecha[$fecha] as $plato): ?>
                                    <div class="tarjeta-produccion">
                                        <span class="tarjeta-produccion__nombre">
                                            <?php echo htmlspecialchars($plato["nombre_producto"]); ?>
                                        </span>
                                        <strong class="tarjeta-produccion__total">
                                            <?php echo (int)$plato["total"]; ?>
                                        </strong>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($resumenComplementosPorFecha[$fecha])): ?>
                        <div class="resumen-produccion-dia resumen-produccion-dia--secundario">
                            <h4 class="resumen-produccion-dia__titulo">Producción de complementos</h4>

                            <div class="resumen-produccion-dia__grid">
                                <?php foreach ($resumenComplementosPorFecha[$fecha] as $complemento): ?>
                                    <div class="tarjeta-produccion">
                                        <span class="tarjeta-produccion__nombre">
                                            <?php echo htmlspecialchars($complemento["nombre_producto"]); ?>
                                        </span>
                                        <strong class="tarjeta-produccion__total">
                                            <?php echo (int)$complemento["total"]; ?>
                                        </strong>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="tabla-pedidos-wrapper">
                        <table class="tabla-pedidos">
                            <thead>
                                <tr>
                                    <th>Folio</th>
                                    <th>Cliente</th>
                                    <th>Ubicación</th>
                                    <th>Hora</th>
                                    <th>Pago</th>
                                    <th>Total</th>
                                    <th>Acción</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($listaPedidos as $pedido): ?>
                                    <tr class="<?php echo ((int)$pedido["visto"] === 0) ? 'fila-pedido-nuevo' : 'fila-pedido-visto'; ?> fila-pedido"
                                        data-folio="<?php echo strtolower(htmlspecialchars($pedido["folio"])); ?>"
                                        data-id="<?php echo (int)$pedido["id_pedido"]; ?>"
                                        data-ubicacion="<?php echo htmlspecialchars($pedido["nombre_ubicacion"]); ?>"
                                        data-hora="<?php echo htmlspecialchars($pedido["hora_entrega"]); ?>">
                                        <td>
                                            <div class="folio-pedido">
                                                <strong><?php echo htmlspecialchars($pedido["folio"]); ?></strong>
                                                <?php if ((int)$pedido["visto"] === 0): ?>
                                                    <span class="badge-nuevo">Nuevo</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($pedido["nombre_cliente"]); ?></strong><br>
                                            <span class="texto-secundario"><?php echo htmlspecialchars($pedido["telefono"]); ?></span>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($pedido["nombre_ubicacion"]); ?><br>
                                            <span class="texto-secundario">
                                                <?php echo ucfirst(htmlspecialchars($pedido["tipo_ubicacion"])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo date("g:i A", strtotime($pedido["hora_entrega"])); ?>
                                        </td>
                                        <td>
                                            <span class="<?php echo obtenerClaseEstadoPago($pedido["estado_pago"]); ?>">
                                                <?php echo htmlspecialchars(obtenerTextoEstadoPago($pedido["estado_pago"])); ?>
                                            </span>
                                            <br>
                                            <span class="texto-secundario">
                                                <?php echo htmlspecialchars($pedido["metodo_pago"]); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <strong>$<?php echo number_format((float)$pedido["total"], 2); ?></strong>
                                        </td>
                                        <td>
                                            <a class="btn-tabla" href="ver_pedido.php?id=<?php echo urlencode($pedido["id_pedido"]); ?>">
                                                Ver detalle
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<audio id="audio-alerta-pedido" preload="auto">
    <source src="../assets/audio/notificacion.mp3" type="audio/mpeg">
</audio>

<script>
document.addEventListener("DOMContentLoaded", function () {

    // ============================================================
    // FILTROS DE PEDIDOS
    // ============================================================
    const filtroBusqueda   = document.getElementById("filtro-busqueda");
    const filtroUbicacion  = document.getElementById("filtro-ubicacion");
    const filtroHora       = document.getElementById("filtro-hora");
    const btnLimpiar       = document.getElementById("btn-limpiar-filtros");
    const sinResultados    = document.getElementById("filtros-sin-resultados");

    function aplicarFiltros() {
        const busqueda  = (filtroBusqueda  ? filtroBusqueda.value.toLowerCase().trim()  : "");
        const ubicacion = (filtroUbicacion ? filtroUbicacion.value                       : "");
        const hora      = (filtroHora      ? filtroHora.value                            : "");

        let totalVisibles = 0;

        document.querySelectorAll(".grupo-fecha-pedidos").forEach(function (grupo) {
            let visiblesEnGrupo = 0;

            grupo.querySelectorAll(".fila-pedido").forEach(function (fila) {
                const folio     = fila.dataset.folio     || "";
                const id        = String(fila.dataset.id || "");
                const filUb     = fila.dataset.ubicacion || "";
                const filHora   = fila.dataset.hora      || "";

                const coincideBusqueda  = !busqueda  || folio.includes(busqueda) || id.includes(busqueda);
                const coincideUbicacion = !ubicacion || filUb  === ubicacion;
                const coincideHora      = !hora      || filHora === hora;

                const visible = coincideBusqueda && coincideUbicacion && coincideHora;
                fila.style.display = visible ? "" : "none";
                if (visible) { visiblesEnGrupo++; totalVisibles++; }
            });

            grupo.style.display = visiblesEnGrupo > 0 ? "" : "none";
        });

        if (sinResultados) {
            sinResultados.style.display = totalVisibles === 0 ? "block" : "none";
        }
    }

    if (filtroBusqueda)  filtroBusqueda.addEventListener("input",  aplicarFiltros);
    if (filtroUbicacion) filtroUbicacion.addEventListener("change", aplicarFiltros);
    if (filtroHora)      filtroHora.addEventListener("change",      aplicarFiltros);

    if (btnLimpiar) {
        btnLimpiar.addEventListener("click", function () {
            if (filtroBusqueda)  filtroBusqueda.value  = "";
            if (filtroUbicacion) filtroUbicacion.value = "";
            if (filtroHora)      filtroHora.value       = "";
            aplicarFiltros();
        });
    }

    // ============================================================
    // ALERTAS DE NUEVOS PEDIDOS
    // ============================================================
    let totalNuevosActual = <?php echo (int)$totalNuevosActual; ?>;
    const audioAlerta = document.getElementById("audio-alerta-pedido");
    let audioHabilitado = false;

    function habilitarAudio() {
        audioHabilitado = true;
        document.removeEventListener("click", habilitarAudio);
        document.removeEventListener("keydown", habilitarAudio);
    }

    document.addEventListener("click", habilitarAudio);
    document.addEventListener("keydown", habilitarAudio);

    function revisarNuevosPedidos() {
        fetch("check_nuevos_pedidos.php", {
            method: "GET",
            cache: "no-store"
        })
        .then(response => response.json())
        .then(data => {
            const totalNuevosServidor = parseInt(data.total_nuevos || 0, 10);

            if (totalNuevosServidor > totalNuevosActual) {
                if (audioHabilitado && audioAlerta) {
                    audioAlerta.currentTime = 0;
                    audioAlerta.play().catch(() => {});
                }

                setTimeout(function () {
                    window.location.reload();
                }, 1200);
            }

            totalNuevosActual = totalNuevosServidor;
        })
        .catch(error => {
            console.error("Error al revisar nuevos pedidos:", error);
        });
    }

    setInterval(revisarNuevosPedidos, 10000);

    // ============================================================
    // NOTIFICACIÓN DE LLEGADA AL PUNTO
    // ============================================================
    const btnToggleNotif   = document.getElementById("btn-toggle-notificacion");
    const bloqueNotif      = document.getElementById("bloque-notificacion-ruta");
    const btnEnviarNotif   = document.getElementById("btn-enviar-notificacion");
    const resultadoNotif   = document.getElementById("notificacion-resultado");
    const notifFecha       = document.getElementById("notif-fecha");
    const notifUbicacion   = document.getElementById("notif-ubicacion");
    const notifHora        = document.getElementById("notif-hora");

    if (btnToggleNotif && bloqueNotif) {
        btnToggleNotif.addEventListener("click", function () {
            const visible = bloqueNotif.style.display !== "none";
            bloqueNotif.style.display = visible ? "none" : "block";
            btnToggleNotif.classList.toggle("btn-notificar-ruta--activo", !visible);
            if (!visible) {
                bloqueNotif.scrollIntoView({ behavior: "smooth", block: "start" });
            }
        });
    }

    if (btnEnviarNotif) {
        btnEnviarNotif.addEventListener("click", function () {
            const fecha     = notifFecha     ? notifFecha.value     : "";
            const ubicacion = notifUbicacion ? notifUbicacion.value : "";
            const hora      = notifHora      ? notifHora.value      : "";

            if (!ubicacion || !hora) {
                mostrarResultadoNotif("error", "Selecciona una ubicación y un horario antes de enviar.");
                return;
            }

            btnEnviarNotif.disabled    = true;
            btnEnviarNotif.textContent = "Enviando…";

            const datos = new FormData();
            datos.append("fecha",            fecha);
            datos.append("nombre_ubicacion", ubicacion);
            datos.append("hora_entrega",     hora);

            fetch("notificar_ruta.php", { method: "POST", body: datos })
                .then(r => r.json())
                .then(function (data) {
                    if (!data.ok) {
                        mostrarResultadoNotif("error", data.mensaje || "Ocurrió un error.");
                    } else {
                        let msg = `✅ Notificación enviada. <strong>${data.enviados}</strong> correo${data.enviados !== 1 ? "s" : ""} enviado${data.enviados !== 1 ? "s" : ""} de ${data.total} pedido${data.total !== 1 ? "s" : ""}.`;
                        if (data.sin_correo > 0) {
                            msg += ` <span class="notif-aviso">(${data.sin_correo} sin correo registrado)</span>`;
                        }
                        if (data.errores > 0) {
                            msg += ` <span class="notif-aviso">(${data.errores} con error al enviar)</span>`;
                        }
                        mostrarResultadoNotif("exito", msg);
                    }
                })
                .catch(function () {
                    mostrarResultadoNotif("error", "No se pudo conectar con el servidor.");
                })
                .finally(function () {
                    btnEnviarNotif.disabled    = false;
                    btnEnviarNotif.textContent = "Enviar notificación";
                });
        });
    }

    function mostrarResultadoNotif(tipo, html) {
        if (!resultadoNotif) return;
        resultadoNotif.className = "notificacion-resultado notificacion-resultado--" + tipo;
        resultadoNotif.innerHTML = html;
        resultadoNotif.style.display = "block";
    }
});
</script>

</body>
</html>