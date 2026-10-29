<?php
// Painel: contratos com assinatura eletrônica (evento corporativo e comodato de equipamentos).
// Rascunho pode ser editado; ao enviar, o texto é congelado. A Donkey assina com a senha do
// administrador e o cliente pelo link (código por e-mail). Ver inc/contratos.php.

// Lê o formulário do contrato (só os campos do tipo)
function dh_contrato_ler_form(string $tipo, array $p): array
{
    $f = dh_contrato_campos_padrao($tipo);
    $txt = fn($k, $max = 200) => mb_substr(trim((string) ($p[$k] ?? '')), 0, $max);
    foreach (['contratante_nome', 'contratante_doc', 'contratante_endereco', 'contratante_telefone', 'contratante_email'] as $k) {
        $f[$k] = $txt($k);
    }
    $f['pagamento'] = isset(DH_PAGAMENTOS[$p['pagamento'] ?? '']) ? $p['pagamento'] : '';
    if ($tipo === 'evento') {
        foreach (['espaco', 'data', 'hora_inicio', 'hora_fim', 'atracao', 'responsavel_nome', 'responsavel_telefone', 'responsavel_email'] as $k) {
            $f[$k] = $txt($k);
        }
        foreach (['valor_pessoa', 'valor_adicional', 'total'] as $k) {
            $f[$k] = dh_valor($p[$k] ?? '');
        }
        $f['convidados'] = (string) max(0, (int) ($p['convidados'] ?? 0)) ?: '';
        $f['max_participantes'] = (string) max(0, (int) ($p['max_participantes'] ?? 0)) ?: '';
        $f['adicionais'] = array_values(array_intersect(array_keys(DH_EVENTO_ADICIONAIS), (array) ($p['adicionais'] ?? [])));
        $f['adicionais'] = array_fill_keys($f['adicionais'], true);
        $f['cardapio'] = mb_substr(trim((string) ($p['cardapio'] ?? '')), 0, 8000);
        return $f;
    }
    foreach (['entrega_endereco', 'entrega_data', 'entrega_hora', 'devolucao_data', 'devolucao_hora'] as $k) {
        $f[$k] = $txt($k);
    }
    $f['frete'] = dh_valor($p['frete'] ?? '');
    $f['observacoes'] = mb_substr(trim((string) ($p['observacoes'] ?? '')), 0, 2000);
    $f['itens'] = [];
    foreach ((array) ($p['itens'] ?? []) as $it) {
        $d = mb_substr(trim((string) ($it['descricao'] ?? '')), 0, 200);
        if ($d !== '') {
            $f['itens'][] = ['descricao' => $d, 'litros' => dh_valor($it['litros'] ?? ''), 'valor_litro' => dh_valor($it['valor_litro'] ?? ''), 'comodato' => !empty($it['comodato'])];
        }
    }
    $f['equipamentos'] = [];
    foreach ((array) ($p['equipamentos'] ?? []) as $eq) {
        $d = mb_substr(trim((string) ($eq['descricao'] ?? '')), 0, 200);
        if ($d !== '' && !empty($eq['incluir'])) {
            $f['equipamentos'][] = ['descricao' => $d, 'valor' => dh_valor($eq['valor'] ?? ''), 'quantidade' => max(1, (int) ($eq['quantidade'] ?? 1))];
        }
    }
    return $f;
}

function contratos_acao(string $acao): bool
{
    global $usuario, $usuarios, $telaErro;
    switch ($acao) {
        case 'contratos':
        case 'contrato':
        case 'contratos-config':
            exigir('contratos');
            return true;

        case 'contrato-salvar':
            exigir_post();
            exigir('contratos');
            $telaErro = 'contrato';
            $c = dh_contratos();
            $id = (string) ($_POST['id'] ?? '');
            $tipo = isset(DH_CONTRATO_TIPOS[$_POST['tipo'] ?? '']) ? $_POST['tipo'] : 'evento';
            $i = $id !== '' ? dh_contrato_indice($c, $id) : null;
            if ($i !== null && $c['contratos'][$i]['status'] !== 'rascunho') {
                throw new RuntimeException('Este contrato já foi enviado e não pode mais ser alterado. Use "Duplicar" para fazer um novo.');
            }
            $k = $i !== null ? $c['contratos'][$i] : ['id' => dh_id_novo('c'), 'token' => bin2hex(random_bytes(16)), 'numero' => '', 'tipo' => $tipo, 'status' => 'rascunho',
                'criado_por' => $usuario['nome'], 'criado_em' => date('Y-m-d H:i:s'), 'assinaturas' => []];
            $k['campos'] = dh_contrato_ler_form($k['tipo'], $_POST);
            $enviar = ($_POST['op'] ?? '') === 'enviar';
            if ($enviar) {
                $faltam = dh_contrato_pendencias($k);
                if ($faltam) {
                    // Guarda o rascunho mesmo assim, para não perder o que foi digitado
                    if ($i === null) {
                        $c['contratos'][] = $k;
                    } else {
                        $c['contratos'][$i] = $k;
                    }
                    dh_contratos_salvar($c);
                    ir('./?acao=contrato&id=' . $k['id'], 'Rascunho salvo. Para enviar, falta: ' . implode(', ', $faltam) . '.');
                }
                if (empty($c['config']['representante_cpf'])) {
                    throw new RuntimeException('Antes de enviar, preencha o nome e o CPF de quem assina pela Donkey em Contratos → Configurações.');
                }
                $k['numero'] = dh_contrato_numero($c);
                dh_contrato_congelar($k, $c['config']);
            }
            if ($i === null) {
                $c['contratos'][] = $k;
            } else {
                $c['contratos'][$i] = $k;
            }
            dh_contratos_salvar($c);
            ir('./?acao=contrato&id=' . $k['id'], $enviar ? 'Contrato nº ' . $k['numero'] . ' pronto para assinatura. Assine pela Donkey e envie o link ao cliente.' : 'Rascunho salvo.');

        case 'contrato-pedido':
            exigir_post();
            exigir('contratos');
            $c = dh_contratos();
            $k = ['id' => dh_id_novo('c'), 'token' => bin2hex(random_bytes(16)), 'numero' => '', 'tipo' => 'comodato', 'status' => 'preenchimento',
                'criado_por' => $usuario['nome'], 'criado_em' => date('Y-m-d H:i:s'), 'assinaturas' => [], 'campos' => dh_contrato_campos_padrao('comodato')];
            $k['campos']['itens'] = [];
            $k['campos']['pagamento'] = isset(DH_PAGAMENTOS[$_POST['pagamento'] ?? '']) ? $_POST['pagamento'] : 'pix';
            $k['campos']['frete'] = dh_valor($_POST['frete'] ?? '');
            $k['campos']['observacoes'] = mb_substr(trim((string) ($_POST['observacoes'] ?? '')), 0, 2000);
            $k['referencia'] = mb_substr(trim((string) ($_POST['referencia'] ?? '')), 0, 80);
            $c['contratos'][] = $k;
            dh_contratos_salvar($c);
            ir('./?acao=contrato&id=' . $k['id'], 'Pedido criado. Envie o link para o cliente preencher e assinar.');

        case 'contrato-assinar':
            exigir_post();
            exigir('contratos');
            $telaErro = 'contrato';
            if ($usuario['papel'] !== 'admin') {
                throw new RuntimeException('Só o administrador assina pela Donkey.');
            }
            $c = dh_contratos();
            $i = dh_contrato_indice($c, (string) ($_POST['id'] ?? ''));
            if ($i === null || $c['contratos'][$i]['status'] !== 'enviado') {
                throw new RuntimeException('Contrato não disponível para assinatura.');
            }
            if (!password_verify((string) ($_POST['senha'] ?? ''), $usuarios[$usuario['login']]['hash'])) {
                throw new RuntimeException('Senha incorreta. A assinatura não foi registrada.');
            }
            if (empty($_POST['aceite'])) {
                throw new RuntimeException('Marque que leu e concorda com o contrato.');
            }
            $cfg = $c['config'];
            $c['contratos'][$i]['assinaturas']['contratada'] = dh_contrato_assinatura($cfg['representante_nome'], $cfg['representante_cpf'], DH_EMPRESA['email'],
                'Senha do painel administrativo (usuário "' . $usuario['login'] . '") · representante da CONTRATADA', (string) ($_POST['geo'] ?? ''));
            $fechou = dh_contrato_concluir($c['contratos'][$i]);
            dh_contratos_salvar($c);
            ir('./?acao=contrato&id=' . $c['contratos'][$i]['id'], $fechou ? 'Contrato assinado pelas duas partes. As cópias foram enviadas por e-mail.' : 'Assinado pela Donkey. Agora falta o cliente.');

        case 'contrato-convite':
            exigir_post();
            exigir('contratos');
            $c = dh_contratos();
            $i = dh_contrato_indice($c, (string) ($_POST['id'] ?? ''));
            if ($i === null || $c['contratos'][$i]['status'] !== 'enviado') {
                throw new RuntimeException('Contrato não disponível.');
            }
            $k = $c['contratos'][$i];
            $ok = dh_email($k['campos']['contratante_email'], 'Contrato para assinatura nº ' . $k['numero'] . ' · Donkey Head Cervejaria',
                '<p>Olá, ' . e($k['campos']['contratante_nome']) . '!</p><p>Segue o contrato nº <b>' . e($k['numero']) . '</b> (' . e(DH_CONTRATO_TIPOS[$k['tipo']]) . ') para leitura e assinatura eletrônica:</p>'
                . '<p><a href="' . e(dh_url_site() . 'contrato/?t=' . $k['token']) . '" style="display:inline-block;background:#e9a23b;color:#1a120a;padding:12px 20px;border-radius:999px;text-decoration:none;font-weight:bold">Ler e assinar o contrato</a></p>');
            ir('./?acao=contrato&id=' . $k['id'], $ok ? 'Convite enviado para ' . $k['campos']['contratante_email'] . '.' : 'Não foi possível enviar o e-mail. Mande o link pelo WhatsApp.');

        case 'contrato-cancelar':
            exigir_post();
            exigir('contratos');
            $c = dh_contratos();
            $i = dh_contrato_indice($c, (string) ($_POST['id'] ?? ''));
            if ($i === null || $c['contratos'][$i]['status'] === 'assinado') {
                throw new RuntimeException('Contrato assinado não pode ser cancelado por aqui.');
            }
            if (in_array($c['contratos'][$i]['status'], ['rascunho', 'preenchimento'], true)) {
                array_splice($c['contratos'], $i, 1);
                dh_contratos_salvar($c);
                ir('./?acao=contratos', 'Excluído.');
            }
            $c['contratos'][$i]['status'] = 'cancelado';
            $c['contratos'][$i]['cancelado_em'] = date('Y-m-d H:i:s');
            $c['contratos'][$i]['cancelado_por'] = $usuario['nome'];
            dh_contratos_salvar($c);
            ir('./?acao=contrato&id=' . $c['contratos'][$i]['id'], 'Contrato cancelado. O link do cliente não aceita mais assinatura.');

        case 'contrato-duplicar':
            exigir_post();
            exigir('contratos');
            $c = dh_contratos();
            $i = dh_contrato_indice($c, (string) ($_POST['id'] ?? ''));
            if ($i === null) {
                throw new RuntimeException('Contrato não encontrado.');
            }
            $o = $c['contratos'][$i];
            $k = ['id' => dh_id_novo('c'), 'token' => bin2hex(random_bytes(16)), 'numero' => '', 'tipo' => $o['tipo'], 'status' => 'rascunho',
                'criado_por' => $usuario['nome'], 'criado_em' => date('Y-m-d H:i:s'), 'assinaturas' => [], 'campos' => $o['campos']];
            $c['contratos'][] = $k;
            dh_contratos_salvar($c);
            ir('./?acao=contrato&id=' . $k['id'], 'Cópia criada como rascunho. Ajuste e envie.');

        case 'contratos-config-salvar':
            exigir_post();
            exigir('contratos');
            $telaErro = 'contratos-config';
            if ($usuario['papel'] !== 'admin') {
                throw new RuntimeException('Só o administrador altera quem assina pela Donkey.');
            }
            $nome = mb_substr(trim((string) ($_POST['representante_nome'] ?? '')), 0, 100);
            $cpf = (string) ($_POST['representante_cpf'] ?? '');
            if ($nome === '' || !dh_cpf_valido($cpf)) {
                throw new RuntimeException('Informe o nome e um CPF válido do representante.');
            }
            $c = dh_contratos();
            $c['config']['representante_nome'] = $nome;
            $c['config']['representante_cpf'] = dh_so_digitos($cpf);
            dh_contratos_salvar($c);
            ir('./?acao=contratos', 'Representante da Donkey salvo.');
    }
    return false;
}

function contratos_tela(string $tela): bool
{
    switch ($tela) {
        case 'contratos':
            contratos_tela_lista();
            return true;
        case 'contrato':
            contratos_tela_contrato();
            return true;
        case 'contratos-config':
            contratos_tela_config();
            return true;
    }
    return false;
}

function dh_contrato_tag(string $st): string
{
    return '<em class="tag ct-' . e($st) . '">' . e(DH_CONTRATO_STATUS[$st] ?? $st) . '</em>';
}

function contratos_tela_lista(): void
{
    $c = dh_contratos();
    $lista = array_reverse($c['contratos']);
    ?>
      <div class="titulo-acao"><h1>Contratos</h1></div>
      <?php if (empty($c['config']['representante_cpf'])): ?>
        <p class="aviso">Primeiro passo: <a href="./?acao=contratos-config">informe o nome e o CPF de quem assina pela Donkey</a>.</p>
      <?php endif; ?>
      <div class="novo-contrato">
        <a class="btn primario largo" href="./?acao=contrato&tipo=pedido">+ Pedido de chope <small>(o cliente preenche)</small></a>
        <?php foreach (DH_CONTRATO_TIPOS as $t => $rot): ?>
          <a class="btn secundario largo" href="./?acao=contrato&tipo=<?= $t ?>">+ <?= e($rot) ?> <small>(eu preencho)</small></a>
        <?php endforeach; ?>
      </div>
      <?php if (!$lista): ?><p class="dica">Nenhum contrato ainda.</p><?php endif; ?>
      <ul class="lista contratos">
        <?php foreach ($lista as $k): $f = $k['campos']; $quando = $k['tipo'] === 'evento' ? $f['data'] : $f['entrega_data']; ?>
          <li>
            <a href="./?acao=contrato&id=<?= e($k['id']) ?>">
              <span class="info">
                <strong><?= e($f['contratante_nome'] ?: ($k['referencia'] ?? '') ?: 'Sem nome') ?></strong>
                <span><?= e($k['numero'] ?: 'Rascunho') ?> · <?= e(DH_CONTRATO_TIPOS[$k['tipo']]) ?><?= $quando ? ' · ' . e(dh_data_br($quando)) : '' ?> · <?= e(dh_moeda(dh_contrato_total($k))) ?></span>
                <span class="tags"><?= dh_contrato_tag($k['status']) ?>
                  <?php if ($k['status'] === 'enviado'): ?>
                    <em class="tag <?= isset($k['assinaturas']['contratada']) ? 'ct-ok' : 'cinza' ?>">Donkey <?= isset($k['assinaturas']['contratada']) ? '✓' : 'pendente' ?></em>
                    <em class="tag <?= isset($k['assinaturas']['contratante']) ? 'ct-ok' : 'cinza' ?>">Cliente <?= isset($k['assinaturas']['contratante']) ? '✓' : 'pendente' ?></em>
                  <?php endif; ?>
                </span>
              </span>
              <span class="seta">›</span>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
      <p><a class="voltar" href="./?acao=contratos-config">⚙ Configurações dos contratos</a></p>
    <?php
}

function contratos_tela_config(): void
{
    global $csrf, $usuario;
    $c = dh_contratos();
    ?>
      <p><a class="voltar" href="./?acao=contratos">‹ Voltar para Contratos</a></p>
      <h1>Configurações dos contratos</h1>
      <p class="dica">Quem assina pela Donkey Head Cervejaria. Fica guardado só no servidor (não vai para o código do site).</p>
      <form method="post" action="./?acao=contratos-config-salvar" class="form-cerveja">
        <?= $csrf ?>
        <label>Nome do representante <input name="representante_nome" value="<?= e($c['config']['representante_nome'] ?? 'Fábio Alan Urbano Valente') ?>" required maxlength="100"></label>
        <label>CPF do representante <input name="representante_cpf" value="<?= e(dh_cpf_formatar($c['config']['representante_cpf'] ?? '')) ?>" required inputmode="numeric" placeholder="000.000.000-00"></label>
        <?php if ($usuario['papel'] === 'admin'): ?><div class="barra-salvar"><button class="btn primario">Salvar</button></div><?php else: ?><p class="aviso">Só o administrador altera estes dados.</p><?php endif; ?>
      </form>
    <?php
}

function contratos_tela_contrato(): void
{
    global $csrf, $erro, $usuario;
    $c = dh_contratos();
    $i = dh_contrato_indice($c, (string) ($_GET['id'] ?? $_POST['id'] ?? ''));
    if ($i === null && ($_GET['tipo'] ?? '') === 'pedido') {
        contratos_form_pedido();
        return;
    }
    if ($i === null) {
        $tipo = isset(DH_CONTRATO_TIPOS[$_GET['tipo'] ?? $_POST['tipo'] ?? '']) ? ($_GET['tipo'] ?? $_POST['tipo']) : 'evento';
        $k = ['id' => '', 'tipo' => $tipo, 'status' => 'rascunho', 'numero' => '', 'campos' => dh_contrato_campos_padrao($tipo), 'assinaturas' => []];
    } else {
        $k = $c['contratos'][$i];
    }
    if ($erro && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['op'] ?? '') !== '' && $k['status'] === 'rascunho') {
        $k['campos'] = dh_contrato_ler_form($k['tipo'], $_POST);
    }
    ?>
      <p><a class="voltar" href="./?acao=contratos">‹ Voltar para Contratos</a></p>
      <h1><?= e(DH_CONTRATO_TIPOS[$k['tipo']]) ?></h1>
      <p><?= $k['numero'] ? 'Contrato nº <strong>' . e($k['numero']) . '</strong> · ' : '' ?><?= dh_contrato_tag($k['status']) ?></p>
    <?php
    if ($k['status'] === 'rascunho') {
        contratos_form($k);
        if ($k['id']) {
            ?>
              <form method="post" action="./?acao=contrato-cancelar" class="excluir" onsubmit="return confirm('Excluir este rascunho?');"><?= $csrf ?><input type="hidden" name="id" value="<?= e($k['id']) ?>"><button class="btn perigo">Excluir rascunho</button></form>
            <?php
        }
        return;
    }
    $link = dh_url_site() . 'contrato/?t=' . $k['token'];
    if ($k['status'] === 'preenchimento') {
        ?>
          <p class="dica">O cliente abre o link, escolhe os rótulos e a litragem, preenche os dados dele e já assina. Você recebe um e-mail quando ele terminar; depois é só assinar pela Donkey aqui.</p>
          <?php if (!empty($k['referencia'])): ?><p>Referência: <strong><?= e($k['referencia']) ?></strong></p><?php endif; ?>
          <p>Pagamento: <strong><?= e(DH_PAGAMENTOS[$k['campos']['pagamento']] ?? '') ?></strong> · Frete: <strong><?= $k['campos']['frete'] !== '' ? e(dh_moeda($k['campos']['frete'])) : 'incluso' ?></strong></p>
          <h2>Enviar ao cliente</h2>
          <input class="link-banda" value="<?= e($link) ?>" readonly onclick="this.select()">
          <p class="botoes-link">
            <a class="btn primario" target="_blank" rel="noopener" href="https://wa.me/?text=<?= e(rawurlencode("Olá! Aqui é a Donkey Head Cervejaria.\n\nPara fazer o seu pedido de chope, escolha os rótulos e a quantidade, preencha os seus dados e assine o contrato por este link:\n" . $link)) ?>">Enviar pelo WhatsApp</a>
            <button type="button" class="btn secundario" onclick="navigator.clipboard.writeText('<?= e($link) ?>').then(function(){ alert('Link copiado!'); })">Copiar link</button>
          </p>
          <form method="post" action="./?acao=contrato-cancelar" class="excluir" onsubmit="return confirm('Excluir este pedido? O link deixa de funcionar.');"><?= $csrf ?><input type="hidden" name="id" value="<?= e($k['id']) ?>"><button class="btn perigo">Excluir pedido</button></form>
        <?php
        return;
    }
    $donkey = $k['assinaturas']['contratada'] ?? null;
    $cliente = $k['assinaturas']['contratante'] ?? null;
    ?>
      <div class="assinaturas-status">
        <p class="<?= $donkey ? 'feito' : '' ?>"><?= $donkey ? '✓' : '○' ?> <strong>Donkey Head Cervejaria</strong><br><?= $donkey ? 'Assinado por ' . e($donkey['nome']) . ' em ' . e(date('d/m/Y H:i', strtotime($donkey['em']))) : 'Aguardando assinatura do administrador' ?></p>
        <p class="<?= $cliente ? 'feito' : '' ?>"><?= $cliente ? '✓' : '○' ?> <strong><?= e($k['campos']['contratante_nome']) ?></strong><br><?= $cliente ? 'Assinado por ' . e($cliente['nome']) . ' em ' . e(date('d/m/Y H:i', strtotime($cliente['em']))) : 'Aguardando assinatura do cliente' ?></p>
      </div>

      <?php if ($k['status'] === 'enviado'): ?>
        <?php if (!$donkey && $usuario['papel'] === 'admin'): ?>
          <form method="post" action="./?acao=contrato-assinar" class="form-cerveja caixa-assinar" id="form-assinar">
            <?= $csrf ?><input type="hidden" name="id" value="<?= e($k['id']) ?>"><input type="hidden" name="geo" id="geo">
            <h2>Assinar pela Donkey Head Cervejaria</h2>
            <p class="dica">Assinatura como <?= e($c['config']['representante_nome'] ?? '') ?> (CPF <?= e(dh_cpf_mascara($c['config']['representante_cpf'] ?? '')) ?>). Leia o contrato em "Abrir o contrato" antes.</p>
            <label class="check"><input type="checkbox" name="aceite" value="1" required> Li e concordo com o contrato nº <?= e($k['numero']) ?></label>
            <label>Sua senha do painel <input type="password" name="senha" required autocomplete="current-password"></label>
            <button class="btn primario largo">✍ Assinar pela Donkey</button>
          </form>
        <?php elseif (!$donkey): ?>
          <p class="aviso">A assinatura pela Donkey é feita pelo administrador.</p>
        <?php endif; ?>
        <?php if (!$cliente): ?>
          <h2>Enviar ao cliente</h2>
          <input class="link-banda" value="<?= e($link) ?>" readonly onclick="this.select()">
          <p class="botoes-link">
            <a class="btn primario" target="_blank" rel="noopener" href="https://wa.me/<?= e(dh_whatsapp_numero($k['campos']['contratante_telefone'])) ?>?text=<?= e(rawurlencode('Olá, ' . $k['campos']['contratante_nome'] . ", aqui é a Donkey Head Cervejaria.\n\nSegue o contrato nº " . $k['numero'] . " para leitura e assinatura eletrônica:\n" . $link)) ?>">Enviar pelo WhatsApp</a>
            <button type="button" class="btn secundario" onclick="navigator.clipboard.writeText('<?= e($link) ?>').then(function(){ alert('Link copiado!'); })">Copiar link</button>
          </p>
          <form method="post" action="./?acao=contrato-convite"><?= $csrf ?><input type="hidden" name="id" value="<?= e($k['id']) ?>"><button class="btn secundario">Enviar convite por e-mail (<?= e($k['campos']['contratante_email']) ?>)</button></form>
        <?php endif; ?>
      <?php endif; ?>

      <div class="botoes-link">
        <a class="btn secundario" href="../contrato/?t=<?= e($k['token']) ?>" target="_blank">📄 Abrir o contrato</a>
        <form method="post" action="./?acao=contrato-duplicar"><?= $csrf ?><input type="hidden" name="id" value="<?= e($k['id']) ?>"><button class="btn secundario">⧉ Duplicar</button></form>
      </div>
      <p class="dica">Código de integridade: <code><?= e($k['hash']) ?></code></p>
      <?php if ($k['status'] === 'enviado'): ?>
        <form method="post" action="./?acao=contrato-cancelar" class="excluir" onsubmit="return confirm('Cancelar este contrato? O link do cliente deixa de aceitar assinatura.');"><?= $csrf ?><input type="hidden" name="id" value="<?= e($k['id']) ?>"><button class="btn perigo">Cancelar contrato</button></form>
      <?php endif; ?>
      <script>
        // Localização aproximada (opcional) para o registro da assinatura
        var fa = document.getElementById('form-assinar');
        if (fa && navigator.geolocation) navigator.geolocation.getCurrentPosition(function (p) {
          document.getElementById('geo').value = p.coords.latitude.toFixed(5) + ',' + p.coords.longitude.toFixed(5);
        }, function () {}, { timeout: 8000 });
      </script>
    <?php
}

function contratos_form(array $k): void
{
    global $csrf;
    $f = $k['campos'];
    $v = fn($x) => e(is_numeric($x) && strpos((string) $x, '.') !== false ? dh_num($x) : $x);
    ?>
      <form method="post" action="./?acao=contrato-salvar" class="form-cerveja form-contrato">
        <?= $csrf ?><input type="hidden" name="id" value="<?= e($k['id']) ?>"><input type="hidden" name="tipo" value="<?= e($k['tipo']) ?>">
        <h2>Contratante</h2>
        <label>Nome / Razão social <input name="contratante_nome" value="<?= e($f['contratante_nome']) ?>" maxlength="200"></label>
        <div class="duas">
          <label>CPF ou CNPJ <input name="contratante_doc" value="<?= e($f['contratante_doc']) ?>" inputmode="numeric"></label>
          <label>Telefone (WhatsApp) <input name="contratante_telefone" value="<?= e($f['contratante_telefone']) ?>" inputmode="tel"></label>
        </div>
        <label>E-mail <small>(o código de assinatura vai para este e-mail)</small><input type="email" name="contratante_email" value="<?= e($f['contratante_email']) ?>" autocapitalize="none"></label>
        <label>Endereço <input name="contratante_endereco" value="<?= e($f['contratante_endereco']) ?>"></label>

        <?php if ($k['tipo'] === 'evento'): ?>
          <h2>Evento</h2>
          <label>Espaço disponibilizado <input name="espaco" value="<?= e($f['espaco']) ?>" placeholder="Ex.: Salão superior da Aldeota"></label>
          <div class="tres">
            <label>Data <input type="date" name="data" value="<?= e($f['data']) ?>"></label>
            <label>Início <input type="time" name="hora_inicio" value="<?= e($f['hora_inicio']) ?>"></label>
            <label>Término <input type="time" name="hora_fim" value="<?= e($f['hora_fim']) ?>"></label>
          </div>
          <label>Atração <input name="atracao" value="<?= e($f['atracao']) ?>"></label>
          <div class="duas">
            <label>Quantidade de convidados <input name="convidados" value="<?= e($f['convidados']) ?>" inputmode="numeric" class="calc"></label>
            <label>Máximo de participantes <input name="max_participantes" value="<?= e($f['max_participantes']) ?>" inputmode="numeric"></label>
          </div>
          <div class="duas">
            <label>Valor do pacote por pessoa (R$) <input name="valor_pessoa" value="<?= $v($f['valor_pessoa']) ?>" inputmode="decimal" class="calc"></label>
            <label>Valor por convidado adicional (R$) <input name="valor_adicional" value="<?= $v($f['valor_adicional']) ?>" inputmode="decimal"></label>
          </div>
          <p class="rotulo">Adicionais por pessoa</p>
          <span class="escolhas">
            <?php foreach (DH_EVENTO_ADICIONAIS as $ch => [$nome, $valor]): ?>
              <label class="escolha"><input type="checkbox" name="adicionais[]" value="<?= $ch ?>" data-valor="<?= $valor ?>" class="calc" <?= !empty($f['adicionais'][$ch]) ? 'checked' : '' ?>><span><?= e($nome) ?> · <?= e(dh_moeda($valor)) ?></span></label>
            <?php endforeach; ?>
          </span>
          <label>Preço total (R$) <small id="total-sugerido"></small><input name="total" value="<?= $v($f['total']) ?>" inputmode="decimal" placeholder="Calculado automaticamente"></label>
          <h2>Responsável pelo evento</h2>
          <label>Nome <input name="responsavel_nome" value="<?= e($f['responsavel_nome']) ?>"></label>
          <div class="duas">
            <label>Telefone <input name="responsavel_telefone" value="<?= e($f['responsavel_telefone']) ?>" inputmode="tel"></label>
            <label>E-mail <input type="email" name="responsavel_email" value="<?= e($f['responsavel_email']) ?>"></label>
          </div>
          <label>Anexo I – Menu <textarea name="cardapio" rows="14"><?= e($f['cardapio']) ?></textarea></label>
        <?php else: ?>
          <h2>Entrega e devolução</h2>
          <label>Endereço de entrega <input name="entrega_endereco" value="<?= e($f['entrega_endereco']) ?>"></label>
          <div class="duas">
            <label>Data da entrega <input type="date" name="entrega_data" value="<?= e($f['entrega_data']) ?>"></label>
            <label>Horário da entrega <input type="time" name="entrega_hora" value="<?= e($f['entrega_hora']) ?>"></label>
          </div>
          <div class="duas">
            <label>Data da devolução <input type="date" name="devolucao_data" value="<?= e($f['devolucao_data']) ?>"></label>
            <label>Horário da devolução <input type="time" name="devolucao_hora" value="<?= e($f['devolucao_hora']) ?>"></label>
          </div>
          <h2>Chope</h2>
          <?php $itens = array_pad($f['itens'], max(3, count($f['itens']) + 1), ['descricao' => '', 'litros' => '', 'valor_litro' => '', 'comodato' => false]); ?>
          <?php foreach ($itens as $n => $it): ?>
            <div class="linha-item">
              <label>Item <input name="itens[<?= $n ?>][descricao]" value="<?= e($it['descricao']) ?>" placeholder="Ex.: DH Premium Lager"></label>
              <label>Litros <input name="itens[<?= $n ?>][litros]" value="<?= $v($it['litros']) ?>" inputmode="decimal" class="calc"></label>
              <label>R$/litro <input name="itens[<?= $n ?>][valor_litro]" value="<?= $v($it['valor_litro']) ?>" inputmode="decimal" class="calc"></label>
              <label class="check em-comodato"><input type="checkbox" name="itens[<?= $n ?>][comodato]" value="1" class="calc" <?= !empty($it['comodato']) ? 'checked' : '' ?>> Em comodato (não entra no total)</label>
            </div>
          <?php endforeach; ?>
          <label>Frete de ida e volta (R$) <small>(em branco = incluso)</small><input name="frete" value="<?= $v($f['frete']) ?>" inputmode="decimal" class="calc"></label>
          <p class="aviso" id="total-comodato"></p>
          <h2>Equipamentos em comodato</h2>
          <?php
            $marcados = array_column($f['equipamentos'], null, 'descricao');
            $lista = array_map(fn($eq) => ['descricao' => $eq[0], 'valor' => (string) $eq[1], 'quantidade' => 1], DH_EQUIPAMENTOS);
            foreach ($f['equipamentos'] as $eq) {
                if (!in_array($eq['descricao'], array_column($lista, 'descricao'), true)) {
                    $lista[] = $eq;
                }
            }
            $lista[] = ['descricao' => '', 'valor' => '', 'quantidade' => 1];
          ?>
          <?php foreach ($lista as $n => $eq): $m = $marcados[$eq['descricao']] ?? null; ?>
            <div class="linha-equip">
              <label class="check"><input type="checkbox" name="equipamentos[<?= $n ?>][incluir]" value="1" <?= $m ? 'checked' : '' ?>> Incluir</label>
              <label>Equipamento <input name="equipamentos[<?= $n ?>][descricao]" value="<?= e($eq['descricao']) ?>" placeholder="Outro equipamento"></label>
              <div class="duas">
                <label>Qtd. <input name="equipamentos[<?= $n ?>][quantidade]" value="<?= (int) ($m['quantidade'] ?? $eq['quantidade']) ?>" inputmode="numeric"></label>
                <label>Valor de reposição (R$) <input name="equipamentos[<?= $n ?>][valor]" value="<?= $v($m['valor'] ?? $eq['valor']) ?>" inputmode="decimal"></label>
              </div>
            </div>
          <?php endforeach; ?>
          <label>Observações <textarea name="observacoes" rows="3"><?= e($f['observacoes']) ?></textarea></label>
        <?php endif; ?>

        <p class="rotulo">Forma de pagamento</p>
        <span class="escolhas"><?php foreach (DH_PAGAMENTOS as $p => $rot): ?><label class="escolha"><input type="radio" name="pagamento" value="<?= $p ?>" <?= $f['pagamento'] === $p ? 'checked' : '' ?>><span><?= e($rot) ?></span></label><?php endforeach; ?></span>

        <div class="barra-salvar barra-dupla">
          <button class="btn secundario" name="op" value="salvar">Salvar rascunho</button>
          <button class="btn primario" name="op" value="enviar" onclick="return confirm('Gerar o contrato para assinatura? Depois disso o texto não pode mais ser alterado.');">Gerar para assinatura</button>
        </div>
      </form>
      <script>
        (function () {
          var form = document.querySelector('.form-contrato');
          function num(v) { v = (v || '').replace(/\./g, '').replace(',', '.'); return parseFloat(v) || 0; }
          function brl(n) { return n.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }); }
          function calc() {
            var sug = document.getElementById('total-sugerido');
            if (sug) {
              var pp = num(form.valor_pessoa.value);
              form.querySelectorAll('[name="adicionais[]"]:checked').forEach(function (c) { pp += parseFloat(c.dataset.valor); });
              var t = pp * (parseInt(form.convidados.value, 10) || 0);
              sug.textContent = t ? '· calculado: ' + brl(t) + ' (' + brl(pp) + ' por pessoa)' : '';
            }
            var tc = document.getElementById('total-comodato');
            if (tc) {
              var s = 0;
              form.querySelectorAll('.linha-item').forEach(function (l) {
                var i = l.querySelectorAll('input'); if (!i[3].checked) s += num(i[1].value) * num(i[2].value);
              });
              s += num(form.frete.value);
              tc.textContent = 'Valor total: ' + brl(s);
            }
          }
          form.addEventListener('input', calc);
          form.addEventListener('change', calc);
          calc();
        })();
      </script>
    <?php
}

// Novo pedido de chope: a Donkey só define pagamento e frete; o cliente preenche o resto pelo link
function contratos_form_pedido(): void
{
    global $csrf;
    $cervejas = dh_cervejas_evento();
    ?>
      <p><a class="voltar" href="./?acao=contratos">‹ Voltar para Contratos</a></p>
      <h1>Pedido de chope</h1>
      <p class="dica">Você cria o pedido e manda o link. O cliente escolhe os rótulos e a litragem, informa se quer chope em comodato, preenche os dados dele (como no cabeçalho do contrato) e assina. Os equipamentos em comodato já vão preenchidos e ele não pode mudar.</p>
      <?php if (!$cervejas): ?>
        <p class="aviso">Nenhuma cerveja com valor para evento ainda. Em <a href="./?acao=cervejas">Cervejas</a>, preencha "Valor do litro para evento" nos rótulos que vocês vendem para eventos.</p>
      <?php else: ?>
        <p class="dica">Rótulos que o cliente vai ver: <?= e(implode(' · ', array_map(fn($x) => $x['nome'] . ' (' . dh_moeda($x['preco_evento']) . '/L)', $cervejas))) ?></p>
      <?php endif; ?>
      <form method="post" action="./?acao=contrato-pedido" class="form-cerveja">
        <?= $csrf ?>
        <label>Referência <small>(só para você achar na lista, ex.: nome do cliente ou do evento)</small><input name="referencia" maxlength="80"></label>
        <p class="rotulo">Forma de pagamento</p>
        <span class="escolhas"><?php foreach (DH_PAGAMENTOS as $p => $rot): ?><label class="escolha"><input type="radio" name="pagamento" value="<?= $p ?>" <?= $p === 'pix' ? 'checked' : '' ?>><span><?= e($rot) ?></span></label><?php endforeach; ?></span>
        <label>Frete de ida e volta (R$) <small>(em branco = incluso)</small><input name="frete" inputmode="decimal"></label>
        <label>Observações para o contrato <small>(opcional)</small><textarea name="observacoes" rows="3"></textarea></label>
        <div class="barra-salvar"><button class="btn primario">Criar pedido e gerar o link</button></div>
      </form>
    <?php
}
