/*
    Comportamiento de app de Zabisu (cliente y restaurante).
    Va en el <head>, después de <meta name="viewport">.

    1. Sin zoom (solo abierta como app instalada; en el navegador normal el
       zoom sigue funcionando, por accesibilidad):
       - Doble toque: touch-action: manipulation (también quita la espera de 300 ms).
       - Pellizco: viewport sin escala y, en iPhone (que ignora user-scalable),
         se cancelan los gestos de dos dedos.
       - Al tocar un campo de texto el iPhone ya no se acerca solo.

    2. Vibración ligera al tocar (solo Android: el iPhone no deja vibrar a
       las páginas web). Respeta "reducir movimiento".
       - Elegir una opción, botones + / −, pasos: toque corto.
       - Enviar un formulario: doble toque de confirmación.
       - window.zbVibrar("error") para avisos de error desde cada página.
       - data-vibrar="no" en un elemento lo excluye.
*/
(function () {
    var esApp = (window.matchMedia && window.matchMedia("(display-mode: standalone)").matches)
             || window.navigator.standalone === true;

    /* ── 1. Sin zoom ── */
    if (esApp) {
        document.documentElement.classList.add("es-app");

        var vp = document.querySelector('meta[name="viewport"]');
        if (!vp) {
            vp = document.createElement("meta");
            vp.name = "viewport";
            document.head.appendChild(vp);
        }
        var cover = /viewport-fit=cover/.test(vp.content) ? ", viewport-fit=cover" : "";
        vp.content = "width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no" + cover;

        var estilo = document.createElement("style");
        estilo.textContent = "html,body{touch-action:manipulation}";
        document.head.appendChild(estilo);

        // iPhone: pellizco
        ["gesturestart", "gesturechange", "gestureend"].forEach(function (ev) {
            document.addEventListener(ev, function (e) { e.preventDefault(); }, { passive: false });
        });
        document.addEventListener("touchmove", function (e) {
            if (e.touches && e.touches.length > 1) e.preventDefault();
        }, { passive: false });
    }

    /* ── 2. Vibración ── */
    var patrones = { toque: 8, paso: 14, listo: [14, 60, 14], error: [30, 50, 30] };
    var sinMovimiento = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    var puede = typeof navigator.vibrate === "function" && !sinMovimiento;

    window.zbVibrar = function (tipo) {
        if (!puede) return;
        try { navigator.vibrate(patrones[tipo] || patrones.toque); } catch (e) {}
    };

    if (!puede) return;

    function excluido(el) {
        return el && el.closest && el.closest('[data-vibrar="no"]');
    }

    // Botones y enlaces que parecen botón
    document.addEventListener("click", function (e) {
        var el = e.target && e.target.closest && e.target.closest(
            "button, [role='button'], .btn-principal, .btn-secundario, a.pz-btn"
        );
        if (!el || excluido(el) || el.disabled) return;
        if (el.type === "submit") return; // lo cubre el evento submit
        var esPaso = el.matches("[data-siguiente], [data-anterior], .btn-stepper");
        window.zbVibrar(esPaso ? "paso" : "toque");
    }, true);

    // Elegir una opción (platillos, complementos, horario, método de pago...)
    document.addEventListener("change", function (e) {
        var t = e.target;
        if (!t || excluido(t)) return;
        if (t.type === "radio" || t.type === "checkbox") window.zbVibrar("toque");
    }, true);

    // Enviar un formulario (confirmar pedido, guardar, etc.)
    document.addEventListener("submit", function (e) {
        if (!excluido(e.target)) window.zbVibrar("listo");
    }, true);
})();
