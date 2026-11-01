<?php
require_once "../config/db.php";
require_once "auth_check.php";
require_once "../includes/gastos_frecuentes.php";

/*
    Guarda la lista de "lo de siempre" que se edita en gastos_semana.php
    y hoja_gastos.php. Recibe JSON:
      {"compras": [...], "sueldos": [...]}  cualquiera de las dos; la que
                                            no venga se queda como está
      {"restablecer": true}                 regresa a la lista automática
*/

header('Content-Type: application/json; charset=UTF-8');

function responder(bool $ok, string $mensaje = ''): void {
    echo json_encode(['ok' => $ok, 'mensaje' => $mensaje], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') responder(false, 'Petición inválida.');

$datos = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($datos)) responder(false, 'Petición inválida.');

if (!empty($datos['restablecer'])) {
    gastosListaGuardar(null) ? responder(true) : responder(false, 'No se pudo restablecer la lista.');
}

/* Nombres limpios, sin repetidos ("3 B" y "3b" son el mismo) */
function limpiarNombres(array $nombres): array {
    $vistos = [];
    $limpios = [];
    foreach ($nombres as $n) {
        if (!is_string($n)) continue;
        $n = mb_substr(trim(preg_replace('/\s+/u', ' ', $n)), 0, 60);
        $k = claveConcepto($n);
        if ($k === '' || isset($vistos[$k])) continue;
        $vistos[$k] = true;
        $limpios[]  = $n;
        if (count($limpios) >= 40) break;
    }
    return $limpios;
}

[, $compras, $sueldos] = gastosFrecuentes($conexion);
$lista = [
    'compras' => array_column(array_values($compras), 'nombre'),
    'sueldos' => array_column(array_values($sueldos), 'nombre'),
];
foreach (['compras', 'sueldos'] as $grupo) {
    if (isset($datos[$grupo]) && is_array($datos[$grupo])) $lista[$grupo] = limpiarNombres($datos[$grupo]);
}

// Un nombre va en un solo grupo: si está en sueldos, sale de compras
$enSueldos = array_flip(array_map('claveConcepto', $lista['sueldos']));
$lista['compras'] = array_values(array_filter($lista['compras'], fn($n) => !isset($enSueldos[claveConcepto($n)])));

gastosListaGuardar($lista) ? responder(true) : responder(false, 'No se pudo guardar la lista.');
