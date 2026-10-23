<?php
require __DIR__ . '/inc/dados.php';

$dados = dh_carregar();
$catalogo = array_values(array_filter($dados['cervejas'], fn($c) => !empty($c['ativa'])));
$taplists = [];
$engatadas = [];
foreach (DH_UNIDADES as $u => $_) {
    $taplists[$u] = dh_taplist($dados, $u);
    foreach ($taplists[$u] as $c) {
        if ($c['id']) {
            $engatadas[$c['id']][] = $u;
        }
    }
}
$temTaplist = (bool) array_filter($taplists);

$galeria = [1, 2, 3, 4, 24, 28, 34, 35, 36, 49, 5, 6, 47, 45, 29, 30, 9, 15, 11, 12, 13, 23, 26, 16, 17, 18, 19, 7, 21, 25, 8, 22, 27, 31, 32, 33, 37, 38, 39, 40, 41, 42, 43, 50];
$cardapio = 'https://xmenu.com.br/pedidos/?loja=20476&camp=XMENU-TESTE';

function ficha(array $c): string
{
    $itens = [];
    foreach (['abv' => 'ABV', 'ibu' => 'IBU'] as $k => $rot) {
        if (($c[$k] ?? '') !== '') {
            $itens[] = '<div><dt>' . $rot . '</dt><dd>' . e(dh_num($c[$k])) . ($k === 'abv' ? '%' : '') . '</dd></div>';
        }
    }
    return $itens ? '<dl class="ficha">' . implode('', $itens) . '</dl>' : '';
}

function detalhe(array $c): string
{
    $paras = array_filter(array_map('trim', preg_split('/\n\s*\n/', (string) ($c['descricao'] ?? ''))));
    $html = '<img src="' . e($c['imagem']) . '" alt="Rótulo da ' . e($c['nome']) . '" loading="lazy">';
    $html .= '<div class="detalhe-texto"><h3>' . e($c['nome']) . '</h3>';
    $html .= '<p class="estilo">' . e($c['estilo']) . ($c['pais'] ? ' · ' . e($c['pais']) : '') . '</p>';
    $html .= ficha($c);
    foreach ($paras as $p) {
        $html .= '<p>' . nl2br(e($p)) . '</p>';
    }
    return $html . '</div>';
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Donkey Head Cervejaria · Mais cerveja, menos arenga!</title>
  <meta name="description" content="Cervejaria Donkey Head: produção local de cerveja artesanal em Fortaleza, garantindo uma cerveja extremamente fresca. Unidades Aldeota e Maraponga.">
  <meta name="theme-color" content="#16110d">
  <link rel="canonical" href="https://www.donkeyheadcervejaria.com.br/">
  <meta property="og:type" content="website">
  <meta property="og:title" content="Donkey Head Cervejaria">
  <meta property="og:description" content="Mais cerveja, menos arenga! Cerveja artesanal produzida em Fortaleza.">
  <meta property="og:url" content="https://www.donkeyheadcervejaria.com.br/">
  <meta property="og:image" content="https://www.donkeyheadcervejaria.com.br/assets/img/galeria/1.jpg">
  <link rel="icon" href="favicon.ico">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/style.css?v=4">
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "Brewery",
    "name": "Donkey Head Cervejaria",
    "url": "https://www.donkeyheadcervejaria.com.br/",
    "email": "contato@donkeyheadcervejaria.com.br",
    "sameAs": ["https://www.instagram.com/donkeyheadcervejaria/", "https://www.facebook.com/donkeyheadcervejaria/"],
    "location": [
      {"@type": "Place", "name": "Donkey Head Aldeota", "telephone": "+55-85-98212-4149", "address": {"@type": "PostalAddress", "streetAddress": "R. Norvinda Píres, 42", "addressLocality": "Fortaleza", "addressRegion": "CE", "postalCode": "60150-280", "addressCountry": "BR"}},
      {"@type": "Place", "name": "Donkey Head Maraponga", "telephone": "+55-85-9420-6246", "address": {"@type": "PostalAddress", "streetAddress": "Av. Godofredo Maciel, 2127", "addressLocality": "Fortaleza", "addressRegion": "CE", "postalCode": "60710-001", "addressCountry": "BR"}}
    ]
  }
  </script>
</head>
<body>

  <div class="age-gate" id="age-gate" role="dialog" aria-modal="true" aria-labelledby="age-title" hidden>
    <div class="age-card">
      <img src="assets/img/logo-donkey-head.png" alt="" width="120" height="170">
      <h2 id="age-title">Você tem 18 anos ou mais?</h2>
      <p>Este site contém informações sobre bebidas alcoólicas e é destinado a maiores de idade.</p>
      <div class="age-actions">
        <button class="btn btn-primary" id="age-yes">Sim, tenho 18+</button>
        <a class="btn btn-ghost" href="https://www.google.com">Não</a>
      </div>
    </div>
  </div>

  <header class="site-header" id="topo">
    <div class="container nav">
      <a class="brand" href="#topo" aria-label="Donkey Head Cervejaria, início">
        <img src="assets/img/logo-donkey-head.png" alt="" width="34" height="48">
        <span>Donkey Head</span>
      </a>
      <button class="nav-toggle" aria-expanded="false" aria-controls="menu" aria-label="Abrir menu">
        <span></span><span></span><span></span>
      </button>
      <nav id="menu" class="menu">
        <a href="#historia">Nossa história</a>
        <?php if ($temTaplist): ?><a href="#taplist">Tap List</a><?php endif; ?>
        <a href="#cervejas">Cervejas</a>
        <a href="#galeria">Galeria</a>
        <a href="#contato">Contato</a>
        <a class="btn btn-primary btn-sm" href="<?= e($cardapio) ?>" target="_blank" rel="noopener">Cardápio</a>
      </nav>
    </div>
  </header>

  <main>
    <section class="hero">
      <video class="hero-video" autoplay muted loop playsinline preload="metadata" poster="assets/img/chope-poster.jpg" aria-hidden="true">
        <source src="assets/video/chope.mp4" type="video/mp4">
      </video>
      <div class="container hero-inner">
        <img class="hero-logo" src="assets/img/logo-donkey-head.png" alt="Donkey Head Cervejaria" width="400" height="566">
        <div class="hero-texto">
          <h1><img src="assets/img/mais-cerveja-menos-arenga.png" alt="Mais cerveja, menos arenga!" width="1003" height="324"></h1>
          <p class="lead">Cerveja artesanal produzida em Fortaleza, do fermentador direto para a câmara fria e para o seu copo.</p>
          <div class="hero-actions">
            <a class="btn btn-primary" href="<?= $temTaplist ? '#taplist' : '#cervejas' ?>"><?= $temTaplist ? 'O que está engatado' : 'Nossas cervejas' ?></a>
            <a class="btn btn-ghost" href="#contato">Onde estamos</a>
          </div>
        </div>
      </div>
    </section>

    <section class="section" id="historia">
      <div class="container split">
        <div>
          <p class="eyebrow">Nossa história</p>
          <h2>Começou no quintal de casa.</h2>
          <figure class="historia-foto">
            <img src="assets/img/galeria/mini/24.jpg" alt="Interior da Donkey Head Cervejaria" loading="lazy">
          </figure>
        </div>
        <div class="prose">
          <p>Três amigos apaixonados por cerveja resolvem fazê-la no quintal de casa. O resultado da primeira produção foi inesperado: uma cerveja excelente. Entregamos para os amigos e alguns especialistas e logo começaram os elogios, então surgiu a ideia: por que não comercializá-la?</p>
          <p>Decidimos então fazer um teste mercadológico como cervejaria cigana, fizemos nossa cerveja em uma estrutura de outra cervejaria, a Patt Lou em Recife, a qual agradecemos ao cervejeiro Luiz imensamente por fazer parte da nossa história.</p>
          <p>Após a boa aceitação do mercado, surgiu mais uma ideia: e se nós produzíssemos localmente, proporcionando uma cerveja extremamente fresca, sem intervenção de mau armazenamento ou transporte?</p>
          <p>Após fazermos um estudo de mercado, percebemos a necessidade de um local que, além de boas cervejas, oferecesse também uma culinária à altura. Então surgiu o desejo de criar uma cervejaria com produção local aliada a uma boa cozinha.</p>
          <p>Nossa cerveja sai do fermentador direto para a câmara fria, o que retarda consideravelmente o seu envelhecimento, garantindo uma maior qualidade. Nosso chef de cozinha, Júnior Sales, possui formação pela Le Cordon Bleu, em Paris, considerada uma das mais renomadas escolas de gastronomia do mundo.</p>
        </div>
      </div>
    </section>

    <?php if ($temTaplist): ?>
    <section class="section section-feature" id="taplist">
      <div class="container">
        <div class="section-head">
          <p class="eyebrow">Tap List</p>
          <h2>Engatadas agora</h2>
          <p>Confira o que temos engatado neste momento em cada unidade.</p>
        </div>
        <div class="tabs" role="tablist">
          <?php $primeira = true; foreach (DH_UNIDADES as $u => $nomeU): ?>
            <button class="tab" role="tab" id="tab-<?= $u ?>" aria-controls="tap-<?= $u ?>" aria-selected="<?= $primeira ? 'true' : 'false' ?>"><?= e($nomeU) ?> <span class="contagem"><?= count($taplists[$u]) ?></span></button>
          <?php $primeira = false; endforeach; ?>
        </div>
        <?php $primeira = true; foreach (DH_UNIDADES as $u => $nomeU): ?>
          <div class="taps" role="tabpanel" id="tap-<?= $u ?>" aria-labelledby="tab-<?= $u ?>" <?= $primeira ? '' : 'hidden' ?>>
            <?php if (!$taplists[$u]): ?>
              <p class="vazio">Tap list da unidade <?= e($nomeU) ?> em atualização. Pergunte ao nosso barman!</p>
            <?php endif; ?>
            <ol>
              <?php foreach ($taplists[$u] as $n => $c): ?>
                <li>
                  <button class="tap"<?= $c['id'] ? ' data-cerveja="' . e($c['id']) . '"' : ' disabled' ?>>
                    <span class="tap-num"><?= $n + 1 ?></span>
                    <img src="<?= e($c['imagem']) ?>" alt="" loading="lazy" width="64" height="64">
                    <span class="tap-info">
                      <strong><?= e($c['nome']) ?></strong>
                      <span><?= e($c['estilo']) ?></span>
                      <?php if ($c['precos']): ?>
                        <span class="tap-preco"><?php foreach ($c['precos'] as $p): ?><span><?= e($p['tam']) ?> <?= e(dh_preco($p['valor'])) ?></span><?php endforeach; ?></span>
                      <?php endif; ?>
                    </span>
                    <span class="tap-abv"><?= $c['abv'] !== '' ? e(dh_num($c['abv'])) . '%' : '' ?><?php if ($c['ibu'] !== ''): ?><small><?= e($c['ibu']) ?> IBU</small><?php endif; ?></span>
                  </button>
                </li>
              <?php endforeach; ?>
            </ol>
          </div>
        <?php $primeira = false; endforeach; ?>
      </div>
    </section>
    <?php endif; ?>

    <section class="section section-alt" id="cervejas">
      <div class="container">
        <div class="section-head">
          <p class="eyebrow">Nossos rótulos</p>
          <h2>Nossas cervejas</h2>
          <p>Toque no rótulo para ver os detalhes da cerveja.</p>
        </div>
        <?php if ($temTaplist): ?>
        <div class="filtros" role="group" aria-label="Filtrar cervejas">
          <button class="filtro ativo" data-filtro="todas" aria-pressed="true">Todas</button>
          <button class="filtro" data-filtro="engatada" aria-pressed="false">Engatadas agora</button>
        </div>
        <?php endif; ?>
        <div class="beer-grid">
          <?php foreach ($catalogo as $c): $onde = $engatadas[$c['id']] ?? []; ?>
            <article class="beer" data-engatada="<?= $onde ? '1' : '0' ?>">
              <button class="beer-btn" data-cerveja="<?= e($c['id']) ?>">
                <span class="beer-img">
                  <img src="<?= e($c['imagem']) ?>" alt="Rótulo da <?= e($c['nome']) ?>" loading="lazy" width="640" height="640">
                  <?php if ($onde): ?><span class="selo">Engatada</span><?php endif; ?>
                </span>
                <span class="beer-nome"><?= e($c['nome']) ?></span>
                <span class="beer-estilo"><?= e($c['estilo']) ?><?= $c['abv'] !== '' ? ' · ' . e(dh_num($c['abv'])) . '%' : '' ?></span>
              </button>
              <div class="detalhe" id="cerveja-<?= e($c['id']) ?>" hidden>
                <?= detalhe($c) ?>
                <?php if ($onde): ?><p class="onde">Engatada agora em: <?= e(implode(' e ', array_map(fn($u) => DH_UNIDADES[$u], $onde))) ?></p><?php endif; ?>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
        <details class="legendas">
          <summary>O que significam ABV e IBU?</summary>
          <dl>
            <dt>IBU</dt><dd>International Bitterness Unit (Unidade Internacional de Amargor): unidade que permite classificar uma cerveja pelo nível de amargor apresentado.</dd>
            <dt>ABV</dt><dd>Alcohol by Volume (álcool por volume), também conhecida por gradação alcoólica, foi adotada internacionalmente para indicar o percentual em volume da quantidade de álcool em uma bebida.</dd>
          </dl>
        </details>
      </div>
    </section>

    <section class="section" id="galeria">
      <div class="container">
        <div class="section-head">
          <p class="eyebrow">Galeria</p>
          <h2>Por dentro da Donkey</h2>
          <p>Toque nas fotos para ampliá-las.</p>
        </div>
        <div class="galeria">
          <?php foreach ($galeria as $i => $n): ?>
            <a href="assets/img/galeria/<?= $n ?>.jpg" class="foto<?= $i >= 12 ? ' extra' : '' ?>" data-indice="<?= $i ?>">
              <img src="assets/img/galeria/mini/<?= $n ?>.jpg" alt="Foto <?= $i + 1 ?> da Donkey Head Cervejaria" loading="lazy">
            </a>
          <?php endforeach; ?>
        </div>
        <p class="centro"><button class="btn btn-ghost" id="mais-fotos">Ver todas as <?= count($galeria) ?> fotos</button></p>
      </div>
    </section>

    <section class="section section-feature cardapio-cta">
      <div class="container cta">
        <div>
          <p class="eyebrow">Cardápio</p>
          <h2>Boa cerveja pede boa cozinha.</h2>
          <p class="lead">Veja o nosso cardápio completo e faça o seu pedido online.</p>
        </div>
        <a class="btn btn-primary" href="<?= e($cardapio) ?>" target="_blank" rel="noopener">Ver cardápio</a>
      </div>
    </section>

    <section class="section" id="contato">
      <div class="container">
        <div class="section-head">
          <p class="eyebrow">Contate-nos</p>
          <h2>Venha nos visitar</h2>
          <p>Para dúvidas, parcerias, críticas, sugestões e demais assuntos, fale com a gente.</p>
        </div>
        <div class="units">
          <article class="unit">
            <h3>Sede Aldeota</h3>
            <p class="addr">R. Norvinda Píres, 42 · Aldeota<br>Fortaleza - CE, 60150-280</p>
            <div class="unit-actions">
              <a class="btn btn-whats btn-sm" href="https://wa.me/5585982124149" target="_blank" rel="noopener">WhatsApp (85) 98212-4149</a>
              <a class="btn btn-ghost btn-sm" href="https://www.instagram.com/donkeyheadcervejaria/" target="_blank" rel="noopener">Instagram</a>
              <a class="btn btn-ghost btn-sm" href="https://www.facebook.com/donkeyheadcervejaria/" target="_blank" rel="noopener">Facebook</a>
            </div>
            <iframe class="mapa" title="Mapa da unidade Aldeota" loading="lazy" referrerpolicy="no-referrer-when-downgrade" src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3981.335261517803!2d-38.50336088524095!3d-3.7369241972812173!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x7c749b9f3259157%3A0xc8ca672d1f153fdf!2sDonkey+Head+Cervejaria!5e0!3m2!1spt-BR!2sbr!4v1539631173074"></iframe>
          </article>
          <article class="unit">
            <h3>Sede Maraponga</h3>
            <p class="addr">Av. Godofredo Maciel, 2127 · Maraponga<br>Fortaleza - CE, 60710-001</p>
            <div class="unit-actions">
              <a class="btn btn-whats btn-sm" href="https://wa.me/558594206246" target="_blank" rel="noopener">WhatsApp (85) 9420-6246</a>
              <a class="btn btn-ghost btn-sm" href="https://www.instagram.com/donkeyheadmaraponga" target="_blank" rel="noopener">Instagram</a>
              <a class="btn btn-ghost btn-sm" href="https://www.facebook.com/donkeyheadmaraponga" target="_blank" rel="noopener">Facebook</a>
            </div>
            <iframe class="mapa" title="Mapa da unidade Maraponga" loading="lazy" referrerpolicy="no-referrer-when-downgrade" src="https://www.google.com/maps/embed?pb=!1m14!1m8!1m3!1d11260.175553229092!2d-38.569613862983374!3d-3.79421723541069!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x0%3A0x22103327ec2d6e18!2sDonkey%20Head%20Cervejaria%20-%20Maraponga!5e0!3m2!1spt-BR!2sbr!4v1603997522189!5m2!1spt-BR!2sbr"></iframe>
          </article>
        </div>
        <div class="contato-extra">
          <a href="https://wa.me/5585992938600" target="_blank" rel="noopener"><strong>WhatsApp Comercial</strong> (85) 99293-8600</a>
          <a href="mailto:contato@donkeyheadcervejaria.com.br"><strong>E-mail</strong> contato@donkeyheadcervejaria.com.br</a>
        </div>
      </div>
    </section>
  </main>

  <footer class="site-footer">
    <div class="container legal">
      <p>Beba com moderação. Venda proibida para menores de 18 anos. Se beber, não dirija.</p>
      <p>Copyright © <?= date('Y') ?> Donkey Head Cervejaria LTDA</p>
    </div>
  </footer>

  <dialog class="modal" id="modal-cerveja" aria-label="Detalhes da cerveja">
    <button class="fechar" aria-label="Fechar">×</button>
    <div class="modal-corpo"></div>
  </dialog>

  <dialog class="lightbox" id="lightbox" aria-label="Foto ampliada">
    <button class="fechar" aria-label="Fechar">×</button>
    <button class="nav-foto ant" aria-label="Foto anterior">‹</button>
    <img alt="">
    <button class="nav-foto prox" aria-label="Próxima foto">›</button>
  </dialog>

  <script src="assets/js/main.js?v=2"></script>
</body>
</html>
