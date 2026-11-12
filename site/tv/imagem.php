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
    h1#story { margin-top: 32px; }
    .stories { display: flex; flex-wrap: wrap; gap: 20px; }
    .stories .pagina { width: min(360px, 100%); }
    .botoes { display: flex; gap: 8px; }
    .botoes .baixar { flex: 1; }
    .baixar.zap { background: #25d366; color: #0b2e17; }
    .baixar.sec { background: transparent; color: #e9a23b; border: 2px solid #e9a23b; }
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

    <h1 id="story">Story do Instagram</h1>
    <p>Imagem 1080×1920 com o que está engatado hoje. No celular, toque em <b>Compartilhar</b> e escolha o Instagram → Story, ou em <b>Enviar no WhatsApp</b> para mandar no grupo.</p>
    <div id="stories" class="stories"><p class="carregando">Gerando imagem…</p></div>
  </main>

  <script src="desenho.js?v=6"></script>
  <script>
    var DADOS = <?= json_encode($dadosTv, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var UNIDADE = <?= json_encode($unidade) ?>;

    var HOJE = new Date().toISOString().slice(0, 10);
    function arquivo(cv, nome, fn) { cv.toBlob(function (blob) { fn(blob, nome); }, 'image/png'); }
    function baixar(blob, nome) {
      var a = document.createElement('a');
      a.href = URL.createObjectURL(blob); a.download = nome;
      document.body.appendChild(a); a.click(); a.remove();
      setTimeout(function () { URL.revokeObjectURL(a.href); }, 5000);
    }
    function botao(texto, classe, acao) {
      var b = document.createElement('button'); b.className = 'baixar' + (classe ? ' ' + classe : ''); b.textContent = texto;
      b.addEventListener('click', acao); return b;
    }
    // Lista de imagens (TV ou Story) com botão de baixar e, no celular, de compartilhar
    function montar(alvo, telas, desenhar, prefixo, rotulo) {
      alvo.innerHTML = '';
      telas.forEach(function (tela, p) {
        var cv = document.createElement('canvas');
        desenhar(cv, tela, p);
        var nome = prefixo + '-' + UNIDADE + '-' + HOJE + (telas.length > 1 ? '-' + (p + 1) : '') + '.png';
        var bloco = document.createElement('div'); bloco.className = 'pagina';
        var linha = document.createElement('div'); linha.className = 'botoes';
        linha.appendChild(botao(telas.length > 1 ? rotulo + ' ' + (p + 1) + ' de ' + telas.length : rotulo, '', function () { arquivo(cv, nome, baixar); }));
        if (navigator.canShare && navigator.share) {
          // O arquivo fica pronto antes do toque: o celular só abre o compartilhar direto no toque
          var pronto = null;
          arquivo(cv, nome, function (blob) { pronto = new File([blob], nome, { type: 'image/png' }); });
          linha.appendChild(botao('Compartilhar', 'sec', function () {
            if (pronto && navigator.canShare({ files: [pronto] })) navigator.share({ files: [pronto] }).catch(function () {});
            else arquivo(cv, nome, baixar);
          }));
        }
        // WhatsApp: no celular abre o compartilhar já com a imagem (escolha o grupo); no computador
        // baixa a imagem e abre o WhatsApp Web para anexar
        var arqZap = null;
        arquivo(cv, nome, function (blob) { arqZap = new File([blob], nome, { type: 'image/png' }); });
        var zap = botao('Enviar no WhatsApp', 'zap', function () {
          var texto = 'Tap list de hoje · Donkey Head Cervejaria ' + DADOS.unidade;
          if (arqZap && navigator.canShare && navigator.canShare({ files: [arqZap] })) {
            navigator.share({ files: [arqZap], text: texto }).catch(function () {});
          } else {
            arquivo(cv, nome, baixar);
            window.open('https://web.whatsapp.com/send?text=' + encodeURIComponent(texto + ' (imagem baixada: anexe no grupo)'), '_blank');
          }
        });
        bloco.appendChild(cv); bloco.appendChild(linha); bloco.appendChild(zap); alvo.appendChild(bloco);
      });
    }

    DHTap.carregar(DADOS, '../assets/img/logo-donkey-head.png?v=2').then(function (imgs) {
      var telas = DHTap.paginas(DADOS.taps || []);
      montar(document.getElementById('paginas'), telas, function (cv, tela, p) { DHTap.desenhar(cv, DADOS, tela, p + 1, telas.length, imgs); }, 'taplist', 'Baixar imagem (PNG)');
      var st = DHTap.paginasStory(DADOS.taps || []);
      montar(document.getElementById('stories'), st, function (cv, tela, p) { DHTap.desenharStory(cv, DADOS, tela, p + 1, st.length, imgs); }, 'story', 'Baixar Story');
    });
  </script>
</body>
</html>
