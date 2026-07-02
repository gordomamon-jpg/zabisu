<?php
require_once "../config/db.php";
require_once "auth_check.php";
require_once "../includes/enviar_whatsapp.php";

header("Content-Type: application/json; charset=UTF-8");

$accion = $_POST["accion"] ?? $_GET["accion"] ?? "";

// ── Listar contactos ──────────────────────────────────────────
if ($accion === "listar") {
    $stmt = $conexion->query(
        "SELECT id_contacto, nombre, telefono FROM difusion_contactos WHERE activo = 1 ORDER BY nombre ASC, telefono ASC"
    );
    echo json_encode(["ok" => true, "contactos" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

// ── Agregar contacto ─────────────────────────────────────────
if ($accion === "agregar") {
    $telefono = preg_replace('/\D/', '', $_POST["telefono"] ?? "");
    $nombre   = trim($_POST["nombre"] ?? "");

    if (strlen($telefono) !== 10) {
        echo json_encode(["ok" => false, "error" => "El teléfono debe tener 10 dígitos."]);
        exit;
    }

    $stmt = $conexion->prepare(
        "SELECT id_contacto FROM difusion_contactos WHERE telefono = :tel AND activo = 1 LIMIT 1"
    );
    $stmt->execute([":tel" => $telefono]);
    if ($stmt->fetchColumn()) {
        echo json_encode(["ok" => false, "error" => "Ese número ya está en la lista."]);
        exit;
    }

    $ins = $conexion->prepare(
        "INSERT INTO difusion_contactos (nombre, telefono, activo) VALUES (:nombre, :tel, 1)"
    );
    $ins->execute([":nombre" => $nombre, ":tel" => $telefono]);
    echo json_encode(["ok" => true, "id" => $conexion->lastInsertId()]);
    exit;
}

// ── Importar lista de números ─────────────────────────────────
if ($accion === "importar") {
    $texto = trim($_POST["numeros"] ?? "");
    if ($texto === "") {
        echo json_encode(["ok" => false, "error" => "No se recibieron números."]);
        exit;
    }

    $lineas     = preg_split('/\r\n|\r|\n/', $texto);
    $agregados  = 0;
    $duplicados = 0;
    $invalidos  = 0;
    $nuevos     = [];

    $stmtCheck = $conexion->prepare(
        "SELECT id_contacto FROM difusion_contactos WHERE telefono = :tel AND activo = 1 LIMIT 1"
    );
    $stmtIns = $conexion->prepare(
        "INSERT INTO difusion_contactos (nombre, telefono, activo) VALUES ('', :tel, 1)"
    );

    foreach ($lineas as $linea) {
        $tel = preg_replace('/\D/', '', trim($linea));
        if ($tel === "") continue;
        if (strlen($tel) !== 10) { $invalidos++; continue; }

        $stmtCheck->execute([":tel" => $tel]);
        if ($stmtCheck->fetchColumn()) { $duplicados++; continue; }

        $stmtIns->execute([":tel" => $tel]);
        $id = (int)$conexion->lastInsertId();
        $nuevos[]  = ["id" => $id, "telefono" => $tel];
        $agregados++;
    }

    echo json_encode(["ok" => true, "agregados" => $agregados, "duplicados" => $duplicados, "invalidos" => $invalidos, "nuevos" => $nuevos]);
    exit;
}

// ── Eliminar contacto ─────────────────────────────────────────
if ($accion === "eliminar") {
    $id = (int)($_POST["id"] ?? 0);
    if ($id <= 0) { echo json_encode(["ok" => false, "error" => "ID inválido."]); exit; }

    $conexion->prepare("UPDATE difusion_contactos SET activo = 0 WHERE id_contacto = :id")
             ->execute([":id" => $id]);
    echo json_encode(["ok" => true]);
    exit;
}

// ── Enviar broadcast ─────────────────────────────────────────
if ($accion === "broadcast") {
    $caption = trim($_POST["caption"] ?? "");

    if ($caption === "" && empty($_FILES["imagen"]["tmp_name"])) {
        echo json_encode(["ok" => false, "error" => "Debes escribir un mensaje o adjuntar una imagen."]);
        exit;
    }

    // Obtener todos los teléfonos activos
    $stmt = $conexion->query(
        "SELECT telefono FROM difusion_contactos WHERE activo = 1"
    );
    $telefonos = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($telefonos)) {
        echo json_encode(["ok" => false, "error" => "No hay contactos en la lista."]);
        exit;
    }

    // Procesar imagen si viene
    $imagen = null;
    if (isset($_FILES["imagen"]) && $_FILES["imagen"]["error"] !== UPLOAD_ERR_NO_FILE) {
        $uploadError = $_FILES["imagen"]["error"];
        if ($uploadError !== UPLOAD_ERR_OK) {
            $errMsg = match($uploadError) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => "La imagen es demasiado grande. Redúcela a menos de 8 MB.",
                UPLOAD_ERR_PARTIAL  => "La imagen no se subió completa. Intenta de nuevo.",
                default             => "Error al subir la imagen (código {$uploadError}).",
            };
            echo json_encode(["ok" => false, "error" => $errMsg]);
            exit;
        }
        $mime       = mime_content_type($_FILES["imagen"]["tmp_name"]);
        $permitidos = ["image/jpeg", "image/png", "image/webp", "image/gif"];
        if (!in_array($mime, $permitidos)) {
            echo json_encode(["ok" => false, "error" => "Formato de imagen no permitido."]);
            exit;
        }
        $imagen = [
            "data"     => base64_encode(file_get_contents($_FILES["imagen"]["tmp_name"])),
            "mimetype" => $mime,
            "filename" => basename($_FILES["imagen"]["name"]),
        ];
    }

    $resultado = enviarBroadcastWA($telefonos, $caption, $imagen);
    echo json_encode($resultado);
    exit;
}

echo json_encode(["ok" => false, "error" => "Acción no reconocida."]);
