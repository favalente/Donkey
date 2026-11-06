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
const DH_ESCALA_FUNCOES_PADRAO = ['CAIXA', 'ROOFTOP', 'BAR SUP.', 'PISO SUP.', 'BARMAN', 'BARBACK', 'PISO INF.', 'CALÇADA', 'APOIO', 'HOSTESS', 'CONCIERGE'];

function dh_escala(): array
{
    $e = json_decode((string) @file_get_contents(DH_ARQ_ESCALA), true);
    $e = (is_array($e) ? $e : []) + ['pessoas' => [], 'funcoes' => [], 'dias' => [], 'pagamentos' => []];
    if (!$e['funcoes']) {
        foreach (DH_ESCALA_FUNCOES_PADRAO as $n => $f) {
            $e['funcoes'][] = ['id' => 'f' . ($n + 1), 'nome' => $f, 'diaria' => ''];
        }
    }
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

// Valor da diária de uma posição: o da pessoa (se tiver) ou o da função
function dh_escala_diaria(array $e, array $pos): float
{
    $p = dh_escala_pessoa($e, $pos['pessoa'] ?? '');
    if ($p && ($p['diaria'] ?? '') !== '') {
        return (float) $p['diaria'];
    }
    return (float) (dh_escala_funcao($e, $pos['funcao'] ?? '')['diaria'] ?? 0);
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
            $falta = !empty($pos['falta']);
            $diaria = $falta ? 0.0 : dh_escala_diaria($e, $pos);
            $ajuste = (float) ($pos['ajuste'] ?? 0);
            $r[$p['id']] ??= ['pessoa' => $p, 'itens' => [], 'total' => 0.0];
            $r[$p['id']]['itens'][] = ['dia' => $dia, 'funcao' => dh_escala_funcao($e, $pos['funcao'])['nome'] ?? '?', 'entrada' => $pos['entrada'], 'saida' => $pos['saida'],
                'diaria' => $diaria, 'falta' => $falta, 'ajuste' => $ajuste, 'motivo' => $pos['motivo'] ?? ''];
            $r[$p['id']]['total'] += $diaria + $ajuste;
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
            . ($it['falta'] ? ' · FALTA' : ' · ' . dh_moeda($it['diaria']))
            . ($it['ajuste'] != 0 ? ' · ajuste ' . ($it['ajuste'] > 0 ? '+' : '−') . dh_moeda(abs($it['ajuste'])) . ($it['motivo'] !== '' ? ' (' . $it['motivo'] . ')' : '') : '') . "\n";
    }
    return $t . "\nTotal: " . dh_moeda($linha['total']);
}
