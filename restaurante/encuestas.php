<?php
require_once "../config/db.php";
require_once "auth_check.php";

/* ── Sugerencias de hoy y días anteriores ── */
$stmtSug = $conexion->prepare(
    "SELECT fecha, sugerencia, created_at
     FROM sugerencias_plato
     ORDER BY created_at DESC
     LIMIT 100"
);
$stmtSug->execute();
$sugerencias = $stmtSug->fetchAll(PDO::FETCH_ASSOC);

$sugerenciasPorFecha = [];
foreach ($sugerencias as $s) {
    $sugerenciasPorFecha[$s["fecha"]][] = $s;
}

/* ── Feedback: promedio y listado reciente ── */
$stmtProm = $conexion->prepare(
    "SELECT COUNT(*) AS total, ROUND(AVG(calificacion), 1) AS promedio FROM feedback_pedidos"
);
$stmtProm->execute();
$statsGlobal = $stmtProm->fetch(PDO::FETCH_ASSOC);

$stmtFeed = $conexion->prepare(
    "SELECT folio, calificacion, comentario, created_at
     FROM feedback_pedidos
     ORDER BY created_at DESC
     LIMIT 50"
);
$stmtFeed->execute();
$feedbacks = $stmtFeed->fetchAll(PDO::FETCH_ASSOC);

/* ── Distribución por estrellas ── */
$stmtDist = $conexion->prepare(
    "SELECT calificacion, COUNT(*) AS total
     FROM feedback_pedidos
     GROUP BY calificacion
     ORDER BY calificacion DESC"
);
$stmtDist->execute();
$distribucion = [];
foreach ($stmtDist->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $distribucion[(int)$row["calificacion"]] = (int)$row["total"];
}
$totalFeedbacks = (int)($statsGlobal["total"] ?? 0);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Encuestas | Restaurante Zabisu</title>
    <link rel="icon" type="image/png" href="../assets/img/LOGO_NARA.png">
    <link rel="stylesheet" href="../assets/css/styles.css">
</head>
<body>
<div class="contenedor">

    <div class="hero-zabisu">
        <div class="hero-zabisu__glow"></div>
        <div class="hero-zabisu__contenido">
            <p class="hero-zabisu__eyebrow">RESTAURANTE</p>
            <h1 class="hero-zabisu__titulo">Encuestas</h1>
            <p class="hero-zabisu__texto">Sugerencias y feedback de los clientes</p>
            <a href="panel_general.php" class="btn-volver-panel">← Panel general</a>
        </div>
    </div>

    <!-- FEEDBACK -->
    <div class="bloque-formulario">
        <h2>Calificaciones</h2>

        <?php if ($totalFeedbacks === 0): ?>
            <p class="nota-formulario">Aún no hay calificaciones registradas.</p>
        <?php else: ?>
            <div class="encuesta-stats">
                <div class="encuesta-promedio">
                    <span class="encuesta-promedio__numero"><?php echo $statsGlobal["promedio"]; ?></span>
                    <div>
                        <div class="encuesta-promedio__estrellas">
                            <?php
                            $prom = (float)$statsGlobal["promedio"];
                            for ($s = 1; $s <= 5; $s++) {
                                echo $s <= round($prom) ? '<span class="zb-estrella zb-estrella--activa">★</span>' : '<span class="zb-estrella">★</span>';
                            }
                            ?>
                        </div>
                        <span class="nota-formulario"><?php echo $totalFeedbacks; ?> opinión<?php echo $totalFeedbacks !== 1 ? "es" : ""; ?></span>
                    </div>
                </div>

                <div class="encuesta-distribucion">
                    <?php for ($s = 5; $s >= 1; $s--): ?>
                        <?php $cnt = $distribucion[$s] ?? 0; $pct = $totalFeedbacks > 0 ? round($cnt / $totalFeedbacks * 100) : 0; ?>
                        <div class="encuesta-dist__fila">
                            <span class="encuesta-dist__label"><?php echo $s; ?>★</span>
                            <div class="encuesta-dist__barra-track">
                                <div class="encuesta-dist__barra-fill" style="width:<?php echo $pct; ?>%"></div>
                            </div>
                            <span class="encuesta-dist__cnt"><?php echo $cnt; ?></span>
                        </div>
                    <?php endfor; ?>
                </div>
            </div>

            <?php if (!empty($feedbacks)): ?>
            <div style="margin-top:20px;">
                <?php foreach ($feedbacks as $fb): ?>
                <div class="encuesta-feedback-item">
                    <div class="encuesta-feedback-item__header">
                        <div>
                            <?php for ($s = 1; $s <= 5; $s++): ?>
                                <span class="zb-estrella <?php echo $s <= (int)$fb["calificacion"] ? 'zb-estrella--activa' : ''; ?>" style="font-size:16px;">★</span>
                            <?php endfor; ?>
                        </div>
                        <div style="display:flex;flex-direction:column;align-items:flex-end;gap:2px;">
                            <?php if (!empty($fb["folio"])): ?>
                                <span style="font-size:11px;color:var(--zabisu-orange);font-weight:700;"><?php echo htmlspecialchars($fb["folio"]); ?></span>
                            <?php endif; ?>
                            <span class="nota-formulario" style="font-size:11px;"><?php echo date("d/m/Y g:i A", strtotime($fb["created_at"])); ?></span>
                        </div>
                    </div>
                    <?php if (!empty($fb["comentario"])): ?>
                        <p class="encuesta-feedback-item__comentario"><?php echo htmlspecialchars($fb["comentario"]); ?></p>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- SUGERENCIAS -->
    <div class="bloque-formulario">
        <h2>Sugerencias de plato fuerte</h2>

        <?php if (empty($sugerenciasPorFecha)): ?>
            <p class="nota-formulario">Aún no hay sugerencias registradas.</p>
        <?php else: ?>
            <?php foreach ($sugerenciasPorFecha as $fecha => $lista): ?>
                <?php
                    $ts    = strtotime($fecha);
                    $dias  = ['Sunday'=>'Domingo','Monday'=>'Lunes','Tuesday'=>'Martes','Wednesday'=>'Miércoles','Thursday'=>'Jueves','Friday'=>'Viernes','Saturday'=>'Sábado'];
                    $meses = ['01'=>'enero','02'=>'febrero','03'=>'marzo','04'=>'abril','05'=>'mayo','06'=>'junio','07'=>'julio','08'=>'agosto','09'=>'septiembre','10'=>'octubre','11'=>'noviembre','12'=>'diciembre'];
                    $fechaLabel = ($dias[date('l', $ts)] ?? '') . " " . date('j', $ts) . " de " . ($meses[date('m', $ts)] ?? '');
                    $esHoy = $fecha === date("Y-m-d");
                ?>
                <div style="margin-bottom:18px;">
                    <p style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--zabisu-orange);margin:0 0 8px;">
                        <?php echo $esHoy ? "Hoy — " : ""; ?><?php echo htmlspecialchars($fechaLabel); ?>
                        <span style="color:var(--zabisu-white-muted);font-weight:400;text-transform:none;letter-spacing:0;">(<?php echo count($lista); ?>)</span>
                    </p>
                    <?php foreach ($lista as $sug): ?>
                        <div class="encuesta-sugerencia-item">
                            <span>💡</span>
                            <span><?php echo htmlspecialchars($sug["sugerencia"]); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</div>
</body>
</html>
