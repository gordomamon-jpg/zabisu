<?php
require_once "../config/db.php";
require_once "auth_check.php";

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["eliminar_id"])) {
    $idEliminar = (int)$_POST["eliminar_id"];
    $conexion->prepare("DELETE FROM notas_cuenta WHERE id_nota = :id")->execute([":id" => $idEliminar]);
    header("Location: notas_cuenta.php?eliminado=1");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["cambiar_estado_id"])) {
    $id = (int)$_POST["cambiar_estado_id"];
    $nuevo = ($_POST["nuevo_estado"] ?? "") === "cerrada" ? "cerrada" : "abierta";
    $conexion->prepare("UPDATE notas_cuenta SET estado = :estado WHERE id_nota = :id")
             ->execute([":estado" => $nuevo, ":id" => $id]);
    header("Location: notas_cuenta.php");
    exit;
}

$stmt = $conexion->query("SELECT * FROM notas_cuenta ORDER BY estado ASC, created_at DESC");
$notas = $stmt->fetchAll(PDO::FETCH_ASSOC);

function fmtPeso($n) { return "$" . number_format((float)$n, 2, ".", ","); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notas de cuenta | Zabisu</title>
    <link rel="icon" type="image/png" href="../assets/img/LOGO_NARA.png">
    <link rel="stylesheet" href="../assets/css/styles.css?v=5">
</head>
<body>
<div class="contenedor">

    <div class="hero-zabisu">
        <div class="hero-zabisu__glow"></div>
        <div class="hero-zabisu__contenido">
            <p class="hero-zabisu__eyebrow">CLIENTES DE CRÉDITO</p>
            <h1 class="hero-zabisu__titulo">Notas de cuenta</h1>
            <p class="hero-zabisu__texto">Para clientes que piden diario y pagan a fin de semana</p>
            <a href="panel_general.php" class="btn-volver-panel">← Panel general</a>
        </div>
    </div>

    <?php if (isset($_GET["eliminado"])): ?>
    <div class="nm-exito" style="margin-bottom:0;">
        <span class="nm-exito__icono">✓</span>
        <div><strong>Nota eliminada correctamente.</strong></div>
    </div>
    <?php endif; ?>

    <div class="bloque-formulario">
        <div class="cabecera-modulo">
            <h2>Todas las notas</h2>
            <a href="nueva_nota_cuenta.php" class="btn-principal">+ Nueva nota</a>
        </div>

        <?php if (empty($notas)): ?>
        <div style="text-align:center;padding:48px 20px;color:var(--texto-secundario);">
            <p style="font-size:32px;margin:0 0 12px;">🧾</p>
            <p style="margin:0 0 20px;">Aún no hay notas de cuenta registradas.</p>
            <a href="nueva_nota_cuenta.php" class="btn-principal">Crear primera nota</a>
        </div>
        <?php else: ?>
        <div class="tabla-pedidos-wrapper">
            <table class="tabla-pedidos">
                <thead>
                    <tr>
                        <th>Folio</th>
                        <th>Cliente</th>
                        <th>Creada</th>
                        <th>Total</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($notas as $n): ?>
                    <tr>
                        <td>
                            <a href="ver_nota_cuenta.php?id=<?php echo (int)$n["id_nota"]; ?>"
                               class="btn-tabla" style="padding:6px 12px;font-size:13px;">
                                <?php echo htmlspecialchars($n["folio"]); ?>
                            </a>
                        </td>
                        <td><?php echo htmlspecialchars($n["nombre_cliente"]); ?></td>
                        <td><?php echo date("d/m/Y", strtotime($n["created_at"])); ?></td>
                        <td><strong><?php echo fmtPeso($n["total"]); ?></strong></td>
                        <td>
                            <span class="estado <?php echo $n["estado"] === "abierta" ? "estado-pendiente" : "estado-pagado"; ?>">
                                <?php echo $n["estado"] === "abierta" ? "Abierta" : "Cerrada"; ?>
                            </span>
                        </td>
                        <td style="display:flex;gap:8px;flex-wrap:wrap;">
                            <a href="ver_nota_cuenta.php?id=<?php echo (int)$n["id_nota"]; ?>"
                               class="btn-limpiar-filtros" style="font-size:12px;padding:5px 10px;">Ver</a>
                            <form method="POST" style="margin:0;">
                                <input type="hidden" name="cambiar_estado_id" value="<?php echo (int)$n["id_nota"]; ?>">
                                <input type="hidden" name="nuevo_estado" value="<?php echo $n["estado"] === "abierta" ? "cerrada" : "abierta"; ?>">
                                <button type="submit" class="btn-limpiar-filtros" style="font-size:12px;padding:5px 10px;">
                                    <?php echo $n["estado"] === "abierta" ? "Marcar pagada" : "Reabrir"; ?>
                                </button>
                            </form>
                            <form method="POST" onsubmit="return confirm('¿Eliminar la nota <?php echo htmlspecialchars($n["folio"]); ?>? Esta acción no se puede deshacer.');" style="margin:0;">
                                <input type="hidden" name="eliminar_id" value="<?php echo (int)$n["id_nota"]; ?>">
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
