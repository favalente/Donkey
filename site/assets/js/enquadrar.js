// Enquadramento da foto da banda: arrastar a foto dentro da moldura 4:3 muda o ponto que fica
// no centro (object-position, em %). Usado na página da banda e no cadastro da banda no painel.
function dhEnquadrar(m, img, ix, iy) {
  var x = +ix.value, y = +iy.value, ini = null;
  function sobra() {
    var fw = m.clientWidth, fh = m.clientHeight, nw = img.naturalWidth || fw, nh = img.naturalHeight || fh, esc = Math.max(fw / nw, fh / nh);
    return [nw * esc - fw, nh * esc - fh];
  }
  function aplicar() { img.style.objectPosition = x + '% ' + y + '%'; ix.value = Math.round(x); iy.value = Math.round(y); }
  m.addEventListener('pointerdown', function (e) { ini = [e.clientX, e.clientY, x, y]; m.setPointerCapture(e.pointerId); m.classList.add('arrastando'); });
  m.addEventListener('pointermove', function (e) {
    if (!ini) return;
    var s = sobra();
    if (s[0] > 1) x = Math.max(0, Math.min(100, ini[2] - (e.clientX - ini[0]) / s[0] * 100));
    if (s[1] > 1) y = Math.max(0, Math.min(100, ini[3] - (e.clientY - ini[1]) / s[1] * 100));
    aplicar();
  });
  ['pointerup', 'pointercancel'].forEach(function (t) { m.addEventListener(t, function () { ini = null; m.classList.remove('arrastando'); }); });
}
