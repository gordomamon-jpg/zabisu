<?php
/*
    Decoración de temporada para el ticket (Día de Muertos).
    Todo es SVG en negro sobre blanco: la impresora térmica solo imprime
    negro, así que nada de grises ni degradados.
*/

// Temporada activa (inclusive). Fuera de estas fechas el ticket es el normal.
const TICKET_MUERTOS_DESDE = '2026-10-26';
const TICKET_MUERTOS_HASTA = '2026-11-02';

/*
    Qué decoración lleva el ticket: 0 = ninguna, 1 = papel picado,
    2 = altar, 3 = calaveritas. Se sortea con el folio, así cada pedido
    sale con una al azar pero al reimprimirlo sale la misma.
    ?tema=1|2|3 fuerza una en cualquier fecha (para mostrarlas antes de
    que empiece la temporada).
*/
function temaTicket(string $folio): int
{
    $forzado = (int)($_GET["tema"] ?? 0);
    if ($forzado >= 1 && $forzado <= 3) return $forzado;
    $hoy = date('Y-m-d');
    if ($hoy < TICKET_MUERTOS_DESDE || $hoy > TICKET_MUERTOS_HASTA) return 0;
    return abs(crc32("tema-" . $folio)) % 3 + 1;
}

// ── Piezas sueltas ──────────────────────────────────────────────

// Bandera de papel picado (40×46) con motivo calado en blanco
function svgBanderaPapelPicado(float $x, float $y, string $motivo): string
{
    $w = 40; $h = 46;
    // Borde inferior en ondas
    $d = "M{$x},{$y} h{$w} v" . ($h - 6);
    for ($i = 0; $i < 5; $i++) $d .= " a4,4 0 0 1 -8,0";
    $d .= " Z";
    $s = "<path d='{$d}' fill='#000'/>";
    // Fila de agujeritos arriba (como el papel picado real)
    for ($i = 0; $i < 6; $i++) $s .= "<circle cx='" . ($x + 5 + $i * 6) . "' cy='" . ($y + 5) . "' r='1.3' fill='#fff'/>";
    $cx = $x + $w / 2; $cy = $y + 22;
    if ($motivo === "calavera") {
        $s .= "<circle cx='{$cx}' cy='" . ($cy - 2) . "' r='9' fill='#fff'/>"
            . "<rect x='" . ($cx - 6) . "' y='" . ($cy + 3) . "' width='12' height='8' rx='2' fill='#fff'/>"
            . "<circle cx='" . ($cx - 3.6) . "' cy='" . ($cy - 2) . "' r='2.7' fill='#000'/>"
            . "<circle cx='" . ($cx + 3.6) . "' cy='" . ($cy - 2) . "' r='2.7' fill='#000'/>"
            . "<path d='M{$cx}," . ($cy + 1.5) . " l-1.6,2.6 h3.2 z' fill='#000'/>"
            . "<path d='M" . ($cx - 4) . "," . ($cy + 7) . " h8 M" . ($cx - 2) . "," . ($cy + 5.5) . " v3 M{$cx}," . ($cy + 5.5) . " v3 M" . ($cx + 2) . "," . ($cy + 5.5) . " v3' stroke='#000' stroke-width='1'/>";
    } elseif ($motivo === "flor") {
        for ($a = 0; $a < 360; $a += 45) {
            $px = $cx + 7.5 * cos(deg2rad($a)); $py = $cy + 7.5 * sin(deg2rad($a));
            $s .= "<circle cx='" . round($px, 1) . "' cy='" . round($py, 1) . "' r='3.6' fill='#fff'/>";
        }
        $s .= "<circle cx='{$cx}' cy='{$cy}' r='4' fill='#fff'/><circle cx='{$cx}' cy='{$cy}' r='1.8' fill='#000'/>";
    } else { // rombos
        foreach ([[-7, -6], [7, -6], [0, 0], [-7, 6], [7, 6]] as [$dx, $dy]) {
            $px = $cx + $dx; $py = $cy + $dy;
            $s .= "<path d='M{$px}," . ($py - 4.5) . " l4.5,4.5 l-4.5,4.5 l-4.5,-4.5 z' fill='#fff'/>";
        }
    }
    return $s;
}

// Tira de papel picado colgando de un hilo, a todo lo ancho
function svgPapelPicado(int $ancho = 272, int $banderas = 6): string
{
    $motivos = ["calavera", "flor", "rombos"];
    $paso = ($ancho - 4) / $banderas;
    $s = "<svg xmlns='http://www.w3.org/2000/svg' width='100%' viewBox='0 0 {$ancho} 58' style='display:block'>"
       . "<path d='M0,3 Q" . ($ancho / 2) . ",11 {$ancho},3' fill='none' stroke='#000' stroke-width='1.4'/>";
    for ($i = 0; $i < $banderas; $i++) {
        $x = 2 + $i * $paso + ($paso - 40) / 2;
        $t = ($x + 20) / $ancho;                    // caída del hilo (parábola)
        $y = 3 + 8 * 4 * $t * (1 - $t) * 0.5 + 1;
        $s .= svgBanderaPapelPicado(round($x, 1), round($y, 1), $motivos[$i % 3]);
    }
    return $s . "</svg>";
}

// Calaverita de azúcar (contorno), caja 44×44
function gCalaverita(float $x, float $y, float $esc = 1): string
{
    return "<g transform='translate({$x},{$y}) scale({$esc})'>"
         . "<path d='M6,19 A16,16 0 0 1 38,19 C38,26 34,28 33,30 L33,38 Q33,41 30,41 L14,41 Q11,41 11,38 L11,30 C10,28 6,26 6,19 Z' fill='#fff' stroke='#000' stroke-width='2.2'/>"
         . "<circle cx='15' cy='20' r='5.2' fill='#000'/><circle cx='29' cy='20' r='5.2' fill='#000'/>"
         . "<circle cx='15' cy='20' r='1.6' fill='#fff'/><circle cx='29' cy='20' r='1.6' fill='#fff'/>"
         . "<path d='M22,25.5 l-2.4,3.4 h4.8 z' fill='#000'/>"
         . "<path d='M15,34 h14 M17.5,31.5 v5 M20.5,31.5 v5 M23.5,31.5 v5 M26.5,31.5 v5' stroke='#000' stroke-width='1.4'/>"
         . "<circle cx='22' cy='9' r='1.6' fill='#000'/><circle cx='18.2' cy='10.6' r='1.1' fill='#000'/><circle cx='25.8' cy='10.6' r='1.1' fill='#000'/>"
         . "<path d='M8.5,25 q2,2 4,0 M31.5,25 q2,2 4,0' fill='none' stroke='#000' stroke-width='1.1'/>"
         . "</g>";
}

// Velita encendida, caja 16×42
function gVela(float $x, float $y, float $esc = 1): string
{
    return "<g transform='translate({$x},{$y}) scale({$esc})'>"
         . "<path d='M8,1 C11.5,6.5 12.5,10 8,14.5 C3.5,10 4.5,6.5 8,1 Z' fill='#000'/>"
         . "<path d='M8,8 C9.2,10 9.2,11.5 8,12.8 C6.8,11.5 6.8,10 8,8 Z' fill='#fff'/>"
         . "<path d='M1,5 l2,1.5 M15,5 l-2,1.5 M8,-2 v1.5' stroke='#000' stroke-width='1.1'/>"
         . "<path d='M8,14.5 v2' stroke='#000' stroke-width='1.2'/>"
         . "<rect x='3' y='16.5' width='10' height='23' rx='1' fill='#fff' stroke='#000' stroke-width='1.8'/>"
         . "<path d='M3,18 h10 v3 q-1.5,3 -2.5,0 q-1,4 -2.5,0 q-1.5,2.5 -2,0 q-1,2 -3,0 z' fill='#000'/>"
         . "<path d='M0,40.5 h16' stroke='#000' stroke-width='1.8'/>"
         . "</g>";
}

// Flor de cempasúchil, caja 30×30
function gCempasuchil(float $x, float $y, float $esc = 1): string
{
    $s = "<g transform='translate({$x},{$y}) scale({$esc})'>";
    for ($a = 0; $a < 360; $a += 30) {
        $s .= "<circle cx='" . round(15 + 9.5 * cos(deg2rad($a)), 1) . "' cy='" . round(15 + 9.5 * sin(deg2rad($a)), 1) . "' r='4.6' fill='#fff' stroke='#000' stroke-width='1.3'/>";
    }
    for ($a = 15; $a < 375; $a += 45) {
        $s .= "<circle cx='" . round(15 + 5.2 * cos(deg2rad($a)), 1) . "' cy='" . round(15 + 5.2 * sin(deg2rad($a)), 1) . "' r='3.2' fill='#fff' stroke='#000' stroke-width='1.2'/>";
    }
    return $s . "<circle cx='15' cy='15' r='3.4' fill='#000'/></g>";
}

// Huesitos cruzados, caja 30×30
function gHuesos(float $x, float $y, float $esc = 1): string
{
    $hueso = "<path d='M4,13 a3,3 0 1 1 3,-3 l16,0 a3,3 0 1 1 3,3 a3,3 0 1 1 -3,3 l-16,0 a3,3 0 1 1 -3,-3 z' fill='#fff' stroke='#000' stroke-width='1.6'/>";
    return "<g transform='translate({$x},{$y}) scale({$esc})'>"
         . "<g transform='rotate(35 15 15) translate(0,2)'>{$hueso}</g>"
         . "<g transform='rotate(-35 15 15) translate(0,2)'>{$hueso}</g>"
         . "</g>";
}

function svgFila(int $ancho, int $alto, string $contenido): string
{
    return "<svg xmlns='http://www.w3.org/2000/svg' width='100%' viewBox='0 0 {$ancho} {$alto}' style='display:block'>{$contenido}</svg>";
}

// ── Composiciones por propuesta ─────────────────────────────────

// Propuesta 2: altar (cempasúchil, velitas y calaverita al centro)
function svgAltar(int $ancho = 272): string
{
    $c = $ancho / 2;
    return svgFila($ancho, 50,
        gCempasuchil(4, 14) . gVela(44, 6) . gCempasuchil(70, 16, .8)
        . gCalaverita($c - 22, 3)
        . gCempasuchil($ancho - 94, 16, .8) . gVela($ancho - 60, 6) . gCempasuchil($ancho - 34, 14));
}

// Propuesta 3: tira de calaveritas y huesitos
function svgCalaveritas(int $ancho = 272): string
{
    $n = (int)floor($ancho / 34);
    if ($n % 2 === 0) $n--;                     // impar: empieza y termina en calaverita
    $x = ($ancho - $n * 34) / 2; $s = "";
    for ($i = 0; $i < $n; $i++, $x += 34) {
        $s .= $i % 2 === 0 ? gCalaverita($x, 1, .75) : gHuesos($x + 1, 3, .9);
    }
    return svgFila($ancho, 36, $s);
}

// Línea separadora de flores pequeñitas (sustituye a la línea punteada)
function svgSeparadorFlores(int $ancho = 272): string
{
    $s = "<path d='M0,8 H{$ancho}' stroke='#000' stroke-width='1' stroke-dasharray='3 3'/>";
    for ($x = 10; $x < $ancho; $x += 44) $s .= gCempasuchil($x, 1, .48);
    return svgFila($ancho, 16, $s);
}

/*
    Calaverita literaria con el primer nombre del cliente. Se elige una de
    varias según el folio, así no sale siempre la misma pero el mismo
    pedido imprime siempre la misma.
*/
function calaveritaLiteraria(string $nombreCliente, string $folio): array
{
    $n = trim(preg_split('/\s+/', trim($nombreCliente))[0] ?? "");
    $n = $n !== "" ? mb_convert_case(mb_strtolower($n, "UTF-8"), MB_CASE_TITLE, "UTF-8") : "Nuestro cliente";
    $versos = [
        ["La Catrina vino por {$n},", "con su vestido elegante;", "pero al ver su comida Zabisu,", "¡se quedó a comer al instante!"],
        ["Estaba {$n} en su oficina", "cuando la Huesuda llegó;", "olió el guisado de Zabisu", "y hasta el pan se lo robó."],
        ["Ni la Flaca pudo llevarse", "a {$n} en este día:", "\"Primero mi menú Zabisu,", "luego me voy contigo, mija\"."],
        ["La muerte buscó a {$n}", "por toda la ciudad;", "y en la fila de Zabisu", "¡se formó a almorzar!"],
    ];
    return $versos[abs(crc32($folio)) % count($versos)];
}

// ── Lo que usa ticket.php ───────────────────────────────────────

function cssDecoracionTicket(): string
{
    return "
        .deco { margin: 0 0 6px; }
        .deco--abajo { margin: 6px 0 0; }
        .deco-titulo { text-align: center; font-weight: 900; font-size: 13px; letter-spacing: 2px; margin: 8px 0 4px; }
        .sep-flores { margin: 6px 0; }
        .calaverita { border: 2px solid #000; border-radius: 8px; padding: 8px 10px; margin: 8px 0; text-align: center; }
        .calaverita__titulo { font-size: 11px; font-weight: 900; letter-spacing: 1.5px; margin-bottom: 4px; }
        .calaverita__verso { font-size: 12px; font-style: italic; line-height: 1.45; }
    ";
}

function decoracionArriba(int $tema): string
{
    $svg = [1 => "svgPapelPicado", 2 => "svgAltar", 3 => "svgCalaveritas"][$tema] ?? null;
    return $svg ? "<div class='deco'>" . $svg() . "</div>" : "";
}

// Separador entre secciones: guirnalda de cempasúchil en el altar, línea normal en los demás
function separadorTicket(int $tema): string
{
    return $tema === 2 ? "<div class='sep-flores'>" . svgSeparadorFlores() . "</div>" : "<div class='line'></div>";
}

function decoracionAbajo(int $tema, string $nombre, string $semilla): string
{
    if ($tema === 0) return "";
    $html = "";
    if ($tema === 3) {
        $versos = array_map('htmlspecialchars', calaveritaLiteraria($nombre, $semilla));
        $html .= "<div class='calaverita'><div class='calaverita__titulo'>&dagger; CALAVERITA PARA TI &dagger;</div>"
               . "<div class='calaverita__verso'>" . implode("<br>", $versos) . "</div></div>";
    }
    $html .= "<div class='deco-titulo'>¡FELIZ DÍA DE MUERTOS!</div>";
    $svg = [1 => "svgPapelPicado", 2 => "svgAltar", 3 => "svgCalaveritas"][$tema];
    return $html . "<div class='deco deco--abajo'>" . $svg() . "</div>";
}
