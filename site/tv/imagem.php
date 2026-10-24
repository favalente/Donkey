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
    <p>Imagem Full HD (1920×1080) com o que está engatado agora. Baixe e use no vídeo do YouTube.</p>
    <div class="unidades">
      <?php foreach (DH_UNIDADES as $u => $nomeU): ?>
        <a href="?unidade=<?= $u ?>" class="<?= $u === $unidade ? 'ativo' : '' ?>"><?= e($nomeU) ?></a>
      <?php endforeach; ?>
    </div>
    <div id="paginas"><p class="carregando">Gerando imagem…</p></div>
  </main>

  <script>
    var DADOS = <?= json_encode($dadosTv, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var UNIDADE = <?= json_encode($unidade) ?>;
    var W = 1920, H = 1080, POR_IMAGEM = 14; // 2 colunas x 7 linhas
    var C = { bg: '#16110d', card: '#241c16', linha: '#3d3128', texto: '#f4ece1', suave: '#bcab97', ambar: '#e9a23b', creme: '#f6e7c8' };
    var BEBAS = '"Bebas Neue", Impact, sans-serif', INTER = 'Inter, system-ui, sans-serif';

    function carregarImagem(src) {
      return new Promise(function (ok) {
        var img = new Image();
        img.onload = function () { ok(img); };
        img.onerror = function () { ok(null); };
        img.src = src;
      });
    }

    function retangulo(ctx, x, y, w, h, r) {
      ctx.beginPath();
      ctx.moveTo(x + r, y);
      ctx.arcTo(x + w, y, x + w, y + h, r);
      ctx.arcTo(x + w, y + h, x, y + h, r);
      ctx.arcTo(x, y + h, x, y, r);
      ctx.arcTo(x, y, x + w, y, r);
      ctx.closePath();
    }

    // Diminui a fonte até o texto caber na largura
    function caber(ctx, texto, peso, tamanho, familia, largura) {
      var t = tamanho;
      do { ctx.font = peso + ' ' + t + 'px ' + familia; } while (ctx.measureText(texto).width > largura && --t > 12);
      return t;
    }

    function desenhar(taps, imagens, logo, pagina, total) {
      var cv = document.createElement('canvas');
      cv.width = W; cv.height = H;
      var ctx = cv.getContext('2d');

      ctx.fillStyle = C.bg; ctx.fillRect(0, 0, W, H);
      var g = ctx.createRadialGradient(W, 0, 0, W, 0, 1100);
      g.addColorStop(0, 'rgba(233,162,59,0.18)'); g.addColorStop(1, 'rgba(233,162,59,0)');
      ctx.fillStyle = g; ctx.fillRect(0, 0, W, H);

      // Cabeçalho
      if (logo) { var lh = 130, lw = logo.width * lh / logo.height; ctx.drawImage(logo, 60, 34, lw, lh); }
      ctx.textBaseline = 'alphabetic';
      ctx.font = '400 116px ' + BEBAS; ctx.fillStyle = C.texto; ctx.fillText('TAP', 200, 132);
      var wTap = ctx.measureText('TAP ').width;
      ctx.fillStyle = C.ambar; ctx.fillText('LIST', 200 + wTap, 132);
      ctx.font = '700 28px ' + INTER; ctx.fillStyle = C.suave;
      if ('letterSpacing' in ctx) ctx.letterSpacing = '6px';
      ctx.fillText('DONKEY HEAD · ' + DADOS.unidade.toUpperCase(), 204, 172);
      if ('letterSpacing' in ctx) ctx.letterSpacing = '0px';
      if (total > 1) {
        ctx.font = '400 56px ' + BEBAS; ctx.fillStyle = C.creme; ctx.textAlign = 'right';
        ctx.fillText(pagina + '/' + total, W - 60, 130); ctx.textAlign = 'left';
      }
      ctx.fillStyle = C.linha; ctx.fillRect(60, 198, W - 120, 3);

      // Cervejas em 2 colunas
      var topo = 226, base = 1012, gapX = 36, gapY = 16;
      var linhas = Math.max(4, Math.ceil(taps.length / 2));
      var cw = (W - 120 - gapX) / 2;
      var ch = (base - topo - gapY * (linhas - 1)) / linhas;
      taps.forEach(function (t, i) {
        var col = i % 2, lin = Math.floor(i / 2);
        var x = 60 + col * (cw + gapX), y = topo + lin * (ch + gapY);
        retangulo(ctx, x, y, cw, ch, 18);
        ctx.fillStyle = C.card; ctx.fill();
        ctx.lineWidth = 2; ctx.strokeStyle = C.linha; ctx.stroke();

        // Rótulo
        var pad = 12, lado = ch - pad * 2, img = imagens[i];
        if (img) {
          ctx.save(); retangulo(ctx, x + pad, y + pad, lado, lado, 10); ctx.clip();
          var s = Math.min(img.width, img.height);
          ctx.drawImage(img, (img.width - s) / 2, (img.height - s) / 2, s, s, x + pad, y + pad, lado, lado);
          ctx.restore();
        }

        // Preços à direita
        var precos = t.precos || [];
        var tamPreco = Math.min(54, ch * 0.36), xDir = x + cw - 26, largPrecos = 0;
        ctx.textAlign = 'right';
        precos.forEach(function (p, k) {
          var yp = precos.length === 1 ? y + ch / 2 + tamPreco * 0.35 : y + ch / 2 + (k === 0 ? -tamPreco * 0.18 : tamPreco * 0.88);
          ctx.font = '400 ' + tamPreco + 'px ' + BEBAS; ctx.fillStyle = C.creme;
          ctx.fillText(p.valor, xDir, yp);
          var wv = ctx.measureText(p.valor).width;
          ctx.font = '700 ' + Math.round(tamPreco * 0.38) + 'px ' + INTER; ctx.fillStyle = C.ambar;
          ctx.fillText(p.tam, xDir - wv - 12, yp - tamPreco * 0.12);
          largPrecos = Math.max(largPrecos, wv + 12 + ctx.measureText(p.tam).width);
        });
        ctx.textAlign = 'left';

        // Nome e estilo
        var xt = x + pad + lado + 24, larg = xDir - largPrecos - 28 - xt;
        var tn = caber(ctx, t.nome.toUpperCase(), '400', Math.min(66, ch * 0.42), BEBAS, larg);
        ctx.fillStyle = C.texto; ctx.fillText(t.nome.toUpperCase(), xt, y + ch / 2 + tn * 0.08);
        var meta = [t.estilo, t.abv, t.ibu].filter(Boolean).join(' · ');
        caber(ctx, meta, '500', Math.min(28, ch * 0.2), INTER, larg);
        ctx.fillStyle = C.suave; ctx.fillText(meta, xt, y + ch / 2 + tn * 0.08 + Math.min(40, ch * 0.3));
      });
      if (!taps.length) {
        ctx.font = '400 72px ' + BEBAS; ctx.fillStyle = C.suave; ctx.textAlign = 'center';
        ctx.fillText('TAP LIST EM ATUALIZAÇÃO', W / 2, H / 2); ctx.textAlign = 'left';
      }

      ctx.font = '500 24px ' + INTER; ctx.fillStyle = C.suave;
      ctx.fillText('Beba com moderação · Venda proibida para menores de 18 anos', 60, 1052);
      ctx.textAlign = 'right'; ctx.fillText('donkeyheadcervejaria.com.br', W - 60, 1052); ctx.textAlign = 'left';
      return cv;
    }

    Promise.all([
      document.fonts ? Promise.all([document.fonts.load('400 64px "Bebas Neue"'), document.fonts.load('500 24px Inter'), document.fonts.load('700 24px Inter')]) : null,
      carregarImagem('../assets/img/logo-donkey-head.png'),
      Promise.all(DADOS.taps.map(function (t) { return carregarImagem(t.img); }))
    ]).then(function (r) {
      var logo = r[1], imagens = r[2], taps = DADOS.taps;
      var total = Math.max(1, Math.ceil(taps.length / POR_IMAGEM));
      var hoje = new Date().toISOString().slice(0, 10);
      var alvo = document.getElementById('paginas');
      alvo.innerHTML = '';
      for (var p = 0; p < total; p++) {
        var cv = desenhar(taps.slice(p * POR_IMAGEM, (p + 1) * POR_IMAGEM), imagens.slice(p * POR_IMAGEM, (p + 1) * POR_IMAGEM), logo, p + 1, total);
        var bloco = document.createElement('div'); bloco.className = 'pagina';
        var btn = document.createElement('button'); btn.className = 'baixar';
        btn.textContent = total > 1 ? 'Baixar imagem ' + (p + 1) + ' de ' + total : 'Baixar imagem (PNG)';
        (function (canvas, nome) {
          btn.addEventListener('click', function () {
            canvas.toBlob(function (blob) {
              var a = document.createElement('a');
              a.href = URL.createObjectURL(blob); a.download = nome;
              document.body.appendChild(a); a.click(); a.remove();
              setTimeout(function () { URL.revokeObjectURL(a.href); }, 5000);
            }, 'image/png');
          });
        })(cv, 'taplist-' + UNIDADE + '-' + hoje + (total > 1 ? '-' + (p + 1) : '') + '.png');
        bloco.appendChild(cv); bloco.appendChild(btn); alvo.appendChild(bloco);
      }
    });
  </script>
</body>
</html>
