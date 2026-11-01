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

/* Categoría por palabras, para conceptos que nunca se han capturado */
function categoriaPorPalabras(string $clave): string {
    $reglas = [
        'Nómina'                => '/sueldo|nomina|salario|raya|aguinaldo/',
        'Gas / Transporte'      => '/gasolina|^gas$|gaslp|tanque|uber|didi|taxi|transporte|envio|pasaje/',
        'Empaque y desechables' => '/desechable|bolsa|cofia|empaque|contenedor|unicel|servilleta|cubierto|guante|charola|vaso/',
        'Servicios'             => '/^luz$|cfe|internet|renta|telefono|celular|recarga|basura|predial|aguapotable|garrafon/',
    ];
    foreach ($reglas as $cat => $re) {
        if (preg_match($re, $clave)) return $cat;
    }
    return 'Ingredientes';
}

/*
    La lista de "lo de siempre" se puede editar (quitar y agregar) desde
    gastos_semana.php y hoja_gastos.php. Se guarda en un archivo para que
    sea la misma en todos los celulares y computadoras; si no existe, la
    lista se arma sola con lo más capturado.
*/
function gastosListaArchivo(): string {
    // Junto a login_intentos.json: carpeta bloqueada para la web y con permiso de escritura para PHP
    return __DIR__ . '/../config/gastos_lista.json';
}

/* ['compras' => [nombres], 'sueldos' => [nombres]] o null si nunca se ha editado */
function gastosListaGuardada(): ?array {
    $archivo = gastosListaArchivo();
    if (!is_file($archivo)) return null;
    $datos = json_decode((string)file_get_contents($archivo), true);
    if (!is_array($datos) || !is_array($datos['compras'] ?? null) || !is_array($datos['sueldos'] ?? null)) return null;
    return [
        'compras' => array_values(array_filter($datos['compras'], 'is_string')),
        'sueldos' => array_values(array_filter($datos['sueldos'], 'is_string')),
    ];
}

/* null borra la lista editada y se regresa a la automática */
function gastosListaGuardar(?array $lista): bool {
    $archivo = gastosListaArchivo();
    if ($lista === null) return !is_file($archivo) || unlink($archivo);
    $tmp = $archivo . '.tmp';
    $json = json_encode($lista, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    return file_put_contents($tmp, $json, LOCK_EX) !== false && rename($tmp, $archivo);
}

/*
    Devuelve [conceptos, compras, sueldos, editada]:
    - conceptos: todo lo capturado, por clave, con su categoría (para
      ponerle categoría sola a lo que se anote)
    - compras / sueldos: lo de siempre, con nombre, categoría y el monto
      de la última vez
    - editada: true si la lista la editaron (no es la automática)
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

    $guardada = gastosListaGuardada();
    if ($guardada !== null) {
        // La lista editada manda; de lo capturado solo se toma la categoría y la última vez
        $armar = function (array $nombres, bool $esSueldo) use ($conceptos): array {
            $items = [];
            foreach ($nombres as $nombre) {
                $k = claveConcepto($nombre);
                if ($k === '' || isset($items[$k])) continue;
                $cat = $conceptos[$k]['categoria'] ?? '';
                if ($esSueldo)            $cat = 'Nómina';
                elseif ($cat === '' || $cat === 'Nómina') $cat = categoriaPorPalabras($k);
                $items[$k] = ['nombre' => $nombre, 'categoria' => $cat, 'ultMonto' => $conceptos[$k]['ultMonto'] ?? 0.0];
            }
            return $items;
        };
        $compras = $armar($guardada['compras'], false);
        $sueldos = $armar($guardada['sueldos'], true);
    }

    return [$conceptos, $compras, $sueldos, $guardada !== null];
}
