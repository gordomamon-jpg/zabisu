<?php
require_once "../config/db.php";
require_once "auth_check.php";
require_once "../includes/enviar_correo.php";
require_once "../includes/enviar_whatsapp.php";

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["ok" => false, "mensaje" => "Método no permitido."]);
    exit;
}

$nombre_ubicacion = trim($_POST["nombre_ubicacion"] ?? "");
$hora_entrega     = trim($_POST["hora_entrega"]     ?? "");
$fecha            = trim($_POST["fecha"]             ?? date("Y-m-d"));

if ($nombre_ubicacion === "" || $hora_entrega === "") {
    echo json_encode(["ok" => false, "mensaje" => "Selecciona una ubicación y un horario."]);
    exit;
}

/* ── Validar formato de fecha ── */
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
    $fecha = date("Y-m-d");
}

/* ── Obtener id_horario ── */
$stmtH = $conexion->prepare("
    SELECT h.id_horario
    FROM horarios_ubicacion h
    INNER JOIN ubicaciones u ON h.id_ubicacion = u.id_ubicacion
    WHERE u.nombre_ubicacion = :nombre_ubicacion
      AND h.hora_entrega     = :hora_entrega
    LIMIT 1
");
$stmtH->execute([":nombre_ubicacion" => $nombre_ubicacion, ":hora_entrega" => $hora_entrega]);
$id_horario = $stmtH->fetchColumn();

if (!$id_horario) {
    echo json_encode(["ok" => false, "mensaje" => "No se encontró el horario indicado."]);
    exit;
}

/* ── Verificar si ya se envió la notificación ── */
$stmtCheck = $conexion->prepare("
    SELECT id FROM notificaciones_ruta
    WHERE fecha_menu = :fecha AND id_horario = :id_horario
    LIMIT 1
");
$stmtCheck->execute([":fecha" => $fecha, ":id_horario" => $id_horario]);
if ($stmtCheck->fetchColumn()) {
    echo json_encode([
        "ok"      => false,
        "mensaje" => "La notificación para esta ubicación y horario ya fue enviada anteriormente."
    ]);
    exit;
}

/* ── Obtener pedidos del grupo ── */
$sql = "SELECT
            p.id_pedido,
            p.folio,
            p.nombre_cliente,
            p.correo_cliente,
            p.telefono,
            p.total,
            h.hora_entrega,
            u.nombre_ubicacion
        FROM pedidos p
        INNER JOIN horarios_ubicacion h ON p.id_horario = h.id_horario
        INNER JOIN ubicaciones u        ON h.id_ubicacion = u.id_ubicacion
        INNER JOIN (
            SELECT pm2.id_pedido, MIN(md2.fecha) AS fecha_menu
            FROM pedido_menus pm2
            INNER JOIN detalle_pedido dp2 ON dp2.id_pedido_menu = pm2.id_pedido_menu
            INNER JOIN productos pr2      ON pr2.id_producto    = dp2.id_producto
            INNER JOIN menu_dia md2       ON md2.id_menu        = pr2.id_menu
            GROUP BY pm2.id_pedido
        ) AS mi ON mi.id_pedido = p.id_pedido
        WHERE mi.fecha_menu      = :fecha
          AND u.nombre_ubicacion = :nombre_ubicacion
          AND h.hora_entrega     = :hora_entrega
          AND p.es_prueba        = 0
        ORDER BY p.nombre_cliente ASC";

$stmt = $conexion->prepare($sql);
$stmt->execute([
    ":fecha"            => $fecha,
    ":nombre_ubicacion" => $nombre_ubicacion,
    ":hora_entrega"     => $hora_entrega,
]);
$pedidos = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($pedidos)) {
    echo json_encode([
        "ok"      => false,
        "mensaje" => "No se encontraron pedidos para esa ubicación, horario y fecha."
    ]);
    exit;
}

$enviados  = 0;
$sinCorreo = 0;
$errores   = 0;

/* correo desactivado — reemplazado por WhatsApp */
if (false) {

$ubicacionBonita = htmlspecialchars($nombre_ubicacion);
$horaBonita      = date("g:i A", strtotime($hora_entrega));

foreach ($pedidos as $pedido) {
    $correo = trim($pedido["correo_cliente"] ?? "");

    if ($correo === "" || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        $sinCorreo++;
        continue;
    }

    $nombre = $pedido["nombre_cliente"];
    $folio  = $pedido["folio"];

    $asunto = "¡Tu pedido ya llegó! · Zabisu";

    $mensaje = "
        <div style='font-family:Arial,Helvetica,sans-serif;max-width:520px;margin:0 auto;color:#222;line-height:1.6;'>

            <div style='background:#0c0c0f;padding:28px 24px 20px;border-radius:14px 14px 0 0;text-align:center;'>
                <p style='margin:0 0 6px;font-size:11px;font-weight:700;letter-spacing:3px;color:#ff7a00;text-transform:uppercase;'>
                    Zabisu
                </p>
                <h1 style='margin:0;font-size:26px;font-weight:800;color:#fff;'>
                    ¡Tu pedido llegó al punto!
                </h1>
            </div>

            <div style='background:#f9f9f9;padding:24px;border-radius:0 0 14px 14px;'>
                <p>Hola, <strong>" . htmlspecialchars($nombre) . "</strong>.</p>
                <p>🎉 Tu pedido de Zabisu ya se encuentra en el punto de entrega. ¡Pasa a recogerlo!</p>

                <table style='width:100%;border-collapse:collapse;margin:20px 0;'>
                    <tr style='border-bottom:1px solid #e5e5e5;'>
                        <td style='padding:10px 0;color:#888;font-weight:700;width:140px;'>Folio</td>
                        <td style='padding:10px 0;font-weight:700;color:#222;'>" . htmlspecialchars($folio) . "</td>
                    </tr>
                    <tr style='border-bottom:1px solid #e5e5e5;'>
                        <td style='padding:10px 0;color:#888;font-weight:700;'>Punto de entrega</td>
                        <td style='padding:10px 0;color:#222;'>{$ubicacionBonita}</td>
                    </tr>
                    <tr>
                        <td style='padding:10px 0;color:#888;font-weight:700;'>Horario</td>
                        <td style='padding:10px 0;color:#222;'>{$horaBonita}</td>
                    </tr>
                </table>

                <p style='margin-top:6px;'>Si tienes alguna duda, comunícate con nosotros.</p>
                <p style='margin-top:24px;font-size:13px;color:#aaa;'>
                    Zabisu — <em>Sabor y Servicio</em>
                </p>
            </div>

        </div>
    ";

    $resultado = enviarCorreo($correo, $nombre, $asunto, $mensaje);
    if ($resultado) {
        $enviados++;
    } else {
        $errores++;
    }
}
} /* fin correo desactivado */

/* ── Registrar notificación enviada ── */
$stmtLog = $conexion->prepare("
    INSERT IGNORE INTO notificaciones_ruta (fecha_menu, id_horario, enviado_en, tipo, total_enviados)
    VALUES (:fecha, :id_horario, NOW(), 'manual', :total_enviados)
");
$stmtLog->execute([
    ":fecha"          => $fecha,
    ":id_horario"     => $id_horario,
    ":total_enviados" => count($pedidos),
]);

/* ── WhatsApp — notificación masiva de llegada ── */
$horaBonita = date("g:i A", strtotime($hora_entrega));
$mensajesWA = [];
foreach ($pedidos as $p) {
    if (empty($p["telefono"])) continue;
    $mensajesWA[] = [
        "phone"   => $p["telefono"],
        "message" => "📍 *¡Tu pedido llegó al punto!*\n\nHola *{$p['nombre_cliente']}* 👋\n\n🎉 Tu pedido de Zabisu ya se encuentra en el punto de entrega. ¡Pasa a recogerlo!\n\n*Folio:* " . strtoupper($p['folio']) . "\n*Punto de entrega:* {$nombre_ubicacion}\n*Retira antes de:* {$horaBonita}\n\n_Si tienes alguna duda, comunícate con nosotros._ 🍱",
    ];
}
$resultadoWA = enviarWhatsAppBulk($mensajesWA);

$clientesWA = array_values(array_map(function ($p) {
    return [
        "nombre"   => $p["nombre_cliente"],
        "telefono" => $p["telefono"] ?? "",
        "folio"    => $p["folio"],
    ];
}, $pedidos));

echo json_encode([
    "ok"      => true,
    "total"   => count($pedidos),
    "queued"  => $resultadoWA['queued'] ?? 0,
    "clientes" => $clientesWA,
]);
