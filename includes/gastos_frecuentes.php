<?php
/*
    Los gastos que se repiten cada semana, sacados de lo que más se ha
    capturado en los últimos 180 días. Los usan gastos_semana.php (captura)
    y hoja_gastos.php (hoja para llenar a mano), así ambas muestran la
    misma lista.
*/

/* "3 B", "3b" y "3B" son el mismo concepto */
function claveConcepto(string $c): string {
    $c = mb_strtolower(trim($c));
    $c = strtr($c, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n',"'"=>'']);
    return preg_replace('/[^a-z0-9]/', '', $c);
}

function nombreBonito(string $c): string {
    $c = trim($c);
    if (preg_match('/\d/', $c) && mb_strlen($c) <= 4) return mb_strtoupper(str_replace(' ', '', $c));
    if ($c === mb_strtolower($c) || $c === mb_strtoupper($c)) return mb_strtoupper(mb_substr($c, 0, 1)) . mb_strtolower(mb_substr($c, 1));
    return $c;
}

/*
    Devuelve [conceptos, compras, sueldos]:
    - conceptos: todo lo capturado, por clave, con su categoría (para
      ponerle categoría sola a lo que se anote)
    - compras / sueldos: lo de siempre, con nombre y el monto de la última vez
*/
function gastosFrecuentes(PDO $conexion): array {
    $historial = $conexion->query(
        "SELECT concepto, categoria, monto, semana_destino
         FROM gastos
         WHERE fecha_compra >= DATE_SUB(CURDATE(), INTERVAL 180 DAY)
         ORDER BY fecha_compra DESC, id_gasto DESC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $conceptos = []; // clave => nombre, categoria (la más reciente), semanas, ultima semana y su suma
    foreach ($historial as $h) {
        $k = claveConcepto($h['concepto']);
        if ($k === '') continue;
        if (!isset($conceptos[$k])) {
            $conceptos[$k] = [
                'nombre'    => nombreBonito($h['concepto']),
                'categoria' => $h['categoria'],
                'semanas'   => [],
                'ultSemana' => $h['semana_destino'],
                'ultMonto'  => 0.0,
            ];
        }
        $conceptos[$k]['semanas'][$h['semana_destino']] = true;
        if ($h['semana_destino'] === $conceptos[$k]['ultSemana']) {
            $conceptos[$k]['ultMonto'] += (float)$h['monto'];
        }
        // Los sueldos se llegaron a capturar como Ingredientes o Servicios; con una vez en Nómina basta
        if ($h['categoria'] === 'Nómina') $conceptos[$k]['categoria'] = 'Nómina';
    }

    // Fuera los totales sueltos ("Gasto total", "Semana"...): no se repiten igual cada semana
    $noFrecuentes = '/^(gastos?|semana|total|varios|extra|extras|insumos?|materia|comidafaltante|retaso)/';
    uasort($conceptos, fn($a, $b) => count($b['semanas']) <=> count($a['semanas']));

    // "Torillas" y "Tortilla" se juntan con "Tortillas": el más capturado se queda con el nombre
    $frecuentes = [];
    foreach ($conceptos as $k => $c) {
        if (preg_match($noFrecuentes, $k)) continue;
        foreach ($frecuentes as $k2 => $c2) {
            if ($k . 's' === $k2 || $k2 . 's' === $k || (strlen($k) >= 5 && levenshtein($k, $k2) <= 1)) {
                $frecuentes[$k2]['semanas'] += $c['semanas'];
                if ($c['categoria'] === 'Nómina') $frecuentes[$k2]['categoria'] = 'Nómina';
                $conceptos[$k]['categoria'] = $frecuentes[$k2]['categoria'];
                continue 2;
            }
        }
        $frecuentes[$k] = $c;
    }
    $frecuentes = array_filter($frecuentes, fn($c) => count($c['semanas']) >= 2);
    uasort($frecuentes, fn($a, $b) => count($b['semanas']) <=> count($a['semanas']));

    $compras = [];
    $sueldos = [];
    foreach ($frecuentes as $k => $c) {
        if ($c['categoria'] === 'Nómina') { if (count($sueldos) < 6) $sueldos[$k] = $c; }
        elseif (count($compras) < 12) $compras[$k] = $c;
    }
    if (!$compras) {
        foreach (['3B', 'Merced', 'Pollo', 'Tortillas', 'Bodega', "Sam's"] as $n) {
            $compras[claveConcepto($n)] = ['nombre' => $n, 'categoria' => 'Ingredientes', 'ultMonto' => 0.0];
        }
    }

    return [$conceptos, $compras, $sueldos];
}
