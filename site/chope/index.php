<?php
// Chope delivery: tabela de preços dos rótulos para evento. www.donkeyheadcervejaria.com.br/chope/
// Os valores vêm de Cervejas (campo "Valor do litro para evento") e das regras do pedido (frete, copos).
require __DIR__ . '/../inc/dados.php';

$cervejas = dh_cervejas_evento();
$whats = 'https://wa.me/5585982124149?text=' . rawurlencode("Olá! Vim pelo site e quero fazer um pedido de chope para o meu evento.");
$equipamentos = array_map(fn($eq) => $eq[0], DH_EQUIPAMENTOS);

// Ficha da cerveja (a mesma da página inicial), só com as informações da cerveja
function detalhe_delivery(array $c): string
{
    $paras = array_filter(array_map('trim', preg_split('/\n\s*\n/', (string) ($c['descricao'] ?? ''))));
    $ficha = '';
    foreach (['abv' => 'ABV', 'ibu' => 'IBU'] as $k => $rot) {
        if (($c[$k] ?? '') !== '') {
            $ficha .= '<div><dt>' . $rot . '</dt><dd>' . e(dh_num($c[$k])) . ($k === 'abv' ? '%' : '') . '</dd></div>';
        }
    }
    $h = '<img src="../' . e($c['imagem']) . '" alt="Rótulo da ' . e($c['nome']) . '" loading="lazy">';
    $h .= '<div class="detalhe-texto"><h3>' . e($c['nome']) . '</h3>';
    $h .= '<p class="estilo">' . e($c['estilo'] ?? '') . (!empty($c['pais']) ? ' · ' . e($c['pais']) : '') . '</p>';
    $h .= $ficha !== '' ? '<dl class="ficha">' . $ficha . '</dl>' : '';
    foreach ($paras as $p) {
        $h .= '<p>' . nl2br(e($p)) . '</p>';
    }
    return $h . '</div>';
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Chope delivery · Donkey Head Cervejaria</title>
  <meta name="description" content="Leve o chope da Donkey Head Cervejaria para o seu evento em Fortaleza: rótulos, preço do litro, chopeira em comodato, instalação e retirada.">
  <meta name="theme-color" content="#16110d">
  <link rel="canonical" href="https://www.donkeyheadcervejaria.com.br/chope/">
  <link rel="icon" href="../favicon.ico">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/style.css?v=22">
</head>
<body>
  <header class="site-header">
    <div class="container nav">
      <a class="brand" href="../" aria-label="Donkey Head Cervejaria, início">
        <img src="../assets/img/logo-donkey-head.png" alt="" width="34" height="48">
        <span>Donkey Head Cervejaria</span>
      </a>
      <a class="btn btn-ghost btn-sm" href="../">‹ Voltar ao site</a>
    </div>
  </header>

  <main class="section">
    <div class="container">
      <div class="section-head">
        <p class="eyebrow">Chope Delivery</p>
        <h1 class="titulo-pagina">Leve o nosso chope para o seu evento!</h1>
        <p>Chope artesanal da Donkey Head Cervejaria direto no seu evento, com chopeira em comodato, instalação e retirada.</p>
      </div>

      <h2 class="delivery-subtitulo">Tabela de preços</h2>
      <p class="delivery-nota">Rótulos engatados hoje nas nossas torneiras. A tabela muda conforme o que está engatado.</p>
      <?php if (!$cervejas): ?>
        <p class="delivery-aviso">A tabela está sendo atualizada. Chame a gente no WhatsApp que passamos os rótulos e os valores.</p>
      <?php else: ?>
        <div class="delivery-tabela">
          <?php foreach ($cervejas as $c): $l = (float) $c['preco_evento']; ?>
            <article class="delivery-rotulo">
              <button type="button" class="delivery-ver" data-cerveja="<?= e($c['id']) ?>" aria-label="Ver detalhes da <?= e($c['nome']) ?>">
                <?php if (!empty($c['imagem'])): ?><img src="../<?= e($c['imagem']) ?>" alt="Rótulo da <?= e($c['nome']) ?>" width="96" height="96" loading="lazy"><?php endif; ?>
                <span class="delivery-mais">Saiba mais</span>
              </button>
              <div class="detalhe" id="cerveja-<?= e($c['id']) ?>" hidden>
                <?= detalhe_delivery($c) ?>
              </div>
              <div class="delivery-info">
                <h3><button type="button" class="link-cerveja" data-cerveja="<?= e($c['id']) ?>"><?= e($c['nome']) ?></button></h3>
                <?php if ($c['estilo'] !== ''): ?><p class="delivery-estilo"><?= e($c['estilo']) ?><?= ($c['abv'] ?? '') !== '' ? ' · ' . e(dh_num($c['abv'])) . '%' : '' ?></p><?php endif; ?>
                <p class="delivery-litro"><strong><?= e(dh_moeda($l)) ?></strong> o litro</p>
                <p class="delivery-onde">Engatada na <?= e(implode(' e na ', array_map(fn($u) => DH_UNIDADES[$u], $c['unidades']))) ?></p>
                <p class="delivery-barris">Barril de 30 L: <strong><?= e(dh_moeda($l * 30)) ?></strong><br>Barril de 50 L: <strong><?= e(dh_moeda($l * 50)) ?></strong></p>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="delivery-extras">
        <section>
          <h2>Já incluso</h2>
          <ul>
            <?php foreach ($equipamentos as $eq): ?><li><?= e($eq) ?>, em comodato</li><?php endforeach; ?>
            <li>Instalação e retirada do equipamento</li>
          </ul>
        </section>
        <section>
          <h2>Frete</h2>
          <ul>
            <li>Fortaleza: <strong><?= e(dh_moeda(DH_FRETE_FORTALEZA)) ?></strong> (instalação e retirada)</li>
            <li>Outras cidades: a combinar</li>
          </ul>
        </section>
        <section>
          <h2>Copos personalizados</h2>
          <ul>
            <li>Pacote com <?= DH_COPOS['qtd'] ?> copos: <strong><?= e(dh_moeda(DH_COPOS['valor'])) ?></strong></li>
          </ul>
        </section>
      </div>

      <section class="delivery-passos">
        <h2>Como funciona</h2>
        <ol>
          <li>Fale com a gente no WhatsApp e diga a data, o local e quantos litros você precisa.</li>
          <li>Você recebe o link do pedido, escolhe os rótulos, anexa o comprovante do Pix e assina o contrato online.</li>
          <li>Levamos e instalamos a chopeira no dia combinado.</li>
          <li>Depois do evento, buscamos o equipamento (no máximo no dia seguinte).</li>
        </ol>
      </section>

      <p class="centro"><a class="btn btn-whats" href="<?= e($whats) ?>" target="_blank" rel="noopener">Fazer meu pedido pelo WhatsApp</a></p>
      <p class="delivery-legal">Valores sujeitos a alteração. Venda proibida para menores de 18 anos.</p>
    </div>
  </main>

  <dialog class="modal" id="modal-cerveja" aria-label="Detalhes da cerveja">
    <button class="fechar" aria-label="Fechar">×</button>
    <div class="modal-corpo"></div>
  </dialog>
  <script>
    // Tocar no rótulo ou no nome abre a ficha da cerveja
    (function () {
      var modal = document.getElementById('modal-cerveja'), corpo = modal.querySelector('.modal-corpo');
      document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-cerveja]');
        if (!b) return;
        var origem = document.getElementById('cerveja-' + b.getAttribute('data-cerveja'));
        if (!origem) return;
        corpo.innerHTML = origem.innerHTML;
        modal.showModal();
        modal.scrollTop = 0;
      });
      modal.addEventListener('click', function (e) {
        if (e.target === modal || e.target.classList.contains('fechar')) modal.close();
      });
    })();
  </script>

  <footer class="site-footer">
    <div class="container legal">
      <p>Beba com moderação. Venda proibida para menores de 18 anos. Se beber, não dirija.</p>
      <p>Copyright © <?= date('Y') ?> Donkey Head Cervejaria LTDA</p>
    </div>
  </footer>
</body>
</html>
