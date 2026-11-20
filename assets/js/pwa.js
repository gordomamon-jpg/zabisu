/*
    Sin zoom dentro de la app instalada (PWA), del cliente y del restaurante.
    - Solo actúa abierta como app (pantalla completa); en el navegador normal
      el zoom sigue funcionando, por accesibilidad.
    - Doble toque: touch-action: manipulation (también quita la espera de 300 ms).
    - Pellizco: viewport sin escala y, en iPhone (que ignora user-scalable),
      se cancelan los gestos de dos dedos.
    - Al tocar un campo de texto el iPhone ya no se acerca solo.
    Va en el <head>, después de <meta name="viewport">.
*/
(function () {
    var esApp = (window.matchMedia && window.matchMedia("(display-mode: standalone)").matches)
             || window.navigator.standalone === true;
    if (!esApp) return;

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
})();
