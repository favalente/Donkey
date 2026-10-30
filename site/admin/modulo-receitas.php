<?php
// Painel → Receitas: uma receita por cerveja do cadastro, só com o que a fábrica usa: insumos,
// pré-fervura, OG e FG em °Brix, litragem da lavagem e o print da receita (opcional). Dá para
// preencher à mão ou importar do BeerSmith; importar de novo guarda a anterior em "versões".
// A Brassagem puxa a receita da cerveja escolhida. Ver inc/receitas.php.

function receitas_acao(string $acao): bool
{
    global $usuario, $telaErro;
    switch ($acao) {
        case 'receitas':
        case 'receita':
            exigir('receitas');
            return true;

        case 'receita-imagem':
            // Print da receita (fica em dados/, fora da parte pública)
            exigir('receitas');
            $x = null;
            foreach (dh_receitas()['receitas'] as $rc) {
                if ($rc['id'] === (string) ($_GET['id'] ?? '')) {
                    $x = $rc;
                }
            }
            $arq = $x ? dh_receita_imagem_caminho($x['imagem']) : null;
            if (!$arq) {
                http_response_code(404);
                exit('Print não encontrado.');
            }
            header('Content-Type: ' . (array_search(pathinfo($arq, PATHINFO_EXTENSION), DH_RECEITA_IMG_TIPOS, true) ?: 'image/jpeg'));
            header('Content-Length: ' . filesize($arq));
            header('Cache-Control: private, max-age=3600');
            readfile($arq);
            exit;

        case 'receitas-importar':
            exigir_post();
            exigir('receitas');
            $telaErro = (string) ($_POST['alvo'] ?? '') !== '' ? 'receita' : 'receitas';
            $arqs = $_FILES['arquivos'] ?? null;
            if (!$arqs || !is_array($arqs['name'])) {
                throw new RuntimeException('Escolha o arquivo exportado do BeerSmith (.xml ou .bsmx).');
            }
            $cervejas = dh_brassagem_cervejas();
            $r = dh_receitas();
            $alvo = dh_brassagem_indice($r['receitas'], (string) ($_POST['alvo'] ?? ''));
            $atualizadas = 0;
            $usadas = ['' => true];
            $nomes = [];
            $ultimoId = '';
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
                    // Duas receitas do mesmo arquivo para a mesma cerveja: a segunda fica para você ligar
                    if ($alvo === null && isset($usadas[$cid])) {
                        $cid = '';
                    }
                    $usadas[$cid] = true;
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
                    } else {
                        // O que o arquivo não trouxer fica como estava (e o print também)
                        $velha = $r['receitas'][$i];
                        $x['id'] = $velha['id'];
                        foreach (['volume_l', 'pre_fervura', 'og', 'fg', 'lavagem_l', 'cor_ebc', 'abv', 'ibu', 'imagem'] as $k) {
                            $x[$k] = $x[$k] !== '' ? $x[$k] : $velha[$k];
                        }
                        $x['versoes'] = array_slice(array_merge([array_diff_key($velha, ['versoes' => 1])], $velha['versoes']), 0, 10);
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
            ir(count($nomes) === 1 ? './?acao=receita&id=' . $ultimoId : './?acao=receitas', count($nomes) . ' receita(s) importada(s): ' . implode(', ', array_slice($nomes, 0, 8)) . (count($nomes) > 8 ? '…' : '') . '.'
                . ($atualizadas ? ' ' . $atualizadas . ' substituíram a receita que já existia (a anterior fica em "versões").' : '')
                . ' Confira a cerveja ligada a cada uma.');

        case 'receita-salvar':
            exigir_post();
            exigir('receitas');
            $telaErro = 'receita';
            $r = dh_receitas();
            $i = dh_brassagem_indice($r['receitas'], (string) ($_POST['id'] ?? ''));
            $x = $i === null ? array_merge(dh_receita_vazia(), ['id' => dh_id_novo('rc')]) : $r['receitas'][$i];
            $cid = (string) ($_POST['cerveja_id'] ?? '');
            $insumos = [];
            foreach ((array) ($_POST['insumos'] ?? []) as $it) {
                $nome = mb_substr(trim((string) ($it['nome'] ?? '')), 0, 160);
                if ($nome !== '') {
                    $insumos[] = ['qtd' => brassagem_num($it['qtd'] ?? ''), 'unidade' => mb_substr(trim((string) ($it['unidade'] ?? '')), 0, 10), 'nome' => $nome];
                }
            }
            $x = array_merge($x, ['nome' => mb_substr(trim((string) ($_POST['nome'] ?? '')), 0, 100), 'cerveja_id' => isset(array_column(dh_brassagem_cervejas(), null, 'id')[$cid]) ? $cid : '',
                'insumos' => $insumos, 'pre_fervura' => brassagem_num($_POST['pre_fervura'] ?? ''), 'og' => brassagem_num($_POST['og'] ?? ''), 'fg' => brassagem_num($_POST['fg'] ?? ''), 'fg_refr' => true,
                'lavagem_l' => brassagem_num($_POST['lavagem_l'] ?? ''), 'volume_l' => brassagem_num($_POST['volume_l'] ?? ''),
                'cor_ebc' => brassagem_num($_POST['cor_ebc'] ?? ''), 'abv' => brassagem_num($_POST['abv'] ?? ''), 'ibu' => brassagem_num($_POST['ibu'] ?? ''), 'atualizado_em' => date('Y-m-d H:i:s')]);
            if ($x['nome'] === '') {
                throw new RuntimeException('Dê um nome à receita.');
            }
            foreach (['pre_fervura' => 'Pré-fervura', 'og' => 'OG', 'fg' => 'FG'] as $k => $rot) {
                if ($x[$k] !== '' && ((float) $x[$k] < 0 || (float) $x[$k] > 40)) {
                    throw new RuntimeException($rot . ' em °Brix (ex.: 12,5). Se estiver em densidade (1,050), converta para °Brix.');
                }
            }
            if (($_FILES['imagem']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $antiga = $x['imagem'];
                $x['imagem'] = dh_receita_imagem_salvar($x['id'], $_FILES['imagem']);
                if ($antiga !== '' && ($f = dh_receita_imagem_caminho($antiga))) {
                    @unlink($f);
                }
            } elseif (!empty($_POST['tirar_imagem']) && $x['imagem'] !== '') {
                if ($f = dh_receita_imagem_caminho($x['imagem'])) {
                    @unlink($f);
                }
                $x['imagem'] = '';
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
            if ($f = dh_receita_imagem_caminho($r['receitas'][$i]['imagem'])) {
                @unlink($f);
            }
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
        <label><?= $alvo ? 'Atualizar pelo BeerSmith' : 'Importar do BeerSmith' ?> <small>(opcional: .bsmx ou BeerXML .xml; preenche tudo, menos o print)</small>
          <input type="file" name="arquivos[]" accept=".xml,.bsmx,.beerxml,text/xml,application/xml" <?= $alvo ? '' : 'multiple' ?> required></label>
        <button class="btn secundario">Importar</button>
      </form>
    <?php
}

// "PF 12 · OG 13,5 · FG 3 °Bx · lavagem 37 L"
function receitas_linha(array $x): string
{
    $p = [];
    foreach (['pre_fervura' => 'PF', 'og' => 'OG', 'fg' => 'FG'] as $k => $rot) {
        if ($x[$k] !== '') {
            $p[] = $rot . ' ' . dh_num($x[$k]);
        }
    }
    $t = $p ? implode(' · ', $p) . ' °Bx' : '';
    if ($x['lavagem_l'] !== '') {
        $t .= ($t !== '' ? ' · ' : '') . 'lavagem ' . dh_num($x['lavagem_l']) . ' L';
    }
    return $t;
}

function receitas_tela_lista(): void
{
    $r = dh_receitas();
    $cervejas = dh_brassagem_cervejas();
    $ids = array_column($cervejas, 'id');
    $soltas = array_filter($r['receitas'], fn($x) => !in_array($x['cerveja_id'], $ids, true));
    ?>
      <div class="titulo-acao"><h1>Receitas</h1></div>
      <p class="dica">Uma receita para cada cerveja: tamanho do lote, insumos, pré-fervura, OG e FG (°Brix), lavagem, cor, ABV, IBU e, se quiser, o print da receita. Toque na cerveja para preencher.</p>
      <?php if ($soltas): ?>
        <h2>Sem cerveja ligada</h2>
        <ul class="lista-lotes">
          <?php foreach ($soltas as $x): ?>
            <li><a href="./?acao=receita&amp;id=<?= e($x['id']) ?>"><strong><?= e($x['nome']) ?></strong><span>escolha a cerveja do cadastro</span><em class="tag alerta">Ligar à cerveja</em></a></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <ul class="lista-lotes lista-receitas">
        <?php foreach ($cervejas as $c): $x = dh_receita_da_cerveja($r, $c['id']); ?>
          <li><a href="<?= $x ? './?acao=receita&amp;id=' . e($x['id']) : './?acao=receita&amp;cerveja=' . e(rawurlencode($c['id'])) ?>">
            <?php if (!empty($c['imagem'])): ?><img src="../<?= e($c['imagem']) ?>" alt="" width="40" height="40" loading="lazy"><?php endif; ?>
            <strong><?= e($c['nome']) ?></strong>
            <?php if ($x): ?>
              <span><?= e(receitas_linha($x) ?: count($x['insumos']) . ' insumos') ?><?= $x['imagem'] !== '' ? ' · 🖼 print' : '' ?></span>
              <em class="tag ct-ok">Receita ✓</em>
            <?php else: ?>
              <span>Sem receita</span><em class="tag cinza">Adicionar</em>
            <?php endif; ?>
          </a></li>
        <?php endforeach; ?>
      </ul>
      <?php receitas_form_importar(); ?>
    <?php
}

function receitas_tela_receita(): void
{
    global $csrf, $erro;
    $r = dh_receitas();
    $i = dh_brassagem_indice($r['receitas'], (string) ($_GET['id'] ?? $_POST['id'] ?? ''));
    $x = $i === null ? array_merge(dh_receita_vazia(), ['cerveja_id' => (string) ($_GET['cerveja'] ?? $_POST['cerveja_id'] ?? '')]) : $r['receitas'][$i];
    $cervejas = dh_brassagem_cervejas();
    $cv = array_column($cervejas, null, 'id')[$x['cerveja_id']] ?? null;
    if ($i === null && $cv) {
        $x['nome'] = $cv['nome'];
    }
    if ($erro && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['acao'] ?? '') === 'receita-salvar') {
        $x = array_merge($x, array_intersect_key($_POST, array_flip(['nome', 'cerveja_id', 'pre_fervura', 'og', 'fg', 'lavagem_l', 'volume_l', 'cor_ebc', 'abv', 'ibu'])));
        $x['insumos'] = array_values(array_filter((array) ($_POST['insumos'] ?? []), fn($it) => trim((string) ($it['nome'] ?? '')) !== ''));
    }
    $n = fn($k) => e(($x[$k] ?? '') !== '' && is_numeric($x[$k]) ? dh_num($x[$k]) : ($x[$k] ?? ''));
    $linhas = array_merge($x['insumos'], array_fill(0, $x['insumos'] ? 2 : 6, ['qtd' => '', 'unidade' => '', 'nome' => '']));
    ?>
      <p class="sem-impressao"><a class="voltar" href="./?acao=receitas">‹ Voltar para Receitas</a></p>
      <?php if ($i === null): ?>
        <h1>Nova receita<?= $cv ? ' · ' . e($cv['nome']) : '' ?></h1>
      <?php else: ?>
        <div class="titulo-acao"><h1><?= e($x['nome']) ?></h1><button type="button" class="btn secundario sem-impressao" onclick="window.print()">🖨 Imprimir</button></div>
        <p class="dica"><?= $cv ? 'Receita da <b>' . e($cv['nome']) . '</b>' : '<b style="color:var(--erro)">Sem cerveja ligada</b>' ?><?= !empty($x['atualizado_em']) ? ' · atualizada em ' . e(date('d/m/Y', strtotime($x['atualizado_em']))) : '' ?></p>
        <?php receitas_resumo($x); ?>
      <?php endif; ?>

      <details class="sem-impressao caderno-editar" <?= $i === null || $erro ? 'open' : '' ?>>
        <summary class="<?= $i === null ? 'escondido' : '' ?>">✏️ Editar receita</summary>
        <form method="post" action="./?acao=receita-salvar" class="form-cerveja" enctype="multipart/form-data">
          <?= $csrf ?><input type="hidden" name="id" value="<?= e($x['id']) ?>">
          <div class="duas">
            <label>Cerveja do cadastro
              <select name="cerveja_id"><option value="">— Nenhuma —</option>
                <?php foreach ($cervejas as $c): ?><option value="<?= e($c['id']) ?>" <?= $c['id'] === $x['cerveja_id'] ? 'selected' : '' ?>><?= e($c['nome']) ?></option><?php endforeach; ?>
              </select>
            </label>
            <label>Nome da receita <input name="nome" required value="<?= e($x['nome']) ?>"></label>
          </div>
          <label>Tamanho do lote <small>(litros)</small><input name="volume_l" inputmode="decimal" value="<?= $n('volume_l') ?>"></label>
          <h3>Insumos</h3>
          <div id="linhas-insumo">
            <?php foreach ($linhas as $k => $it): ?>
              <div class="linha-insumo">
                <input name="insumos[<?= $k ?>][qtd]" inputmode="decimal" value="<?= e($it['qtd'] !== '' && is_numeric($it['qtd']) ? dh_num($it['qtd']) : $it['qtd']) ?>" placeholder="Qtd" aria-label="Quantidade">
                <input name="insumos[<?= $k ?>][unidade]" list="unidades-insumo" value="<?= e($it['unidade']) ?>" placeholder="kg" aria-label="Unidade">
                <input name="insumos[<?= $k ?>][nome]" value="<?= e($it['nome']) ?>" placeholder="Ex.: Malte Pilsen" aria-label="Insumo">
                <button type="button" class="tirar-insumo" aria-label="Remover insumo">×</button>
              </div>
            <?php endforeach; ?>
          </div>
          <datalist id="unidades-insumo"><option value="kg"><option value="g"><option value="L"><option value="ml"><option value="pct"><option value="un"></datalist>
          <button type="button" class="link" id="mais-insumo">+ Adicionar insumo</button>
          <div class="quatro receita-numeros">
            <label>Pré-fervura <small>(°Brix)</small><input name="pre_fervura" inputmode="decimal" value="<?= $n('pre_fervura') ?>"></label>
            <label>OG <small>(°Brix)</small><input name="og" inputmode="decimal" value="<?= $n('og') ?>"></label>
            <label>FG <small>(°Brix lido no refratômetro)</small><input name="fg" inputmode="decimal" value="<?= $n('fg') ?>"></label>
            <label>Lavagem <small>(litros)</small><input name="lavagem_l" inputmode="decimal" value="<?= $n('lavagem_l') ?>"></label>
          </div>
          <div class="quatro receita-numeros">
            <label>Cor <small>(EBC)</small><input name="cor_ebc" inputmode="decimal" value="<?= $n('cor_ebc') ?>"></label>
            <label>ABV <small>(%)</small><input name="abv" inputmode="decimal" value="<?= $n('abv') ?>"></label>
            <label>IBU <input name="ibu" inputmode="decimal" value="<?= $n('ibu') ?>"></label>
          </div>
          <label>Print da receita <small>(opcional: foto ou captura do BeerSmith)</small><input type="file" name="imagem" accept="image/jpeg,image/png,image/webp"></label>
          <?php if ($x['imagem'] !== ''): ?><label class="check"><input type="checkbox" name="tirar_imagem" value="1"> Tirar o print atual</label><?php endif; ?>
          <button class="btn primario largo">Salvar receita</button>
        </form>
        <script>
          (function () {
            var caixa = document.getElementById('linhas-insumo');
            document.getElementById('mais-insumo').addEventListener('click', function () {
              var nova = caixa.querySelector('.linha-insumo').cloneNode(true), n = Date.now();
              nova.querySelectorAll('input').forEach(function (i) { i.value = ''; i.name = i.name.replace(/\[\d+\]/, '[' + n + ']'); });
              caixa.appendChild(nova); nova.querySelector('input').focus();
            });
            caixa.addEventListener('click', function (ev) {
              var b = ev.target.closest('.tirar-insumo'); if (!b) return;
              var l = b.closest('.linha-insumo');
              if (caixa.querySelectorAll('.linha-insumo').length > 1) l.remove(); else l.querySelectorAll('input').forEach(function (i) { i.value = ''; });
            });
          })();
        </script>
      </details>

      <?php if ($i !== null): ?>
        <div class="sem-impressao">
          <?php receitas_form_importar($x['id']); ?>
          <?php if ($x['versoes']): ?>
            <details class="caderno-editar"><summary>Versões anteriores (<?= count($x['versoes']) ?>)</summary>
              <ul class="lista-lotes"><?php foreach ($x['versoes'] as $vv): ?><li><span><?= e(date('d/m/Y H:i', strtotime($vv['atualizado_em'] ?? 'now'))) ?> · <?= e($vv['nome']) ?> · <?= e(receitas_linha($vv)) ?> · <?= count($vv['insumos']) ?> insumos</span></li><?php endforeach; ?></ul>
            </details>
          <?php endif; ?>
          <form method="post" action="./?acao=receita-excluir" class="excluir" onsubmit="return confirm('Excluir a receita <?= e(addslashes($x['nome'])) ?>? Os lotes já feitos guardam a cópia dela.');"><?= $csrf ?><input type="hidden" name="id" value="<?= e($x['id']) ?>"><button class="btn perigo">🗑 Excluir receita</button></form>
        </div>
      <?php endif; ?>
    <?php
}

// Números, insumos e print (também usado no caderno do lote, com a cópia da receita)
function receitas_resumo(array $x): void
{
    $met = [];
    if (($x['volume_l'] ?? '') !== '') {
        $met[] = ['Tamanho do lote', dh_num($x['volume_l']) . ' L'];
    }
    foreach (['pre_fervura' => 'Pré-fervura', 'og' => 'OG'] as $k => $rot) {
        if (($x[$k] ?? '') !== '') {
            $met[] = [$rot, dh_num($x[$k]) . ' °Bx'];
        }
    }
    if (($x['fg'] ?? '') !== '') {
        // FG como o refratômetro lê (com álcool) e a densidade real corrigida
        $real = $x['og'] !== '' ? dh_refratometro_sg((float) $x['og'], (float) $x['fg']) : null;
        $met[] = ['FG no refratômetro', dh_num($x['fg']) . ' °Bx', $real ? 'real ≈ ' . number_format($real, 3, ',', '') . ' (' . dh_num(dh_brix_de_sg($real)) . ' °P)' : ''];
    }
    if (($x['lavagem_l'] ?? '') !== '') {
        $met[] = ['Lavagem', dh_num($x['lavagem_l']) . ' L'];
    }
    if (($x['cor_ebc'] ?? '') !== '') {
        $met[] = ['Cor', dh_num($x['cor_ebc']) . ' EBC (' . dh_num(round((float) $x['cor_ebc'] / 1.97, 1)) . ' SRM)'];
    }
    if (($x['abv'] ?? '') !== '') {
        $met[] = ['ABV', dh_num($x['abv']) . '%'];
    }
    if (($x['ibu'] ?? '') !== '') {
        $met[] = ['IBU', dh_num($x['ibu'])];
    }
    ?>
      <?php if ($met): ?>
        <div class="grade-metricas receita-metricas">
          <?php foreach ($met as $m): ?><div class="cartao-metrica"><span><?= e($m[0]) ?></span><strong><?= e($m[1]) ?></strong><?php if (($m[2] ?? '') !== ''): ?><small><?= e($m[2]) ?></small><?php endif; ?></div><?php endforeach; ?>
        </div>
      <?php endif; ?>
      <?php if ($x['insumos']): ?>
        <h3>Insumos</h3>
        <table class="tabela-receita insumos"><?php foreach ($x['insumos'] as $it): ?><tr><td><?= $it['qtd'] !== '' ? e(is_numeric($it['qtd']) ? dh_num($it['qtd']) : $it['qtd']) . ' ' . e($it['unidade']) : '' ?></td><td><?= e($it['nome']) ?></td></tr><?php endforeach; ?></table>
      <?php endif; ?>
      <?php if (($x['imagem'] ?? '') !== '' && $x['id'] !== ''): $src = './?acao=receita-imagem&amp;id=' . e(rawurlencode($x['id'])); ?>
        <h3>Print da receita</h3>
        <a href="<?= $src ?>" target="_blank" class="receita-print"><img src="<?= $src ?>" alt="Print da receita <?= e($x['nome']) ?>" loading="lazy"></a>
      <?php endif; ?>
    <?php
}
