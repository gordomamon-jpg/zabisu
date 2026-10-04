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
          . '<p class="opciones-plato__titulo">Elige tu aderezo <span>· sin costo</span></p>'
          . '<div class="opciones-plato__lista">';
    foreach ($lista as $op) {
        $html .= '<label class="opciones-plato__item' . ($seleccionada === $op ? ' is-sel' : '') . '">'
               . '<input type="radio" name="' . htmlspecialchars($grupo) . '[opcion]" value="' . htmlspecialchars($op) . '"'
               . ($seleccionada === $op ? ' checked' : '') . ' disabled>'
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
/* Botones 2×2 pensados para el pulgar. El selector va con la clase doble
   para ganarle a ".bloque-formulario label:not(...)" de styles.css, que
   convierte cualquier label del formulario en un título gris en mayúsculas. */
.opciones-plato {
    margin: -2px 0 14px; padding: 14px;
    border-radius: 16px;
    border: 1px solid rgba(255,122,0,.28);
    background: rgba(255,122,0,.06);
}
.opciones-plato__titulo {
    margin: 0 0 10px; font-size: 12px; font-weight: 700;
    letter-spacing: .6px; text-transform: uppercase; color: rgba(255,255,255,.7);
}
.opciones-plato__titulo span { font-weight: 400; text-transform: none; letter-spacing: 0; opacity: .75; }
.opciones-plato__lista { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.opciones-plato .opciones-plato__lista label.opciones-plato__item.opciones-plato__item {
    position: relative; display: flex; align-items: center; justify-content: center; gap: 6px;
    min-height: 50px; margin: 0; padding: 10px 8px;
    border-radius: 12px; border: 1px solid rgba(255,255,255,.14);
    background: rgba(255,255,255,.03);
    color: #fff; font-size: 14px; font-weight: 600; line-height: 1.2;
    text-align: center; text-transform: none; letter-spacing: normal;
    cursor: pointer; -webkit-tap-highlight-color: transparent;
    transition: background .15s ease, border-color .15s ease, transform .1s ease;
}
.opciones-plato .opciones-plato__lista label.opciones-plato__item.opciones-plato__item:active { transform: scale(.97); }
.opciones-plato__item input { position: absolute; opacity: 0; width: 1px; height: 1px; pointer-events: none; }
.opciones-plato .opciones-plato__lista label.opciones-plato__item.is-sel {
    background: linear-gradient(135deg, #FF7A00 0%, #ff5e00 100%);
    border-color: #FF7A00; box-shadow: 0 6px 18px rgba(255,122,0,.28);
}
.opciones-plato__item.is-sel span::before { content: "✓ "; font-weight: 800; }
.opciones-plato__item:focus-within { outline: 2px solid rgba(255,255,255,.6); outline-offset: 2px; }
.opciones-plato--error { border-color: #ff4d4d; background: rgba(255,77,77,.08); }
@media (hover: hover) {
    .opciones-plato .opciones-plato__lista label.opciones-plato__item.opciones-plato__item:hover { border-color: rgba(255,122,0,.6); }
}
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
                var lbl = i.closest(".opciones-plato__item");
                if (lbl) lbl.classList.toggle("is-sel", i.checked);
            });
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
            if (b) {
                b.classList.remove("opciones-plato--error");
                b.querySelectorAll(".opciones-plato__item").forEach(function (l) {
                    var i = l.querySelector("input");
                    l.classList.toggle("is-sel", !!(i && i.checked));
                });
            }
        }
    });
    document.addEventListener("click", function () { setTimeout(actualizarOpcionesPlato, 0); });
    if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", actualizarOpcionesPlato);
    else actualizarOpcionesPlato();
})();
</script>
HTML;
}
