<?php
require_once "../config/db.php";
require_once "auth_check.php";

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["eliminar_id"])) {
    $idEliminar = (int)$_POST["eliminar_id"];
    $conexion->prepare("DELETE FROM cotizaciones WHERE id_cotizacion = :id")->execute([":id" => $idEliminar]);
    header("Location: cotizaciones.php?eliminado=1");
    exit;
}

$stmt = $conexion->prepare("SELECT * FROM cotizaciones ORDER BY created_at DESC");
$stmt->execute();
$cotizaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

function fmtPeso($n) { return "$" . number_format((float)$n, 2, ".", ","); }

$estadoClase = [
    "borrador"  => "estado estado-pendiente",
    "enviada"   => "estado estado-revision",
    "aceptada"  => "estado estado-pagado",
    "rechazada" => "estado",
];
$estadoLabel = [
    "borrador"  => "Borrador",
    "enviada"   => "Enviada",
    "aceptada"  => "Aceptada",
    "rechazada" => "Rechazada",
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cotizaciones | Foodtruck Zabisu</title>
    <link rel="icon" type="image/png" href="../assets/img/LOGO_NARA.png">
    <link rel="stylesheet" href="../assets/css/styles.css?v=5">
</head>
<body>
<div class="contenedor">

    <div class="hero-zabisu">
        <div class="hero-zabisu__glow"></div>
        <div class="hero-zabisu__contenido">
            <p class="hero-zabisu__eyebrow">FOODTRUCK ZABISU</p>
            <h1 class="hero-zabisu__titulo">Cotizaciones</h1>
            <p class="hero-zabisu__texto">Genera cotizaciones profesionales para eventos y clientes</p>
            <a href="panel_general.php" class="btn-volver-panel">← Panel general</a>
        </div>
    </div>

    <?php if (isset($_GET["eliminado"])): ?>
    <div class="nm-exito" style="margin-bottom:0;">
        <span class="nm-exito__icono">✓</span>
        <div><strong>Cotización eliminada correctamente.</strong></div>
    </div>
    <?php endif; ?>

    <div class="bloque-formulario">
        <div class="cabecera-modulo">
            <h2>Todas las cotizaciones</h2>
            <a href="nueva_cotizacion.php" class="btn-principal">+ Nueva cotización</a>
        </div>

        <?php if (empty($cotizaciones)): ?>
        <div style="text-align:center;padding:48px 20px;color:var(--texto-secundario);">
            <p style="font-size:32px;margin:0 0 12px;">📄</p>
            <p style="margin:0 0 20px;">Aún no hay cotizaciones registradas.</p>
            <a href="nueva_cotizacion.php" class="btn-principal">Crear primera cotización</a>
        </div>
        <?php else: ?>
        <div class="tabla-pedidos-wrapper">
            <table class="tabla-pedidos">
                <thead>
                    <tr>
                        <th>Folio</th>
                        <th>Cliente</th>
                        <th>Empresa</th>
                        <th>Evento</th>
                        <th>Total</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($cotizaciones as $c): ?>
                    <tr>
                        <td>
                            <a href="ver_cotizacion.php?id=<?php echo (int)$c["id_cotizacion"]; ?>"
                               class="btn-tabla" style="padding:6px 12px;font-size:13px;">
                                <?php echo htmlspecialchars($c["folio"]); ?>
                            </a>
                        </td>
                        <td><?php echo htmlspecialchars($c["nombre_cliente"]); ?></td>
                        <td><?php echo $c["empresa"] ? htmlspecialchars($c["empresa"]) : '<span class="texto-secundario">—</span>'; ?></td>
                        <td>
                            <?php if ($c["fecha_evento"]): ?>
                                <?php echo date("d/m/Y", strtotime($c["fecha_evento"])); ?>
                            <?php else: ?>
                                <span class="texto-secundario">—</span>
                            <?php endif; ?>
                        </td>
                        <?php
                            $iva = (float)($c["iva_porcentaje"] ?? 0);
                            $gran = (float)$c["total"] * (1 + $iva / 100);
                        ?>
                        <td>
                            <strong><?php echo fmtPeso($gran); ?></strong>
                            <?php if ($iva > 0): ?><br><span style="font-size:11px;color:var(--texto-secundario);">+ IVA <?php echo (int)$iva; ?>%</span><?php endif; ?>
                        </td>
                        <td>
                            <span class="<?php echo $estadoClase[$c["estado"]] ?? "estado"; ?>"
                                  <?php if ($c["estado"] === "rechazada"): ?>style="background:#3a1a1a;color:#e57373;"<?php endif; ?>>
                                <?php echo $estadoLabel[$c["estado"]] ?? $c["estado"]; ?>
                            </span>
                        </td>
                        <td style="display:flex;gap:8px;flex-wrap:wrap;">
                            <a href="ver_cotizacion.php?id=<?php echo (int)$c["id_cotizacion"]; ?>"
                               class="btn-limpiar-filtros" style="font-size:12px;padding:5px 10px;">Ver</a>
                            <form method="POST" onsubmit="return confirm('¿Eliminar la cotización <?php echo htmlspecialchars($c["folio"]); ?>? Esta acción no se puede deshacer.');" style="margin:0;">
                                <input type="hidden" name="eliminar_id" value="<?php echo (int)$c["id_cotizacion"]; ?>">
                                <button type="submit" class="btn-tabla" style="font-size:12px;padding:5px 10px;background:#3a1a1a;color:#e57373;border:none;cursor:pointer;">Eliminar</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

</div>
</body>
</html>
