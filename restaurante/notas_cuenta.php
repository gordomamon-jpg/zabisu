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
    <?php require __DIR__ . "/../includes/rz_app.php"; ?>
    <title>Notas de cuenta | Zabisu</title>
    <link rel="icon" type="image/png" href="../assets/img/LOGO_NARA.png">
    <meta name="theme-color" content="#151413">
    <link rel="preload" href="../assets/fonts/kaushan-script-400.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="../assets/fonts/alfa-slab-one-400.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="../assets/css/menu-pizarron.css?v=5">
    <link rel="stylesheet" href="../assets/css/notas-pizarron.css?v=1">
</head>
<body class="pz">
<?php require __DIR__ . "/../includes/pz_filtros.php"; ?>

<main class="nc">
    <header class="nc-cab">
        <div class="pz-marca">
            <img src="../assets/img/LOGO_N_128.png" alt="">
            <div>
                <span class="pz-marca__nombre">Zabisu</span>
                <span class="pz-marca__lema">Sabor y Servicio</span>
            </div>
        </div>
        <h1 class="pz-titulo"><span class="pz-pincel"><span>Notas de cuenta</span></span></h1>
        <nav class="nc-cab__acc" aria-label="Acciones">
            <a href="nueva_nota_cuenta.php" class="nc-btn--naranja">+ Nueva nota</a>
            <a href="panel_general.php" class="nc-btn">← Panel general</a>
        </nav>
    </header>
    <p class="nc-sub">Para clientes que piden diario y pagan a fin de semana</p>

    <?php if (isset($_GET["eliminado"])): ?>
    <div class="nc-aviso">
        <span class="nc-aviso__icono">✓</span>
        <div><strong>Nota eliminada correctamente.</strong></div>
    </div>
    <?php endif; ?>

    <?php if (empty($notas)): ?>
    <div class="nc-vacio">
        <p>Aún no hay notas de cuenta registradas.</p>
        <a href="nueva_nota_cuenta.php" class="nc-btn--naranja">Crear primera nota</a>
    </div>
    <?php else: ?>
    <div class="nc-lista">
        <?php foreach ($notas as $n): ?>
        <?php $abierta = $n["estado"] === "abierta"; ?>
        <article class="nc-item<?php echo $abierta ? ' nc-item--abierta' : ''; ?>">
            <a href="ver_nota_cuenta.php?id=<?php echo (int)$n["id_nota"]; ?>" class="nc-item__folio">
                <?php echo htmlspecialchars($n["folio"]); ?>
            </a>
            <span class="estado <?php echo $abierta ? "estado-pendiente" : "estado-pagado"; ?>">
                <?php echo $abierta ? "Abierta" : "Cerrada"; ?>
            </span>
            <p class="nc-item__cliente"><?php echo htmlspecialchars($n["nombre_cliente"]); ?></p>
            <p class="nc-item__meta">Creada el <?php echo date("d/m/Y", strtotime($n["created_at"])); ?></p>
            <strong class="nc-item__total"><?php echo fmtPeso($n["total"]); ?></strong>
            <div class="nc-item__acc">
                <a href="ver_nota_cuenta.php?id=<?php echo (int)$n["id_nota"]; ?>" class="nc-btn nc-btn--chico">Ver</a>
                <form method="POST">
                    <input type="hidden" name="cambiar_estado_id" value="<?php echo (int)$n["id_nota"]; ?>">
                    <input type="hidden" name="nuevo_estado" value="<?php echo $abierta ? "cerrada" : "abierta"; ?>">
                    <button type="submit" class="nc-btn nc-btn--chico <?php echo $abierta ? 'nc-btn--estraza' : ''; ?>">
                        <?php echo $abierta ? "Marcar pagada" : "Reabrir"; ?>
                    </button>
                </form>
                <form method="POST" onsubmit="return confirm('¿Eliminar la nota <?php echo htmlspecialchars($n["folio"]); ?>? Esta acción no se puede deshacer.');">
                    <input type="hidden" name="eliminar_id" value="<?php echo (int)$n["id_nota"]; ?>">
                    <button type="submit" class="nc-btn nc-btn--chico nc-btn--eliminar">Eliminar</button>
                </form>
            </div>
        </article>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</main>
</body>
</html>
