<?php
// Sugestão de pedido de malte e lúpulo (Inventário / Contagem): quanto vai ser usado nas próximas semanas
// (brassagens de cada cerveja × receita), menos o que tem na última contagem da fábrica.
// As brassagens por cerveja começam pelo ritmo das últimas 12 semanas (cadernos de brassagem) e dá
// para mudar na tela. As New England se alternam (All My Love, Caldo de Bila, Citranela), cada uma
// com 5 kg do seu lúpulo (single hop).

declare(strict_types=1);

const DH_SACA_KG = 25;
// Malte como é comprado e contado (Swaen) e os nomes equivalentes (Viking, Agrária, apelidos).
// A ordem importa: o primeiro que bater vale ("Pilsen Barley" é Pilsen, não Barley).
const DH_MALTES_EQUIV = [
    'Pilsen' => '/pils/',
    'Wheat Classic (trigo)' => '/whiteswaen|wheat|trigo|weiz/',
    'Melany' => '/melan|red ale/',
    'Chocolate B' => '/chocolate/',
    'Munich Dark' => '/munich dark|munick|caramel 150|cara ?150|caramunich/',
    'Light (Carapils)' => '/caramelo pale|cara ?pale|carapils|goldswaen light|^light/',
    'Rye' => '/\brye\b|centeio/',
    'Red' => '/caramel 50\b|goldswaen red|^red\b/',
    'Classic' => '/caramel 100|goldswaen classic|^classic/',
    'Brown' => '/caramel 200|brown/',
    'Barley (cevada torrada)' => '/cevada torrada|roasted|blackswaen barley|^barley/',
];
// New England: cada receita, na ordem da alternância, e o lúpulo dela (5 kg)
const DH_NE_RODIZIO = ['all my love' => 'Mosaic', 'caldo de bila' => 'Galaxy', 'citranela' => 'Citra'];
const DH_NE_LUPULO_KG = 5;

function dh_insumo_malte(string $nome): ?string
{
    $s = trim(dh_sem_acento(mb_strtolower($nome)));
    foreach (DH_MALTES_EQUIV as $canon => $re) {
        if (preg_match($re, $s)) {
            return $canon;
        }
    }
    return null;
}

// "Mosaic [12,5%] – Dry hop" -> "Mosaic"
function dh_insumo_lupulo(string $nome): string
{
    $n = trim(preg_split('/\s*[\[(–-]\s*/u', $nome)[0]);
    return mb_convert_case(mb_strtolower($n), MB_CASE_TITLE);
}

// Malte e lúpulo de uma receita, em kg: ['malte' => [nome => kg], 'lupulo' => [nome => kg], 'sem' => [nomes sem equivalência]]
function dh_insumos_receita(array $rc, ?string $loteNE = null): array
{
    $r = ['malte' => [], 'lupulo' => [], 'sem' => []];
    foreach ($rc['insumos'] ?? [] as $x) {
        $q = (float) str_replace(',', '.', (string) ($x['qtd'] ?? ''));
        if ($q <= 0) {
            continue;
        }
        $u = mb_strtolower((string) ($x['unidade'] ?? ''));
        $kg = $u === 'g' ? $q / 1000 : ($u === 'kg' ? $q : null);
        if ($kg === null) {
            continue;
        }
        if ($u === 'g' || preg_match('/\[\d/', (string) $x['nome'])) {
            $r['lupulo'][dh_insumo_lupulo((string) $x['nome'])] = ($r['lupulo'][dh_insumo_lupulo((string) $x['nome'])] ?? 0) + $kg;
            continue;
        }
        $m = dh_insumo_malte((string) $x['nome']);
        if ($m === null) {
            // Açúcar, fruta, etc. ficam de fora; o resto aparece como "sem equivalência"
            if (!preg_match('/a[cç]ucar|sugar|dextrose|lactose|fruta|caju|goiaba|mel\b|honey|cacau|cafe|coffee|baunilha|vanilla|sal\b|salt|gesso|gypsum|cloreto|acido|acid|whirlfloc|irish moss|levedura|yeast|nutriente/', dh_sem_acento(mb_strtolower((string) $x['nome'])))) {
                $r['sem'][] = (string) $x['nome'];
                $r['malte'][(string) $x['nome']] = ($r['malte'][(string) $x['nome']] ?? 0) + $kg;
            }
            continue;
        }
        $r['malte'][$m] = ($r['malte'][$m] ?? 0) + $kg;
    }
    // New England: single hop de 5 kg (vale o combinado, não o que estiver na receita)
    if ($loteNE !== null) {
        $r['lupulo'] = [DH_NE_RODIZIO[$loteNE] => DH_NE_LUPULO_KG];
    }
    return $r;
}

// Receita de cada New England, na ordem da alternância
function dh_insumos_ne_receitas(array $grupo): array
{
    $r = [];
    foreach (DH_NE_RODIZIO as $nome => $_) {
        foreach ($grupo['receitas'] as $rc) {
            if (str_contains(dh_sem_acento(mb_strtolower($rc['nome'])), $nome)) {
                $r[$nome] = $rc;
                break;
            }
        }
    }
    return $r;
}

// Brassagens por semana de cada grupo nas últimas 12 semanas (cadernos) e a última New England feita
function dh_insumos_ritmo(array $b, array $grupos): array
{
    $desde = date('Y-m-d', strtotime('-84 days'));
    $n = [];
    $ultNE = null;
    $lotes = $b['lotes'];
    usort($lotes, fn($x, $y) => strcmp($x['data'], $y['data']));
    foreach ($lotes as $l) {
        $g = dh_prog_achar_grupo((string) $l['cerveja'], $grupos, (string) ($l['cerveja_id'] ?? ''));
        if ($g === null) {
            continue;
        }
        if ($g === 'newengland') {
            foreach (DH_NE_RODIZIO as $nome => $_) {
                if (str_contains(dh_sem_acento(mb_strtolower((string) $l['cerveja'])), $nome)) {
                    $ultNE = $nome;
                }
            }
        }
        if ($l['data'] >= $desde) {
            $n[$g] = ($n[$g] ?? 0) + 1;
        }
    }
    return ['por_semana' => array_map(fn($x) => $x / 12, $n), 'ultima_ne' => $ultNE];
}

// Estoque de malte e lúpulo (kg) na última contagem da fábrica
function dh_insumos_estoque(): array
{
    $c = dh_contagem();
    $ult = dh_contagem_anterior($c, 'fabrica');
    $r = ['malte' => [], 'lupulo' => [], 'data' => $ult['data'] ?? ''];
    foreach ($ult['linhas'] ?? [] as $l) {
        $local = dh_sem_acento(mb_strtolower($l['local']));
        $tipo = str_contains($local, 'malte') ? 'malte' : (str_contains($local, 'lupulo') ? 'lupulo' : null);
        if (!$tipo) {
            continue;
        }
        $kg = match ($l['unidade']) {
            'saca' => (float) dh_contagem_total($l),
            'kg' => (float) $l['qtd'],
            default => null,
        };
        if ($kg === null) {
            continue;
        }
        $nome = $tipo === 'malte' ? (dh_insumo_malte($l['nome']) ?? $l['nome']) : dh_insumo_lupulo($l['nome']);
        $r[$tipo][$nome] = ($r[$tipo][$nome] ?? 0) + $kg;
    }
    return $r;
}

// Pedido sugerido. $brassagens = [grupo => quantas no período]. Devolve as linhas de malte e lúpulo.
function dh_insumos_pedido(array $b, array $grupos, array $brassagens, ?string $ultimaNE): array
{
    $precisa = ['malte' => [], 'lupulo' => []];
    $sem = [];
    $semReceita = [];
    $neOrdem = [];
    foreach ($brassagens as $g => $n) {
        $n = (int) $n;
        if ($n <= 0 || !isset($grupos[$g])) {
            continue;
        }
        if ($g === 'newengland') {
            $rcs = dh_insumos_ne_receitas($grupos[$g]);
            $nomes = array_keys(DH_NE_RODIZIO);
            $i = $ultimaNE !== null ? (array_search($ultimaNE, $nomes, true) + 1) % count($nomes) : 0;
            for ($k = 0; $k < $n; $k++, $i = ($i + 1) % count($nomes)) {
                $nome = $nomes[$i];
                $neOrdem[] = $nome;
                $ins = isset($rcs[$nome]) ? dh_insumos_receita($rcs[$nome], $nome) : ['malte' => [], 'lupulo' => [DH_NE_RODIZIO[$nome] => DH_NE_LUPULO_KG], 'sem' => []];
                if (!isset($rcs[$nome])) {
                    $semReceita[] = mb_convert_case($nome, MB_CASE_TITLE);
                }
                foreach (['malte', 'lupulo'] as $t) {
                    foreach ($ins[$t] as $nm => $kg) {
                        $precisa[$t][$nm] = ($precisa[$t][$nm] ?? 0) + $kg;
                    }
                }
                $sem = array_merge($sem, $ins['sem']);
            }
            continue;
        }
        $rc = $grupos[$g]['receitas'][0] ?? null;
        if (!$rc) {
            continue;
        }
        $ins = dh_insumos_receita($rc);
        foreach (['malte', 'lupulo'] as $t) {
            foreach ($ins[$t] as $nm => $kg) {
                $precisa[$t][$nm] = ($precisa[$t][$nm] ?? 0) + $kg * $n;
            }
        }
        $sem = array_merge($sem, $ins['sem']);
    }
    $est = dh_insumos_estoque();
    $linhas = [];
    foreach (['malte', 'lupulo'] as $t) {
        $nomes = array_unique(array_merge(array_keys($precisa[$t]), array_keys($est[$t])));
        foreach ($nomes as $nm) {
            $p = round($precisa[$t][$nm] ?? 0, 2);
            $e = round($est[$t][$nm] ?? 0, 2);
            $falta = max(0, $p - $e);
            $linhas[$t][] = ['nome' => $nm, 'precisa' => $p, 'estoque' => $e, 'falta' => round($falta, 2),
                'pedir' => $t === 'malte' ? (int) ceil($falta / DH_SACA_KG - 1e-9) : (float) ceil($falta - 1e-9)];
        }
        usort($linhas[$t], fn($x, $y) => [$y['pedir'] > 0, $y['precisa']] <=> [$x['pedir'] > 0, $x['precisa']]);
    }
    return ['malte' => $linhas['malte'] ?? [], 'lupulo' => $linhas['lupulo'] ?? [], 'sem' => array_values(array_unique($sem)),
        'sem_receita' => array_values(array_unique($semReceita)), 'ne' => $neOrdem, 'estoque_data' => $est['data']];
}
