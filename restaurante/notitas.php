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
        /* ── Pantalla ── */
        .notitas-form {
            max-width: 560px;
            margin: 0 auto;
            padding: 32px 20px 60px;
        }
        .notitas-form h1 {
            font-size: 22px;
            font-weight: 700;
            margin-bottom: 6px;
            color: #1a1a1a;
        }
        .notitas-form .subtitulo {
            font-size: 14px;
            color: #888;
            margin-bottom: 28px;
        }
        .notitas-form label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #444;
            margin-bottom: 6px;
        }
        .notitas-form textarea {
            width: 100%;
            min-height: 100px;
            padding: 12px 14px;
            border: 1.5px solid #ddd;
            border-radius: 10px;
            font-size: 15px;
            font-family: inherit;
            resize: vertical;
            box-sizing: border-box;
            color: #1a1a1a;
        }
        .notitas-form textarea:focus {
            outline: none;
            border-color: #FF7A00;
        }
        .notitas-form input[type=number] {
            width: 120px;
            padding: 10px 14px;
            border: 1.5px solid #ddd;
            border-radius: 10px;
            font-size: 15px;
            font-family: inherit;
            color: #1a1a1a;
        }
        .notitas-form input[type=number]:focus {
            outline: none;
            border-color: #FF7A00;
        }
        .notitas-form .campo {
            margin-bottom: 22px;
        }
        .notitas-form .acciones {
            display: flex;
            gap: 12px;
            margin-top: 28px;
        }
        .btn-imprimir {
            background: #FF7A00;
            color: #fff;
            border: none;
            border-radius: 10px;
            padding: 13px 28px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            font-family: inherit;
        }
        .btn-imprimir:hover { background: #e06a00; }
        .btn-volver {
            background: transparent;
            color: #888;
            border: 1.5px solid #ddd;
            border-radius: 10px;
            padding: 13px 20px;
            font-size: 14px;
            cursor: pointer;
            font-family: inherit;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
        }

        /* ── Preview en pantalla ── */
        .preview-grid {
            display: none;
            flex-wrap: wrap;
            gap: 10px;
            padding: 24px 20px;
            max-width: 820px;
            margin: 0 auto;
        }
        .preview-grid.visible { display: flex; }

        /* ── Ticket ── */
        .notita {
            width: 210px;
            border: 1.5px solid #eee;
            border-radius: 10px;
            overflow: hidden;
            background: #fff;
            box-shadow: 0 2px 8px rgba(0,0,0,.08);
            display: flex;
            flex-direction: column;
            page-break-inside: avoid;
        }
        .notita__header {
            background: #FF7A00;
            padding: 10px 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .notita__logo {
            width: 28px;
            height: 28px;
            object-fit: contain;
        }
        .notita__marca {
            color: #fff;
            font-size: 16px;
            font-weight: 800;
            letter-spacing: .3px;
        }
        .notita__cuerpo {
            padding: 14px 14px 10px;
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
        }
        .notita__mensaje {
            font-size: 13px;
            color: #1a1a1a;
            line-height: 1.55;
            white-space: pre-wrap;
            word-break: break-word;
        }
        .notita__footer {
            padding: 8px 14px 10px;
            text-align: center;
            border-top: 1px solid #f0f0f0;
        }
        .notita__slogan {
            font-size: 10px;
            color: #aaa;
            letter-spacing: .5px;
            text-transform: uppercase;
        }

        /* ── Barra de acciones del preview ── */
        .preview-acciones {
            display: none;
            justify-content: center;
            gap: 12px;
            padding: 0 20px 32px;
        }
        .preview-acciones.visible { display: flex; }

        /* ── Print ── */
        @media print {
            body * { visibility: hidden; }
            .preview-grid, .preview-grid * { visibility: visible; }
            .preview-grid {
                display: flex !important;
                position: fixed;
                top: 0; left: 0;
                width: 100%;
                padding: 8mm;
                gap: 6mm;
                box-sizing: border-box;
            }
            .notita {
                width: 62mm;
                border: 1px solid #ddd;
                box-shadow: none;
                border-radius: 6px;
            }
            .notita__mensaje { font-size: 11px; }
            .notita__slogan { font-size: 8px; }
            .preview-acciones { display: none !important; }
        }
    </style>
</head>
<body>

<!-- ── Formulario ── -->
<div class="notitas-form" id="seccion-form">
    <h1>🎈 Imprimir Notitas</h1>
    <p class="subtitulo">Crea pequeños tickets personalizados para imprimir en cantidad.</p>

    <div class="campo">
        <label for="mensaje">Mensaje de la notita</label>
        <textarea id="mensaje" placeholder="Ej: ¡Feliz Día del Niño! 🎉&#10;Con cariño, el equipo Zabisu 🧡" maxlength="300"></textarea>
    </div>

    <div class="campo">
        <label for="cantidad">Cantidad de notitas</label>
        <input type="number" id="cantidad" value="50" min="1" max="200">
    </div>

    <div class="acciones">
        <button type="button" class="btn-imprimir" onclick="generarPreview()">Ver preview</button>
        <a href="panel_general.php" class="btn-volver">← Volver</a>
    </div>
</div>

<!-- ── Preview ── -->
<div class="preview-acciones" id="preview-acciones">
    <button type="button" class="btn-imprimir" onclick="window.print()">🖨️ Imprimir</button>
    <button type="button" class="btn-volver" onclick="volverForm()">← Editar</button>
</div>

<div class="preview-grid" id="preview-grid"></div>

<script>
function generarPreview() {
    var mensaje  = document.getElementById("mensaje").value.trim();
    var cantidad = Math.max(1, Math.min(200, parseInt(document.getElementById("cantidad").value) || 1));

    if (!mensaje) {
        document.getElementById("mensaje").focus();
        return;
    }

    var grid = document.getElementById("preview-grid");
    grid.innerHTML = "";

    for (var i = 0; i < cantidad; i++) {
        grid.innerHTML += `
        <div class="notita">
            <div class="notita__header">
                <img class="notita__logo" src="../assets/img/LOGO_BLANCO.png" alt="Zabisu">
                <span class="notita__marca">Zabisu</span>
            </div>
            <div class="notita__cuerpo">
                <p class="notita__mensaje">${escapeHtml(mensaje)}</p>
            </div>
            <div class="notita__footer">
                <span class="notita__slogan">Sabor y Servicio</span>
            </div>
        </div>`;
    }

    document.getElementById("seccion-form").style.display = "none";
    grid.classList.add("visible");
    document.getElementById("preview-acciones").classList.add("visible");
}

function volverForm() {
    document.getElementById("seccion-form").style.display = "";
    document.getElementById("preview-grid").classList.remove("visible");
    document.getElementById("preview-acciones").classList.remove("visible");
}

function escapeHtml(str) {
    return str.replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;");
}
</script>

</body>
</html>
