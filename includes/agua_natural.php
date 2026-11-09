<?php
/*
    Agua natural: todos los días, además del agua del día.

    El restaurante sube su menú igual que siempre (con su agua de sabor).
    Aquí se agrega solo un producto "Agua natural" (categoría Agua) a cada
    tipo de menú, para que el cliente y la tableta puedan elegir entre las dos.
    Al ser un producto normal, sale en el ticket, en el resumen de producción
    y en la confirmación como cualquier otro.

    - El agua del día siempre va primero (es la que se pone si no eligen).
    - En la captura del menú (productos_menu) y en el histórico no aparece:
      del lado del restaurante no cambia nada.
    - No se ofrece como extra de $20.
*/

const AGUA_NATURAL = "Agua natural";

function aguaEsNatural($producto): bool
{
    $nombre = is_array($producto) ? ($producto["nombre"] ?? "") : (string)$producto;
    return mb_strtolower(trim($nombre)) === mb_strtolower(AGUA_NATURAL);
}

/* Crea el "Agua natural" que falte en cada tipo de menú (Zabisu / Ejecutivo) que tenga productos */
function aguaNaturalAsegurar(PDO $conexion, int $idMenu): void
{
    static $hechos = [];
    if ($idMenu <= 0 || isset($hechos[$idMenu])) return;
    $hechos[$idMenu] = true;

    $stmt = $conexion->prepare(
        "SELECT tipo_menu,
                SUM(categoria = 'Agua' AND nombre = :nombre AND disponible = 1) AS tiene,
                SUM(categoria = 'Agua' AND nombre = :nombre2) AS existe
           FROM productos
          WHERE id_menu = :id_menu AND categoria IN ('Plato fuerte', 'Agua')
          GROUP BY tipo_menu"
    );
    $stmt->execute([":nombre" => AGUA_NATURAL, ":nombre2" => AGUA_NATURAL, ":id_menu" => $idMenu]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
        if ((int)$fila["tiene"] > 0) continue;
        if ((int)$fila["existe"] > 0) {
            // Estaba como no disponible (p. ej. al editar el menú): se vuelve a activar
            $conexion->prepare(
                "UPDATE productos SET disponible = 1
                  WHERE id_menu = :id_menu AND tipo_menu = :tipo AND categoria = 'Agua' AND nombre = :nombre"
            )->execute([":id_menu" => $idMenu, ":tipo" => $fila["tipo_menu"], ":nombre" => AGUA_NATURAL]);
            continue;
        }
        $conexion->prepare(
            "INSERT INTO productos (id_menu, tipo_menu, categoria, nombre, descripcion, disponible)
             VALUES (:id_menu, :tipo, 'Agua', :nombre, NULL, 1)"
        )->execute([":id_menu" => $idMenu, ":tipo" => $fila["tipo_menu"], ":nombre" => AGUA_NATURAL]);
    }
}

/* En una lista de aguas: la del día primero, la natural al final */
function aguaOrdenar(array $aguas): array
{
    usort($aguas, fn($a, $b) => (int)aguaEsNatural($a) <=> (int)aguaEsNatural($b));
    return $aguas;
}

/* Aplica aguaOrdenar a $menusPorTipo[tipo]["Agua"] */
function aguaOrdenarMenus(array &$menusPorTipo): void
{
    foreach ($menusPorTipo as $tipo => $cats) {
        if (!empty($cats["Agua"])) {
            $menusPorTipo[$tipo]["Agua"] = aguaOrdenar($cats["Agua"]);
        }
    }
}

/*
    El agua que eligieron para un menú: si el id enviado es una de las aguas
    de ese tipo de menú se respeta; si no, va la del día.
*/
function aguaElegida(array $aguasDelTipo, $idEnviado): ?string
{
    if (empty($aguasDelTipo)) return null;
    foreach ($aguasDelTipo as $a) {
        if ((string)$a["id_producto"] === (string)$idEnviado) return (string)$a["id_producto"];
    }
    return (string)$aguasDelTipo[0]["id_producto"];
}

/* SQL para dejar fuera el agua natural en listados del restaurante (alias de la tabla productos) */
function aguaSqlSinNatural(string $alias = ""): string
{
    $p = $alias !== "" ? $alias . "." : "";
    return "NOT ({$p}categoria = 'Agua' AND {$p}nombre = '" . AGUA_NATURAL . "')";
}
