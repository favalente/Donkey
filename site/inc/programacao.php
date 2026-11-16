<?php
// Programação de brassagem: sugere o que brassar na semana a partir do que há em estoque (última
// contagem da fábrica e das casas), do que está nos tanques e do consumo de cada cerveja.
// O administrador marca o que vai ser brassado; a equipe da cervejaria vê só o que foi marcado.

declare(strict_types=1);

const DH_PROG_CATEGORIAS = [
    'essencial' => 'De linha · nunca pode faltar',
    'linha' => 'De linha · raramente pode faltar',
    'sazonal' => 'Sazonal · de vez em quando',
    'rara' => 'Sazonal · raramente',
    'fora' => 'Fora de linha',
];
const DH_PROG_DIAS_SEM_DRYHOP = 16;
const DH_PROG_DIAS_COM_DRYHOP = 30;
const DH_PROG_MARGEM_DIAS = 7;    // folga além do tempo até ficar pronto
const DH_PROG_MAX_SEMANA = 4;     // brassagens por semana, no máximo

// Classificação inicial (dá para mudar na tela): palavras do nome da receita -> categoria
const DH_PROG_CLASSES = [
    'fora' => ['arabica', 'cacau brownie', 'pele encarnada', 'reliz', 'west coast chocolate'],
    'rara' => ['dragao do mar', 'maracutaia', 'metanoia', 'pifitao', 'red hop', 'rye se lascar', 'rye timbora', 'santa stout', 'strawberry', 'strowberry'],
    'sazonal' => ['assum preto', 'quadrupel', 'tripel', 'new england', 'azedinha', 'velas do mucuripe'],
    'essencial' => ['stigm', 'mandacar', 'soft hop', 'softhop', 'premium lager', 'lager'],
    'linha' => ['severina caju', 'caju', 'miolo de pote', 'asa branca', 'galo campina', 'mel da goiaba', 'vixe', 'peste'],
];

// Chave de comparação de nomes: sem acento, só letras e números, sem "DH"
function dh_prog_chave(string $nome): string
{
    $s = dh_sem_acento(mb_strtolower($nome));
    $s = preg_replace('/\bdh\b/', '', $s);
    return preg_replace('/[^a-z0-9]/', '', $s);
}

// Grupo de brassagem de uma cerveja: as Pestes (todos os sabores) e a Pardal Negro saem da mesma
// brassagem da Peste Negra; as Severinas de outras frutas são sazonais, a de linha é a de caju
function dh_prog_grupo(string $nome): string
{
    $s = dh_sem_acento(mb_strtolower($nome));
    if (preg_match('/peste|pardal/', $s)) {
        return 'pestenegra';
    }
    if (preg_match('/new ?england/', $s)) {
        return 'newengland';
    }
    if (str_contains($s, 'severina')) {
        return str_contains($s, 'caju') ? 'severinacaju' : 'severinafrutas';
    }
    return dh_prog_chave($nome);
}

function dh_prog_categoria_inicial(string $nome): string
{
    $s = dh_sem_acento(mb_strtolower($nome));
    if (str_contains($s, 'severina') && !str_contains($s, 'caju')) {
        return 'sazonal';
    }
    foreach (DH_PROG_CLASSES as $cat => $chaves) {
        foreach ($chaves as $k) {
            if (str_contains($s, $k)) {
                return $cat;
            }
        }
    }
    return 'sazonal';
}

function dh_prog_tem_dryhop(?array $rc): bool
{
    foreach ($rc['insumos'] ?? [] as $it) {
        if (dh_insumo_maturacao($it)) {
            return true;
        }
    }
    return false;
}

// Grupos de brassagem a partir das receitas: [chave => nome, receitas, dryhop, dias, categoria, consumo, dobro]
function dh_prog_grupos(array $b): array
{
    $cfg = $b['config']['programacao']['grupos'] ?? [];
    $g = [];
    foreach (dh_receitas()['receitas'] as $rc) {
        $k = dh_prog_grupo($rc['nome']);
        if ($k === '') {
            continue;
        }
        $g[$k] ??= ['chave' => $k, 'nome' => $rc['nome'], 'receitas' => [], 'dryhop' => false];
        $g[$k]['receitas'][] = $rc;
        $g[$k]['dryhop'] = $g[$k]['dryhop'] || dh_prog_tem_dryhop($rc);
        if (mb_strlen($rc['nome']) < mb_strlen($g[$k]['nome'])) {
            $g[$k]['nome'] = $rc['nome'];
        }
    }
    foreach ($g as $k => &$x) {
        if ($k === 'pestenegra') {
            $x['nome'] = 'Peste Negra (e variações)';
        } elseif ($k === 'newengland') {
            $x['nome'] = 'New England (alternando)';
        } elseif ($k === 'severinafrutas') {
            $x['nome'] = 'Severina de frutas';
        }
        $x['dias'] = $x['dryhop'] ? DH_PROG_DIAS_COM_DRYHOP : DH_PROG_DIAS_SEM_DRYHOP;
        $x['categoria'] = $cfg[$k]['categoria'] ?? dh_prog_categoria_inicial($x['nome']);
        $x['consumo'] = (string) ($cfg[$k]['consumo'] ?? '');
        $x['dobro'] = $cfg[$k]['dobro'] ?? str_contains($k, 'lager');
    }
    unset($x);
    uasort($g, fn($a, $c) => [array_search($a['categoria'], array_keys(DH_PROG_CATEGORIAS)), $a['nome']] <=> [array_search($c['categoria'], array_keys(DH_PROG_CATEGORIAS)), $c['nome']]);
    return $g;
}

// Acha o grupo de um nome digitado na contagem ou no caderno ("Lager" -> grupo da Premium Lager)
function dh_prog_achar_grupo(string $nome, array $grupos): ?string
{
    $k = dh_prog_grupo($nome);
    if (isset($grupos[$k])) {
        return $k;
    }
    if (strlen($k) < 4) {
        return null;
    }
    foreach ($grupos as $gk => $g) {
        foreach ($g['receitas'] as $rc) {
            $rk = dh_prog_chave($rc['nome']);
            if ($rk !== '' && (str_contains($rk, $k) || str_contains($k, $rk))) {
                return $gk;
            }
        }
    }
    return null;
}

// Barris (equivalentes a 50 L cheios) na última contagem da fábrica e das casas
function dh_prog_estoque(array $grupos): array
{
    $r = [];
    $c = dh_contagem();
    foreach (array_keys(DH_CONTAGEM_CASAS) as $casa) {
        $ult = dh_contagem_anterior($c, $casa);
        foreach ($ult['linhas'] ?? [] as $l) {
            if ($l['unidade'] !== 'barril' || $l['qtd'] === null) {
                continue;
            }
            $gk = dh_prog_achar_grupo($l['nome'], $grupos);
            if (!$gk) {
                continue;
            }
            $tam = ($l['litros'] ?? '') === '30' ? 0.6 : 1.0;
            $barris = !empty($l['barris']) ? array_sum(array_map(fn($p) => (int) $p / 100, $l['barris'])) + max(0, $l['qtd'] - count($l['barris'])) : (float) $l['qtd'];
            $r[$gk] = ($r[$gk] ?? 0) + $barris * $tam;
        }
    }
    return $r;
}

// Lotes nos tanques (fermentando ou maturando): barris de 50 L e quando ficam prontos
function dh_prog_tanques(array $b, array $grupos): array
{
    $r = [];
    foreach ($b['lotes'] as $l) {
        if ($l['status'] === 'finalizado') {
            continue;
        }
        $gk = dh_prog_achar_grupo($l['cerveja'], $grupos);
        if (!$gk) {
            continue;
        }
        $litros = ($l['og_cm'] ?? '') !== '' ? (float) dh_litros_cm((string) $l['og_cm']) : (float) (($l['receita']['volume_l'] ?? '') ?: 450);
        $dias = dh_prog_tem_dryhop($l['receita'] ?? null) || ($grupos[$gk]['dryhop'] ?? false) ? DH_PROG_DIAS_COM_DRYHOP : DH_PROG_DIAS_SEM_DRYHOP;
        $r[$gk][] = ['barris' => round($litros / 50, 1), 'pronto' => date('Y-m-d', strtotime(($l['data'] ?: date('Y-m-d')) . ' +' . $dias . ' days')), 'tanque' => dh_tanque($b, $l['tanque'])['nome'] ?? '', 'lote' => $l['lote']];
    }
    return $r;
}

// Sugestões da semana: a situação de cada grupo e se deve ser brassado, da mais urgente para a menos
function dh_prog_sugestoes(array $b): array
{
    $grupos = dh_prog_grupos($b);
    $estoque = dh_prog_estoque($grupos);
    $tanques = dh_prog_tanques($b, $grupos);
    $livres = count(array_filter($b['tanques'], fn($t) => ($t['ativo'] ?? true) && !dh_tanque_lote($b, $t['id'])));
    $linhas = [];
    foreach ($grupos as $k => $g) {
        if (in_array($g['categoria'], ['fora'], true)) {
            continue;
        }
        $est = round($estoque[$k] ?? 0, 1);
        $tq = array_sum(array_column($tanques[$k] ?? [], 'barris'));
        $disp = $est + $tq;
        $consumo = (float) str_replace(',', '.', $g['consumo']);
        $precisa = ($g['dias'] + DH_PROG_MARGEM_DIAS) * ($g['dobro'] ? 2 : 1);
        $cobertura = $consumo > 0 ? $disp / ($consumo / 7) : null;
        $sugerir = false;
        $motivo = '';
        if (in_array($g['categoria'], ['essencial', 'linha'], true)) {
            if ($cobertura !== null) {
                $sugerir = $cobertura < $precisa;
                $motivo = 'Dá para ' . (int) round($cobertura) . ' dias; um lote novo leva ' . $g['dias'] . ($g['dobro'] ? ' (estoque em dobro)' : '');
            } else {
                $sugerir = $disp < ($g['dobro'] ? 4 : 2);
                $motivo = !$sugerir ? '' : ($disp <= 0 ? 'Sem estoque e nada no tanque' : 'Estoque baixo (informe o consumo por semana para o cálculo ficar certo)');
            }
        } elseif ($g['categoria'] === 'sazonal') {
            $sugerir = $disp <= 0;
            $motivo = $disp <= 0 ? 'Sazonal sem estoque: brassar se houver tanque' : '';
        }
        $peso = ['essencial' => 0, 'linha' => 1, 'sazonal' => 2, 'rara' => 3][$g['categoria']] ?? 4;
        $linhas[] = $g + ['estoque' => $est, 'tanque' => $tanques[$k] ?? [], 'em_tanque' => $tq, 'cobertura' => $cobertura, 'sugerir' => $sugerir, 'motivo' => $motivo,
            'ordem' => [$sugerir ? 0 : 1, $peso, $cobertura ?? ($disp > 0 ? 999 : -1)]];
    }
    usort($linhas, fn($a, $c) => $a['ordem'] <=> $c['ordem']);
    // Só o que cabe nos tanques livres (e no máximo 4 por semana)
    $vagas = min($livres, DH_PROG_MAX_SEMANA);
    $n = 0;
    foreach ($linhas as &$l) {
        if ($l['sugerir'] && ++$n > $vagas) {
            $l['sugerir'] = false;
            $l['motivo'] .= ' · fica para a próxima (' . ($vagas < DH_PROG_MAX_SEMANA ? 'só ' . $vagas . ' tanque' . ($vagas !== 1 ? 's' : '') . ' livre' . ($vagas !== 1 ? 's' : '') : 'até ' . DH_PROG_MAX_SEMANA . ' brassagens por semana') . ')';
            $l['espera'] = true;
        }
    }
    unset($l);
    return ['linhas' => $linhas, 'livres' => $livres, 'vagas' => $vagas];
}

// Programação marcada de uma semana (segunda-feira)
function dh_prog_semana(array $b, string $segunda): array
{
    $l = $b['config']['programacao']['semanas'][$segunda] ?? [];
    usort($l, fn($x, $y) => strcmp($x['dia'] . $x['nome'], $y['dia'] . $y['nome']));
    return $l;
}
