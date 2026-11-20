<?php
// Brassagem → 📅 Programação: sugestão do que brassar (administrador marca) e a programação da semana
// (a equipe da cervejaria vê só o que foi marcado).

function prog_admin(): bool
{
    global $usuario;
    return ($usuario['papel'] ?? '') === 'admin';
}

function prog_semana_param(): string
{
    $s = (string) ($_GET['semana'] ?? $_POST['semana'] ?? '');
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? dh_semana_inicio($s) : dh_semana_inicio(date('Y-m-d'));
}

function programacao_acao(string $acao): bool
{
    global $usuario;
    switch ($acao) {
        case 'brassagem-programacao':
            exigir('brassagem');
            dh_prog_migracoes();
            return true;

        case 'programacao-salvar':
            // Marca as brassagens da semana (o que veio marcado nas sugestões e o que foi acrescentado)
            exigir_post();
            exigir('brassagem');
            if (!prog_admin()) {
                throw new RuntimeException('Só o administrador monta a programação.');
            }
            $b = dh_brassagem();
            $semana = prog_semana_param();
            $grupos = dh_prog_grupos($b);
            $lista = $b['config']['programacao']['semanas'][$semana] ?? [];
            foreach ((array) ($_POST['itens'] ?? []) as $k => $it) {
                if (empty($it['marcar']) || !isset($grupos[$k])) {
                    continue;
                }
                $dia = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($it['dia'] ?? '')) ? $it['dia'] : $semana;
                $rc0 = $grupos[$k]['receitas'][0] ?? null;
                $lista[] = ['id' => dh_id_novo('p'), 'grupo' => $k, 'nome' => $k === 'newengland' && $rc0 ? $rc0['nome'] : $grupos[$k]['nome'], 'receita' => $rc0['id'] ?? '',
                    'dia' => $dia, 'tanque' => (string) ($it['tanque'] ?? ''), 'obs' => mb_strimwidth(trim((string) ($it['obs'] ?? '')), 0, 120), 'por' => $usuario['nome']];
            }
            $b['config']['programacao']['semanas'][$semana] = $lista;
            dh_brassagem_salvar($b);
            ir('./?acao=brassagem-programacao&semana=' . $semana . '#programadas', 'Programação salva.');

        case 'programacao-tirar':
            exigir_post();
            exigir('brassagem');
            if (!prog_admin()) {
                throw new RuntimeException('Só o administrador muda a programação.');
            }
            $b = dh_brassagem();
            $semana = prog_semana_param();
            $id = (string) ($_POST['id'] ?? '');
            $b['config']['programacao']['semanas'][$semana] = array_values(array_filter($b['config']['programacao']['semanas'][$semana] ?? [], fn($x) => $x['id'] !== $id));
            dh_brassagem_salvar($b);
            ir('./?acao=brassagem-programacao&semana=' . $semana . '#programadas', 'Tirado da programação.');

        case 'programacao-feita':
            // ✓ Feita: sai do "Programado" e vai para "Brassadas" (a equipe também marca). Desfazer volta.
            exigir_post();
            exigir('brassagem');
            $b = dh_brassagem();
            $semana = prog_semana_param();
            $id = (string) ($_POST['id'] ?? '');
            $desfazer = !empty($_POST['desfazer']);
            $nome = '';
            foreach ($b['config']['programacao']['semanas'][$semana] ?? [] as $j => $x) {
                if ($x['id'] === $id) {
                    $nome = $x['nome'];
                    if ($desfazer) {
                        unset($b['config']['programacao']['semanas'][$semana][$j]['feita'], $b['config']['programacao']['semanas'][$semana][$j]['feita_em'], $b['config']['programacao']['semanas'][$semana][$j]['feita_por']);
                    } else {
                        $b['config']['programacao']['semanas'][$semana][$j] += ['feita' => true, 'feita_em' => date('Y-m-d H:i:s'), 'feita_por' => $usuario['nome']];
                    }
                }
            }
            if ($nome === '') {
                throw new RuntimeException('Item da programação não encontrado.');
            }
            dh_brassagem_salvar($b);
            ir('./?acao=brassagem-programacao&semana=' . $semana . ($desfazer ? '#programadas' : '#brassadas'), $desfazer ? $nome . ' voltou para o Programado.' : '✅ ' . $nome . ' marcada como brassada.');

        case 'programacao-config':
            // Categoria e consumo por semana de cada cerveja
            exigir_post();
            exigir('brassagem');
            if (!prog_admin()) {
                throw new RuntimeException('Só o administrador muda essas informações.');
            }
            $b = dh_brassagem();
            foreach ((array) ($_POST['grupos'] ?? []) as $k => $g) {
                $b['config']['programacao']['grupos'][(string) $k] = [
                    'categoria' => isset(DH_PROG_CATEGORIAS[$g['categoria'] ?? '']) ? $g['categoria'] : 'sazonal',
                    'consumo' => brassagem_num($g['consumo'] ?? ''),
                    'dobro' => !empty($g['dobro']),
                ];
            }
            dh_brassagem_salvar($b);
            ir('./?acao=brassagem-programacao#cervejas', 'Cervejas da programação salvas.');
    }
    return false;
}

// Card da programação da semana (topo da Brassagem e tela da programação)
function prog_lista_semana(array $b, string $semana, bool $podeTirar): void
{
    global $csrf;
    $todas = dh_prog_semana($b, $semana);
    $lista = array_filter($todas, fn($x) => empty($x['feita']));
    $feitas = array_filter($todas, fn($x) => !empty($x['feita']));
    ?>
      <div class="abas">
        <a href="#programadas" class="ativo" data-prog-aba="programadas">📅 Programado <small><?= count($lista) ?></small></a>
        <a href="#brassadas" data-prog-aba="brassadas">✅ Brassadas <small><?= count($feitas) ?></small></a>
      </div>
      <div data-prog-painel="programadas">
    <?php
    if (!$lista) {
        echo '<p class="dica">' . ($feitas ? 'Tudo o que foi programado nesta semana já foi brassado.' : 'Nada programado para esta semana.') . '</p>';
    } else {
        prog_itens($b, $semana, $lista, $podeTirar, false);
    }
    ?>
      </div>
      <div data-prog-painel="brassadas" hidden>
    <?php
    if (!$feitas) {
        echo '<p class="dica">Nenhuma brassagem marcada como feita nesta semana. Toque em <b>✓ Feita</b> no Programado.</p>';
    } else {
        prog_itens($b, $semana, $feitas, $podeTirar, true);
    }
    ?>
      </div>
      <script>
      (function () {
        var abas = document.querySelectorAll('[data-prog-aba]');
        function abrir(q) {
          abas.forEach(function (a) { a.classList.toggle('ativo', a.dataset.progAba === q); });
          document.querySelectorAll('[data-prog-painel]').forEach(function (p) { p.hidden = p.dataset.progPainel !== q; });
        }
        abas.forEach(function (a) { a.addEventListener('click', function (e) { e.preventDefault(); abrir(a.dataset.progAba); history.replaceState(null, '', '#' + a.dataset.progAba); }); });
        if (location.hash === '#brassadas') abrir('brassadas');
      })();
      </script>
    <?php
}

function prog_itens(array $b, string $semana, array $lista, bool $podeTirar, bool $feitas): void
{
    global $csrf;
    ?>
      <ul class="prog-lista">
        <?php foreach ($lista as $x): $t = dh_tanque($b, $x['tanque']); ?>
          <li>
            <span class="lr-data"><strong><?= date('d', strtotime($x['dia'])) ?></strong><?= e(mb_substr(DH_DIAS_SEMANA[(int) date('w', strtotime($x['dia']))], 0, 3)) ?></span>
            <span class="lr-info"><strong><?= e($x['nome']) ?></strong><small><?= $t ? e($t['nome']) : 'Tanque a definir' ?><?= $x['obs'] !== '' ? ' · ' . e($x['obs']) : '' ?><?= $feitas ? ' · ✅ ' . e(date('d/m H:i', strtotime($x['feita_em']))) . ' · ' . e($x['feita_por']) : '' ?></small>
              <?php if ($x['receita'] !== ''): ?><a href="./?acao=receita&amp;id=<?= e($x['receita']) ?>">📖 Ver receita</a><?php endif; ?></span>
            <form method="post" action="./?acao=programacao-feita"><?= $csrf ?><input type="hidden" name="semana" value="<?= e($semana) ?>"><input type="hidden" name="id" value="<?= e($x['id']) ?>"><?php if ($feitas): ?><input type="hidden" name="desfazer" value="1"><button class="btn-acao" title="Voltar para o Programado">↩ Desfazer</button><?php else: ?><button class="btn-feita">✓ Feita</button><?php endif; ?></form>
            <?php if ($podeTirar && !$feitas): ?><form method="post" action="./?acao=programacao-tirar" onsubmit="return confirm('Tirar <?= e(addslashes($x['nome'])) ?> da programação?');"><?= $csrf ?><input type="hidden" name="semana" value="<?= e($semana) ?>"><input type="hidden" name="id" value="<?= e($x['id']) ?>"><button class="ct-tirar" aria-label="Tirar">×</button></form><?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php
}

function programacao_tela(string $tela): bool
{
    if ($tela !== 'brassagem-programacao') {
        return false;
    }
    global $csrf;
    $b = dh_brassagem();
    $semana = prog_semana_param();
    $admin = prog_admin();
    $dias = array_map(fn($n) => date('Y-m-d', strtotime($semana . ' +' . $n . ' days')), range(0, 5));
    ?>
      <h1>Brassagem</h1>
      <?php brassagem_abas('brassagem-programacao'); ?>
      <div class="semana-nav">
        <a class="semana-seta" href="./?acao=brassagem-programacao&amp;semana=<?= date('Y-m-d', strtotime($semana . ' -7 days')) ?>" aria-label="Semana anterior">‹</a>
        <div class="semana-centro"><small><?= $semana === dh_semana_inicio(date('Y-m-d')) ? 'Esta semana' : 'Semana' ?></small><strong><?= date('d/m', strtotime($semana)) ?> a <?= date('d/m', strtotime($semana . ' +6 days')) ?></strong></div>
        <a class="semana-seta" href="./?acao=brassagem-programacao&amp;semana=<?= date('Y-m-d', strtotime($semana . ' +7 days')) ?>" aria-label="Próxima semana">›</a>
      </div>

      <h2 id="programadas">Programação da semana</h2><span id="brassadas"></span>
      <?php prog_lista_semana($b, $semana, $admin); ?>

      <?php if ($admin):
          $s = dh_prog_sugestoes($b);
          $livres = array_values(array_filter($b['tanques'], fn($t) => ($t['ativo'] ?? true) && !dh_tanque_lote($b, $t['id'])));
          $jaProg = array_column(dh_prog_semana($b, $semana), 'grupo');
      ?>
        <?php
          // Lotes nos tanques que não batem com nenhuma receita (não entram na conta)
          $gruposT = dh_prog_grupos($b);
          $semReceita = [];
          foreach ($b['lotes'] as $lt) {
              if ($lt['status'] !== 'finalizado' && !dh_prog_achar_grupo($lt['cerveja'], $gruposT, (string) ($lt['cerveja_id'] ?? ''))) {
                  $semReceita[] = $lt['cerveja'] . ' (' . (dh_tanque($b, $lt['tanque'])['nome'] ?? 'tanque ?') . ')';
              }
          }
        ?>
        <h2>Sugestões</h2>
        <?php if ($semReceita): ?><p class="aviso">Nos tanques, sem receita reconhecida (não entram na conta): <?= e(implode(', ', $semReceita)) ?>. Confira se a receita existe em Receitas com um nome parecido.</p><?php endif; ?>
        <p class="dica"><?= $s['livres'] ?> tanque<?= $s['livres'] !== 1 ? 's' : '' ?> livre<?= $s['livres'] !== 1 ? 's' : '' ?> agora. Conta o estoque da última contagem (fábrica e casas, em barris de 50 L; mexido conta a parte que sobra), o que está nos tanques e o consumo por semana de cada cerveja. Sem dry hop o lote leva <?= DH_PROG_DIAS_SEM_DRYHOP ?> dias; com dry hop, <?= DH_PROG_DIAS_COM_DRYHOP ?>. Marque o que vai brassar e toque em <b>Programar</b>; a equipe vê só o que for programado.</p>
        <form method="post" action="./?acao=programacao-salvar" class="prog-form">
          <?= $csrf ?><input type="hidden" name="semana" value="<?= e($semana) ?>">
          <?php $mostrou = false; foreach ($s['linhas'] as $l): if (in_array($l['chave'], $jaProg, true)) continue; $destaque = $l['sugerir'] || !empty($l['espera']); if (!$destaque && $mostrou === false) { $mostrou = true; echo '<details class="prog-outras"><summary>Outras cervejas (sem urgência)</summary>'; } ?>
            <div class="prog-item<?= $l['sugerir'] ? ' sugerida' : '' ?><?= !empty($l['espera']) ? ' espera' : '' ?>">
              <label class="prog-marcar"><input type="checkbox" name="itens[<?= e($l['chave']) ?>][marcar]" value="1" <?= $l['sugerir'] ? 'checked' : '' ?>>
                <span><strong><?= e($l['nome']) ?></strong> <em class="tag cinza"><?= e(explode(' · ', DH_PROG_CATEGORIAS[$l['categoria']])[1] ?? DH_PROG_CATEGORIAS[$l['categoria']]) ?></em></span></label>
              <p class="prog-numeros">Estoque: <b><?= e(dh_num($l['estoque'])) ?></b> barris · Em tanque: <b><?= e(dh_num($l['em_tanque'])) ?></b><?php foreach ($l['tanque'] as $tq): ?> <small>(<?= e($tq['tanque']) ?>, pronto ~<?= e(date('d/m', strtotime($tq['pronto']))) ?>)</small><?php endforeach; ?><?= $l['consumo'] !== '' ? ' · Consumo: <b>' . e($l['consumo']) . '</b>/semana' : '' ?></p>
              <?php if ($l['motivo'] !== ''): ?><p class="prog-motivo"><?= e($l['motivo']) ?></p><?php endif; ?>
              <div class="prog-quando">
                <select name="itens[<?= e($l['chave']) ?>][dia]" aria-label="Dia"><?php foreach ($dias as $d): ?><option value="<?= $d ?>"><?= e(DH_DIAS_SEMANA[(int) date('w', strtotime($d))] . ' ' . date('d/m', strtotime($d))) ?></option><?php endforeach; ?></select>
                <select name="itens[<?= e($l['chave']) ?>][tanque]" aria-label="Tanque"><option value="">Tanque a definir</option><?php foreach ($livres as $t): ?><option value="<?= e($t['id']) ?>"><?= e($t['nome']) ?></option><?php endforeach; ?></select>
              </div>
            </div>
          <?php endforeach; if ($mostrou) echo '</details>'; ?>
          <div class="barra-salvar"><button class="btn primario largo">📅 Programar as marcadas</button></div>
        </form>

        <details class="config-reservas" id="cervejas">
          <summary>⚙ Cervejas da programação (categoria e consumo)</summary>
          <p class="dica">Consumo: barris de 50 L por semana (somando as casas e o chope delivery). "Em dobro": mantém o dobro de estoque (ex.: Premium Lager).</p>
          <form method="post" action="./?acao=programacao-config" class="prog-config">
            <?= $csrf ?>
            <?php foreach (dh_prog_grupos($b) as $k => $g): ?>
              <div class="prog-cfg-linha">
                <strong><?= e($g['nome']) ?></strong><small><?= $g['dryhop'] ? 'com dry hop · ' . DH_PROG_DIAS_COM_DRYHOP : 'sem dry hop · ' . DH_PROG_DIAS_SEM_DRYHOP ?> dias</small>
                <select name="grupos[<?= e($k) ?>][categoria]" aria-label="Categoria"><?php foreach (DH_PROG_CATEGORIAS as $ck => $cr): ?><option value="<?= $ck ?>" <?= $g['categoria'] === $ck ? 'selected' : '' ?>><?= e($cr) ?></option><?php endforeach; ?></select>
                <label>Barris/semana <input name="grupos[<?= e($k) ?>][consumo]" inputmode="decimal" value="<?= e(dh_num($g['consumo'])) ?>" placeholder="—"></label>
                <label class="check"><input type="checkbox" name="grupos[<?= e($k) ?>][dobro]" value="1" <?= $g['dobro'] ? 'checked' : '' ?>> Em dobro</label>
              </div>
            <?php endforeach; ?>
            <button class="btn primario">Salvar</button>
          </form>
        </details>
      <?php endif; ?>
    <?php
    return true;
}
