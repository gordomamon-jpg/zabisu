/*
    App del restaurante (solo /restaurante/).
    No guarda páginas ni datos: todo va siempre al servidor, para que nunca
    se vea un pedido o una cifra vieja. Si no hay internet, se avisa.
*/
self.addEventListener("install", function () { self.skipWaiting(); });
self.addEventListener("activate", function (e) { e.waitUntil(self.clients.claim()); });

self.addEventListener("fetch", function (e) {
  if (e.request.mode !== "navigate") return;
  e.respondWith(
    fetch(e.request).catch(function () {
      return new Response(
        '<!doctype html><html lang="es"><head><meta charset="utf-8">' +
        '<meta name="viewport" content="width=device-width,initial-scale=1">' +
        '<title>Sin conexión</title></head>' +
        '<body style="margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;' +
        'background:#151413;color:#EDE6DA;font:17px/1.4 system-ui,sans-serif;text-align:center;padding:24px">' +
        '<div><p style="font-size:22px;font-weight:700;margin:0 0 8px">Sin conexión</p>' +
        '<p style="margin:0 0 20px;color:#A8A096">Revisa el internet del celular y vuelve a intentar.</p>' +
        '<button onclick="location.reload()" style="min-height:48px;padding:0 22px;border:0;border-radius:12px;' +
        'background:#FF9120;color:#151413;font:700 17px system-ui,sans-serif">Reintentar</button></div></body></html>',
        { headers: { "Content-Type": "text/html; charset=utf-8" } }
      );
    })
  );
});
