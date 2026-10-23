(function () {
  // Aviso de maioridade: lembrado por 30 dias neste navegador
  var gate = document.getElementById('age-gate');
  var KEY = 'dh-age-ok';
  var ok = false;
  try {
    var saved = Number(localStorage.getItem(KEY));
    ok = saved && Date.now() - saved < 30 * 24 * 60 * 60 * 1000;
  } catch (e) {}
  if (!ok && gate) {
    gate.hidden = false;
    document.body.style.overflow = 'hidden';
    document.getElementById('age-yes').addEventListener('click', function () {
      try { localStorage.setItem(KEY, String(Date.now())); } catch (e) {}
      gate.hidden = true;
      document.body.style.overflow = '';
    });
  }

  // Menu mobile
  var toggle = document.querySelector('.nav-toggle');
  var menu = document.getElementById('menu');
  toggle.addEventListener('click', function () {
    var open = menu.classList.toggle('open');
    toggle.setAttribute('aria-expanded', String(open));
  });
  menu.addEventListener('click', function (e) {
    if (e.target.tagName === 'A') {
      menu.classList.remove('open');
      toggle.setAttribute('aria-expanded', 'false');
    }
  });

  function fecharAoClicarFora(dialog) {
    dialog.addEventListener('click', function (e) {
      if (e.target === dialog || e.target.classList.contains('fechar')) dialog.close();
    });
  }

  // Detalhes da cerveja (catálogo e tap list)
  var modal = document.getElementById('modal-cerveja');
  var corpo = modal.querySelector('.modal-corpo');
  fecharAoClicarFora(modal);
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-cerveja]');
    if (!btn) return;
    var origem = document.getElementById('cerveja-' + btn.getAttribute('data-cerveja'));
    if (!origem) return;
    corpo.innerHTML = origem.innerHTML;
    modal.showModal();
    modal.scrollTop = 0;
  });

  // Abas da tap list
  var tabs = document.querySelectorAll('.tab');
  tabs.forEach(function (tab) {
    tab.addEventListener('click', function () {
      tabs.forEach(function (t) {
        var sel = t === tab;
        t.setAttribute('aria-selected', String(sel));
        document.getElementById(t.getAttribute('aria-controls')).hidden = !sel;
      });
    });
  });

  // Filtro "Engatadas agora"
  var filtros = document.querySelectorAll('.filtro');
  filtros.forEach(function (f) {
    f.addEventListener('click', function () {
      var so = f.getAttribute('data-filtro') === 'engatada';
      filtros.forEach(function (o) {
        o.classList.toggle('ativo', o === f);
        o.setAttribute('aria-pressed', String(o === f));
      });
      document.querySelectorAll('.beer').forEach(function (b) {
        b.hidden = so && b.getAttribute('data-engatada') !== '1';
      });
    });
  });

  // Galeria: mostra 12 fotos e expande; foto ampliada com navegação
  var galeria = document.querySelector('.galeria');
  var mais = document.getElementById('mais-fotos');
  if (galeria && mais) {
    galeria.classList.add('recolhida');
    mais.addEventListener('click', function () {
      galeria.classList.remove('recolhida');
      mais.remove();
    });
  }
  var fotos = Array.prototype.slice.call(document.querySelectorAll('.foto'));
  var lb = document.getElementById('lightbox');
  var lbImg = lb.querySelector('img');
  var atual = 0;
  function mostrar(i) {
    atual = (i + fotos.length) % fotos.length;
    lbImg.src = fotos[atual].getAttribute('href');
    lbImg.alt = fotos[atual].querySelector('img').alt;
  }
  fecharAoClicarFora(lb);
  fotos.forEach(function (a, i) {
    a.addEventListener('click', function (e) {
      e.preventDefault();
      mostrar(i);
      lb.showModal();
    });
  });
  lb.querySelector('.ant').addEventListener('click', function () { mostrar(atual - 1); });
  lb.querySelector('.prox').addEventListener('click', function () { mostrar(atual + 1); });
  lb.addEventListener('keydown', function (e) {
    if (e.key === 'ArrowLeft') mostrar(atual - 1);
    if (e.key === 'ArrowRight') mostrar(atual + 1);
  });

  // Animação ao rolar
  var items = document.querySelectorAll('.section-head, .beer, .unit, .prose, .taps li');
  if ('IntersectionObserver' in window) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add('visible');
          io.unobserve(entry.target);
        }
      });
    }, { threshold: 0.08 });
    items.forEach(function (el) { el.classList.add('reveal'); io.observe(el); });
  }
})();
