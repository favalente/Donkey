<?php
// Escala da equipe do restaurante (Painel → Escala) e pagamento dos freelancers (Financeiro → Freelancers).
// Cada dia tem uma lista de posições, como na planilha: função, nome, entrada e saída (vaga sem nome = em
// aberto). Quem é fixo (funcionário) aparece na escala mas não recebe diária; o freelancer recebe a diária
// da função por dia trabalhado, menos as faltas, mais/menos os ajustes (hora extra, desconto…). A semana
// vai de segunda a domingo e o pagamento é na segunda seguinte.
// Dados em dados/escala.json (só no servidor), com cópia diária em dados/backup.

const DH_ARQ_ESCALA = DH_RAIZ . '/dados/escala.json';
const DH_DIAS_SEMANA_ESCALA = [1 => 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado', 'Domingo'];
// Dias de funcionamento e a saída de costume (Ter a Qui até 23h, Sex e Sáb até 0h, Dom até 22h; a equipe sai 30 min depois)
const DH_ESCALA_DIAS_ABERTOS = [2 => '23:30', 3 => '23:30', 4 => '23:30', 5 => '00:30', 6 => '00:30', 7 => '22:30'];
const DH_ESCALA_ENTRADA_FIXO = '16:00';
const DH_ESCALA_ENTRADA_FREELA = '17:00';
// Funções e diárias de nov/2026: [Ter a Qui, Sex e Sáb, Domingo]. Atendentes (Rooftop, Pisos, Calçada) 90/120/80,
// Barman e Barback como o Atendente, Apoio 90/100/80, quem cobre o Caixa 120/120/100; o Bar superior (100), a Hostess
// (100) e o Concierge (130) só funcionam sexta e sábado.
const DH_ESCALA_FUNCOES_PADRAO = [
    'CAIXA' => [120, 120, 100], 'ATENDENTE' => [90, 120, 80], 'BAR SUP.' => [null, 100, null], 'BARMAN' => [90, 120, 80],
    'BARBACK' => [90, 120, 80], 'APOIO' => [90, 100, 80], 'HOSTESS' => [null, 100, null], 'CONCIERGE' => [null, 130, null],
];
// Ambientes onde trabalha o Atendente: na escala aparecem no lugar da função (como na planilha) e pagam a diária dela
const DH_ESCALA_AMBIENTES_PADRAO = ['ROOFTOP' => 'ATENDENTE', 'PISO SUP.' => 'ATENDENTE', 'PISO INF.' => 'ATENDENTE', 'CALÇADA' => 'ATENDENTE'];
// Atraso (a partir da entrada marcada na escala): R$ 10 a menos a cada 15 min; passou de 30 min, volta para casa sem diária
const DH_ESCALA_ATRASO_PADRAO = ['faixa' => 15, 'desconto' => '10', 'limite' => 30];

function dh_escala(): array
{
    $e = json_decode((string) @file_get_contents(DH_ARQ_ESCALA), true);
    $e = (is_array($e) ? $e : []) + ['pessoas' => [], 'funcoes' => [], 'dias' => [], 'pagamentos' => [], 'config' => []];
    $e['config'] += ['atraso' => DH_ESCALA_ATRASO_PADRAO];
    if (!$e['funcoes']) {
        $n = 0;
        foreach (array_keys(DH_ESCALA_FUNCOES_PADRAO + DH_ESCALA_AMBIENTES_PADRAO) as $f) {
            $e['funcoes'][] = ['id' => 'f' . ++$n, 'nome' => $f];
        }
    }
    // Rooftop, Pisos e Calçada são ambientes do Atendente (uma vez só): cria a função ATENDENTE com a diária que
    // esses ambientes tinham (ou a tabela) e liga cada ambiente a ela
    if (empty($e['config']['ambientes_2026_11'])) {
        $nomes = array_column($e['funcoes'], 'nome');
        if (!in_array('ATENDENTE', $nomes, true)) {
            $base = null;
            foreach ($e['funcoes'] as $f) {
                if (isset(DH_ESCALA_AMBIENTES_PADRAO[$f['nome']]) && ($f['diaria_semana'] ?? '') !== '') {
                    $base = $f;
                    break;
                }
            }
            $e['funcoes'][] = ['id' => dh_id_novo('f'), 'nome' => 'ATENDENTE'] + ($base ? array_intersect_key($base, array_flip(['diaria_semana', 'diaria_fds', 'diaria_dom'])) : []);
        }
        $porNome = array_column($e['funcoes'], 'id', 'nome');
        foreach ($e['funcoes'] as $i => $f) {
            if (isset(DH_ESCALA_AMBIENTES_PADRAO[$f['nome']])) {
                $e['funcoes'][$i]['de'] = $porNome[DH_ESCALA_AMBIENTES_PADRAO[$f['nome']]];
                $e['funcoes'][$i]['diaria_semana'] = $e['funcoes'][$i]['diaria_fds'] = $e['funcoes'][$i]['diaria_dom'] = '';
            }
        }
        $e['config']['ambientes_2026_11'] = true;
    }
    // Diária por dia da semana (Ter a Qui / Sex e Sáb / Domingo). Uma vez só: as funções da casa sem valor
    // recebem a tabela de nov/2026, e a diária única antiga vale para os três.
    foreach ($e['funcoes'] as $i => $f) {
        if (!empty($f['de'])) {
            continue; // ambiente: a diária é a da função
        }
        $tab = empty($e['config']['diarias_2026_11']) ? (DH_ESCALA_FUNCOES_PADRAO[$f['nome']] ?? null) : null;
        foreach (['diaria_semana' => 0, 'diaria_fds' => 1, 'diaria_dom' => 2] as $k => $n) {
            if (($f[$k] ?? '') === '') {
                $e['funcoes'][$i][$k] = ($f['diaria'] ?? '') !== '' ? $f['diaria'] : ($tab && $tab[$n] !== null ? number_format($tab[$n], 2, '.', '') : '');
            }
        }
        unset($e['funcoes'][$i]['diaria']);
        // Hostess e Concierge só sexta e sábado (uma vez só: tira os valores de Ter a Qui e Domingo da tabela anterior)
        if (empty($e['config']['hostess_concierge_fds']) && in_array($f['nome'], ['HOSTESS', 'CONCIERGE'], true)) {
            foreach (['diaria_semana', 'diaria_dom'] as $k) {
                if (in_array((float) ($e['funcoes'][$i][$k] ?? 0), [100.0, 130.0], true)) {
                    $e['funcoes'][$i][$k] = '';
                }
            }
        }
    }
    $e['config']['hostess_concierge_fds'] = true;
    // Barman e Barback ganham como o Atendente (uma vez só: troca o 120/120/100 da tabela anterior)
    if (empty($e['config']['barman_barback_2026_11'])) {
        foreach ($e['funcoes'] as $i => $f) {
            if (in_array($f['nome'], ['BARMAN', 'BARBACK'], true) && (float) ($f['diaria_semana'] ?? 0) === 120.0 && (float) ($f['diaria_dom'] ?? 0) === 100.0) {
                $e['funcoes'][$i]['diaria_semana'] = '90.00';
                $e['funcoes'][$i]['diaria_fds'] = '120.00';
                $e['funcoes'][$i]['diaria_dom'] = '80.00';
            }
        }
        $e['config']['barman_barback_2026_11'] = true;
    }
    // O bar superior só abre sexta e sábado (uma vez só: tira o 90 de Ter a Qui e o 80 de Domingo da tabela anterior)
    if (empty($e['config']['bar_sup_fds'])) {
        foreach ($e['funcoes'] as $i => $f) {
            if ($f['nome'] === 'BAR SUP.' && (float) ($f['diaria_semana'] ?? 0) === 90.0 && (float) ($f['diaria_dom'] ?? 0) === 80.0) {
                $e['funcoes'][$i]['diaria_semana'] = '';
                $e['funcoes'][$i]['diaria_dom'] = '';
            }
        }
        $e['config']['bar_sup_fds'] = true;
    }
    $e['config']['diarias_2026_11'] = true;
    return $e;
}

function dh_escala_salvar(array $e): void
{
    $dir = dirname(DH_ARQ_ESCALA);
    $json = json_encode($e, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $tmp = DH_ARQ_ESCALA . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) === false || !rename($tmp, DH_ARQ_ESCALA)) {
        @unlink($tmp);
        throw new RuntimeException('Não foi possível gravar a escala. Verifique a permissão da pasta "dados".');
    }
    if (!is_dir($dir . '/backup')) {
        mkdir($dir . '/backup', 0755, true);
    }
    @copy(DH_ARQ_ESCALA, $dir . '/backup/escala-' . date('Y-m-d') . '.json');
}

// Segunda-feira da semana de uma data
function dh_semana_inicio(string $data): string
{
    $t = strtotime($data);
    return date('Y-m-d', strtotime('-' . ((int) date('N', $t) - 1) . ' days', $t));
}

function dh_semana_dias(string $segunda): array
{
    return array_map(fn($n) => date('Y-m-d', strtotime($segunda . ' +' . $n . ' days')), range(0, 6));
}

function dh_escala_pessoa(array $e, string $id): ?array
{
    foreach ($e['pessoas'] as $p) {
        if ($p['id'] === $id) {
            return $p;
        }
    }
    return null;
}

function dh_escala_funcao(array $e, string $id): ?array
{
    foreach ($e['funcoes'] as $f) {
        if ($f['id'] === $id) {
            return $f;
        }
    }
    return null;
}

// Acha pela grafia (sem acento, sem diferenciar maiúsculas) ou cria: pessoa nova entra como freelancer
function dh_escala_pessoa_por_nome(array &$e, string $nome): string
{
    $nome = trim(preg_replace('/\s+/u', ' ', $nome));
    $chave = dh_sem_acento($nome);
    foreach ($e['pessoas'] as $p) {
        if (dh_sem_acento($p['nome']) === $chave) {
            return $p['id'];
        }
    }
    $id = dh_id_novo('p');
    $e['pessoas'][] = ['id' => $id, 'nome' => mb_convert_case(mb_strtolower($nome), MB_CASE_TITLE), 'tipo' => 'freelancer', 'telefone' => '', 'pix' => '', 'diaria' => '', 'ativo' => true];
    return $id;
}

function dh_escala_funcao_por_nome(array &$e, string $nome): string
{
    $nome = mb_strtoupper(trim(preg_replace('/\s+/u', ' ', $nome)));
    foreach ($e['funcoes'] as $f) {
        if (dh_sem_acento($f['nome']) === dh_sem_acento($nome)) {
            return $f['id'];
        }
    }
    $id = dh_id_novo('f');
    $e['funcoes'][] = ['id' => $id, 'nome' => $nome, 'diaria' => ''];
    return $id;
}

// Hora digitada como na planilha ("16", "16:00", "0:30", "1630") → "HH:MM"
function dh_escala_hora(string $h): string
{
    $h = trim(str_replace(['h', '.', ','], ':', mb_strtolower($h)), ': ');
    if (preg_match('/^(\d{1,2})(?::?(\d{2}))?$/', $h, $m) && (int) $m[1] < 24 && (int) ($m[2] ?? 0) < 60) {
        return sprintf('%02d:%02d', $m[1], $m[2] ?? 0);
    }
    return '';
}

// Coluna da diária pelo dia da semana: Sex e Sáb, Domingo ou Ter a Qui (e segunda)
function dh_escala_coluna_diaria(string $dia): string
{
    $n = (int) date('N', strtotime($dia));
    return $n === 7 ? 'diaria_dom' : ($n >= 5 ? 'diaria_fds' : 'diaria_semana');
}

// Valor da diária de uma posição no dia: o da pessoa (se tiver) ou o da função naquele dia da semana
function dh_escala_diaria(array $e, array $pos, string $dia): float
{
    $p = dh_escala_pessoa($e, $pos['pessoa'] ?? '');
    if ($p && ($p['diaria'] ?? '') !== '') {
        return (float) $p['diaria'];
    }
    $f = dh_escala_funcao($e, $pos['funcao'] ?? '') ?? [];
    if (!empty($f['de'])) {
        $f = dh_escala_funcao($e, $f['de']) ?? [];
    }
    return (float) ($f[dh_escala_coluna_diaria($dia)] ?? 0);
}

// "ATENDENTE · ROOFTOP" para ambiente, ou o nome da função
function dh_escala_funcao_rotulo(array $e, string $id): string
{
    $f = dh_escala_funcao($e, $id);
    if (!$f) {
        return '?';
    }
    return !empty($f['de']) ? (dh_escala_funcao($e, $f['de'])['nome'] ?? '?') . ' · ' . $f['nome'] : $f['nome'];
}

// Atraso pela chegada anotada: [minutos, desconto em R$, voltou para casa]
function dh_escala_atraso(array $e, array $pos): array
{
    $chegada = $pos['chegada'] ?? '';
    if ($chegada === '' || ($pos['entrada'] ?? '') === '') {
        return [0, 0.0, false];
    }
    $min = fn($h) => (int) substr($h, 0, 2) * 60 + (int) substr($h, 3, 2);
    $atraso = $min($chegada) - $min($pos['entrada']);
    if ($atraso < -360) {
        $atraso += 1440; // chegou depois da meia-noite
    }
    if ($atraso <= 0) {
        return [0, 0.0, false];
    }
    $c = $e['config']['atraso'] ?? DH_ESCALA_ATRASO_PADRAO;
    if ($atraso > (int) $c['limite']) {
        return [$atraso, 0.0, true];
    }
    return [$atraso, (int) ceil($atraso / max(1, (int) $c['faixa'])) * (float) $c['desconto'], false];
}

// O que cada freelancer tem a receber numa semana: [pessoa_id => ['pessoa', 'itens' => [...], 'total']]
function dh_escala_a_receber(array $e, string $segunda): array
{
    $r = [];
    foreach (dh_semana_dias($segunda) as $dia) {
        foreach ($e['dias'][$dia]['posicoes'] ?? [] as $pos) {
            $p = dh_escala_pessoa($e, $pos['pessoa'] ?? '');
            if (!$p || ($p['tipo'] ?? 'freelancer') !== 'freelancer') {
                continue;
            }
            [$atrasoMin, $descAtraso, $dispensado] = dh_escala_atraso($e, $pos);
            $falta = !empty($pos['falta']) || $dispensado;
            $diaria = $falta ? 0.0 : dh_escala_diaria($e, $pos, $dia) - $descAtraso;
            $ajuste = (float) ($pos['ajuste'] ?? 0);
            $caixa = (float) ($pos['pago_caixa'] ?? 0);
            $r[$p['id']] ??= ['pessoa' => $p, 'itens' => [], 'total' => 0.0, 'caixa' => 0.0];
            $semDiaria = !$falta && dh_escala_diaria($e, $pos, $dia) <= 0;
            $r[$p['id']]['itens'][] = ['sem_diaria' => $semDiaria, 'dia' => $dia, 'funcao' => dh_escala_funcao_rotulo($e, $pos['funcao']), 'entrada' => $pos['entrada'], 'saida' => $pos['saida'],
                'diaria' => $diaria, 'falta' => $falta, 'ajuste' => $ajuste, 'motivo' => $pos['motivo'] ?? '', 'pago_caixa' => $caixa,
                'chegada' => $pos['chegada'] ?? '', 'atraso' => $atrasoMin, 'desconto_atraso' => $descAtraso, 'dispensado' => $dispensado];
            // "total" é o que falta pagar na segunda: o que já saiu do caixa no dia (sangria) não entra
            $r[$p['id']]['total'] += $diaria + $ajuste - $caixa;
            $r[$p['id']]['caixa'] += $caixa;
        }
    }
    uasort($r, fn($a, $b) => strcoll(dh_sem_acento($a['pessoa']['nome']), dh_sem_acento($b['pessoa']['nome'])));
    return $r;
}

// Extrato da semana para mandar no WhatsApp
function dh_escala_extrato(array $linha, string $segunda): string
{
    $t = 'Donkey Head Cervejaria · semana de ' . date('d/m', strtotime($segunda)) . ' a ' . date('d/m', strtotime($segunda . ' +6 days')) . "\n" . $linha['pessoa']['nome'] . "\n\n";
    foreach ($linha['itens'] as $it) {
        $t .= mb_substr(DH_DIAS_SEMANA_ESCALA[(int) date('N', strtotime($it['dia']))], 0, 3) . ' ' . date('d/m', strtotime($it['dia'])) . ' · ' . $it['funcao']
            . (!empty($it['dispensado']) ? ' · chegou ' . $it['chegada'] . ', voltou para casa' : ($it['falta'] ? ' · FALTA' : ' · ' . dh_moeda($it['diaria'])
                . (!empty($it['desconto_atraso']) ? ' (chegou ' . $it['chegada'] . ', −' . dh_moeda($it['desconto_atraso']) . ')' : '')))
            . ($it['ajuste'] != 0 ? ' · ajuste ' . ($it['ajuste'] > 0 ? '+' : '−') . dh_moeda(abs($it['ajuste'])) . ($it['motivo'] !== '' ? ' (' . $it['motivo'] . ')' : '') : '')
            . (($it['pago_caixa'] ?? 0) > 0 ? ' · já recebido no caixa ' . dh_moeda($it['pago_caixa']) : '') . "\n";
    }
    return $t . (($linha['caixa'] ?? 0) > 0 ? "\nJá recebido no caixa: " . dh_moeda($linha['caixa']) : '') . "\nA receber: " . dh_moeda($linha['total']);
}
