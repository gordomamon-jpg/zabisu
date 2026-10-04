<?php
/*
    Opciones sin costo de un plato fuerte (p. ej. los aderezos de la
    Ensalada Ejecutiva). Se configuran en productos_menu.php, una por línea,
    en productos.opciones. Si un plato tiene opciones, elegir una es
    obligatorio; la elegida se guarda en detalle_pedido.opcion.
*/

// Lista de opciones de un producto (vacía si no tiene)
function opcionesDePlato(array $producto): array
{
    $lineas = preg_split('/\r\n|\r|\n/', (string)($producto["opciones"] ?? ""));
    $lista  = [];
    foreach ($lineas as $l) {
        $l = trim($l);
        if ($l !== "" && !in_array($l, $lista, true)) $lista[] = mb_substr($l, 0, 100);
    }
    return $lista;
}

// Normaliza lo que escribió el restaurante en el campo de opciones
function normalizarOpcionesPlato(string $texto): ?string
{
    $lista = opcionesDePlato(["opciones" => $texto]);
    return $lista ? implode("\n", $lista) : null;
}

/*
    Valida la opción elegida para un plato.
    Regresa ["opcion" => string|null, "error" => string|null].
*/
function validarOpcionPlato(array $producto, $elegida): array
{
    $lista = opcionesDePlato($producto);
    if (!$lista) return ["opcion" => null, "error" => null];

    $elegida = trim((string)$elegida);
    if ($elegida === "") {
        return ["opcion" => null, "error" => "Elige una opción para " . $producto["nombre"] . "."];
    }
    if (!in_array($elegida, $lista, true)) {
        return ["opcion" => null, "error" => "La opción elegida para " . $producto["nombre"] . " no es válida."];
    }
    return ["opcion" => $elegida, "error" => null];
}

// Nombre del platillo con su opción, p. ej. "Ensalada Ejecutiva (Mil Islas)"
function nombreConOpcion(array $detalle): string
{
    $nombre = (string)($detalle["nombre_producto"] ?? "");
    $opcion = trim((string)($detalle["opcion"] ?? ""));
    return $opcion !== "" ? "{$nombre} ({$opcion})" : $nombre;
}

/*
    Selector de opciones que va justo debajo del radio del plato.
    $grupo = prefijo del nombre del radio del plato, p. ej. "menus[2]";
    el radio de la opción se llama "{$grupo}[opcion]". Se muestra solo
    cuando ese plato está elegido (ver scriptOpcionesPlato()).
*/
function htmlOpcionesPlato(array $producto, string $grupo, ?string $seleccionada = null): string
{
    $lista = opcionesDePlato($producto);
    if (!$lista) return "";

    $html = '<div class="opciones-plato" data-grupo="' . htmlspecialchars($grupo) . '" data-plato="' . (int)$producto["id_producto"] . '" style="display:none;">'
          . '<p class="opciones-plato__titulo">Elige una opción <span>· sin costo</span></p>'
          . '<div class="opciones-plato__lista">';
    foreach ($lista as $op) {
        $html .= '<label class="opciones-plato__item">'
               . '<input type="radio" name="' . htmlspecialchars($grupo) . '[opcion]" value="' . htmlspecialchars($op) . '"'
               . ($seleccionada === $op ? ' checked' : '') . ' disabled> '
               . '<span>' . htmlspecialchars($op) . '</span></label>';
    }
    return $html . '</div></div>';
}

/*
    CSS + JS del selector (una sola vez por página). Muestra las opciones
    del plato elegido y deshabilita las de los demás para que no se envíen.
*/
function scriptOpcionesPlato(): string
{
    return <<<'HTML'
<style>
.opciones-plato { margin: 6px 0 12px 28px; padding: 10px 12px; border-left: 3px solid #FF7A00; background: rgba(255,122,0,.06); border-radius: 8px; }
.opciones-plato__titulo { margin: 0 0 6px; font-weight: 700; font-size: 14px; }
.opciones-plato__titulo span { font-weight: 400; opacity: .7; }
.opciones-plato__lista { display: flex; flex-wrap: wrap; gap: 6px 14px; }
.opciones-plato__item { display: inline-flex; align-items: center; gap: 6px; font-size: 14px; cursor: pointer; }
.opciones-plato--error { border-left-color: #ff4d4d; background: rgba(255,77,77,.08); }
</style>
<script>
(function () {
    function actualizarOpcionesPlato() {
        document.querySelectorAll(".opciones-plato").forEach(function (bloque) {
            var grupo = bloque.dataset.grupo;
            var elegido = null;
            document.querySelectorAll("input[type='radio']").forEach(function (r) {
                if (r.name === grupo + "[plato_fuerte]" && r.checked && !r.disabled) elegido = r;
            });
            var visible = !!elegido && elegido.value === bloque.dataset.plato;
            bloque.style.display = visible ? "block" : "none";
            bloque.querySelectorAll("input").forEach(function (i) {
                i.disabled = !visible;
                if (!visible) i.checked = false;
            });
            if (visible) bloque.classList.remove("opciones-plato--error");
        });
    }
    // Para validaciones en el navegador: ¿hay algún selector visible sin opción?
    window.opcionesPlatoPendientes = function (contenedor) {
        return Array.prototype.filter.call((contenedor || document).querySelectorAll(".opciones-plato"), function (b) {
            var falta = b.style.display !== "none" && !b.querySelector("input:checked");
            b.classList.toggle("opciones-plato--error", falta);
            return falta;
        });
    };
    document.addEventListener("change", function (e) {
        if (e.target && /\[plato_fuerte\]$/.test(e.target.name || "")) actualizarOpcionesPlato();
        if (e.target && /\[tipo_menu\]$/.test(e.target.name || "")) setTimeout(actualizarOpcionesPlato, 0);
        if (e.target && /\[opcion\]$/.test(e.target.name || "")) {
            var b = e.target.closest(".opciones-plato");
            if (b) b.classList.remove("opciones-plato--error");
        }
    });
    document.addEventListener("click", function () { setTimeout(actualizarOpcionesPlato, 0); });
    if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", actualizarOpcionesPlato);
    else actualizarOpcionesPlato();
})();
</script>
HTML;
}
