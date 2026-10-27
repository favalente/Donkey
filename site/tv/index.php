<?php
// Tela de TV com a tap list: www.donkeyheadcervejaria.com.br/tv/?unidade=aldeota
// Mesmo desenho da imagem para o YouTube (tv/desenho.js), alternando as telas por grupo.
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
    html, body { margin: 0; height: 100%; overflow: hidden; background: #f6efe2; cursor: none; }
    body { display: grid; place-items: center; }
    canvas { width: 100vw; height: 100vh; object-fit: contain; display: block; }
  </style>
</head>
<body>
  <canvas id="tela" aria-label="Tap list da unidade <?= e($inicial['unidade']) ?>"></canvas>
  <script src="desenho.js?v=4"></script>
  <script>
    var UNIDADE = <?= json_encode($unidade) ?>;
    var TROCA_TELA = 15000;   // 15 s por tela quando há mais de uma
    var ATUALIZA = 60000;     // busca a tap list a cada 1 min
    var LOGO = '../assets/img/logo-donkey-head.png';
    var dados = <?= json_encode($inicial, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var imgs = {}, telas = [], atual = 0, assinatura = '';
    var cv = document.getElementById('tela');

    function mostrar() {
      if (!telas.length) return;
      if (atual >= telas.length) atual = 0;
      DHTap.desenhar(cv, dados, telas[atual], atual + 1, telas.length, imgs);
    }

    function aplicar(novo) {
      return DHTap.carregar(novo, LOGO).then(function (r) {
        dados = novo; imgs = r; telas = DHTap.paginas(novo.taps || []);
        assinatura = JSON.stringify(novo.taps);
        mostrar();
      });
    }

    function atualizar() {
      fetch('dados.php?unidade=' + encodeURIComponent(UNIDADE) + '&t=' + Date.now(), { cache: 'no-store' })
        .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
        .then(function (novo) { if (JSON.stringify(novo.taps) !== assinatura) aplicar(novo); })
        .catch(function () { /* Sem internet: mantém a última lista na tela */ });
    }

    aplicar(dados);
    setInterval(atualizar, ATUALIZA);
    setInterval(function () { if (telas.length > 1) { atual++; mostrar(); } }, TROCA_TELA);
    // Recarrega a página a cada 6 h para pegar melhorias no site
    setTimeout(function () { location.reload(); }, 6 * 60 * 60 * 1000);
  </script>
</body>
</html>
