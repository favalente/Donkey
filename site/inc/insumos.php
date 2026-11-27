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
// Lúpulo por brassagem de cada cerveja (importado da planilha de pedido): fica só no servidor
const DH_ARQ_INSUMOS = DH_RAIZ . '/dados/insumos.json';
// Mesmos lúpulos escritos de jeitos diferentes (planilha, receitas, contagem)
const DH_LUPULO_NOMES = ['collumbus' => 'Columbus', 'columbus' => 'Columbus', 'ctz' => 'Columbus', 'herkules' => 'Herkules', 'hercules' => 'Herkules',
    'magnun' => 'Magnum', 'idaho7' => 'Idaho 7', 'idaho 7' => 'Idaho 7', 'sorache ace' => 'Sorachi Ace', 'sorachi ace' => 'Sorachi Ace', 'el dorado' => 'El Dorado'];
// Colunas da planilha -> cerveja (o "4 X Lager" é dividido por 4)
const DH_PLANILHA_CERVEJAS = ['velas' => 'Velas do Mucuripe', 'apa' => 'StigmAPA', 'ipa' => 'MandacarIPA', 'soft' => 'Soft Hop', 'asa' => 'Asa Branca', 'miolo' => 'Miolo de Pote', 'lager' => 'Premium Lager'];

function dh_insumos_cfg(): array
{
    $r = json_decode((string) @file_get_contents(DH_ARQ_INSUMOS), true);
    return (is_array($r) ? $r : []) + ['lupulos' => [], 'atualizado_em' => ''];
}

function dh_insumos_cfg_salvar(array $r): void
{
    if (!is_dir(dirname(DH_ARQ_INSUMOS))) {
        mkdir(dirname(DH_ARQ_INSUMOS), 0755, true);
    }
    $tmp = DH_ARQ_INSUMOS . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) === false || !rename($tmp, DH_ARQ_INSUMOS)) {
        @unlink($tmp);
        throw new RuntimeException('Não foi possível gravar. Verifique a permissão da pasta "dados".');
    }
}

// Planilha de pedido de lúpulo: o primeiro bloco ("Lupulo | Quant | Velas | APA | …") vira kg por brassagem.
// Devolve [grupo => [lúpulo => kg]] e as colunas que não deu para ligar a uma cerveja.
function dh_insumos_ler_planilha(string $arq, array $grupos): array
{
    $linhas = dh_xlsx_linhas($arq);
    $cab = null;
    $r = [];
    $semCerveja = [];
    foreach ($linhas as $l) {
        $a = dh_sem_acento(mb_strtolower(trim((string) ($l[0] ?? ''))));
        if ($cab === null) {
            if ($a === 'lupulo') {
                $cab = [];
                foreach ($l as $i => $v) {
                    $n = dh_sem_acento(mb_strtolower(trim((string) $v)));
                    if ($i < 2 || $n === '' || $n === 'total') {
                        continue;
                    }
                    if (preg_match('/new england|all my love|caldo|citranela/', $n)) {
                        continue; // New England: 5 kg de single hop, já combinado
                    }
                    $div = preg_match('/(\d+)\s*x\s*(.+)/', $n, $m) ? max(1, (int) $m[1]) : 1;
                    $base = trim($m[2] ?? $n);
                    $nome = DH_PLANILHA_CERVEJAS[$base] ?? $base;
                    $g = dh_prog_achar_grupo($nome, $grupos);
                    if ($g === null) {
                        $semCerveja[] = trim((string) $v);
                        continue;
                    }
                    $cab[$i] = [$g, $div];
                }
            }
            continue;
        }
        if ($a === '' || str_starts_with($a, 'total')) {
            break; // fim do primeiro bloco (Aldeota)
        }
        $lup = dh_insumo_lupulo((string) $l[0]);
        foreach ($cab as $i => [$g, $div]) {
            $kg = (float) str_replace(',', '.', (string) ($l[$i] ?? ''));
            if ($kg > 0) {
                $r[$g][$lup] = round(($r[$g][$lup] ?? 0) + $kg / $div, 3);
            }
        }
    }
    if ($cab === null) {
        throw new RuntimeException('Não achei a linha "Lupulo | Quant | …" na planilha.');
    }
    return ['lupulos' => $r, 'sem_cerveja' => $semCerveja];
}

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
    $n = trim(preg_split('/\s*[\[(–]|\s+-\s+/u', $nome)[0]);
    $k = dh_sem_acento(mb_strtolower($n));
    return DH_LUPULO_NOMES[$k] ?? mb_convert_case(mb_strtolower($n), MB_CASE_TITLE);
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
        $planilha = dh_insumos_cfg()['lupulos'][$g] ?? null;
        if (!$rc && !$planilha) {
            continue;
        }
        $ins = $rc ? dh_insumos_receita($rc) : ['malte' => [], 'lupulo' => [], 'sem' => []];
        if ($planilha) {
            $ins['lupulo'] = $planilha; // lúpulo por brassagem da planilha vale mais que o da receita
        }
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
        $linhas[$t] = [];
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
