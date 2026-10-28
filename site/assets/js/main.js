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

  // Filtro do catálogo: todas ou só as engatadas numa unidade (com o preço dela)
  var filtros = document.querySelectorAll('.filtro');
  function filtrar(qual) {
    filtros.forEach(function (o) {
      var sel = o.getAttribute('data-filtro') === qual;
      o.classList.toggle('ativo', sel);
      o.setAttribute('aria-pressed', String(sel));
    });
    document.querySelectorAll('.beer').forEach(function (b) {
      var unidades = (b.getAttribute('data-unidades') || '').split(' ');
      b.hidden = qual !== 'todas' && unidades.indexOf(qual) < 0;
      b.querySelectorAll('.beer-preco').forEach(function (p) {
        // Preço só nas abas de engatadas; em "Todas" o cartão fica limpo
        p.hidden = p.getAttribute('data-unidade') !== qual;
      });
    });
  }
  filtros.forEach(function (f) {
    f.addEventListener('click', function () { filtrar(f.getAttribute('data-filtro')); });
  });
  document.querySelectorAll('[data-ir-filtro]').forEach(function (a) {
    a.addEventListener('click', function () { filtrar(a.getAttribute('data-ir-filtro')); });
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
  var lbLegenda = lb.querySelector('figcaption');
  var atual = 0;
  function mostrar(i) {
    atual = (i + fotos.length) % fotos.length;
    lbImg.src = fotos[atual].getAttribute('href');
    lbImg.alt = fotos[atual].querySelector('img').alt;
    lbLegenda.textContent = fotos[atual].getAttribute('data-legenda') || '';
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
  var items = document.querySelectorAll('.section-head, .beer, .unit, .prose');
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
