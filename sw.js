/*
    App del cliente (todo el sitio menos /restaurante/, que tiene su propio sw.js).

    - Páginas: siempre del servidor (nunca un menú o un pedido viejo). Si no
      hay internet, se muestra una pantalla de "Sin conexión" con Reintentar.
    - Archivos estáticos (css, js, imágenes, fuentes): del caché si ya están,
      y al guardar una versión nueva (?v=...) se borra la anterior, para que
      el caché no crezca para siempre.
    - Notificaciones push: muestra el aviso y al tocarlo abre el pedido.
*/
const CACHE = "zabisu-v2";
const ASSETS = [
  "/assets/css/styles.css",
  "/assets/img/LOGO_NARA.png",
  "/assets/img/LOGO_BLANCO.png"
];

self.addEventListener("install", function (e) {
  e.waitUntil(
    caches.open(CACHE).then(function (cache) {
      return cache.addAll(ASSETS);
    }).catch(function () {})
  );
  self.skipWaiting();
});

self.addEventListener("activate", function (e) {
  e.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(
        keys.filter(function (k) { return k !== CACHE; }).map(function (k) { return caches.delete(k); })
      );
    }).then(function () { return self.clients.claim(); })
  );
});

function paginaSinConexion() {
  return new Response(
    '<!doctype html><html lang="es"><head><meta charset="utf-8">' +
    '<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">' +
    '<meta name="theme-color" content="#151413">' +
    '<title>Sin conexión | Zabisu</title></head>' +
    '<body style="margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;' +
    'background:#151413;color:#EDE6DA;font:17px/1.45 system-ui,-apple-system,sans-serif;text-align:center;padding:24px;box-sizing:border-box">' +
    '<div style="max-width:340px">' +
    '<img src="/assets/img/LOGO_NARA.png" alt="" width="72" height="72" style="display:block;margin:0 auto 18px;object-fit:contain">' +
    '<p style="font-size:23px;font-weight:700;margin:0 0 8px">Sin conexión</p>' +
    '<p style="margin:0 0 22px;color:#A8A096">No pudimos cargar el menú. Revisa el internet de tu celular y vuelve a intentar.</p>' +
    '<button onclick="location.reload()" style="min-height:50px;width:100%;padding:0 22px;border:0;border-radius:14px;' +
    'background:#FF9120;color:#151413;font:700 17px system-ui,-apple-system,sans-serif;cursor:pointer">Reintentar</button>' +
    '</div>' +
    '<script>addEventListener("online",function(){location.reload()})</script>' +
    '</body></html>',
    { headers: { "Content-Type": "text/html; charset=utf-8" } }
  );
}

/* Guarda la respuesta y borra versiones anteriores del mismo archivo */
function guardar(request, response) {
  var url = new URL(request.url);
  return caches.open(CACHE).then(function (cache) {
    return cache.keys().then(function (claves) {
      return Promise.all(claves.filter(function (c) {
        var u = new URL(c.url);
        return u.pathname === url.pathname && u.search !== url.search;
      }).map(function (c) { return cache.delete(c); }));
    }).then(function () {
      return cache.put(request, response);
    });
  });
}

self.addEventListener("fetch", function (e) {
  var req = e.request;
  if (req.method !== "GET") return;
  var url = new URL(req.url);
  if (url.origin !== self.location.origin) return;
  if (url.pathname.indexOf("/restaurante/") === 0) return;

  // Páginas: red siempre; sin internet, la pantalla de aviso
  if (req.mode === "navigate") {
    e.respondWith(fetch(req).catch(paginaSinConexion));
    return;
  }

  // Peticiones a PHP desde la página (avisos, estado, etc.): solo red
  if (url.pathname.endsWith(".php")) return;

  // Estáticos: caché primero
  if (/\.(css|js|png|jpe?g|webp|svg|gif|ico|woff2?|ttf|mp3|json)$/i.test(url.pathname)) {
    e.respondWith(
      caches.match(req).then(function (enCache) {
        if (enCache) return enCache;
        return fetch(req).then(function (resp) {
          if (resp && resp.ok && resp.type === "basic") {
            var copia = resp.clone();
            guardar(req, copia);
          }
          return resp;
        });
      })
    );
  }
});

/* ── Notificaciones push ── */
self.addEventListener("push", function (e) {
  var datos = {};
  try { datos = e.data ? e.data.json() : {}; } catch (err) { datos = { cuerpo: e.data ? e.data.text() : "" }; }

  var titulo = datos.titulo || "Zabisu";
  e.waitUntil(self.registration.showNotification(titulo, {
    body: datos.cuerpo || "",
    icon: "/assets/img/LOGO_NARA.png",
    badge: "/assets/img/LOGO_NARA.png",
    tag: datos.tag || undefined,
    renotify: !!datos.tag,
    vibrate: [80, 60, 80],
    data: { url: datos.url || "/cliente/menu.php" }
  }));
});

self.addEventListener("notificationclick", function (e) {
  e.notification.close();
  var destino = new URL((e.notification.data && e.notification.data.url) || "/cliente/menu.php", self.location.origin).href;
  e.waitUntil(
    self.clients.matchAll({ type: "window", includeUncontrolled: true }).then(function (ventanas) {
      for (var i = 0; i < ventanas.length; i++) {
        var v = ventanas[i];
        if (new URL(v.url).origin === self.location.origin && "focus" in v) {
          return v.focus()
            .then(function (w) { return w.navigate(destino); })
            .catch(function () { return self.clients.openWindow(destino); });
        }
      }
      return self.clients.openWindow(destino);
    })
  );
});
