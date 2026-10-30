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
// O calendário mostra o mês inteiro; a lista embaixo, só o que ainda vai acontecer
$doMes = dh_shows_publicos($a, $ini, $fim, $unidade);
$shows = array_values(array_filter($doMes, fn($s) => $s['data'] >= $de));
$porDia = [];
foreach ($doMes as $s) {
    $porDia[$s['data']][] = $s;
}
$hoje = date('Y-m-d');
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
  <link rel="stylesheet" href="../assets/css/style.css?v=28">
  <script src="../assets/js/arrastar-mes.js?v=2"></script>
  <script type="application/ld+json">
  <?= json_encode(array_map(fn($s) => [
      '@context' => 'https://schema.org',
      '@type' => 'MusicEvent',
      'name' => $s['banda'] . ($s['tributo'] !== '' ? ' · ' . $s['tributo'] : ''),
      'startDate' => $s['data'] . 'T' . $s['hora'] . ':00-03:00',
      'image' => 'https://www.donkeyheadcervejaria.com.br/' . $s['foto'],
      'performer' => ['@type' => 'MusicGroup', 'name' => $s['banda']],
      'location' => ['@type' => 'Place', 'name' => 'Donkey Head Cervejaria ' . DH_UNIDADES[$s['unidade']],
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
        <h1 class="titulo-pagina">Som ao vivo na Donkey Head Cervejaria</h1>
        <p>Programação de shows nas unidades Aldeota e Maraponga. Sujeita a alterações: confira também o nosso Instagram.</p>
      </div>
      <div class="agenda-filtros">
        <div class="filtros" role="group" aria-label="Unidade">
          <a class="filtro<?= !$unidade ? ' ativo' : '' ?>" href="<?= e($link($mes, null)) ?>">Todas</a>
          <?php foreach (DH_UNIDADES as $u => $nomeU): ?>
            <a class="filtro<?= $unidade === $u ? ' ativo' : '' ?>" href="<?= e($link($mes, $u)) ?>"><?= e($nomeU) ?></a>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="cal-legenda">
        <?php foreach (DH_UNIDADES as $u => $nomeU): if ($unidade && $unidade !== $u) continue; ?><span class="cal-u-<?= $u ?>"><?= e($nomeU) ?></span><?php endforeach; ?>
      </div>
      <div class="agenda-mes cal-mes-perto">
        <a href="<?= e($link($anterior, $unidade)) ?>" aria-label="Mês anterior" id="mes-anterior">‹</a>
        <strong><?= e($nomeMes) ?></strong>
        <a href="<?= e($link($proximo, $unidade)) ?>" aria-label="Próximo mês" id="mes-proximo">›</a>
      </div>
      <div class="calendario" role="grid" aria-label="Shows de <?= e($nomeMes) ?>">
        <?php foreach (['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'] as $d): ?><div class="cal-semana" role="columnheader"><?= $d ?></div><?php endforeach; ?>
        <?php for ($i = 0; $i < (int) date('w', strtotime($ini)); $i++): ?><div class="cal-vazio"></div><?php endfor; ?>
        <?php for ($t = strtotime($ini); $t <= strtotime($fim); $t = strtotime('+1 day', $t)): $dia = date('Y-m-d', $t); $doDia = $porDia[$dia] ?? []; ?>
          <div class="cal-dia<?= $dia < $hoje ? ' passado' : '' ?><?= $dia === $hoje ? ' hoje' : '' ?>" role="gridcell">
            <span class="cal-num"><?= date('j', $t) ?></span>
            <?php if ($doDia): ?>
              <?php foreach ($doDia as $sh): ?>
                <button class="cal-abrir cal-show cal-u-<?= $sh['unidade'] ?>" data-show="<?= e($sh['id']) ?>" aria-label="<?= e($sh['banda'] . ', ' . dh_data_curta($dia) . ', ' . DH_UNIDADES[$sh['unidade']]) ?>"><?= e($sh['banda']) ?></button>
                <div class="cal-detalhe" id="show-<?= e($sh['id']) ?>" hidden><?= dh_cartao_show($sh, '../') ?></div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        <?php endfor; ?>
        <?php for ($i = (int) date('w', strtotime($fim)); $i < 6; $i++): ?><div class="cal-vazio"></div><?php endfor; ?>
      </div>
      <p class="note cal-dica">Toque no nome da banda para ver o tributo e o horário. Arraste o calendário para o lado para trocar de mês.</p>

      <h2 class="cal-lista-titulo"><?= $mes === date('Y-m') ? 'Próximos shows do mês' : 'Shows do mês' ?></h2>
      <?php if ($shows): ?>
        <div class="cal-sedes">
          <?php foreach (DH_UNIDADES as $u => $nomeU): if ($unidade && $unidade !== $u) continue; $daSede = array_values(array_filter($shows, fn($s) => $s['unidade'] === $u)); ?>
            <section class="cal-sede cal-u-<?= $u ?>">
              <h3 class="cal-sede-titulo"><?= e($nomeU) ?></h3>
              <?php if (!$daSede): ?>
                <p class="cal-sede-vazia">Nenhum show <?= $mes === date('Y-m') ? 'até o fim do mês' : 'neste mês' ?> por aqui.</p>
              <?php else: ?>
                <ul class="cal-lista">
                  <?php foreach ($daSede as $s): $t = strtotime($s['data']); ?>
                    <li>
                      <button class="cal-abrir cal-linha" data-show="<?= e($s['id']) ?>">
                        <span class="cal-linha-data"><strong><?= date('d', $t) ?></strong><?= e(mb_substr(DH_DIAS_SEMANA[(int) date('w', $t)], 0, 3)) ?></span>
                        <span class="cal-linha-info">
                          <strong><?= e($s['banda']) ?></strong>
                          <?php if ($s['tributo'] !== ''): ?><span><?= e($s['tributo']) ?></span><?php endif; ?>
                        </span>
                        <span class="cal-linha-onde"><small><?= e(str_replace(':', 'h', $s['hora'])) ?></small></span>
                      </button>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
            </section>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <p class="agenda-vazia"><?= $doMes ? 'Os shows deste mês já aconteceram. Veja o próximo mês!' : 'A programação de ' . e($nomeMes) . ' ainda não foi divulgada. Volte em breve!' ?></p>
      <?php endif; ?>
      <p class="note">Quer a agenda no seu celular? <a href="ics.php">Adicione ao seu calendário</a>.</p>
    </div>
  </main>

  <dialog class="cal-modal" id="cal-modal" aria-label="Shows do dia">
    <button class="fechar" aria-label="Fechar">×</button>
    <div class="cal-modal-corpo"></div>
  </dialog>
  <script>
    (function () {
      var modal = document.getElementById('cal-modal');
      var corpo = modal.querySelector('.cal-modal-corpo');
      document.querySelectorAll('.cal-abrir').forEach(function (b) {
        b.addEventListener('click', function () {
          // Abre só o show tocado (no calendário e na lista)
          corpo.innerHTML = document.getElementById('show-' + b.getAttribute('data-show')).innerHTML;
          modal.showModal();
        });
      });
      dhArrastarMes(document.querySelector('.calendario'), document.getElementById('mes-anterior'), document.getElementById('mes-proximo'));
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
