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
    <meta name="theme-color" content="#151413">
    <link rel="preload" href="../assets/fonts/kaushan-script-400.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="../assets/fonts/alfa-slab-one-400.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="../assets/css/menu-pizarron.css?v=5">
    <link rel="stylesheet" href="../assets/css/encuestas-pizarron.css?v=1">
</head>
<body class="pz">
<?php require __DIR__ . "/../includes/pz_filtros.php"; ?>

<main class="ec">
    <header class="ec-cab">
        <div class="pz-marca">
            <img src="../assets/img/LOGO_N_128.png" alt="">
            <div>
                <span class="pz-marca__nombre">Zabisu</span>
                <span class="pz-marca__lema">Sabor y Servicio</span>
            </div>
        </div>
        <h1 class="pz-titulo"><span class="pz-pincel"><span>Encuestas</span></span></h1>
        <nav class="ec-cab__acc" aria-label="Navegación">
            <a href="panel_general.php" class="ec-btn">← Panel general</a>
        </nav>
    </header>
    <p class="ec-sub">Sugerencias y feedback de los clientes</p>

    <!-- FEEDBACK -->
    <section class="ec-sec">
        <h2 class="pz-cinta pz-fibra">Calificaciones</h2>

        <?php if ($totalFeedbacks === 0): ?>
            <p class="ec-vacio">Aún no hay calificaciones registradas.</p>
        <?php else: ?>

            <?php
                $prom = (float)($statsGlobal["promedio"] ?? 0);
                $etiqueta = match(true) {
                    $prom >= 4.5 => ["label" => "Excelente", "nivel" => "excelente"],
                    $prom >= 3.5 => ["label" => "Bueno",     "nivel" => "bueno"],
                    $prom >= 2.5 => ["label" => "Regular",   "nivel" => "regular"],
                    default      => ["label" => "Mejorable", "nivel" => "mejorable"],
                };
            ?>

            <!-- Resumen global -->
            <div class="ec-resumen">
                <div class="ec-promedio ec-nivel--<?php echo $etiqueta['nivel']; ?>">
                    <span class="ec-promedio__num"><?php echo number_format($prom, 1); ?></span>
                    <span class="ec-promedio__etiqueta"><?php echo $etiqueta['label']; ?></span>
                    <div class="ec-estrellas" aria-label="<?php echo number_format($prom, 1); ?> de 5">
                        <?php for ($s = 1; $s <= 5; $s++): ?>
                            <span class="<?php echo $s <= round($prom) ? 'on' : ''; ?>">★</span>
                        <?php endfor; ?>
                    </div>
                    <span class="ec-promedio__total"><?php echo $totalFeedbacks; ?> <?php echo $totalFeedbacks !== 1 ? "opiniones" : "opinión"; ?></span>
                </div>

                <div class="ec-dist">
                    <?php for ($s = 5; $s >= 1; $s--):
                        $cnt = $distribucion[$s] ?? 0;
                        $pct = $totalFeedbacks > 0 ? round($cnt / $totalFeedbacks * 100) : 0;
                    ?>
                        <div class="ec-dist__fila ec-nivel--<?php echo $s; ?>">
                            <span class="ec-dist__label"><?php echo $s; ?> ★</span>
                            <div class="ec-dist__track">
                                <div class="ec-dist__fill" style="width:<?php echo $pct; ?>%;"></div>
                            </div>
                            <span class="ec-dist__pct"><?php echo $pct; ?>%</span>
                            <span class="ec-dist__cnt">(<?php echo $cnt; ?>)</span>
                        </div>
                    <?php endfor; ?>
                </div>
            </div>

            <!-- Tarjetas de feedback -->
            <?php if (!empty($feedbacks)): ?>
            <div class="ec-opiniones">
                <?php foreach ($feedbacks as $fb):
                    $cal = (int)$fb["calificacion"];
                ?>
                <article class="ec-op ec-nivel--<?php echo $cal; ?>">
                    <div class="ec-op__top">
                        <div class="ec-op__cal">
                            <span class="ec-op__num"><?php echo $cal; ?><small>/5</small></span>
                            <div class="ec-estrellas ec-estrellas--chicas" aria-label="<?php echo $cal; ?> de 5">
                                <?php for ($s = 1; $s <= 5; $s++): ?>
                                    <span class="<?php echo $s <= $cal ? 'on' : ''; ?>">★</span>
                                <?php endfor; ?>
                            </div>
                        </div>
                        <div class="ec-op__meta">
                            <?php if (!empty($fb["folio"])): ?>
                                <span class="ec-op__folio"><?php echo htmlspecialchars($fb["folio"]); ?></span>
                            <?php endif; ?>
                            <span class="ec-op__fecha"><?php echo date("d/m/Y · g:i A", strtotime($fb["created_at"])); ?></span>
                        </div>
                    </div>
                    <?php if (!empty($fb["comentario"])): ?>
                        <p class="ec-op__comentario">"<?php echo htmlspecialchars($fb["comentario"]); ?>"</p>
                    <?php endif; ?>
                </article>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

        <?php endif; ?>
    </section>

    <!-- SUGERENCIAS -->
    <section class="ec-sec">
        <h2 class="pz-cinta pz-fibra">Sugerencias de plato fuerte</h2>

        <?php if (empty($sugerenciasPorFecha)): ?>
            <p class="ec-vacio">Aún no hay sugerencias registradas.</p>
        <?php else: ?>
            <?php foreach ($sugerenciasPorFecha as $fecha => $lista): ?>
                <?php
                    $ts    = strtotime($fecha);
                    $dias  = ['Sunday'=>'Domingo','Monday'=>'Lunes','Tuesday'=>'Martes','Wednesday'=>'Miércoles','Thursday'=>'Jueves','Friday'=>'Viernes','Saturday'=>'Sábado'];
                    $meses = ['01'=>'enero','02'=>'febrero','03'=>'marzo','04'=>'abril','05'=>'mayo','06'=>'junio','07'=>'julio','08'=>'agosto','09'=>'septiembre','10'=>'octubre','11'=>'noviembre','12'=>'diciembre'];
                    $fechaLabel = ($dias[date('l', $ts)] ?? '') . " " . date('j', $ts) . " de " . ($meses[date('m', $ts)] ?? '');
                    $esHoy = $fecha === date("Y-m-d");
                ?>
                <div class="ec-sug-dia">
                    <p class="ec-sug-dia__fecha">
                        <?php if ($esHoy): ?><b>Hoy</b><?php endif; ?>
                        <?php echo htmlspecialchars($fechaLabel); ?>
                        <small>(<?php echo count($lista); ?>)</small>
                    </p>
                    <ul class="ec-sug">
                        <?php foreach ($lista as $sug): ?>
                            <li><?php echo htmlspecialchars($sug["sugerencia"]); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>

</main>
</body>
</html>
