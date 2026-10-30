<?php
// Painel → Receitas: uma receita por cerveja do cadastro, importada do BeerSmith (BeerXML .xml ou .bsmx)
// ou criada à mão. Importar de novo a receita de uma cerveja guarda a anterior em "versões".
// A Brassagem puxa a receita da cerveja escolhida. Ver inc/receitas.php.

function receitas_acao(string $acao): bool
{
    global $usuario, $telaErro;
    $txt = fn($k, $max = 200) => mb_substr(trim(str_replace("\r", '', (string) ($_POST[$k] ?? ''))), 0, $max);
    switch ($acao) {
        case 'receitas':
        case 'receita':
            exigir('receitas');
            return true;

        case 'receitas-importar':
            exigir_post();
            exigir('receitas');
            $telaErro = 'receitas';
            $arqs = $_FILES['arquivos'] ?? null;
            if (!$arqs || !is_array($arqs['name'])) {
                throw new RuntimeException('Escolha o arquivo exportado do BeerSmith (.xml ou .bsmx).');
            }
            $cervejas = dh_brassagem_cervejas();
            $r = dh_receitas();
            $alvo = dh_brassagem_indice($r['receitas'], (string) ($_POST['alvo'] ?? ''));
            $novas = $atualizadas = 0;
            $nomes = [];
            foreach ($arqs['name'] as $n => $nomeArq) {
                if (($arqs['error'][$n] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                if ($arqs['error'][$n] !== UPLOAD_ERR_OK || !is_uploaded_file($arqs['tmp_name'][$n])) {
                    throw new RuntimeException('Não foi possível receber o arquivo ' . $nomeArq . '.');
                }
                foreach (dh_receitas_importar((string) file_get_contents($arqs['tmp_name'][$n]), (string) $nomeArq) as $x) {
                    // Nova versão de uma receita escolhida, ou da receita da cerveja de mesmo nome
                    $cid = $alvo !== null ? $r['receitas'][$alvo]['cerveja_id'] : dh_receita_casar_cerveja($x['nome'], $cervejas);
                    $i = $alvo;
                    if ($i === null && $cid !== '') {
                        $atual = dh_receita_da_cerveja($r, $cid);
                        $i = $atual ? dh_brassagem_indice($r['receitas'], $atual['id']) : null;
                    }
                    $x['cerveja_id'] = $cid;
                    $x['importado_por'] = $usuario['nome'];
                    $x['atualizado_em'] = date('Y-m-d H:i:s');
                    if ($i === null) {
                        $x['id'] = dh_id_novo('rc');
                        $r['receitas'][] = $x;
                        $novas++;
                    } else {
                        $velha = $r['receitas'][$i];
                        $versoes = $velha['versoes'] ?? [];
                        unset($velha['versoes']);
                        array_unshift($versoes, $velha);
                        $x['id'] = $velha['id'];
                        $x['notas'] = $x['notas'] !== '' ? $x['notas'] : $velha['notas'];
                        $x['versoes'] = array_slice($versoes, 0, 10);
                        $r['receitas'][$i] = $x;
                        $atualizadas++;
                    }
                    $nomes[] = $x['nome'];
                    $ultimoId = $x['id'];
                    $alvo = null;
                }
            }
            if (!$nomes) {
                throw new RuntimeException('Escolha o arquivo exportado do BeerSmith (.xml ou .bsmx).');
            }
            dh_receitas_salvar($r);
            $destino = count($nomes) === 1 ? './?acao=receita&id=' . $ultimoId : './?acao=receitas';
            ir($destino, count($nomes) . ' receita(s) importada(s): ' . implode(', ', array_slice($nomes, 0, 8)) . (count($nomes) > 8 ? '…' : '') . '.'
                . ($atualizadas ? ' ' . $atualizadas . ' substituíram a receita que já existia (a anterior fica em "versões").' : '')
                . ' Confira a cerveja ligada a cada uma.');

        case 'receita-salvar':
            exigir_post();
            exigir('receitas');
            $telaErro = 'receita';
            $r = dh_receitas();
            $i = dh_brassagem_indice($r['receitas'], (string) ($_POST['id'] ?? ''));
            $x = $i === null ? array_merge(dh_receita_vazia(), ['id' => dh_id_novo('rc')]) : $r['receitas'][$i];
            $num = fn($k) => brassagem_num($_POST[$k] ?? '');
            $cid = (string) ($_POST['cerveja_id'] ?? '');
            $x = array_merge($x, ['nome' => $txt('nome', 100), 'estilo' => $txt('estilo', 80), 'cerveja_id' => isset(array_column(dh_brassagem_cervejas(), null, 'id')[$cid]) ? $cid : '',
                'volume_l' => $num('volume_l'), 'og' => $num('og'), 'fg' => $num('fg'), 'abv' => $num('abv'), 'ibu' => $num('ibu'), 'notas' => $txt('notas', 8000),
                'atualizado_em' => date('Y-m-d H:i:s')]);
            if ($x['nome'] === '') {
                throw new RuntimeException('Dê um nome à receita.');
            }
            foreach (['og', 'fg'] as $k) {
                if ($x[$k] !== '' && ((float) $x[$k] < 1 || (float) $x[$k] > 1.2)) {
                    throw new RuntimeException(strtoupper($k) . ' em densidade, por exemplo 1,050.');
                }
            }
            if ($i === null) {
                $r['receitas'][] = $x;
            } else {
                $r['receitas'][$i] = $x;
            }
            dh_receitas_salvar($r);
            ir('./?acao=receita&id=' . $x['id'], 'Receita ' . $x['nome'] . ' salva.');

        case 'receita-excluir':
            exigir_post();
            exigir('receitas');
            $telaErro = 'receita';
            $r = dh_receitas();
            $i = dh_brassagem_indice($r['receitas'], (string) ($_POST['id'] ?? ''));
            if ($i === null) {
                throw new RuntimeException('Receita não encontrada.');
            }
            $nome = $r['receitas'][$i]['nome'];
            array_splice($r['receitas'], $i, 1);
            dh_receitas_salvar($r);
            ir('./?acao=receitas', 'Receita ' . $nome . ' excluída. Os lotes já feitos guardam a cópia dela.');
    }
    return false;
}

function receitas_tela(string $tela): bool
{
    if ($tela === 'receitas') {
        receitas_tela_lista();
        return true;
    }
    if ($tela === 'receita') {
        receitas_tela_receita();
        return true;
    }
    return false;
}

function receitas_form_importar(string $alvo = ''): void
{
    global $csrf;
    ?>
      <form method="post" action="./?acao=receitas-importar" enctype="multipart/form-data" class="form-importar-receita">
        <?= $csrf ?><input type="hidden" name="alvo" value="<?= e($alvo) ?>">
        <label><?= $alvo ? 'Importar nova versão desta receita' : 'Importar do BeerSmith' ?> <small>(BeerXML .xml ou .bsmx; pode escolher vários arquivos)</small>
          <input type="file" name="arquivos[]" accept=".xml,.bsmx,.beerxml,text/xml,application/xml" <?= $alvo ? '' : 'multiple' ?> required></label>
        <button class="btn primario">Importar</button>
      </form>
    <?php
}

function receitas_tela_lista(): void
{
    $r = dh_receitas();
    $cervejas = dh_brassagem_cervejas();
    $ids = array_column($cervejas, 'id');
    $soltas = array_filter($r['receitas'], fn($x) => !in_array($x['cerveja_id'], $ids, true));
    ?>
      <div class="titulo-acao"><h1>Receitas</h1><a class="btn secundario" href="./?acao=receita">+ Receita manual</a></div>
      <p class="dica">Uma receita para cada cerveja do cadastro. No BeerSmith, exporte a receita em <b>BeerXML</b> (Arquivo → Exportar → BeerXML; traz OG, FG, IBU e ABV previstos) ou envie o arquivo <b>.bsmx</b>. A receita é ligada à cerveja de nome parecido; confira e troque se precisar.</p>
      <?php receitas_form_importar(); ?>
      <?php if ($soltas): ?>
        <h2>Sem cerveja ligada</h2>
        <ul class="lista-lotes">
          <?php foreach ($soltas as $x): ?>
            <li><a href="./?acao=receita&amp;id=<?= e($x['id']) ?>"><strong><?= e($x['nome']) ?></strong><span><?= e($x['estilo']) ?> · escolha a cerveja do cadastro</span><em class="tag alerta">Ligar à cerveja</em></a></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <h2>Cervejas</h2>
      <ul class="lista-lotes lista-receitas">
        <?php foreach ($cervejas as $c): $x = dh_receita_da_cerveja($r, $c['id']); ?>
          <li><a href="<?= $x ? './?acao=receita&amp;id=' . e($x['id']) : './?acao=receita&amp;cerveja=' . e(rawurlencode($c['id'])) ?>">
            <?php if (!empty($c['imagem'])): ?><img src="../<?= e($c['imagem']) ?>" alt="" width="40" height="40" loading="lazy"><?php endif; ?>
            <strong><?= e($c['nome']) ?></strong>
            <?php if ($x): ?>
              <span><?= e($x['nome']) ?><?= $x['og'] !== '' ? ' · OG ' . e(dh_sg_formatar($x['og'])) . ' (≈ ' . e(dh_sg_brix($x['og'])) . ' °Bx)' : '' ?><?= $x['ibu'] !== '' ? ' · ' . e(dh_num($x['ibu'])) . ' IBU' : '' ?></span>
              <em class="tag ct-ok">Receita ✓</em>
            <?php else: ?>
              <span>Sem receita</span><em class="tag cinza">Adicionar</em>
            <?php endif; ?>
          </a></li>
        <?php endforeach; ?>
      </ul>
    <?php
}

function receitas_tela_receita(): void
{
    global $csrf, $erro;
    $r = dh_receitas();
    $i = dh_brassagem_indice($r['receitas'], (string) ($_GET['id'] ?? $_POST['id'] ?? ''));
    $x = $i === null ? array_merge(dh_receita_vazia(), ['cerveja_id' => (string) ($_GET['cerveja'] ?? '')]) : $r['receitas'][$i];
    $cervejas = dh_brassagem_cervejas();
    $cv = array_column($cervejas, null, 'id')[$x['cerveja_id']] ?? null;
    if ($i === null && $cv) {
        $x['nome'] = $cv['nome'];
        $x['estilo'] = $cv['estilo'] ?? '';
    }
    if ($erro && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['acao'] ?? '') === 'receita-salvar') {
        $x = array_merge($x, array_intersect_key($_POST, array_flip(['nome', 'estilo', 'cerveja_id', 'volume_l', 'og', 'fg', 'abv', 'ibu', 'notas'])));
    }
    $n = fn($k) => e(($x[$k] ?? '') !== '' && is_numeric($x[$k]) ? ($k === 'og' || $k === 'fg' ? dh_sg_formatar($x[$k]) : dh_num($x[$k])) : ($x[$k] ?? ''));
    ?>
      <p class="sem-impressao"><a class="voltar" href="./?acao=receitas">‹ Voltar para Receitas</a></p>
      <?php if ($i === null): ?>
        <h1>Nova receita</h1>
        <p class="dica">Se tiver a receita no BeerSmith, é mais rápido importar o arquivo na tela de Receitas.</p>
      <?php else: ?>
        <div class="titulo-acao"><h1><?= e($x['nome']) ?></h1><button type="button" class="btn secundario sem-impressao" onclick="window.print()">🖨 Imprimir</button></div>
        <p class="dica"><?= $cv ? 'Receita da <b>' . e($cv['nome']) . '</b>' : '<b style="color:var(--erro)">Sem cerveja ligada</b>' ?><?= $x['estilo'] !== '' ? ' · ' . e($x['estilo']) : '' ?>
          <?= $x['origem'] !== 'manual' ? ' · importada do BeerSmith (' . e($x['arquivo']) . ')' : '' ?><?= !empty($x['atualizado_em']) ? ' · atualizada em ' . e(date('d/m/Y', strtotime($x['atualizado_em']))) : '' ?></p>
        <?php receitas_resumo($x); ?>
      <?php endif; ?>

      <details class="sem-impressao caderno-editar" <?= $i === null || $erro ? 'open' : '' ?>>
        <summary class="<?= $i === null ? 'escondido' : '' ?>">✏️ Editar dados da receita</summary>
        <form method="post" action="./?acao=receita-salvar" class="form-cerveja">
          <?= $csrf ?><input type="hidden" name="id" value="<?= e($x['id']) ?>">
          <label>Cerveja do cadastro
            <select name="cerveja_id"><option value="">— Nenhuma —</option>
              <?php foreach ($cervejas as $c): ?><option value="<?= e($c['id']) ?>" <?= $c['id'] === $x['cerveja_id'] ? 'selected' : '' ?>><?= e($c['nome']) ?></option><?php endforeach; ?>
            </select>
          </label>
          <div class="duas">
            <label>Nome da receita <input name="nome" required value="<?= e($x['nome']) ?>"></label>
            <label>Estilo <input name="estilo" value="<?= e($x['estilo']) ?>"></label>
          </div>
          <div class="quatro">
            <label>Volume (L) <input name="volume_l" inputmode="decimal" value="<?= $n('volume_l') ?>"></label>
            <label>OG <small>(ex.: 1,050)</small><input name="og" inputmode="decimal" value="<?= $n('og') ?>"></label>
            <label>FG <small>(ex.: 1,010)</small><input name="fg" inputmode="decimal" value="<?= $n('fg') ?>"></label>
            <label>IBU <input name="ibu" inputmode="decimal" value="<?= $n('ibu') ?>"></label>
          </div>
          <label>ABV (%) <input name="abv" inputmode="decimal" value="<?= $n('abv') ?>"></label>
          <label>Notas e processo <small>(ingredientes, se for receita manual, e observações)</small><textarea name="notas" rows="6"><?= e($x['notas']) ?></textarea></label>
          <button class="btn primario largo">Salvar receita</button>
        </form>
      </details>

      <?php if ($i !== null): ?>
        <div class="sem-impressao">
          <?php receitas_form_importar($x['id']); ?>
          <?php if (!empty($x['versoes'])): ?>
            <details class="caderno-editar"><summary>Versões anteriores (<?= count($x['versoes']) ?>)</summary>
              <ul class="lista-lotes"><?php foreach ($x['versoes'] as $vv): ?><li><span><?= e(date('d/m/Y H:i', strtotime($vv['atualizado_em'] ?? 'now'))) ?> · <?= e($vv['nome']) ?><?= $vv['og'] !== '' ? ' · OG ' . e(dh_sg_formatar($vv['og'])) : '' ?> · <?= count($vv['fermentaveis']) ?> fermentáveis, <?= count($vv['lupulos']) ?> lúpulos</span></li><?php endforeach; ?></ul>
            </details>
          <?php endif; ?>
          <form method="post" action="./?acao=receita-excluir" class="excluir" onsubmit="return confirm('Excluir a receita <?= e(addslashes($x['nome'])) ?>? Os lotes já feitos guardam a cópia dela.');"><?= $csrf ?><input type="hidden" name="id" value="<?= e($x['id']) ?>"><button class="btn perigo">🗑 Excluir receita</button></form>
        </div>
      <?php endif; ?>
    <?php
}

// Números previstos e ingredientes (também usado no caderno do lote)
function receitas_resumo(array $x, bool $compacto = false): void
{
    $met = [];
    if ($x['og'] !== '') {
        $met[] = ['OG', dh_sg_formatar($x['og']), '≈ ' . dh_sg_brix($x['og']) . ' °Bx'];
    }
    if ($x['fg'] !== '') {
        $met[] = ['FG', dh_sg_formatar($x['fg']), '≈ ' . dh_sg_brix($x['fg']) . ' °Bx (no refratômetro, a FG lida com álcool fica diferente)'];
    }
    if ($x['abv'] !== '') {
        $met[] = ['ABV', dh_num($x['abv']) . '%', ''];
    }
    if ($x['ibu'] !== '') {
        $met[] = ['IBU', dh_num($x['ibu']), ''];
    }
    if (($x['cor_ebc'] ?? '') !== '') {
        $met[] = ['Cor', dh_num($x['cor_ebc']) . ' EBC', ''];
    }
    if ($x['volume_l'] !== '') {
        $met[] = ['Volume', dh_num($x['volume_l']) . ' L', ''];
    }
    ?>
      <?php if ($met): ?>
        <div class="grade-metricas receita-metricas">
          <?php foreach ($met as [$rot, $val, $sub]): ?><div class="cartao-metrica"><span><?= e($rot) ?></span><strong><?= e($val) ?></strong><?php if ($sub !== ''): ?><small><?= e($sub) ?></small><?php endif; ?></div><?php endforeach; ?>
        </div>
      <?php endif; ?>
      <?php if ($x['fermentaveis']): $tot = array_sum(array_map(fn($f) => (float) $f['kg'], $x['fermentaveis'])); ?>
        <h3>Fermentáveis <small><?= e(dh_num(round($tot, 2))) ?> kg</small></h3>
        <table class="tabela-receita"><?php foreach ($x['fermentaveis'] as $f): ?><tr><td><?= e($f['nome']) ?></td><td><?= e(dh_num($f['kg'])) ?> kg</td><td><?= $tot > 0 ? e(dh_num(round((float) $f['kg'] / $tot * 100, 1))) . '%' : '' ?></td></tr><?php endforeach; ?></table>
      <?php endif; ?>
      <?php if ($x['lupulos']): ?>
        <h3>Lúpulos</h3>
        <table class="tabela-receita"><?php foreach ($x['lupulos'] as $h): ?><tr><td><?= e($h['nome']) ?><?= $h['alfa'] !== '' ? ' <small>(' . e(dh_num($h['alfa'])) . '% AA)</small>' : '' ?></td><td><?= e(dh_num($h['g'])) ?> g</td><td><?= e(receitas_uso_lupulo($h)) ?></td></tr><?php endforeach; ?></table>
      <?php endif; ?>
      <?php if ($x['leveduras']): ?>
        <h3>Levedura</h3>
        <p><?= e(implode(' · ', array_map(fn($y) => trim($y['nome'] . ($y['lab'] !== '' || $y['produto'] !== '' ? ' (' . trim($y['lab'] . ' ' . $y['produto']) . ')' : '')), $x['leveduras']))) ?></p>
      <?php endif; ?>
      <?php if ($x['outros']): ?>
        <h3>Outros</h3>
        <table class="tabela-receita"><?php foreach ($x['outros'] as $m): ?><tr><td><?= e($m['nome']) ?></td><td><?= $m['qtd'] !== '' ? e(dh_num($m['qtd'])) . ' ' . e($m['unidade']) : '' ?></td><td><?= e($m['uso']) ?></td></tr><?php endforeach; ?></table>
      <?php endif; ?>
      <?php if ($x['mostura']): ?>
        <h3>Mostura</h3>
        <table class="tabela-receita"><?php foreach ($x['mostura'] as $s): ?><tr><td><?= e($s['nome']) ?></td><td><?= $s['temp_c'] !== '' ? e(dh_num($s['temp_c'])) . ' °C' : '' ?></td><td><?= $s['tempo_min'] !== '' ? e(dh_num($s['tempo_min'])) . ' min' : '' ?></td></tr><?php endforeach; ?></table>
      <?php endif; ?>
      <?php if (!$compacto && trim($x['notas']) !== ''): ?>
        <h3>Notas</h3><p class="receita-notas"><?= nl2br(e($x['notas'])) ?></p>
      <?php endif; ?>
    <?php
}

function receitas_uso_lupulo(array $h): string
{
    $uso = ['boil' => 'Fervura', 'dry hop' => 'Dry hop', 'mash' => 'Mostura', 'first wort' => 'First wort', 'aroma' => 'Whirlpool/aroma'][strtolower($h['uso'])] ?? $h['uso'];
    if ($h['tempo_min'] === '') {
        return $uso;
    }
    $min = (float) $h['tempo_min'];
    return $uso . ' · ' . (strtolower($h['uso']) === 'dry hop' && $min >= 1440 ? dh_num(round($min / 1440, 1)) . ' dias' : dh_num($min) . ' min');
}
