const CACHE = "zabisu-v1";
const ASSETS = [
  "/assets/css/styles.css",
  "/assets/img/LOGO_NARA.png",
  "/assets/img/LOGO_BLANCO.png"
];

self.addEventListener("install", function (e) {
  e.waitUntil(
    caches.open(CACHE).then(function (cache) {
      return cache.addAll(ASSETS);
    })
  );
  self.skipWaiting();
});

self.addEventListener("activate", function (e) {
  e.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(
        keys.filter(function (k) { return k !== CACHE; }).map(function (k) { return caches.delete(k); })
      );
    })
  );
  self.clients.claim();
});

/* Network-first para páginas PHP; cache-first para assets estáticos */
self.addEventListener("fetch", function (e) {
  var url = new URL(e.request.url);

  if (e.request.method !== "GET") return;

  if (url.pathname.endsWith(".php")) {
    e.respondWith(
      fetch(e.request).catch(function () {
        return caches.match(e.request);
      })
    );
  } else {
    e.respondWith(
      caches.match(e.request).then(function (cached) {
        return cached || fetch(e.request).then(function (response) {
          return caches.open(CACHE).then(function (cache) {
            cache.put(e.request, response.clone());
            return response;
          });
        });
      })
    );
  }
});
