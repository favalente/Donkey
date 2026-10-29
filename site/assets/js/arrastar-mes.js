// Troca de mês no calendário: arrastar para o lado (esquerda: próximo; direita: anterior)
// ou tocar nos botões do mês. A página recarrega, mas volta com o calendário no mesmo
// ponto da tela, em vez de pular para o topo.
function dhArrastarMes(area, linkAnterior, linkProximo) {
  if (!area || !linkAnterior || !linkProximo) return;
  var CHAVE = 'dh-cal-posicao';
  var ancora = linkAnterior.closest('.mes-nav, .agenda-mes') || area;

  // Chegou de uma troca de mês: põe o calendário onde ele estava
  var salvo = null;
  try { salvo = sessionStorage.getItem(CHAVE); sessionStorage.removeItem(CHAVE); } catch (e) {}
  if (salvo !== null) {
    if ('scrollRestoration' in history) history.scrollRestoration = 'manual';
    var posicionar = function () {
      window.scrollTo(0, ancora.getBoundingClientRect().top + window.pageYOffset - Number(salvo));
    };
    posicionar();
    document.addEventListener('DOMContentLoaded', posicionar);
    window.addEventListener('load', posicionar);
  }

  function ir(link) {
    try { sessionStorage.setItem(CHAVE, String(ancora.getBoundingClientRect().top)); } catch (e) {}
    location.href = link.href;
  }
  [linkAnterior, linkProximo].concat([].slice.call(document.querySelectorAll('[data-manter-posicao]'))).forEach(function (a) {
    a.addEventListener('click', function (e) { e.preventDefault(); ir(a); });
  });

  var x0 = null, y0 = 0;
  area.addEventListener('touchstart', function (e) {
    if (e.touches.length !== 1) return;
    x0 = e.touches[0].clientX; y0 = e.touches[0].clientY;
  }, { passive: true });
  area.addEventListener('touchmove', function (e) {
    if (x0 === null) return;
    var dx = e.touches[0].clientX - x0, dy = e.touches[0].clientY - y0;
    if (Math.abs(dx) > Math.abs(dy)) area.style.transform = 'translateX(' + dx / 3 + 'px)';
  }, { passive: true });
  area.addEventListener('touchend', function (e) {
    if (x0 === null) return;
    var dx = e.changedTouches[0].clientX - x0, dy = e.changedTouches[0].clientY - y0;
    x0 = null;
    if (Math.abs(dx) > 60 && Math.abs(dx) > Math.abs(dy) * 1.5) {
      area.style.transition = 'transform .15s, opacity .15s';
      area.style.transform = 'translateX(' + (dx < 0 ? -40 : 40) + 'px)';
      area.style.opacity = '.3';
      ir(dx < 0 ? linkProximo : linkAnterior);
    } else {
      area.style.transition = 'transform .15s';
      area.style.transform = '';
      setTimeout(function () { area.style.transition = ''; }, 200);
    }
  });
}
