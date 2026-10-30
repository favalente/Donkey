<?php
// Painel → Orçamentos: proposta antes do contrato. Puxa o cliente da base, sugere os itens (chope da
// tabela do delivery, frete, copos, tirador), gera o PDF no modelo da casa, manda o link para o
// cliente aprovar e vira contrato com um toque. Ver inc/orcamentos.php.

function orcamentos_ler_form(array $p): array
{
    $txt = fn($k, $max = 200) => mb_substr(trim((string) ($p[$k] ?? '')), 0, $max);
    $itens = [];
    foreach ((array) ($p['itens'] ?? []) as $it) {
        $d = mb_substr(trim((string) ($it['descricao'] ?? '')), 0, 160);
        if ($d === '') {
            continue;
        }
        $itens[] = ['descricao' => $d, 'detalhe' => mb_substr(trim((string) ($it['detalhe'] ?? '')), 0, 160), 'valor' => dh_valor($it['valor'] ?? ''),
            'quant' => mb_substr(trim((string) ($it['quant'] ?? '')), 0, 20), 'total' => dh_valor($it['total'] ?? '')];
    }
    return [
        'cliente' => ['nome' => $txt('contratante_nome', 120), 'doc' => dh_so_digitos($txt('contratante_doc', 30)), 'telefone' => $txt('contratante_telefone', 30),
            'email' => strtolower($txt('contratante_email', 120)), 'endereco' => $txt('contratante_endereco', 250)],
        'data_evento' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($p['data_evento'] ?? '')) ? $p['data_evento'] : '',
        'local' => '',
        'itens' => $itens,
        'desconto' => dh_valor($p['desconto'] ?? ''),
        'validade' => max(1, min(120, (int) ($p['validade'] ?? 15))),
        'banco' => mb_substr(trim(str_replace("\r", '', (string) ($p['banco'] ?? ''))), 0, 600),
        'observacoes' => mb_substr(trim(str_replace("\r", '', (string) ($p['observacoes'] ?? ''))), 0, 1000),
        'assinante' => $txt('assinante', 100),
    ];
}

// Campos do contrato a partir do orçamento: itens com quantidade em litros ("50 L") viram chope,
// o item de frete vira o frete e os demais (copos, tirador…) entram como adicionais com o preço do orçamento
function orcamentos_campos_contrato(array $k): array
{
    $f = array_merge(dh_contrato_campos_padrao('comodato'), ['contratante_nome' => $k['cliente']['nome'], 'contratante_doc' => $k['cliente']['doc'], 'contratante_telefone' => $k['cliente']['telefone'],
        'contratante_email' => $k['cliente']['email'], 'contratante_endereco' => $k['cliente']['endereco'], 'entrega_endereco' => $k['cliente']['endereco'],
        'entrega_data' => $k['data_evento'], 'devolucao_data' => $k['data_evento'] ? date('Y-m-d', strtotime($k['data_evento'] . ' +1 day')) : '', 'itens' => [], 'extras' => [], 'pagamento' => 'pix']);
    $produtos = dh_produtos_adicionais();
    foreach ($k['itens'] as $n => $it) {
        $total = dh_orcamento_item_total($it);
        $litros = preg_match('/^\s*(\d+(?:[.,]\d+)?)\s*l\s*$/i', $it['quant'], $m) ? str_replace(',', '.', $m[1]) : '';
        if ($litros !== '' && (float) $litros > 0) {
            $f['itens'][] = ['descricao' => trim(preg_replace('/^barril\s+(de\s+)?chope\s+/iu', '', $it['descricao'])), 'litros' => $litros,
                'valor_litro' => $it['valor'] !== '' ? $it['valor'] : number_format($total / (float) $litros, 2, '.', ''), 'comodato' => false];
        } elseif (stripos(dh_sem_acento($it['descricao']), 'frete') !== false) {
            $f['frete'] = number_format((float) $f['frete'] + $total, 2, '.', '');
        } elseif ($total > 0) {
            // Quantidade inteira ("2") vira a quantidade do adicional; senão, 1 pelo total da linha
            $qtd = preg_match('/^\s*(\d+)\s*$/', $it['quant'], $m) && (int) $m[1] > 0 ? (int) $m[1] : 1;
            $id = 'orc-' . $n;
            foreach ($produtos as $p) {
                if (dh_sem_acento(mb_strtolower($p['nome'])) === dh_sem_acento(mb_strtolower($it['descricao']))) {
                    $id = $p['id'];
                }
            }
            $f['extras'][] = ['id' => $id, 'descricao' => $it['descricao'], 'detalhe' => $it['detalhe'], 'valor' => number_format($total / $qtd, 2, '.', ''), 'unidade' => '', 'qtd' => $qtd];
        }
    }
    // Desconto do orçamento vai para o desconto do contrato (abatido do chope)
    if (dh_orcamento_desconto($k) > 0) {
        $f['desconto'] = number_format(dh_orcamento_desconto($k), 2, '.', '');
    }
    $f['observacoes'] = 'Pagamento: ' . DH_ORCAMENTO_PAGAMENTO . "\nConforme orçamento " . $k['numero'] . '.';
    return $f;
}

function orcamentos_acao(string $acao): bool
{
    global $usuario, $telaErro;
    switch ($acao) {
        case 'orcamentos':
        case 'orcamento':
        case 'orcamentos-config':
            exigir('contratos');
            return true;

        case 'orcamento-salvar':
            exigir_post();
            exigir('contratos');
            $telaErro = 'orcamento';
            $o = dh_orcamentos();
            $i = dh_orcamento_indice($o, (string) ($_POST['id'] ?? ''));
            $f = orcamentos_ler_form($_POST);
            if ($i === null) {
                $k = ['id' => dh_id_novo('o'), 'token' => bin2hex(random_bytes(16)), 'numero' => dh_orcamento_numero($o), 'status' => 'rascunho',
                    'criado_em' => date('Y-m-d H:i:s'), 'criado_por' => $usuario['nome'], 'emitido_em' => date('Y-m-d H:i:s')] + $f;
                $o['orcamentos'][] = $k;
            } else {
                if ($o['orcamentos'][$i]['status'] === 'contrato') {
                    throw new RuntimeException('Este orçamento já virou contrato. Use "Duplicar" para fazer outro.');
                }
                $k = array_merge($o['orcamentos'][$i], $f, ['emitido_em' => date('Y-m-d H:i:s'), 'editado_por' => $usuario['nome']]);
                $o['orcamentos'][$i] = $k;
            }
            dh_orcamentos_salvar($o);
            // Cliente com CPF/CNPJ válido entra (ou é atualizado) na base de clientes
            dh_cliente_registrar(['id' => $k['id'], 'campos' => ['contratante_doc' => $k['cliente']['doc'], 'contratante_nome' => $k['cliente']['nome'],
                'contratante_telefone' => $k['cliente']['telefone'], 'contratante_email' => $k['cliente']['email'], 'contratante_endereco' => $k['cliente']['endereco']]]);
            ir('./?acao=orcamento&id=' . $k['id'], 'Orçamento ' . $k['numero'] . ' salvo. Baixe o PDF ou envie o link para o cliente.');

        case 'orcamento-pdf':
            exigir('contratos');
            $o = dh_orcamentos();
            $i = dh_orcamento_indice($o, (string) ($_GET['id'] ?? ''));
            if ($i === null) {
                throw new RuntimeException('Orçamento não encontrado.');
            }
            $arq = dh_orcamento_pdf($o['orcamentos'][$i]);
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . dh_orcamento_pdf_nome($o['orcamentos'][$i]) . '"');
            header('Content-Length: ' . strlen($arq));
            echo $arq;
            exit;

        case 'orcamento-email':
            exigir_post();
            exigir('contratos');
            $telaErro = 'orcamentos';
            $o = dh_orcamentos();
            $i = dh_orcamento_indice($o, (string) ($_POST['id'] ?? ''));
            if ($i === null) {
                throw new RuntimeException('Orçamento não encontrado.');
            }
            $k = $o['orcamentos'][$i];
            $link = dh_url_site() . 'orcamento/?t=' . $k['token'];
            $ok = dh_email($k['cliente']['email'], 'Orçamento ' . $k['numero'] . ' · Donkey Head Cervejaria',
                '<p>Olá' . ($k['cliente']['nome'] !== '' ? ', ' . e($k['cliente']['nome']) : '') . '!</p><p>Segue o orçamento da Donkey Head Cervejaria' . ($k['data_evento'] ? ' para o seu evento de ' . e(dh_data_br($k['data_evento'])) : '') . ', no valor de <b>' . e(dh_moeda(dh_orcamento_total($k))) . '</b>, em PDF anexo.</p>'
                . '<p><a href="' . e($link) . '" style="display:inline-block;background:#e9a23b;color:#1a120a;padding:12px 20px;border-radius:999px;text-decoration:none;font-weight:bold">Ver e aprovar o orçamento</a></p>',
                $erroEmail, [['nome' => dh_orcamento_pdf_nome($k), 'tipo' => 'application/pdf', 'dados' => dh_orcamento_pdf($k)]]);
            if (!$ok) {
                throw new RuntimeException('O e-mail não saiu: ' . $erroEmail);
            }
            ir('./?acao=orcamentos', '✉️ Orçamento ' . $k['numero'] . ' enviado para ' . $k['cliente']['email'] . ' (com o PDF).');

        case 'orcamento-status':
            exigir_post();
            exigir('contratos');
            $o = dh_orcamentos();
            $i = dh_orcamento_indice($o, (string) ($_POST['id'] ?? ''));
            $st = (string) ($_POST['status'] ?? '');
            if ($i === null || !in_array($st, ['rascunho', 'recusado', 'aprovado'], true) || $o['orcamentos'][$i]['status'] === 'contrato') {
                throw new RuntimeException('Não foi possível mudar a situação.');
            }
            $o['orcamentos'][$i]['status'] = $st;
            dh_orcamentos_salvar($o);
            ir('./?acao=orcamentos', 'Orçamento ' . $o['orcamentos'][$i]['numero'] . ': ' . mb_strtolower(DH_ORCAMENTO_STATUS[$st]) . '.');

        case 'orcamento-duplicar':
            exigir_post();
            exigir('contratos');
            $o = dh_orcamentos();
            $i = dh_orcamento_indice($o, (string) ($_POST['id'] ?? ''));
            if ($i === null) {
                throw new RuntimeException('Orçamento não encontrado.');
            }
            $k = array_merge($o['orcamentos'][$i], ['id' => dh_id_novo('o'), 'token' => bin2hex(random_bytes(16)), 'numero' => dh_orcamento_numero($o), 'status' => 'rascunho',
                'criado_em' => date('Y-m-d H:i:s'), 'criado_por' => $usuario['nome'], 'emitido_em' => date('Y-m-d H:i:s')]);
            unset($k['aprovado'], $k['contrato_id']);
            $o['orcamentos'][] = $k;
            dh_orcamentos_salvar($o);
            ir('./?acao=orcamento&id=' . $k['id'], 'Cópia criada: ' . $k['numero'] . '.');

        case 'orcamento-excluir':
            exigir_post();
            exigir('contratos');
            $o = dh_orcamentos();
            $i = dh_orcamento_indice($o, (string) ($_POST['id'] ?? ''));
            if ($i !== null) {
                $num = $o['orcamentos'][$i]['numero'];
                array_splice($o['orcamentos'], $i, 1);
                dh_orcamentos_salvar($o);
                ir('./?acao=orcamentos', 'Orçamento ' . $num . ' excluído.');
            }
            ir('./?acao=orcamentos');

        case 'orcamento-contrato':
        case 'orcamento-pedido':
            // Contrato de venda de chope com comodato, já com cliente, chope, adicionais, frete e desconto.
            // "Virar contrato": rascunho que a Donkey revisa. "Cliente preenche": link de pedido com os itens
            // e os preços do orçamento travados; o cliente completa os dados, anexa o comprovante e assina.
            exigir_post();
            exigir('contratos');
            $o = dh_orcamentos();
            $i = dh_orcamento_indice($o, (string) ($_POST['id'] ?? ''));
            if ($i === null) {
                throw new RuntimeException('Orçamento não encontrado.');
            }
            $k = $o['orcamentos'][$i];
            if ($k['status'] === 'contrato') {
                throw new RuntimeException('Este orçamento já virou contrato. Para mudar, use "Duplicar".');
            }
            $paraCliente = $acao === 'orcamento-pedido';
            $f = orcamentos_campos_contrato($k);
            if ($paraCliente && !array_filter($f['itens'])) {
                throw new RuntimeException('O orçamento não tem chope em litros (ex.: "50 L" na quantidade). Sem isso, use "Virar contrato".');
            }
            $c = dh_contratos();
            $novo = ['id' => dh_id_novo('c'), 'token' => bin2hex(random_bytes(16)), 'numero' => '', 'tipo' => 'comodato', 'status' => $paraCliente ? 'preenchimento' : 'rascunho',
                'criado_por' => $usuario['nome'], 'criado_em' => date('Y-m-d H:i:s'), 'assinaturas' => [], 'campos' => $f, 'orcamento' => $k['numero']];
            if ($paraCliente) {
                $novo['campos']['travado'] = true;
                $novo['referencia'] = $k['cliente']['nome'] !== '' ? $k['cliente']['nome'] : $k['numero'];
            }
            $c['contratos'][] = $novo;
            dh_contratos_salvar($c);
            $o['orcamentos'][$i]['status'] = 'contrato';
            $o['orcamentos'][$i]['contrato_id'] = $novo['id'];
            dh_orcamentos_salvar($o);
            ir('./?acao=contrato&id=' . $novo['id'], $paraCliente
                ? 'Pedido criado a partir do orçamento ' . $k['numero'] . '. Envie o link: o cliente completa os dados, anexa o comprovante e assina.'
                : 'Contrato criado a partir do orçamento ' . $k['numero'] . '. Confira os itens, complete o que faltar e gere para assinatura.');

        case 'orcamentos-config-salvar':
            exigir_post();
            exigir('contratos');
            $o = dh_orcamentos();
            $o['config'] = ['validade' => max(1, min(120, (int) ($_POST['validade'] ?? 15))),
                'banco' => mb_substr(trim(str_replace("\r", '', (string) ($_POST['banco'] ?? ''))), 0, 600)];
            dh_orcamentos_salvar($o);
            ir('./?acao=orcamentos', 'Padrões do orçamento salvos.');
    }
    return false;
}

function orcamentos_tela(string $tela): bool
{
    if ($tela === 'orcamentos') {
        orcamentos_tela_lista();
        return true;
    }
    if ($tela === 'orcamento') {
        orcamentos_tela_form();
        return true;
    }
    if ($tela === 'orcamentos-config') {
        orcamentos_tela_config();
        return true;
    }
    return false;
}

function orcamentos_tela_lista(): void
{
    global $csrf;
    $o = dh_orcamentos();
    $lista = array_reverse($o['orcamentos']);
    ?>
      <div class="titulo-acao"><h1>Orçamentos</h1></div>
      <p class="dica">A proposta que vai antes do contrato. Puxe o cliente da base, monte os itens, mande o PDF ou o link para o cliente aprovar e, fechado, transforme em contrato com um toque.</p>
      <?php if (trim($o['config']['banco']) === ''): ?><p class="aviso">Primeiro passo: <a href="./?acao=orcamentos-config">cadastre os dados bancários</a> que vão nos orçamentos (ficam só no servidor).</p><?php endif; ?>
      <div class="novo-contrato"><a class="btn primario largo" href="./?acao=orcamento">+ Novo orçamento</a></div>
      <?php if (!$lista): ?><p class="dica">Nenhum orçamento ainda.</p><?php endif; ?>
      <div class="cards-contrato">
        <?php foreach ($lista as $k): $link = dh_url_site() . 'orcamento/?t=' . $k['token']; $vencido = $k['status'] === 'rascunho' && dh_orcamento_validade($k) < date('Y-m-d'); ?>
          <article class="card-contrato st-<?= $k['status'] === 'contrato' || $k['status'] === 'aprovado' ? 'assinado' : ($k['status'] === 'recusado' ? 'cancelado' : 'enviado') ?>">
            <a class="card-topo" href="./?acao=orcamento&amp;id=<?= e($k['id']) ?>">
              <strong><?= e($k['cliente']['nome'] ?: 'Sem nome') ?></strong>
              <span><?= e($k['numero']) ?></span>
              <span><?= $k['data_evento'] ? '📅 ' . e(dh_data_br($k['data_evento'])) . ' · ' : '' ?><strong class="valor"><?= e(dh_moeda(dh_orcamento_total($k))) ?></strong></span>
              <span class="tags"><em class="tag <?= in_array($k['status'], ['aprovado', 'contrato'], true) ? 'ct-ok' : ($k['status'] === 'recusado' ? 'ct-cancelado' : 'ct-enviado') ?>"><?= e(DH_ORCAMENTO_STATUS[$k['status']]) ?></em>
                <?php if ($vencido): ?><em class="tag cinza">Validade vencida</em><?php endif; ?></span>
            </a>
            <div class="card-botoes">
              <a class="btn primario" href="./?acao=orcamento-pdf&amp;id=<?= e($k['id']) ?>">⬇ PDF</a>
              <a class="btn secundario" target="_blank" rel="noopener" href="https://wa.me/<?= e(dh_whatsapp_numero($k['cliente']['telefone'])) ?>?text=<?= e(rawurlencode('Olá' . ($k['cliente']['nome'] !== '' ? ', ' . $k['cliente']['nome'] : '') . ", aqui é a Donkey Head Cervejaria.\n\nSegue o orçamento " . $k['numero'] . ' (' . dh_moeda(dh_orcamento_total($k)) . "). Você pode ver, baixar em PDF e aprovar por este link:\n" . $link)) ?>">📲 Enviar</a>
              <?php if (filter_var($k['cliente']['email'], FILTER_VALIDATE_EMAIL)): ?>
                <form method="post" action="./?acao=orcamento-email" onsubmit="return confirm('Enviar o orçamento com o PDF para <?= e(addslashes($k['cliente']['email'])) ?>?');"><?= $csrf ?><input type="hidden" name="id" value="<?= e($k['id']) ?>"><button class="btn secundario">✉️ E-mail</button></form>
              <?php endif; ?>
              <?php if ($k['status'] !== 'contrato'): ?>
                <form method="post" action="./?acao=orcamento-contrato" onsubmit="return confirm('Criar o contrato a partir do orçamento <?= e($k['numero']) ?>?');"><?= $csrf ?><input type="hidden" name="id" value="<?= e($k['id']) ?>"><button class="btn secundario">📝 Virar contrato</button></form>
                <form method="post" action="./?acao=orcamento-pedido" onsubmit="return confirm('Gerar o link para o cliente preencher os dados e assinar, com os itens e preços do orçamento <?= e($k['numero']) ?>?');"><?= $csrf ?><input type="hidden" name="id" value="<?= e($k['id']) ?>"><button class="btn secundario">🧾 Cliente preenche</button></form>
              <?php elseif (!empty($k['contrato_id'])): ?>
                <a class="btn secundario" href="./?acao=contrato&amp;id=<?= e($k['contrato_id']) ?>">📝 Ver contrato</a>
              <?php endif; ?>
              <form method="post" action="./?acao=orcamento-duplicar"><?= $csrf ?><input type="hidden" name="id" value="<?= e($k['id']) ?>"><button class="btn secundario">⧉ Duplicar</button></form>
              <?php if (in_array($k['status'], ['rascunho', 'aprovado'], true)): ?>
                <form method="post" action="./?acao=orcamento-status"><?= $csrf ?><input type="hidden" name="id" value="<?= e($k['id']) ?>"><input type="hidden" name="status" value="recusado"><button class="btn secundario">✗ Recusado</button></form>
              <?php endif; ?>
              <form method="post" action="./?acao=orcamento-excluir" onsubmit="return confirm('Excluir o orçamento <?= e($k['numero']) ?>?');"><?= $csrf ?><input type="hidden" name="id" value="<?= e($k['id']) ?>"><button class="btn perigo">🗑 Excluir</button></form>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
      <p><a class="voltar" href="./?acao=orcamentos-config">⚙ Padrões do orçamento (dados bancários e validade)</a></p>
    <?php
}

function orcamentos_tela_form(): void
{
    global $csrf, $erro;
    $o = dh_orcamentos();
    $i = dh_orcamento_indice($o, (string) ($_GET['id'] ?? $_POST['id'] ?? ''));
    $cfg = $o['config'];
    $k = $i !== null ? $o['orcamentos'][$i] : ['id' => '', 'numero' => '', 'status' => 'rascunho', 'cliente' => ['nome' => '', 'doc' => '', 'telefone' => '', 'email' => '', 'endereco' => ''],
        'data_evento' => '', 'local' => '', 'itens' => [], 'desconto' => '', 'validade' => $cfg['validade'], 'banco' => $cfg['banco'], 'observacoes' => '',
        'assinante' => dh_contratos()['config']['representante_nome'] ?? ''];
    if ($erro && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $k = array_merge($k, orcamentos_ler_form($_POST));
    }
    $c = $k['cliente'];
    $itens = array_pad($k['itens'], max(4, count($k['itens']) + 1), ['descricao' => '', 'detalhe' => '', 'valor' => '', 'quant' => '', 'total' => '']);
    $v = fn($x) => e(is_numeric($x) && strpos((string) $x, '.') !== false ? dh_num($x) : $x);
    $sug = dh_orcamento_sugestoes();
    ?>
      <p><a class="voltar" href="./?acao=orcamentos">‹ Voltar para Orçamentos</a></p>
      <h1><?= $k['numero'] ? 'Orçamento ' . e($k['numero']) : 'Novo orçamento' ?></h1>
      <?php if ($k['status'] === 'aprovado' && !empty($k['aprovado'])): ?><p class="aviso">✅ Aprovado pelo cliente (<?= e($k['aprovado']['nome']) ?>) em <?= e(date('d/m/Y H:i', strtotime($k['aprovado']['em']))) ?>.</p><?php endif; ?>
      <?php if ($k['status'] === 'contrato'): ?><p class="aviso">Este orçamento já virou contrato. Para mudar, use "Duplicar" na lista.</p><?php endif; ?>
      <form method="post" action="./?acao=orcamento-salvar" class="form-cerveja form-contrato" id="form-orc">
        <?= $csrf ?><input type="hidden" name="id" value="<?= e($k['id']) ?>">
        <p class="dica">Todos os campos são opcionais.</p>
        <h2>Para quem</h2>
        <?= contratos_campo_cliente('', 'Cliente da base', 'escolha para preencher os dados') ?>
        <label>Nome ou empresa <input name="contratante_nome" maxlength="120" value="<?= e($c['nome']) ?>"></label>
        <div class="duas">
          <label>CPF ou CNPJ <input name="contratante_doc" inputmode="numeric" value="<?= e($c['doc'] !== '' ? dh_cpf_formatar($c['doc']) : '') ?>"></label>
          <label>Data do evento <input type="date" name="data_evento" value="<?= e($k['data_evento']) ?>"></label>
        </div>
        <details class="contato-envio"<?= $c['telefone'] !== '' || $c['email'] !== '' ? ' open' : '' ?>>
          <summary>Contato para envio <small>(opcional; não aparece na proposta)</small></summary>
          <div class="duas">
            <label>WhatsApp <input name="contratante_telefone" inputmode="tel" value="<?= e($c['telefone']) ?>"></label>
            <label>E-mail <input type="email" name="contratante_email" value="<?= e($c['email']) ?>"></label>
          </div>
        </details>
        <input type="hidden" name="contratante_endereco" value="<?= e($c['endereco']) ?>">
        <h2>Itens</h2>
        <p class="dica">Escreva ou escolha uma sugestão (o chope já vem com o valor do litro da tabela do delivery). O total de cada item é valor × quantidade ("300L" conta como 300); se preferir, digite o total direto.</p>
        <datalist id="sug-itens"><?php foreach ($sug as $s): ?><option value="<?= e($s['descricao']) ?>"><?php endforeach; ?></datalist>
        <div id="itens-orc">
          <?php foreach ($itens as $n => $it): ?>
            <div class="linha-orc">
              <label>Descrição <input name="itens[<?= $n ?>][descricao]" list="sug-itens" value="<?= e($it['descricao']) ?>" class="it-desc"></label>
              <label>Detalhe <small>(opcional)</small><input name="itens[<?= $n ?>][detalhe]" value="<?= e($it['detalhe']) ?>" class="it-det" placeholder="Ex.: 17h às 21h"></label>
              <div class="tres">
                <label>Valor un. (R$) <input name="itens[<?= $n ?>][valor]" inputmode="decimal" value="<?= $v($it['valor']) ?>" class="it-valor"></label>
                <label>Quant. <input name="itens[<?= $n ?>][quant]" value="<?= e($it['quant']) ?>" class="it-quant" placeholder="Ex.: 300L ou 6"></label>
                <label>Total (R$) <input name="itens[<?= $n ?>][total]" inputmode="decimal" value="<?= $v($it['total']) ?>" class="it-total" placeholder="automático"></label>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <button type="button" class="btn secundario" id="mais-item">+ Adicionar item</button>
        <label>Desconto (R$) <small>(abatido no total)</small><input name="desconto" id="desconto-orc" inputmode="decimal" value="<?= $v($k['desconto'] ?? '') ?>" placeholder="Ex.: 200,00"></label>
        <p class="aviso" id="total-orc"></p>
        <h2>Condições</h2>
        <p class="dica">Pagamento: <strong><?= e(DH_ORCAMENTO_PAGAMENTO) ?></strong>.</p>
        <div class="duas">
          <label>Validade (dias) <input name="validade" inputmode="numeric" value="<?= (int) $k['validade'] ?>"></label>
          <label>Quem assina <input name="assinante" maxlength="100" value="<?= e($k['assinante'] ?? '') ?>"></label>
        </div>
        <label>Dados bancários <textarea name="banco" rows="5"><?= e($k['banco']) ?></textarea></label>
        <label>Observações <small>(opcional; aparecem abaixo dos itens)</small><textarea name="observacoes" rows="2"><?= e($k['observacoes']) ?></textarea></label>
        <?php if ($k['status'] !== 'contrato'): ?><div class="barra-salvar"><button class="btn primario">Salvar orçamento</button></div><?php endif; ?>
      </form>
      <?php if ($k['id']): ?>
        <p class="botoes-link"><a class="btn secundario" href="./?acao=orcamento-pdf&amp;id=<?= e($k['id']) ?>">⬇ Baixar PDF</a> <a class="btn secundario" target="_blank" href="../orcamento/?t=<?= e($k['token']) ?>">👁 Ver como o cliente vê</a></p>
      <?php endif; ?>
      <script>
        (function () {
          var sug = <?= json_encode(array_column($sug, null, 'descricao'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
          var caixa = document.getElementById('itens-orc');
          function num(x) { x = (x || '').trim(); if (x.indexOf(',') >= 0) x = x.replace(/\./g, '').replace(',', '.'); return parseFloat(x.replace(/[^\d.]/g, '')) || 0; }
          function brl(n) { return n.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }); }
          function total() {
            var t = 0;
            caixa.querySelectorAll('.linha-orc').forEach(function (l) {
              var tot = l.querySelector('.it-total').value.trim();
              var sub = tot !== '' ? num(tot) : num(l.querySelector('.it-valor').value) * num(l.querySelector('.it-quant').value);
              l.querySelector('.it-total').placeholder = sub ? brl(sub) : 'automático';
              t += sub;
            });
            var d = Math.min(t, num(document.getElementById('desconto-orc').value));
            document.getElementById('total-orc').textContent = d ? 'Subtotal ' + brl(t) + ' − desconto ' + brl(d) + ' = Total do orçamento: ' + brl(t - d) : 'Total do orçamento: ' + brl(t);
          }
          caixa.addEventListener('change', function (e) {
            if (!e.target.classList.contains('it-desc') || !sug[e.target.value]) return;
            var s = sug[e.target.value], l = e.target.closest('.linha-orc');
            if (!l.querySelector('.it-det').value) l.querySelector('.it-det').value = s.detalhe;
            if (s.valor && !l.querySelector('.it-valor').value) l.querySelector('.it-valor').value = String(s.valor).replace('.', ',');
            if (s.unidade && !l.querySelector('.it-quant').value) l.querySelector('.it-quant').placeholder = 'Ex.: 50L';
            total();
          });
          document.getElementById('mais-item').addEventListener('click', function () {
            var n = Date.now(), nova = caixa.querySelector('.linha-orc').cloneNode(true);
            nova.querySelectorAll('input').forEach(function (i) { i.value = ''; i.name = i.name.replace(/itens\[\d+\]/, 'itens[' + n + ']'); });
            caixa.appendChild(nova);
          });
          document.getElementById('form-orc').addEventListener('input', total);
          total();
        })();
      </script>
    <?php
}

function orcamentos_tela_config(): void
{
    global $csrf;
    $cfg = dh_orcamentos()['config'];
    ?>
      <p><a class="voltar" href="./?acao=orcamentos">‹ Voltar para Orçamentos</a></p>
      <h1>Padrões do orçamento</h1>
      <p class="dica">Vêm preenchidos em cada orçamento novo (dá para mudar em cada um).</p>
      <form method="post" action="./?acao=orcamentos-config-salvar" class="form-cerveja">
        <?= $csrf ?>
        <p class="dica">Pagamento dos orçamentos: <strong><?= e(DH_ORCAMENTO_PAGAMENTO) ?></strong>.</p>
        <label>Validade (dias) <input name="validade" inputmode="numeric" value="<?= (int) $cfg['validade'] ?>"></label>
        <label>Dados bancários <small>(banco, agência, conta, titular e chave Pix; ficam só no servidor, não vão para o código do site)</small><textarea name="banco" rows="6" placeholder="Banco: …&#10;Agência: …&#10;Conta: …&#10;Titular e CNPJ (chave Pix)"><?= e($cfg['banco']) ?></textarea></label>
        <div class="barra-salvar"><button class="btn primario">Salvar</button></div>
      </form>
    <?php
}
