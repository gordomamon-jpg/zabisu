<?php
require_once "../config/db.php";
require_once "auth_check.php";

$stmt = $conexion->query(
    "SELECT id_contacto, nombre, telefono FROM difusion_contactos WHERE activo = 1 ORDER BY nombre ASC, telefono ASC"
);
$contactos    = $stmt->fetchAll(PDO::FETCH_ASSOC);
$totalContactos = count($contactos);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Difusión WhatsApp | Zabisu</title>
    <link rel="icon" type="image/png" href="../assets/img/LOGO_NARA.png">
    <link rel="stylesheet" href="../assets/css/styles.css?v=5">
    <style>
        .dif-layout {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            align-items: start;
        }
        @media (max-width: 860px) { .dif-layout { grid-template-columns: 1fr; } }

        .dif-card {
            background: #111114;
            border: 1px solid #1e1e22;
            border-radius: 16px;
            overflow: hidden;
        }
        .dif-card__header {
            background: #0f0f12;
            border-bottom: 1px solid #1e1e22;
            padding: 18px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        .dif-card__titulo {
            font-size: 14px;
            font-weight: 700;
            color: #e8e8e8;
            margin: 0;
        }
        .dif-card__badge {
            background: #ff7a00;
            color: #fff;
            font-size: 11px;
            font-weight: 800;
            padding: 3px 10px;
            border-radius: 20px;
        }
        .dif-card__body { padding: 20px; }

        /* Formulario agregar */
        .dif-form-agregar {
            display: flex;
            gap: 8px;
            margin-bottom: 16px;
        }
        .dif-form-agregar input {
            flex: 1;
            background: #1a1a1f;
            border: 1px solid #2a2a2e;
            border-radius: 10px;
            color: #e8e8e8;
            font-size: 13px;
            padding: 9px 12px;
            outline: none;
            transition: border-color .2s;
        }
        .dif-form-agregar input:focus { border-color: #ff7a00; }
        .dif-form-agregar input::placeholder { color: #555; }
        .btn-agregar-contacto {
            background: #ff7a00;
            color: #fff;
            border: none;
            border-radius: 10px;
            font-size: 18px;
            font-weight: 700;
            padding: 0 16px;
            cursor: pointer;
            transition: background .2s;
            flex-shrink: 0;
        }
        .btn-agregar-contacto:hover { background: #e06800; }

        /* Lista de contactos */
        .dif-lista {
            max-height: 420px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .dif-lista::-webkit-scrollbar { width: 4px; }
        .dif-lista::-webkit-scrollbar-track { background: transparent; }
        .dif-lista::-webkit-scrollbar-thumb { background: #333; border-radius: 4px; }
        .dif-contacto {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #1a1a1f;
            border: 1px solid #222226;
            border-radius: 10px;
            padding: 10px 14px;
            gap: 10px;
        }
        .dif-contacto__info { flex: 1; min-width: 0; }
        .dif-contacto__nombre {
            font-size: 13px;
            font-weight: 600;
            color: #e0e0e0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .dif-contacto__tel {
            font-size: 11px;
            color: #666;
            margin-top: 1px;
        }
        .btn-eliminar-contacto {
            background: none;
            border: none;
            color: #555;
            font-size: 16px;
            cursor: pointer;
            padding: 4px 6px;
            border-radius: 6px;
            transition: color .2s, background .2s;
            flex-shrink: 0;
        }
        .btn-eliminar-contacto:hover { color: #ff4444; background: rgba(255,68,68,.1); }
        .dif-lista-vacia {
            text-align: center;
            color: #444;
            font-size: 13px;
            padding: 32px 0;
        }

        /* Broadcast form */
        .dif-broadcast-form { display: flex; flex-direction: column; gap: 14px; }

        .dif-field label {
            display: block;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            color: #555;
            margin-bottom: 8px;
        }
        .dif-field textarea {
            width: 100%;
            background: #1a1a1f;
            border: 1px solid #2a2a2e;
            border-radius: 10px;
            color: #e8e8e8;
            font-size: 14px;
            padding: 12px;
            resize: vertical;
            min-height: 120px;
            outline: none;
            font-family: inherit;
            box-sizing: border-box;
            transition: border-color .2s;
        }
        .dif-field textarea:focus { border-color: #ff7a00; }

        /* Upload imagen */
        .dif-upload {
            border: 2px dashed #2a2a2e;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            cursor: pointer;
            transition: border-color .2s, background .2s;
            position: relative;
        }
        .dif-upload:hover { border-color: #ff7a00; background: rgba(255,122,0,.03); }
        .dif-upload input[type=file] {
            position: absolute;
            inset: 0;
            opacity: 0;
            cursor: pointer;
            width: 100%;
            height: 100%;
        }
        .dif-upload__icono { font-size: 28px; margin-bottom: 6px; }
        .dif-upload__texto { font-size: 13px; color: #666; }
        .dif-upload__nombre { font-size: 12px; color: #ff7a00; margin-top: 4px; font-weight: 600; }

        .dif-preview-img {
            margin-top: 10px;
            border-radius: 10px;
            overflow: hidden;
            display: none;
        }
        .dif-preview-img img {
            width: 100%;
            max-height: 200px;
            object-fit: cover;
            display: block;
        }

        /* Botón enviar */
        .btn-broadcast {
            background: #25d366;
            color: #fff;
            border: none;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 700;
            padding: 14px 20px;
            cursor: pointer;
            transition: background .2s, transform .1s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
        }
        .btn-broadcast:hover:not(:disabled) { background: #1db954; }
        .btn-broadcast:active:not(:disabled) { transform: scale(.98); }
        .btn-broadcast:disabled { opacity: .5; cursor: not-allowed; }

        /* Status */
        .dif-status {
            border-radius: 10px;
            padding: 12px 16px;
            font-size: 13px;
            font-weight: 600;
            display: none;
        }
        .dif-status--ok { background: rgba(37,211,102,.12); border: 1px solid rgba(37,211,102,.3); color: #25d366; }
        .dif-status--err { background: rgba(255,68,68,.1); border: 1px solid rgba(255,68,68,.25); color: #ff6666; }

        .dif-aviso-total {
            font-size: 12px;
            color: #555;
            text-align: center;
            margin-top: 4px;
        }
    </style>
</head>
<body>
<div class="contenedor">

    <div class="hero-zabisu">
        <div class="hero-zabisu__glow"></div>
        <div class="hero-zabisu__contenido">
            <p class="hero-zabisu__eyebrow">RESTAURANTE</p>
            <div class="hero-zabisu__marca-grupo">
                <img class="hero-zabisu__logo" src="../assets/img/LOGO_BLANCO.png" alt="Zabisu">
                <span class="hero-zabisu__nombre" style="background:linear-gradient(90deg,#ff7a00,#ffb347);-webkit-background-clip:text;-webkit-text-fill-color:transparent;">Zabisu</span>
            </div>
        </div>
    </div>

    <div style="margin-bottom:24px;">
        <a href="panel_general.php" style="color:#ff7a00;font-size:13px;text-decoration:none;">← Volver al panel</a>
        <h1 style="margin:8px 0 4px;font-size:22px;color:#e8e8e8;">📣 Difusión WhatsApp</h1>
        <p style="margin:0;font-size:13px;color:#666;">Envía el menú del día a todos tus clientes de un solo golpe</p>
    </div>

    <div class="dif-layout">

        <!-- ── COLUMNA IZQUIERDA: Contactos ── -->
        <div class="dif-card">
            <div class="dif-card__header">
                <h2 class="dif-card__titulo">👥 Lista de contactos</h2>
                <span class="dif-card__badge" id="badge-total"><?php echo $totalContactos; ?></span>
            </div>
            <div class="dif-card__body">

                <div class="dif-form-agregar">
                    <input type="text" id="input-tel" placeholder="Teléfono (10 dígitos)" maxlength="10" inputmode="numeric">
                    <input type="text" id="input-nombre" placeholder="Nombre (opcional)" maxlength="80">
                    <button class="btn-agregar-contacto" id="btn-agregar" title="Agregar">+</button>
                </div>

                <div class="dif-lista" id="lista-contactos">
                    <?php if (empty($contactos)): ?>
                        <div class="dif-lista-vacia" id="lista-vacia">Aún no hay contactos. Agrega el primero.</div>
                    <?php else: ?>
                        <?php foreach ($contactos as $c): ?>
                            <div class="dif-contacto" data-id="<?php echo $c['id_contacto']; ?>">
                                <div class="dif-contacto__info">
                                    <div class="dif-contacto__nombre"><?php echo $c['nombre'] !== '' ? htmlspecialchars($c['nombre']) : '—'; ?></div>
                                    <div class="dif-contacto__tel"><?php echo htmlspecialchars($c['telefono']); ?></div>
                                </div>
                                <button class="btn-eliminar-contacto" data-id="<?php echo $c['id_contacto']; ?>" title="Eliminar">✕</button>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

            </div>
        </div>

        <!-- ── COLUMNA DERECHA: Broadcast ── -->
        <div class="dif-card">
            <div class="dif-card__header">
                <h2 class="dif-card__titulo">📤 Enviar difusión</h2>
            </div>
            <div class="dif-card__body">
                <form class="dif-broadcast-form" id="form-broadcast" enctype="multipart/form-data">

                    <div class="dif-field">
                        <label>Imagen del menú</label>
                        <div class="dif-upload" id="zona-upload">
                            <input type="file" name="imagen" id="input-imagen" accept="image/*">
                            <div class="dif-upload__icono">🖼️</div>
                            <div class="dif-upload__texto">Toca para seleccionar la imagen del menú</div>
                            <div class="dif-upload__nombre" id="nombre-imagen"></div>
                        </div>
                        <div class="dif-preview-img" id="preview-img">
                            <img id="preview-img-tag" src="" alt="Vista previa">
                        </div>
                    </div>

                    <div class="dif-field">
                        <label>Texto / Descripción</label>
                        <textarea name="caption" id="input-caption" placeholder="Ej: 🍽️ Menú del día - Lunes 28 de junio&#10;Hoy tenemos pollo al pastor, caldo tlalpeño..."></textarea>
                    </div>

                    <div class="dif-status" id="dif-status"></div>

                    <button type="submit" class="btn-broadcast" id="btn-broadcast">
                        <span>📣</span> <span id="btn-broadcast-texto">Enviar a <strong id="total-en-boton"><?php echo $totalContactos; ?></strong> contactos</span>
                    </button>
                    <p class="dif-aviso-total">El envío se hace en segundo plano con pequeños intervalos para no saturar WhatsApp</p>

                </form>
            </div>
        </div>

    </div>
</div>

<script>
(function () {
    var listaEl      = document.getElementById("lista-contactos");
    var badgeEl      = document.getElementById("badge-total");
    var totalBotonEl = document.getElementById("total-en-boton");
    var listaVaciaEl = document.getElementById("lista-vacia");
    var statusEl     = document.getElementById("dif-status");
    var btnBroadcast = document.getElementById("btn-broadcast");

    function contarContactos() {
        return listaEl.querySelectorAll(".dif-contacto").length;
    }

    function actualizarContador() {
        var n = contarContactos();
        badgeEl.textContent        = n;
        totalBotonEl.textContent   = n;
        listaVaciaEl && (listaVaciaEl.style.display = n === 0 ? "block" : "none");
    }

    // ── Agregar contacto ─────────────────────────────────────
    document.getElementById("btn-agregar").addEventListener("click", function () {
        var tel    = document.getElementById("input-tel").value.trim();
        var nombre = document.getElementById("input-nombre").value.trim();

        if (!/^\d{10}$/.test(tel)) {
            alert("El teléfono debe tener exactamente 10 dígitos.");
            return;
        }

        var fd = new FormData();
        fd.append("accion", "agregar");
        fd.append("telefono", tel);
        fd.append("nombre", nombre);

        fetch("difusion_ajax.php", { method: "POST", body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.ok) { alert(data.error || "Error al agregar."); return; }

                // Insertar en lista
                var div = document.createElement("div");
                div.className   = "dif-contacto";
                div.dataset.id  = data.id;
                div.innerHTML   =
                    '<div class="dif-contacto__info">'
                    + '<div class="dif-contacto__nombre">' + (nombre || "—") + '</div>'
                    + '<div class="dif-contacto__tel">' + tel + '</div>'
                    + '</div>'
                    + '<button class="btn-eliminar-contacto" data-id="' + data.id + '" title="Eliminar">✕</button>';

                div.querySelector(".btn-eliminar-contacto").addEventListener("click", eliminarContacto);
                listaEl.appendChild(div);

                document.getElementById("input-tel").value    = "";
                document.getElementById("input-nombre").value = "";
                actualizarContador();
            })
            .catch(function () { alert("Error de red."); });
    });

    // Enter en inputs → agregar
    ["input-tel", "input-nombre"].forEach(function (id) {
        document.getElementById(id).addEventListener("keydown", function (e) {
            if (e.key === "Enter") { e.preventDefault(); document.getElementById("btn-agregar").click(); }
        });
    });

    // ── Eliminar contacto ─────────────────────────────────────
    function eliminarContacto(e) {
        var id  = e.currentTarget.dataset.id;
        var row = listaEl.querySelector(".dif-contacto[data-id='" + id + "']");
        if (!row) return;

        var fd = new FormData();
        fd.append("accion", "eliminar");
        fd.append("id", id);

        fetch("difusion_ajax.php", { method: "POST", body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.ok) { alert(data.error || "Error."); return; }
                row.remove();
                actualizarContador();
            })
            .catch(function () { alert("Error de red."); });
    }

    listaEl.querySelectorAll(".btn-eliminar-contacto").forEach(function (btn) {
        btn.addEventListener("click", eliminarContacto);
    });

    // ── Preview imagen ────────────────────────────────────────
    document.getElementById("input-imagen").addEventListener("change", function () {
        var file    = this.files[0];
        var preview = document.getElementById("preview-img");
        var tag     = document.getElementById("preview-img-tag");
        var nombre  = document.getElementById("nombre-imagen");

        if (file) {
            nombre.textContent  = file.name;
            preview.style.display = "block";
            tag.src = URL.createObjectURL(file);
        } else {
            preview.style.display = "none";
            nombre.textContent  = "";
        }
    });

    // ── Enviar broadcast ──────────────────────────────────────
    document.getElementById("form-broadcast").addEventListener("submit", function (e) {
        e.preventDefault();

        if (contarContactos() === 0) {
            mostrarStatus("No hay contactos en la lista.", "err");
            return;
        }

        var caption = document.getElementById("input-caption").value.trim();
        var imagen  = document.getElementById("input-imagen").files[0];

        if (!caption && !imagen) {
            mostrarStatus("Escribe un mensaje o selecciona una imagen.", "err");
            return;
        }

        btnBroadcast.disabled = true;
        document.getElementById("btn-broadcast-texto").textContent = "Enviando…";
        statusEl.style.display = "none";

        var fd = new FormData();
        fd.append("accion", "broadcast");
        fd.append("caption", caption);
        if (imagen) fd.append("imagen", imagen);

        fetch("difusion_ajax.php", { method: "POST", body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                btnBroadcast.disabled = false;
                document.getElementById("btn-broadcast-texto").innerHTML =
                    "Enviar a <strong id='total-en-boton'>" + contarContactos() + "</strong> contactos";
                totalBotonEl = document.getElementById("total-en-boton");

                if (!data.ok) {
                    mostrarStatus("❌ " + (data.error || "Error al enviar."), "err");
                } else {
                    mostrarStatus("✅ " + (data.queued || 0) + " mensajes en cola. El envío continúa en segundo plano.", "ok");
                    // Limpiar form
                    document.getElementById("input-caption").value = "";
                    document.getElementById("input-imagen").value  = "";
                    document.getElementById("preview-img").style.display = "none";
                    document.getElementById("nombre-imagen").textContent = "";
                }
            })
            .catch(function () {
                btnBroadcast.disabled = false;
                document.getElementById("btn-broadcast-texto").innerHTML =
                    "Enviar a <strong id='total-en-boton'>" + contarContactos() + "</strong> contactos";
                mostrarStatus("❌ Error de red.", "err");
            });
    });

    function mostrarStatus(msg, tipo) {
        statusEl.textContent     = msg;
        statusEl.className       = "dif-status dif-status--" + tipo;
        statusEl.style.display   = "block";
    }
})();
</script>
</body>
</html>
