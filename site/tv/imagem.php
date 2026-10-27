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
    var W = 1920, H = 1080, POR_IMAGEM = 15; // até 15 cervejas por imagem
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

    // Cores de cada grupo, da cerveja mais leve para a mais forte (iguais às da tela da TV)
    var GRUPO = {
      1: { cor: '#e9c14a', fundo: 'rgba(233,193,74,' },
      2: { cor: '#d9773a', fundo: 'rgba(217,119,58,' },
      3: { cor: '#8fbf4a', fundo: 'rgba(143,191,74,' },
      4: { cor: '#a27ad8', fundo: 'rgba(162,122,216,' }
    };

    function desenhar(taps, imagens, logo, pagina, total) {
      var cv = document.createElement('canvas');
      cv.width = W; cv.height = H;
      var ctx = cv.getContext('2d');

      ctx.fillStyle = C.bg; ctx.fillRect(0, 0, W, H);
      var g = ctx.createRadialGradient(W, 0, 0, W, 0, 1100);
      g.addColorStop(0, 'rgba(233,162,59,0.14)'); g.addColorStop(1, 'rgba(233,162,59,0)');
      ctx.fillStyle = g; ctx.fillRect(0, 0, W, H);

      // Cabeçalho
      var X0 = 38, X1 = W - 38;
      if (logo) { var lh = 88, lw = logo.width * lh / logo.height; ctx.drawImage(logo, 44, 16, lw, lh); }
      ctx.textBaseline = 'alphabetic';
      ctx.font = '400 84px ' + BEBAS; ctx.fillStyle = C.texto; ctx.fillText('CERVEJAS', 128, 92);
      var wc = ctx.measureText('CERVEJAS ').width;
      ctx.fillStyle = C.ambar; ctx.fillText('ENGATADAS', 128 + wc, 92);
      ctx.font = '700 24px ' + INTER; ctx.fillStyle = C.suave; ctx.textAlign = 'right';
      if ('letterSpacing' in ctx) ctx.letterSpacing = '5px';
      ctx.fillText('DONKEY HEAD · ' + DADOS.unidade.toUpperCase() + (total > 1 ? '  ' + pagina + '/' + total : ''), X1, 70);
      if ('letterSpacing' in ctx) ctx.letterSpacing = '0px';

      // Linhas: altura conforme a quantidade (no máximo a de 9 linhas)
      var topo = 172, base = 1028;
      var grupos = 0;
      for (var i = 1; i < taps.length; i++) if (taps[i].cat !== taps[i - 1].cat) grupos++;
      var h = Math.floor((base - topo - grupos * 10) / Math.max(9, taps.length));
      var xImg = X0 + 30, xNome = xImg + h + 22;
      var xEstilo = xNome + (1300 - xNome) * 0.56;
      var xAbv = 1476, xIbu = 1614, xPreco = X1 - 24;

      // Títulos das colunas
      ctx.font = '400 34px ' + BEBAS; ctx.fillStyle = C.suave;
      ctx.textAlign = 'left'; ctx.fillText('CERVEJA', xNome, 150); ctx.fillText('ESTILO', xEstilo, 150);
      ctx.textAlign = 'right'; ctx.fillText('ÁLCOOL', xAbv, 150); ctx.fillText('IBU', xIbu, 150); ctx.fillText('300 ML', xPreco, 150);
      ctx.textAlign = 'left';
      ctx.fillStyle = C.linha; ctx.fillRect(X0, 160, X1 - X0, 3);

      var y = topo;
      taps.forEach(function (t, i) {
        if (i > 0 && t.cat !== taps[i - 1].cat) y += 10;
        var gr = GRUPO[t.cat] || GRUPO[2];
        var fundo = ctx.createLinearGradient(X0, 0, X1, 0);
        fundo.addColorStop(0, gr.fundo + '0.22)'); fundo.addColorStop(1, gr.fundo + '0.07)');
        ctx.fillStyle = fundo; ctx.fillRect(X0, y, X1 - X0, h);
        ctx.fillStyle = gr.cor; ctx.fillRect(X0, y, 10, h);
        ctx.fillStyle = 'rgba(255,255,255,0.06)'; ctx.fillRect(X0, y + h - 1, X1 - X0, 1);

        var img = imagens[i], lado = h - 8;
        if (img) {
          ctx.save(); retangulo(ctx, xImg, y + 4, lado, lado, 6); ctx.clip();
          var s = Math.min(img.width, img.height);
          ctx.drawImage(img, (img.width - s) / 2, (img.height - s) / 2, s, s, xImg, y + 4, lado, lado);
          ctx.restore();
        }

        var meio = y + h / 2;
        var tn = caber(ctx, t.nome.toUpperCase(), '400', Math.round(h * 0.66), BEBAS, xEstilo - xNome - 24);
        ctx.fillStyle = C.texto; ctx.fillText(t.nome.toUpperCase(), xNome, meio + tn * 0.36);
        var te = caber(ctx, (t.estilo || '').toUpperCase(), '400', Math.round(h * 0.5), BEBAS, xAbv - 190 - xEstilo);
        ctx.fillStyle = gr.cor; ctx.fillText((t.estilo || '').toUpperCase(), xEstilo, meio + te * 0.36);

        var tv = Math.round(h * 0.62);
        ctx.font = '400 ' + tv + 'px ' + BEBAS; ctx.textAlign = 'right';
        ctx.fillStyle = C.creme; ctx.fillText(t.abv || '', xAbv, meio + tv * 0.36);
        ctx.fillStyle = '#ff8f6b'; ctx.fillText(t.ibuNum || '', xIbu, meio + tv * 0.36);
        ctx.fillStyle = '#ffffff'; ctx.fillText(t.p300 || '', xPreco, meio + tv * 0.36);
        ctx.textAlign = 'left';
        y += h;
      });
      if (!taps.length) {
        ctx.font = '400 72px ' + BEBAS; ctx.fillStyle = C.suave; ctx.textAlign = 'center';
        ctx.fillText('TAP LIST EM ATUALIZAÇÃO', W / 2, H / 2); ctx.textAlign = 'left';
      }

      // Legenda dos grupos e aviso
      var usados = {};
      DADOS.taps.forEach(function (t) { usados[t.cat] = true; });
      var xl = X0;
      ctx.font = '700 22px ' + INTER;
      Object.keys(DADOS.grupos).forEach(function (k) {
        if (!usados[k]) return;
        ctx.fillStyle = GRUPO[k].cor; ctx.fillRect(xl, 1046, 18, 18);
        ctx.fillStyle = C.texto; ctx.fillText(DADOS.grupos[k], xl + 26, 1063);
        xl += 26 + ctx.measureText(DADOS.grupos[k]).width + 34;
      });
      ctx.font = '500 20px ' + INTER; ctx.fillStyle = C.suave; ctx.textAlign = 'right';
      ctx.fillText('Beba com moderação · Venda proibida para menores de 18 anos', X1, 1062); ctx.textAlign = 'left';
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
