<?php
// Tela de TV com a tap list: www.donkeyheadcervejaria.com.br/tv/?unidade=aldeota
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
  <style>
    :root { --bg: #16110d; --card: #241c16; --line: #3d3128; --text: #f4ece1; --muted: #bcab97; --amber: #e9a23b; --cream: #f6e7c8; }
    * { box-sizing: border-box; }
    html, body { margin: 0; height: 100%; overflow: hidden; cursor: none; }
    body {
      background: radial-gradient(ellipse 70% 60% at 100% 0%, rgba(233,162,59,.16), transparent 70%), var(--bg);
      color: var(--text); font-family: Inter, system-ui, sans-serif;
      display: flex; flex-direction: column; padding: 2.2vh 2.4vw 1.6vh;
    }
    header { display: flex; align-items: center; gap: 1.6vw; padding-bottom: 1.6vh; border-bottom: .3vh solid var(--line); }
    header img { height: 9vh; width: auto; }
    header h1 { margin: 0; font: 400 8vh/1 "Bebas Neue", Impact, sans-serif; letter-spacing: .03em; }
    header h1 span { color: var(--amber); }
    .unidade { font: 700 2.4vh/1 Inter, sans-serif; color: var(--muted); text-transform: uppercase; letter-spacing: .2em; margin-top: .6vh; }
    .relogio { margin-left: auto; font: 400 7vh/1 "Bebas Neue", Impact, sans-serif; color: var(--cream); }
    main { flex: 1; min-height: 0; display: grid; grid-template-columns: 1fr 1fr; grid-auto-rows: minmax(0, 1fr); gap: 1.4vh 1.6vw; padding: 2vh 0; }
    .tap {
      display: flex; align-items: center; gap: 1.2vw; min-height: 0;
      background: var(--card); border: .2vh solid var(--line); border-radius: 1.6vh; padding: 1vh 1.4vw 1vh 1vh;
      animation: entra .6s ease both;
    }
    .num { flex: none; width: 6vh; height: 6vh; border-radius: 50%; display: grid; place-items: center; background: var(--amber); color: #1a120a; font: 400 4vh/1 "Bebas Neue", Impact, sans-serif; }
    .tap img { flex: none; height: 100%; max-height: 13vh; aspect-ratio: 1; object-fit: cover; border-radius: 1vh; }
    .info { flex: 1; min-width: 0; }
    .nome { font: 400 5vh/1 "Bebas Neue", Impact, sans-serif; letter-spacing: .02em; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .nome.longo { font-size: 4.1vh; }
    .nome.muito-longo { font-size: 3.4vh; }
    .estilo { color: var(--muted); font-size: 2.2vh; margin-top: .4vh; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .estilo b { color: var(--cream); font-weight: 700; }
    .precos { flex: none; display: flex; flex-direction: column; gap: .6vh; text-align: right; }
    .preco { font: 400 4.2vh/1 "Bebas Neue", Impact, sans-serif; color: var(--cream); }
    .preco small { font: 700 1.6vh/1 Inter, sans-serif; color: var(--amber); margin-right: .5vw; letter-spacing: .08em; vertical-align: middle; }
    .vazio { grid-column: 1 / -1; display: grid; place-items: center; font: 400 6vh/1.2 "Bebas Neue", Impact, sans-serif; color: var(--muted); text-align: center; }
    footer { display: flex; justify-content: space-between; align-items: center; color: var(--muted); font-size: 1.8vh; }
    .paginas { display: flex; gap: .6vw; }
    .paginas i { width: 1.2vh; height: 1.2vh; border-radius: 50%; background: var(--line); }
    .paginas i.on { background: var(--amber); }
    .offline { color: #ff9b8a; }
    @keyframes entra { from { opacity: 0; transform: translateY(1.5vh); } to { opacity: 1; transform: none; } }
  </style>
</head>
<body>
  <header>
    <img src="../assets/img/logo-donkey-head.png" alt="">
    <div>
      <h1>Tap <span>List</span></h1>
      <div class="unidade">Donkey Head · <?= e($inicial['unidade']) ?></div>
    </div>
    <div class="relogio" id="relogio"></div>
  </header>
  <main id="taps"></main>
  <footer>
    <span id="status">Beba com moderação · Venda proibida para menores de 18 anos</span>
    <div class="paginas" id="paginas"></div>
  </footer>

  <script>
    var UNIDADE = <?= json_encode($unidade) ?>;
    var POR_PAGINA = 12;          // 2 colunas x 6 linhas
    var TROCA_PAGINA = 15000;     // 15 s por página quando há mais de 12 torneiras
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
      // Linhas proporcionais à quantidade de torneiras (mínimo 4 para não ficar gigante)
      main.style.gridTemplateRows = 'repeat(' + Math.max(4, Math.ceil(lista.length / 2)) + ', minmax(0, 1fr))';
      main.innerHTML = lista.length ? lista.map(function (t, i) {
        var meta = [t.abv && '<b>' + esc(t.abv) + '</b>', t.ibu && esc(t.ibu)].filter(Boolean).join(' · ');
        return '<div class="tap" style="animation-delay:' + (i * 50) + 'ms">' +
          '<div class="num">' + t.n + '</div>' +
          '<img src="' + esc(t.img) + '" alt="">' +
          '<div class="info"><div class="nome' + (t.nome.length > 24 ? ' muito-longo' : t.nome.length > 18 ? ' longo' : '') + '">' + esc(t.nome) + '</div>' +
          '<div class="estilo">' + esc(t.estilo) + (meta ? ' · ' + meta : '') + '</div></div>' +
          '<div class="precos">' +
            (t.p300 ? '<div class="preco"><small>300 ML</small>' + esc(t.p300) + '</div>' : '') +
            (t.p1l ? '<div class="preco"><small>1 L</small>' + esc(t.p1l) + '</div>' : '') +
          '</div></div>';
      }).join('') : '<div class="vazio">Tap list em atualização<br>Pergunte ao nosso barman!</div>';
      var dots = '';
      for (var i = 0; total > 1 && i < total; i++) dots += '<i class="' + (i === pagina ? 'on' : '') + '"></i>';
      document.getElementById('paginas').innerHTML = dots;
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

    function relogio() {
      var d = new Date();
      document.getElementById('relogio').textContent =
        ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2);
    }

    assinatura = JSON.stringify(dados.taps);
    desenhar();
    relogio();
    setInterval(relogio, 10000);
    setInterval(atualizar, ATUALIZA);
    setInterval(function () {
      if ((dados.taps || []).length > POR_PAGINA) { pagina++; desenhar(); }
    }, TROCA_PAGINA);
    // Recarrega a página a cada 6 h para pegar melhorias no site
    setTimeout(function () { location.reload(); }, 6 * 60 * 60 * 1000);
  </script>
</body>
</html>
