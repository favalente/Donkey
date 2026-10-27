<?php
// Gera a tap list como imagem Full HD (1920x1080) para usar em vídeos (YouTube na TV).
// A imagem é desenhada no navegador, com os dados atuais da tap list.
require __DIR__ . '/../inc/dados.php';

$unidade = isset(DH_UNIDADES[$_GET['unidade'] ?? '']) ? $_GET['unidade'] : array_key_first(DH_UNIDADES);
$dadosTv = dh_tap_tv($unidade);
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title>Imagem da Tap List · <?= e($dadosTv['unidade']) ?></title>
  <link rel="icon" href="../favicon.ico">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Inter:wght@400;500;700&display=swap" rel="stylesheet">
  <style>
    body { margin: 0; background: #16110d; color: #f4ece1; font: 16px/1.5 Inter, system-ui, sans-serif; }
    main { max-width: 1000px; margin: 0 auto; padding: 16px; }
    h1 { font-size: 1.4rem; margin: .2em 0 .4em; }
    p { color: #bcab97; margin: 0 0 1em; }
    .unidades { display: flex; gap: 8px; margin-bottom: 16px; }
    .unidades a { flex: 1; text-align: center; padding: 10px; border-radius: 12px; border: 2px solid #3d3128; color: #f4ece1; text-decoration: none; font-weight: 700; }
    .unidades a.ativo { border-color: #e9a23b; color: #e9a23b; }
    .pagina { margin-bottom: 24px; }
    canvas { width: 100%; height: auto; border-radius: 10px; border: 1px solid #3d3128; display: block; }
    .baixar { display: block; width: 100%; margin-top: 10px; padding: 14px; border: 0; border-radius: 999px; background: #e9a23b; color: #1a120a; font: 700 1.05rem Inter, sans-serif; cursor: pointer; }
    .carregando { color: #bcab97; }
  </style>
</head>
<body>
  <main>
    <h1>Imagem da Tap List para a TV</h1>
    <p>Imagens Full HD (1920×1080) com o que está engatado agora, divididas pelos grupos (da mais leve para a mais forte). Baixe e use no vídeo do YouTube.</p>
    <div class="unidades">
      <?php foreach (DH_UNIDADES as $u => $nomeU): ?>
        <a href="?unidade=<?= $u ?>" class="<?= $u === $unidade ? 'ativo' : '' ?>"><?= e($nomeU) ?></a>
      <?php endforeach; ?>
    </div>
    <div id="paginas"><p class="carregando">Gerando imagem…</p></div>
  </main>

  <script src="desenho.js?v=4"></script>
  <script>
    var DADOS = <?= json_encode($dadosTv, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var UNIDADE = <?= json_encode($unidade) ?>;

    DHTap.carregar(DADOS, '../assets/img/logo-donkey-head.png').then(function (imgs) {
      var telas = DHTap.paginas(DADOS.taps || []);
      var hoje = new Date().toISOString().slice(0, 10);
      var alvo = document.getElementById('paginas');
      alvo.innerHTML = '';
      telas.forEach(function (tela, p) {
        var cv = document.createElement('canvas');
        DHTap.desenhar(cv, DADOS, tela, p + 1, telas.length, imgs);
        var bloco = document.createElement('div'); bloco.className = 'pagina';
        var btn = document.createElement('button'); btn.className = 'baixar';
        btn.textContent = telas.length > 1 ? 'Baixar imagem ' + (p + 1) + ' de ' + telas.length : 'Baixar imagem (PNG)';
        var nome = 'taplist-' + UNIDADE + '-' + hoje + (telas.length > 1 ? '-' + (p + 1) : '') + '.png';
        btn.addEventListener('click', function () {
          cv.toBlob(function (blob) {
            var a = document.createElement('a');
            a.href = URL.createObjectURL(blob); a.download = nome;
            document.body.appendChild(a); a.click(); a.remove();
            setTimeout(function () { URL.revokeObjectURL(a.href); }, 5000);
          }, 'image/png');
        });
        bloco.appendChild(cv); bloco.appendChild(btn); alvo.appendChild(bloco);
      });
    });
  </script>
</body>
</html>
