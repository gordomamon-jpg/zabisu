<?php
require_once "../config/db.php";
require_once "auth_check.php";

/*
    Auto-desactivar menús cuyo pedido_hasta ya pasó
*/
$conexion->exec("UPDATE menu_dia SET activo = 0 WHERE activo = 1 AND pedido_hasta < NOW()");

/*
    Eliminar menú
*/
$mensajeEliminar = "";
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["eliminar_menu"])) {
    $idEliminar = (int)($_POST["id_menu"] ?? 0);
    if ($idEliminar > 0) {
        $sqlCheck = "SELECT activo FROM menu_dia WHERE id_menu = :id LIMIT 1";
        $stmtCheck = $conexion->prepare($sqlCheck);
        $stmtCheck->execute([":id" => $idEliminar]);
        $menuCheck = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if (!$menuCheck) {
            $mensajeEliminar = "error:Menú no encontrado.";
        } elseif ((int)$menuCheck["activo"] === 1) {
            $mensajeEliminar = "error:No puedes eliminar un menú activo. Desactívalo primero.";
        } else {
            try {
                $conexion->beginTransaction();

                /*
                    Verificar si hay pedidos REALES (no de prueba) que referencian productos de este menú.
                    Si los hay, bloqueamos la eliminación para proteger el historial real.
                */
                $stmtReal = $conexion->prepare(
                    "SELECT COUNT(*) FROM pedidos p
                     INNER JOIN pedido_menus pm ON pm.id_pedido = p.id_pedido
                     INNER JOIN detalle_pedido dp ON dp.id_pedido_menu = pm.id_pedido_menu
                     INNER JOIN productos pr ON pr.id_producto = dp.id_producto
                     WHERE pr.id_menu = :id AND p.es_prueba = 0"
                );
                $stmtReal->execute([":id" => $idEliminar]);
                $tieneReales = (int)$stmtReal->fetchColumn();

                if ($tieneReales > 0) {
                    $conexion->rollBack();
                    $mensajeEliminar = "error:Este menú tiene pedidos reales asociados y no puede eliminarse.";
                } else {
                    /*
                        Solo hay pedidos de prueba: los eliminamos en cascada antes de borrar el menú.
                    */
                    // IDs de pedidos de prueba ligados a este menú
                    $stmtIds = $conexion->prepare(
                        "SELECT DISTINCT p.id_pedido FROM pedidos p
                         INNER JOIN pedido_menus pm ON pm.id_pedido = p.id_pedido
                         INNER JOIN detalle_pedido dp ON dp.id_pedido_menu = pm.id_pedido_menu
                         INNER JOIN productos pr ON pr.id_producto = dp.id_producto
                         WHERE pr.id_menu = :id AND p.es_prueba = 1"
                    );
                    $stmtIds->execute([":id" => $idEliminar]);
                    $idsPrueba = $stmtIds->fetchAll(PDO::FETCH_COLUMN);

                    if (!empty($idsPrueba)) {
                        $in = implode(",", array_map("intval", $idsPrueba));

                        $conexion->exec("DELETE pe FROM pedido_extras pe WHERE pe.id_pedido IN ($in)");
                        $conexion->exec("DELETE dp FROM detalle_pedido dp
                                         INNER JOIN pedido_menus pm ON pm.id_pedido_menu = dp.id_pedido_menu
                                         WHERE pm.id_pedido IN ($in)");
                        $conexion->exec("DELETE FROM pedido_menus WHERE id_pedido IN ($in)");
                        $conexion->exec("DELETE FROM pedidos WHERE id_pedido IN ($in)");
                    }

                    $conexion->prepare("DELETE FROM productos WHERE id_menu = :id")->execute([":id" => $idEliminar]);
                    $conexion->prepare("DELETE FROM menu_dia WHERE id_menu = :id")->execute([":id" => $idEliminar]);
                    $conexion->commit();
                    $mensajeEliminar = "ok:Menú eliminado correctamente.";
                }
            } catch (Exception $e) {
                $conexion->rollBack();
                $mensajeEliminar = "error:Error al eliminar: " . $e->getMessage();
            }
        }
    }
}

/*
    Obtener menús registrados
*/
$sqlMenus = "SELECT
                id_menu,
                fecha,
                publicado_desde,
                pedido_hasta,
                activo,
                creado_en
             FROM menu_dia
             ORDER BY fecha DESC, id_menu DESC";
$stmtMenus = $conexion->prepare($sqlMenus);
$stmtMenus->execute();
$menus = $stmtMenus->fetchAll(PDO::FETCH_ASSOC);

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
    <title>Menús | Restaurante Zabisu</title>
    <link rel="icon" type="image/png" href="../assets/img/LOGO_NARA.png">
    <meta name="theme-color" content="#151413">
    <link rel="preload" href="../assets/fonts/kaushan-script-400.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="../assets/fonts/alfa-slab-one-400.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="../assets/css/menu-pizarron.css?v=5">
    <link rel="stylesheet" href="../assets/css/menus-pizarron.css?v=3">
</head>
<body class="pz">
<?php require __DIR__ . "/../includes/pz_filtros.php"; ?>

<main class="mz">
    <header class="mz-cab">
        <div class="pz-marca">
            <img src="../assets/img/LOGO_N_128.png" alt="">
            <div>
                <span class="pz-marca__nombre">Zabisu</span>
                <span class="pz-marca__lema">Sabor y Servicio</span>
            </div>
        </div>
        <h1 class="pz-titulo"><span class="pz-pincel"><span>Menús</span></span></h1>

        <nav class="mz-cab__acc" aria-label="Acciones">
            <a href="nuevo_menu.php" class="mz-btn--naranja">Nuevo menú</a>
            <a href="historico_menus.php" class="btn-volver-panel">Historial de menús</a>
            <a href="panel_general.php" class="btn-volver-panel">← Panel general</a>
        </nav>
    </header>

    <?php if ($mensajeEliminar): ?>
        <?php [$tipo, $texto] = explode(":", $mensajeEliminar, 2); ?>
        <div class="<?php echo $tipo === 'ok' ? 'nm-exito' : 'mensaje-error'; ?>">
            <?php echo htmlspecialchars($texto); ?>
        </div>
    <?php endif; ?>

    <?php if (empty($menus)): ?>
        <p class="nota-formulario" style="margin-top:24px;">No hay menús registrados todavía.</p>
    <?php else: ?>
        <?php
            // Agrupados por mes para encontrarlos rápido
            $mesesNombre = [1 => "Enero", "Febrero", "Marzo", "Abril", "Mayo", "Junio", "Julio", "Agosto", "Septiembre", "Octubre", "Noviembre", "Diciembre"];
            $menusPorMes = [];
            foreach ($menus as $menu) {
                $ts = strtotime($menu["fecha"]);
                $menusPorMes[$mesesNombre[(int)date("n", $ts)] . " " . date("Y", $ts)][] = $menu;
            }
        ?>
        <?php foreach ($menusPorMes as $nombreMes => $menusMes): ?>
        <section class="mz-mes">
            <h2 class="pz-cinta pz-fibra"><?php echo $nombreMes; ?></h2>
            <div class="mz-lista">
                <?php foreach ($menusMes as $menu): ?>
                    <?php $activo = (int)$menu["activo"] === 1; ?>
                    <article class="mz-menu<?php echo $activo ? ' mz-menu--activo' : ''; ?>">
                        <div class="mz-menu__dia">
                            <b><?php echo htmlspecialchars(formatearFechaBonita($menu["fecha"])); ?></b>
                            <small>ID: <?php echo (int)$menu["id_menu"]; ?> · creado <?php echo htmlspecialchars(date("d/m/Y g:i A", strtotime($menu["creado_en"]))); ?></small>
                        </div>
                        <div class="mz-menu__horas">
                            <span>Publica <b><?php echo htmlspecialchars(date("d/m/Y g:i A", strtotime($menu["publicado_desde"]))); ?></b></span>
                            <span>Cierra <b><?php echo htmlspecialchars(date("d/m/Y g:i A", strtotime($menu["pedido_hasta"]))); ?></b></span>
                        </div>
                        <div class="mz-menu__estado">
                            <?php if ($activo): ?>
                                <span class="estado estado-pagado">Activo · en uso</span>
                            <?php else: ?>
                                <span class="estado estado-pendiente">Inactivo</span>
                            <?php endif; ?>
                        </div>
                        <div class="mz-menu__acc">
                            <?php if (!$activo): ?>
                                <a href="activar_menu.php?id_menu=<?php echo (int)$menu["id_menu"]; ?>" class="btn-tabla btn-tabla--estraza">
                                    Activar menú
                                </a>
                            <?php endif; ?>
                            <a class="btn-tabla" href="productos_menu.php?id_menu=<?php echo (int)$menu["id_menu"]; ?>&editar=1">
                                Editar
                            </a>
                            <?php if (!$activo): ?>
                            <form method="POST" action="" class="form-eliminar-menu"
                                  data-fecha="<?php echo htmlspecialchars(formatearFechaBonita($menu["fecha"])); ?>">
                                <input type="hidden" name="id_menu" value="<?php echo (int)$menu["id_menu"]; ?>">
                                <button type="submit" name="eliminar_menu" class="btn-tabla btn-tabla--eliminar">
                                    Eliminar
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endforeach; ?>
    <?php endif; ?>

    <nav class="mz-siguientes" aria-label="Siguientes acciones">
        <a href="pedidos.php" class="btn-link">Ir a pedidos</a>
        <a href="nuevo_menu.php" class="btn-tabla">Crear menú del día</a>
    </nav>
</main>

<script>
document.querySelectorAll(".form-eliminar-menu").forEach(function (form) {
    form.addEventListener("submit", function (e) {
        var fecha = form.dataset.fecha;
        if (!confirm("¿Eliminar el menú del " + fecha + "?\nEsta acción también borrará todos sus productos y no se puede deshacer.")) {
            e.preventDefault();
        }
    });
});
</script>

</body>
</html>