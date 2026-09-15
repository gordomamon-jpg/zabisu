<?php
/*
    Consulta el resultado real de un envío masivo de WhatsApp iniciado por
    notificar_ruta.php (que ya no espera a que termine, solo entrega un
    job_id). Mientras el job siga en curso regresa en_proceso=true; cuando
    termina, clasifica el resultado por número y confirma el candado de
    notificaciones_ruta con el conteo real — igual que hacía antes
    notificar_ruta.php de forma síncrona.
*/
require_once "../config/db.php";
require_once "auth_check.php";
require_once "../includes/enviar_whatsapp.php";

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["ok" => false, "mensaje" => "Método no permitido."]);
    exit;
}

$jobId            = trim($_POST["job_id"] ?? "");
$nombre_ubicacion = trim($_POST["nombre_ubicacion"] ?? "");
$hora_entrega     = trim($_POST["hora_entrega"] ?? "");
$fecha            = trim($_POST["fecha"] ?? date("Y-m-d"));

if ($jobId === "" || $nombre_ubicacion === "" || $hora_entrega === "") {
    echo json_encode(["ok" => false, "mensaje" => "Datos incompletos."]);
    exit;
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
    $fecha = date("Y-m-d");
}

$estado = consultarEstadoWhatsAppBulk($jobId);

if (!($estado["ok"] ?? false)) {
    echo json_encode(["ok" => false, "mensaje" => $estado["error"] ?? "No se pudo consultar el estado del envío."]);
    exit;
}

if (($estado["status"] ?? "") !== "done") {
    echo json_encode(["ok" => true, "en_proceso" => true]);
    exit;
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

/* ── Mismos pedidos del grupo que ya se usaron para mandar el WhatsApp ── */
$sql = "SELECT
            p.id_pedido,
            p.folio,
            p.nombre_cliente,
            p.telefono
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

function nre_normalizarTel(?string $tel): string
{
    $d = preg_replace('/\D/', '', $tel ?? '');
    if (strlen($d) === 10) $d = '52' . $d;
    return $d;
}

$resultadosPorTel = [];
foreach (($estado["resultados"] ?? []) as $r) {
    $resultadosPorTel[$r["phone"]] = $r;
}

$enviadosOk  = [];
$fallidos    = [];
$sinTelefono = [];

foreach ($pedidos as $p) {
    $telNorm = nre_normalizarTel($p["telefono"] ?? "");
    if ($telNorm === "") {
        $sinTelefono[] = $p;
        continue;
    }
    $r = $resultadosPorTel[$telNorm] ?? null;
    if ($r && $r["ok"]) {
        $enviadosOk[] = $p;
    } else {
        $fallidos[] = ["pedido" => $p, "error" => $r["error"] ?? "No se pudo confirmar el envío"];
    }
}

/*
    Confirmar el candado (ya reservado desde notificar_ruta.php) con el
    conteo real. Si el panel consulta este endpoint más de una vez tras
    terminar, simplemente vuelve a escribir el mismo resultado — idempotente.
*/
$conexion->prepare("
    UPDATE notificaciones_ruta
    SET enviado_en = NOW(), tipo = 'manual', total_enviados = :total_enviados
    WHERE fecha_menu = :fecha AND id_horario = :id_horario
")->execute([
    ":fecha"          => $fecha,
    ":id_horario"     => $id_horario,
    ":total_enviados" => count($enviadosOk),
]);

$clientesWA = array_values(array_map(function ($p) {
    return [
        "nombre"   => $p["nombre_cliente"],
        "telefono" => $p["telefono"] ?? "",
        "folio"    => $p["folio"],
    ];
}, $pedidos));

echo json_encode([
    "ok"           => true,
    "en_proceso"   => false,
    "total"        => count($pedidos),
    "enviados"     => count($enviadosOk),
    "fallidos"     => array_map(function ($f) {
        return [
            "nombre"   => $f["pedido"]["nombre_cliente"],
            "telefono" => $f["pedido"]["telefono"] ?? "",
            "folio"    => $f["pedido"]["folio"],
            "error"    => $f["error"],
        ];
    }, $fallidos),
    "sin_telefono" => count($sinTelefono),
    "clientes"     => $clientesWA,
]);
