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
    <link rel="stylesheet" href="../assets/css/styles.css?v=<?php echo CSS_VERSION; ?>">
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

            <?php
                $prom = (float)($statsGlobal["promedio"] ?? 0);
                $etiqueta = match(true) {
                    $prom >= 4.5 => ["label" => "Excelente", "color" => "#4ac86e"],
                    $prom >= 3.5 => ["label" => "Bueno",     "color" => "#a3e635"],
                    $prom >= 2.5 => ["label" => "Regular",   "color" => "#facc15"],
                    default      => ["label" => "Mejorable", "color" => "#f87171"],
                };
            ?>

            <!-- Resumen global -->
            <div class="encuesta-resumen-global">
                <div class="encuesta-resumen-global__izq">
                    <span class="encuesta-resumen-global__num" style="color:<?php echo $etiqueta['color']; ?>">
                        <?php echo number_format($prom, 1); ?>
                    </span>
                    <span class="encuesta-resumen-global__etiqueta" style="color:<?php echo $etiqueta['color']; ?>">
                        <?php echo $etiqueta['label']; ?>
                    </span>
                    <div class="encuesta-resumen-global__estrellas">
                        <?php for ($s = 1; $s <= 5; $s++): ?>
                            <span class="encuesta-estrella-display <?php echo $s <= round($prom) ? 'encuesta-estrella-display--activa' : ''; ?>">★</span>
                        <?php endfor; ?>
                    </div>
                    <span class="nota-formulario" style="font-size:12px;"><?php echo $totalFeedbacks; ?> opinión<?php echo $totalFeedbacks !== 1 ? "es" : ""; ?></span>
                </div>

                <div class="encuesta-distribucion">
                    <?php for ($s = 5; $s >= 1; $s--):
                        $cnt = $distribucion[$s] ?? 0;
                        $pct = $totalFeedbacks > 0 ? round($cnt / $totalFeedbacks * 100) : 0;
                        $barColor = match(true) {
                            $s >= 4 => "#4ac86e",
                            $s === 3 => "#facc15",
                            default  => "#f87171",
                        };
                    ?>
                        <div class="encuesta-dist__fila">
                            <span class="encuesta-dist__label"><?php echo $s; ?> ★</span>
                            <div class="encuesta-dist__barra-track">
                                <div class="encuesta-dist__barra-fill" style="width:<?php echo $pct; ?>%; background:<?php echo $barColor; ?>;"></div>
                            </div>
                            <span class="encuesta-dist__pct"><?php echo $pct; ?>%</span>
                            <span class="encuesta-dist__cnt">(<?php echo $cnt; ?>)</span>
                        </div>
                    <?php endfor; ?>
                </div>
            </div>

            <!-- Tarjetas de feedback -->
            <?php if (!empty($feedbacks)): ?>
            <div class="encuesta-feedback-lista">
                <?php foreach ($feedbacks as $fb):
                    $cal = (int)$fb["calificacion"];
                    $accentColor = match(true) {
                        $cal >= 4 => "#4ac86e",
                        $cal === 3 => "#facc15",
                        default   => "#f87171",
                    };
                    $emojis = [1 => "😞", 2 => "😕", 3 => "😐", 4 => "😊", 5 => "🤩"];
                ?>
                <div class="encuesta-feedback-card" style="border-left-color: <?php echo $accentColor; ?>">
                    <div class="encuesta-feedback-card__top">
                        <div class="encuesta-feedback-card__izq">
                            <span class="encuesta-feedback-card__emoji"><?php echo $emojis[$cal]; ?></span>
                            <div>
                                <div class="encuesta-feedback-card__estrellas">
                                    <?php for ($s = 1; $s <= 5; $s++): ?>
                                        <span style="color: <?php echo $s <= $cal ? $accentColor : 'rgba(255,255,255,0.15)'; ?>; font-size:15px;">★</span>
                                    <?php endfor; ?>
                                </div>
                                <span class="encuesta-feedback-card__cal" style="color:<?php echo $accentColor; ?>">
                                    <?php echo $cal; ?>/5
                                </span>
                            </div>
                        </div>
                        <div class="encuesta-feedback-card__meta">
                            <?php if (!empty($fb["folio"])): ?>
                                <span class="encuesta-feedback-card__folio"><?php echo htmlspecialchars($fb["folio"]); ?></span>
                            <?php endif; ?>
                            <span class="encuesta-feedback-card__fecha"><?php echo date("d/m/Y · g:i A", strtotime($fb["created_at"])); ?></span>
                        </div>
                    </div>
                    <?php if (!empty($fb["comentario"])): ?>
                        <p class="encuesta-feedback-card__comentario">"<?php echo htmlspecialchars($fb["comentario"]); ?>"</p>
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
