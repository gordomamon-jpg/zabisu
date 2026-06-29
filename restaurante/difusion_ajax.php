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
    if (!empty($_FILES["imagen"]["tmp_name"]) && $_FILES["imagen"]["error"] === UPLOAD_ERR_OK) {
        $mime     = mime_content_type($_FILES["imagen"]["tmp_name"]);
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
