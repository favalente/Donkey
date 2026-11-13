<?php
// Calendário das reservas para a equipe (só ver): www.donkeyheadcervejaria.com.br/reservas/?t=...
// O link tem um código secreto, criado e trocado no painel (Reservas → Link para a equipe).
require __DIR__ . '/../inc/dados.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
$r = dh_reservas();
$token = (string) ($_GET['t'] ?? '');
if ($r['config']['token'] === '' || !preg_match('/^[a-f0-9]{32}$/', $token) || !hash_equals($r['config']['token'], $token)) {
    http_response_code(404);
    exit('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><p style="font:18px system-ui;padding:24px">Link inválido ou trocado. Peça o link novo para a Donkey Head Cervejaria.</p>');
}
dh_reservas_sincronizar();
$r = dh_reservas();

$mes = (string) ($_GET['mes'] ?? '');
$mes = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes) ? $mes : date('Y-m');
$unidade = isset(DH_UNIDADES[$_GET['unidade'] ?? '']) ? $_GET['unidade'] : 'aldeota';
$ini = $mes . '-01';
$fim = date('Y-m-t', strtotime($ini));
$hoje = date('Y-m-d');
$porDia = dh_reservas_por_dia($r, $mes, $unidade);
$link = fn($m, $u) => '?t=' . $token . '&mes=' . $m . '&unidade=' . $u;
$anterior = date('Y-m', strtotime($ini . ' -1 month'));
$proximo = date('Y-m', strtotime($ini . ' +1 month'));
$nomeMes = DH_MESES[(int) substr($mes, 5, 2)] . ' ' . substr($mes, 0, 4);
$proximas = array_values(array_filter($r['reservas'], fn($x) => $x['unidade'] === $unidade && $x['data'] >= $hoje && $x['data'] <= date('Y-m-d', strtotime('+7 days'))));
usort($proximas, fn($a, $b) => strcmp($a['data'] . $a['hora'], $b['data'] . $b['hora']));
$info = fn($x) => dh_reserva_info($x);
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Reservas · Donkey Head Cervejaria</title>
  <link rel="icon" href="../favicon.ico">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/style.css?v=31">
  <script src="../assets/js/arrastar-mes.js?v=2"></script>
  <style>
    .reservas-lista { list-style: none; margin: 0 0 24px; padding: 0; display: grid; gap: 8px; }
    .reservas-lista .cal-linha { display: flex; }
    .rd-texto { white-space: pre-line; color: var(--muted); padding: 0 20px 8px; }
    .rd-topo { padding: 20px 20px 4px; }
    .rd-topo small { color: var(--muted); display: block; }
    .rd-topo h3 { margin: 6px 0; font-size: 1.6rem; }
    .rd-pessoas { padding: 0 20px; font-weight: 700; color: var(--amber); }
    .cal-modal .fechar-baixo { margin: 8px 20px 20px; }
  </style>
</head>
<body>
  <header class="site-header">
    <div class="container nav">
      <span class="brand"><img src="../assets/img/logo-donkey-head-mini.png" alt="" width="34" height="48"><span>Donkey Head Cervejaria</span></span>
    </div>
  </header>
  <main class="section">
    <div class="container">
      <div class="section-head">
        <p class="eyebrow">Equipe</p>
        <h1 class="titulo-pagina">Reservas</h1>
        <p>Calendário das reservas, atualizado do Google Agenda. Toque numa reserva para ver os detalhes.</p>
      </div>
      <nav class="sede-escolha" aria-label="Escolha a casa">
        <?php foreach (DH_UNIDADES as $u => $nomeU): $ativa = $unidade === $u; ?>
          <a class="sede-opcao cal-u-<?= $u ?><?= $ativa ? ' ativa' : '' ?>" href="<?= e($link($mes, $u)) ?>"><span class="sede-nome"><?= $ativa ? '✓ ' : '' ?><?= e($nomeU) ?></span></a>
        <?php endforeach; ?>
      </nav>
      <div class="agenda-mes cal-mes-perto">
        <a href="<?= e($link($anterior, $unidade)) ?>" aria-label="Mês anterior" id="mes-anterior">‹</a>
        <strong><?= e($nomeMes) ?></strong>
        <a href="<?= e($link($proximo, $unidade)) ?>" aria-label="Próximo mês" id="mes-proximo">›</a>
      </div>
      <div class="calendario" role="grid" aria-label="Reservas de <?= e($nomeMes) ?>">
        <?php foreach (['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'] as $d): ?><div class="cal-semana" role="columnheader"><?= $d ?></div><?php endforeach; ?>
        <?php for ($i = 0; $i < (int) date('w', strtotime($ini)); $i++): ?><div class="cal-vazio"></div><?php endfor; ?>
        <?php for ($t = strtotime($ini); $t <= strtotime($fim); $t = strtotime('+1 day', $t)): $dia = date('Y-m-d', $t); ?>
          <div class="cal-dia<?= $dia < $hoje ? ' passado' : '' ?><?= $dia === $hoje ? ' hoje' : '' ?>" role="gridcell">
            <span class="cal-num"><?= date('j', $t) ?></span>
            <?php foreach ($porDia[$dia] ?? [] as $x): ?>
              <button type="button" class="cal-abrir cal-show cal-u-<?= e($unidade) ?>" data-info="<?= e(json_encode($info($x), JSON_UNESCAPED_UNICODE)) ?>"><?= e(dh_reserva_rotulo($x)) ?></button>
            <?php endforeach; ?>
          </div>
        <?php endfor; ?>
        <?php for ($i = (int) date('w', strtotime($fim)); $i < 6; $i++): ?><div class="cal-vazio"></div><?php endfor; ?>
      </div>
      <p class="note cal-dica">Arraste o calendário para o lado para trocar de mês.</p>

      <h2 class="cal-lista-titulo">Próximos 7 dias · <?= e(DH_UNIDADES[$unidade]) ?></h2>
      <?php if (!$proximas): ?><p class="agenda-vazia">Nenhuma reserva nos próximos 7 dias.</p><?php endif; ?>
      <ul class="reservas-lista">
        <?php foreach ($proximas as $x): $t = strtotime($x['data']); ?>
          <li><button class="cal-abrir cal-linha cal-u-<?= e($unidade) ?>" data-info="<?= e(json_encode($info($x), JSON_UNESCAPED_UNICODE)) ?>">
            <span class="cal-linha-data"><strong><?= date('d', $t) ?></strong><?= e(mb_substr(DH_DIAS_SEMANA[(int) date('w', $t)], 0, 3)) ?></span>
            <span class="cal-linha-info"><strong><?= e(dh_reserva_rotulo($x)) ?></strong></span>
            <span class="cal-linha-onde"><small><?= $x['hora'] !== '' ? e(str_replace(':', 'h', $x['hora'])) : 'dia todo' ?></small></span>
          </button></li>
        <?php endforeach; ?>
      </ul>
      <p class="note">Última leitura do Google Agenda: <?= $r['atualizado_em'] ? e(date('d/m H:i', (int) $r['atualizado_em'])) : '—' ?>.</p>
    </div>
  </main>

  <dialog class="cal-modal" id="reserva-modal" aria-label="Reserva">
    <div class="rd-topo"><small class="rd-quando"></small><h3 class="rd-titulo"></h3></div>
    <p class="rd-pessoas"></p>
    <p class="rd-texto"></p>
    <button type="button" class="btn btn-ghost fechar-baixo">Fechar</button>
  </dialog>
  <script>
    (function () {
      var d = document.getElementById('reserva-modal');
      document.querySelectorAll('[data-info]').forEach(function (b) {
        b.addEventListener('click', function () {
          var i = JSON.parse(b.dataset.info);
          d.querySelector('.rd-quando').textContent = i.quando + (i.casa ? ' · ' + i.casa : '');
          d.querySelector('.rd-titulo').textContent = i.titulo;
          d.querySelector('.rd-pessoas').textContent = i.pessoas;
          d.querySelector('.rd-texto').textContent = i.texto;
          d.showModal();
        });
      });
      d.querySelector('.fechar-baixo').addEventListener('click', function () { d.close(); });
      d.addEventListener('click', function (e) { if (e.target === d) d.close(); });
      if (window.dhArrastarMes) dhArrastarMes(document.querySelector('.calendario'), document.getElementById('mes-anterior'), document.getElementById('mes-proximo'));
    })();
  </script>
</body>
</html>
