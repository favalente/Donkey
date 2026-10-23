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

  // Ano no rodapé
  document.getElementById('year').textContent = new Date().getFullYear();

  // Animação ao rolar
  var items = document.querySelectorAll('.section-head, .beer, .unit, .steps li, .prose');
  if ('IntersectionObserver' in window) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add('visible');
          io.unobserve(entry.target);
        }
      });
    }, { threshold: 0.12 });
    items.forEach(function (el) { el.classList.add('reveal'); io.observe(el); });
  }
})();
