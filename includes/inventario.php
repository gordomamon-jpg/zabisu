<?php
/*
    Inventario desechable: códigos de barras de los paquetes.

    - inventario_codigos: cada código de barras (del proveedor o una etiqueta
      impresa por Zabisu) apunta a un artículo y a cuántas piezas trae el paquete.
      Se crea sola la primera vez, como las tablas de Finanzas.
    - Las etiquetas propias usan Code 128 con el formato ZB-<artículo>-<piezas>,
      p. ej. ZB-04-0100 = Tenedor, paquete de 100.
*/

function inventarioPrepararTablas(PDO $conexion): void
{
    $conexion->exec("CREATE TABLE IF NOT EXISTS inventario_codigos (
        codigo     VARCHAR(64)  NOT NULL,
        id_item    INT          NOT NULL,
        piezas     INT          NOT NULL DEFAULT 1,
        propio     TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = etiqueta impresa por Zabisu',
        creado_en  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (codigo),
        KEY idx_item (id_item)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/* Lo que manda un escáner trae a veces espacios o saltos: se limpia igual siempre */
function inventarioLimpiarCodigo(string $codigo): string
{
    return strtoupper(substr(preg_replace('/[^A-Za-z0-9\-\.]/', '', $codigo), 0, 64));
}

function inventarioCodigoPropio(int $idItem, int $piezas): string
{
    return sprintf("ZB-%02d-%04d", $idItem, $piezas);
}

/*
    Code 128 (juego B) como SVG, sin librerías.
    Devuelve las barras en unidades de módulo; el SVG se escala con CSS.
*/
function inventarioCode128Svg(string $texto, int $alto = 60): string
{
    static $patrones = [
        "212222","222122","222221","121223","121322","131222","122213","122312","132212","221213",
        "221312","231212","112232","122132","122231","113222","123122","123221","223211","221132",
        "221231","213212","223112","312131","311222","321122","321221","312212","322112","322211",
        "212123","212321","232121","111323","131123","131321","112313","132113","132311","211313",
        "231113","231311","112133","112331","132131","113123","113321","133121","313121","211331",
        "231131","213113","213311","213131","311123","311321","331121","312113","312311","332111",
        "314111","221411","431111","111224","111422","121124","121421","141122","141221","112214",
        "112412","122114","122411","142112","142211","241211","221114","413111","241112","134111",
        "111242","121142","121241","114212","124112","124211","411212","421112","421211","212141",
        "214121","412121","111143","111341","131141","114113","114311","411113","411311","113141",
        "114131","311141","411131","211412","211214","211232","2331112",
    ];
    $codigos = [104]; // START B
    $suma = 104;
    $chars = str_split($texto);
    foreach ($chars as $i => $c) {
        $valor = ord($c) - 32;
        if ($valor < 0 || $valor > 94) $valor = 0;
        $codigos[] = $valor;
        $suma += $valor * ($i + 1);
    }
    $codigos[] = $suma % 103; // dígito de control
    $codigos[] = 106;         // STOP

    $x = 10; // zona en blanco a la izquierda (10 módulos)
    $barras = "";
    foreach ($codigos as $cod) {
        $patron = $patrones[$cod];
        foreach (str_split($patron) as $j => $ancho) {
            $ancho = (int)$ancho;
            if ($j % 2 === 0) {
                $barras .= '<rect x="' . $x . '" y="0" width="' . $ancho . '" height="' . $alto . '"/>';
            }
            $x += $ancho;
        }
    }
    $total = $x + 10; // zona en blanco a la derecha
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $total . ' ' . $alto . '" preserveAspectRatio="none" shape-rendering="crispEdges" role="img" aria-label="' . htmlspecialchars($texto) . '">'
         . '<rect width="' . $total . '" height="' . $alto . '" fill="#fff"/><g fill="#000">' . $barras . '</g></svg>';
}
