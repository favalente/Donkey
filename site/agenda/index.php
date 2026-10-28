<?php
// Programação de shows do mês, para o público: www.donkeyheadcervejaria.com.br/agenda/
require __DIR__ . '/../inc/dados.php';

$a = dh_agenda();
$mes = (string) ($_GET['mes'] ?? '');
$mes = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes) ? $mes : date('Y-m');
$unidade = isset(DH_UNIDADES[$_GET['unidade'] ?? '']) ? $_GET['unidade'] : null;
$ini = $mes . '-01';
$fim = date('Y-m-t', strtotime($ini));
// No mês atual, mostra a partir de hoje
$de = $mes === date('Y-m') ? date('Y-m-d') : $ini;
$shows = dh_shows_publicos($a, $de, $fim, $unidade);
$anterior = date('Y-m', strtotime($ini . ' -1 month'));
$proximo = date('Y-m', strtotime($ini . ' +1 month'));
$nomeMes = DH_MESES[(int) substr($mes, 5, 2)] . ' ' . substr($mes, 0, 4);
$link = fn($m, $u) => '?mes=' . $m . ($u ? '&unidade=' . $u : '');
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Agenda de shows · Donkey Head Cervejaria</title>
  <meta name="description" content="Programação de música ao vivo da Donkey Head Cervejaria em Fortaleza: unidades Aldeota e Maraponga.">
  <meta name="theme-color" content="#16110d">
  <link rel="canonical" href="https://www.donkeyheadcervejaria.com.br/agenda/">
  <link rel="icon" href="../favicon.ico">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/style.css?v=8">
  <script type="application/ld+json">
  <?= json_encode(array_map(fn($s) => [
      '@context' => 'https://schema.org',
      '@type' => 'MusicEvent',
      'name' => $s['banda'] . ($s['tributo'] !== '' ? ' · ' . $s['tributo'] : ''),
      'startDate' => $s['data'] . 'T' . $s['hora'] . ':00-03:00',
      'image' => 'https://www.donkeyheadcervejaria.com.br/' . $s['foto'],
      'performer' => ['@type' => 'MusicGroup', 'name' => $s['banda']],
      'location' => ['@type' => 'Place', 'name' => 'Donkey Head ' . DH_UNIDADES[$s['unidade']],
          'address' => $s['unidade'] === 'maraponga' ? 'Av. Godofredo Maciel, 2127, Maraponga, Fortaleza - CE' : 'R. Norvinda Píres, 42, Aldeota, Fortaleza - CE'],
  ], $shows), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
  </script>
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
        <p class="eyebrow">Agenda</p>
        <h1 class="titulo-pagina">Som ao vivo na Donkey</h1>
        <p>Programação de shows nas unidades Aldeota e Maraponga. Sujeita a alterações: confira também o nosso Instagram.</p>
      </div>
      <div class="agenda-filtros">
        <div class="agenda-mes">
          <a href="<?= e($link($anterior, $unidade)) ?>" aria-label="Mês anterior">‹</a>
          <strong><?= e($nomeMes) ?></strong>
          <a href="<?= e($link($proximo, $unidade)) ?>" aria-label="Próximo mês">›</a>
        </div>
        <div class="filtros" role="group" aria-label="Unidade">
          <a class="filtro<?= !$unidade ? ' ativo' : '' ?>" href="<?= e($link($mes, null)) ?>">Todas</a>
          <?php foreach (DH_UNIDADES as $u => $nomeU): ?>
            <a class="filtro<?= $unidade === $u ? ' ativo' : '' ?>" href="<?= e($link($mes, $u)) ?>"><?= e($nomeU) ?></a>
          <?php endforeach; ?>
        </div>
      </div>
      <?php if ($shows): ?>
        <div class="shows-grid">
          <?php foreach ($shows as $s) echo dh_cartao_show($s, '../'); ?>
        </div>
      <?php else: ?>
        <p class="agenda-vazia">A programação de <?= e($nomeMes) ?> ainda não foi divulgada. Volte em breve!</p>
      <?php endif; ?>
      <p class="note">Quer a agenda no seu celular? <a href="ics.php">Adicione ao seu calendário</a>.</p>
    </div>
  </main>

  <footer class="site-footer">
    <div class="container legal">
      <p>Beba com moderação. Venda proibida para menores de 18 anos. Se beber, não dirija.</p>
      <p>Copyright © <?= date('Y') ?> Donkey Head Cervejaria LTDA</p>
    </div>
  </footer>
</body>
</html>
