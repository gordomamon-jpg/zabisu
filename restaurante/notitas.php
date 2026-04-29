<?php require_once "../config/db.php"; ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Imprimir Notitas · Zabisu</title>
    <link rel="icon" type="image/png" href="../assets/img/LOGO_NARA.png">
    <link rel="stylesheet" href="../assets/css/styles.css?v=5">
    <style>
        .notitas-form {
            max-width: 560px;
            margin: 0 auto;
            padding: 32px 20px 60px;
        }
        .notitas-form h1 { font-size: 22px; font-weight: 700; margin-bottom: 6px; color: #1a1a1a; }
        .notitas-form .subtitulo { font-size: 14px; color: #888; margin-bottom: 28px; }
        .notitas-form label { display: block; font-size: 13px; font-weight: 600; color: #444; margin-bottom: 6px; }
        .notitas-form textarea {
            width: 100%; min-height: 100px; padding: 12px 14px;
            border: 1.5px solid #ddd; border-radius: 10px;
            font-size: 15px; font-family: inherit; resize: vertical;
            box-sizing: border-box; color: #1a1a1a;
        }
        .notitas-form textarea:focus { outline: none; border-color: #FF7A00; }
        .notitas-form input[type=number] {
            width: 120px; padding: 10px 14px;
            border: 1.5px solid #ddd; border-radius: 10px;
            font-size: 15px; font-family: inherit; color: #1a1a1a;
        }
        .notitas-form input[type=number]:focus { outline: none; border-color: #FF7A00; }
        .notitas-form .campo { margin-bottom: 22px; }
        .notitas-form .acciones { display: flex; gap: 12px; margin-top: 28px; }
        .btn-imprimir {
            background: #FF7A00; color: #fff; border: none;
            border-radius: 10px; padding: 13px 28px;
            font-size: 15px; font-weight: 700; cursor: pointer; font-family: inherit;
        }
        .btn-imprimir:hover { background: #e06a00; }
        .btn-volver {
            background: transparent; color: #888; border: 1.5px solid #ddd;
            border-radius: 10px; padding: 13px 20px; font-size: 14px;
            cursor: pointer; font-family: inherit; text-decoration: none;
            display: inline-flex; align-items: center;
        }
    </style>
</head>
<body>

<div class="notitas-form">
    <h1>🎈 Imprimir Notitas</h1>
    <p class="subtitulo">Se abrirá una ventana de impresión con cada notita lista para cortar.</p>

    <div class="campo">
        <label for="mensaje">Mensaje de la notita</label>
        <textarea id="mensaje" placeholder="Ej: ¡Feliz Día del Niño!&#10;Con cariño, el equipo Zabisu" maxlength="300"></textarea>
    </div>

    <div class="campo">
        <label for="cantidad">Cantidad de notitas</label>
        <input type="number" id="cantidad" value="50" min="1" max="300">
    </div>

    <div class="acciones">
        <button type="button" class="btn-imprimir" onclick="imprimirNotitas()">🖨️ Imprimir</button>
        <a href="panel_general.php" class="btn-volver">← Volver</a>
    </div>
</div>

<script>
function escapeHtml(str) {
    return str.replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;");
}

function imprimirNotitas() {
    var mensaje  = document.getElementById("mensaje").value.trim();
    var cantidad = Math.max(1, Math.min(300, parseInt(document.getElementById("cantidad").value) || 1));

    if (!mensaje) {
        document.getElementById("mensaje").focus();
        return;
    }

    var mensajeEsc = escapeHtml(mensaje);
    var tickets = "";
    for (var i = 0; i < cantidad; i++) {
        tickets += `
        <div class="notita">
            <img class="notita__logo" src="../assets/img/dd.png" alt="Zabisu">
            <div class="notita__marca">ZABISU</div>
            <div class="notita__linea"></div>
            <div class="notita__mensaje">${mensajeEsc}</div>
            <div class="notita__linea notita__linea--dashed"></div>
            <div class="notita__slogan">Sabor y Servicio</div>
        </div>`;
    }

    var html = `<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Notitas Zabisu</title>
<style>
    @page { size: 80mm auto; margin: 4mm; }
    html, body { width: 80mm; margin: 0; padding: 0; }
    body { font-family: Arial, Helvetica, sans-serif; color: #000; background: #fff; }

    .notita {
        width: 100%;
        text-align: center;
        padding: 6mm 3mm 5mm;
        box-sizing: border-box;
        page-break-after: always;
    }
    .notita:last-child { page-break-after: avoid; }

    .notita__logo {
        width: 50px;
        height: 50px;
        object-fit: contain;
        display: block;
        margin: 0 auto 4px;
    }
    .notita__marca {
        font-size: 20px;
        font-weight: 900;
        letter-spacing: 4px;
        margin-bottom: 4px;
    }
    .notita__linea {
        border-top: 2px solid #000;
        margin: 5px 0;
    }
    .notita__linea--dashed {
        border-top: 1px dashed #000;
        margin: 5px 0;
    }
    .notita__mensaje {
        font-size: 14px;
        font-weight: 600;
        line-height: 1.65;
        white-space: pre-wrap;
        word-break: break-word;
        margin: 8px 4px;
    }
    .notita__slogan {
        font-size: 10px;
        font-weight: 700;
        letter-spacing: 2px;
        text-transform: uppercase;
        opacity: .65;
        margin-top: 4px;
    }
</style>
</head>
<body>
${tickets}
<script>window.onload = function(){ window.print(); }<\/script>
</body>
</html>`;

    var win = window.open("", "_blank");
    win.document.write(html);
    win.document.close();
}
</script>

</body>
</html>
