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
        .notitas-form textarea:focus { outline: none; border-color: #FF7A00; }
        .notitas-form input[type=number] {
            width: 120px;
            padding: 10px 14px;
            border: 1.5px solid #ddd;
            border-radius: 10px;
            font-size: 15px;
            font-family: inherit;
            color: #1a1a1a;
        }
        .notitas-form input[type=number]:focus { outline: none; border-color: #FF7A00; }
        .notitas-form .campo { margin-bottom: 22px; }
        .notitas-form .acciones { display: flex; gap: 12px; margin-top: 28px; }
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

        /* ── Barra de acciones del preview ── */
        .preview-acciones {
            display: none;
            justify-content: center;
            gap: 12px;
            padding: 20px 20px 16px;
        }
        .preview-acciones.visible { display: flex; }

        /* ── Preview en pantalla: columna centrada simulando rollo ── */
        .preview-lista {
            display: none;
            flex-direction: column;
            align-items: center;
            padding: 0 20px 60px;
        }
        .preview-lista.visible { display: flex; }

        /* ── Ticket individual ── */
        .notita {
            width: 72mm;
            background: #fff;
            font-family: 'Courier New', Courier, monospace;
            color: #000;
            text-align: center;
            padding: 6mm 4mm 4mm;
            box-sizing: border-box;
        }
        .notita__logo {
            width: 28px;
            height: 28px;
            object-fit: contain;
            display: block;
            margin: 0 auto 4px;
            /* forzar grises para pantalla también */
            filter: grayscale(100%);
        }
        .notita__marca {
            font-size: 15px;
            font-weight: 900;
            letter-spacing: 2px;
            text-transform: uppercase;
            display: block;
        }
        .notita__linea {
            border: none;
            border-top: 1px dashed #000;
            margin: 5px 0;
        }
        .notita__mensaje {
            font-size: 12px;
            line-height: 1.6;
            white-space: pre-wrap;
            word-break: break-word;
            margin: 6px 0;
        }
        .notita__slogan {
            font-size: 9px;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            opacity: .6;
            display: block;
            margin-top: 4px;
        }

        /* ── Línea de corte entre tickets ── */
        .corte {
            width: 72mm;
            display: flex;
            align-items: center;
            gap: 4px;
            color: #bbb;
            font-size: 11px;
            margin: 0;
            user-select: none;
        }
        .corte::before, .corte::after {
            content: '';
            flex: 1;
            border-top: 1px dashed #bbb;
        }

        /* ── Print ── */
        @media print {
            @page { margin: 0; size: 72mm auto; }

            body * { visibility: hidden; }
            .preview-lista, .preview-lista * { visibility: visible; }

            .preview-lista {
                display: flex !important;
                position: fixed;
                top: 0; left: 0;
                width: 72mm;
                padding: 0;
                align-items: stretch;
            }

            .notita {
                width: 72mm;
                color: #000 !important;
                background: #fff !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
                page-break-after: always;
            }
            .notita__logo {
                filter: grayscale(100%) contrast(200%);
            }
            .corte {
                color: #000;
                width: 72mm;
            }
            .corte::before, .corte::after {
                border-top: 1px dashed #000;
            }
            .preview-acciones { display: none !important; }
        }
    </style>
</head>
<body>

<!-- ── Formulario ── -->
<div class="notitas-form" id="seccion-form">
    <h1>🎈 Imprimir Notitas</h1>
    <p class="subtitulo">Para impresora térmica de tickets. Blanco y negro, con línea de corte entre cada notita.</p>

    <div class="campo">
        <label for="mensaje">Mensaje de la notita</label>
        <textarea id="mensaje" placeholder="Ej: ¡Feliz Día del Niño!&#10;Con cariño, el equipo Zabisu" maxlength="300"></textarea>
    </div>

    <div class="campo">
        <label for="cantidad">Cantidad de notitas</label>
        <input type="number" id="cantidad" value="50" min="1" max="300">
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

<div class="preview-lista" id="preview-lista"></div>

<script>
function generarPreview() {
    var mensaje  = document.getElementById("mensaje").value.trim();
    var cantidad = Math.max(1, Math.min(300, parseInt(document.getElementById("cantidad").value) || 1));

    if (!mensaje) {
        document.getElementById("mensaje").focus();
        return;
    }

    var lista = document.getElementById("preview-lista");
    lista.innerHTML = "";

    var ticketHtml = `
        <div class="notita">
            <img class="notita__logo" src="../assets/img/LOGO_N.png" alt="Zabisu">
            <span class="notita__marca">Zabisu</span>
            <hr class="notita__linea">
            <p class="notita__mensaje">${escapeHtml(mensaje)}</p>
            <hr class="notita__linea">
            <span class="notita__slogan">Sabor y Servicio</span>
        </div>`;

    for (var i = 0; i < cantidad; i++) {
        lista.innerHTML += ticketHtml;
        if (i < cantidad - 1) {
            lista.innerHTML += `<div class="corte">✂</div>`;
        }
    }

    document.getElementById("seccion-form").style.display = "none";
    lista.classList.add("visible");
    document.getElementById("preview-acciones").classList.add("visible");
}

function volverForm() {
    document.getElementById("seccion-form").style.display = "";
    document.getElementById("preview-lista").classList.remove("visible");
    document.getElementById("preview-acciones").classList.remove("visible");
}

function escapeHtml(str) {
    return str.replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;");
}
</script>

</body>
</html>
