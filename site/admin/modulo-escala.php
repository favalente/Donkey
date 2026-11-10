<?php
// Painel → Escala (semana da equipe, dia a dia, como a planilha), Equipe (fixos e freelancers),
// Funções e diárias, e Financeiro → Freelancers (fechamento da semana, pago na segunda). Ver inc/escala.php.
// Feito para ser simples de usar: digita-se o nome como na planilha, copia-se a semana passada e
// cola-se direto do Excel.

function escala_semana_param(): string
{
    $s = (string) ($_GET['semana'] ?? $_POST['semana'] ?? '');
    return dh_semana_inicio(preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? $s : date('Y-m-d'));
}

function escala_dia_param(): string
{
    $d = (string) ($_POST['dia'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
        throw new RuntimeException('Dia inválido.');
    }
    return $d;
}

function escala_dia_nome(string $dia, bool $curto = false): string
{
    $n = DH_DIAS_SEMANA_ESCALA[(int) date('N', strtotime($dia))];
    return ($curto ? mb_substr($n, 0, 3) : $n) . ' ' . date('d/m', strtotime($dia));
}

// Posições lidas do formulário do dia (na ordem da tela; linha sem função e sem nome é ignorada)
function escala_ler_posicoes(array &$e, array $linhas, string $dia): array
{
    $pos = [];
    foreach ($linhas as $l) {
        $funcao = trim((string) ($l['funcao'] ?? ''));
        $nome = trim((string) ($l['nome'] ?? ''));
        if ($funcao === '' && $nome === '') {
            continue;
        }
        if ($funcao === '') {
            throw new RuntimeException('Escolha a função de ' . $nome . ' em ' . escala_dia_nome($dia) . '.');
        }
        $saidaPadrao = DH_ESCALA_DIAS_ABERTOS[(int) date('N', strtotime($dia))] ?? '23:30';
        $pos[] = ['funcao' => dh_escala_funcao_por_nome($e, $funcao), 'pessoa' => $nome !== '' ? dh_escala_pessoa_por_nome($e, $nome) : '',
            'entrada' => dh_escala_hora((string) ($l['entrada'] ?? '')) ?: DH_ESCALA_ENTRADA_FREELA, 'saida' => dh_escala_hora((string) ($l['saida'] ?? '')) ?: $saidaPadrao,
            'falta' => !empty($l['falta']), 'ajuste' => brassagem_num(str_replace(['−', '+'], ['-', ''], (string) ($l['ajuste'] ?? ''))),
            'motivo' => mb_substr(trim((string) ($l['motivo'] ?? '')), 0, 80), 'pago_caixa' => brassagem_num($l['pago_caixa'] ?? ''), 'chegada' => dh_escala_hora((string) ($l['chegada'] ?? ''))];
    }
    return $pos;
}

// Texto colado da planilha: NOME · FUNÇÃO · ENTRADA · SAÍDA (separados por tabulação)
function escala_ler_colagem(string $texto): array
{
    $linhas = [];
    foreach (preg_split('/\R/u', $texto) as $l) {
        if (trim($l) === '') {
            continue;
        }
        $c = array_map('trim', strpos($l, "\t") !== false ? explode("\t", $l) : preg_split('/\s{2,}/u', trim($l)));
        $c = array_values(array_pad($c, 4, ''));
        // Cabeçalho ("NOME FUNÇÃO ENTRADA SAIDA") e título do dia ("QUARTA-FEIRA 04/11") ficam de fora
        if (preg_match('/^(nom|func|entrad|said)/i', dh_sem_acento($c[0] . $c[1])) || count(array_filter($c)) === 1) {
            continue;
        }
        // Sem a coluna do nome (vaga em aberto): a primeira coluna já é a função
        if (dh_escala_hora($c[1]) !== '' && dh_escala_hora($c[0]) === '') {
            array_unshift($c, '');
        }
        $linhas[] = ['nome' => $c[0], 'funcao' => $c[1], 'entrada' => $c[2], 'saida' => $c[3]];
    }
    return $linhas;
}

function escala_acao(string $acao): bool
{
    global $usuario, $telaErro;
    switch ($acao) {
        case 'escala':
        case 'escala-equipe':
        case 'escala-funcoes':
            exigir('escala');
            return true;
        case 'freelancers':
            exigir('freelancers');
            return true;

        case 'escala-dia-salvar':
        case 'escala-colar':
            exigir_post();
            exigir('escala');
            $telaErro = 'escala';
            $dia = escala_dia_param();
            $e = dh_escala();
            if ($acao === 'escala-colar') {
                $linhas = escala_ler_colagem((string) ($_POST['texto'] ?? ''));
                if (!$linhas) {
                    throw new RuntimeException('Não achei linhas na colagem. Copie da planilha as colunas Nome, Função, Entrada e Saída.');
                }
                $pos = escala_ler_posicoes($e, $linhas, $dia);
            } else {
                $pos = escala_ler_posicoes($e, (array) ($_POST['linhas'] ?? []), $dia);
            }
            $e['dias'][$dia] = ['posicoes' => $pos, 'por' => $usuario['nome'], 'em' => date('Y-m-d H:i:s')];
            dh_escala_salvar($e);
            ir('./?acao=escala&semana=' . dh_semana_inicio($dia) . '#dia-' . $dia, escala_dia_nome($dia) . ' salva: ' . count($pos) . ' posições.');

        case 'escala-copiar-dia':
            // Copia as posições de outro dia (o dia anterior ou o mesmo dia da semana passada)
            exigir_post();
            exigir('escala');
            $telaErro = 'escala';
            $dia = escala_dia_param();
            $de = (string) ($_POST['de'] ?? '');
            $e = dh_escala();
            if (empty($e['dias'][$de]['posicoes'])) {
                throw new RuntimeException('O dia ' . (preg_match('/^\d{4}-\d{2}-\d{2}$/', $de) ? escala_dia_nome($de) : '') . ' está vazio.');
            }
            $saida = DH_ESCALA_DIAS_ABERTOS[(int) date('N', strtotime($dia))] ?? null;
            $mudaSaida = (DH_ESCALA_DIAS_ABERTOS[(int) date('N', strtotime($de))] ?? null) !== $saida;
            $e['dias'][$dia] = ['posicoes' => array_map(fn($p) => array_merge($p, ['falta' => false, 'ajuste' => '', 'motivo' => '', 'pago_caixa' => '', 'chegada' => ''], $mudaSaida && $saida ? ['saida' => $saida] : []), $e['dias'][$de]['posicoes']),
                'por' => $usuario['nome'], 'em' => date('Y-m-d H:i:s')];
            dh_escala_salvar($e);
            ir('./?acao=escala&semana=' . dh_semana_inicio($dia) . '#dia-' . $dia, escala_dia_nome($dia) . ': copiada de ' . escala_dia_nome($de) . '. Ajuste quem mudou e salve.');

        case 'escala-copiar-semana':
            // A semana inteira para a semana seguinte (mesmos dias da semana, sem faltas e ajustes)
            exigir_post();
            exigir('escala');
            $telaErro = 'escala';
            $de = escala_semana_param();
            $para = date('Y-m-d', strtotime($de . ' +7 days'));
            $e = dh_escala();
            $n = 0;
            foreach (dh_semana_dias($de) as $k => $dia) {
                $alvo = dh_semana_dias($para)[$k];
                if (!empty($e['dias'][$dia]['posicoes']) && empty($e['dias'][$alvo]['posicoes'])) {
                    $e['dias'][$alvo] = ['posicoes' => array_map(fn($p) => array_merge($p, ['falta' => false, 'ajuste' => '', 'motivo' => '', 'pago_caixa' => '', 'chegada' => '']), $e['dias'][$dia]['posicoes']),
                        'por' => $usuario['nome'], 'em' => date('Y-m-d H:i:s')];
                    $n++;
                }
            }
            if (!$n) {
                throw new RuntimeException('Nada para copiar: esta semana está vazia ou a próxima já tem escala nos mesmos dias.');
            }
            dh_escala_salvar($e);
            ir('./?acao=escala&semana=' . $para, $n . ' dia(s) copiado(s) para a semana de ' . date('d/m', strtotime($para)) . '. Ajuste quem mudou.');

        case 'escala-importar':
            // Semana inteira de um arquivo .json: {"fixos": [nomes], "dias": {"AAAA-MM-DD": [[nome, função, entrada, saída], ...]}}
            exigir_post();
            exigir('escala');
            $telaErro = 'escala';
            $arq = $_FILES['arquivo'] ?? null;
            if (!$arq || ($arq['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($arq['tmp_name']) || $arq['size'] > 2 * 1024 * 1024) {
                throw new RuntimeException('Escolha o arquivo .json da escala.');
            }
            $dados = json_decode((string) file_get_contents($arq['tmp_name']), true);
            if (!is_array($dados['dias'] ?? null) || !$dados['dias']) {
                throw new RuntimeException('Arquivo inválido: não encontrei os dias da escala.');
            }
            $e = dh_escala();
            $substituir = !empty($_POST['substituir']);
            $feitos = $pulados = [];
            foreach ($dados['dias'] as $dia => $linhas) {
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $dia) || !is_array($linhas)) {
                    continue;
                }
                if (!empty($e['dias'][$dia]['posicoes']) && !$substituir) {
                    $pulados[] = escala_dia_nome($dia, true);
                    continue;
                }
                $pos = escala_ler_posicoes($e, array_map(fn($l) => ['nome' => $l[0] ?? '', 'funcao' => $l[1] ?? '', 'entrada' => $l[2] ?? '', 'saida' => $l[3] ?? ''], $linhas), $dia);
                $e['dias'][$dia] = ['posicoes' => $pos, 'por' => $usuario['nome'] . ' (importado)', 'em' => date('Y-m-d H:i:s')];
                $feitos[] = escala_dia_nome($dia, true);
            }
            // Fixos (os nomes em azul da planilha)
            foreach ((array) ($dados['fixos'] ?? []) as $nome) {
                $id = dh_escala_pessoa_por_nome($e, (string) $nome);
                foreach ($e['pessoas'] as $k => $pp) {
                    if ($pp['id'] === $id) {
                        $e['pessoas'][$k]['tipo'] = 'fixo';
                    }
                }
            }
            if (!$feitos) {
                throw new RuntimeException('Nenhum dia importado: ' . implode(', ', $pulados) . ' já têm escala. Marque "substituir" para trocar.');
            }
            dh_escala_salvar($e);
            $primeiro = array_key_first($dados['dias']);
            ir('./?acao=escala&semana=' . dh_semana_inicio($primeiro), 'Escala importada: ' . implode(', ', $feitos) . '.' . ($pulados ? ' Já tinham escala (ficaram como estavam): ' . implode(', ', $pulados) . '.' : ''));

        case 'escala-pessoa-salvar':
            exigir_post();
            exigir('escala');
            $telaErro = 'escala-equipe';
            $e = dh_escala();
            $nome = trim(preg_replace('/\s+/u', ' ', (string) ($_POST['nome'] ?? '')));
            if ($nome === '') {
                throw new RuntimeException('Escreva o nome.');
            }
            $dados = ['nome' => mb_substr($nome, 0, 60), 'tipo' => ($_POST['tipo'] ?? '') === 'fixo' ? 'fixo' : 'freelancer', 'telefone' => mb_substr(trim((string) ($_POST['telefone'] ?? '')), 0, 30),
                'pix' => mb_substr(trim((string) ($_POST['pix'] ?? '')), 0, 120), 'diaria' => brassagem_num($_POST['diaria'] ?? ''), 'ativo' => empty($_POST['inativo'])];
            $id = (string) ($_POST['id'] ?? '');
            $i = null;
            foreach ($e['pessoas'] as $k => $p) {
                if ($p['id'] === $id) {
                    $i = $k;
                } elseif (dh_sem_acento($p['nome']) === dh_sem_acento($nome)) {
                    throw new RuntimeException('Já existe ' . $p['nome'] . ' na equipe.');
                }
            }
            if ($i === null) {
                $e['pessoas'][] = ['id' => dh_id_novo('p')] + $dados;
            } else {
                $e['pessoas'][$i] = array_merge($e['pessoas'][$i], $dados);
            }
            dh_escala_salvar($e);
            ir('./?acao=escala-equipe', $dados['nome'] . ' salvo(a).');

        case 'escala-funcoes-salvar':
            exigir_post();
            exigir('escala');
            $telaErro = 'escala-funcoes';
            $e = dh_escala();
            $novas = [];
            foreach ((array) ($_POST['funcoes'] ?? []) as $f) {
                $nome = mb_strtoupper(trim((string) ($f['nome'] ?? '')));
                if ($nome === '') {
                    continue;
                }
                $novas[] = ['id' => preg_match('/^[a-z0-9]+$/', (string) ($f['id'] ?? '')) && $f['id'] !== '' ? $f['id'] : dh_id_novo('f'), 'nome' => mb_substr($nome, 0, 30),
                    'diaria_semana' => brassagem_num($f['diaria_semana'] ?? ''), 'diaria_fds' => brassagem_num($f['diaria_fds'] ?? ''), 'diaria_dom' => brassagem_num($f['diaria_dom'] ?? '')];
            }
            // Função que está em alguma escala não some (só muda de nome ou diária)
            $usadas = [];
            foreach ($e['dias'] as $d) {
                foreach ($d['posicoes'] ?? [] as $p) {
                    $usadas[$p['funcao']] = true;
                }
            }
            foreach ($e['funcoes'] as $f) {
                if (isset($usadas[$f['id']]) && !in_array($f['id'], array_column($novas, 'id'), true)) {
                    $novas[] = $f;
                }
            }
            $e['funcoes'] = $novas;
            $e['config']['atraso'] = ['faixa' => max(1, min(120, (int) ($_POST['atraso_faixa'] ?? 15))), 'desconto' => brassagem_num($_POST['atraso_desconto'] ?? '') ?: '0',
                'limite' => max(0, min(600, (int) ($_POST['atraso_limite'] ?? 30)))];
            dh_escala_salvar($e);
            ir('./?acao=escala-funcoes', 'Funções e diárias salvas.');

        case 'freelancer-pagar':
            // Marca (ou desfaz) o pagamento da semana de um freelancer, guardando o valor e o detalhe do dia
            exigir_post();
            exigir('freelancers');
            $telaErro = 'freelancers';
            $semana = escala_semana_param();
            $id = (string) ($_POST['pessoa'] ?? '');
            $e = dh_escala();
            if (!empty($_POST['desfazer'])) {
                unset($e['pagamentos'][$semana][$id]);
                dh_escala_salvar($e);
                ir('./?acao=freelancers&semana=' . $semana, 'Pagamento desfeito.');
            }
            $linha = dh_escala_a_receber($e, $semana)[$id] ?? null;
            if (!$linha) {
                throw new RuntimeException('Esse freelancer não tem dias nesta semana.');
            }
            $e['pagamentos'][$semana][$id] = ['valor' => round($linha['total'], 2), 'pago_em' => date('Y-m-d H:i:s'), 'por' => $usuario['nome'], 'itens' => $linha['itens']];
            dh_escala_salvar($e);
            ir('./?acao=freelancers&semana=' . $semana, $linha['pessoa']['nome'] . ': ' . dh_moeda($linha['total']) . ' marcado como pago.');
    }
    return false;
}

function escala_tela(string $tela): bool
{
    $telas = ['escala' => 'escala_tela_semana', 'escala-equipe' => 'escala_tela_equipe', 'escala-funcoes' => 'escala_tela_funcoes', 'freelancers' => 'escala_tela_freelancers'];
    if (!isset($telas[$tela])) {
        return false;
    }
    $telas[$tela]();
    return true;
}

function escala_nav_semana(string $acao, string $segunda): void
{
    ?>
      <div class="mes-nav">
        <a class="btn mini" href="./?acao=<?= $acao ?>&amp;semana=<?= date('Y-m-d', strtotime($segunda . ' -7 days')) ?>" aria-label="Semana anterior">‹</a>
        <strong>Semana de <?= date('d/m', strtotime($segunda)) ?> a <?= date('d/m', strtotime($segunda . ' +6 days')) ?></strong>
        <a class="btn mini" href="./?acao=<?= $acao ?>&amp;semana=<?= date('Y-m-d', strtotime($segunda . ' +7 days')) ?>" aria-label="Próxima semana">›</a>
      </div>
      <?php if ($segunda !== dh_semana_inicio(date('Y-m-d'))): ?><p class="centro"><a href="./?acao=<?= $acao ?>">Ir para esta semana</a></p><?php endif; ?>
    <?php
}

function escala_tela_semana(): void
{
    global $csrf;
    $e = dh_escala();
    $segunda = escala_semana_param();
    $dias = array_values(array_filter(dh_semana_dias($segunda), fn($d) => isset(DH_ESCALA_DIAS_ABERTOS[(int) date('N', strtotime($d))]) || !empty($e['dias'][$d]['posicoes'])));
    $vazia = !array_filter($dias, fn($d) => !empty($e['dias'][$d]['posicoes']));
    $anterior = date('Y-m-d', strtotime($segunda . ' -7 days'));
    $temAnterior = (bool) array_filter(dh_semana_dias($anterior), fn($d) => !empty($e['dias'][$d]['posicoes']));
    $ativos = array_filter($e['pessoas'], fn($p) => $p['ativo']);
    usort($ativos, fn($a, $b) => strcoll(dh_sem_acento($a['nome']), dh_sem_acento($b['nome'])));
    ?>
      <div class="titulo-acao sem-impressao"><h1>Escala</h1><span class="botoes-titulo"><a class="btn secundario" href="./?acao=escala-equipe">👥 Equipe</a><a class="btn secundario" href="./?acao=escala-funcoes">Funções e diárias</a></span></div>
      <div class="sem-impressao"><?php escala_nav_semana('escala', $segunda); ?></div>
      <h1 class="so-impressao">Escala · semana de <?= date('d/m', strtotime($segunda)) ?> a <?= date('d/m', strtotime($segunda . ' +6 days')) ?></h1>
      <div class="botoes-semana sem-impressao">
        <?php if ($vazia && $temAnterior): ?>
          <form method="post" action="./?acao=escala-copiar-semana"><?= $csrf ?><input type="hidden" name="semana" value="<?= $anterior ?>"><button class="btn primario largo">📋 Copiar a escala da semana passada</button></form>
        <?php endif; ?>
        <?php if (!$vazia): ?>
          <form method="post" action="./?acao=escala-copiar-semana" onsubmit="return confirm('Copiar esta escala para a próxima semana? Dias que já têm escala lá não mudam.');"><?= $csrf ?><input type="hidden" name="semana" value="<?= $segunda ?>"><button class="btn secundario">📋 Copiar esta semana para a próxima</button></form>
          <button type="button" class="btn secundario" onclick="window.print()">🖨 Imprimir a semana</button>
        <?php endif; ?>
      </div>
      <datalist id="lista-funcoes"><?php foreach ($e['funcoes'] as $f): ?><option value="<?= e($f['nome']) ?>"><?php endforeach; ?></datalist>
      <datalist id="lista-pessoas"><?php foreach ($ativos as $p): ?><option value="<?= e($p['nome']) ?>"><?php endforeach; ?></datalist>
      <div class="dias-escala">
        <?php foreach ($dias as $n => $dia): $pos = $e['dias'][$dia]['posicoes'] ?? []; $antes = date('Y-m-d', strtotime($dia . ' -1 day')); while (!isset(DH_ESCALA_DIAS_ABERTOS[(int) date('N', strtotime($antes))]) && empty($e['dias'][$antes]['posicoes']) && $antes > date('Y-m-d', strtotime($dia . ' -7 days'))) { $antes = date('Y-m-d', strtotime($antes . ' -1 day')); } $semPassada = date('Y-m-d', strtotime($dia . ' -7 days')); ?>
          <section class="dia-escala" id="dia-<?= $dia ?>">
            <h2 class="dia-titulo"><?= e(mb_strtoupper(escala_dia_nome($dia))) ?><?php if ($pos): $preench = count(array_filter($pos, fn($p) => $p['pessoa'] !== '')); ?> <small><?= $preench ?> de <?= count($pos) ?> preenchidas</small><?php endif; ?></h2>
            <?php if (!$pos): ?>
              <p class="dica sem-impressao">Sem escala ainda.</p>
              <div class="botoes-dia sem-impressao">
                <?php if (!empty($e['dias'][$antes]['posicoes'])): ?><form method="post" action="./?acao=escala-copiar-dia"><?= $csrf ?><input type="hidden" name="dia" value="<?= $dia ?>"><input type="hidden" name="de" value="<?= $antes ?>"><button class="btn primario">Copiar <?= e(escala_dia_nome($antes, true)) ?></button></form><?php endif; ?>
                <?php if (!empty($e['dias'][$semPassada]['posicoes'])): ?><form method="post" action="./?acao=escala-copiar-dia"><?= $csrf ?><input type="hidden" name="dia" value="<?= $dia ?>"><input type="hidden" name="de" value="<?= $semPassada ?>"><button class="btn secundario">Copiar <?= e(escala_dia_nome($semPassada, true)) ?> (semana passada)</button></form><?php endif; ?>
              </div>
            <?php endif; ?>
            <form method="post" action="./?acao=escala-dia-salvar" class="form-dia">
              <?= $csrf ?><input type="hidden" name="dia" value="<?= $dia ?>">
              <table class="tabela-escala">
                <thead><tr><th>Nome</th><th>Função</th><th>Entrada</th><th>Saída</th><th class="sem-impressao"></th></tr></thead>
                <tbody>
                  <?php foreach (array_merge($pos, $pos ? [] : array_fill(0, 1, ['funcao' => '', 'pessoa' => '', 'entrada' => '', 'saida' => '', 'falta' => false, 'ajuste' => '', 'motivo' => ''])) as $k => $p): $pe = dh_escala_pessoa($e, $p['pessoa']); $fu = dh_escala_funcao($e, $p['funcao']); ?>
                    <tr class="<?= $p['pessoa'] === '' && $p['funcao'] !== '' ? 'vaga' : '' ?><?= !empty($p['falta']) ? ' falta' : '' ?><?= $pe && $pe['tipo'] === 'fixo' ? ' fixo' : '' ?>">
                      <td><input name="linhas[<?= $k ?>][nome]" list="lista-pessoas" value="<?= e($pe['nome'] ?? '') ?>" placeholder="vaga" aria-label="Nome" autocomplete="off"></td>
                      <td><input name="linhas[<?= $k ?>][funcao]" list="lista-funcoes" value="<?= e($fu['nome'] ?? '') ?>" placeholder="Função" aria-label="Função" autocomplete="off"></td>
                      <td><input name="linhas[<?= $k ?>][entrada]" value="<?= e($p['entrada']) ?>" inputmode="numeric" placeholder="<?= DH_ESCALA_ENTRADA_FREELA ?>" aria-label="Entrada"></td>
                      <td><input name="linhas[<?= $k ?>][saida]" value="<?= e($p['saida']) ?>" inputmode="numeric" placeholder="<?= e(DH_ESCALA_DIAS_ABERTOS[(int) date('N', strtotime($dia))] ?? '23:30') ?>" aria-label="Saída"></td>
                      <td class="sem-impressao acoes-linha">
                        <button type="button" class="tirar-linha" aria-label="Tirar esta linha" title="Tirar esta linha">×</button>
                        <details class="ajuste-linha"><summary title="Chegada, falta, ajuste de valor ou pago no caixa"><?php [$atrMin, $atrDesc, $atrCasa] = dh_escala_atraso($e, $p); ?><?= $atrCasa ? '🏠' : (!empty($p['falta']) ? 'falta' : (($p['ajuste'] ?? '') !== '' ? ((float) $p['ajuste'] > 0 ? '+' : '−') . dh_num(abs((float) $p['ajuste'])) : (($p['pago_caixa'] ?? '') === '' && $atrDesc == 0 ? '⋯' : ''))) ?><?= $atrDesc > 0 ? ' ⏰−' . dh_num($atrDesc) : '' ?><?= ($p['pago_caixa'] ?? '') !== '' ? ' 💵' : '' ?></summary>
                          <label>Chegou às <small>(desconto por atraso automático)</small><input name="linhas[<?= $k ?>][chegada]" value="<?= e($p['chegada'] ?? '') ?>" inputmode="numeric" placeholder="ex.: 17:10"></label>
                          <label class="check"><input type="checkbox" name="linhas[<?= $k ?>][falta]" value="1" <?= !empty($p['falta']) ? 'checked' : '' ?>> Faltou</label>
                          <label>Ajuste (R$) <small>(+ hora extra / − desconto)</small><input name="linhas[<?= $k ?>][ajuste]" value="<?= e(($p['ajuste'] ?? '') !== '' ? dh_num($p['ajuste']) : '') ?>" inputmode="decimal" placeholder="ex.: 20 ou -15"></label>
                          <label>Motivo <input name="linhas[<?= $k ?>][motivo]" value="<?= e($p['motivo'] ?? '') ?>" maxlength="80"></label>
                          <label>Pago no dia pelo caixa (R$) <small>(sangria; desconta do pagamento da semana)</small><input name="linhas[<?= $k ?>][pago_caixa]" value="<?= e(($p['pago_caixa'] ?? '') !== '' ? dh_num($p['pago_caixa']) : '') ?>" inputmode="decimal" placeholder="0,00"></label>
                        </details>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
              <?php $sangria = array_filter($pos, fn($x) => ($x['pago_caixa'] ?? '') !== ''); if ($sangria): ?>
                <p class="sangria-dia">💵 Pago no caixa hoje (sangria): <b><?= e(dh_moeda(array_sum(array_map(fn($x) => (float) $x['pago_caixa'], $sangria)))) ?></b> · <?= e(implode(', ', array_map(fn($x) => (dh_escala_pessoa($e, $x['pessoa'])['nome'] ?? '?') . ' ' . dh_moeda((float) $x['pago_caixa']), $sangria))) ?></p>
              <?php endif; ?>
              <div class="botoes-dia sem-impressao">
                <button type="button" class="btn secundario mais-linha">+ Linha</button>
                <button class="btn primario">Salvar <?= e(mb_strtolower(DH_DIAS_SEMANA_ESCALA[(int) date('N', strtotime($dia))])) ?></button>
                <?php if ($pos): ?><a class="btn secundario" target="_blank" rel="noopener" href="https://wa.me/?text=<?= e(rawurlencode(escala_texto_dia($e, $dia))) ?>">📤 WhatsApp</a><?php endif; ?>
              </div>
            </form>
            <details class="colar-excel sem-impressao">
              <summary>📋 Colar do Excel</summary>
              <form method="post" action="./?acao=escala-colar" onsubmit="return <?= $pos ? "confirm('Trocar a escala de " . e(escala_dia_nome($dia)) . " pelo que foi colado?')" : 'true' ?>;">
                <?= $csrf ?><input type="hidden" name="dia" value="<?= $dia ?>">
                <p class="dica">Na planilha, selecione as linhas do dia (colunas Nome, Função, Entrada e Saída), copie e cole aqui.</p>
                <textarea name="texto" rows="5" placeholder="TATIANA&#9;CAIXA&#9;16:00&#9;0:30"></textarea>
                <button class="btn secundario">Usar o que foi colado</button>
              </form>
            </details>
          </section>
        <?php endforeach; ?>
      </div>
      <details class="colar-excel sem-impressao importar-escala">
        <summary>📥 Importar escala de um arquivo</summary>
        <form method="post" action="./?acao=escala-importar" enctype="multipart/form-data">
          <?= $csrf ?>
          <label>Arquivo .json da escala <input type="file" name="arquivo" accept=".json,application/json" required></label>
          <label class="check"><input type="checkbox" name="substituir" value="1"> Substituir os dias que já têm escala</label>
          <button class="btn secundario">Importar</button>
        </form>
      </details>
      <p class="dica sem-impressao">Digite o nome como na planilha: quem ainda não está na <a href="./?acao=escala-equipe">Equipe</a> entra como freelancer. Linha sem nome é vaga em aberto (fica amarela). Fixos ficam em azul e não entram no pagamento. Em "⋯" anota-se a hora em que chegou (o desconto por atraso sai sozinho; 🏠 = voltou para casa), falta, ajuste de valor ou o que foi pago no dia pelo caixa (sangria).</p>
      <script>
        // + Linha copia a última linha do dia (vazia) e × tira a linha
        document.querySelectorAll('.form-dia').forEach(function (form) {
          var corpo = form.querySelector('tbody');
          form.querySelector('.mais-linha').addEventListener('click', function () {
            var ultima = corpo.querySelector('tr:last-child'), nova = ultima.cloneNode(true), n = Date.now();
            nova.className = '';
            nova.querySelectorAll('input').forEach(function (i) {
              i.name = i.name.replace(/\[\d+\]/, '[' + n + ']');
              if (i.type === 'checkbox') i.checked = false; else if (!/entrada|saida/.test(i.name)) i.value = '';
            });
            nova.querySelector('details') && (nova.querySelector('details').open = false, nova.querySelector('summary').textContent = '⋯');
            corpo.appendChild(nova); nova.querySelector('input').focus();
          });
          corpo.addEventListener('click', function (ev) {
            var b = ev.target.closest('.tirar-linha'); if (!b) return;
            var tr = b.closest('tr');
            if (corpo.querySelectorAll('tr').length > 1) tr.remove(); else tr.querySelectorAll('input').forEach(function (i) { i.value = ''; i.checked = false; });
          });
        });
      </script>
    <?php
}

// O dia em texto, para o WhatsApp
function escala_texto_dia(array $e, string $dia): string
{
    $t = '*' . mb_strtoupper(escala_dia_nome($dia)) . "*\n";
    foreach ($e['dias'][$dia]['posicoes'] ?? [] as $p) {
        $t .= (dh_escala_funcao($e, $p['funcao'])['nome'] ?? '?') . ' – ' . (dh_escala_pessoa($e, $p['pessoa'])['nome'] ?? '(vaga)') . ' · ' . $p['entrada'] . '–' . $p['saida'] . "\n";
    }
    return $t;
}

function escala_tela_equipe(): void
{
    global $csrf;
    $e = dh_escala();
    $pessoas = $e['pessoas'];
    usort($pessoas, fn($a, $b) => [!$a['ativo'], $a['tipo'], dh_sem_acento($a['nome'])] <=> [!$b['ativo'], $b['tipo'], dh_sem_acento($b['nome'])]);
    $form = function (array $p) use ($csrf): void {
        ?>
          <form method="post" action="./?acao=escala-pessoa-salvar" class="form-cerveja">
            <?= $csrf ?><input type="hidden" name="id" value="<?= e($p['id']) ?>">
            <div class="duas">
              <label>Nome <input name="nome" required maxlength="60" value="<?= e($p['nome']) ?>"></label>
              <label>Tipo <select name="tipo"><option value="freelancer" <?= $p['tipo'] === 'freelancer' ? 'selected' : '' ?>>Freelancer (recebe diária)</option><option value="fixo" <?= $p['tipo'] === 'fixo' ? 'selected' : '' ?>>Fixo (funcionário)</option></select></label>
            </div>
            <div class="duas">
              <label>Celular <input name="telefone" inputmode="tel" value="<?= e($p['telefone']) ?>" placeholder="(85) 99999-9999"></label>
              <label>Chave Pix <input name="pix" value="<?= e($p['pix']) ?>"></label>
            </div>
            <label>Diária própria (R$) <small>(em branco = a diária da função)</small><input name="diaria" inputmode="decimal" value="<?= e($p['diaria'] !== '' ? dh_num($p['diaria']) : '') ?>"></label>
            <?php if ($p['id']): ?><label class="check"><input type="checkbox" name="inativo" value="1" <?= $p['ativo'] ? '' : 'checked' ?>> Não trabalha mais (sai da lista de nomes)</label><?php endif; ?>
            <button class="btn primario"><?= $p['id'] ? 'Salvar' : 'Adicionar' ?></button>
          </form>
        <?php
    };
    ?>
      <p><a class="voltar" href="./?acao=escala">‹ Voltar para a Escala</a></p>
      <h1>Equipe <small><?= count(array_filter($pessoas, fn($p) => $p['ativo'])) ?></small></h1>
      <p class="dica">Quem trabalha na escala. <b>Freelancer</b> recebe a diária por dia trabalhado (Financeiro → Freelancers); <b>fixo</b> aparece na escala mas não entra no pagamento. Nomes digitados na escala entram aqui sozinhos, como freelancer.</p>
      <ul class="lista-lotes">
        <?php foreach ($pessoas as $p): ?>
          <li>
            <details>
              <summary><strong><?= e($p['nome']) ?></strong> <span><?= $p['tipo'] === 'fixo' ? 'Fixo' : 'Freelancer' ?><?= $p['diaria'] !== '' ? ' · diária ' . e(dh_moeda($p['diaria'])) : '' ?><?= $p['pix'] === '' && $p['tipo'] === 'freelancer' ? ' · sem Pix' : '' ?></span><?= $p['ativo'] ? '' : ' <em class="tag cinza">Não trabalha mais</em>' ?><?= $p['tipo'] === 'fixo' ? ' <em class="tag ct-enviado">Fixo</em>' : '' ?></summary>
              <?php $form($p); ?>
            </details>
          </li>
        <?php endforeach; ?>
        <?php if (!$pessoas): ?><li class="dica">Ninguém ainda. Digite os nomes na escala ou adicione abaixo.</li><?php endif; ?>
      </ul>
      <h2>Adicionar pessoa</h2>
      <?php $form(['id' => '', 'nome' => '', 'tipo' => 'freelancer', 'telefone' => '', 'pix' => '', 'diaria' => '', 'ativo' => true]); ?>
    <?php
}

function escala_tela_funcoes(): void
{
    global $csrf;
    $e = dh_escala();
    $linhas = array_merge($e['funcoes'], array_fill(0, 2, ['id' => '', 'nome' => '', 'diaria_semana' => '', 'diaria_fds' => '', 'diaria_dom' => '']));
    $a = $e['config']['atraso'];
    // Valor redondo sem centavos (cabe na coluna do celular): 120 em vez de 120,00
    $v = fn($x) => e(($x ?? '') === '' ? '' : (floor((float) $x) == (float) $x ? (string) (int) $x : dh_num($x)));
    ?>
      <p><a class="voltar" href="./?acao=escala">‹ Voltar para a Escala</a></p>
      <h1>Funções e diárias</h1>
      <p class="dica">A diária que o freelancer recebe por dia em cada função, conforme o dia da semana. Para alguém com valor diferente, use a diária própria na Equipe. Função nova digitada na escala entra aqui sozinha.</p>
      <form method="post" action="./?acao=escala-funcoes-salvar" class="form-cerveja">
        <?= $csrf ?>
        <table class="tabela-diarias">
          <thead><tr><th>Função</th><th>Ter a Qui</th><th>Sex e Sáb</th><th>Domingo</th></tr></thead>
          <tbody>
            <?php foreach ($linhas as $k => $f): ?>
              <tr>
                <td><input name="funcoes[<?= $k ?>][nome]" value="<?= e($f['nome']) ?>" placeholder="Nova função" aria-label="Função"><input type="hidden" name="funcoes[<?= $k ?>][id]" value="<?= e($f['id']) ?>"></td>
                <?php foreach (['diaria_semana' => 'Ter a Qui', 'diaria_fds' => 'Sex e Sáb', 'diaria_dom' => 'Domingo'] as $col => $rot): ?>
                  <td><input name="funcoes[<?= $k ?>][<?= $col ?>]" inputmode="decimal" value="<?= $v($f[$col] ?? '') ?>" placeholder="0,00" aria-label="<?= e(($f['nome'] ?: 'Nova função') . ' · ' . $rot) ?>"></td>
                <?php endforeach; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <h2>Desconto por atraso</h2>
        <p class="dica">Conta a partir da entrada marcada na escala, pelo "Chegou às" anotado no ⋯ da linha.</p>
        <div class="tres-campos">
          <label>A cada (minutos) <input name="atraso_faixa" inputmode="numeric" value="<?= (int) $a['faixa'] ?>"></label>
          <label>Desconta (R$) <input name="atraso_desconto" inputmode="decimal" value="<?= $v($a['desconto']) ?>"></label>
          <label>Passou de (minutos), volta para casa <input name="atraso_limite" inputmode="numeric" value="<?= (int) $a['limite'] ?>"></label>
        </div>
        <p class="dica">Hoje: chegou até <?= (int) $a['faixa'] ?> min depois, −<?= e(dh_moeda((float) $a['desconto'])) ?>; até <?= 2 * (int) $a['faixa'] ?> min, −<?= e(dh_moeda(2 * (float) $a['desconto'])) ?>; passou de <?= (int) $a['limite'] ?> min, volta para casa sem diária.</p>
        <div class="barra-salvar"><button class="btn primario">Salvar funções, diárias e atraso</button></div>
      </form>
    <?php
}

function escala_tela_freelancers(): void
{
    global $csrf;
    $e = dh_escala();
    $segunda = escala_semana_param();
    $linhas = dh_escala_a_receber($e, $segunda);
    $pagos = $e['pagamentos'][$segunda] ?? [];
    $total = array_sum(array_column($linhas, 'total'));
    $semDiaria = array_filter($e['funcoes'], fn($f) => ($f['diaria_semana'] ?? '') === '' && ($f['diaria_fds'] ?? '') === '' && ($f['diaria_dom'] ?? '') === '');
    $paga = date('d/m', strtotime($segunda . ' +7 days'));
    financeiro_abas('freelancers');
    ?>
      <h1>Freelancers</h1>
      <?php escala_nav_semana('freelancers', $segunda); ?>
      <?php if ($semDiaria): ?><p class="aviso">Sem diária cadastrada: <?= e(implode(', ', array_column($semDiaria, 'nome'))) ?>. <a href="./?acao=escala-funcoes">Cadastrar as diárias</a>.</p><?php endif; ?>
      <div class="grade-metricas tres receita-metricas">
        <div class="cartao-metrica"><span>A pagar na segunda</span><strong><?= e(dh_moeda($total)) ?></strong><small><?= $paga ?><?= ($cx = array_sum(array_column($linhas, 'caixa'))) > 0 ? ' · ' . e(dh_moeda($cx)) . ' já saiu do caixa' : '' ?></small></div>
        <div class="cartao-metrica"><span>Já pago</span><strong><?= e(dh_moeda(array_sum(array_column($pagos, 'valor')))) ?></strong><small><?= count($pagos) ?> de <?= count($linhas) ?> freelancers</small></div>
        <div class="cartao-metrica"><span>Diárias</span><strong><?= array_sum(array_map(fn($l) => count(array_filter($l['itens'], fn($i) => !$i['falta'])), $linhas)) ?></strong><small><?php $nf = array_sum(array_map(fn($l) => count(array_filter($l['itens'], fn($i) => $i['falta'])), $linhas)); ?><?= $nf ?> falta<?= $nf === 1 ? '' : 's' ?></small></div>
      </div>
      <?php if (!$linhas): ?><p class="dica">Nenhum freelancer na escala desta semana.</p><?php endif; ?>
      <ul class="lista-freelas">
        <?php foreach ($linhas as $id => $l): $pg = $pagos[$id] ?? null; $p = $l['pessoa']; ?>
          <li class="<?= $pg ? 'pago' : '' ?>">
            <details>
              <summary><strong><?= e($p['nome']) ?></strong><span><?= count(array_filter($l['itens'], fn($i) => !$i['falta'])) ?> dia(s)<?= $p['pix'] !== '' ? ' · Pix ' . e($p['pix']) : ' · <b>sem Pix</b>' ?></span>
                <b class="valor-freela"><?= e(dh_moeda($l['total'])) ?><?= $l['caixa'] > 0 ? '<small> (' . e(dh_moeda($l['caixa'])) . ' já pago no caixa)</small>' : '' ?></b><?= $pg ? '<em class="tag ct-ok">Pago ' . e(date('d/m', strtotime($pg['pago_em']))) . '</em>' : '<em class="tag ct-preenchimento">A pagar</em>' ?></summary>
              <table class="tabela-receita">
                <?php foreach ($l['itens'] as $it): ?>
                  <tr><td><?= e(escala_dia_nome($it['dia'], true)) ?> · <?= e($it['funcao']) ?> <small><?= e($it['entrada']) ?>–<?= e($it['saida']) ?></small><?= $it['motivo'] !== '' ? '<br><small>' . e($it['motivo']) . '</small>' : '' ?></td>
                    <td><?= $it['dispensado'] ? '<b style="color:var(--erro)">chegou ' . e($it['chegada']) . ', voltou para casa</b>' : ($it['falta'] ? '<b style="color:var(--erro)">falta</b>' : e(dh_moeda($it['diaria'])) . ($it['desconto_atraso'] > 0 ? '<br><small>chegou ' . e($it['chegada']) . ' · −' . e(dh_moeda($it['desconto_atraso'])) . '</small>' : '')) ?></td><td><?= $it['ajuste'] != 0 ? ($it['ajuste'] > 0 ? '+' : '−') . e(dh_moeda(abs($it['ajuste']))) : '' ?><?= $it['pago_caixa'] > 0 ? '<br><small>pago no caixa −' . e(dh_moeda($it['pago_caixa'])) . '</small>' : '' ?></td></tr>
                <?php endforeach; ?>
              </table>
              <?php if ($pg && abs($pg['valor'] - $l['total']) > 0.005): ?><p class="aviso">A escala mudou depois do pagamento: foi pago <?= e(dh_moeda($pg['valor'])) ?>, agora soma <?= e(dh_moeda($l['total'])) ?>.</p><?php endif; ?>
              <div class="card-botoes">
                <?php if (!$pg): ?>
                  <form method="post" action="./?acao=freelancer-pagar" onsubmit="return confirm('Marcar <?= e(addslashes($p['nome'])) ?> como pago (<?= e(dh_moeda($l['total'])) ?>)?');"><?= $csrf ?><input type="hidden" name="semana" value="<?= $segunda ?>"><input type="hidden" name="pessoa" value="<?= e($id) ?>"><button class="btn primario">✅ Marcar como pago</button></form>
                <?php else: ?>
                  <form method="post" action="./?acao=freelancer-pagar" onsubmit="return confirm('Desfazer o pagamento de <?= e(addslashes($p['nome'])) ?>?');"><?= $csrf ?><input type="hidden" name="semana" value="<?= $segunda ?>"><input type="hidden" name="pessoa" value="<?= e($id) ?>"><input type="hidden" name="desfazer" value="1"><button class="btn secundario">↩ Desfazer pagamento</button></form>
                <?php endif; ?>
                <a class="btn secundario" target="_blank" rel="noopener" href="https://wa.me/<?= e(dh_whatsapp_numero($p['telefone'])) ?>?text=<?= e(rawurlencode(dh_escala_extrato($l, $segunda))) ?>">📲 Extrato no WhatsApp</a>
              </div>
            </details>
          </li>
        <?php endforeach; ?>
      </ul>
      <p class="dica">Os valores saem da <a href="./?acao=escala&amp;semana=<?= $segunda ?>">escala da semana</a>: diária da função (ou a própria da pessoa) por dia, sem as faltas, mais os ajustes, menos o que já foi pago no dia pelo caixa (sangria). Para corrigir, ajuste a escala.</p>
    <?php
}

// Abas do Financeiro: Freelancers · Bandas · Chope
function financeiro_abas(string $ativa): void
{
    $abas = array_filter(['freelancers' => ['Freelancers', pode('freelancers')], 'relatorio' => ['Bandas', pode('relatorio')], 'relatorios' => ['Chope', pode('vendas')]], fn($a) => $a[1]);
    if (count($abas) < 2) {
        return;
    }
    echo '<nav class="abas-financeiro" aria-label="Financeiro">';
    foreach ($abas as $acao => [$rot]) {
        echo '<a href="./?acao=' . $acao . '" class="' . ($acao === $ativa ? 'ativo' : '') . '">' . e($rot) . '</a>';
    }
    echo '</nav>';
}
