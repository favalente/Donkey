<?php
// Tela de TV com a tap list: www.donkeyheadcervejaria.com.br/tv/?unidade=aldeota
// Formato de tabela com letras grandes, agrupada da cerveja mais leve para a mais forte.
require __DIR__ . '/../inc/dados.php';

$unidade = isset(DH_UNIDADES[$_GET['unidade'] ?? '']) ? $_GET['unidade'] : array_key_first(DH_UNIDADES);
$inicial = dh_tap_tv($unidade);
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title>Tap List · <?= e($inicial['unidade']) ?> · Donkey Head</title>
  <link rel="icon" href="../favicon.ico">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Inter:wght@500;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="tv.css?v=2">
</head>
<body>
  <header>
    <img src="../assets/img/logo-donkey-head.png" alt="">
    <h1>Cervejas <span>engatadas</span></h1>
    <div class="unidade">Donkey Head · <?= e($inicial['unidade']) ?></div>
  </header>
  <div class="cab">
    <span></span><span>Cerveja</span><span>Estilo</span><span class="num">Álcool</span><span class="num">IBU</span><span class="num">300 ml</span>
  </div>
  <main id="taps"></main>
  <footer>
    <div class="legenda" id="legenda"></div>
    <span id="status">Beba com moderação · Venda proibida para menores de 18 anos</span>
    <div class="paginas" id="paginas"></div>
  </footer>

  <script>
    var UNIDADE = <?= json_encode($unidade) ?>;
    var POR_PAGINA = 15;          // até 15 cervejas por tela
    var TROCA_PAGINA = 15000;     // 15 s por tela quando há mais de 15 torneiras
    var ATUALIZA = 60000;         // busca a tap list a cada 1 min
    var dados = <?= json_encode($inicial, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var pagina = 0;
    var assinatura = '';

    function esc(s) {
      return String(s || '').replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
    }

    function desenhar() {
      var taps = dados.taps || [];
      var total = Math.max(1, Math.ceil(taps.length / POR_PAGINA));
      if (pagina >= total) pagina = 0;
      var lista = taps.slice(pagina * POR_PAGINA, (pagina + 1) * POR_PAGINA);
      var main = document.getElementById('taps');
      // Altura da linha conforme a quantidade (no máximo a de 9 linhas, para não ficar gigante)
      var linhas = Math.max(9, lista.length);
      var grupos = 0;
      for (var i = 1; i < lista.length; i++) if (lista[i].cat !== lista[i - 1].cat) grupos++;
      var h = (main.clientHeight - grupos * 10) / linhas;
      document.documentElement.style.setProperty('--h', Math.floor(h) + 'px');
      main.innerHTML = lista.length ? lista.map(function (t, i) {
        var novoGrupo = i > 0 && t.cat !== lista[i - 1].cat;
        return '<div class="linha g' + t.cat + (novoGrupo ? ' novo' : '') + '">' +
          '<img src="' + esc(t.img) + '" alt="">' +
          '<span class="nome">' + esc(t.nome) + '</span>' +
          '<span class="estilo">' + esc(t.estilo) + '</span>' +
          '<span class="num abv">' + esc(t.abv) + '</span>' +
          '<span class="num ibu">' + esc(t.ibuNum) + '</span>' +
          '<span class="num preco">' + esc(t.p300) + '</span>' +
          '</div>';
      }).join('') : '<div class="vazio">Tap list em atualização · Pergunte ao nosso barman!</div>';
      // Nome que não cabe diminui até caber
      main.querySelectorAll('.nome, .estilo').forEach(function (el) {
        var t = parseFloat(getComputedStyle(el).fontSize);
        while (el.scrollWidth > el.clientWidth && t > 10) { t -= 1; el.style.fontSize = t + 'px'; }
      });
      var dots = '';
      for (var p = 0; total > 1 && p < total; p++) dots += '<i class="' + (p === pagina ? 'on' : '') + '"></i>';
      document.getElementById('paginas').innerHTML = dots;
      var usados = {};
      taps.forEach(function (t) { usados[t.cat] = true; });
      document.getElementById('legenda').innerHTML = Object.keys(dados.grupos).filter(function (k) { return usados[k]; })
        .map(function (k) { return '<span class="g' + k + '"><i></i>' + esc(dados.grupos[k]) + '</span>'; }).join('');
    }

    function atualizar() {
      fetch('dados.php?unidade=' + encodeURIComponent(UNIDADE) + '&t=' + Date.now(), { cache: 'no-store' })
        .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
        .then(function (novo) {
          document.getElementById('status').classList.remove('offline');
          var a = JSON.stringify(novo.taps);
          if (a !== assinatura) { assinatura = a; dados = novo; desenhar(); }
        })
        .catch(function () {
          // Sem internet: mantém a última lista na tela
          document.getElementById('status').classList.add('offline');
        });
    }

    assinatura = JSON.stringify(dados.taps);
    desenhar();
    window.addEventListener('resize', desenhar);
    if (document.fonts) document.fonts.ready.then(desenhar);
    setInterval(atualizar, ATUALIZA);
    setInterval(function () {
      if ((dados.taps || []).length > POR_PAGINA) { pagina++; desenhar(); }
    }, TROCA_PAGINA);
    // Recarrega a página a cada 6 h para pegar melhorias no site
    setTimeout(function () { location.reload(); }, 6 * 60 * 60 * 1000);
  </script>
</body>
</html>
