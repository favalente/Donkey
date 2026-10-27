// Desenho da tap list em 1920x1080 (fundo claro, cartões em 2 colunas), usado pela
// tela da TV e pela imagem para o YouTube. As cervejas são divididas em telas pelos
// grupos (da mais leve para a mais forte), com no máximo POR_TELA cervejas cada.
(function () {
  var W = 1920, H = 1080, POR_TELA = 8;
  var BEBAS = '"Bebas Neue", Impact, sans-serif', INTER = 'Inter, system-ui, sans-serif';
  var C = { fundo: '#f6efe2', cartao: '#ffffff', borda: '#e4d8c4', texto: '#1d1611', suave: '#6d5f50', ambar: '#d4861a' };
  var GRUPO = { 1: '#c4930c', 2: '#c2581c', 3: '#4f8f22', 4: '#7449b3' };

  // Junta grupos inteiros numa tela enquanto couberem; grupo grande demais é quebrado
  function paginas(taps) {
    var grupos = [];
    taps.forEach(function (t) {
      var g = grupos[grupos.length - 1];
      if (!g || g.cat !== t.cat) grupos.push(g = { cat: t.cat, taps: [] });
      g.taps.push(t);
    });
    var telas = [], atual = [];
    grupos.forEach(function (g) {
      for (var i = 0; i < g.taps.length; i += POR_TELA) {
        var parte = g.taps.slice(i, i + POR_TELA);
        if (atual.length && atual.length + parte.length > POR_TELA) { telas.push(atual); atual = []; }
        atual = atual.concat(parte);
      }
    });
    if (atual.length || !telas.length) telas.push(atual);
    return telas;
  }

  function carregarImagem(src) {
    return new Promise(function (ok) {
      var img = new Image();
      img.onload = function () { ok(img); };
      img.onerror = function () { ok(null); };
      img.src = src;
    });
  }

  // Fontes, logo e rótulos; devolve um mapa src -> imagem
  function carregar(dados, logoSrc) {
    var srcs = (dados.taps || []).map(function (t) { return t.img; }).concat([logoSrc]);
    var fontes = document.fonts ? Promise.all([
      document.fonts.load('400 64px "Bebas Neue"'), document.fonts.load('700 24px Inter'), document.fonts.load('500 24px Inter')
    ]).catch(function () {}) : Promise.resolve();
    return Promise.all([fontes].concat(srcs.map(carregarImagem))).then(function (r) {
      var mapa = {};
      srcs.forEach(function (s, i) { mapa[s] = r[i + 1]; });
      mapa.logo = mapa[logoSrc];
      return mapa;
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

  function desenhar(cv, dados, tela, n, total, imgs) {
    cv.width = W; cv.height = H;
    var ctx = cv.getContext('2d');
    ctx.fillStyle = C.fundo; ctx.fillRect(0, 0, W, H);
    ctx.textBaseline = 'alphabetic';

    // Cabeçalho
    var X0 = 50, X1 = W - 50;
    if (imgs.logo) { var lh = 96, lw = imgs.logo.width * lh / imgs.logo.height; ctx.drawImage(imgs.logo, X0, 18, lw, lh); }
    ctx.font = '400 92px ' + BEBAS; ctx.fillStyle = C.texto; ctx.fillText('CERVEJAS', 140, 100);
    var wc = ctx.measureText('CERVEJAS ').width;
    ctx.fillStyle = C.ambar; ctx.fillText('ENGATADAS', 140 + wc, 100);
    ctx.textAlign = 'right';
    ctx.font = '700 26px ' + INTER; ctx.fillStyle = C.suave;
    if ('letterSpacing' in ctx) ctx.letterSpacing = '5px';
    ctx.fillText('DONKEY HEAD · ' + dados.unidade.toUpperCase(), X1, 62);
    if ('letterSpacing' in ctx) ctx.letterSpacing = '0px';
    if (total > 1) { ctx.font = '400 44px ' + BEBAS; ctx.fillStyle = C.ambar; ctx.fillText(n + '/' + total, X1, 108); }
    ctx.textAlign = 'left';
    ctx.fillStyle = C.borda; ctx.fillRect(X0, 132, X1 - X0, 3);

    if (!tela.length) {
      ctx.font = '400 80px ' + BEBAS; ctx.fillStyle = C.suave; ctx.textAlign = 'center';
      ctx.fillText('TAP LIST EM ATUALIZAÇÃO', W / 2, H / 2); ctx.textAlign = 'left';
      return;
    }

    // Blocos por grupo: título + cartões em 2 colunas
    var blocos = [];
    tela.forEach(function (t) {
      var b = blocos[blocos.length - 1];
      if (!b || b.cat !== t.cat) blocos.push(b = { cat: t.cat, taps: [] });
      b.taps.push(t);
    });
    var linhas = blocos.reduce(function (s, b) { return s + Math.ceil(b.taps.length / 2); }, 0);
    var TIT = 54, GAP = 18, topo = 152, base = 1032;
    var hCard = Math.min(230, Math.floor((base - topo - blocos.length * TIT - (linhas - blocos.length) * GAP - (blocos.length - 1) * 14) / linhas));
    var gapX = 32, wCard = (X1 - X0 - gapX) / 2;
    var y = topo;

    blocos.forEach(function (b) {
      var cor = GRUPO[b.cat] || GRUPO[2];
      // Título do grupo
      ctx.fillStyle = cor; ctx.fillRect(X0, y + 12, 26, 26);
      ctx.font = '400 42px ' + BEBAS; ctx.fillStyle = cor;
      ctx.fillText((dados.grupos[b.cat] || '').toUpperCase(), X0 + 40, y + 40);
      y += TIT;
      b.taps.forEach(function (t, i) {
        var col = i % 2, x = X0 + col * (wCard + gapX), yc = y + Math.floor(i / 2) * (hCard + GAP);
        // Cartão
        ctx.save();
        ctx.shadowColor = 'rgba(60,40,20,0.10)'; ctx.shadowBlur = 14; ctx.shadowOffsetY = 4;
        retangulo(ctx, x, yc, wCard, hCard, 18); ctx.fillStyle = C.cartao; ctx.fill();
        ctx.restore();
        ctx.save(); retangulo(ctx, x, yc, wCard, hCard, 18); ctx.clip();
        ctx.fillStyle = cor; ctx.fillRect(x, yc, 14, hCard);
        ctx.restore();
        ctx.lineWidth = 2; ctx.strokeStyle = C.borda; retangulo(ctx, x, yc, wCard, hCard, 18); ctx.stroke();

        // Rótulo
        var pad = 14, lado = hCard - pad * 2, img = imgs[t.img];
        if (img) {
          ctx.save(); retangulo(ctx, x + 14 + pad, yc + pad, lado, lado, 12); ctx.clip();
          var s = Math.min(img.width, img.height);
          ctx.drawImage(img, (img.width - s) / 2, (img.height - s) / 2, s, s, x + 14 + pad, yc + pad, lado, lado);
          ctx.restore();
        }

        // Preço de 300 ml à direita ("R$" menor, para sobrar espaço para o nome)
        var xDir = x + wCard - 30, meio = yc + hCard / 2;
        var tp = Math.round(Math.min(84, hCard * 0.36));
        var valor = (t.p300 || '').replace(/^R\$\s*/, ''), tr = Math.round(tp * 0.5);
        ctx.textAlign = 'right';
        ctx.font = '400 ' + tp + 'px ' + BEBAS; ctx.fillStyle = C.texto;
        ctx.fillText(valor, xDir, meio + tp * 0.42);
        var wPreco = ctx.measureText(valor).width;
        if (valor) {
          ctx.font = '400 ' + tr + 'px ' + BEBAS;
          ctx.fillText('R$', xDir - wPreco - 8, meio + tp * 0.42);
          wPreco += 8 + ctx.measureText('R$').width;
        }
        ctx.font = '700 ' + Math.round(Math.max(20, tp * 0.28)) + 'px ' + INTER; ctx.fillStyle = cor;
        ctx.fillText('300 ML', xDir, meio - tp * 0.5);
        ctx.textAlign = 'left';

        // Nome, estilo e números
        var xt = x + 14 + pad + lado + 26, larg = xDir - wPreco - 36 - xt;
        var tn = caber(ctx, t.nome.toUpperCase(), '400', Math.round(Math.min(84, hCard * 0.38)), BEBAS, larg);
        ctx.fillStyle = C.texto; ctx.fillText(t.nome.toUpperCase(), xt, meio - hCard * 0.04);
        var linhaInfo = [t.estilo, t.abv, t.ibuNum ? t.ibuNum + ' IBU' : ''].filter(Boolean).join('  ·  ');
        var ti = caber(ctx, linhaInfo, '700', Math.round(Math.min(30, hCard * 0.15)), INTER, larg);
        ctx.fillStyle = cor; ctx.fillText(linhaInfo, xt, meio + tn * 0.18 + ti * 1.1);
      });
      y += Math.ceil(b.taps.length / 2) * (hCard + GAP) - GAP + 14;
    });

    ctx.font = '500 20px ' + INTER; ctx.fillStyle = C.suave; ctx.textAlign = 'right';
    ctx.fillText('Beba com moderação · Venda proibida para menores de 18 anos', X1, 1064); ctx.textAlign = 'left';
  }

  window.DHTap = { W: W, H: H, paginas: paginas, carregar: carregar, desenhar: desenhar };
})();
