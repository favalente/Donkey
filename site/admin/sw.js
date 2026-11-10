// Notificações do painel no celular. O "toque" chega sem conteúdo; o texto vem do próprio painel.
self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (e) { e.waitUntil(self.clients.claim()); });

self.addEventListener('push', function (e) {
  e.waitUntil(
    fetch('./?acao=notificacoes-json', { credentials: 'include', cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .catch(function () { return {}; })
      .then(function (n) {
        var titulo = n.titulo || 'Donkey Head · nova notificação';
        return self.registration.showNotification(titulo, {
          body: n.texto || 'Abra o painel para ver.',
          icon: '../assets/img/icone-192.png',
          badge: '../assets/img/icone-badge.png',
          tag: n.id || 'painel',
          data: { url: n.url || './?acao=notificacoes' }
        });
      })
  );
});

self.addEventListener('notificationclick', function (e) {
  e.notification.close();
  var url = new URL(e.notification.data && e.notification.data.url || './?acao=notificacoes', self.registration.scope).href;
  e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (cs) {
    for (var i = 0; i < cs.length; i++) {
      if (cs[i].url.indexOf(self.registration.scope) === 0 && 'navigate' in cs[i]) {
        return cs[i].focus().then(function (c) { return c.navigate(url); });
      }
    }
    return self.clients.openWindow(url);
  }));
});
