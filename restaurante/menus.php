<?php
require_once "../config/db.php";
require_once "auth_check.php";

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
    <link rel="stylesheet" href="../assets/css/styles.css">
</head>
<body>

<div class="contenedor">
    <div class="hero-zabisu">
        <div class="hero-zabisu__glow"></div>
        <div class="hero-zabisu__contenido">
            <p class="hero-zabisu__eyebrow">RESTAURANTE</p>
            <h1 class="hero-zabisu__titulo">Panel de menús</h1>
            <p class="hero-zabisu__texto">
                Administra los menús del día y prepara la operación semanal.
            </p>
            <a href="panel_general.php" class="btn-volver-panel">← Panel general</a>
        </div>
    </div>

    <div class="bloque-formulario">
        <div class="cabecera-modulo">
            <h2>Menús registrados</h2>
            <a href="nuevo_menu.php" class="btn-tabla">Nuevo menú</a>
        </div>

        <?php if (empty($menus)): ?>
            <p class="nota-formulario">No hay menús registrados todavía.</p>
        <?php else: ?>
            <div class="tabla-pedidos-wrapper">
                <table class="tabla-pedidos">
                    <thead>
                       <tr>
                            <th>Fecha del menú</th>
                            <th>Publicado desde</th>
                            <th>Pedido hasta</th>
                            <th>Estado</th>
                            <th>Creado</th>
                            <th>Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($menus as $menu): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars(formatearFechaBonita($menu["fecha"])); ?></strong><br>
                                    <span class="texto-secundario">ID: <?php echo (int)$menu["id_menu"]; ?></span>
                                </td>

                                <td>
                                    <?php if ((int)$menu["activo"] === 1): ?>
                                        <span class="estado estado-pagado">En uso</span>
                                    <?php else: ?>
                                        <a href="activar_menu.php?id_menu=<?php echo (int)$menu["id_menu"]; ?>" class="btn-tabla">
                                            Activar menú
                                        </a>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php echo htmlspecialchars(date("d/m/Y g:i A", strtotime($menu["publicado_desde"]))); ?>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars(date("d/m/Y g:i A", strtotime($menu["pedido_hasta"]))); ?>
                                </td>
                                <td>
                                    <?php if ((int)$menu["activo"] === 1): ?>
                                        <span class="estado estado-pagado">Activo</span>
                                    <?php else: ?>
                                        <span class="estado estado-pendiente">Inactivo</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="texto-secundario">
                                        <?php echo htmlspecialchars(date("d/m/Y g:i A", strtotime($menu["creado_en"]))); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="bloque-formulario">
        <h2>Siguientes acciones</h2>
        <div class="acciones-panel__botones">
            <a href="pedidos.php" class="btn-link">Ir a pedidos</a>
            <a href="nuevo_menu.php" class="btn-tabla">Crear menú del día</a>
        </div>
    </div>
</div>

</body>
</html>