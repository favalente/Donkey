<?php
require __DIR__ . '/inc/dados.php';

$dados = dh_carregar();
$catalogo = array_values(array_filter($dados['cervejas'], fn($c) => !empty($c['ativa'])));
$taplists = [];
foreach (DH_UNIDADES as $u => $_) {
    $taplists[$u] = dh_taplist($dados, $u);
}
$temTaplist = (bool) array_filter($taplists);
// Agenda na página inicial: só o próximo show de cada unidade, num card que leva à agenda completa
$proximosShows = [];
foreach (dh_shows_publicos(dh_agenda(), date('Y-m-d')) as $sh) {
    $proximosShows[$sh['unidade']] = $proximosShows[$sh['unidade']] ?? $sh;
}
// A tap list pode ter cadastrado cervejas novas vindas do xMenu: recarrega o catálogo
$dados = dh_carregar();
$catalogo = array_values(array_filter($dados['cervejas'], fn($c) => !empty($c['ativa'])));

// A tap list fica dentro de "Nossas cervejas": cada cerveja sabe em que unidade está engatada e por quanto.
// Engatada sem cadastro no catálogo (ou escondida dele) entra também, com os dados do xMenu.
$engatadas = [];
$porId = array_column($catalogo, null, 'id');
foreach ($taplists as $u => $lista) {
    foreach ($lista as $c) {
        $id = $c['id'] ?: 'xm-' . dh_slug($c['nome']);
        $engatadas[$id][$u] = $c['precos'];
        if (!isset($porId[$id])) {
            $c['id'] = $id;
            $porId[$id] = $c;
            $catalogo[] = $c;
        }
    }
}
$contagem = array_map('count', $taplists);

$galeria = dh_galeria();
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

// No cartão vai só o preço do copo de 300 ml (os demais tamanhos ficam nos detalhes)
function preco_cartao(array $precos): string
{
    foreach ($precos as $p) {
        if (stripos($p['tam'], '300') === 0) {
            return $p['tam'] . ' ' . dh_preco($p['valor']);
        }
    }
    return $precos ? $precos[0]['tam'] . ' ' . dh_preco($precos[0]['valor']) : 'engatada';
}

function detalhe(array $c): string
{
    $paras = array_filter(array_map('trim', preg_split('/\n\s*\n/', (string) ($c['descricao'] ?? ''))));
    $html = '<img src="' . e($c['imagem']) . '" alt="Rótulo da ' . e($c['nome']) . '" loading="lazy">';
    $html .= '<div class="detalhe-texto"><h3>' . e($c['nome']) . '</h3>';
    $html .= '<p class="estilo">' . e($c['estilo']) . (!empty($c['pais']) ? ' · ' . e($c['pais']) : '') . '</p>';
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
  <meta name="description" content="Donkey Head Cervejaria: produção local de cerveja artesanal em Fortaleza, garantindo uma cerveja extremamente fresca. Unidades Aldeota e Maraponga.">
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
  <link rel="stylesheet" href="assets/css/style.css?v=22">
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "Brewery",
    "name": "Donkey Head Cervejaria",
    "url": "https://www.donkeyheadcervejaria.com.br/",
    "email": "contato@donkeyheadcervejaria.com.br",
    "sameAs": ["https://www.instagram.com/donkeyheadcervejaria/", "https://www.instagram.com/donkeyheadmaraponga/"],
    "location": [
      {"@type": "Place", "name": "Donkey Head Cervejaria Aldeota", "telephone": "+55-85-98212-4149", "address": {"@type": "PostalAddress", "streetAddress": "R. Norvinda Píres, 42", "addressLocality": "Fortaleza", "addressRegion": "CE", "postalCode": "60150-280", "addressCountry": "BR"}},
      {"@type": "Place", "name": "Donkey Head Cervejaria Maraponga", "telephone": "+55-85-98194-3774", "address": {"@type": "PostalAddress", "streetAddress": "Av. Godofredo Maciel, 2127", "addressLocality": "Fortaleza", "addressRegion": "CE", "postalCode": "60710-001", "addressCountry": "BR"}}
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
        <span>Donkey Head Cervejaria</span>
      </a>
      <button class="nav-toggle" aria-expanded="false" aria-controls="menu" aria-label="Abrir menu">
        <span></span><span></span><span></span>
      </button>
      <nav id="menu" class="menu">
        <a href="chope/">Chope Delivery</a>
        <a href="agenda/">Agenda</a>
        <a href="#historia">Nossa história</a>
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
          <p class="lead">Cerveja artesanal feita aqui, gastronomia de verdade e muito rock. Aqui, a vida acontece fora da tela.</p>
        </div>
      </div>
    </section>

    <section class="section" id="historia">
      <div class="container">
        <button type="button" class="historia-card" id="abrir-historia" aria-haspopup="dialog">
          <span class="historia-card-foto"><img src="assets/img/historia-comeco.jpg" alt="Panela de brassagem caseira com mosto e, ao lado, uma garrafa da StigmAPA servida no copo" width="1200" height="906" loading="lazy"></span>
          <span class="historia-card-texto">
            <span class="eyebrow">Nossa história</span>
            <span class="historia-card-titulo">Começou no quintal de casa.</span>
            <span class="historia-card-resumo">Três amigos apaixonados por cerveja resolvem fazê-la no quintal de casa. Deu tão certo que virou a Donkey Head Cervejaria.</span>
            <span class="btn btn-primary">Conheça a nossa história</span>
          </span>
        </button>
      </div>
    </section>

    <dialog class="historia-modal" id="historia-completa" aria-labelledby="historia-titulo">
      <button class="fechar" aria-label="Fechar">×</button>
      <article class="historia-artigo">
        <p class="eyebrow">Nossa história</p>
        <h2 id="historia-titulo">Começou no quintal de casa.</h2>
        <figure class="foto-jornal">
          <img src="assets/img/historia-comeco.jpg" alt="Panela de brassagem caseira com mosto e, ao lado, uma garrafa da StigmAPA servida no copo" width="1200" height="906" loading="lazy">
          <figcaption>Lá no começo: a brassagem no equipamento de casa e uma das primeiras StigmAPA.</figcaption>
        </figure>
        <div class="prose">
          <p>Três amigos apaixonados por cerveja resolvem fazê-la no quintal de casa. O resultado da primeira produção foi inesperado: uma cerveja excelente. Entregamos para os amigos e alguns especialistas e logo começaram os elogios, então surgiu a ideia: por que não comercializá-la?</p>
          <p>Decidimos então fazer um teste mercadológico como cervejaria cigana, fizemos nossa cerveja em uma estrutura de outra cervejaria, a Patt Lou em Recife, a qual agradecemos ao cervejeiro Luiz imensamente por fazer parte da nossa história.</p>
          <figure class="foto-jornal">
            <img src="assets/img/historia-pattlou.jpg" alt="Fermentador com o adesivo da Donkey Head Cervejaria, os sócios ao lado dele e a brassagem com saco de malte na Patt Lou" width="1200" height="915" loading="lazy">
            <figcaption>Nossa primeira brassagem na Patt Lou, em Recife, com o nosso fermentador.</figcaption>
          </figure>
          <p>Após a boa aceitação do mercado, surgiu mais uma ideia: e se nós produzíssemos localmente, proporcionando uma cerveja extremamente fresca, sem intervenção de mau armazenamento ou transporte?</p>
          <p>Após fazermos um estudo de mercado, percebemos a necessidade de um local que, além de boas cervejas, oferecesse também uma culinária à altura. Então surgiu o desejo de criar uma cervejaria com produção local aliada a uma boa cozinha.</p>
          <p>Nossa cerveja sai do fermentador direto para a câmara fria, o que retarda consideravelmente o seu envelhecimento, garantindo uma maior qualidade. Nosso chef de cozinha, Júnior Sales, possui formação pela Le Cordon Bleu, em Paris, considerada uma das mais renomadas escolas de gastronomia do mundo.</p>
          <p class="assinatura">Mais cerveja, menos arenga! 🍺</p>
        </div>
        <p class="centro"><button type="button" class="btn btn-ghost fechar-historia">Voltar para o site</button></p>
      </article>
    </dialog>

    <section class="section section-alt" id="cervejas">
      <div class="container">
        <div class="section-head">
          <h2>Nossas cervejas</h2>
          <p class="eyebrow subtitulo">Confira todos os rótulos que já produzimos</p>
          <p>Veja o que está engatado em cada unidade e toque no rótulo para ver os detalhes da cerveja.</p>
        </div>
        <?php if ($temTaplist): ?>
        <div class="filtros" role="group" aria-label="Filtrar cervejas">
          <button class="filtro ativo" data-filtro="todas" aria-pressed="true">Todas</button>
          <?php foreach (DH_UNIDADES as $u => $nomeU): ?>
            <button class="filtro" data-filtro="<?= $u ?>" aria-pressed="false">Engatadas na <?= e($nomeU) ?> <span class="contagem"><?= $contagem[$u] ?></span></button>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div class="beer-grid">
          <?php foreach ($catalogo as $c): $onde = $engatadas[$c['id']] ?? []; ?>
            <article class="beer" data-unidades="<?= e(implode(' ', array_keys($onde))) ?>">
              <button class="beer-btn" data-cerveja="<?= e($c['id']) ?>">
                <span class="beer-img">
                  <img src="<?= e($c['imagem']) ?>" alt="Rótulo da <?= e($c['nome']) ?>" loading="lazy" width="640" height="640">
                  <?php if ($onde): ?><span class="selo">Engatada</span><?php endif; ?>
                </span>
                <span class="beer-nome"><?= e($c['nome']) ?></span>
                <span class="beer-estilo"><?= e($c['estilo']) ?><?= $c['abv'] !== '' ? ' · ' . e(dh_num($c['abv'])) . '%' : '' ?></span>
                <?php foreach ($onde as $u => $precos): ?>
                  <span class="beer-preco" data-unidade="<?= $u ?>" hidden><?= e(preco_cartao($precos)) ?></span>
                <?php endforeach; ?>
              </button>
              <div class="detalhe" id="cerveja-<?= e($c['id']) ?>" hidden>
                <?= detalhe($c) ?>
                <?php foreach ($onde as $u => $precos): ?><p class="onde">Engatada agora na <?= e(DH_UNIDADES[$u]) ?><?= $precos ? ': ' . e(implode(' · ', array_map(fn($p) => $p['tam'] . ' ' . dh_preco($p['valor']), $precos))) : '' ?></p><?php endforeach; ?>
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
          <p class="eyebrow">Conheça a Donkey Head Cervejaria</p>
          <h2>Chega mais, a casa é tua!</h2>
          <p>Cerveja boa, comida caprichada, rock e muita história pra contar. Dá uma olhadinha, mas depois aparece pra conhecer de perto.</p>
        </div>
        <div class="galeria">
          <?php foreach ($galeria as $i => $f): ?>
            <a href="<?= e($f['foto']) ?>" class="foto<?= $i >= 12 ? ' extra' : '' ?>" data-indice="<?= $i ?>"<?= $f['legenda'] !== '' ? ' data-legenda="' . e($f['legenda']) . '"' : '' ?>>
              <img src="<?= e($f['mini']) ?>" alt="<?= e($f['legenda'] !== '' ? $f['legenda'] : 'Foto ' . ($i + 1) . ' da Donkey Head Cervejaria') ?>" loading="lazy">
              <?php if ($f['legenda'] !== ''): ?><span class="legenda"><?= e($f['legenda']) ?></span><?php endif; ?>
            </a>
          <?php endforeach; ?>
        </div>
        <?php if (count($galeria) > 12): ?><p class="centro"><button class="btn btn-ghost" id="mais-fotos">Ver todas as <?= count($galeria) ?> fotos</button></p><?php endif; ?>
      </div>
    </section>

    <section class="section section-feature cardapio-cta">
      <div class="container cta">
        <div>
          <p class="eyebrow">Dá uma olhadinha no nosso menu</p>
          <h2>Boa cerveja pede boa cozinha.</h2>
        </div>
        <a class="btn btn-primary" href="<?= e($cardapio) ?>" target="_blank" rel="noopener">Ver cardápio</a>
      </div>
    </section>

    <section class="section delivery-cta" id="chope-delivery">
      <div class="container">
        <a class="historia-card delivery-card" href="chope/">
          <span class="historia-card-foto"><img src="assets/img/chope-delivery.jpg" alt="Chope Delivery da Donkey Head Cervejaria: o mascote com um copo de chope, barris de Premium Lager e a picape da Donkey Head" width="892" height="1042" loading="lazy"></span>
          <span class="historia-card-texto">
            <span class="eyebrow">Chope Delivery</span>
            <span class="historia-card-titulo">Leve o nosso chope para o seu evento!</span>
            <span class="historia-card-resumo">Clique abaixo e confira os valores e rótulos disponíveis:</span>
            <span class="btn btn-primary">Ver Tabela de Preços</span>
          </span>
        </a>
      </div>
    </section>

    <?php if ($proximosShows): ?>
    <section class="section agenda-cta" id="agenda">
      <a class="container cta agenda-card" href="agenda/">
        <div>
          <p class="eyebrow">Agenda</p>
          <h2>Próximos shows</h2>
          <ul class="agenda-proximos">
            <?php foreach (DH_UNIDADES as $u => $nomeU): if (empty($proximosShows[$u])) continue; $sh = $proximosShows[$u]; ?>
              <li><span><?= e($nomeU) ?></span> <?= e(dh_data_curta($sh['data'])) ?> · <strong><?= e($sh['banda']) ?></strong></li>
            <?php endforeach; ?>
          </ul>
        </div>
        <span class="btn btn-ghost">Ver agenda completa</span>
      </a>
    </section>
    <?php endif; ?>


    <section class="section" id="contato">
      <div class="container">
        <div class="section-head">
          <p class="eyebrow">Fale com a gente</p>
          <h2>Bora se encontrar?</h2>
          <p>Quer tirar uma dúvida, mandar uma ideia, fazer uma parceria ou até puxar nossa orelha? Pode chamar. A gente gosta de uma boa conversa!</p>
        </div>
        <div class="units">
          <article class="unit">
            <h3>Sede Aldeota</h3>
            <p class="addr">R. Norvinda Píres, 42 · Aldeota<br>Fortaleza - CE, 60150-280</p>
            <div class="unit-actions">
              <a class="btn btn-whats btn-sm" href="https://wa.me/5585982124149" target="_blank" rel="noopener">WhatsApp (85) 98212-4149</a>
              <a class="btn btn-ghost btn-sm btn-insta" href="https://www.instagram.com/donkeyheadcervejaria/" target="_blank" rel="noopener" aria-label="Instagram @donkeyheadcervejaria"><svg class="ico" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="M12 2.2c3.2 0 3.6 0 4.8.1 1.2.1 1.8.2 2.2.4.6.2 1 .5 1.4.9.4.4.7.8.9 1.4.2.4.4 1 .4 2.2.1 1.3.1 1.6.1 4.8s0 3.6-.1 4.8c-.1 1.2-.2 1.8-.4 2.2-.2.6-.5 1-.9 1.4-.4.4-.8.7-1.4.9-.4.2-1 .4-2.2.4-1.3.1-1.6.1-4.8.1s-3.6 0-4.8-.1c-1.2-.1-1.8-.2-2.2-.4-.6-.2-1-.5-1.4-.9-.4-.4-.7-.8-.9-1.4-.2-.4-.4-1-.4-2.2C2.2 15.6 2.2 15.2 2.2 12s0-3.6.1-4.8c.1-1.2.2-1.8.4-2.2.2-.6.5-1 .9-1.4.4-.4.8-.7 1.4-.9.4-.2 1-.4 2.2-.4C8.4 2.2 8.8 2.2 12 2.2Zm0 4.7a5.1 5.1 0 1 0 0 10.2 5.1 5.1 0 0 0 0-10.2Zm0 8.4a3.3 3.3 0 1 1 0-6.6 3.3 3.3 0 0 1 0 6.6Zm5.3-9.8a1.2 1.2 0 1 0 0 2.4 1.2 1.2 0 0 0 0-2.4Z"/></svg>@donkeyheadcervejaria</a>
            </div>
            <iframe class="mapa" title="Mapa da unidade Aldeota" loading="lazy" referrerpolicy="no-referrer-when-downgrade" src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3981.335261517803!2d-38.50336088524095!3d-3.7369241972812173!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x7c749b9f3259157%3A0xc8ca672d1f153fdf!2sDonkey+Head+Cervejaria!5e0!3m2!1spt-BR!2sbr!4v1539631173074"></iframe>
          </article>
          <article class="unit">
            <h3>Sede Maraponga</h3>
            <p class="addr">Av. Godofredo Maciel, 2127 · Maraponga<br>Fortaleza - CE, 60710-001</p>
            <div class="unit-actions">
              <a class="btn btn-whats btn-sm" href="https://wa.me/5585981943774" target="_blank" rel="noopener">WhatsApp (85) 98194-3774</a>
              <a class="btn btn-ghost btn-sm btn-insta" href="https://www.instagram.com/donkeyheadmaraponga" target="_blank" rel="noopener" aria-label="Instagram @donkeyheadmaraponga"><svg class="ico" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="M12 2.2c3.2 0 3.6 0 4.8.1 1.2.1 1.8.2 2.2.4.6.2 1 .5 1.4.9.4.4.7.8.9 1.4.2.4.4 1 .4 2.2.1 1.3.1 1.6.1 4.8s0 3.6-.1 4.8c-.1 1.2-.2 1.8-.4 2.2-.2.6-.5 1-.9 1.4-.4.4-.8.7-1.4.9-.4.2-1 .4-2.2.4-1.3.1-1.6.1-4.8.1s-3.6 0-4.8-.1c-1.2-.1-1.8-.2-2.2-.4-.6-.2-1-.5-1.4-.9-.4-.4-.7-.8-.9-1.4-.2-.4-.4-1-.4-2.2C2.2 15.6 2.2 15.2 2.2 12s0-3.6.1-4.8c.1-1.2.2-1.8.4-2.2.2-.6.5-1 .9-1.4.4-.4.8-.7 1.4-.9.4-.2 1-.4 2.2-.4C8.4 2.2 8.8 2.2 12 2.2Zm0 4.7a5.1 5.1 0 1 0 0 10.2 5.1 5.1 0 0 0 0-10.2Zm0 8.4a3.3 3.3 0 1 1 0-6.6 3.3 3.3 0 0 1 0 6.6Zm5.3-9.8a1.2 1.2 0 1 0 0 2.4 1.2 1.2 0 0 0 0-2.4Z"/></svg>@donkeyheadmaraponga</a>
            </div>
            <iframe class="mapa" title="Mapa da unidade Maraponga" loading="lazy" referrerpolicy="no-referrer-when-downgrade" src="https://www.google.com/maps/embed?pb=!1m14!1m8!1m3!1d11260.175553229092!2d-38.569613862983374!3d-3.79421723541069!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x0%3A0x22103327ec2d6e18!2sDonkey%20Head%20Cervejaria%20-%20Maraponga!5e0!3m2!1spt-BR!2sbr!4v1603997522189!5m2!1spt-BR!2sbr"></iframe>
          </article>
        </div>
        <div class="contato-extra">
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
    <figure><img alt=""><figcaption></figcaption></figure>
    <button class="nav-foto prox" aria-label="Próxima foto">›</button>
  </dialog>

  <script src="assets/js/main.js?v=6"></script>
</body>
</html>
