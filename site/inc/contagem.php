<?php
// Contagem de estoque (aba Contagem): o barman conta por lugar (câmara, estoque seco, estoque frio…),
// cada item com quantidade e unidade (barril, un, fardo…). Começa copiando a contagem anterior, pelas
// cervejas engatadas ou colando o texto que ele mandava no WhatsApp.

declare(strict_types=1);

const DH_ARQ_CONTAGEM = DH_RAIZ . '/dados/contagem.json';
const DH_CONTAGEM_UNIDADES = ['barril', 'saca', 'un', 'fardo', 'caixa', 'garrafa', 'lata', 'kg', 'L', 'pacote'];
// Onde se conta: as duas casas e a fábrica (com os lugares de cada uma)
const DH_CONTAGEM_CASAS = ['aldeota' => 'Aldeota', 'maraponga' => 'Maraponga', 'fabrica' => 'Fábrica'];
const DH_CONTAGEM_LOCAIS_CASA = ['fabrica' => ['Câmara produção', 'Câmara estoque', 'Maltes', 'Lúpulos', 'Leveduras'], '*' => ['Câmara produção', 'Câmara estoque', 'Estoque seco', 'Estoque frio']];

function dh_contagem_locais(string $casa): array
{
    return DH_CONTAGEM_LOCAIS_CASA[$casa] ?? DH_CONTAGEM_LOCAIS_CASA['*'];
}

function dh_contagem_casa_nome(string $casa): string
{
    return DH_CONTAGEM_CASAS[$casa] ?? (DH_UNIDADES[$casa] ?? $casa);
}
const DH_CONTAGEM_LITROS = ['50', '30'];
// Malte: sacas fechadas (de 25 kg) + os quilos que sobram na saca aberta
const DH_CONTAGEM_SACA_KG = ['25']; // as sacas da Donkey Head são sempre de 25 kg

// Total do item para comparar com a contagem anterior: saca vira quilos; o resto, a quantidade
function dh_contagem_total(array $l): ?float
{
    if ($l['unidade'] === 'saca' && ($l['qtd'] !== null || (float) ($l['aberta_kg'] ?? 0) > 0)) {
        return round((float) $l['qtd'] * (float) ($l['saca_kg'] ?? 25) + (float) ($l['aberta_kg'] ?? 0), 2);
    }
    if ($l['qtd'] === null) {
        return null;
    }
    return (float) $l['qtd'];
}
// Situação de cada barril: na câmara de produção já vem "mexido", na de estoque "cheio"
const DH_CONTAGEM_STATUS = ['100' => 'Cheio (100%)', '80' => 'Mexido 80%', '50' => 'Mexido 50%', '20' => 'Mexido 20%'];
const DH_CONTAGEM_ABERTO = ['' => '', 'cheio' => 'Cheio (100%)', '90' => 'Mexido 90%', '60' => 'Mexido 60%', '30' => 'Mexido 30%']; // contagens antigas

function dh_contagem_status_padrao(string $local): string
{
    return preg_match('/produ/iu', $local) ? '80' : '100';
}

// Uma situação para cada barril (quantidade inteira), completando com o padrão do lugar
function dh_contagem_barris(array $l): array
{
    $n = max(0, (int) floor((float) ($l['qtd'] ?? 0)));
    $b = array_values(array_filter((array) ($l['barris'] ?? []), fn($x) => isset(DH_CONTAGEM_STATUS[(string) $x])));
    $b = array_map('strval', array_slice($b, 0, $n));
    while (count($b) < $n) {
        $b[] = dh_contagem_status_padrao($l['local'] ?? '');
    }
    return $b;
}

// Quantidade para mostrar: [número em destaque, resto]. Malte sem saca fechada: só os kg da aberta.
function dh_contagem_qtd_partes(array $l): array
{
    if ($l['unidade'] === 'saca' && (float) $l['qtd'] <= 0 && (float) ($l['aberta_kg'] ?? 0) > 0) {
        return [dh_num((float) $l['aberta_kg']), 'kg (saca aberta)'];
    }
    return [dh_qtd_txt($l['qtd']), dh_contagem_unidade_txt($l['unidade'], $l['qtd']) . dh_contagem_detalhe_barril($l)];
}

// "2 barris de 50 L (1 cheio, 1 mexido 50%)"
function dh_contagem_detalhe_barril(array $l): string
{
    if ($l['unidade'] === 'saca') {
        // "3 sacas de 25 kg + 8 kg na aberta = 83 kg"
        $ab = (float) ($l['aberta_kg'] ?? 0);
        return ' de ' . ($l['saca_kg'] ?? '25') . ' kg' . ($ab > 0 ? ' + ' . dh_num($ab) . ' kg na aberta' : '') . ($l['qtd'] !== null ? ' = ' . dh_num(dh_contagem_total($l)) . ' kg' : '');
    }
    if ($l['unidade'] !== 'barril') {
        return '';
    }
    $t = ($l['litros'] ?? '') !== '' ? ' de ' . $l['litros'] . ' L' : '';
    if (!empty($l['barris'])) {
        $cont = array_count_values($l['barris']);
        $partes = [];
        foreach (DH_CONTAGEM_STATUS as $k => $rot) {
            if (!empty($cont[$k])) {
                $partes[] = $cont[$k] . ' ' . mb_strtolower(preg_replace('/\s*\(100%\)/', '', $rot));
            }
        }
        return $t . ($partes ? ' (' . implode(', ', $partes) . ')' : '');
    }
    return $t . (($l['aberto'] ?? '') !== '' ? ' (1 ' . mb_strtolower(DH_CONTAGEM_ABERTO[$l['aberto']] ?? '') . ')' : '');
}

function dh_contagem(): array
{
    $c = json_decode((string) @file_get_contents(DH_ARQ_CONTAGEM), true);
    return (is_array($c) ? $c : []) + ['contagens' => []];
}

function dh_contagem_salvar(array $c): void
{
    if (!is_dir(dirname(DH_ARQ_CONTAGEM))) {
        mkdir(dirname(DH_ARQ_CONTAGEM), 0755, true);
    }
    $tmp = DH_ARQ_CONTAGEM . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) === false || !rename($tmp, DH_ARQ_CONTAGEM)) {
        @unlink($tmp);
        throw new RuntimeException('Não foi possível gravar a contagem. Verifique a permissão da pasta "dados".');
    }
}

// "6,5" "6 + 1/2" "1+ 1/2" "1/2" "½" "3" -> número (null se vazio ou inválido)
function dh_qtd_ler(string $s): ?float
{
    $s = trim(str_replace(['½', '¼', '¾'], ['+1/2', '+1/4', '+3/4'], mb_strtolower($s)));
    $s = preg_replace('/\s+/', '', $s);
    $s = ltrim($s, '+');
    if ($s === '') {
        return null;
    }
    $total = 0.0;
    foreach (explode('+', $s) as $p) {
        if (preg_match('/^(\d+)\/(\d+)$/', $p, $m) && (int) $m[2] > 0) {
            $total += (int) $m[1] / (int) $m[2];
        } elseif (preg_match('/^\d+(?:[.,]\d+)?$/', $p)) {
            $total += (float) str_replace(',', '.', $p);
        } else {
            return null;
        }
    }
    return round($total, 2);
}

// 1.5 -> "1½", 0.5 -> "½", 3 -> "3"
function dh_qtd_txt(?float $q): string
{
    if ($q === null) {
        return '—';
    }
    $int = (int) floor($q);
    $frac = round($q - $int, 2);
    $f = ['0.5' => '½', '0.25' => '¼', '0.75' => '¾'][(string) $frac] ?? null;
    if ($frac == 0) {
        return (string) $int;
    }
    return $f !== null ? ($int ? $int : '') . $f : dh_num(rtrim(rtrim(number_format($q, 2, '.', ''), '0'), '.'));
}

function dh_contagem_unidade_txt(string $u, ?float $q): string
{
    $plural = ['barril' => 'barris', 'un' => 'un', 'fardo' => 'fardos', 'caixa' => 'caixas', 'garrafa' => 'garrafas', 'lata' => 'latas', 'pacote' => 'pacotes', 'saca' => 'sacas'];
    return $q !== null && $q > 1 ? ($plural[$u] ?? $u) : $u;
}

// Chave para comparar o mesmo item entre contagens (lugar + nome + unidade, sem acento)
function dh_contagem_chave(array $l): string
{
    return dh_sem_acento(mb_strtolower(trim($l['local']) . '|' . trim($l['nome']) . '|' . $l['unidade'] . ($l['unidade'] === 'barril' ? '|' . ($l['litros'] ?? '') : '')));
}

// Linhas organizadas por lugar, na ordem em que aparecem
function dh_contagem_por_local(array $linhas): array
{
    $r = [];
    foreach ($linhas as $l) {
        $r[$l['local']][] = $l;
    }
    return $r;
}

// Última contagem enviada de uma casa (antes de uma data, se dada)
function dh_contagem_anterior(array $c, string $casa, string $antesDe = '', string $excetoId = ''): ?array
{
    $melhor = null;
    foreach ($c['contagens'] as $x) {
        if ($x['casa'] !== $casa || $x['status'] !== 'enviada' || $x['id'] === $excetoId || ($antesDe !== '' && $x['data'] > $antesDe)) {
            continue;
        }
        if (!$melhor || [$x['data'], $x['em']] > [$melhor['data'], $melhor['em']]) {
            $melhor = $x;
        }
    }
    return $melhor;
}

// Texto colado do WhatsApp -> linhas. Linha sem número = nome do lugar; "3 fardo guaraná zero",
// "1un Ypioca prata", "Softhop 1 barril", "Lager 2 barril (mexido 1)", "1/2 fardo de fanta uva"
function dh_contagem_do_texto(string $txt): array
{
    $unidades = ['barris' => 'barril', 'barril' => 'barril', 'fardos' => 'fardo', 'fardo' => 'fardo', 'unidades' => 'un', 'unidade' => 'un', 'un' => 'un',
        'caixas' => 'caixa', 'caixa' => 'caixa', 'cx' => 'caixa', 'garrafas' => 'garrafa', 'garrafa' => 'garrafa', 'latas' => 'lata', 'lata' => 'lata',
        'pacotes' => 'pacote', 'pacote' => 'pacote', 'sacas' => 'saca', 'saca' => 'saca', 'sacos' => 'saca', 'saco' => 'saca', 'kg' => 'kg', 'l' => 'L'];
    $uRe = implode('|', array_keys($unidades));
    $num = '(\d+\/\d+|\d+(?:\s*[.,]\s*\d+)?(?:\s*\+\s*\d+\/\d+)?)';
    $local = 'Estoque';
    $linhas = [];
    foreach (preg_split('/\r?\n/', $txt) as $ln) {
        $ln = trim(preg_replace('/\s+/u', ' ', $ln));
        if ($ln === '' || preg_match('/^\d{1,2}\/\d{1,2}(\/\d{2,4})?$/', $ln) || preg_match('/^data\b/iu', $ln)) {
            continue;
        }
        $obs = '';
        if (preg_match('/\(([^)]*)\)\s*$/u', $ln, $m)) {
            $obs = trim($m[1]);
            $ln = trim(substr($ln, 0, -strlen($m[0])));
        }
        $q = null;
        $u = 'un';
        $nome = '';
        // Número no começo: "3 fardo guaraná zero", "1un Ypioca", "6 + 1/2 fardo coca"
        if (preg_match('/^' . $num . '\s*(?:(' . $uRe . ')\b\.?)?\s*(?:de\s+)?(.+)$/iu', $ln, $m)) {
            $q = dh_qtd_ler($m[1]);
            $u = $unidades[mb_strtolower($m[2] ?? '')] ?? 'un';
            $nome = $m[3];
        // Número depois do nome: "Softhop 1 barril", "Peste Amarula 1barril de 30"
        } elseif (preg_match('/^(.+?)\s*' . $num . '\s*(' . $uRe . ')?\b\.?\s*(.*)$/iu', $ln, $m) && trim($m[1]) !== '') {
            $q = dh_qtd_ler($m[2]);
            $u = $unidades[mb_strtolower($m[3] ?? '')] ?? 'un';
            $nome = $m[1];
            if (trim($m[4]) !== '') {
                $obs = trim(trim($m[4]) . ($obs !== '' ? ' · ' . $obs : ''));
            }
        }
        if ($q === null || trim($nome) === '') {
            // Sem quantidade: é o nome de um lugar ou grupo ("Câmara produção", "Vinhos")
            $local = mb_strimwidth(trim($ln, " :-"), 0, 60);
            continue;
        }
        // Vinhos e cervejas engarrafadas sem unidade escrita: garrafa
        if ($u === 'un' && !preg_match('/\b(un|unidades?)\b/iu', $ln) && preg_match('/engarraf|vinho|garraf/iu', $local)) {
            $u = 'garrafa';
        }
        // Barril: "de 30" vira o tamanho; sem tamanho escrito, 50 L (o mais comum)
        $litros = '';
        if ($u === 'barril') {
            $litros = '50';
            if (preg_match('/\bde\s*(30|50)\s*(l|litros)?\b/iu', $obs, $mm)) {
                $litros = $mm[1];
                $obs = trim(preg_replace('/\bde\s*(30|50)\s*(l|litros)?\b/iu', '', $obs), ' ·-');
            }
        }
        $linhas[] = ['local' => $local, 'nome' => mb_strimwidth(trim($nome, " -:"), 0, 80), 'unidade' => $u, 'qtd' => $q, 'obs' => mb_strimwidth($obs, 0, 80), 'litros' => $litros, 'barris' => []];
    }
    return $linhas;
}

// Texto para mandar no WhatsApp
function dh_contagem_texto(array $x): string
{
    $t = '*Contagem de estoque · ' . dh_contagem_casa_nome($x['casa']) . ' · ' . date('d/m', strtotime($x['data'])) . "*\n";
    foreach (dh_contagem_por_local($x['linhas']) as $local => $ls) {
        $t .= "\n*" . $local . "*\n";
        foreach ($ls as $l) {
            $t .= $l['nome'] . ': ' . implode(' ', dh_contagem_qtd_partes($l)) . ($l['obs'] !== '' ? ' (' . $l['obs'] . ')' : '') . "\n";
        }
    }
    return $t . "\nContado por " . ($x['por'] ?? '');
}
