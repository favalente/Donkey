<?php
// Brassagem: o caderno de produção em versão digital. Cada tanque da fábrica (única, atende as duas sedes) tem no máximo um lote
// ativo; o lote guarda o cabeçalho do caderno (cerveja, lote, levedura, PF/OG em Brix e litragem em cm)
// e o acompanhamento da fermentação e maturação (data, Brix, pressão, temperatura e anotação).
// Painel → Brassagem. Dados em dados/brassagem.json (só no servidor), com cópia diária em dados/backup.

const DH_ARQ_BRASSAGEM = DH_RAIZ . '/dados/brassagem.json';
const DH_LOTE_STATUS = ['fermentando' => 'Fermentando', 'maturando' => 'Maturando', 'finalizado' => 'Finalizado'];

function dh_brassagem(): array
{
    $b = json_decode((string) @file_get_contents(DH_ARQ_BRASSAGEM), true);
    $b = (is_array($b) ? $b : []) + ['tanques' => [], 'lotes' => []];
    // Tanques da fábrica: 1 a 11, todos de 500 L menos o 10 (450 L). Entram uma vez só, enquanto não
    // houver nenhum tanque; depois o cadastro é pelo painel (⚙ Tanques).
    if (!$b['tanques'] && empty($b['tanques_iniciais'])) {
        foreach (range(1, 11) as $n) {
            $b['tanques'][] = ['id' => sprintf('t%02d', $n), 'nome' => 'Tanque ' . $n, 'capacidade' => $n === 10 ? '450' : '500', 'litros_cm' => '', 'ativo' => true];
        }
        $b['tanques_iniciais'] = true;
    }
    return $b;
}

function dh_brassagem_salvar(array $b): void
{
    $dir = dirname(DH_ARQ_BRASSAGEM);
    $json = json_encode($b, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $tmp = DH_ARQ_BRASSAGEM . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) === false || !rename($tmp, DH_ARQ_BRASSAGEM)) {
        @unlink($tmp);
        throw new RuntimeException('Não foi possível gravar a brassagem. Verifique a permissão da pasta "dados".');
    }
    // O caderno é o histórico da produção: guarda uma cópia por dia
    if (!is_dir($dir . '/backup')) {
        mkdir($dir . '/backup', 0755, true);
    }
    @copy(DH_ARQ_BRASSAGEM, $dir . '/backup/brassagem-' . date('Y-m-d') . '.json');
}

function dh_brassagem_indice(array $lista, string $id): ?int
{
    foreach ($lista as $i => $x) {
        if ($x['id'] === $id) {
            return $i;
        }
    }
    return null;
}

function dh_tanque(array $b, string $id): ?array
{
    $i = dh_brassagem_indice($b['tanques'], $id);
    return $i === null ? null : $b['tanques'][$i];
}

// Lote que está no tanque agora (fermentando ou maturando)
function dh_tanque_lote(array $b, string $tanque): ?array
{
    foreach ($b['lotes'] as $l) {
        if ($l['tanque'] === $tanque && $l['status'] !== 'finalizado') {
            return $l;
        }
    }
    return null;
}

// Próximo número de lote: o maior número já usado + 1
function dh_lote_proximo(array $b): string
{
    $nums = array_map(fn($l) => (int) preg_replace('/\D/', '', $l['lote']), $b['lotes']);
    return $nums ? (string) (max($nums) + 1) : '';
}

// Dia da produção (dia 0 = dia da brassagem)
function dh_lote_dia(array $l, ?string $ate = null): int
{
    if (!$l['data']) {
        return 0;
    }
    return (int) floor((strtotime($ate ?? date('Y-m-d')) - strtotime($l['data'])) / 86400);
}

function dh_lote_registros(array $l): array
{
    $r = $l['registros'] ?? [];
    usort($r, fn($a, $b) => [$a['data'], $a['em']] <=> [$b['data'], $b['em']]);
    return $r;
}

// Última medida de um campo (brix, pressao, temp) no acompanhamento
function dh_lote_ultima(array $l, string $campo): ?array
{
    foreach (array_reverse(dh_lote_registros($l)) as $r) {
        if (($r[$campo] ?? '') !== '') {
            return $r;
        }
    }
    return null;
}

// Litragem em cm vira litros quando o tanque tem a medida "litros por cm"
function dh_tanque_litros(?array $t, string $cm): ?float
{
    $f = (float) ($t['litros_cm'] ?? 0);
    return $f > 0 && $cm !== '' ? round((float) $cm * $f, 1) : null;
}

// Brix → densidade aproximada (SG), para quem prefere ver em OG/FG
function dh_brix_sg(string $brix): ?string
{
    if ($brix === '') {
        return null;
    }
    $bx = (float) $brix;
    return number_format(1 + $bx / (258.6 - (($bx / 258.2) * 227.1)), 3, ',', '');
}

// Cervejas do cadastro do site (Painel → Cervejas), em ordem de nome, para escolher a do tanque
function dh_brassagem_cervejas(): array
{
    $l = array_values(array_filter(dh_carregar()['cervejas'] ?? [], fn($c) => ($c['nome'] ?? '') !== ''));
    usort($l, fn($a, $b) => strnatcasecmp($a['nome'], $b['nome']));
    return $l;
}

// A cerveja do cadastro de um lote (pelo id escolhido; lotes antigos, pelo nome)
function dh_lote_cerveja(array $l, ?array $cervejas = null): ?array
{
    foreach ($cervejas ?? dh_brassagem_cervejas() as $c) {
        if ((($l['cerveja_id'] ?? '') !== '' && $c['id'] === $l['cerveja_id']) || mb_strtolower($c['nome']) === mb_strtolower($l['cerveja'])) {
            return $c;
        }
    }
    return null;
}
