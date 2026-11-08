<?php
/*
    Inventario desechable: acciones del escáner (responde JSON).

    POST accion=
      buscar           codigo                         → artículo y piezas del paquete, o nuevo=true
      registrar_codigo codigo, id_item, piezas        → guarda a qué artículo pertenece el código
      borrar_codigo    codigo                         → quita un código mal registrado
      recibir          lineas (JSON: id_item, paquetes, piezas)       → suma al stock (entrada)
      contar           lineas (JSON: id_item, total)                  → deja el stock en lo contado (ajuste)
*/
require_once "../config/db.php";
require_once "auth_check.php";
require_once "../includes/inventario.php";

header("Content-Type: application/json; charset=UTF-8");

function responder(array $datos): void
{
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    responder(["ok" => false, "mensaje" => "Método no permitido."]);
}

inventarioPrepararTablas($conexion);

$accion = $_POST["accion"] ?? "";

$articulos = [];
foreach ($conexion->query("SELECT id_item, nombre, stock_actual FROM inventario_desechable")->fetchAll(PDO::FETCH_ASSOC) as $a) {
    $articulos[(int)$a["id_item"]] = $a;
}

if ($accion === "buscar") {
    $codigo = inventarioLimpiarCodigo($_POST["codigo"] ?? "");
    if ($codigo === "") responder(["ok" => false, "mensaje" => "Código vacío."]);

    $stmt = $conexion->prepare("SELECT id_item, piezas FROM inventario_codigos WHERE codigo = :c");
    $stmt->execute([":c" => $codigo]);
    $fila = $stmt->fetch(PDO::FETCH_ASSOC);

    // Una etiqueta propia se reconoce aunque se haya impreso en otro lado
    if (!$fila && preg_match('/^ZB-(\d{2})-(\d{4})$/', $codigo, $m) && isset($articulos[(int)$m[1]]) && (int)$m[2] > 0) {
        $fila = ["id_item" => (int)$m[1], "piezas" => (int)$m[2]];
    }

    if (!$fila || !isset($articulos[(int)$fila["id_item"]])) {
        responder(["ok" => true, "nuevo" => true, "codigo" => $codigo]);
    }
    $art = $articulos[(int)$fila["id_item"]];
    responder([
        "ok" => true, "nuevo" => false, "codigo" => $codigo,
        "id_item" => (int)$art["id_item"], "nombre" => $art["nombre"],
        "piezas" => (int)$fila["piezas"], "stock" => (int)$art["stock_actual"],
    ]);
}

if ($accion === "registrar_codigo") {
    $codigo = inventarioLimpiarCodigo($_POST["codigo"] ?? "");
    $idItem = (int)($_POST["id_item"] ?? 0);
    $piezas = (int)($_POST["piezas"] ?? 0);
    if ($codigo === "" || !isset($articulos[$idItem]) || $piezas < 1 || $piezas > 100000) {
        responder(["ok" => false, "mensaje" => "Revisa el producto y las piezas del paquete."]);
    }
    $conexion->prepare(
        "INSERT INTO inventario_codigos (codigo, id_item, piezas) VALUES (:c, :i, :p)
         ON DUPLICATE KEY UPDATE id_item = VALUES(id_item), piezas = VALUES(piezas)"
    )->execute([":c" => $codigo, ":i" => $idItem, ":p" => $piezas]);
    responder([
        "ok" => true, "codigo" => $codigo, "id_item" => $idItem, "nombre" => $articulos[$idItem]["nombre"],
        "piezas" => $piezas, "stock" => (int)$articulos[$idItem]["stock_actual"],
    ]);
}

if ($accion === "borrar_codigo") {
    $codigo = inventarioLimpiarCodigo($_POST["codigo"] ?? "");
    $conexion->prepare("DELETE FROM inventario_codigos WHERE codigo = :c")->execute([":c" => $codigo]);
    responder(["ok" => true]);
}

if ($accion === "recibir" || $accion === "contar") {
    $lineas = json_decode($_POST["lineas"] ?? "[]", true);
    if (!is_array($lineas) || empty($lineas)) {
        responder(["ok" => false, "mensaje" => "No hay nada que guardar."]);
    }

    // Juntar por artículo (pueden venir varios paquetes distintos del mismo)
    $porArticulo = [];
    $detalle     = [];
    foreach ($lineas as $l) {
        $idItem = (int)($l["id_item"] ?? 0);
        if (!isset($articulos[$idItem])) continue;
        if ($accion === "recibir") {
            $paquetes = (int)($l["paquetes"] ?? 0);
            $piezas   = (int)($l["piezas"] ?? 0);
            if ($paquetes < 1 || $piezas < 1 || $paquetes > 10000 || $piezas > 100000) continue;
            $porArticulo[$idItem] = ($porArticulo[$idItem] ?? 0) + $paquetes * $piezas;
            $detalle[$idItem][] = $paquetes . " paq. de " . $piezas;
        } else {
            $total = (int)($l["total"] ?? -1);
            if ($total < 0 || $total > 10000000) continue;
            $porArticulo[$idItem] = $total;
        }
    }
    if (empty($porArticulo)) {
        responder(["ok" => false, "mensaje" => "Revisa las cantidades: ninguna es válida."]);
    }

    try {
        $conexion->beginTransaction();
        $stmtStock = $conexion->prepare("SELECT stock_actual FROM inventario_desechable WHERE id_item = :i FOR UPDATE");
        $stmtUpd   = $conexion->prepare("UPDATE inventario_desechable SET stock_actual = stock_actual + :d WHERE id_item = :i");
        $stmtMov   = $conexion->prepare(
            "INSERT INTO inventario_movimientos (id_item, tipo, cantidad, notas) VALUES (:i, :t, :c, :n)"
        );
        $resultado = [];
        foreach ($porArticulo as $idItem => $valor) {
            $stmtStock->execute([":i" => $idItem]);
            $antes = (int)$stmtStock->fetchColumn();
            if ($accion === "recibir") {
                $delta = $valor;
                $nota  = "Recepción con escáner: " . implode(", ", $detalle[$idItem]);
            } else {
                $delta = $valor - $antes;
                $nota  = "Conteo físico: había " . $antes . " en sistema, se contaron " . $valor;
            }
            if ($delta !== 0) {
                $stmtUpd->execute([":d" => $delta, ":i" => $idItem]);
            }
            // El conteo queda en el historial aunque coincida, para saber cuándo se contó
            $stmtMov->execute([
                ":i" => $idItem,
                ":t" => $accion === "recibir" ? "entrada" : "ajuste",
                ":c" => $delta,
                ":n" => mb_substr($nota, 0, 255),
            ]);
            $resultado[] = ["id_item" => $idItem, "nombre" => $articulos[$idItem]["nombre"], "antes" => $antes, "despues" => $antes + $delta];
        }
        $conexion->commit();
        responder(["ok" => true, "articulos" => $resultado]);
    } catch (Exception $e) {
        $conexion->rollBack();
        responder(["ok" => false, "mensaje" => "No se pudo guardar. Intenta de nuevo."]);
    }
}

responder(["ok" => false, "mensaje" => "Acción no reconocida."]);
