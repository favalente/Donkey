<?php
// Painel → Relatórios: métricas de venda de chope (barris) para ver onde melhorar.
// Mesmo padrão do painel do Reverti: período no topo, cartões de números, rankings e clientes inativos.

const DH_PERIODOS = ['12m' => 'Últimos 12 meses', '6m' => 'Últimos 6 meses', '3m' => 'Últimos 3 meses', 'ano' => 'Este ano', 'ano-passado' => 'Ano passado', 'tudo' => 'Todo o histórico'];
const DH_MESES_CURTOS = [1 => 'jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];

function relatorios_acao(string $acao): bool
{
    global $telaErro;
    switch ($acao) {
        case 'relatorios':
            exigir('contratos');
            return true;

        case 'relatorios-importar':
            exigir_post();
            exigir('contratos');
            $telaErro = 'relatorios';
            $arq = $_FILES['planilha'] ?? null;
            if (!$arq || ($arq['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($arq['tmp_name'])) {
                throw new RuntimeException('Escolha a planilha .xlsx baixada da ZapSign.');
            }
            $r = dh_zap_importar($arq['tmp_name']);
            ir('./?acao=relatorios&periodo=tudo', 'Planilha importada: ' . $r['novos'] . ' contratos novos, ' . $r['atualizados'] . ' atualizados'
                . ($r['duplicados'] ? ', ' . $r['duplicados'] . ' repetidos (contam uma vez só)' : '') . ($r['alertas'] ? '. ' . $r['alertas'] . ' foram corrigidos ou têm algo para conferir (veja no fim da página)' : '') . '. Os clientes entraram na base.');
    }
    return false;
}

function relatorios_tela(string $tela): bool
{
    if ($tela !== 'relatorios') {
        return false;
    }
    relatorios_tela_vendas();
    return true;
}

// Intervalo [de, ate] (Y-m-d) do período escolhido
function relatorios_periodo(string $p, string $de, string $ate): array
{
    $hoje = date('Y-m-d');
    switch ($p) {
        case '6m':
            return [date('Y-m-d', strtotime('-6 months +1 day')), $hoje];
        case '3m':
            return [date('Y-m-d', strtotime('-3 months +1 day')), $hoje];
        case 'ano':
            return [date('Y') . '-01-01', date('Y') . '-12-31'];
        case 'ano-passado':
            return [(date('Y') - 1) . '-01-01', (date('Y') - 1) . '-12-31'];
        case 'tudo':
            return ['2000-01-01', '2100-12-31'];
        case 'outro':
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $de) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $ate) && $de <= $ate) {
                return [$de, $ate];
            }
    }
    return [date('Y-m-d', strtotime('-12 months +1 day')), $hoje];
}

// Totais de uma lista de pedidos
function relatorios_totais(array $lista): array
{
    $comValor = array_filter($lista, fn($p) => $p['valor'] !== null);
    $comLitros = array_filter($lista, fn($p) => $p['litros']);
    $fat = array_sum(array_column($comValor, 'valor'));
    $litros = array_sum(array_column($comLitros, 'litros'));
    $fatComLitros = array_sum(array_map(fn($p) => (float) $p['valor'], array_filter($comLitros, fn($p) => $p['valor'] !== null)));
    $litrosComValor = array_sum(array_map(fn($p) => (float) $p['litros'], array_filter($comLitros, fn($p) => $p['valor'] !== null)));
    return [
        'pedidos' => count($lista),
        'faturamento' => $fat,
        'litros' => $litros,
        'ticket' => $comValor ? $fat / count($comValor) : 0,
        'litro_medio' => $litrosComValor ? $fatComLitros / $litrosComValor : 0,
        'clientes' => count(array_unique(array_map('dh_vendas_cliente_chave', $lista))),
    ];
}

// Barras horizontais de uma série (o número fica escrito ao lado; passar o dedo/mouse mostra o detalhe)
function relatorios_barras(array $itens, callable $fmt, ?callable $detalhe = null): string
{
    if (!$itens) {
        return '<p class="dica">Sem pedidos no período.</p>';
    }
    $max = max(array_map('floatval', $itens)) ?: 1;
    $h = '<div class="barras">';
    foreach ($itens as $rot => $val) {
        $dica = $detalhe ? $detalhe($rot, $val) : $rot . ': ' . $fmt($val);
        $h .= '<div class="barra-linha" title="' . e($dica) . '"><span class="barra-rotulo">' . e($rot) . '</span>'
            . '<span class="barra-trilho"><span class="barra" style="width:' . round(100 * (float) $val / $max, 1) . '%"></span></span>'
            . '<span class="barra-valor">' . e($fmt($val)) . '</span></div>';
    }
    return $h . '</div>';
}

function relatorios_cartao(string $rotulo, string $valor, string $dica = ''): string
{
    return '<div class="cartao-metrica"><p class="cm-rotulo">' . e($rotulo) . '</p><p class="cm-valor">' . e($valor) . '</p>' . ($dica !== '' ? '<p class="cm-dica">' . e($dica) . '</p>' : '') . '</div>';
}

function relatorios_variacao(float $agora, float $antes): string
{
    if ($antes <= 0) {
        return '';
    }
    $v = ($agora - $antes) / $antes * 100;
    return ($v >= 0 ? '▲ ' : '▼ ') . dh_num(round(abs($v))) . '% vs. mesmo período do ano anterior';
}

function relatorios_tela_vendas(): void
{
    global $csrf;
    $todos = dh_vendas_pedidos();
    $hist = dh_vendas_historico();
    $periodo = isset(DH_PERIODOS[$_GET['periodo'] ?? '']) || ($_GET['periodo'] ?? '') === 'outro' ? $_GET['periodo'] : '12m';
    [$de, $ate] = relatorios_periodo($periodo, (string) ($_GET['de'] ?? ''), (string) ($_GET['ate'] ?? ''));
    $top = in_array((int) ($_GET['top'] ?? 10), [5, 10, 20], true) ? (int) ($_GET['top'] ?? 10) : 10;
    $no = array_values(array_filter($todos, fn($p) => $p['data'] >= $de && $p['data'] <= $ate));
    $ant = array_values(array_filter($todos, fn($p) => $p['data'] >= date('Y-m-d', strtotime($de . ' -1 year')) && $p['data'] <= date('Y-m-d', strtotime($ate . ' -1 year'))));
    $t = relatorios_totais($no);
    $ta = relatorios_totais($ant);
    $temComparacao = $periodo !== 'tudo' && $ant;

    // Recompra: clientes do período que compraram mais de uma vez (em todo o histórico)
    $porCliente = [];
    foreach ($todos as $p) {
        $porCliente[dh_vendas_cliente_chave($p)][] = $p;
    }
    $clientesPeriodo = array_unique(array_map('dh_vendas_cliente_chave', $no));
    $recompra = count(array_filter($clientesPeriodo, fn($c) => count($porCliente[$c]) > 1));
    $empresas = array_filter($no, fn($p) => strlen($p['doc']) === 14);

    // Séries
    $porMes = [];
    foreach ($no as $p) {
        $m = substr($p['data'], 0, 7);
        $porMes[$m] = ($porMes[$m] ?? 0) + (float) $p['valor'];
    }
    ksort($porMes);
    $qtdMes = [];
    foreach ($no as $p) {
        $m = substr($p['data'], 0, 7);
        $qtdMes[$m] = ($qtdMes[$m] ?? 0) + 1;
    }
    $mesesRot = $qtdRot = [];
    foreach ($porMes as $m => $v) {
        $r = DH_MESES_CURTOS[(int) substr($m, 5)] . '/' . substr($m, 2, 2);
        $mesesRot[$r] = $v;
        $qtdRot[$r] = $qtdMes[$m];
    }
    // Sazonalidade: todo o histórico, por mês do ano
    $saz = array_fill_keys(array_values(DH_MESES_CURTOS), 0);
    foreach ($todos as $p) {
        $saz[DH_MESES_CURTOS[(int) substr($p['data'], 5, 2)]]++;
    }
    $dias = array_fill_keys(DH_DIAS_SEMANA, 0);
    foreach ($no as $p) {
        $dias[DH_DIAS_SEMANA[(int) date('w', strtotime($p['data']))]]++;
    }
    $faixas = ['30 L' => 0, '50 L' => 0, '51 a 100 L' => 0, 'Mais de 100 L' => 0, 'Não informado' => 0];
    $faixaValor = $faixas;
    foreach ($no as $p) {
        $f = dh_vendas_faixa($p['litros']);
        $faixas[$f]++;
        $faixaValor[$f] += (float) $p['valor'];
    }
    $faixas = array_filter($faixas);
    $rotulos = [];
    $rotLitros = [];
    foreach ($no as $p) {
        foreach ($p['rotulos'] ?: ['Não identificado'] as $r) {
            $rotulos[$r] = ($rotulos[$r] ?? 0) + 1;
            if (count($p['rotulos']) === 1 && $p['litros']) {
                $rotLitros[$r] = ($rotLitros[$r] ?? 0) + $p['litros'];
            }
        }
    }
    arsort($rotulos);
    $locais = [];
    foreach ($no as $p) {
        $l = $p['cidade'] !== 'Fortaleza' ? $p['cidade'] : ($p['bairro'] !== '' ? $p['bairro'] : 'Fortaleza (bairro não informado)');
        $locais[$l] = ($locais[$l] ?? 0) + 1;
    }
    arsort($locais);
    // Antecedência: dias entre o contrato e o evento
    $antec = [];
    foreach ($no as $p) {
        if ($p['criado'] !== '' && $p['data'] >= $p['criado']) {
            $antec[] = (int) round((strtotime($p['data']) - strtotime($p['criado'])) / 86400);
        }
    }
    sort($antec);
    $faixasAntec = ['No mesmo dia ou na véspera' => 0, '2 a 7 dias antes' => 0, '8 a 15 dias antes' => 0, 'Mais de 15 dias antes' => 0];
    foreach ($antec as $d) {
        $faixasAntec[$d <= 1 ? 'No mesmo dia ou na véspera' : ($d <= 7 ? '2 a 7 dias antes' : ($d <= 15 ? '8 a 15 dias antes' : 'Mais de 15 dias antes'))]++;
    }
    $mediana = $antec ? $antec[intdiv(count($antec), 2)] : null;

    // Ranking de clientes no período
    $rank = [];
    foreach ($no as $p) {
        $c = dh_vendas_cliente_chave($p);
        $rank[$c] ??= ['nome' => $p['nome'], 'telefone' => $p['telefone'], 'pedidos' => 0, 'valor' => 0, 'litros' => 0];
        $rank[$c]['pedidos']++;
        $rank[$c]['valor'] += (float) $p['valor'];
        $rank[$c]['litros'] += (float) $p['litros'];
    }
    uasort($rank, fn($a, $b) => $b['valor'] <=> $a['valor']);
    $rank = array_slice($rank, 0, $top, true);

    // Clientes sem comprar há muito tempo (não seguem o filtro de período, como no Reverti)
    $inativos = ['6' => [], '12' => [], '24' => []];
    foreach ($porCliente as $c => $ps) {
        $ultimo = end($ps);
        $meses = (strtotime(date('Y-m-d')) - strtotime($ultimo['data'])) / (30.4 * 86400);
        $info = ['nome' => $ultimo['nome'], 'telefone' => $ultimo['telefone'], 'ultimo' => $ultimo['data'], 'pedidos' => count($ps), 'valor' => array_sum(array_map(fn($p) => (float) $p['valor'], $ps))];
        foreach ([24, 12, 6] as $lim) {
            if ($meses >= $lim) {
                $inativos[(string) $lim][] = $info;
                break;
            }
        }
    }
    $filtroInativo = in_array($_GET['inativos'] ?? '', ['6', '12', '24'], true) ? $_GET['inativos'] : '';

    // Onde melhorar: leituras diretas dos números
    $dicas = [];
    if ($t['pedidos']) {
        $f30 = $faixas['30 L'] ?? 0;
        if ($f30 / $t['pedidos'] >= 0.4) {
            $dicas[] = '<strong>' . round(100 * $f30 / $t['pedidos']) . '% dos pedidos são de 30 L.</strong> Vale oferecer o barril de 50 L na conversa (ex.: um preço do litro menor ou copos de brinde a partir de 50 L): o ticket médio de 50 L é '
                . e(dh_moeda(($faixas['50 L'] ?? 0) ? $faixaValor['50 L'] / $faixas['50 L'] : 0)) . ' contra ' . e(dh_moeda($f30 ? $faixaValor['30 L'] / $f30 : 0)) . ' no de 30 L.';
        }
        $topRot = array_key_first($rotulos);
        if ($topRot && $rotulos[$topRot] / $t['pedidos'] >= 0.6) {
            $dicas[] = '<strong>' . e($topRot) . ' está em ' . round(100 * $rotulos[$topRot] / $t['pedidos']) . '% dos pedidos.</strong> Os outros rótulos quase não saem: dá para sugerir um segundo barril de outro estilo ou mandar uma degustação junto.';
        }
        if ($clientesPeriodo) {
            $dicas[] = '<strong>' . round(100 * $recompra / count($clientesPeriodo)) . '% dos clientes do período já compraram mais de uma vez.</strong> Quem comprou uma vez é o público mais fácil: veja a lista de clientes sem comprar abaixo e chame no WhatsApp antes das datas fortes.';
        }
        if ($mediana !== null) {
            $dicas[] = '<strong>Metade dos pedidos é fechada até ' . $mediana . ' dia' . ($mediana === 1 ? '' : 's') . ' antes do evento.</strong> Anúncios e mensagens funcionam melhor de 1 a 2 semanas antes de feriados e fins de semana fortes.';
        }
    }
    $sazOrd = $saz;
    arsort($sazOrd);
    $fortes = array_slice(array_keys(array_filter($sazOrd)), 0, 3);
    if ($fortes) {
        $dicas[] = '<strong>Meses mais fortes (todo o histórico): ' . e(implode(', ', $fortes)) . '.</strong> Comece a divulgar o chope delivery e a chamar clientes antigos um mês antes deles.';
    }
    $inat12 = count($inativos['12']) + count($inativos['24']);
    if ($inat12) {
        $dicas[] = '<strong>' . $inat12 . ' clientes não compram há mais de 1 ano.</strong> Uma mensagem com um cupom (Contratos → Cupons) pode trazer parte deles de volta.';
    }

    $link = fn(array $mais) => './?' . http_build_query(array_merge(['acao' => 'relatorios', 'periodo' => $periodo, 'de' => $_GET['de'] ?? null, 'ate' => $_GET['ate'] ?? null, 'top' => $top], $mais));
    $msgWhats = fn(string $nome) => 'Olá, ' . trim(explode(' ', $nome)[0]) . "! Aqui é a Donkey Head Cervejaria.\n\nTem algum evento vindo aí? Levamos o chope até você, com chopeira e tudo. Quer que eu monte um orçamento?";
    ?>
      <div class="titulo-acao"><h1>Relatórios <small>vendas de chope</small></h1></div>
      <p class="dica">Pedidos de chope em barril: contratos importados da ZapSign + pedidos assinados aqui no site. Valores pela data do evento.</p>

      <form method="get" class="filtro-periodo">
        <input type="hidden" name="acao" value="relatorios"><input type="hidden" name="top" value="<?= $top ?>">
        <label>Período
          <select name="periodo" onchange="this.form.querySelector('.datas').hidden = this.value !== 'outro'; if (this.value !== 'outro') this.form.submit();">
            <?php foreach (DH_PERIODOS as $k => $rot): ?><option value="<?= $k ?>" <?= $k === $periodo ? 'selected' : '' ?>><?= e($rot) ?></option><?php endforeach; ?>
            <option value="outro" <?= $periodo === 'outro' ? 'selected' : '' ?>>Escolher datas…</option>
          </select>
        </label>
        <span class="datas" <?= $periodo === 'outro' ? '' : 'hidden' ?>>
          <label>De <input type="date" name="de" value="<?= e($_GET['de'] ?? '') ?>"></label>
          <label>Até <input type="date" name="ate" value="<?= e($_GET['ate'] ?? '') ?>"></label>
          <button class="btn primario">Ver</button>
        </span>
      </form>
      <p class="dica"><?= $periodo === 'tudo' ? 'Todo o histórico' : e(dh_data_br($de) . ' a ' . dh_data_br($ate)) ?></p>

      <?php if (!$todos): ?>
        <p class="aviso">Ainda não há vendas para analisar. Importe a planilha da ZapSign no fim desta página.</p>
      <?php endif; ?>

      <div class="grade-metricas">
        <?= relatorios_cartao('Faturamento', dh_moeda($t['faturamento']), $temComparacao ? relatorios_variacao($t['faturamento'], $ta['faturamento']) : '') ?>
        <?= relatorios_cartao('Pedidos', (string) $t['pedidos'], $temComparacao ? relatorios_variacao($t['pedidos'], $ta['pedidos']) : '') ?>
        <?= relatorios_cartao('Litros vendidos', number_format($t['litros'], 0, ',', '.') . ' L', $t['pedidos'] ? 'média de ' . dh_num(round($t['litros'] / max(1, count(array_filter($no, fn($p) => $p['litros']))))) . ' L por pedido' : '') ?>
        <?= relatorios_cartao('Ticket médio', dh_moeda($t['ticket']), $temComparacao ? relatorios_variacao($t['ticket'], $ta['ticket']) : '') ?>
        <?= relatorios_cartao('Valor médio do litro', dh_moeda($t['litro_medio'])) ?>
        <?= relatorios_cartao('Clientes', (string) $t['clientes'], $clientesPeriodo ? $recompra . ' já compraram mais de uma vez' : '') ?>
        <?= relatorios_cartao('Empresas (CNPJ)', (string) count($empresas), $t['pedidos'] ? round(100 * count($empresas) / $t['pedidos']) . '% dos pedidos' : '') ?>
        <?= relatorios_cartao('Com chope consignado', (string) count(array_filter($no, fn($p) => $p['consignado'])), 'barris extras em comodato') ?>
      </div>

      <?php if ($dicas): ?>
        <section class="caixa-relatorio dicas-vendas">
          <h2>💡 Onde melhorar</h2>
          <ul><?php foreach ($dicas as $d): ?><li><?= $d ?></li><?php endforeach; ?></ul>
        </section>
      <?php endif; ?>

      <section class="caixa-relatorio">
        <h2>Faturamento por mês</h2>
        <?= relatorios_barras($mesesRot, fn($v) => dh_moeda($v), fn($rot, $v) => $rot . ': ' . dh_moeda($v) . ' em ' . $qtdRot[$rot] . ' pedido(s)') ?>
      </section>

      <section class="caixa-relatorio">
        <h2>Em que época do ano vende mais <small>(pedidos, todo o histórico)</small></h2>
        <?= relatorios_barras($saz, fn($v) => (string) $v, fn($r, $v) => "$r: $v pedido(s) somando todos os anos") ?>
      </section>

      <div class="duas-colunas">
        <section class="caixa-relatorio">
          <h2>Tamanho do pedido</h2>
          <?= relatorios_barras($faixas, fn($v) => $v . ' pedido' . ($v == 1 ? '' : 's'), fn($r, $v) => "$r: $v pedido(s), ticket médio " . dh_moeda($v ? $faixaValor[$r] / $v : 0)) ?>
        </section>
        <section class="caixa-relatorio">
          <h2>Dia da semana do evento</h2>
          <?= relatorios_barras($dias, fn($v) => (string) $v) ?>
        </section>
        <section class="caixa-relatorio">
          <h2>Rótulos</h2>
          <?= relatorios_barras($rotulos, fn($v) => $v . ' pedido' . ($v == 1 ? '' : 's'), fn($r, $v) => "$r: em $v pedido(s)" . (isset($rotLitros[$r]) ? ', ' . number_format($rotLitros[$r], 0, ',', '.') . ' L nos pedidos só desse rótulo' : '')) ?>
        </section>
        <section class="caixa-relatorio">
          <h2>Antecedência do pedido</h2>
          <?= relatorios_barras(array_filter($faixasAntec), fn($v) => (string) $v, fn($r, $v) => "$r: $v pedido(s)") ?>
        </section>
      </div>

      <section class="caixa-relatorio">
        <h2>Onde foram os eventos</h2>
        <?= relatorios_barras(array_slice($locais, 0, 15, true), fn($v) => (string) $v, fn($r, $v) => "$r: $v pedido(s)") ?>
        <?php if (count($locais) > 15): ?><p class="dica">E mais <?= count($locais) - 15 ?> locais com menos pedidos.</p><?php endif; ?>
      </section>

      <section class="caixa-relatorio">
        <div class="titulo-acao"><h2>Clientes que mais compraram</h2>
          <span class="abas-mini"><?php foreach ([5, 10, 20] as $n): ?><a href="<?= e($link(['top' => $n])) ?>" class="<?= $n === $top ? 'ativo' : '' ?>">Top <?= $n ?></a><?php endforeach; ?></span>
        </div>
        <?php if (!$rank): ?><p class="dica">Sem pedidos no período.</p><?php else: ?>
          <ol class="ranking">
            <?php foreach ($rank as $r): ?>
              <li><span class="info"><strong><?= e($r['nome']) ?></strong><span><?= $r['pedidos'] ?> pedido<?= $r['pedidos'] > 1 ? 's' : '' ?> · <?= number_format($r['litros'], 0, ',', '.') ?> L · <?= e(dh_moeda($r['valor'])) ?></span></span>
                <?php if (dh_whatsapp_numero($r['telefone']) !== ''): ?><a class="btn secundario" target="_blank" rel="noopener" href="https://wa.me/<?= e(dh_whatsapp_numero($r['telefone'])) ?>">WhatsApp</a><?php endif; ?></li>
            <?php endforeach; ?>
          </ol>
        <?php endif; ?>
      </section>

      <section class="caixa-relatorio">
        <h2>Clientes sem comprar <small>(não segue o período)</small></h2>
        <div class="grade-metricas tres">
          <?php foreach (['6' => 'Há 6 a 12 meses', '12' => 'Há 1 a 2 anos', '24' => 'Há mais de 2 anos'] as $k => $rot): ?>
            <a href="<?= e($link(['inativos' => $filtroInativo === $k ? null : $k])) ?>#inativos" class="<?= $filtroInativo === $k ? 'ativo' : '' ?>"><?= relatorios_cartao($rot, (string) count($inativos[$k]), $filtroInativo === $k ? 'toque para fechar' : 'toque para ver') ?></a>
          <?php endforeach; ?>
        </div>
        <?php if ($filtroInativo !== ''): $lista = $inativos[$filtroInativo]; usort($lista, fn($a, $b) => $b['valor'] <=> $a['valor']); ?>
          <ol class="ranking" id="inativos">
            <?php foreach ($lista as $r): ?>
              <li><span class="info"><strong><?= e($r['nome']) ?></strong><span>último pedido em <?= e(dh_data_br($r['ultimo'])) ?> · <?= $r['pedidos'] ?> pedido<?= $r['pedidos'] > 1 ? 's' : '' ?> · <?= e(dh_moeda($r['valor'])) ?></span></span>
                <?php if (dh_whatsapp_numero($r['telefone']) !== ''): ?><a class="btn secundario" target="_blank" rel="noopener" href="https://wa.me/<?= e(dh_whatsapp_numero($r['telefone'])) ?>?text=<?= e(rawurlencode($msgWhats($r['nome']))) ?>">Chamar no WhatsApp</a><?php endif; ?></li>
            <?php endforeach; ?>
          </ol>
        <?php endif; ?>
      </section>

      <section class="caixa-relatorio">
        <h2>Importar contratos da ZapSign</h2>
        <p class="dica">Na ZapSign, baixe as respostas do modelo de contrato (planilha .xlsx) e envie aqui. Pode reenviar a planilha atualizada quando quiser: os contratos já importados são atualizados, não duplicam. Os clientes também entram na base de Clientes.</p>
        <?php if ($hist['importado_em'] !== ''): ?><p>Última importação: <strong><?= e(date('d/m/Y H:i', strtotime($hist['importado_em']))) ?></strong> · <?= count($hist['pedidos']) ?> contratos (<?= count(array_filter($hist['pedidos'], fn($p) => $p['duplicado'])) ?> repetidos, contados uma vez só).</p><?php endif; ?>
        <form method="post" action="./?acao=relatorios-importar" enctype="multipart/form-data" class="form-cerveja">
          <?= $csrf ?>
          <label>Planilha da ZapSign (.xlsx) <input type="file" name="planilha" accept=".xlsx" required></label>
          <button class="btn primario largo">⬆ Importar planilha</button>
        </form>
        <?php $comAlerta = array_filter($hist['pedidos'], fn($p) => $p['alertas'] && !$p['duplicado']); ?>
        <?php if ($comAlerta): ?>
          <details class="alertas-importacao">
            <summary><?= count($comAlerta) ?> contratos importados com correção ou algo para conferir</summary>
            <ul>
              <?php foreach ($comAlerta as $p): ?>
                <li><strong><?= e(dh_data_br($p['data'])) ?> · <?= e($p['nome']) ?></strong> (Doc <?= e(substr($p['id'], 4)) ?>) · <?= e(mb_substr($p['descricao'], 0, 60)) ?><br><span><?= e(implode('; ', $p['alertas'])) ?></span></li>
              <?php endforeach; ?>
            </ul>
          </details>
        <?php endif; ?>
      </section>
    <?php
}
