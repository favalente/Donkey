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
    ctx.fillText('DONKEY HEAD CERVEJARIA · ' + dados.unidade.toUpperCase(), X1, 62);
    if ('letterSpacing' in ctx) ctx.letterSpacing = '0px';
    if (total > 1) { ctx.font = '400 44px ' + BEBAS; ctx.fillStyle = C.ambar; ctx.fillText(n + '/' + total, X1, 108); }
    ctx.textAlign = 'left';
    ctx.fillStyle = C.borda; ctx.fillRect(X0, 132, X1 - X0, 3);

    if (!tela.length) {
      ctx.font = '400 80px ' + BEBAS; ctx.fillStyle = C.suave; ctx.textAlign = 'center';
      ctx.fillText('TAP LIST EM ATUALIZAÇÃO', W / 2, H / 2); ctx.textAlign = 'left';
      return;
    }

    // Cartões em sequência (da mais leve para a mais forte), 2 colunas, sem título de grupo:
    // a cor da faixa lateral mostra o grupo e a legenda no rodapé explica as cores
    var linhas = Math.ceil(tela.length / 2);
    var GAP = 18, topo = 156, base = 992, FAIXA = 30;
    var hCard = Math.min(230, Math.floor((base - topo - (linhas - 1) * GAP) / linhas));
    var gapX = 32, wCard = (X1 - X0 - gapX) / 2;
    var y = topo;

    tela.forEach(function (t, i) {
        var cor = GRUPO[t.cat] || GRUPO[2];
        var col = i % 2, x = X0 + col * (wCard + gapX), yc = y + Math.floor(i / 2) * (hCard + GAP);
        // Cartão
        ctx.save();
        ctx.shadowColor = 'rgba(60,40,20,0.10)'; ctx.shadowBlur = 14; ctx.shadowOffsetY = 4;
        retangulo(ctx, x, yc, wCard, hCard, 18); ctx.fillStyle = C.cartao; ctx.fill();
        ctx.restore();
        ctx.save(); retangulo(ctx, x, yc, wCard, hCard, 18); ctx.clip();
        ctx.fillStyle = cor; ctx.fillRect(x, yc, FAIXA, hCard);
        ctx.restore();
        ctx.lineWidth = 2; ctx.strokeStyle = C.borda; retangulo(ctx, x, yc, wCard, hCard, 18); ctx.stroke();

        // Rótulo
        var pad = 14, lado = Math.min(hCard - pad * 2, 176), img = imgs[t.img];
        if (img) {
          ctx.save(); retangulo(ctx, x + FAIXA + pad, yc + (hCard - lado) / 2, lado, lado, 12); ctx.clip();
          var s = Math.min(img.width, img.height);
          ctx.drawImage(img, (img.width - s) / 2, (img.height - s) / 2, s, s, x + FAIXA + pad, yc + (hCard - lado) / 2, lado, lado);
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

        // Nome, estilo e números em três linhas, centralizadas na altura do cartão
        var xt = x + FAIXA + pad + lado + 26, larg = xDir - wPreco - 36 - xt;
        var nome = t.nome.toUpperCase(), estilo = (t.estilo || '').toUpperCase();
        var numeros = [t.abv, t.ibuNum ? t.ibuNum + ' IBU' : ''].filter(Boolean).join('  ·  ');
        var tn = caber(ctx, nome, '400', Math.round(Math.min(78, hCard * 0.31)), BEBAS, larg);
        var te = caber(ctx, estilo, '400', Math.round(Math.min(56, hCard * 0.23)), BEBAS, larg);
        var tm = caber(ctx, numeros, '400', Math.round(Math.min(56, hCard * 0.23)), BEBAS, larg);
        var esp = Math.round(hCard * 0.07), altura = tn + te + tm + esp * 2;
        var yb = meio - altura / 2;
        ctx.font = '400 ' + tn + 'px ' + BEBAS; ctx.fillStyle = C.texto; ctx.fillText(nome, xt, yb + tn * 0.86);
        yb += tn + esp;
        ctx.font = '400 ' + te + 'px ' + BEBAS; ctx.fillStyle = cor; ctx.fillText(estilo, xt, yb + te * 0.86);
        yb += te + esp;
        ctx.font = '400 ' + tm + 'px ' + BEBAS; ctx.fillStyle = '#3f3328'; ctx.fillText(numeros, xt, yb + tm * 0.86);
    });

    // Legenda das cores (os grupos que aparecem nesta tela)
    var xl = X0, yl = 1038;
    Object.keys(dados.grupos).forEach(function (k) {
      if (!tela.some(function (t) { return String(t.cat) === k; })) return;
      ctx.fillStyle = GRUPO[k] || GRUPO[2]; retangulo(ctx, xl, yl - 26, 28, 28, 6); ctx.fill();
      ctx.font = '400 36px ' + BEBAS; ctx.fillStyle = C.texto;
      var rot = (dados.grupos[k] || '').toUpperCase();
      ctx.fillText(rot, xl + 42, yl + 2);
      xl += 42 + ctx.measureText(rot).width + 44;
    });

    ctx.font = '500 18px ' + INTER; ctx.fillStyle = C.suave; ctx.textAlign = 'right';
    ctx.fillText('Beba com moderação · Venda proibida para menores de 18 anos', X1, 1070); ctx.textAlign = 'left';
  }

  // ---------------------------------------------------------------------------------------
  // Story do Instagram (1080x1920): fundo escuro, uma coluna, no máximo POR_STORY cervejas.
  // O topo e o rodapé ficam livres para a barra do Instagram (nome, respostas).
  var SW = 1080, SH = 1920, POR_STORY = 9;
  var ESC = { fundo: '#16110d', cartao: '#231b15', borda: '#3d3128', texto: '#f6eee3', suave: '#bcab97', ambar: '#e9a23b' };
  var GRUPO_ESC = { 1: '#f0c23b', 2: '#ee7d3f', 3: '#7cc447', 4: '#a985e6' };
  var DIAS = ['DOMINGO', 'SEGUNDA', 'TERÇA', 'QUARTA', 'QUINTA', 'SEXTA', 'SÁBADO'];

  function paginasStory(taps) {
    var r = [];
    for (var i = 0; i < taps.length; i += POR_STORY) r.push(taps.slice(i, i + POR_STORY));
    return r.length ? r : [[]];
  }

  function desenharStory(cv, dados, tela, n, total, imgs) {
    cv.width = SW; cv.height = SH;
    var ctx = cv.getContext('2d');
    // Fundo: escuro com um brilho âmbar no alto e outro discreto embaixo
    ctx.fillStyle = ESC.fundo; ctx.fillRect(0, 0, SW, SH);
    var g = ctx.createRadialGradient(SW / 2, 260, 40, SW / 2, 260, 900);
    g.addColorStop(0, 'rgba(233,162,59,0.30)'); g.addColorStop(1, 'rgba(233,162,59,0)');
    ctx.fillStyle = g; ctx.fillRect(0, 0, SW, SH);
    var g2 = ctx.createRadialGradient(SW / 2, SH + 100, 40, SW / 2, SH + 100, 900);
    g2.addColorStop(0, 'rgba(233,162,59,0.16)'); g2.addColorStop(1, 'rgba(233,162,59,0)');
    ctx.fillStyle = g2; ctx.fillRect(0, 0, SW, SH);
    ctx.textBaseline = 'alphabetic';

    // Cabeçalho: logo, título e casa + dia
    var cx = SW / 2;
    if (imgs.logo) { var lh = 150, lw = imgs.logo.width * lh / imgs.logo.height; ctx.drawImage(imgs.logo, cx - lw / 2, 150, lw, lh); }
    ctx.textAlign = 'center';
    ctx.font = '400 128px ' + BEBAS; ctx.fillStyle = ESC.texto;
    ctx.fillText('NA TORNEIRA', cx, 418);
    ctx.font = '400 72px ' + BEBAS; ctx.fillStyle = ESC.ambar;
    var hoje = new Date();
    ctx.fillText('HOJE · ' + DIAS[hoje.getDay()] + ' ' + String(hoje.getDate()).padStart(2, '0') + '/' + String(hoje.getMonth() + 1).padStart(2, '0'), cx, 492);
    ctx.font = '700 26px ' + INTER; ctx.fillStyle = ESC.suave;
    if ('letterSpacing' in ctx) ctx.letterSpacing = '6px';
    ctx.fillText('DONKEY HEAD CERVEJARIA · ' + dados.unidade.toUpperCase() + (total > 1 ? ' · ' + n + '/' + total : ''), cx, 546);
    if ('letterSpacing' in ctx) ctx.letterSpacing = '0px';
    ctx.textAlign = 'left';

    if (!tela.length) {
      ctx.font = '400 72px ' + BEBAS; ctx.fillStyle = ESC.suave; ctx.textAlign = 'center';
      ctx.fillText('TAP LIST EM ATUALIZAÇÃO', cx, SH / 2); ctx.textAlign = 'left';
      return;
    }

    // Cartões numa coluna, crescendo para ocupar o espaço
    var X0 = 60, wCard = SW - 120, topo = 590, base = 1596, GAP = 16, FAIXA = 22;
    var hCard = Math.min(200, Math.floor((base - topo - (tela.length - 1) * GAP) / tela.length));
    var y0 = topo + Math.max(0, Math.floor((base - topo - (tela.length * (hCard + GAP) - GAP)) / 2));
    tela.forEach(function (t, i) {
      var cor = GRUPO_ESC[t.cat] || GRUPO_ESC[2], x = X0, yc = y0 + i * (hCard + GAP);
      ctx.save(); retangulo(ctx, x, yc, wCard, hCard, 20); ctx.fillStyle = ESC.cartao; ctx.fill(); ctx.clip();
      ctx.fillStyle = cor; ctx.fillRect(x, yc, FAIXA, hCard);
      ctx.restore();
      ctx.lineWidth = 2; ctx.strokeStyle = ESC.borda; retangulo(ctx, x, yc, wCard, hCard, 20); ctx.stroke();

      var pad = Math.round(hCard * 0.1), lado = hCard - pad * 2, img = imgs[t.img], xi = x + FAIXA + 18;
      if (img) {
        ctx.save(); retangulo(ctx, xi, yc + pad, lado, lado, 14); ctx.clip();
        var s = Math.min(img.width, img.height);
        ctx.drawImage(img, (img.width - s) / 2, (img.height - s) / 2, s, s, xi, yc + pad, lado, lado);
        ctx.restore();
      }

      // Preço (300 ml) à direita
      var xDir = x + wCard - 28, meio = yc + hCard / 2;
      var tp = Math.round(Math.min(78, hCard * 0.42)), valor = (t.p300 || '').replace(/^R\$\s*/, '');
      ctx.textAlign = 'right';
      ctx.font = '400 ' + tp + 'px ' + BEBAS; ctx.fillStyle = ESC.texto;
      ctx.fillText(valor, xDir, meio + tp * 0.5);
      var wPreco = ctx.measureText(valor).width;
      if (valor) {
        ctx.font = '400 ' + Math.round(tp * 0.48) + 'px ' + BEBAS; ctx.fillStyle = ESC.suave;
        ctx.fillText('R$', xDir - wPreco - 6, meio + tp * 0.5);
        wPreco += 6 + ctx.measureText('R$').width;
      }
      ctx.font = '700 ' + Math.round(Math.max(16, tp * 0.26)) + 'px ' + INTER; ctx.fillStyle = cor;
      ctx.fillText('300 ML', xDir, meio - tp * 0.42);
      ctx.textAlign = 'left';

      // Nome, estilo e números
      var xt = xi + lado + 24, larg = xDir - wPreco - 28 - xt;
      var nome = t.nome.toUpperCase(), estilo = (t.estilo || '').toUpperCase();
      var numeros = [t.abv, t.ibuNum ? t.ibuNum + ' IBU' : ''].filter(Boolean).join('  ·  ');
      var tn = caber(ctx, nome, '400', Math.round(Math.min(64, hCard * 0.34)), BEBAS, larg);
      var te = caber(ctx, estilo, '400', Math.round(Math.min(40, hCard * 0.22)), BEBAS, larg);
      var tm = caber(ctx, numeros, '400', Math.round(Math.min(38, hCard * 0.21)), BEBAS, larg);
      var esp = Math.round(hCard * 0.05), alt = tn + te + tm + esp * 2, yb = meio - alt / 2;
      ctx.font = '400 ' + tn + 'px ' + BEBAS; ctx.fillStyle = ESC.texto; ctx.fillText(nome, xt, yb + tn * 0.86);
      yb += tn + esp;
      ctx.font = '400 ' + te + 'px ' + BEBAS; ctx.fillStyle = cor; ctx.fillText(estilo, xt, yb + te * 0.86);
      yb += te + esp;
      ctx.font = '400 ' + tm + 'px ' + BEBAS; ctx.fillStyle = ESC.suave; ctx.fillText(numeros, xt, yb + tm * 0.86);
    });

    // Legenda das cores (centralizada, em até duas linhas)
    var itens = Object.keys(dados.grupos).filter(function (k) { return tela.some(function (t) { return String(t.cat) === k; }); });
    ctx.font = '400 34px ' + BEBAS;
    var medidas = itens.map(function (k) { return 30 + 12 + ctx.measureText((dados.grupos[k] || '').toUpperCase()).width; });
    var linhasL = [[]], larguras = [0];
    itens.forEach(function (k, i) {
      var w = medidas[i] + (linhasL[linhasL.length - 1].length ? 36 : 0);
      if (larguras[larguras.length - 1] + w > SW - 120) { linhasL.push([]); larguras.push(0); w = medidas[i]; }
      linhasL[linhasL.length - 1].push(i); larguras[larguras.length - 1] += w;
    });
    linhasL.forEach(function (lin, li) {
      var xl = (SW - larguras[li]) / 2, yl = 1660 + li * 50;
      lin.forEach(function (i, j) {
        if (j) xl += 36;
        var k = itens[i];
        ctx.fillStyle = GRUPO_ESC[k] || GRUPO_ESC[2]; retangulo(ctx, xl, yl - 26, 28, 28, 6); ctx.fill();
        ctx.fillStyle = ESC.texto; ctx.fillText((dados.grupos[k] || '').toUpperCase(), xl + 40, yl);
        xl += medidas[i];
      });
    });
    ctx.textAlign = 'center';
    ctx.font = '500 22px ' + INTER; ctx.fillStyle = ESC.suave;
    ctx.fillText('Beba com moderação · Venda proibida para menores de 18 anos', cx, 1770);
    ctx.textAlign = 'left';
  }

  window.DHTap = { W: W, H: H, paginas: paginas, carregar: carregar, desenhar: desenhar, paginasStory: paginasStory, desenharStory: desenharStory };
})();
