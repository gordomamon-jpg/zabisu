<?php
/*
    Inventario desechable: escáner con la cámara del celular.
      ?modo=recibir  → lo que llegó se SUMA al stock (paquetes × piezas)
      ?modo=contar   → el stock de lo contado queda en ese número (corrige el sistema)
    También acepta pistola lectora USB o escribir el código a mano.
*/
require_once "../config/db.php";
require_once "auth_check.php";
require_once "../includes/inventario.php";

inventarioPrepararTablas($conexion);

$modo = ($_GET["modo"] ?? "") === "contar" ? "contar" : "recibir";

// Artículos, con el tamaño de paquete que más se ha registrado (para sugerirlo)
$articulos = $conexion->query(
    "SELECT i.id_item, i.nombre, i.stock_actual,
            (SELECT c.piezas FROM inventario_codigos c WHERE c.id_item = i.id_item
              GROUP BY c.piezas ORDER BY COUNT(*) DESC, c.piezas DESC LIMIT 1) AS piezas_sugeridas
       FROM inventario_desechable i
      ORDER BY i.id_item"
)->fetchAll(PDO::FETCH_ASSOC);

$articulosJs = array_map(fn($a) => [
    "id"     => (int)$a["id_item"],
    "nombre" => $a["nombre"],
    "stock"  => (int)$a["stock_actual"],
    "piezas" => $a["piezas_sugeridas"] !== null ? (int)$a["piezas_sugeridas"] : null,
], $articulos);

$titulo = $modo === "recibir" ? "Recibir mercancía" : "Contar lo que hay";
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php require __DIR__ . "/../includes/rz_app.php"; ?>
    <title><?php echo $titulo; ?> | Zabisu</title>
    <link rel="icon" type="image/png" href="../assets/img/LOGO_NARA.png">
    <meta name="theme-color" content="#151413">
    <link rel="preload" href="../assets/fonts/kaushan-script-400.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="../assets/fonts/alfa-slab-one-400.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="../assets/css/menu-pizarron.css?v=5">
    <link rel="stylesheet" href="../assets/css/inventario-pizarron.css?v=2">
    <!-- ZXing: lector de códigos para los navegadores sin lector propio (iPhone) -->
    <script src="https://cdn.jsdelivr.net/npm/@zxing/library@0.21.3/umd/index.min.js" defer></script>
</head>
<body class="pz iv-escaner" data-modo="<?php echo $modo; ?>">
<?php require __DIR__ . "/../includes/pz_filtros.php"; ?>

<main class="iv iv--escaner">
    <header class="iv-cab iv-cab--escaner">
        <a href="inventario.php" class="iv-btn iv-btn--icono" aria-label="Volver a inventario">←</a>
        <h1 class="pz-titulo"><span class="pz-pincel"><span><?php echo $titulo; ?></span></span></h1>
    </header>
    <p class="iv-sub">
        <?php if ($modo === "recibir"): ?>
            Escanea cada paquete que llegó. Se suma al stock al guardar.
        <?php else: ?>
            Escanea o elige lo que hay en el almacén. El stock de lo contado queda en ese número.
        <?php endif; ?>
    </p>

    <!-- Cámara -->
    <section class="iv-camara" id="camara">
        <video id="lector" class="iv-camara__video" playsinline muted autoplay></video>
        <div class="iv-camara__marco" aria-hidden="true"><i></i></div>
        <div class="iv-camara__controles" id="camara-controles" hidden>
            <div class="iv-zoom" id="zoom" hidden role="group" aria-label="Zoom"></div>
            <button type="button" class="iv-linterna" id="btn-linterna" hidden aria-pressed="false" aria-label="Linterna">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 2h10v4l-2 3v13H9V9L7 6z"/><path d="M12 13v3"/></svg>
            </button>
        </div>
        <div class="iv-camara__inicio" id="camara-inicio">
            <button type="button" class="iv-btn iv-btn--naranja iv-btn--grande" id="btn-camara">Abrir cámara</button>
            <p>El celular te pedirá permiso para usar la cámara.</p>
        </div>
        <p class="iv-camara__estado" id="camara-estado" hidden>Apunta al código de barras</p>
    </section>

    <!-- Sin cámara: escribir el código o usar pistola lectora -->
    <form class="iv-manual" id="form-manual" autocomplete="off">
        <input type="text" id="codigo-manual" inputmode="text" enterkeyhint="search" placeholder="Código de barras" aria-label="Código de barras">
        <button type="submit" class="iv-btn">Buscar</button>
    </form>
    <button type="button" class="iv-btn iv-btn--linea" id="btn-sin-codigo">Elegir producto sin código</button>

    <!-- Último escaneado -->
    <section class="iv-ultimo" id="ultimo" hidden>
        <p class="iv-ultimo__etiqueta">Último escaneado</p>
        <p class="iv-ultimo__nombre" id="ultimo-nombre"></p>
        <p class="iv-ultimo__paquete" id="ultimo-paquete"></p>
        <div class="iv-contador">
            <button type="button" class="iv-contador__btn" id="ultimo-menos" aria-label="Un paquete menos">−</button>
            <span class="iv-contador__num"><b id="ultimo-paquetes">1</b><small>paquetes</small></span>
            <button type="button" class="iv-contador__btn iv-contador__btn--mas" id="ultimo-mas" aria-label="Un paquete más">+</button>
        </div>
        <p class="iv-ultimo__total" id="ultimo-total"></p>
    </section>

    <!-- Lo escaneado -->
    <section class="iv-sec">
        <h2 class="pz-cinta pz-fibra"><?php echo $modo === "recibir" ? "Lo que llegó" : "Lo contado"; ?></h2>
        <p class="iv-vacio" id="lista-vacia">Todavía no hay nada. Escanea el primer paquete.</p>
        <ul class="iv-lista" id="lista"></ul>
    </section>

    <!-- Resultado al guardar -->
    <section class="iv-hecho" id="hecho" hidden>
        <p class="iv-hecho__titulo" id="hecho-titulo"></p>
        <ul class="iv-hecho__lista" id="hecho-lista"></ul>
        <div class="iv-hecho__acc">
            <a href="inventario_escanear.php?modo=<?php echo $modo; ?>" class="iv-btn iv-btn--naranja"><?php echo $modo === "recibir" ? "Recibir más" : "Contar otra vez"; ?></a>
            <a href="inventario.php" class="iv-btn">Ver inventario</a>
        </div>
    </section>
</main>

<!-- Barra para guardar -->
<div class="iv-pie" id="pie">
    <p class="iv-pie__resumen" id="pie-resumen">0 productos</p>
    <button type="button" class="iv-btn iv-btn--naranja" id="btn-guardar" disabled>
        <?php echo $modo === "recibir" ? "Guardar entrada" : "Guardar conteo"; ?>
    </button>
</div>

<!-- Hoja: código nuevo o producto sin código -->
<div class="iv-hoja-fondo" id="hoja" hidden>
    <div class="iv-hoja" role="dialog" aria-modal="true" aria-labelledby="hoja-titulo">
        <div id="hoja-paso1">
            <p class="iv-hoja__eyebrow" id="hoja-codigo"></p>
            <h2 class="iv-hoja__titulo" id="hoja-titulo">¿Qué producto es?</h2>
            <div class="iv-hoja__productos" id="hoja-productos"></div>
        </div>
        <div id="hoja-paso2" hidden>
            <p class="iv-hoja__eyebrow" id="hoja-producto"></p>
            <h2 class="iv-hoja__titulo">¿Cuántas piezas trae cada paquete?</h2>
            <input type="number" id="hoja-piezas" min="1" max="100000" inputmode="numeric" placeholder="Ej. 100">
            <div class="iv-hoja__chips" id="hoja-chips"></div>
            <p class="iv-hoja__nota" id="hoja-nota"></p>
            <button type="button" class="iv-btn iv-btn--naranja iv-btn--grande" id="hoja-guardar">Listo</button>
        </div>
        <button type="button" class="iv-btn iv-btn--linea" id="hoja-cancelar">Cancelar</button>
    </div>
</div>

<p class="iv-toast" id="toast" role="status" hidden></p>

<script>
(function () {
    var MODO      = document.body.dataset.modo;
    var ARTICULOS = <?php echo json_encode($articulosJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    var porId = {};
    ARTICULOS.forEach(function (a) { porId[a.id] = a; });

    // Líneas: una por artículo y tamaño de paquete. En "contar" además piezas sueltas por artículo.
    var lineas  = [];
    var sueltas = {};
    var ultima  = null;
    var CLAVE   = "zb_inventario_" + MODO;

    function guardarBorrador() {
        try { sessionStorage.setItem(CLAVE, JSON.stringify({ lineas: lineas, sueltas: sueltas })); } catch (e) {}
    }
    function leerBorrador() {
        try {
            var d = JSON.parse(sessionStorage.getItem(CLAVE) || "null");
            if (d && Array.isArray(d.lineas)) { lineas = d.lineas.filter(function (l) { return porId[l.id_item]; }); sueltas = d.sueltas || {}; }
        } catch (e) {}
    }
    function borrarBorrador() { try { sessionStorage.removeItem(CLAVE); } catch (e) {} }

    function num(n) { return Number(n).toLocaleString("es-MX"); }
    function $(id) { return document.getElementById(id); }

    function toast(texto, error) {
        var t = $("toast");
        t.textContent = texto;
        t.classList.toggle("iv-toast--error", !!error);
        t.hidden = false;
        clearTimeout(toast.t);
        toast.t = setTimeout(function () { t.hidden = true; }, 2600);
    }

    // Bip corto al leer un código (y vibración si el celular puede)
    var audioCtx = null;
    function bip(ok) {
        try {
            audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
            var o = audioCtx.createOscillator(), g = audioCtx.createGain();
            o.frequency.value = ok ? 1250 : 330;
            g.gain.setValueAtTime(0.18, audioCtx.currentTime);
            g.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + (ok ? 0.12 : 0.3));
            o.connect(g); g.connect(audioCtx.destination);
            o.start(); o.stop(audioCtx.currentTime + (ok ? 0.12 : 0.3));
        } catch (e) {}
        if (navigator.vibrate) navigator.vibrate(ok ? 40 : [60, 60, 60]);
    }

    function totalLinea(l) { return l.paquetes * l.piezas; }
    function totalArticulo(id) {
        var t = 0;
        lineas.forEach(function (l) { if (l.id_item === id) t += totalLinea(l); });
        return t + (parseInt(sueltas[id], 10) || 0);
    }

    function agregar(idItem, piezas, codigo) {
        var l = lineas.find(function (x) { return x.id_item === idItem && x.piezas === piezas; });
        if (l) { l.paquetes += 1; }
        else { l = { id_item: idItem, piezas: piezas, paquetes: 1, codigo: codigo || "" }; lineas.push(l); }
        ultima = l;
        pintar();
        guardarBorrador();
    }

    // ── Pintar la pantalla ──────────────────────────────────
    function pintar() {
        var lista = $("lista");
        lista.innerHTML = "";
        var ids = [];
        lineas.forEach(function (l) { if (ids.indexOf(l.id_item) === -1) ids.push(l.id_item); });
        Object.keys(sueltas).forEach(function (k) { k = +k; if ((parseInt(sueltas[k], 10) || 0) > 0 && ids.indexOf(k) === -1) ids.push(k); });

        ids.forEach(function (id) {
            var a = porId[id];
            var li = document.createElement("li");
            li.className = "iv-linea";
            var html = '<div class="iv-linea__cab"><b>' + escapar(a.nombre) + '</b><span>' + num(totalArticulo(id)) + ' pzas</span></div>';
            lineas.forEach(function (l, i) {
                if (l.id_item !== id) return;
                html += '<div class="iv-linea__paq">' +
                    '<span class="iv-linea__det">Paquete de ' + num(l.piezas) + '</span>' +
                    '<div class="iv-mini">' +
                        '<button type="button" data-accion="menos" data-i="' + i + '" aria-label="Un paquete menos">−</button>' +
                        '<b>' + l.paquetes + '</b>' +
                        '<button type="button" data-accion="mas" data-i="' + i + '" aria-label="Un paquete más">+</button>' +
                    '</div>' +
                '</div>';
            });
            if (MODO === "contar") {
                html += '<label class="iv-linea__sueltas"><span>Piezas sueltas</span>' +
                    '<input type="number" min="0" inputmode="numeric" data-sueltas="' + id + '" value="' + (parseInt(sueltas[id], 10) || "") + '" placeholder="0"></label>' +
                    '<p class="iv-linea__antes">En sistema: ' + num(a.stock) + ' → quedará en <b>' + num(totalArticulo(id)) + '</b></p>';
            }
            li.innerHTML = html;
            lista.appendChild(li);
        });

        $("lista-vacia").hidden = ids.length > 0;

        // Último escaneado
        var u = $("ultimo");
        if (ultima && lineas.indexOf(ultima) !== -1) {
            u.hidden = false;
            $("ultimo-nombre").textContent = porId[ultima.id_item].nombre;
            $("ultimo-paquete").textContent = "Paquete de " + num(ultima.piezas) + " piezas";
            $("ultimo-paquetes").textContent = ultima.paquetes;
            $("ultimo-total").textContent = "= " + num(totalLinea(ultima)) + " piezas";
        } else {
            u.hidden = true;
        }

        // Resumen y botón de guardar
        var piezas = 0;
        ids.forEach(function (id) { piezas += totalArticulo(id); });
        $("pie-resumen").innerHTML = "<b>" + ids.length + "</b> producto" + (ids.length !== 1 ? "s" : "") + " · <b>" + num(piezas) + "</b> piezas";
        $("btn-guardar").disabled = ids.length === 0;
    }

    function escapar(t) { var d = document.createElement("div"); d.textContent = t; return d.innerHTML; }

    $("lista").addEventListener("click", function (e) {
        var b = e.target.closest("button[data-accion]");
        if (!b) return;
        var l = lineas[+b.dataset.i];
        if (!l) return;
        l.paquetes += b.dataset.accion === "mas" ? 1 : -1;
        if (l.paquetes <= 0) { lineas.splice(lineas.indexOf(l), 1); if (ultima === l) ultima = null; }
        pintar(); guardarBorrador();
    });
    $("lista").addEventListener("input", function (e) {
        var inp = e.target.closest("input[data-sueltas]");
        if (!inp) return;
        sueltas[inp.dataset.sueltas] = Math.max(0, parseInt(inp.value, 10) || 0);
        guardarBorrador();
        // Solo actualizar los números, sin redibujar (no perder el foco al escribir)
        var li = inp.closest(".iv-linea"), id = +inp.dataset.sueltas;
        li.querySelector(".iv-linea__cab span").textContent = num(totalArticulo(id)) + " pzas";
        li.querySelector(".iv-linea__antes b").textContent = num(totalArticulo(id));
        var ids = {}, piezas = 0;
        lineas.forEach(function (l) { ids[l.id_item] = 1; });
        Object.keys(sueltas).forEach(function (k) { if ((parseInt(sueltas[k], 10) || 0) > 0) ids[k] = 1; });
        Object.keys(ids).forEach(function (k) { piezas += totalArticulo(+k); });
        $("pie-resumen").innerHTML = "<b>" + Object.keys(ids).length + "</b> producto" + (Object.keys(ids).length !== 1 ? "s" : "") + " · <b>" + num(piezas) + "</b> piezas";
        $("btn-guardar").disabled = Object.keys(ids).length === 0;
    });
    $("ultimo-mas").addEventListener("click", function () { if (ultima) { ultima.paquetes++; pintar(); guardarBorrador(); } });
    $("ultimo-menos").addEventListener("click", function () {
        if (!ultima) return;
        ultima.paquetes--;
        if (ultima.paquetes <= 0) { lineas.splice(lineas.indexOf(ultima), 1); ultima = null; }
        pintar(); guardarBorrador();
    });

    // ── Llamadas al servidor ────────────────────────────────
    function api(datos) {
        var cuerpo = new URLSearchParams(datos);
        return fetch("inventario_api.php", {
            method: "POST",
            headers: { "Accept": "application/json", "Content-Type": "application/x-www-form-urlencoded" },
            body: cuerpo.toString(),
            cache: "no-store"
        }).then(function (r) { return r.json(); });
    }

    // ── Al leer un código (cámara, pistola o escrito) ───────
    // La cámara lee el mismo código muchas veces por segundo mientras está a la vista:
    // cuenta una vez, y vuelve a contar solo si el código dejó de verse un momento
    // (apartar la cámara y volver a apuntar = un paquete más).
    var ocupado = false;
    var vistos = {};          // código → última vez que lo vio la cámara
    var silencioHasta = 0;    // al reanudar la cámara, lo que siga a la vista no cuenta
    function procesarCodigo(codigo, desdeCamara) {
        codigo = String(codigo || "").trim();
        if (!codigo || !$("hoja").hidden) return;
        if (desdeCamara) {
            var ahora = Date.now(), antes = vistos[codigo] || 0;
            vistos[codigo] = ahora;
            if (ahora < silencioHasta || ahora - antes < 1200) return;
        }
        if (ocupado) return;
        ocupado = true;
        api({ accion: "buscar", codigo: codigo }).then(function (r) {
            ocupado = false;
            if (!r.ok) { bip(false); toast(r.mensaje || "No se pudo leer el código.", true); return; }
            if (r.nuevo) { bip(true); abrirHoja(r.codigo); return; }
            bip(true);
            agregar(r.id_item, r.piezas, r.codigo);
            toast("+1 " + r.nombre);
        }).catch(function () {
            ocupado = false; bip(false);
            toast("Sin conexión. Revisa el internet e intenta otra vez.", true);
        });
    }
    window.zbProcesarCodigo = procesarCodigo; // también para pruebas

    $("form-manual").addEventListener("submit", function (e) {
        e.preventDefault();
        var inp = $("codigo-manual");
        procesarCodigo(inp.value, false); // escrito a mano (o pistola USB) siempre cuenta
        inp.value = "";
    });

    // ── Hoja: código nuevo o producto sin código ────────────
    var hojaCodigo = "", hojaItem = 0;
    function abrirHoja(codigo) {
        hojaCodigo = codigo || "";
        hojaItem = 0;
        $("hoja-codigo").textContent = codigo ? "Código nuevo · " + codigo : "Sin código";
        $("hoja-titulo").textContent = "¿Qué producto es?";
        $("hoja-paso1").hidden = false;
        $("hoja-paso2").hidden = true;
        $("hoja").hidden = false;
        pausarCamara(true);
    }
    function cerrarHoja() {
        $("hoja").hidden = true;
        // Lo que sigue frente a la cámara (el paquete recién registrado o el último
        // escaneado) no cuenta otra vez al reanudar: hay que apartar la cámara primero
        silencioHasta = Date.now() + 1200;
        pausarCamara(false);
    }

    var contProductos = $("hoja-productos");
    ARTICULOS.forEach(function (a) {
        var b = document.createElement("button");
        b.type = "button";
        b.className = "iv-producto";
        b.dataset.id = a.id;
        b.textContent = a.nombre;
        contProductos.appendChild(b);
    });
    contProductos.addEventListener("click", function (e) {
        var b = e.target.closest(".iv-producto");
        if (!b) return;
        hojaItem = +b.dataset.id;
        var a = porId[hojaItem];
        $("hoja-producto").textContent = a.nombre;
        $("hoja-piezas").value = a.piezas || "";
        $("hoja-nota").textContent = hojaCodigo
            ? "Se guarda una sola vez: la próxima vez este código se reconoce solo."
            : (MODO === "contar" ? "Si no está en paquetes, pon 1 y usa piezas sueltas." : "");
        $("hoja-paso1").hidden = true;
        $("hoja-paso2").hidden = false;
        setTimeout(function () { $("hoja-piezas").focus(); }, 50);
    });
    [1, 10, 25, 50, 100, 250, 500, 1000].forEach(function (n) {
        var c = document.createElement("button");
        c.type = "button"; c.className = "iv-chip"; c.textContent = num(n);
        c.addEventListener("click", function () { $("hoja-piezas").value = n; });
        $("hoja-chips").appendChild(c);
    });
    $("hoja-guardar").addEventListener("click", function () {
        var piezas = parseInt($("hoja-piezas").value, 10);
        if (!hojaItem || !(piezas >= 1)) { toast("Pon cuántas piezas trae el paquete.", true); return; }
        if (!hojaCodigo) {
            agregar(hojaItem, piezas, "");
            cerrarHoja();
            return;
        }
        var btn = $("hoja-guardar");
        btn.disabled = true;
        api({ accion: "registrar_codigo", codigo: hojaCodigo, id_item: hojaItem, piezas: piezas }).then(function (r) {
            btn.disabled = false;
            if (!r.ok) { toast(r.mensaje || "No se pudo guardar el código.", true); return; }
            porId[r.id_item].piezas = r.piezas;
            agregar(r.id_item, r.piezas, r.codigo);
            cerrarHoja();
            toast("Código guardado: " + r.nombre);
        }).catch(function () { btn.disabled = false; toast("Sin conexión. Intenta otra vez.", true); });
    });
    $("hoja-cancelar").addEventListener("click", cerrarHoja);
    $("btn-sin-codigo").addEventListener("click", function () { abrirHoja(""); });

    // ── Cámara ──────────────────────────────────────────────
    // Se lee sobre la imagen en resolución completa (Full HD) para que el código
    // se lea aunque esté lejos: con el lector propio del navegador cuando existe
    // (Android/Chrome) y si no con ZXing sobre la franja del marco (iPhone).
    var video = $("lector"), flujo = null, pista = null;
    var camaraActiva = false, pausada = false, detector = null, zx = null;
    var lienzo = document.createElement("canvas"), ctxLienzo = lienzo.getContext("2d", { willReadFrequently: true });
    var FORMATOS = ["ean_13", "ean_8", "upc_a", "upc_e", "code_128", "code_39", "code_93", "itf", "codabar"];
    var forzarZxing = new URLSearchParams(location.search).get("lector") === "zxing"; // solo para diagnóstico

    function pausarCamara(pausar) { pausada = !!pausar; }

    function prepararLector() {
        if (!forzarZxing && "BarcodeDetector" in window) {
            return BarcodeDetector.getSupportedFormats().then(function (sop) {
                var usar = FORMATOS.filter(function (f) { return sop.indexOf(f) !== -1; });
                if (usar.length) detector = new BarcodeDetector({ formats: usar });
            }).catch(function () {});
        }
        return Promise.resolve();
    }
    function prepararZxing() {
        if (zx || typeof ZXing === "undefined") return !!zx;
        var Z = ZXing, hints = new Map();
        hints.set(Z.DecodeHintType.POSSIBLE_FORMATS, [Z.BarcodeFormat.EAN_13, Z.BarcodeFormat.EAN_8, Z.BarcodeFormat.UPC_A, Z.BarcodeFormat.UPC_E,
            Z.BarcodeFormat.CODE_128, Z.BarcodeFormat.CODE_39, Z.BarcodeFormat.CODE_93, Z.BarcodeFormat.ITF, Z.BarcodeFormat.CODABAR]);
        hints.set(Z.DecodeHintType.TRY_HARDER, true);
        zx = new Z.MultiFormatReader();
        zx.setHints(hints);
        return true;
    }

    // Franja del marco naranja, en pixeles reales del video (lo que se ve en pantalla)
    function franja() {
        var vw = video.videoWidth, vh = video.videoHeight;
        var caja = video.getBoundingClientRect(), proporcion = caja.width / caja.height;
        var visW = Math.min(vw, vh * proporcion), visH = Math.min(vh, vw / proporcion);
        var w = Math.round(visW * 0.9), h = Math.round(visH * 0.45);
        return { x: Math.round((vw - w) / 2), y: Math.round((vh - h) / 2), w: w, h: h };
    }

    function leerCuadro() {
        if (!camaraActiva) return;
        if (pausada || !video.videoWidth || document.hidden) { setTimeout(leerCuadro, 150); return; }
        if (detector) {
            detector.detect(video).then(function (codigos) {
                if (codigos.length) procesarCodigo(codigos[0].rawValue, true);
                setTimeout(leerCuadro, 90);
            }).catch(function () { setTimeout(leerCuadro, 200); });
            return;
        }
        if (prepararZxing()) {
            var f = franja();
            lienzo.width = f.w; lienzo.height = f.h;
            ctxLienzo.drawImage(video, f.x, f.y, f.w, f.h, 0, 0, f.w, f.h);
            try {
                var fuente = new ZXing.HTMLCanvasElementLuminanceSource(lienzo);
                var r = zx.decode(new ZXing.BinaryBitmap(new ZXing.HybridBinarizer(fuente)));
                procesarCodigo(r.getText(), true);
            } catch (e) { /* sin código en este cuadro */ }
            setTimeout(leerCuadro, 110);
            return;
        }
        setTimeout(leerCuadro, 300); // ZXing todavía cargando
    }

    // Zoom (si el celular lo permite) para leer de más lejos, y linterna
    function prepararControles() {
        var cap = {};
        try { cap = pista.getCapabilities ? pista.getCapabilities() : {}; } catch (e) {}
        if (cap.focusMode && cap.focusMode.indexOf("continuous") !== -1) {
            pista.applyConstraints({ advanced: [{ focusMode: "continuous" }] }).catch(function () {});
        }
        var hay = false;
        if (cap.zoom && cap.zoom.max >= 1.5) {
            var cont = $("zoom"), niveles = [1, 2, 3].filter(function (z) { return z >= cap.zoom.min && z <= cap.zoom.max; });
            cont.innerHTML = "";
            niveles.forEach(function (z) {
                var b = document.createElement("button");
                b.type = "button"; b.textContent = z + "×"; b.dataset.zoom = z;
                b.addEventListener("click", function () { ponerZoom(z); });
                cont.appendChild(b);
            });
            cont.hidden = false; hay = true;
            ponerZoom(niveles.indexOf(2) !== -1 ? 2 : niveles[niveles.length - 1]);
        }
        if (cap.torch) { $("btn-linterna").hidden = false; hay = true; }
        $("camara-controles").hidden = !hay;
    }
    function ponerZoom(z) {
        pista.applyConstraints({ advanced: [{ zoom: z }] }).catch(function () {});
        document.querySelectorAll("#zoom button").forEach(function (b) { b.classList.toggle("activo", +b.dataset.zoom === z); });
    }
    $("btn-linterna").addEventListener("click", function () {
        var on = this.getAttribute("aria-pressed") !== "true";
        pista.applyConstraints({ advanced: [{ torch: on }] }).catch(function () {});
        this.setAttribute("aria-pressed", on ? "true" : "false");
    });

    $("btn-camara").addEventListener("click", function () {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            toast("Este navegador no deja usar la cámara. Escribe el código o usa la pistola lectora.", true); return;
        }
        $("camara-inicio").hidden = true;
        $("camara-estado").hidden = false;
        $("camara-estado").textContent = "Abriendo cámara…";
        navigator.mediaDevices.getUserMedia({
            audio: false,
            video: { facingMode: { ideal: "environment" }, width: { ideal: 1920 }, height: { ideal: 1080 } }
        }).then(function (s) {
            flujo = s; pista = s.getVideoTracks()[0];
            video.srcObject = s;
            return video.play();
        }).then(prepararLector).then(function () {
            camaraActiva = true;
            document.body.classList.add("iv-camara-activa");
            $("camara-estado").textContent = "Apunta al código de barras";
            prepararControles();
            leerCuadro();
        }).catch(function () {
            $("camara-inicio").hidden = false;
            $("camara-estado").hidden = true;
            toast("No se pudo abrir la cámara. Revisa el permiso del navegador o escribe el código.", true);
        });
    });
    function apagarCamara() {
        camaraActiva = false;
        if (flujo) flujo.getTracks().forEach(function (t) { t.stop(); });
        flujo = null;
    }
    window.addEventListener("pagehide", apagarCamara);
    window.zbEstadoCamara = function () { return camaraActiva ? (pausada ? 3 : 2) : 0; };
    window.zbLectorCamara = function () { return detector ? "nativo" : (zx ? "zxing" : "ninguno"); };

    // ── Guardar ─────────────────────────────────────────────
    $("btn-guardar").addEventListener("click", function () {
        var btn = this, datos;
        if (MODO === "recibir") {
            datos = lineas.map(function (l) { return { id_item: l.id_item, paquetes: l.paquetes, piezas: l.piezas }; });
        } else {
            var ids = {};
            lineas.forEach(function (l) { ids[l.id_item] = 1; });
            Object.keys(sueltas).forEach(function (k) { if ((parseInt(sueltas[k], 10) || 0) > 0) ids[k] = 1; });
            datos = Object.keys(ids).map(function (k) { return { id_item: +k, total: totalArticulo(+k) }; });
            if (!confirm("El stock de " + datos.length + " producto" + (datos.length !== 1 ? "s" : "") + " quedará en lo que contaste. ¿Guardar conteo?")) return;
        }
        btn.disabled = true;
        btn.textContent = "Guardando…";
        api({ accion: MODO, lineas: JSON.stringify(datos) }).then(function (r) {
            if (!r.ok) throw new Error(r.mensaje);
            borrarBorrador();
            apagarCamara();
            document.body.classList.add("iv-guardado");
            $("hecho-titulo").textContent = MODO === "recibir" ? "✓ Entrada guardada" : "✓ Conteo guardado";
            $("hecho-lista").innerHTML = r.articulos.map(function (a) {
                return "<li><span>" + escapar(a.nombre) + "</span><span>" + num(a.antes) + " → <b>" + num(a.despues) + "</b></span></li>";
            }).join("");
            $("hecho").hidden = false;
            window.scrollTo(0, 0);
        }).catch(function (err) {
            btn.disabled = false;
            btn.textContent = MODO === "recibir" ? "Guardar entrada" : "Guardar conteo";
            toast((err && err.message) || "No se pudo guardar. Intenta otra vez.", true);
        });
    });

    leerBorrador();
    pintar();
})();
</script>
</body>
</html>
