<?php
// Painel → Brassagem: tanques virtuais da fábrica (que atende as duas sedes) e o caderno de cada lote, no lugar do caderno de papel.
// Cabeçalho igual ao do caderno (cerveja, lote, levedura, data, tanque, PF/OG em Brix e litragem em cm)
// e o acompanhamento da fermentação e maturação, com Brix, pressão e temperatura opcionais. Ver inc/brassagem.php.

// Número digitado com vírgula ou ponto ("0,5", "1.5", "-1"); vazio se não for número
function brassagem_num($v): string
{
    $s = str_replace(',', '.', trim((string) $v));
    return is_numeric($s) ? rtrim(rtrim(number_format((float) $s, 3, '.', ''), '0'), '.') : '';
}

function brassagem_data($v): string
{
    $v = (string) $v;
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) ? $v : '';
}

function brassagem_acao(string $acao): bool
{
    global $usuario, $telaErro;
    $txt = fn($k, $max = 200) => mb_substr(trim(str_replace("\r", '', (string) ($_POST[$k] ?? ''))), 0, $max);
    switch ($acao) {
        case 'brassagem':
        case 'brassagem-lote':
        case 'brassagem-tanques':
            exigir('brassagem');
            return true;

        case 'brassagem-tanque-salvar':
            exigir_post();
            exigir('brassagem');
            $telaErro = 'brassagem-tanques';
            $b = dh_brassagem();
            $nome = $txt('nome', 40);
            if ($nome === '') {
                throw new RuntimeException('Dê um nome ao tanque (ex.: Tanque 1).');
            }
            $t = ['nome' => $nome, 'capacidade' => brassagem_num($_POST['capacidade'] ?? ''), 'litros_cm' => brassagem_num($_POST['litros_cm'] ?? ''), 'ativo' => empty($_POST['inativo'])];
            $i = dh_brassagem_indice($b['tanques'], (string) ($_POST['id'] ?? ''));
            if ($i === null) {
                $b['tanques'][] = ['id' => dh_id_novo('t')] + $t;
            } else {
                $b['tanques'][$i] = array_merge($b['tanques'][$i], $t);
            }
            usort($b['tanques'], fn($x, $y) => strnatcasecmp($x['nome'], $y['nome']));
            dh_brassagem_salvar($b);
            ir('./?acao=brassagem-tanques', 'Tanque ' . $nome . ' salvo.');

        case 'brassagem-tanque-excluir':
            exigir_post();
            exigir('brassagem');
            $telaErro = 'brassagem-tanques';
            $b = dh_brassagem();
            $id = (string) ($_POST['id'] ?? '');
            if (array_filter($b['lotes'], fn($l) => $l['tanque'] === $id)) {
                throw new RuntimeException('Este tanque tem lotes no histórico. Em vez de excluir, marque "Fora de uso".');
            }
            $b['tanques'] = array_values(array_filter($b['tanques'], fn($t) => $t['id'] !== $id));
            dh_brassagem_salvar($b);
            ir('./?acao=brassagem-tanques', 'Tanque excluído.');

        case 'brassagem-lote-salvar':
            // Cabeçalho do caderno: cria o lote (tanque livre) ou corrige os dados
            exigir_post();
            exigir('brassagem');
            $telaErro = 'brassagem-lote';
            $b = dh_brassagem();
            $i = dh_brassagem_indice($b['lotes'], (string) ($_POST['id'] ?? ''));
            $tanque = dh_tanque($b, (string) ($_POST['tanque'] ?? ''));
            // Cerveja do cadastro (Painel → Cervejas) ou, se ainda não estiver lá, o nome digitado
            $cid = (string) ($_POST['cerveja_id'] ?? '');
            $cad = $cid !== '' && $cid !== 'outra' ? (array_column(dh_brassagem_cervejas(), null, 'id')[$cid] ?? null) : null;
            $l = ['cerveja_id' => $cad['id'] ?? '', 'cerveja' => $cad ? $cad['nome'] : ($cid === 'outra' ? $txt('cerveja', 80) : ''), 'lote' => $txt('lote', 20), 'levedura' => $txt('levedura', 80), 'data' => brassagem_data($_POST['data'] ?? ''),
                'tanque' => $tanque['id'] ?? '', 'pf_brix' => brassagem_num($_POST['pf_brix'] ?? ''), 'pf_cm' => brassagem_num($_POST['pf_cm'] ?? ''),
                'og_brix' => brassagem_num($_POST['og_brix'] ?? ''), 'og_cm' => brassagem_num($_POST['og_cm'] ?? ''), 'obs' => $txt('obs', 1000)];
            if ($l['cerveja'] === '') {
                throw new RuntimeException($cid === 'outra' ? 'Digite o nome da cerveja.' : 'Escolha a cerveja.');
            }
            if ($l['lote'] === '') {
                throw new RuntimeException('Informe o número do lote.');
            }
            if ($l['data'] === '') {
                throw new RuntimeException('Informe a data da brassagem.');
            }
            if (!$tanque) {
                throw new RuntimeException('Escolha o tanque.');
            }
            foreach ($b['lotes'] as $j => $x) {
                if ($j !== $i && mb_strtolower($x['lote']) === mb_strtolower($l['lote'])) {
                    throw new RuntimeException('Já existe o lote ' . $x['lote'] . ' (' . $x['cerveja'] . '). Confira o número.');
                }
            }
            $ocupado = dh_tanque_lote($b, $tanque['id']);
            $mesmoLote = $ocupado && $i !== null && $ocupado['id'] === $b['lotes'][$i]['id'];
            if ($ocupado && !$mesmoLote && ($i === null || $b['lotes'][$i]['status'] !== 'finalizado')) {
                throw new RuntimeException($tanque['nome'] . ' está com o lote ' . $ocupado['lote'] . ' (' . $ocupado['cerveja'] . '). Finalize esse lote antes ou escolha outro tanque.');
            }
            // Receita da cerveja (cópia no lote): na criação, quando a cerveja muda ou se o lote ainda não tinha
            if ($i === null || empty($b['lotes'][$i]['receita']) || ($b['lotes'][$i]['cerveja_id'] ?? '') !== $l['cerveja_id'] || $b['lotes'][$i]['cerveja'] !== $l['cerveja']) {
                $rc = $l['cerveja_id'] !== '' ? dh_receita_da_cerveja(dh_receitas(), $l['cerveja_id']) : null;
                $l['receita'] = $rc ? dh_receita_copia($rc) : null;
            }
            if ($i === null) {
                $l = ['id' => dh_id_novo('l')] + $l + ['status' => 'fermentando', 'registros' => [], 'criado_por' => $usuario['nome'], 'criado_em' => date('Y-m-d H:i:s'), 'finalizado_em' => ''];
                $b['lotes'][] = $l;
                $msg = 'Lote ' . $l['lote'] . ' criado no ' . $tanque['nome'] . '. Agora é só anotar o acompanhamento.';
            } else {
                $b['lotes'][$i] = array_merge($b['lotes'][$i], $l);
                $l = $b['lotes'][$i];
                $msg = 'Cabeçalho do lote ' . $l['lote'] . ' salvo.';
            }
            dh_brassagem_salvar($b);
            ir('./?acao=brassagem-lote&id=' . $l['id'], $msg);

        case 'brassagem-registro-salvar':
            // Uma linha do acompanhamento (nova ou corrigida)
            exigir_post();
            exigir('brassagem');
            $telaErro = 'brassagem-lote';
            $b = dh_brassagem();
            $i = dh_brassagem_indice($b['lotes'], (string) ($_POST['id'] ?? ''));
            if ($i === null) {
                throw new RuntimeException('Lote não encontrado.');
            }
            $r = ['data' => brassagem_data($_POST['data'] ?? ''), 'brix' => brassagem_num($_POST['brix'] ?? ''), 'pressao' => brassagem_num($_POST['pressao'] ?? ''),
                'temp' => brassagem_num($_POST['temp'] ?? ''), 'texto' => $txt('texto', 500)];
            if ($r['data'] === '') {
                throw new RuntimeException('Informe a data da anotação.');
            }
            if ($r['texto'] === '' && $r['brix'] === '' && $r['pressao'] === '' && $r['temp'] === '') {
                throw new RuntimeException('Escreva a anotação ou preencha uma medida (Brix, pressão ou temperatura).');
            }
            $regs = $b['lotes'][$i]['registros'] ?? [];
            $j = dh_brassagem_indice($regs, (string) ($_POST['rid'] ?? ''));
            if ($j === null) {
                $regs[] = ['id' => dh_id_novo('r')] + $r + ['por' => $usuario['nome'], 'em' => date('Y-m-d H:i:s')];
            } else {
                $regs[$j] = array_merge($regs[$j], $r, ['editado_por' => $usuario['nome']]);
            }
            $b['lotes'][$i]['registros'] = $regs;
            dh_brassagem_salvar($b);
            ir('./?acao=brassagem-lote&id=' . $b['lotes'][$i]['id'] . '#acompanhamento', 'Anotação salva.');

        case 'brassagem-registro-excluir':
            exigir_post();
            exigir('brassagem');
            $telaErro = 'brassagem-lote';
            $b = dh_brassagem();
            $i = dh_brassagem_indice($b['lotes'], (string) ($_POST['id'] ?? ''));
            if ($i === null) {
                throw new RuntimeException('Lote não encontrado.');
            }
            $rid = (string) ($_POST['rid'] ?? '');
            $b['lotes'][$i]['registros'] = array_values(array_filter($b['lotes'][$i]['registros'] ?? [], fn($r) => $r['id'] !== $rid));
            dh_brassagem_salvar($b);
            ir('./?acao=brassagem-lote&id=' . $b['lotes'][$i]['id'] . '#acompanhamento', 'Anotação apagada.');

        case 'brassagem-status':
            // Fermentando → maturando → finalizado (libera o tanque). Dá para reabrir se o tanque estiver livre.
            exigir_post();
            $telaErro = 'brassagem-lote';
            exigir('brassagem');
            $b = dh_brassagem();
            $i = dh_brassagem_indice($b['lotes'], (string) ($_POST['id'] ?? ''));
            $st = (string) ($_POST['status'] ?? '');
            if ($i === null || !isset(DH_LOTE_STATUS[$st])) {
                throw new RuntimeException('Lote não encontrado.');
            }
            $l = $b['lotes'][$i];
            $ocupado = dh_tanque_lote($b, $l['tanque']);
            if ($st !== 'finalizado' && $ocupado && $ocupado['id'] !== $l['id']) {
                throw new RuntimeException('O tanque já está com o lote ' . $ocupado['lote'] . '. Não dá para reabrir este lote nele.');
            }
            $b['lotes'][$i]['status'] = $st;
            $b['lotes'][$i]['finalizado_em'] = $st === 'finalizado' ? date('Y-m-d H:i:s') : '';
            if ($st === 'maturando' && empty($l['maturacao_em'])) {
                $b['lotes'][$i]['maturacao_em'] = date('Y-m-d');
            }
            dh_brassagem_salvar($b);
            $tanque = dh_tanque($b, $l['tanque']);
            ir('./?acao=' . ($st === 'finalizado' ? 'brassagem' : 'brassagem-lote&id=' . $l['id']),
                $st === 'finalizado' ? 'Lote ' . $l['lote'] . ' finalizado. ' . ($tanque['nome'] ?? 'O tanque') . ' está livre.' : 'Lote ' . $l['lote'] . ': ' . mb_strtolower(DH_LOTE_STATUS[$st]) . '.');

        case 'brassagem-lote-excluir':
            exigir_post();
            exigir('brassagem');
            $telaErro = 'brassagem-lote';
            $b = dh_brassagem();
            $id = (string) ($_POST['id'] ?? '');
            $i = dh_brassagem_indice($b['lotes'], $id);
            if ($i === null) {
                throw new RuntimeException('Lote não encontrado.');
            }
            $lote = $b['lotes'][$i]['lote'];
            array_splice($b['lotes'], $i, 1);
            dh_brassagem_salvar($b);
            ir('./?acao=brassagem', 'Lote ' . $lote . ' excluído.');
    }
    return false;
}

function brassagem_tela(string $tela): bool
{
    if ($tela === 'brassagem') {
        brassagem_tela_tanques();
        return true;
    }
    if ($tela === 'brassagem-lote') {
        brassagem_tela_lote();
        return true;
    }
    if ($tela === 'brassagem-tanques') {
        brassagem_tela_config();
        return true;
    }
    return false;
}

// Resumo de medidas de uma linha: "7 °Bx · 0,5 bar · 4 °C"
function brassagem_medidas(array $r): string
{
    $m = [];
    if (($r['brix'] ?? '') !== '') {
        $m[] = dh_num($r['brix']) . ' °Bx';
    }
    if (($r['pressao'] ?? '') !== '') {
        $m[] = dh_num($r['pressao']) . ' bar';
    }
    if (($r['temp'] ?? '') !== '') {
        $m[] = dh_num($r['temp']) . ' °C';
    }
    return implode(' · ', $m);
}

function brassagem_tela_tanques(): void
{
    $b = dh_brassagem();
    $busca = mb_strtolower(trim((string) ($_GET['q'] ?? '')));
    $tanques = array_values(array_filter($b['tanques'], fn($t) => $t['ativo'] || dh_tanque_lote($b, $t['id'])));
    $cadastro = dh_brassagem_cervejas();
    ?>
      <div class="titulo-acao"><h1>Brassagem</h1><a class="btn secundario" href="./?acao=brassagem-tanques">⚙ Tanques</a></div>
      <?php if (!$b['tanques']): ?>
        <p class="aviso">Primeiro passo: <a href="./?acao=brassagem-tanques">cadastre os tanques</a> da fábrica. Depois, cada brassagem vira um caderno digital dentro do tanque.</p>
      <?php else: ?>
        <div class="novo-contrato"><a class="btn primario largo" href="./?acao=brassagem-lote">+ Nova brassagem</a></div>
      <?php endif; ?>
      <?php if ($tanques): ?>
        <div class="grade-tanques">
          <?php foreach ($tanques as $t): $l = dh_tanque_lote($b, $t['id']); ?>
            <?php if ($l): $ub = dh_lote_ultima($l, 'brix'); $up = dh_lote_ultima($l, 'pressao'); $ut = dh_lote_ultima($l, 'temp'); $regs = dh_lote_registros($l); $ult = end($regs); ?>
              <a class="tanque ocupado st-<?= e($l['status']) ?>" href="./?acao=brassagem-lote&amp;id=<?= e($l['id']) ?>">
                <span class="tanque-nome"><?= e($t['nome']) ?><em class="tag <?= $l['status'] === 'maturando' ? 'ct-enviado' : 'ct-preenchimento' ?>"><?= e(DH_LOTE_STATUS[$l['status']]) ?></em></span>
                <strong class="tanque-cerveja"><?php $cv = dh_lote_cerveja($l, $cadastro); if (!empty($cv['imagem'])): ?><img src="../<?= e($cv['imagem']) ?>" alt="" width="44" height="44" loading="lazy"><?php endif; ?><?= e($l['cerveja']) ?></strong>
                <span>Lote <?= e($l['lote']) ?> · dia <?= dh_lote_dia($l) ?> <small>(<?= e(dh_data_br($l['data'])) ?>)</small></span>
                <span class="tanque-medidas">
                  <?php if ($ub): ?><b><?= e(dh_num($ub['brix'])) ?> °Bx</b><?php endif; ?>
                  <?php if ($up): ?><b><?= e(dh_num($up['pressao'])) ?> bar</b><?php endif; ?>
                  <?php if ($ut): ?><b><?= e(dh_num($ut['temp'])) ?> °C</b><?php endif; ?>
                </span>
                <?php if ($ult): ?><span class="tanque-ultima"><?= e(date('d/m', strtotime($ult['data']))) ?>: <?= e($ult['texto'] !== '' ? $ult['texto'] : brassagem_medidas($ult)) ?></span><?php else: ?><span class="tanque-ultima">Sem anotações ainda</span><?php endif; ?>
              </a>
            <?php else: ?>
              <a class="tanque livre" href="./?acao=brassagem-lote&amp;tanque=<?= e($t['id']) ?>">
                <span class="tanque-nome"><?= e($t['nome']) ?><em class="tag cinza"><?= $t['ativo'] ? 'Livre' : 'Fora de uso' ?></em></span>
                <strong class="tanque-cerveja">+ Nova brassagem</strong>
                <?php if ($t['capacidade'] !== ''): ?><span><?= e(dh_num($t['capacidade'])) ?> L</span><?php endif; ?>
              </a>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php
        $hist = array_reverse($b['lotes']);
        usort($hist, fn($x, $y) => $y['data'] <=> $x['data']);
        if ($busca !== '') {
            $hist = array_filter($hist, fn($l) => str_contains(mb_strtolower($l['cerveja'] . ' ' . $l['lote'] . ' ' . $l['levedura']), $busca));
        }
        $hist = array_slice($hist, 0, $busca !== '' ? 200 : 40);
      ?>
      <?php if ($b['lotes']): ?>
        <h2>Cadernos (lotes)</h2>
        <form method="get" class="busca-lotes"><input type="hidden" name="acao" value="brassagem"><input type="search" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Buscar por cerveja, lote ou levedura"><button class="btn secundario">Buscar</button></form>
        <ul class="lista-lotes">
          <?php foreach ($hist as $l): $t = dh_tanque($b, $l['tanque']); ?>
            <li><a href="./?acao=brassagem-lote&amp;id=<?= e($l['id']) ?>"><strong>Lote <?= e($l['lote']) ?> · <?= e($l['cerveja']) ?></strong>
              <span><?= e(dh_data_br($l['data'])) ?> · <?= e($t['nome'] ?? '?') ?> · <?= count($l['registros'] ?? []) ?> anotações</span>
              <em class="tag <?= $l['status'] === 'finalizado' ? 'ct-ok' : ($l['status'] === 'maturando' ? 'ct-enviado' : 'ct-preenchimento') ?>"><?= e(DH_LOTE_STATUS[$l['status']]) ?></em></a></li>
          <?php endforeach; ?>
          <?php if (!$hist): ?><li class="dica">Nenhum lote encontrado.</li><?php endif; ?>
        </ul>
      <?php endif; ?>
    <?php
}

function brassagem_tela_lote(): void
{
    global $csrf, $erro;
    $b = dh_brassagem();
    $i = dh_brassagem_indice($b['lotes'], (string) ($_GET['id'] ?? $_POST['id'] ?? ''));
    $novo = $i === null;
    $l = $novo ? ['id' => '', 'cerveja' => '', 'lote' => dh_lote_proximo($b), 'levedura' => '', 'data' => date('Y-m-d'), 'tanque' => (string) ($_GET['tanque'] ?? ''),
        'pf_brix' => '', 'pf_cm' => '', 'og_brix' => '', 'og_cm' => '', 'obs' => '', 'status' => 'fermentando', 'registros' => []] : $b['lotes'][$i];
    $posted = $erro && $_SERVER['REQUEST_METHOD'] === 'POST';
    $cab = $posted && ($_GET['acao'] ?? '') === 'brassagem-lote-salvar' ? array_merge($l, array_intersect_key($_POST, $l)) : $l;
    $t = dh_tanque($b, $l['tanque']);
    $regs = dh_lote_registros($l);
    $cervejas = dh_brassagem_cervejas();
    $receitas = dh_receitas();
    $cv = $novo ? null : dh_lote_cerveja($l, $cervejas);
    // Cerveja escolhida no formulário: do cadastro, "outra" (nome digitado) ou nenhuma ainda
    $cidSel = $posted && isset($_POST['cerveja_id']) ? (string) $_POST['cerveja_id'] : ($cv['id'] ?? ($l['cerveja'] !== '' ? 'outra' : ''));
    $leveduras = array_unique(array_filter(array_column($b['lotes'], 'levedura')));
    $livres = array_filter($b['tanques'], fn($x) => $x['id'] === $l['tanque'] || ($x['ativo'] && !dh_tanque_lote($b, $x['id'])));
    $v = fn($k) => e(is_numeric($cab[$k] ?? '') && ($cab[$k] ?? '') !== '' ? dh_num($cab[$k]) : ($cab[$k] ?? ''));
    $litros = fn($cm) => ($x = dh_tanque_litros($t, (string) $cm)) !== null ? ' <small>(≈ ' . e(dh_num($x)) . ' L)</small>' : '';
    ?>
      <p class="sem-impressao"><a class="voltar" href="./?acao=brassagem">‹ Voltar para Brassagem</a></p>
      <?php if ($novo): ?>
        <h1>Nova brassagem</h1>
        <?php if (!$livres): ?><p class="aviso">Nenhum tanque livre. Finalize um lote ou <a href="./?acao=brassagem-tanques">cadastre outro tanque</a>.</p><?php endif; ?>
      <?php else: ?>
        <div class="titulo-acao"><h1>Lote <?= e($l['lote']) ?> · <?= e($l['cerveja']) ?></h1><em class="tag <?= $l['status'] === 'finalizado' ? 'ct-ok' : ($l['status'] === 'maturando' ? 'ct-enviado' : 'ct-preenchimento') ?>"><?= e(DH_LOTE_STATUS[$l['status']]) ?></em></div>
        <p class="dica"><?= e($t['nome'] ?? '?') ?> · brassagem em <?= e(dh_data_br($l['data'])) ?><?= $l['status'] !== 'finalizado' ? ' · hoje é o dia ' . dh_lote_dia($l) : ' · finalizado em ' . e(dh_data_br(substr($l['finalizado_em'], 0, 10))) . ' (dia ' . dh_lote_dia($l, substr($l['finalizado_em'], 0, 10)) . ')' ?></p>
      <?php endif; ?>

      <section class="caderno">
        <?php if (!$novo): ?>
          <dl class="caderno-cabecalho">
            <div><dt>Cerveja</dt><dd class="caderno-cerveja"><?php if (!empty($cv['imagem'])): ?><img src="../<?= e($cv['imagem']) ?>" alt="" width="48" height="48"><?php endif; ?><span><?= e($l['cerveja']) ?><?= !empty($cv['estilo']) ? '<small>' . e($cv['estilo']) . '</small>' : '' ?></span></dd></div>
            <div><dt>Data</dt><dd><?= e(dh_data_br($l['data'])) ?></dd></div>
            <div><dt>Lote</dt><dd><?= e($l['lote']) ?></dd></div>
            <div><dt>Levedura</dt><dd><?= e($l['levedura'] ?: '—') ?></dd></div>
            <div><dt>Tanque</dt><dd><?= e($t['nome'] ?? '?') ?></dd></div>
          </dl>
          <h2 class="caderno-titulo">Anotações brassagem</h2>
          <div class="caderno-brassagem">
            <div><span>PF</span><strong><?= $l['pf_brix'] !== '' ? e(dh_num($l['pf_brix'])) . ' °Bx' : '—' ?></strong><small><?= ($rcl = dh_receita_simplificar($l['receita'] ?? [])) && $rcl['pre_fervura'] !== '' ? 'receita: ' . e(dh_num($rcl['pre_fervura'])) . ' °Bx · ' : '' ?>Litragem: <?= $l['pf_cm'] !== '' ? e(dh_num($l['pf_cm'])) . ' cm' . $litros($l['pf_cm']) : '—' ?></small></div>
            <div><span>OG</span><strong><?= $l['og_brix'] !== '' ? e(dh_num($l['og_brix'])) . ' °Bx' : '—' ?></strong><small><?= $l['og_brix'] !== '' ? 'SG ≈ ' . e(dh_brix_sg($l['og_brix'])) . ' · ' : '' ?><?= ($rcl = dh_receita_simplificar($l['receita'] ?? [])) && $rcl['og'] !== '' ? 'receita: ' . e(dh_num($rcl['og'])) . ' °Bx · ' : '' ?>Litragem: <?= $l['og_cm'] !== '' ? e(dh_num($l['og_cm'])) . ' cm' . $litros($l['og_cm']) : '—' ?></small></div>
          </div>
          <?php if ($l['obs'] !== ''): ?><p class="caderno-obs"><b>Obs.:</b> <?= nl2br(e($l['obs'])) ?></p><?php endif; ?>
          <?php if (!empty($l['receita'])): $rc = dh_receita_simplificar($l['receita']); ?>
            <details class="caderno-receita">
              <summary>📖 Receita: <?= e($rc['nome']) ?><?= receitas_linha($rc) !== '' ? ' · ' . e(receitas_linha($rc)) : '' ?></summary>
              <?php receitas_resumo($rc); ?>
            </details>
          <?php elseif ($cv): ?>
            <p class="dica sem-impressao">A <?= e($cv['nome']) ?> ainda não tem receita. <a href="./?acao=receita&amp;cerveja=<?= e(rawurlencode($cv['id'])) ?>">Adicionar em Receitas</a> (depois, é só salvar o cabeçalho para ela entrar no caderno).</p>
          <?php endif; ?>
        <?php endif; ?>

        <details class="sem-impressao" <?= $novo || ($posted && ($_GET['acao'] ?? '') === 'brassagem-lote-salvar') ? 'open' : '' ?>>
          <summary class="<?= $novo ? 'escondido' : '' ?>">✏️ Editar cabeçalho</summary>
          <form method="post" action="./?acao=brassagem-lote-salvar" class="form-cerveja">
            <?= $csrf ?><input type="hidden" name="id" value="<?= e($l['id']) ?>">
            <div class="duas">
              <label>Cerveja <small>(do cadastro de Cervejas)</small>
                <select name="cerveja_id" id="cerveja-id" required>
                  <option value="">Escolha a cerveja</option>
                  <?php foreach ($cervejas as $c): $rc = dh_receita_da_cerveja($receitas, $c['id']); ?><option value="<?= e($c['id']) ?>" data-levedura="<?= e($rc['levedura'] ?? '') ?>" <?= $c['id'] === $cidSel ? 'selected' : '' ?>><?= e($c['nome']) ?><?= ($c['estilo'] ?? '') !== '' ? ' · ' . e($c['estilo']) : '' ?><?= $rc ? ' · 📖 receita' : '' ?></option><?php endforeach; ?>
                  <option value="outra" <?= $cidSel === 'outra' ? 'selected' : '' ?>>Outra (ainda não cadastrada)…</option>
                </select>
              </label>
              <label>Data da brassagem <input type="date" name="data" required value="<?= $v('data') ?>"></label>
            </div>
            <label id="cerveja-outra" <?= $cidSel === 'outra' ? '' : 'hidden' ?>>Nome da cerveja <input name="cerveja" value="<?= $cidSel === 'outra' ? $v('cerveja') : '' ?>" placeholder="Ex.: receita nova em teste"></label>
            <script>
              (function () {
                var s = document.getElementById('cerveja-id'), o = document.getElementById('cerveja-outra');
                function ver() { o.hidden = s.value !== 'outra'; o.querySelector('input').required = s.value === 'outra'; }
                // A levedura da receita entra sozinha se o campo estiver vazio
                s.addEventListener('change', function () {
                  var lev = document.querySelector('[name=levedura]'), op = s.selectedOptions[0];
                  if (lev && !lev.value && op && op.dataset.levedura) lev.value = op.dataset.levedura;
                  ver();
                });
                ver();
              })();
            </script>
            <div class="duas">
              <label>Lote <input name="lote" required inputmode="numeric" value="<?= $v('lote') ?>"></label>
              <label>Tanque
                <select name="tanque" required><option value="">—</option>
                  <?php foreach ($livres as $x): ?><option value="<?= e($x['id']) ?>" <?= $x['id'] === $cab['tanque'] ? 'selected' : '' ?>><?= e($x['nome']) ?></option><?php endforeach; ?>
                </select>
              </label>
            </div>
            <label>Levedura <input name="levedura" list="lista-leveduras" value="<?= $v('levedura') ?>" placeholder="Ex.: Lager G3 L136"></label>
            <datalist id="lista-leveduras"><?php foreach ($leveduras as $x): ?><option value="<?= e($x) ?>"><?php endforeach; ?></datalist>
            <div class="quatro">
              <label>PF (°Brix) <input name="pf_brix" inputmode="decimal" value="<?= $v('pf_brix') ?>"></label>
              <label>Litragem PF (cm) <input name="pf_cm" inputmode="decimal" value="<?= $v('pf_cm') ?>"></label>
              <label>OG (°Brix) <input name="og_brix" inputmode="decimal" value="<?= $v('og_brix') ?>"></label>
              <label>Litragem OG (cm) <input name="og_cm" inputmode="decimal" value="<?= $v('og_cm') ?>"></label>
            </div>
            <label>Obs. <textarea name="obs" rows="2"><?= $v('obs') ?></textarea></label>
            <button class="btn primario largo"><?= $novo ? 'Criar lote e abrir o caderno' : 'Salvar cabeçalho' ?></button>
          </form>
        </details>

        <?php if (!$novo): ?>
          <h2 class="caderno-titulo" id="acompanhamento">Acompanhamento fermentação e maturação</h2>
          <?php if ($l['status'] !== 'finalizado'): $rp = $posted && ($_GET['acao'] ?? '') === 'brassagem-registro-salvar' && empty($_POST['rid']) ? $_POST : []; ?>
            <form method="post" action="./?acao=brassagem-registro-salvar" class="form-registro sem-impressao">
              <?= $csrf ?><input type="hidden" name="id" value="<?= e($l['id']) ?>">
              <div class="quatro">
                <label>Data <input type="date" name="data" required value="<?= e($rp['data'] ?? date('Y-m-d')) ?>"></label>
                <label>Brix <small>(°Bx)</small><input name="brix" inputmode="decimal" value="<?= e($rp['brix'] ?? '') ?>"></label>
                <label>Pressão <small>(bar)</small><input name="pressao" inputmode="decimal" value="<?= e($rp['pressao'] ?? '') ?>"></label>
                <label>Temp. <small>(°C)</small><input name="temp" inputmode="decimal" value="<?= e($rp['temp'] ?? '') ?>"></label>
              </div>
              <label>Anotação <textarea name="texto" rows="2" placeholder="Ex.: Medição 4 Brix, fechar válvula, baixar para 4 graus"><?= e($rp['texto'] ?? '') ?></textarea></label>
              <button class="btn primario largo">+ Anotar</button>
            </form>
          <?php endif; ?>
          <ol class="caderno-linhas">
            <?php foreach (array_reverse($regs) as $r): $med = brassagem_medidas($r); ?>
              <li>
                <span class="linha-data"><?= e(date('d/m', strtotime($r['data']))) ?><small>dia <?= dh_lote_dia($l, $r['data']) ?></small></span>
                <span class="linha-texto">
                  <?php if ($med !== ''): ?><b class="linha-medidas"><?= e($med) ?></b><?php endif; ?>
                  <?= nl2br(e($r['texto'])) ?>
                  <small class="linha-autor"><?= e($r['por']) ?></small>
                  <details class="sem-impressao">
                    <summary>editar</summary>
                    <form method="post" action="./?acao=brassagem-registro-salvar" class="form-registro">
                      <?= $csrf ?><input type="hidden" name="id" value="<?= e($l['id']) ?>"><input type="hidden" name="rid" value="<?= e($r['id']) ?>">
                      <div class="quatro">
                        <label>Data <input type="date" name="data" required value="<?= e($r['data']) ?>"></label>
                        <label>Brix <input name="brix" inputmode="decimal" value="<?= e($r['brix'] !== '' ? dh_num($r['brix']) : '') ?>"></label>
                        <label>Pressão <input name="pressao" inputmode="decimal" value="<?= e($r['pressao'] !== '' ? dh_num($r['pressao']) : '') ?>"></label>
                        <label>Temp. <input name="temp" inputmode="decimal" value="<?= e($r['temp'] !== '' ? dh_num($r['temp']) : '') ?>"></label>
                      </div>
                      <label>Anotação <textarea name="texto" rows="2"><?= e($r['texto']) ?></textarea></label>
                      <button class="btn primario">Salvar</button>
                    </form>
                    <form method="post" action="./?acao=brassagem-registro-excluir" onsubmit="return confirm('Apagar esta anotação?');"><?= $csrf ?><input type="hidden" name="id" value="<?= e($l['id']) ?>"><input type="hidden" name="rid" value="<?= e($r['id']) ?>"><button class="link perigo-link">Apagar anotação</button></form>
                  </details>
                </span>
              </li>
            <?php endforeach; ?>
            <?php if (!$regs): ?><li class="dica">Nenhuma anotação ainda.</li><?php endif; ?>
          </ol>

          <div class="card-botoes sem-impressao">
            <?php $st = fn($s, $rot, $cls, $conf) => '<form method="post" action="./?acao=brassagem-status" onsubmit="return confirm(\'' . e($conf) . '\');">' . $csrf . '<input type="hidden" name="id" value="' . e($l['id']) . '"><input type="hidden" name="status" value="' . $s . '"><button class="btn ' . $cls . '">' . $rot . '</button></form>'; ?>
            <?php if ($l['status'] === 'fermentando'): ?><?= $st('maturando', '❄️ Passar para maturação', 'secundario', 'Passar o lote ' . $l['lote'] . ' para maturação?') ?><?php endif; ?>
            <?php if ($l['status'] !== 'finalizado'): ?><?= $st('finalizado', '✅ Finalizar lote (libera o tanque)', 'primario', 'Finalizar o lote ' . $l['lote'] . '? O ' . ($t['nome'] ?? 'tanque') . ' fica livre para outra brassagem.') ?><?php endif; ?>
            <?php if ($l['status'] === 'finalizado' && !dh_tanque_lote($b, $l['tanque'])): ?><?= $st('maturando', '↩ Reabrir lote', 'secundario', 'Reabrir o lote ' . $l['lote'] . ' no ' . ($t['nome'] ?? 'tanque') . '?') ?><?php endif; ?>
            <button type="button" class="btn secundario" onclick="window.print()">🖨 Imprimir caderno</button>
            <form method="post" action="./?acao=brassagem-lote-excluir" onsubmit="return confirm('Excluir o lote <?= e($l['lote']) ?> e todas as anotações? Não dá para desfazer.');"><?= $csrf ?><input type="hidden" name="id" value="<?= e($l['id']) ?>"><button class="btn perigo">🗑 Excluir lote</button></form>
          </div>
        <?php endif; ?>
      </section>
    <?php
}

function brassagem_tela_config(): void
{
    global $csrf;
    $b = dh_brassagem();
    $form = function (array $t) use ($csrf): void {
        ?>
          <form method="post" action="./?acao=brassagem-tanque-salvar" class="form-cerveja">
            <?= $csrf ?><input type="hidden" name="id" value="<?= e($t['id']) ?>">
            <label>Nome <input name="nome" required maxlength="40" value="<?= e($t['nome']) ?>" placeholder="Ex.: Tanque 1"></label>
            <div class="duas">
              <label>Capacidade (L) <small>(opcional)</small><input name="capacidade" inputmode="decimal" value="<?= e($t['capacidade'] !== '' ? dh_num($t['capacidade']) : '') ?>"></label>
              <label>Litros por cm <small>(opcional; converte a litragem em cm para litros)</small><input name="litros_cm" inputmode="decimal" value="<?= e($t['litros_cm'] !== '' ? dh_num($t['litros_cm']) : '') ?>"></label>
            </div>
            <?php if ($t['id']): ?><label class="check"><input type="checkbox" name="inativo" value="1" <?= $t['ativo'] ? '' : 'checked' ?>> Fora de uso (não aparece para novas brassagens)</label><?php endif; ?>
            <button class="btn primario"><?= $t['id'] ? 'Salvar tanque' : 'Adicionar tanque' ?></button>
          </form>
        <?php
    };
    ?>
      <p><a class="voltar" href="./?acao=brassagem">‹ Voltar para Brassagem</a></p>
      <h1>Tanques</h1>
      <p class="dica">Os tanques de fermentação da fábrica (que atende as duas sedes). Cada tanque recebe um lote por vez; ao finalizar o lote, ele fica livre de novo.</p>
      <ul class="lista-lotes">
        <?php foreach ($b['tanques'] as $t): $l = dh_tanque_lote($b, $t['id']); ?>
          <li>
            <details>
              <summary><strong><?= e($t['nome']) ?></strong> <span><?= $t['capacidade'] !== '' ? e(dh_num($t['capacidade'])) . ' L · ' : '' ?><?= $l ? 'lote ' . e($l['lote']) . ' (' . e($l['cerveja']) . ')' : ($t['ativo'] ? 'livre' : 'fora de uso') ?></span></summary>
              <?php $form($t); ?>
              <form method="post" action="./?acao=brassagem-tanque-excluir" class="excluir" onsubmit="return confirm('Excluir o <?= e($t['nome']) ?>?');"><?= $csrf ?><input type="hidden" name="id" value="<?= e($t['id']) ?>"><button class="btn perigo">Excluir tanque</button></form>
            </details>
          </li>
        <?php endforeach; ?>
        <?php if (!$b['tanques']): ?><li class="dica">Nenhum tanque cadastrado.</li><?php endif; ?>
      </ul>
      <h2>Novo tanque</h2>
      <?php $form(['id' => '', 'nome' => 'Tanque ' . (count($b['tanques']) + 1), 'capacidade' => '', 'litros_cm' => '', 'ativo' => true]); ?>
    <?php
}
