<?php
/*
    Imagen del menú para redes (Instagram, Facebook, estado de WhatsApp).
    Dibuja con los platillos reales del menú dos imágenes en el navegador:
    - Historia     1080 × 1920 (deja libre arriba y abajo lo que tapa Instagram)
    - Publicación  1080 × 1350
    Cada una se descarga como PNG o, en el celular, se comparte directo.
    Se abre desde Gestión de menús → "Imagen para redes".
*/
require_once "../config/db.php";
require_once "auth_check.php";
date_default_timezone_set("America/Mexico_City");

$idMenu = (int)($_GET["id_menu"] ?? 0);
$st = $conexion->prepare("SELECT id_menu, fecha, pedido_hasta FROM menu_dia WHERE id_menu = :id LIMIT 1");
$st->execute([":id" => $idMenu]);
$menu = $st->fetch(PDO::FETCH_ASSOC);
if (!$menu) {
    http_response_code(404);
    exit("No se encontró el menú.");
}

$st = $conexion->prepare(
    "SELECT tipo_menu, categoria, nombre FROM productos
      WHERE id_menu = :id AND disponible = 1
      ORDER BY tipo_menu, categoria, id_producto"
);
$st->execute([":id" => $idMenu]);

$platos = ["Zabisu" => [], "Ejecutivo" => []];
$sopa = ""; $complementos = []; $agua = ""; $cortesia = "";
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
    $n = trim($p["nombre"]);
    switch ($p["categoria"]) {
        case "Plato fuerte":
            $platos[$p["tipo_menu"]][] = $n;
            break;
        case "Sopa":
            if ($sopa === "") $sopa = $n;
            break;
        case "Complemento":
            if (!in_array($n, $complementos, true)) $complementos[] = $n;
            break;
        case "Agua":
            if ($agua === "" && mb_strtolower($n, "UTF-8") !== "agua natural") $agua = $n;
            break;
        case "Cortesia":
            if ($cortesia === "") $cortesia = $n;
            break;
    }
}
// "Cortesía Zabisu" es el nombre genérico: se muestra como "un detalle dulce"
if ($cortesia === "" || preg_match('/^cortes[ií]a/iu', $cortesia)) $cortesia = "";

$precios = [];
foreach ($conexion->query("SELECT nombre_menu, precio FROM tipos_menu WHERE activo = 1") as $t) {
    $precios[$t["nombre_menu"]] = (float)$t["precio"];
}

$dias  = ["domingo", "lunes", "martes", "miércoles", "jueves", "viernes", "sábado"];
$ts    = strtotime($menu["fecha"]);
$cierre = strtotime($menu["pedido_hasta"]);
$datos = [
    "titulo"   => "Menú del " . $dias[(int)date("w", $ts)] . " " . date("j", $ts),
    "archivo"  => "zabisu-menu-" . date("Y-m-d", $ts),
    "zabisu"   => ["precio" => (int)($precios["Zabisu"] ?? 110), "platos" => $platos["Zabisu"]],
    "ejecutivo"=> ["precio" => (int)($precios["Ejecutivo"] ?? 120), "platos" => $platos["Ejecutivo"]],
    "sopa"     => $sopa,
    "complementos" => $complementos,
    "agua"     => $agua,
    "cortesia" => $cortesia,
    "cierre"   => date("g:i", $cierre) . " " . (date("A", $cierre) === "AM" ? "am" : "pm"),
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require __DIR__ . "/../includes/rz_app.php"; ?>
    <title>Imagen para redes | Restaurante Zabisu</title>
    <link rel="icon" type="image/png" href="../assets/img/LOGO_NARA.png">
    <meta name="theme-color" content="#151413">
    <link rel="stylesheet" href="../assets/css/menu-pizarron.css?v=5">
    <link rel="stylesheet" href="../assets/css/menus-pizarron.css?v=5">
    <style>
    .mi-pag{max-width:980px;margin:0 auto;padding:18px 20px 60px}
    .mi-sub{margin:10px 0 0;font:400 16px/1.45 var(--pz-sys);color:var(--pz-gris)}
    .mi-grid{display:grid;gap:28px;margin-top:26px}
    @media (min-width:760px){ .mi-grid{grid-template-columns:repeat(2,minmax(0,1fr));align-items:start} }
    .mi-item{background:var(--pz-grupo);border-radius:20px;padding:16px}
    .mi-item h2{margin:0 0 12px;font:400 18px var(--pz-rotulo);color:var(--pz-gis)}
    .mi-item h2 span{font:400 14px var(--pz-sys);color:var(--pz-gris)}
    .mi-item canvas{display:block;width:100%;height:auto;border-radius:12px;background:#151413}
    .mi-acc{display:flex;flex-wrap:wrap;gap:10px;margin-top:14px}
    .mi-btn{flex:1;min-width:140px;min-height:48px;border:0;border-radius:12px;font:700 15px var(--pz-sys);cursor:pointer}
    .mi-btn--prin{background:var(--pz-naranja);color:#111}
    .mi-btn--sec{background:transparent;color:var(--pz-gis);border:1.5px solid var(--pz-linea)}
    .mi-btn[hidden]{display:none}
    .mi-volver{display:inline-block;margin-top:14px}
    </style>
</head>
<body class="pz">
<main class="mi-pag">
    <h1 class="pz-titulo"><span class="pz-pincel"><span>Imagen para redes</span></span></h1>
    <p class="mi-sub"><?php echo htmlspecialchars(ucfirst($datos["titulo"])); ?> · Se arma sola con los platillos del menú. Descárgala o compártela directo a Instagram o WhatsApp.</p>
    <a class="btn-volver-panel mi-volver" href="menus.php">← Gestión de menús</a>

    <div class="mi-grid">
        <section class="mi-item">
            <h2>Historia <span>· 1080 × 1920</span></h2>
            <canvas id="c-historia" width="1080" height="1920" aria-label="Vista previa de la historia del menú"></canvas>
            <div class="mi-acc">
                <button type="button" class="mi-btn mi-btn--prin" data-compartir="historia" hidden>Compartir</button>
                <button type="button" class="mi-btn mi-btn--sec" data-descargar="historia">Descargar</button>
            </div>
        </section>
        <section class="mi-item">
            <h2>Publicación <span>· 1080 × 1350</span></h2>
            <canvas id="c-post" width="1080" height="1350" aria-label="Vista previa de la publicación del menú"></canvas>
            <div class="mi-acc">
                <button type="button" class="mi-btn mi-btn--prin" data-compartir="post" hidden>Compartir</button>
                <button type="button" class="mi-btn mi-btn--sec" data-descargar="post">Descargar</button>
            </div>
        </section>
    </div>
</main>

<script>
(function () {
    var D = <?php echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    var C = { pizarra: "#151413", grupo: "#201E1C", linea: "#2F2C29", gis: "#ECEBE7", gris: "#A5A29B",
              naranja: "#FF7A00", estraza: "#C49A6C", estrazaCl: "#D9B88F", tinta: "#22180F" };
    var SYS = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif";
    var logo = new Image();
    logo.src = "../assets/img/LOGO_NARA.png";

    var fuentes = [
        new FontFace("ZbRotulo", "url(../assets/fonts/alfa-slab-one-400.woff2)"),
        new FontFace("ZbPincel", "url(../assets/fonts/kaushan-script-400.woff2)")
    ];

    // Parte el texto en renglones que quepan en "ancho"
    function renglones(ctx, texto, ancho) {
        var palabras = String(texto).split(/\s+/), out = [], linea = "";
        palabras.forEach(function (p) {
            var prueba = linea ? linea + " " + p : p;
            if (ctx.measureText(prueba).width > ancho && linea) { out.push(linea); linea = p; }
            else linea = prueba;
        });
        if (linea) out.push(linea);
        return out;
    }

    function rect(ctx, x, y, w, h, r, color) {
        ctx.beginPath();
        ctx.moveTo(x + r, y); ctx.arcTo(x + w, y, x + w, y + h, r); ctx.arcTo(x + w, y + h, x, y + h, r);
        ctx.arcTo(x, y + h, x, y, r); ctx.arcTo(x, y, x + w, y, r); ctx.closePath();
        ctx.fillStyle = color; ctx.fill();
    }

    /*
        Dibuja (o solo mide, si dibujar=false) el menú entre "arriba" y "abajo",
        con todo escalado por "s". Devuelve la altura que ocupa.
    */
    function componer(ctx, W, arriba, s, dibujar) {
        var x = 80, ancho = W - 160, y = arriba;
        function f(px) { return Math.round(px * s); }

        // Marca
        if (dibujar && logo.complete) ctx.drawImage(logo, x, y, f(84), f(84));
        if (dibujar) {
            ctx.fillStyle = C.gis; ctx.font = f(44) + "px ZbRotulo"; ctx.textBaseline = "alphabetic";
            ctx.fillText("Zabisu", x + f(102), y + f(42));
            ctx.fillStyle = C.estrazaCl; ctx.font = f(34) + "px ZbPincel";
            ctx.fillText("Sabor y Servicio", x + f(102), y + f(80));
        }
        y += f(84) + f(44);

        // Título con pincel
        ctx.font = f(92) + "px ZbPincel";
        var tw = ctx.measureText(D.titulo).width, th = f(118);
        if (dibujar) {
            ctx.save(); ctx.translate(x, y); ctx.rotate(-1.5 * Math.PI / 180);
            ctx.fillStyle = C.naranja; ctx.fillRect(0, 0, tw + f(56), th);
            ctx.fillStyle = "#111"; ctx.textBaseline = "middle";
            ctx.fillText(D.titulo, f(26), th / 2 + f(4));
            ctx.restore(); ctx.textBaseline = "alphabetic";
        }
        y += th + f(46);

        // Un bloque por menú (Zabisu / Ejecutivo)
        [["Zabisu", D.zabisu], ["Ejecutivo", D.ejecutivo]].forEach(function (par, idx) {
            var nombre = par[0], m = par[1];
            if (!m.platos.length) return;
            ctx.font = f(46) + "px ZbRotulo";
            if (dibujar) {
                ctx.fillStyle = C.gis; ctx.fillText(nombre, x, y + f(44));
                var nw = ctx.measureText(nombre + "  ").width;
                ctx.fillStyle = C.naranja; ctx.fillText("$" + m.precio, x + nw, y + f(44));
            }
            y += f(44) + f(24);
            ctx.font = "600 " + f(40) + "px " + SYS;
            m.platos.forEach(function (p, i) {
                var ls = renglones(ctx, p, ancho - f(40));
                var alto = ls.length * f(50);
                if (dibujar) {
                    ctx.save(); ctx.translate(x + f(5), y + alto / 2 - f(2)); ctx.rotate([-3, 2, -1, 3][i % 4] * Math.PI / 180);
                    ctx.fillStyle = C.estraza; ctx.fillRect(-f(5), -f(20), f(10), f(40)); ctx.restore();
                    ctx.fillStyle = C.gis;
                    ls.forEach(function (l, k) { ctx.fillText(l, x + f(36), y + f(38) + k * f(50)); });
                }
                y += alto + f(16);
            });
            y += f(30);
        });

        // Lo que incluye
        var filas = [];
        if (D.sopa) filas.push(["Sopa", D.sopa]);
        if (D.complementos.length) filas.push(["Complementos · elige hasta 2", D.complementos.join(" · ")]);
        filas.push(["Agua", (D.agua ? D.agua + " o natural" : "Natural")]);
        filas.push(["Cortesía", D.cortesia || "Un detalle dulce de la casa"]);

        var pad = f(34), interior = ancho - pad * 2, altoCaja = pad;
        var medidas = filas.map(function (r) {
            ctx.font = "400 " + f(34) + "px " + SYS;
            var ls = renglones(ctx, r[1], interior);
            var h = f(30) + f(12) + ls.length * f(44);
            return { t: r[0], ls: ls, h: h };
        });
        medidas.forEach(function (m, i) { altoCaja += m.h + (i ? f(22) : 0); });
        altoCaja += pad;
        if (dibujar) {
            rect(ctx, x, y, ancho, altoCaja, f(32), C.grupo);
            var yy = y + pad;
            medidas.forEach(function (m, i) {
                if (i) { ctx.fillStyle = C.linea; ctx.fillRect(x + pad, yy + f(4), interior, f(2)); yy += f(22); }
                ctx.fillStyle = C.estrazaCl; ctx.font = f(28) + "px ZbRotulo";
                ctx.fillText(m.t, x + pad, yy + f(28));
                ctx.fillStyle = C.gis; ctx.font = "400 " + f(34) + "px " + SYS;
                m.ls.forEach(function (l, k) { ctx.fillText(l, x + pad, yy + f(30) + f(12) + f(34) + k * f(44)); });
                yy += m.h;
            });
        }
        y += altoCaja;
        return y - arriba;
    }

    function dibujar(canvas, arriba, abajo) {
        var ctx = canvas.getContext("2d"), W = canvas.width, H = canvas.height;
        ctx.fillStyle = C.pizarra; ctx.fillRect(0, 0, W, H);

        // Franja de abajo: hora de cierre y página
        var franja = 120, yF = H - abajo - franja;
        var disponible = yF - arriba - 40;

        // Escala para que todo quepa: crece hasta 1.25 si sobra lugar, se achica si no cabe
        var s = 1.25;
        for (var i = 0; i < 40 && componer(ctx, W, arriba, s, false) > disponible; i++) s -= 0.025;
        componer(ctx, W, arriba, s, true);

        rect(ctx, 80, yF, W - 160, franja, 28, C.naranja);
        ctx.fillStyle = "#111"; ctx.textAlign = "center"; ctx.textBaseline = "middle";
        ctx.font = "700 44px " + SYS;
        ctx.fillText("Pide antes de las " + D.cierre + " · zabisu.com", W / 2, yF + franja / 2 + 2);
        ctx.textAlign = "left"; ctx.textBaseline = "alphabetic";
    }

    var lienzos = { historia: document.getElementById("c-historia"), post: document.getElementById("c-post") };

    function todo() {
        dibujar(lienzos.historia, 250, 330);   // zona segura de historias
        dibujar(lienzos.post, 80, 70);
    }

    Promise.all(fuentes.map(function (fu) { return fu.load().then(function (x) { document.fonts.add(x); }).catch(function () {}); }))
        .then(function () { return logo.decode ? logo.decode().catch(function () {}) : null; })
        .then(todo);

    function archivo(tipo) {
        return new Promise(function (ok) {
            lienzos[tipo].toBlob(function (b) {
                ok(new File([b], D.archivo + "-" + tipo + ".png", { type: "image/png" }));
            }, "image/png");
        });
    }

    document.querySelectorAll("[data-descargar]").forEach(function (b) {
        b.addEventListener("click", function () {
            archivo(b.dataset.descargar).then(function (f) {
                var a = document.createElement("a");
                a.href = URL.createObjectURL(f); a.download = f.name;
                document.body.appendChild(a); a.click(); a.remove();
                setTimeout(function () { URL.revokeObjectURL(a.href); }, 4000);
            });
        });
    });

    // Compartir directo (celular): solo si el navegador permite compartir archivos
    var prueba = new File([new Blob()], "x.png", { type: "image/png" });
    if (navigator.canShare && navigator.canShare({ files: [prueba] })) {
        document.querySelectorAll("[data-compartir]").forEach(function (b) {
            b.hidden = false;
            b.addEventListener("click", function () {
                archivo(b.dataset.compartir).then(function (f) {
                    navigator.share({ files: [f], title: D.titulo }).catch(function () {});
                });
            });
        });
    }
})();
</script>
</body>
</html>
