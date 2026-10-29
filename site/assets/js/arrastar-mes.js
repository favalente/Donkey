// Arrastar o calendário para o lado troca o mês (dedo para a esquerda: próximo; para a direita: anterior)
function dhArrastarMes(area, linkAnterior, linkProximo) {
  if (!area || !linkAnterior || !linkProximo) return;
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
      location.href = (dx < 0 ? linkProximo : linkAnterior).href;
    } else {
      area.style.transition = 'transform .15s';
      area.style.transform = '';
      setTimeout(function () { area.style.transition = ''; }, 200);
    }
  });
}
