<?php
// Painel: agenda de shows, cadastro de bandas (com fotos), relatório financeiro e
// importação do Google Agenda. Cachê e consumação só aparecem para quem pode ver valores.

// "1.200,50" / "1200.5" / "R$ 800" -> "1200.50"; vazio se não for número
function dh_valor($v): string
{
    $s = preg_replace('/[^\d,.]/', '', (string) $v);
    if ($s === '') {
        return '';
    }
    if (strpos($s, ',') !== false) {
        $s = str_replace(['.', ','], ['', '.'], $s);
    } elseif (substr_count($s, '.') > 1 || preg_match('/\.\d{3}$/', $s)) {
        $s = str_replace('.', '', $s);
    }
    return is_numeric($s) ? number_format((float) $s, 2, '.', '') : '';
}

function dh_mes_param(): string
{
    $m = (string) ($_GET['mes'] ?? '');
    return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m) ? $m : date('Y-m');
}

function dh_mes_nome(string $mes): string
{
    return DH_MESES[(int) substr($mes, 5, 2)] . ' de ' . substr($mes, 0, 4);
}

function dh_mes_somar(string $mes, int $n): string
{
    return date('Y-m', strtotime($mes . '-01 ' . ($n >= 0 ? '+' : '') . $n . ' month'));
}

function dh_tributos_texto(string $t): array
{
    $l = array_map('trim', preg_split('/[\r\n;]+/', $t));
    return array_values(array_unique(array_filter($l, fn($x) => $x !== '')));
}

function dh_buscar_banda(array &$a, string $id): int
{
    foreach ($a['bandas'] as $i => $b) {
        if ($b['id'] === $id) {
            return $i;
        }
    }
    throw new RuntimeException('Banda não encontrada.');
}

// ---- Importação do Google Agenda (.ics) ----

function dh_ics_baixar(string $url): string
{
    if (!preg_match('#^https://#i', $url)) {
        throw new RuntimeException('Cole o endereço que começa com https:// (o "endereço secreto no formato iCal").');
    }
    $url = preg_replace('#^webcal://#i', 'https://', $url);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
        CURLOPT_TIMEOUT => 20, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS]);
    $txt = curl_exec($ch);
    $cod = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!is_string($txt) || $cod !== 200 || strpos($txt, 'BEGIN:VCALENDAR') === false) {
        throw new RuntimeException('Não consegui ler a agenda nesse endereço (resposta ' . $cod . '). Confira se copiou o "endereço secreto no formato iCal".');
    }
    return $txt;
}

// Lê os eventos de um .ics (já convertendo o horário para Fortaleza)
function dh_ics_eventos(string $ics): array
{
    $ics = preg_replace("/\r?\n[ \t]/", '', str_replace("\r\n", "\n", $ics));
    $eventos = [];
    $fuso = new DateTimeZone('America/Fortaleza');
    foreach (explode('BEGIN:VEVENT', $ics) as $i => $bloco) {
        if ($i === 0) {
            continue;
        }
        $bloco = explode('END:VEVENT', $bloco)[0];
        $e = [];
        foreach (explode("\n", $bloco) as $linha) {
            if (!preg_match('/^([A-Z-]+)((?:;[^:]*)?):(.*)$/', $linha, $m)) {
                continue;
            }
            $e[$m[1]] = ['param' => $m[2], 'valor' => $m[3]];
        }
        if (!isset($e['DTSTART']) || ($e['STATUS']['valor'] ?? '') === 'CANCELLED') {
            continue;
        }
        $v = $e['DTSTART']['valor'];
        $diaInteiro = strlen($v) === 8;
        try {
            if (substr($v, -1) === 'Z') {
                $dt = new DateTime($v, new DateTimeZone('UTC'));
            } elseif (preg_match('/TZID=([^;:]+)/', $e['DTSTART']['param'], $tz)) {
                $dt = new DateTime($v, new DateTimeZone($tz[1]));
            } else {
                $dt = new DateTime($v, $fuso);
            }
        } catch (Exception $ex) {
            continue;
        }
        $dt->setTimezone($fuso);
        $txt = fn($k) => trim(str_replace(['\\n', '\\N', '\\,', '\\;', '\\\\'], ["\n", "\n", ',', ';', '\\'], $e[$k]['valor'] ?? ''));
        $eventos[] = [
            'uid' => $e['UID']['valor'] ?? '',
            'data' => $dt->format('Y-m-d'),
            'hora' => $diaInteiro ? '' : $dt->format('H:i'),
            'titulo' => $txt('SUMMARY'),
            'descricao' => $txt('DESCRIPTION'),
            'local' => $txt('LOCATION'),
        ];
    }
    usort($eventos, fn($x, $y) => strcmp($x['data'] . $x['hora'], $y['data'] . $y['hora']));
    return $eventos;
}

// "DI SOUSA BAND" -> "Di Sousa Band" (só palavras todas em maiúsculas; siglas conhecidas como RPM ficam)
function dh_capitalizar(string $t): string
{
    $minusculas = ['de', 'da', 'do', 'das', 'dos', 'e', 'a', 'o', 'ao', 'aos', 'as', 'os', 'com', 'em', 'no', 'na'];
    $palavras = preg_split('/(\s+)/u', trim($t), -1, PREG_SPLIT_DELIM_CAPTURE);
    $primeira = true;
    foreach ($palavras as $i => $w) {
        if (trim($w) === '') {
            continue;
        }
        $letras = preg_replace('/[^\p{L}]/u', '', $w);
        $siglas = ['RPM', 'DJ', 'MPB', 'U2', 'TNT', 'UFO', 'REM', 'KLB', 'AC', 'DC', 'CPM', 'LS', 'BB'];
        if ($letras !== '' && mb_strtoupper($letras, 'UTF-8') === $letras && !in_array($letras, $siglas, true)) {
            $w = mb_strtolower($w, 'UTF-8');
            if ($primeira || !in_array($w, $minusculas, true)) {
                $w = mb_convert_case($w, MB_CASE_TITLE, 'UTF-8');
            }
        }
        $palavras[$i] = $w;
        $primeira = false;
    }
    return implode('', $palavras);
}

// Separa por vírgula, sem quebrar o que está entre parênteses: "A, B (x, y), C" -> [A, B (x, y), C]
function dh_partes_virgula(string $t): array
{
    $partes = [];
    $atual = '';
    $nivel = 0;
    foreach (preg_split('//u', $t, -1, PREG_SPLIT_NO_EMPTY) as $c) {
        if ($c === '(') {
            $nivel++;
        } elseif ($c === ')') {
            $nivel = max(0, $nivel - 1);
        }
        if ($c === ',' && $nivel === 0) {
            $partes[] = trim($atual);
            $atual = '';
        } else {
            $atual .= $c;
        }
    }
    $partes[] = trim($atual);
    return array_values(array_filter($partes, fn($p) => $p !== ''));
}

// Separa no nível de cima (fora de parênteses) por vírgula, " - " e "X: TRIBUTO"
function dh_segmentos(string $t): array
{
    $segs = [];
    foreach (dh_partes_virgula($t) as $p) {
        $nivel = 0;
        $atual = '';
        $chars = preg_split('//u', $p, -1, PREG_SPLIT_NO_EMPTY);
        $n = count($chars);
        for ($i = 0; $i < $n; $i++) {
            $c = $chars[$i];
            $nivel += $c === '(' ? 1 : ($c === ')' && $nivel > 0 ? -1 : 0);
            $antes = $chars[$i - 1] ?? '';
            $depois = $chars[$i + 1] ?? '';
            // traço com espaço de pelo menos um lado separa ("X - Y", "X- Y", "X -Y"); "A-ha" não
            if ($nivel === 0 && ($c === '-' || $c === '–') && ($antes === ' ' || $depois === ' ')) {
                $segs[] = trim($atual);
                $atual = '';
                continue;
            }
            // "BANDA SONORAH: TRIBUTO ..." separa; "TRIBUTO: Arctic..." não
            if ($nivel === 0 && $c === ':' && $depois === ' ' && !preg_match('/(tribut\w*|data|cach[eê]|consum\w*|cover)\s*$/iu', $atual)) {
                $segs[] = trim($atual);
                $atual = '';
                continue;
            }
            $atual .= $c;
        }
        $segs[] = trim($atual);
    }
    return array_values(array_filter($segs, fn($x) => $x !== ''));
}

// Separa banda, tributo, unidade, horário, valores e situação de um evento do Google Agenda.
// Entende os vários jeitos que a casa escreve, ex.:
// "DI SOUSA BAND, DONKEY HEAD MARAPONGA, 30/10/26 AS 20H30, TRIBUTO ROCK BRASIL (...), CACHE R$450 CONSUMACAO R$160"
// "PAGO - BANDA TAPE F - TRIBUTO TEMAS DE FILMES, SÉRIES E ANIMES, DONKEY HEAD MARAPONGA, DATA: ..."
// "4 VENTOS, AEROSMITH, BON JOVI, GUNS E RED HOT, DONKEY HEAD MARAPONGA, ..."  "Coda R$800"  "Fets"
function dh_ics_interpretar(array $ev, string $horaPadrao, string $unidadePadrao = 'aldeota', array $chavesBandas = []): array
{
    $titulo = trim(preg_replace('/\s+/u', ' ', $ev['titulo']));
    $tudo = $titulo . "\n" . $ev['descricao'] . "\n" . $ev['local'];
    $unidade = preg_match('/maraponga|\bmpg\b/iu', $tudo) ? 'maraponga' : (preg_match('/aldeota/iu', $tudo) ? 'aldeota' : $unidadePadrao);
    $status = '';
    // "Ruazinha - Carlos Voice": área da casa, não faz parte do nome
    $titulo = preg_replace('/^\s*rua(zinha)?\b\s*[-–:]?\s*/iu', '', $titulo);
    while (preg_match('/^\s*(pag[oa]s?|paho|cancelad[oa])\b\s*[-–:]?\s*/iu', $titulo, $mp)) {
        $status = stripos($mp[1], 'cancel') === 0 ? 'cancelado' : 'pago';
        $titulo = substr($titulo, strlen($mp[0]));
    }
    if (preg_match('/cancelad/iu', $titulo)) {
        $status = 'cancelado';
    }
    preg_match('/cach[eê]\s*[:=\-]?\s*(?:R\$)?\s*([\d.,]+)/iu', $tudo, $mc);
    preg_match('/(?:consuma[cç][aã]o|consumo|\bcons)\s*[:=\-]?\s*(?:R\$)?\s*([\d.,]+)/iu', $tudo, $mo);
    if (!$mc) {
        // "Coda R$800", "VINNY r$900": valor solto é o cachê
        preg_match('/R\$\s*([\d.,]+)/iu', preg_replace('/(?:consuma[cç][aã]o|consumo|\bcons)\s*[:=\-]?\s*(?:R\$)?\s*[\d.,]+/iu', '', $titulo), $mc);
    }
    $hora = $ev['hora'] === '00:00' ? '' : $ev['hora'];
    if (preg_match('/\b(?:[aà]s|a partir das)\s*(\d{1,2})\s*(?:h|:)\s*(\d{2})?/iu', $tudo, $mh)
        || preg_match('/\b(\d{1,2}):(\d{2})\s*h?\b/u', $titulo, $mh)
        || preg_match('/\b(\d{1,2})\s*h\s*(\d{2})?\b/iu', $titulo, $mh)) {
        if ((int) $mh[1] >= 12 && (int) $mh[1] < 24) {
            $hora = sprintf('%02d:%02d', (int) $mh[1], (int) ($mh[2] ?? 0));
        }
    }

    // Instagram citado no título
    $instagram = '';
    if (preg_match('/@([a-z0-9_.]{3,30})/iu', $titulo, $mi)) {
        $instagram = $mi[1];
        $titulo = trim(str_replace($mi[0], '', $titulo));
    }
    // Tudo que vem depois de local/data/valores não é banda nem tributo
    $meta = '/\b(don[kl]ey|data\s*:|cach[eê]|consum|cons\b|r\$|maraponga|aldeota|barril|v[eé]spera|feriado|n[ao]\s+rua(zinha)?\b)|\b(dia\s+)?\d{1,2}\/(\d{1,2}|[a-zç]{3})|\b[aà]s\s*(\d|-|$)|\b\d{1,2}[:h]\d{2}h?\b|\b\d{1,2}h\b|\b(segunda|terça|terca|quarta|quinta|sext\w*|s[aá]bado|domingo|monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b|\b\d{1,2}\s*(de\s+)?(jan|fev|feb|mar|abr|apr|mai|may|jun|jul|ago|aug|set|sep|out|oct|nov|dez|dec)[a-zç]*\.?(\s|$)/iu';
    $chave = '/\b(tribut\w*|cover|homenagem|especial|ac[uú]stico)\b/iu';
    $partes = [];
    foreach (dh_segmentos($titulo) as $seg) {
        if (preg_match($meta, $seg, $mm, PREG_OFFSET_CAPTURE)) {
            $seg = trim(substr($seg, 0, $mm[0][1]), " ,.-–:(");
        }
        // sobra sem palavra de verdade ("as", "-") é descartada
        if (mb_strlen(preg_replace('/[^\p{L}\d]/u', '', $seg)) >= 3 && preg_match('/\p{L}/u', $seg)) {
            $partes[] = $seg;
        }
    }
    $banda = '';
    $tributo = [];
    $emTributo = false;
    foreach ($partes as $p) {
        if (preg_match('/^com\s+(.+)$/iu', $p, $mcom)) {
            $banda = $mcom[1];
            continue;
        }
        // "Banda Ragnarock (Love Metal Tribute)": tributo entre parênteses
        if ($banda === '' && preg_match('/^(.+?)\s*\((.*)\)\s*$/u', $p, $mpp) && preg_match($chave, $mpp[2])) {
            $banda = $mpp[1];
            $tributo[] = $mpp[2];
            $emTributo = true;
            continue;
        }
        if (preg_match($chave, $p, $mk, PREG_OFFSET_CAPTURE)) {
            if ($mk[0][1] > 0 && $banda === '') {
                // "OUTSIDER TRIBUTO TV UNIAO", "BANDA POLIFONICA (TRIBUTO MALHAÇÃO)"
                $banda = trim(substr($p, 0, $mk[0][1]), " ([-–:");
                $p = rtrim(substr($p, $mk[0][1]), ')');
            }
            // "Tributo a Elvis Presley com Léo Cand e Banda"
            if ($banda === '' && preg_match('/^(.+?)\s+com\s+(.+)$/iu', $p, $mcom)) {
                $p = $mcom[1];
                $banda = $mcom[2];
            }
            $tributo[] = $p;
            $emTributo = true;
        } elseif ($banda === '' && !$emTributo) {
            $banda = $p;
        } else {
            $tributo[] = $p;
        }
    }
    // "Banda Horda (O Rappa, Charlie Brown...)": o que está entre parênteses é o tributo
    if (preg_match('/^(.*?)\s*\((.*)\)\s*$/u', $banda, $mpar)) {
        $banda = trim($mpar[1]);
        if (!$tributo && mb_strlen($mpar[2]) > 6) {
            $tributo[] = $mpar[2];
        }
    }
    // "Tributo Guns, Bon Jovi e Kiss, Fets Domino": a banda vem no fim
    if ($banda === '' && count($tributo) > 1) {
        $ult = end($tributo);
        if (preg_match('/^(banda|com)\s|\s(e\s+)?banda$|\sband$/iu', $ult) || isset($chavesBandas[dh_chave_banda($ult)])) {
            array_pop($tributo);
            $banda = preg_replace('/^com\s+/iu', '', $ult);
        }
    }
    $tributo = trim(implode(', ', $tributo), " ,/");
    // "Tributo Divas Miss Rock": sem vírgula, a banda conhecida está no fim
    if ($banda === '' && $tributo !== '') {
        $pal = preg_split('/\s+/u', $tributo);
        for ($i = 1; $i < count($pal); $i++) {
            $resto = implode(' ', array_slice($pal, $i));
            if (strlen(dh_chave_banda($resto)) >= 4 && isset($chavesBandas[dh_chave_banda($resto)])) {
                $banda = $resto;
                $tributo = implode(' ', array_slice($pal, 0, $i));
                break;
            }
        }
    }
    $tributo = preg_replace('/\btributo\s+(?=tributo\b)/iu', '', $tributo);
    if (preg_match('/a (definir|decidir)/iu', $tributo)) {
        $tributo = '';
    }
    $temTributo = $tributo !== '';
    if ($banda === '' && $tributo !== '') {
        // Só o tributo foi escrito: usa como nome, para revisar
        $banda = $tributo;
        $tributo = '';
    }
    $cache = dh_valor($mc[1] ?? '');
    // "SEM MÚSICA", "Sem Analogicos": dia sem show
    // Contas, fretes, pedidos de barril ("Velas 30L"), aulas e eventos também ficam de fora
    $reserva = preg_match('/^\s*(sem|evento|aula|call|oficina)\s|boleto|\bpagar\b|frete|imposto|icms|\bpis\b|cofins|irpf|csll|mensalidade|aluguel|financiamento|\bnf\b|\b\d+\s*l\b|n[aã]o haver/iu', $titulo) || preg_match('/reservad|n[uú]mero de pessoas|\bpessoas\b|corporativ|confraterniza|degusta|reuni[aã]o|influencer|anivers|pedir |\bnome:/iu', $tudo);
    $conhecida = $banda !== '' && isset($chavesBandas[dh_chave_banda($banda)]);
    return [
        'data' => $ev['data'],
        'hora' => $hora ?: $horaPadrao,
        'unidade' => $unidade,
        'banda' => dh_capitalizar($banda),
        'tributo' => dh_capitalizar($tributo),
        'cache' => $cache,
        'consumacao' => dh_valor($mo[1] ?? ''),
        'instagram' => $instagram,
        'status' => $status,
        // Só vem marcado o que parece show
        'show' => !$reserva && ($cache !== '' || $temTributo || $conhecida || str_word_count(dh_slug($titulo), 0, '0123456789') <= 6),
        // Com cachê ou tributo escrito: dá para confiar que é banda
        'forte' => !$reserva && ($cache !== '' || preg_match($chave, $titulo) === 1),
        'original' => $ev['titulo'] . ($ev['descricao'] !== '' ? ' — ' . mb_substr(preg_replace('/\s+/', ' ', $ev['descricao']), 0, 160) : ''),
        'uid' => $ev['uid'],
    ];
}

// Chave para comparar tributos: "Tributo ao Rock Brasil" ~ "rockbrasil"
function dh_chave_tributo(string $t): string
{
    $k = dh_slug($t);
    $k = preg_replace('/^(tributos?|cover|homenagem)(-(a|ao|aos|as|o|os|de|da|do))?-/', '', $k);
    return str_replace('-', '', $k);
}

// Tributos distintos, do uso mais recente para o mais antigo. Os que estão contidos num
// texto mais completo somem ("Rock Brasil" fica dentro de "Tributo Rock Brasil (Legião, Barão)")
function dh_tributos_completos(array $usos): array
{
    $porChave = [];
    foreach (array_reverse($usos) as $t) {
        $k = dh_chave_tributo($t);
        if (!isset($porChave[$k]) || mb_strlen($t) > mb_strlen($porChave[$k])) {
            $porChave[$k] = $t;
        }
    }
    $lista = [];
    foreach ($porChave as $k => $t) {
        foreach (array_keys($porChave) as $outra) {
            if ($outra !== $k && ($k === '' || strpos($outra, $k) !== false)) {
                continue 2;
            }
        }
        $lista[] = $t;
    }
    return $lista;
}

// O texto completo que contém o tributo escrito (ou ele mesmo)
function dh_tributo_completo(string $t, array $completos): string
{
    $k = dh_chave_tributo($t);
    foreach ($completos as $c) {
        if ($k === '' || strpos(dh_chave_tributo($c), $k) !== false) {
            return $c;
        }
    }
    return $t;
}

// Erros de digitação viram a mesma banda ("Black Pepers" = "Black Peppers", "Gleidson" = "Gleydson"):
// nomes longos que diferem em uma letra só. O nome do cadastro tem preferência; depois, o mais usado.
function dh_bandas_unificar(array $linhas, array $chaves): array
{
    $cont = $nomes = [];
    foreach ($linhas as $l) {
        $k = dh_chave_banda($l['banda']);
        $cont[$k] = ($cont[$k] ?? 0) + 1;
        $nomes[$k] = $nomes[$k] ?? $l['banda'];
    }
    arsort($cont);
    $novas = array_values(array_diff(array_keys($cont), array_keys($chaves)));
    $canon = [];
    foreach (array_merge(array_keys($chaves), $novas) as $k) {
        if (isset($canon[$k]) || strlen($k) < 7) {
            continue;
        }
        foreach ($novas as $o) {
            if ($o !== $k && !isset($canon[$o]) && strlen($o) >= 7 && abs(strlen($o) - strlen($k)) <= 1 && levenshtein($k, $o) <= 1) {
                $canon[$o] = $k;
            }
        }
    }
    foreach ($linhas as &$l) {
        $k = $canon[dh_chave_banda($l['banda'])] ?? null;
        if ($k !== null) {
            $l['banda'] = $chaves[$k] ?? $nomes[$k];
        }
    }
    unset($l);
    return $linhas;
}

// O que a agenda inteira (inclusive shows antigos) diz de cada banda: nome, tributos
// completos, o último tributo usado, e o último cachê e consumação (por unidade e geral)
function dh_ics_historico(array $linhas, array $bandas): array
{
    usort($linhas, fn($x, $y) => [$x['data'], $x['hora']] <=> [$y['data'], $y['hora']]);
    $h = [];
    foreach ($linhas as $l) {
        $k = dh_chave_banda($l['banda']);
        if ($k === '') {
            continue;
        }
        $h[$k] = $h[$k] ?? ['nomes' => [], 'usos' => [], 'cache' => [], 'consumacao' => [], 'instagram' => '', 'shows' => 0, 'ultimo' => '', 'eventos' => 0, 'forte' => false];
        $b = &$h[$k];
        $b['eventos']++;
        $b['forte'] = $b['forte'] || !empty($l['forte']);
        $b['nomes'][$l['banda']] = ($b['nomes'][$l['banda']] ?? 0) + 1;
        if ($l['tributo'] !== '') {
            $b['usos'][] = $l['tributo'];
        }
        foreach (['cache', 'consumacao'] as $c) {
            if ($l[$c] !== '') {
                $b[$c][$l['unidade']] = $l[$c];
                $b[$c]['*'] = $l[$c];
            }
        }
        if ($l['instagram'] !== '') {
            $b['instagram'] = $l['instagram'];
        }
        if ($l['data'] < date('Y-m-d')) {
            $b['shows']++;
            $b['ultimo'] = $l['data'];
        }
        unset($b);
    }
    $cadastro = [];
    foreach ($bandas as $b) {
        $cadastro[dh_chave_banda($b['nome'])] = $b;
    }
    foreach ($h as $k => &$b) {
        // Nome: o do cadastro; se for nova, a grafia mais usada (empate: a mais longa)
        $nomes = array_keys($b['nomes']);
        $caixaAlta = fn($n) => (int) ($n === mb_strtoupper($n));
        usort($nomes, fn($x, $y) => [$b['nomes'][$y], $caixaAlta($x), mb_strlen($y)] <=> [$b['nomes'][$x], $caixaAlta($y), mb_strlen($x)]);
        $b['nome'] = $cadastro[$k]['nome'] ?? $nomes[0];
        $b['nova'] = !isset($cadastro[$k]);
        // Só vira cadastro o que parece banda mesmo: já cadastrada, com cachê ou tributo, ou que se repete
        $b['confiavel'] = !$b['nova'] || $b['forte'] || $b['eventos'] >= 3;
        $usos = array_reverse($b['usos']);
        $b['tributos'] = dh_tributos_completos(array_merge(array_reverse($cadastro[$k]['tributos'] ?? []), $b['usos']));
        $base = $usos[0] ?? ($cadastro[$k]['tributos'][0] ?? '');
        $b['tributo'] = $base !== '' ? dh_tributo_completo($base, $b['tributos']) : '';
        unset($b['nomes'], $b['usos']);
    }
    unset($b);
    return $h;
}

function agenda_acao(string $acao): bool
{
    global $telaErro, $csrf, $usuario;
    switch ($acao) {
        case 'agenda':
        case 'show':
            exigir('agenda');
            return true;
        case 'bandas':
        case 'banda':
            exigir('bandas');
            return true;
        case 'relatorio':
            exigir('relatorio');
            return true;
        case 'importar':
            exigir('importar');
            return true;

        case 'show-salvar':
            exigir_post();
            exigir('agenda_editar');
            $telaErro = 'show';
            $a = dh_agenda();
            $id = (string) ($_POST['id'] ?? '');
            $data = (string) ($_POST['data'] ?? '');
            $hora = (string) ($_POST['hora'] ?? '');
            $unidade = (string) ($_POST['unidade'] ?? '');
            $bandaId = (string) ($_POST['banda_id'] ?? '');
            $bandas = dh_bandas_por_id($a);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) || !strtotime($data)) {
                throw new RuntimeException('Informe a data do show.');
            }
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hora)) {
                throw new RuntimeException('Informe o horário (ex.: 20:30).');
            }
            if (!isset(DH_UNIDADES[$unidade])) {
                throw new RuntimeException('Escolha a unidade.');
            }
            $nomeNovo = mb_substr(trim((string) ($_POST['banda_nova'] ?? '')), 0, 80);
            if (!isset($bandas[$bandaId]) && $nomeNovo !== '') {
                // Banda digitada no show que ainda não existe: cadastra na hora (ou usa a de mesmo nome)
                foreach ($a['bandas'] as $b) {
                    if (dh_chave_banda($b['nome']) === dh_chave_banda($nomeNovo)) {
                        $bandaId = $b['id'];
                    }
                }
                if (!isset($bandas[$bandaId])) {
                    $bandaId = dh_id_novo('b');
                    $a['bandas'][] = ['id' => $bandaId, 'nome' => $nomeNovo, 'tributos' => [], 'cache' => dh_valor($_POST['cache'] ?? ''),
                        'consumacao' => dh_valor($_POST['consumacao'] ?? ''), 'instagram' => '', 'contato' => '', 'obs' => 'Cadastrada pela agenda', 'fotos' => []];
                }
                $bandas = dh_bandas_por_id($a);
            }
            if (!isset($bandas[$bandaId])) {
                throw new RuntimeException('Digite o nome da banda e escolha na lista (ou toque em "Cadastrar nova banda").');
            }
            $tributo = trim((string) ($_POST['tributo'] ?? ''));
            if ($tributo === '__outro') {
                $tributo = trim((string) ($_POST['tributo_outro'] ?? ''));
                // Tributo novo passa a fazer parte do cadastro da banda
                if ($tributo !== '') {
                    $i = dh_buscar_banda($a, $bandaId);
                    $a['bandas'][$i]['tributos'] = array_values(array_unique(array_merge($a['bandas'][$i]['tributos'], [$tributo])));
                }
            }
            $show = [
                'id' => $id !== '' ? $id : dh_id_novo('s'),
                'data' => $data,
                'hora' => $hora,
                'unidade' => $unidade,
                'banda_id' => $bandaId,
                'tributo' => mb_substr($tributo, 0, 120),
                'cache' => dh_valor($_POST['cache'] ?? ''),
                'consumacao' => dh_valor($_POST['consumacao'] ?? ''),
                'status' => isset(DH_STATUS_SHOW[$_POST['status'] ?? '']) ? $_POST['status'] : 'confirmado',
                'criado_por' => '',
                'alterado_por' => $usuario['nome'],
                'alterado_em' => date('Y-m-d H:i'),
                'obs' => mb_substr(trim((string) ($_POST['obs'] ?? '')), 0, 500),
            ];
            $achou = false;
            foreach ($a['shows'] as $i => $s) {
                if ($s['id'] === $show['id']) {
                    $show['criado_por'] = $s['criado_por'] ?? '';
                    $a['shows'][$i] = $show;
                    $achou = true;
                }
            }
            if (!$achou) {
                $show['criado_por'] = $usuario['nome'];
                $a['shows'][] = $show;
            }
            dh_agenda_salvar($a);
            ir('./?acao=agenda&mes=' . substr($data, 0, 7), 'Show de ' . $bandas[$bandaId]['nome'] . ' em ' . dh_data_curta($data) . ' salvo.');

        case 'show-pago':
            exigir_post();
            exigir('valores');
            $a = dh_agenda();
            $id = (string) ($_POST['id'] ?? '');
            $pagar = ($_POST['op'] ?? '') !== 'desfazer';
            $achou = null;
            foreach ($a['shows'] as &$s) {
                if ($s['id'] === $id) {
                    $s['status'] = $pagar ? 'pago' : 'confirmado';
                    $s['alterado_por'] = $usuario['nome'];
                    $s['alterado_em'] = date('Y-m-d H:i');
                    $achou = $s;
                }
            }
            unset($s);
            if (!$achou) {
                throw new RuntimeException('Show não encontrado.');
            }
            dh_agenda_salvar($a);
            $nome = dh_bandas_por_id($a)[$achou['banda_id']]['nome'] ?? 'show';
            ir('./?acao=agenda&mes=' . substr($achou['data'], 0, 7), $pagar ? $nome . ' (' . dh_data_curta($achou['data']) . ') marcado como PAGO.' : 'Pagamento desfeito: ' . $nome . ' voltou para Confirmado.');

        case 'show-excluir':
            exigir_post();
            exigir('agenda_editar');
            $a = dh_agenda();
            $id = (string) ($_POST['id'] ?? '');
            $mes = date('Y-m');
            foreach ($a['shows'] as $s) {
                if ($s['id'] === $id) {
                    $mes = substr($s['data'], 0, 7);
                }
            }
            $a['shows'] = array_values(array_filter($a['shows'], fn($s) => $s['id'] !== $id));
            dh_agenda_salvar($a);
            ir('./?acao=agenda&mes=' . $mes, 'Show excluído.');

        case 'banda-salvar':
            exigir_post();
            exigir('bandas_editar');
            $telaErro = 'banda';
            $a = dh_agenda();
            $id = (string) ($_POST['id'] ?? '');
            $nome = mb_substr(trim((string) ($_POST['nome'] ?? '')), 0, 80);
            if ($nome === '') {
                throw new RuntimeException('Informe o nome da banda.');
            }
            if ($id === '') {
                if (!pode('agenda_editar')) {
                    throw new RuntimeException('Seu usuário não pode cadastrar bandas novas.');
                }
                $b = ['id' => dh_id_novo('b'), 'fotos' => [], 'cache' => '', 'consumacao' => ''];
            } else {
                $b = $a['bandas'][dh_buscar_banda($a, $id)];
            }
            $b['nome'] = $nome;
            $b['tributos'] = dh_tributos_texto((string) ($_POST['tributos'] ?? ''));
            $b['instagram'] = dh_instagram((string) ($_POST['instagram'] ?? ''));
            $b['contato'] = mb_substr(trim((string) ($_POST['contato'] ?? '')), 0, 120);
            $b['obs'] = mb_substr(trim((string) ($_POST['obs'] ?? '')), 0, 1000);
            if (pode('valores')) {
                $b['pix'] = mb_substr(trim((string) ($_POST['pix'] ?? '')), 0, 140);
                $b['pix_nome'] = mb_substr(trim((string) ($_POST['pix_nome'] ?? '')), 0, 80);
                $b['cache'] = dh_valor($_POST['cache'] ?? '');
                $b['consumacao'] = dh_valor($_POST['consumacao'] ?? '');
            }
            foreach (lista_uploads($_FILES['fotos'] ?? []) as $arq) {
                $b['fotos'][] = dh_banda_foto_salvar($arq, $b['id']);
            }
            if ($id === '') {
                $a['bandas'][] = $b;
            } else {
                $a['bandas'][dh_buscar_banda($a, $id)] = $b;
            }
            dh_agenda_salvar($a);
            ir('./?acao=banda&id=' . $b['id'], '"' . $nome . '" salva.');

        case 'banda-foto':
            exigir_post();
            exigir('bandas_editar');
            $telaErro = 'banda';
            $a = dh_agenda();
            $i = dh_buscar_banda($a, (string) ($_POST['id'] ?? ''));
            $fotos = $a['bandas'][$i]['fotos'];
            $k = array_search((string) ($_POST['foto'] ?? ''), array_column($fotos, 'id'), true);
            if ($k !== false) {
                if (($_POST['op'] ?? '') === 'principal') {
                    array_unshift($fotos, array_splice($fotos, $k, 1)[0]);
                } elseif (($_POST['op'] ?? '') === 'excluir') {
                    dh_banda_foto_apagar(array_splice($fotos, $k, 1)[0]);
                }
            }
            $a['bandas'][$i]['fotos'] = $fotos;
            dh_agenda_salvar($a);
            ir('./?acao=banda&id=' . $a['bandas'][$i]['id'] . '#fotos', 'Fotos atualizadas.');

        case 'banda-link':
            exigir_post();
            exigir('bandas_editar');
            $telaErro = 'banda';
            $a = dh_agenda();
            $i = dh_buscar_banda($a, (string) ($_POST['id'] ?? ''));
            $novo = !empty($a['bandas'][$i]['token']);
            // Um link novo invalida o anterior
            $a['bandas'][$i]['token'] = bin2hex(random_bytes(16));
            dh_agenda_salvar($a);
            ir('./?acao=banda&id=' . $a['bandas'][$i]['id'] . '#link', $novo ? 'Link novo criado. O anterior não funciona mais.' : 'Link criado. Envie para a banda.');

        case 'banda-excluir':
            exigir_post();
            exigir('agenda_editar');
            $telaErro = 'banda';
            $a = dh_agenda();
            $id = (string) ($_POST['id'] ?? '');
            foreach ($a['shows'] as $s) {
                if ($s['banda_id'] === $id) {
                    throw new RuntimeException('Essa banda tem shows na agenda. Exclua ou troque a banda desses shows antes.');
                }
            }
            $i = dh_buscar_banda($a, $id);
            foreach ($a['bandas'][$i]['fotos'] as $f) {
                dh_banda_foto_apagar($f);
            }
            @rmdir(DH_DIR_BANDAS . '/' . $id);
            $nome = $a['bandas'][$i]['nome'];
            array_splice($a['bandas'], $i, 1);
            dh_agenda_salvar($a);
            ir('./?acao=bandas', '"' . $nome . '" excluída.');

        case 'banda-zip':
            exigir('bandas');
            $a = dh_agenda();
            $b = $a['bandas'][dh_buscar_banda($a, (string) ($_GET['id'] ?? ''))];
            if (!class_exists('ZipArchive') || !$b['fotos']) {
                throw new RuntimeException('Não há fotos para baixar.');
            }
            $tmp = tempnam(sys_get_temp_dir(), 'dh');
            $zip = new ZipArchive();
            $zip->open($tmp, ZipArchive::OVERWRITE);
            foreach ($b['fotos'] as $n => $f) {
                $zip->addFile(DH_RAIZ . '/' . $f['original'], dh_slug($b['nome']) . '-' . ($n + 1) . '.' . pathinfo($f['original'], PATHINFO_EXTENSION));
            }
            $zip->close();
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="fotos-' . dh_slug($b['nome']) . '.zip"');
            header('Content-Length: ' . filesize($tmp));
            readfile($tmp);
            @unlink($tmp);
            exit;

        case 'relatorio-csv':
            exigir('relatorio');
            $mes = dh_mes_param();
            $a = dh_agenda();
            $bandas = dh_bandas_por_id($a);
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="shows-' . $mes . '.csv"');
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Data', 'Dia', 'Horário', 'Unidade', 'Banda', 'Tributo', 'Cachê', 'Consumação', 'Situação', 'Observações'], ';');
            foreach ($a['shows'] as $s) {
                if (substr($s['data'], 0, 7) !== $mes) {
                    continue;
                }
                $num = fn($v) => is_numeric($v) ? number_format((float) $v, 2, ',', '') : '';
                fputcsv($out, [date('d/m/Y', strtotime($s['data'])), DH_DIAS_SEMANA[(int) date('w', strtotime($s['data']))], $s['hora'],
                    DH_UNIDADES[$s['unidade']], $bandas[$s['banda_id']]['nome'] ?? '', $s['tributo'], $num($s['cache']), $num($s['consumacao']),
                    DH_STATUS_SHOW[$s['status']], $s['obs']], ';');
            }
            fclose($out);
            exit;

        case 'agenda-config':
            exigir_post();
            exigir('usuarios');
            $a = dh_agenda();
            $hora = (string) ($_POST['hora_padrao'] ?? '');
            if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hora)) {
                $a['config']['hora_padrao'] = $hora;
                $a['config']['hora_escolhida'] = true;
            }
            if (!empty($_POST['novo_token'])) {
                $a['config']['token_equipe'] = bin2hex(random_bytes(16));
            }
            dh_agenda_salvar($a);
            ir('./?acao=agenda', 'Configuração da agenda salva.');

        case 'importar-ler':
            exigir_post();
            exigir('importar');
            $telaErro = 'importar';
            $a = dh_agenda();
            if (!empty($_FILES['arquivo']['tmp_name']) && $_FILES['arquivo']['error'] === UPLOAD_ERR_OK) {
                $ics = (string) file_get_contents($_FILES['arquivo']['tmp_name']);
            } else {
                $ics = dh_ics_baixar(trim((string) ($_POST['url'] ?? '')));
            }
            $unidadePadrao = isset(DH_UNIDADES[$_POST['unidade'] ?? '']) ? $_POST['unidade'] : 'aldeota';
            $chaves = [];
            foreach ($a['bandas'] as $b) {
                $chaves[dh_chave_banda($b['nome'])] = $b['nome'];
            }
            // Lê a agenda inteira: os eventos antigos ensinam nome, tributo e valores das bandas
            $eventos = dh_ics_eventos($ics);
            // Duas leituras: a primeira aprende os nomes de banda, para a segunda achar a banda
            // em títulos invertidos ("Tributo Guns e Kiss, Fets Domino")
            $conhecidas = $chaves;
            foreach ($eventos as $ev) {
                $l = dh_ics_interpretar($ev, $a['config']['hora_padrao'], $unidadePadrao, $chaves);
                if ($l['forte'] && $l['banda'] !== '' && !preg_match('/^(tribut|cover|homenagem)/iu', $l['banda'])) {
                    $conhecidas[dh_chave_banda($l['banda'])] = $conhecidas[dh_chave_banda($l['banda'])] ?? $l['banda'];
                }
            }
            $todas = [];
            foreach ($eventos as $ev) {
                $l = dh_ics_interpretar($ev, $a['config']['hora_padrao'], $unidadePadrao, $conhecidas);
                // Reservas e outros compromissos nem aparecem na revisão
                if ($l['show'] && dh_chave_banda($l['banda']) !== '') {
                    $l['banda'] = $chaves[dh_chave_banda($l['banda'])] ?? $l['banda'];
                    $todas[] = $l;
                }
            }
            $todas = dh_bandas_unificar($todas, $chaves);
            $hist = dh_ics_historico($todas, $a['bandas']);
            // Na agenda entram só os shows de hoje em diante, completados pelo histórico
            $hoje = date('Y-m-d');
            $linhas = [];
            foreach ($todas as $l) {
                if ($l['data'] < $hoje) {
                    continue;
                }
                $h = $hist[dh_chave_banda($l['banda'])];
                $l['banda'] = $h['nome'];
                $l['completado'] = [];
                $completo = $l['tributo'] === '' ? $h['tributo'] : dh_tributo_completo($l['tributo'], $h['tributos']);
                if ($completo !== $l['tributo']) {
                    $l['tributo'] = $completo;
                    $l['completado'][] = 'tributo';
                }
                foreach (['cache' => 'cachê', 'consumacao' => 'consumação'] as $c => $rot) {
                    $v = $h[$c][$l['unidade']] ?? ($h[$c]['*'] ?? '');
                    if ($l[$c] === '' && $v !== '') {
                        $l[$c] = $v;
                        $l['completado'][] = $rot;
                    }
                }
                if ($l['status'] === '') {
                    $l['status'] = 'confirmado';
                }
                $linhas[] = $l;
            }
            usort($linhas, fn($x, $y) => [$x['data'], $x['hora']] <=> [$y['data'], $y['hora']]);
            // Cadastro das bandas: só o que o histórico acrescenta (banda nova, tributo novo, valor que falta)
            $porChave = [];
            foreach ($a['bandas'] as $b) {
                $porChave[dh_chave_banda($b['nome'])] = $b;
            }
            $bandas = [];
            foreach ($hist as $k => $h) {
                $atual = $porChave[$k] ?? ['tributos' => [], 'cache' => '', 'consumacao' => ''];
                $novosTrib = array_values(array_diff($h['tributos'], $atual['tributos']));
                $cache = $atual['cache'] === '' ? ($h['cache']['*'] ?? '') : '';
                $consumacao = $atual['consumacao'] === '' ? ($h['consumacao']['*'] ?? '') : '';
                if ($h['confiavel'] && ($h['nova'] || $novosTrib || $cache !== '' || $consumacao !== '')) {
                    $bandas[] = ['nome' => $h['nome'], 'nova' => $h['nova'], 'tributos' => $h['tributos'], 'cache' => $cache, 'consumacao' => $consumacao,
                        'instagram' => $h['instagram'], 'shows' => $h['shows'], 'ultimo' => $h['ultimo']];
                }
            }
            usort($bandas, fn($x, $y) => strcoll(dh_slug($x['nome']), dh_slug($y['nome'])));
            if (!$linhas && !$bandas) {
                throw new RuntimeException('Nada novo nessa agenda: nenhum show de hoje em diante e nenhuma banda para completar (reservas e outros compromissos são ignorados).');
            }
            $_SESSION['importacao'] = $linhas;
            $_SESSION['importacao_bandas'] = $bandas;
            ir('./?acao=importar&revisar=1', count($linhas) . ' shows de hoje em diante e ' . count($bandas) . ' bandas para cadastrar ou completar. Revise antes de importar.');

        case 'importar-confirmar':
            exigir_post();
            exigir('importar');
            $telaErro = 'importar';
            $a = dh_agenda();
            $porNome = [];
            foreach ($a['bandas'] as $i => $b) {
                $porNome[dh_chave_banda($b['nome'])] = $i;
            }
            $novasBandas = $novosShows = $repetidos = $completadas = 0;
            // Primeiro o cadastro das bandas aprendido com o histórico
            $bandasHist = json_decode((string) ($_POST['json_bandas'] ?? ''), true);
            foreach (is_array($bandasHist) ? $bandasHist : [] as $hb) {
                $nome = mb_substr(trim((string) ($hb['nome'] ?? '')), 0, 80);
                $chave = dh_chave_banda($nome);
                if (empty($hb['incluir']) || $chave === '') {
                    continue;
                }
                if (!isset($porNome[$chave])) {
                    $a['bandas'][] = ['id' => dh_id_novo('b'), 'nome' => $nome, 'tributos' => [], 'cache' => '', 'consumacao' => '',
                        'instagram' => '', 'contato' => '', 'obs' => 'Importada do Google Agenda', 'fotos' => []];
                    $porNome[$chave] = count($a['bandas']) - 1;
                    $novasBandas++;
                } else {
                    $completadas++;
                }
                $b = &$a['bandas'][$porNome[$chave]];
                $novos = dh_tributos_texto(mb_substr((string) ($hb['tributos'] ?? ''), 0, 2000));
                // O texto completo substitui o resumido que já estava no cadastro
                $b['tributos'] = dh_tributos_completos(array_merge(array_reverse($b['tributos']), array_reverse($novos)));
                foreach (['cache', 'consumacao'] as $k) {
                    $v = dh_valor($hb[$k] ?? '');
                    if ($b[$k] === '' && $v !== '') {
                        $b[$k] = $v;
                    }
                }
                $insta = ltrim(trim((string) ($hb['instagram'] ?? '')), '@');
                if ($b['instagram'] === '' && preg_match('/^[a-z0-9_.]{3,30}$/i', $insta)) {
                    $b['instagram'] = $insta;
                }
                unset($b);
            }
            // A revisão chega num campo só (JSON), para não esbarrar no limite de campos do PHP
            $recebidas = json_decode((string) ($_POST['json'] ?? ''), true);
            if (!is_array($recebidas)) {
                $recebidas = (array) ($_POST['l'] ?? []);
            }
            foreach ($recebidas as $l) {
                if (empty($l['incluir'])) {
                    continue;
                }
                $nome = mb_substr(trim((string) ($l['banda'] ?? '')), 0, 80);
                $data = (string) ($l['data'] ?? '');
                $hora = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) ($l['hora'] ?? '')) ? $l['hora'] : $a['config']['hora_padrao'];
                $unidade = isset(DH_UNIDADES[$l['unidade'] ?? '']) ? $l['unidade'] : 'aldeota';
                if ($nome === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
                    continue;
                }
                $tributo = mb_substr(trim((string) ($l['tributo'] ?? '')), 0, 120);
                $cache = dh_valor($l['cache'] ?? '');
                $consumacao = dh_valor($l['consumacao'] ?? '');
                $chave = dh_chave_banda($nome);
                if (!isset($porNome[$chave])) {
                    $a['bandas'][] = ['id' => dh_id_novo('b'), 'nome' => $nome, 'tributos' => [], 'cache' => $cache, 'consumacao' => $consumacao,
                        'instagram' => '', 'contato' => '', 'obs' => 'Importada do Google Agenda', 'fotos' => []];
                    $porNome[$chave] = count($a['bandas']) - 1;
                    $novasBandas++;
                }
                $b = &$a['bandas'][$porNome[$chave]];
                if ($tributo !== '') {
                    $b['tributos'] = dh_tributos_completos(array_merge(array_reverse($b['tributos']), [$tributo]));
                }
                $insta = ltrim(trim((string) ($l['instagram'] ?? '')), '@');
                if ($b['instagram'] === '' && preg_match('/^[a-z0-9_.]{3,30}$/i', $insta)) {
                    $b['instagram'] = $insta;
                }
                foreach (['cache' => $cache, 'consumacao' => $consumacao] as $k => $v) {
                    if ($b[$k] === '' && $v !== '') {
                        $b[$k] = $v;
                    }
                }
                $bandaId = $b['id'];
                unset($b);
                foreach ($a['shows'] as $s) {
                    if ($s['data'] === $data && $s['unidade'] === $unidade && $s['banda_id'] === $bandaId) {
                        $repetidos++;
                        continue 2;
                    }
                }
                $a['shows'][] = ['id' => dh_id_novo('s'), 'data' => $data, 'hora' => $hora, 'unidade' => $unidade, 'banda_id' => $bandaId,
                    'tributo' => $tributo, 'cache' => $cache, 'consumacao' => $consumacao,
                    'status' => isset(DH_STATUS_SHOW[$l['status'] ?? '']) ? $l['status'] : 'confirmado', 'obs' => '',
                    'criado_por' => $usuario['nome'] . ' (importado do Google Agenda)', 'alterado_por' => '', 'alterado_em' => ''];
                $novosShows++;
            }
            dh_agenda_salvar($a);
            unset($_SESSION['importacao'], $_SESSION['importacao_bandas']);
            ir('./?acao=agenda', "Importação concluída: $novosShows shows, $novasBandas bandas novas e $completadas bandas completadas" . ($repetidos ? " ($repetidos shows já existiam e foram ignorados)" : '') . '.');
    }
    return false;
}

function agenda_tela(string $tela): bool
{
    global $csrf;
    switch ($tela) {
        case 'agenda':
            agenda_tela_lista();
            return true;
        case 'show':
            agenda_tela_show();
            return true;
        case 'bandas':
            agenda_tela_bandas();
            return true;
        case 'banda':
            agenda_tela_banda();
            return true;
        case 'relatorio':
            agenda_tela_relatorio();
            return true;
        case 'importar':
            agenda_tela_importar();
            return true;
    }
    return false;
}

function agenda_status_tag(string $st): string
{
    // Confirmado é o normal: só pago e cancelado ganham etiqueta
    return $st === 'confirmado' ? '' : '<em class="tag st-' . e($st) . '">' . e(DH_STATUS_SHOW[$st] ?? $st) . '</em>';
}

// Calendário do mês no painel (igual ao do site, com todos os shows e a situação de cada um).
// $escolher: tocar no dia preenche a data do formulário; senão abre um show novo naquele dia.
function agenda_calendario(array $a, string $mes, string $un, bool $escolher, string $selecionado = '', string $extra = ''): void
{
    global $csrf;
    $editar = pode('agenda_editar');
    $bandas = dh_bandas_por_id($a);
    $ini = $mes . '-01';
    $fim = date('Y-m-t', strtotime($ini));
    $porDia = [];
    foreach ($a['shows'] as $s) {
        if ($s['data'] >= $ini && $s['data'] <= $fim && (!$un || $s['unidade'] === $un)) {
            $porDia[$s['data']][] = $s;
        }
    }
    $hoje = date('Y-m-d');
    $nav = './?acao=' . ($escolher ? 'show' : 'agenda') . ($un ? '&unidade=' . $un : '') . $extra . '&mes=';
    ?>
      <div class="cal-admin" data-escolher="<?= $escolher ? '1' : '' ?>" data-editar="<?= $editar ? '1' : '' ?>">
        <p class="cal-legenda"><span class="u-aldeota">Aldeota</span> <span class="u-maraponga">Maraponga</span> <span class="leg-cancelado">Cancelado</span> <b class="leg-pago"><span class="flag-pago">✓</span> Pago</b></p>
        <p class="dica cal-ajuda">👆 <?= !$editar ? 'Toque no nome da banda para ver o tributo, o Instagram e as fotos.' : ($escolher ? 'Toque no dia do show.' : 'Toque num dia para marcar um show.') . ' Toque no nome da banda para editar ou duplicar.' ?> Arraste o calendário para o lado para trocar de mês.</p>
        <?php if ($mes !== date('Y-m')): ?><p class="centro"><a class="btn secundario hoje-btn" data-manter-posicao href="<?= e($nav . date('Y-m')) ?>">Voltar para o mês atual</a></p><?php endif; ?>
        <?php $ant = dh_mes_somar($mes, -1); $prox = dh_mes_somar($mes, 1); ?>
        <div class="mes-nav cal-nav">
          <?php $mesAnt = DH_MESES[(int) substr($ant, 5, 2)]; $mesProx = DH_MESES[(int) substr($prox, 5, 2)]; ?>
          <a class="btn mes-btn" data-mes="anterior" href="<?= e($nav . $ant) ?>" aria-label="<?= e($mesAnt) ?>">‹ <span class="mes-longo"><?= e($mesAnt) ?></span><span class="mes-curto"><?= e(mb_substr($mesAnt, 0, 3)) ?></span></a>
          <strong><?= e(DH_MESES[(int) substr($mes, 5, 2)]) ?> <small><?= e(substr($mes, 0, 4)) ?></small></strong>
          <a class="btn mes-btn" data-mes="proximo" href="<?= e($nav . $prox) ?>" aria-label="<?= e($mesProx) ?>"><span class="mes-longo"><?= e($mesProx) ?></span><span class="mes-curto"><?= e(mb_substr($mesProx, 0, 3)) ?></span> ›</a>
        </div>
        <div class="calendario">
          <?php foreach (['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'] as $d): ?><div class="cal-semana"><?= $d ?></div><?php endforeach; ?>
          <?php for ($i = 0; $i < (int) date('w', strtotime($ini)); $i++): ?><div class="cal-vazio"></div><?php endfor; ?>
          <?php for ($t = strtotime($ini); $t <= strtotime($fim); $t = strtotime('+1 day', $t)): $dia = date('Y-m-d', $t); ?>
            <div class="cal-dia<?= $dia < $hoje ? ' passado' : '' ?><?= $dia === $hoje ? ' hoje' : '' ?><?= $dia === $selecionado ? ' escolhido' : '' ?><?= $editar ? '' : ' so-ver' ?>" data-dia="<?= $dia ?>"<?= $editar ? ' role="button" tabindex="0"' : '' ?> aria-label="<?= e(dh_data_curta($dia)) ?>">
              <span class="cal-num"><?= date('j', $t) ?></span>
              <?php foreach ($porDia[$dia] ?? [] as $s): $b = $bandas[$s['banda_id']] ?? null; ?>
                <button type="button" class="cal-show u-<?= e($s['unidade']) ?> st-<?= e($s['status']) ?>" data-show="<?= e($s['id']) ?>"
                  data-info="<?= e(json_encode(['banda' => $b['nome'] ?? '—', 'tributo' => $s['tributo'], 'quando' => dh_data_curta($s['data']) . ' · ' . $s['hora'] . ' · ' . DH_UNIDADES[$s['unidade']], 'situacao' => DH_STATUS_SHOW[$s['status']] ?? '', 'status' => $s['status'],
                    'banda_id' => $s['banda_id'], 'instagram' => $b['instagram'] ?? '', 'tributos_banda' => implode(' / ', $b['tributos'] ?? []), 'fotos' => count($b['fotos'] ?? []), 'drive' => $b['drive'][0] ?? '']
                    + (pode('valores') ? ['pix' => $b['pix'] ?? '', 'pix_nome' => $b['pix_nome'] ?? '', 'cache' => dh_moeda($s['cache'])] : []), JSON_UNESCAPED_UNICODE)) ?>"><?php if ($s['status'] === 'pago'): ?><span class="flag-pago" title="Pago">✓</span><?php endif; ?><?= e($b['nome'] ?? '—') ?></button>
              <?php endforeach; ?>
            </div>
          <?php endfor; ?>
          <?php for ($i = (int) date('w', strtotime($fim)); $i < 6; $i++): ?><div class="cal-vazio"></div><?php endfor; ?>
        </div>
      </div>
      <dialog class="cal-menu" id="cal-menu">
        <p class="cal-menu-quando"></p>
        <h3 class="cal-menu-banda"></h3>
        <p class="cal-menu-tributo"></p>
        <p class="cal-menu-situacao"></p>
        <?php if (pode('valores')): ?>
          <div class="cal-menu-pix">
            <p class="pix-valor"></p>
            <p class="pix-chave"></p>
            <button type="button" class="btn secundario" data-op="copiar-pix">Copiar Pix</button>
          </div>
        <?php endif; ?>
        <div class="cal-menu-botoes">
          <?php if (pode('valores')): ?>
            <form method="post" action="./?acao=show-pago" class="cal-menu-pago">
              <?= $csrf ?><input type="hidden" name="id" value="">
              <button class="btn pagar" name="op" value="pagar">✓ Marcar como pago</button>
              <button class="btn secundario" name="op" value="desfazer" onclick="return confirm('Desfazer o pagamento deste show?');">Desfazer pagamento</button>
            </form>
          <?php endif; ?>
          <?php if ($editar): ?>
            <a class="btn primario" data-op="editar">Editar</a>
            <a class="btn secundario" data-op="duplicar">Duplicar</a>
          <?php else: ?>
            <a class="btn secundario" data-op="instagram" target="_blank" rel="noopener">Abrir Instagram</a>
            <a class="btn primario" data-op="fotos">Baixar fotos da banda</a>
            <a class="btn secundario" data-op="drive" target="_blank" rel="noopener">Abrir pasta no Google Drive</a>
            <a class="btn secundario" data-op="banda">Ver banda</a>
          <?php endif; ?>
          <button type="button" class="btn secundario" data-op="fechar">Fechar</button>
        </div>
      </dialog>
      <script>
        (function () {
          var cal = document.querySelector('.cal-admin'), menu = document.getElementById('cal-menu');
          var escolher = cal.dataset.escolher === '1', editar = cal.dataset.editar === '1';
          function dia(el) {
            if (!editar) return;
            if (!escolher) { location.href = './?acao=show&data=' + el.dataset.dia; return; }
            var campo = document.querySelector('#form-show [name=data]');
            campo.value = el.dataset.dia;
            campo.dispatchEvent(new Event('change'));
            cal.querySelectorAll('.cal-dia.escolhido').forEach(function (d) { d.classList.remove('escolhido'); });
            el.classList.add('escolhido');
            document.getElementById('form-show').scrollIntoView({ behavior: 'smooth', block: 'start' });
          }
          cal.querySelectorAll('.cal-dia').forEach(function (el) {
            el.addEventListener('click', function () { dia(el); });
            el.addEventListener('keydown', function (e) { if (e.key === 'Enter') dia(el); });
          });
          cal.querySelectorAll('.cal-show').forEach(function (bt) {
            bt.addEventListener('click', function (e) {
              e.stopPropagation();
              var i = JSON.parse(bt.dataset.info);
              menu.querySelector('.cal-menu-quando').textContent = i.quando;
              menu.querySelector('.cal-menu-banda').textContent = i.banda;
              menu.querySelector('.cal-menu-tributo').textContent = i.tributo || 'Sem tributo definido';
              menu.querySelector('.cal-menu-situacao').textContent = i.situacao;
              function link(op, href) { var a = menu.querySelector('[data-op=' + op + ']'); if (a) { a.href = href || '#'; a.hidden = !href; } }
              link('editar', './?acao=show&id=' + encodeURIComponent(bt.dataset.show));
              link('duplicar', './?acao=show&duplicar=' + encodeURIComponent(bt.dataset.show));
              link('instagram', i.instagram ? 'https://www.instagram.com/' + encodeURIComponent(i.instagram) + '/' : '');
              link('fotos', i.fotos ? './?acao=banda-zip&id=' + encodeURIComponent(i.banda_id) : '');
              link('drive', i.drive ? 'https://drive.google.com/drive/folders/' + encodeURIComponent(i.drive) : '');
              link('banda', './?acao=banda&id=' + encodeURIComponent(i.banda_id));
              if (!editar) menu.querySelector('.cal-menu-tributo').textContent = (i.tributo || i.tributos_banda || 'Sem tributo cadastrado') + (i.instagram ? ' · @' + i.instagram : '');
              var pago = menu.querySelector('.cal-menu-pago');
              if (pago) {
                pago.querySelector('[name=id]').value = bt.dataset.show;
                pago.querySelector('[value=pagar]').hidden = i.status === 'pago' || i.status === 'cancelado';
                pago.querySelector('[value=desfazer]').hidden = i.status !== 'pago';
              }
              var caixaPix = menu.querySelector('.cal-menu-pix');
              if (caixaPix) {
                caixaPix.querySelector('.pix-valor').textContent = 'Cachê: ' + i.cache;
                caixaPix.querySelector('.pix-chave').textContent = i.pix ? 'Pix: ' + i.pix + (i.pix_nome ? ' (' + i.pix_nome + ')' : '') : 'Pix: a banda ainda não informou';
                caixaPix.querySelector('[data-op=copiar-pix]').hidden = !i.pix;
                caixaPix.dataset.pix = i.pix || '';
              }
              menu.querySelector('.cal-menu-situacao').className = 'cal-menu-situacao sit-' + i.status;
              menu.querySelector('.cal-menu-situacao').textContent = i.status === 'pago' ? '✓ Pago' : (i.status === 'cancelado' ? 'Cancelado' : '');
              menu.showModal();
            });
          });
          menu.querySelector('[data-op=fechar]').addEventListener('click', function () { menu.close(); });
          var copiar = menu.querySelector('[data-op=copiar-pix]');
          if (copiar) copiar.addEventListener('click', function () {
            var chave = menu.querySelector('.cal-menu-pix').dataset.pix;
            function ok() { copiar.textContent = '✓ Pix copiado'; setTimeout(function () { copiar.textContent = 'Copiar Pix'; }, 2500); }
            if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(chave).then(ok); }
            else { var t = document.createElement('textarea'); t.value = chave; document.body.appendChild(t); t.select(); document.execCommand('copy'); t.remove(); ok(); }
          });
          dhArrastarMes(cal.querySelector('.calendario'), cal.querySelector('[data-mes=anterior]'), cal.querySelector('[data-mes=proximo]'));
          menu.addEventListener('click', function (e) { if (e.target === menu) menu.close(); });
        })();
      </script>
    <?php
}

function agenda_tela_lista(): void
{
    global $csrf;
    $a = dh_agenda();
    $bandas = dh_bandas_por_id($a);
    $mes = dh_mes_param();
    $un = isset(DH_UNIDADES[$_GET['unidade'] ?? '']) ? $_GET['unidade'] : '';
    $shows = array_values(array_filter($a['shows'], fn($s) => substr($s['data'], 0, 7) === $mes && (!$un || $s['unidade'] === $un)));
    $base = './?acao=agenda' . ($un ? '&unidade=' . $un : '');
    ?>
      <div class="titulo-acao">
        <h1>Agenda</h1>
        <?php if (pode('agenda_editar')): ?><a class="btn primario" href="./?acao=show">+ Novo show</a><?php endif; ?>
      </div>
      <div class="abas">
        <a href="./?acao=agenda&mes=<?= e($mes) ?>" class="<?= !$un ? 'ativo' : '' ?>">Todas</a>
        <?php foreach (DH_UNIDADES as $u => $nomeU): ?>
          <a href="./?acao=agenda&mes=<?= e($mes) ?>&unidade=<?= $u ?>" class="<?= $un === $u ? 'ativo' : '' ?>"><?= e($nomeU) ?></a>
        <?php endforeach; ?>
      </div>
      <?php agenda_calendario($a, $mes, $un, false); ?>
      <?php if (!$shows): ?><p class="dica">Nenhum show neste mês.</p><?php endif; ?>
      <ul class="lista shows">
        <?php foreach ($shows as $s): $b = $bandas[$s['banda_id']] ?? null; ?>
          <li>
            <a href="<?= pode('agenda_editar') ? './?acao=show&id=' . e($s['id']) : './?acao=banda&id=' . e($s['banda_id']) ?>">
              <span class="data-show"><strong><?= date('d', strtotime($s['data'])) ?></strong><?= e(mb_substr(DH_DIAS_SEMANA[(int) date('w', strtotime($s['data']))], 0, 3)) ?></span>
              <img src="../<?= e(str_replace('media-', 'mini-', dh_banda_foto($b))) ?>" alt="" width="56" height="56" loading="lazy">
              <span class="info">
                <strong><?= e($b['nome'] ?? '—') ?></strong>
                <span><?= e($s['tributo'] ?: 'Sem tributo definido') ?></span>
                <span><?= e($s['hora']) ?> · <?= e(DH_UNIDADES[$s['unidade']]) ?><?php if (pode('valores')): ?> · Cachê <?= e(dh_moeda($s['cache'])) ?> · Consumação <?= e(dh_moeda($s['consumacao'])) ?><?php endif; ?></span>
                <span class="tags"><?= agenda_status_tag($s['status']) ?><?php if (!empty($s['criado_por'])): ?><em class="tag cinza">por <?= e(explode(' (', $s['criado_por'])[0]) ?></em><?php endif; ?><?php if ($b && !$b['fotos']): ?><em class="tag alerta">Banda sem foto</em><?php endif; ?></span>
              </span>
              <span class="seta">›</span>
            </a>
            <div class="acoes-show">
              <?php if (pode('agenda_editar')): ?><a class="duplicar" href="./?acao=show&duplicar=<?= e($s['id']) ?>" title="Duplicar show">⧉ Duplicar</a><?php endif; ?>
              <?php if (pode('valores') && $s['status'] === 'confirmado'): ?>
                <form method="post" action="./?acao=show-pago"><?= $csrf ?><input type="hidden" name="id" value="<?= e($s['id']) ?>"><button class="btn pagar mini-pagar" name="op" value="pagar">✓ Marcar como pago</button></form>
              <?php endif; ?>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
      <p class="dica">No site aparecem só os shows <strong>confirmados</strong> e <strong>pagos</strong>. Cachê e consumação nunca aparecem para o público.</p>
      <?php if (pode('usuarios')):
          $url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/') . '/agenda/'; ?>
        <details class="config-agenda">
          <summary>Configurações e Google Agenda</summary>
          <form method="post" action="./?acao=agenda-config" class="form-cerveja">
            <?= $csrf ?>
            <label>Horário padrão dos shows <input type="time" name="hora_padrao" value="<?= e($a['config']['hora_padrao']) ?>"></label>
            <p class="dica"><strong>Agenda pública</strong> (para divulgar): <code><?= e($url) ?>ics.php</code></p>
            <?php if ($a['config']['token_equipe']): ?>
              <p class="dica"><strong>Agenda da equipe</strong>, com cachê, consumação e situação (não compartilhe fora da equipe). No Google Agenda: "Outras agendas → + → Do URL":<br><code><?= e($url . 'ics.php?equipe=1&t=' . $a['config']['token_equipe']) ?></code></p>
            <?php endif; ?>
            <label class="check"><input type="checkbox" name="novo_token" value="1"> <?= $a['config']['token_equipe'] ? 'Gerar um novo link da equipe (o antigo para de funcionar)' : 'Criar link da agenda da equipe' ?></label>
            <button class="btn primario">Salvar</button>
          </form>
        </details>
      <?php endif; ?>
    <?php
}

function agenda_tela_show(): void
{
    global $csrf, $erro, $usuario;
    $a = dh_agenda();
    $id = (string) ($_GET['id'] ?? $_POST['id'] ?? '');
    $s = null;
    foreach ($a['shows'] as $x) {
        if ($x['id'] === $id) {
            $s = $x;
        }
    }
    // Duplicar: mesma banda, tributo, horário, unidade e valores; falta só escolher o dia
    $origem = null;
    foreach ($a['shows'] as $x) {
        if (!$s && $x['id'] === (string) ($_GET['duplicar'] ?? '')) {
            $origem = $x;
            $s = ['id' => '', 'data' => '', 'status' => in_array($x['status'], ['pago', 'cancelado'], true) ? 'confirmado' : $x['status'], 'obs' => '']
                + array_intersect_key($x, array_flip(['hora', 'unidade', 'banda_id', 'tributo', 'cache', 'consumacao']));
        }
    }
    $s = $s ?? ['id' => '', 'data' => (string) ($_GET['data'] ?? ''), 'hora' => $a['config']['hora_padrao'], 'unidade' => $usuario['unidade'] ?? '' ?: 'aldeota', 'banda_id' => '',
        'tributo' => '', 'cache' => '', 'consumacao' => '', 'status' => 'confirmado', 'obs' => ''];
    if ($erro && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $s = array_merge($s, array_intersect_key($_POST, $s));
    }
    if (!pode('agenda_editar')) {
        echo '<p class="erro">Seu usuário só pode ver a agenda.</p>';
        return;
    }
    $bandas = $a['bandas'];
    ?>
      <p><a class="voltar" href="./?acao=agenda<?= $s['data'] ? '&mes=' . e(substr($s['data'], 0, 7)) : '' ?>">‹ Voltar para a Agenda</a></p>
      <h1><?= $s['id'] ? 'Editar show' : ($origem ? 'Duplicar show' : 'Novo show') ?></h1>
      <?php if ($origem): ?><p class="aviso">Cópia do show de <?= e(dh_data_curta($origem['data'])) ?>. Escolha o novo dia no calendário e salve.</p><?php endif; ?>
      <?php
        $mesCal = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) ($_GET['mes'] ?? '')) ? $_GET['mes'] : (preg_match('/^\d{4}-\d{2}/', $s['data']) ? substr($s['data'], 0, 7) : ($origem ? substr($origem['data'], 0, 7) : date('Y-m')));
        $extra = $s['id'] ? '&id=' . rawurlencode($s['id']) : ($origem ? '&duplicar=' . rawurlencode($origem['id']) : '');
        agenda_calendario($a, $mesCal, '', true, $s['data'], $extra);
      ?>
      <form method="post" action="./?acao=show-salvar" class="form-cerveja" id="form-show">
        <?= $csrf ?>
        <input type="hidden" name="id" value="<?= e($s['id']) ?>">
        <div class="duas">
          <label>Data <input type="date" name="data" value="<?= e($s['data']) ?>" required>
            <span class="data-extenso" id="data-extenso"><?= $s['data'] ? e(dh_data_curta($s['data'])) . '/' . e(substr($s['data'], 0, 4)) : 'Toque no dia no calendário acima' ?></span></label>
          <label>Horário <input type="time" name="hora" value="<?= e($s['hora']) ?>" required></label>
        </div>
        <label>Unidade
          <span class="escolhas"><?php foreach (DH_UNIDADES as $u => $nomeU): ?><label class="escolha u-<?= $u ?>"><input type="radio" name="unidade" value="<?= $u ?>" <?= $u === $s['unidade'] ? 'checked' : '' ?>><span><?= e($nomeU) ?></span></label><?php endforeach; ?></span>
        </label>
        <?php
          $atual = null;
          foreach ($bandas as $b) {
              if ($b['id'] === $s['banda_id']) {
                  $atual = $b;
              }
          }
          $listaBandas = array_map(fn($b) => ['id' => $b['id'], 'nome' => $b['nome'], 'tributos' => $b['tributos'],
              'cache' => dh_num($b['cache']), 'consumacao' => dh_num($b['consumacao'])], $bandas);
          usort($listaBandas, fn($x, $y) => strcoll(dh_slug($x['nome']), dh_slug($y['nome'])));
        ?>
        <div class="busca-banda">
          <label>Banda <input id="banda-busca" autocomplete="off" placeholder="Digite o nome da banda" value="<?= e($atual['nome'] ?? ($_POST['banda_nova'] ?? '')) ?>" required></label>
          <input type="hidden" name="banda_id" id="banda" value="<?= e($s['banda_id']) ?>">
          <input type="hidden" name="banda_nova" id="banda-nova" value="<?= e($_POST['banda_nova'] ?? '') ?>">
          <ul class="sugestoes" id="sugestoes" hidden></ul>
          <p class="dica" id="banda-aviso" hidden>Banda nova: será cadastrada ao salvar o show. Depois dá para completar fotos e contato em Bandas.</p>
        </div>
        <label>Tributo
          <select name="tributo" id="tributo" data-atual="<?= e($s['tributo']) ?>"></select>
        </label>
        <label id="tributo-outro" hidden>Nome do tributo novo <input name="tributo_outro" maxlength="120" placeholder="ex.: Tributo ao Queen"></label>
        <div class="duas">
          <label>Cachê (R$) <input name="cache" value="<?= e(dh_num($s['cache'])) ?>" inputmode="decimal" id="cache"></label>
          <label>Consumação (R$) <input name="consumacao" value="<?= e(dh_num($s['consumacao'])) ?>" inputmode="decimal" id="consumacao"></label>
        </div>
        <label>Situação
          <span class="escolhas"><?php foreach (DH_STATUS_SHOW as $k => $rot): ?><label class="escolha st-<?= $k ?>"><input type="radio" name="status" value="<?= $k ?>" <?= $k === $s['status'] ? 'checked' : '' ?>><span><?= e($rot) ?></span></label><?php endforeach; ?></span>
        </label>
        <p class="dica">Show cancelado sai do site.</p>
        <label>Observações internas <textarea name="obs" rows="3" maxlength="500"><?= e($s['obs']) ?></textarea></label>
        <?php if (!empty($s['criado_por']) || !empty($s['alterado_por'])): ?>
          <p class="dica">Cadastrado por <?= e(($s['criado_por'] ?? '') ?: '—') ?><?php if (!empty($s['alterado_por'])): ?> · última alteração por <?= e($s['alterado_por']) ?> em <?= e(date('d/m/Y H:i', strtotime($s['alterado_em']))) ?><?php endif; ?></p>
        <?php endif; ?>
        <div class="barra-salvar"><button class="btn primario">Salvar show</button></div>
      </form>
      <?php if ($s['id']): ?>
        <form method="post" action="./?acao=show-excluir" class="excluir" onsubmit="return confirm('Excluir este show?');">
          <?= $csrf ?><input type="hidden" name="id" value="<?= e($s['id']) ?>">
          <button class="btn perigo">Excluir show</button>
        </form>
      <?php endif; ?>
      <script>
        (function () {
          var bandas = <?= json_encode($listaBandas, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
          var busca = document.getElementById('banda-busca'), idBanda = document.getElementById('banda');
          var nova = document.getElementById('banda-nova'), aviso = document.getElementById('banda-aviso');
          var lista = document.getElementById('sugestoes'), trib = document.getElementById('tributo');
          var outro = document.getElementById('tributo-outro'), primeira = true;
          // Compara sem acento, maiúscula e as palavras "banda"/"the"
          function chave(t) {
            return t.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/\b(banda|the|os|as)\b/g, '').replace(/[^a-z0-9]/g, '');
          }
          function porId(id) { return bandas.filter(function (b) { return b.id === id; })[0]; }
          function montar() {
            var b = porId(idBanda.value);
            var tribs = b ? b.tributos.slice() : [];
            var atual = primeira ? trib.dataset.atual : '';
            if (atual && tribs.indexOf(atual) < 0) tribs.push(atual);
            trib.innerHTML = '<option value="">— sem tributo —</option>' + tribs.map(function (t) {
              var o = document.createElement('option'); o.value = o.textContent = t; if (t === atual || (!atual && tribs.length === 1)) o.selected = true; return o.outerHTML;
            }).join('') + '<option value="__outro">+ Outro tributo…</option>';
            // Banda nova ainda não tem tributo: já abre o campo para digitar
            if (nova.value && !b) trib.value = '__outro';
            outro.hidden = trib.value !== '__outro';
            // Ao trocar de banda, traz o cachê e a consumação padrão dela
            if (!primeira && b) {
              document.getElementById('cache').value = b.cache || '';
              document.getElementById('consumacao').value = b.consumacao || '';
            }
            aviso.hidden = !nova.value;
            primeira = false;
          }
          function escolher(b) {
            idBanda.value = b.id; nova.value = ''; busca.value = b.nome; lista.hidden = true; montar();
          }
          function criar() {
            idBanda.value = ''; nova.value = busca.value.trim(); lista.hidden = true; montar();
          }
          function sugerir() {
            var k = chave(busca.value), texto = busca.value.trim();
            idBanda.value = ''; nova.value = '';
            lista.innerHTML = '';
            if (!texto) { lista.hidden = true; montar(); return; }
            var achadas = bandas.filter(function (b) { return chave(b.nome).indexOf(k) >= 0; }).slice(0, 8);
            achadas.forEach(function (b) {
              var li = document.createElement('li'), bt = document.createElement('button');
              bt.type = 'button'; bt.textContent = b.nome; bt.addEventListener('click', function () { escolher(b); });
              li.appendChild(bt); lista.appendChild(li);
            });
            var igual = achadas.filter(function (b) { return chave(b.nome) === k; })[0];
            if (!igual) {
              var li = document.createElement('li'), bt = document.createElement('button');
              bt.type = 'button'; bt.className = 'nova'; bt.textContent = '+ Cadastrar nova banda "' + texto + '"';
              bt.addEventListener('click', criar);
              li.appendChild(bt); lista.appendChild(li);
            }
            lista.hidden = false;
            montar();
          }
          busca.addEventListener('input', sugerir);
          busca.addEventListener('focus', function () { if (!idBanda.value && busca.value) sugerir(); });
          document.getElementById('form-show').addEventListener('submit', function (e) {
            if (!idBanda.value && !nova.value) {
              // Nome digitado igual a uma banda existente vale como escolhida
              var k = chave(busca.value), igual = bandas.filter(function (b) { return chave(b.nome) === k; })[0];
              if (igual) { escolher(igual); return; }
              e.preventDefault(); sugerir(); busca.focus();
            }
          });
          trib.addEventListener('change', function () { outro.hidden = trib.value !== '__outro'; });
          // Dia escolhido por extenso, para conferir sem erro
          var campoData = document.querySelector('#form-show [name=data]'), extenso = document.getElementById('data-extenso');
          var dias = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];
          campoData.addEventListener('change', function () {
            var p = campoData.value.split('-');
            if (p.length !== 3) return;
            var d = new Date(+p[0], +p[1] - 1, +p[2]);
            extenso.textContent = dias[d.getDay()] + ', ' + p[2] + '/' + p[1] + '/' + p[0];
            extenso.classList.add('ok-data');
          });
          montar();
        })();
      </script>
    <?php
}

function agenda_tela_bandas(): void
{
    $a = dh_agenda();
    $hoje = date('Y-m-d');
    $proximo = [];
    foreach ($a['shows'] as $s) {
        if ($s['data'] >= $hoje && $s['status'] !== 'cancelado' && !isset($proximo[$s['banda_id']])) {
            $proximo[$s['banda_id']] = $s;
        }
    }
    ?>
      <div class="titulo-acao">
        <h1>Bandas <small><?= count($a['bandas']) ?></small></h1>
        <?php if (pode('agenda_editar')): ?><a class="btn primario" href="./?acao=banda">+ Nova banda</a><?php endif; ?>
      </div>
      <input type="search" id="busca" placeholder="Buscar por banda ou tributo…" class="busca">
      <ul class="lista">
        <?php foreach ($a['bandas'] as $b): $p = $proximo[$b['id']] ?? null; ?>
          <li data-busca="<?= e(mb_strtolower($b['nome'] . ' ' . implode(' ', $b['tributos']))) ?>">
            <a href="./?acao=banda&id=<?= e($b['id']) ?>">
              <img src="../<?= e($b['fotos'][0]['mini'] ?? 'assets/img/rotulo-padrao.png') ?>" alt="" width="56" height="56" loading="lazy">
              <span class="info">
                <strong><?= e($b['nome']) ?></strong>
                <span><?= e(implode(' · ', $b['tributos']) ?: 'Sem tributo cadastrado') ?></span>
                <?php if ($b['instagram'] !== ''): ?><span>@<?= e($b['instagram']) ?></span><?php endif; ?>
                <span class="tags">
                  <em class="tag cinza"><?= count($b['fotos']) ?> foto<?= count($b['fotos']) === 1 ? '' : 's' ?></em>
                  <?php if ($p): ?><em class="tag">Próximo: <?= e(dh_data_curta($p['data'])) ?> · <?= e(DH_UNIDADES[$p['unidade']]) ?></em><?php endif; ?>
                </span>
              </span>
              <span class="seta">›</span>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php
}

function agenda_tela_banda(): void
{
    global $csrf, $erro;
    $a = dh_agenda();
    $id = (string) ($_GET['id'] ?? $_POST['id'] ?? '');
    $b = null;
    foreach ($a['bandas'] as $x) {
        if ($x['id'] === $id) {
            $b = $x;
        }
    }
    if (!$b && !pode('agenda_editar')) {
        echo '<p class="erro">Banda não encontrada.</p>';
        return;
    }
    if (!pode('bandas_editar')) {
        agenda_tela_banda_ver($b);
        return;
    }
    $b = $b ?? ['id' => '', 'nome' => '', 'tributos' => [], 'cache' => '', 'consumacao' => '', 'instagram' => '', 'contato' => '', 'obs' => '', 'fotos' => []];
    if ($erro && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['nome'])) {
        $b['nome'] = $_POST['nome'];
        $b['tributos'] = dh_tributos_texto((string) ($_POST['tributos'] ?? ''));
    }
    $hoje = date('Y-m-d');
    $shows = array_values(array_filter($a['shows'], fn($s) => $s['banda_id'] === $b['id'] && $s['data'] >= $hoje && $s['status'] !== 'cancelado'));
    ?>
      <p><a class="voltar" href="./?acao=bandas">‹ Voltar para Bandas</a></p>
      <h1><?= $b['id'] ? e($b['nome']) : 'Nova banda' ?></h1>
      <?php if ($shows): ?>
        <p class="aviso">Próximos shows: <?= e(implode(' · ', array_map(fn($s) => dh_data_curta($s['data']) . ' ' . $s['hora'] . ' (' . DH_UNIDADES[$s['unidade']] . ')', $shows))) ?></p>
      <?php endif; ?>
      <?php if (!empty($b['drive'])): ?>
        <p><?php foreach ($b['drive'] as $n => $pasta): ?><a class="btn secundario" href="https://drive.google.com/drive/folders/<?= e(rawurlencode($pasta)) ?>" target="_blank" rel="noopener">Abrir pasta no Google Drive<?= count($b['drive']) > 1 ? ' ' . ($n + 1) : '' ?> ↗</a> <?php endforeach; ?></p>
      <?php endif; ?>
      <form method="post" action="./?acao=banda-salvar" enctype="multipart/form-data" class="form-cerveja">
        <?= $csrf ?>
        <input type="hidden" name="id" value="<?= e($b['id']) ?>">
        <label>Nome da banda <input name="nome" id="nome-banda" value="<?= e($b['nome']) ?>" required maxlength="80" autocomplete="off"></label>
        <?php if (!$b['id']): ?>
          <div class="sugestoes" id="ja-cadastradas" hidden></div>
          <script>
            // Ao digitar o nome, mostra as bandas já cadastradas parecidas (para não cadastrar repetida)
            (function () {
              var bandas = <?= json_encode(array_map(fn($x) => ['id' => $x['id'], 'nome' => $x['nome']], $a['bandas']), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
              var campo = document.getElementById('nome-banda'), caixa = document.getElementById('ja-cadastradas');
              function chave(t) {
                return t.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/\b(banda|the|os|as)\b/g, '').replace(/[^a-z0-9]/g, '');
              }
              campo.addEventListener('input', function () {
                var k = chave(campo.value);
                var achadas = k ? bandas.filter(function (b) { return chave(b.nome).indexOf(k) >= 0; }).slice(0, 8) : [];
                caixa.innerHTML = '';
                if (achadas.length) {
                  var p = document.createElement('p'); p.className = 'dica'; p.textContent = 'Já cadastradas (toque para abrir):'; caixa.appendChild(p);
                }
                achadas.forEach(function (b) {
                  var a = document.createElement('a'); a.href = './?acao=banda&id=' + encodeURIComponent(b.id); a.textContent = b.nome; caixa.appendChild(a);
                });
                caixa.hidden = !achadas.length;
              });
            })();
          </script>
        <?php endif; ?>
        <label>Tributos que ela toca <small>(um por linha)</small>
          <textarea name="tributos" rows="3" placeholder="Tributo ao Legião Urbana&#10;Tributo ao Barão Vermelho"><?= e(implode("\n", $b['tributos'])) ?></textarea>
        </label>
        <?php if (pode('valores')): ?>
          <div class="duas">
            <label>Cachê padrão (R$) <input name="cache" value="<?= e(dh_num($b['cache'])) ?>" inputmode="decimal"></label>
            <label>Consumação padrão (R$) <input name="consumacao" value="<?= e(dh_num($b['consumacao'])) ?>" inputmode="decimal"></label>
          </div>
          <div class="duas">
            <label>Chave Pix <input name="pix" value="<?= e($b['pix'] ?? '') ?>" maxlength="140" autocapitalize="none" placeholder="A banda pode preencher pelo link"></label>
            <label>Nome de quem recebe <input name="pix_nome" value="<?= e($b['pix_nome'] ?? '') ?>" maxlength="80"></label>
          </div>
        <?php endif; ?>
        <div class="duas">
          <label>Instagram <input name="instagram" value="<?= e($b['instagram']) ?>" placeholder="@banda" autocapitalize="none" maxlength="60"></label>
          <label>Contato <input name="contato" value="<?= e($b['contato']) ?>" placeholder="Nome e telefone" maxlength="120"></label>
        </div>
        <label>Observações internas <textarea name="obs" rows="2" maxlength="1000"><?= e($b['obs']) ?></textarea></label>
        <label>Adicionar fotos <small>(várias de uma vez; a original é guardada sem perder qualidade, limite do servidor: <?= e(ini_get('upload_max_filesize')) ?> por foto)</small>
          <input type="file" name="fotos[]" accept="image/jpeg,image/png,image/webp" multiple>
        </label>
        <div class="barra-salvar"><button class="btn primario">Salvar banda</button></div>
      </form>

      <?php if ($b['id']): $linkBanda = !empty($b['token']) ? dh_url_site() . 'banda/?t=' . $b['token'] : ''; ?>
        <h2 id="link">Link para a banda</h2>
        <p class="dica">A banda abre o link no celular e atualiza só as fotos, o Instagram e a chave Pix, sem precisar de senha. Ninguém mais vê os outros dados.<?php if (!empty($b['atualizado_pela_banda'])): ?> <strong>Última atualização feita pela banda: <?= e(date('d/m/Y H:i', strtotime($b['atualizado_pela_banda']))) ?>.</strong><?php endif; ?></p>
        <?php if ($linkBanda): ?>
          <input class="link-banda" value="<?= e($linkBanda) ?>" readonly onclick="this.select()">
          <p class="botoes-link">
            <button type="button" class="btn secundario" onclick="navigator.clipboard.writeText('<?= e($linkBanda) ?>').then(function(){ alert('Link copiado!'); })">Copiar link</button>
            <a class="btn secundario" target="_blank" rel="noopener" href="https://wa.me/?text=<?= e(rawurlencode("Olá, " . $b['nome'] . ", aqui é a Donkey Head Cervejaria.\n\nAcesse este link para enviar/atualizar as fotos, o Instagram e os dados bancários:\n" . $linkBanda)) ?>">Enviar pelo WhatsApp</a>
          </p>
        <?php endif; ?>
        <form method="post" action="./?acao=banda-link"<?= $linkBanda ? ' onsubmit="return confirm(\'Criar um link novo? O link enviado antes para a banda deixa de funcionar.\');"' : '' ?>>
          <?= $csrf ?><input type="hidden" name="id" value="<?= e($b['id']) ?>">
          <button class="btn <?= $linkBanda ? 'secundario' : 'primario' ?>"><?= $linkBanda ? 'Criar link novo (cancela o anterior)' : 'Criar link para a banda' ?></button>
        </form>
      <?php endif; ?>

      <?php if ($b['fotos']): ?>
        <h2 id="fotos">Fotos <small><?= count($b['fotos']) ?></small></h2>
        <p><a class="btn primario largo" href="./?acao=banda-zip&id=<?= e($b['id']) ?>">Baixar todas as fotos (originais)</a></p>
        <ul class="grade-fotos">
          <?php foreach ($b['fotos'] as $n => $f): ?>
            <li>
              <a href="../<?= e($f['original']) ?>" target="_blank" download><img src="../<?= e($f['mini']) ?>" alt=""></a>
              <span class="pos"><?= $n === 0 ? 'Principal' : $n + 1 ?></span>
              <form method="post" action="./?acao=banda-foto" class="botoes">
                <?= $csrf ?><input type="hidden" name="id" value="<?= e($b['id']) ?>"><input type="hidden" name="foto" value="<?= e($f['id']) ?>">
                <small><?= (int) ($f['largura'] ?? 0) ?>×<?= (int) ($f['altura'] ?? 0) ?></small>
                <?php if ($n > 0): ?><button class="btn mini" name="op" value="principal" title="Usar como foto principal no site">★</button><?php endif; ?>
                <button class="btn mini perigo" name="op" value="excluir" aria-label="Excluir foto" onclick="return confirm('Excluir esta foto?')">✕</button>
              </form>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <?php if ($b['id'] && pode('agenda_editar')): ?>
        <form method="post" action="./?acao=banda-excluir" class="excluir" onsubmit="return confirm('Excluir a banda <?= e($b['nome']) ?> e as fotos dela?');">
          <?= $csrf ?><input type="hidden" name="id" value="<?= e($b['id']) ?>">
          <button class="btn perigo">Excluir banda</button>
        </form>
      <?php endif; ?>
    <?php
}

// Banda para quem só visualiza (designer): nome, tributos, Instagram e fotos para baixar
function agenda_tela_banda_ver(array $b): void
{
    ?>
      <p><a class="voltar" href="./?acao=bandas">‹ Voltar para Bandas</a></p>
      <h1><?= e($b['nome']) ?></h1>
      <dl class="ficha-ver">
        <dt>Tributo</dt>
        <dd><?= $b['tributos'] ? implode('<br>', array_map('e', $b['tributos'])) : 'Sem tributo cadastrado' ?></dd>
        <dt>Instagram</dt>
        <dd><?php if ($b['instagram'] !== ''): ?><a href="https://www.instagram.com/<?= e(rawurlencode($b['instagram'])) ?>/" target="_blank" rel="noopener">@<?= e($b['instagram']) ?> ↗</a><?php else: ?>Não informado<?php endif; ?></dd>
        <dt>Fotos</dt>
        <dd>
          <?php if ($b['fotos']): ?>
            <a class="btn primario largo" href="./?acao=banda-zip&id=<?= e($b['id']) ?>">⬇ Baixar as <?= count($b['fotos']) ?> foto<?= count($b['fotos']) === 1 ? '' : 's' ?> (originais)</a>
          <?php endif; ?>
          <?php foreach ($b['drive'] ?? [] as $n => $pasta): ?>
            <a class="btn secundario largo" href="https://drive.google.com/drive/folders/<?= e(rawurlencode($pasta)) ?>" target="_blank" rel="noopener">Abrir pasta no Google Drive<?= count($b['drive']) > 1 ? ' ' . ($n + 1) : '' ?> ↗</a>
          <?php endforeach; ?>
          <?php if (!$b['fotos'] && empty($b['drive'])): ?>Nenhuma foto ainda.<?php endif; ?>
        </dd>
      </dl>
    <?php
}

function agenda_tela_relatorio(): void
{
    $a = dh_agenda();
    $bandas = dh_bandas_por_id($a);
    $mes = dh_mes_param();
    $shows = array_values(array_filter($a['shows'], fn($s) => substr($s['data'], 0, 7) === $mes));
    $tot = [];
    foreach (array_merge(array_keys(DH_UNIDADES), ['total']) as $u) {
        $tot[$u] = ['shows' => 0, 'cache' => 0.0, 'consumacao' => 0.0, 'pago' => 0.0];
    }
    foreach ($shows as $s) {
        if ($s['status'] === 'cancelado') {
            continue;
        }
        foreach ([$s['unidade'], 'total'] as $u) {
            $tot[$u]['shows']++;
            $tot[$u]['cache'] += (float) $s['cache'];
            $tot[$u]['consumacao'] += (float) $s['consumacao'];
            if ($s['status'] === 'pago') {
                $tot[$u]['pago'] += (float) $s['cache'];
            }
        }
    }
    ?>
      <div class="titulo-acao">
        <h1>Financeiro</h1>
        <a class="btn secundario" href="./?acao=relatorio-csv&mes=<?= e($mes) ?>">Baixar planilha</a>
      </div>
      <div class="mes-nav">
        <a class="btn mini" href="./?acao=relatorio&mes=<?= e(dh_mes_somar($mes, -1)) ?>">‹</a>
        <strong><?= e(dh_mes_nome($mes)) ?></strong>
        <a class="btn mini" href="./?acao=relatorio&mes=<?= e(dh_mes_somar($mes, 1)) ?>">›</a>
      </div>
      <div class="totais">
        <?php foreach ($tot as $u => $t): ?>
          <div class="total<?= $u === 'total' ? ' geral' : '' ?>">
            <strong><?= $u === 'total' ? 'Total do mês' : e(DH_UNIDADES[$u]) ?></strong>
            <span><?= $t['shows'] ?> show<?= $t['shows'] === 1 ? '' : 's' ?></span>
            <span>Cachês: <b><?= e(dh_moeda($t['cache'])) ?></b></span>
            <span>Consumação: <b><?= e(dh_moeda($t['consumacao'])) ?></b></span>
            <span>Cachês pagos: <?= e(dh_moeda($t['pago'])) ?> · a pagar: <?= e(dh_moeda($t['cache'] - $t['pago'])) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="tabela-rolagem">
        <table class="tabela">
          <thead><tr><th>Data</th><th>Unidade</th><th>Banda / tributo</th><th class="num">Cachê</th><th class="num">Consumação</th><th>Situação</th></tr></thead>
          <tbody>
            <?php foreach ($shows as $s): ?>
              <tr class="<?= $s['status'] === 'cancelado' ? 'cancelado' : '' ?>">
                <td><a href="./?acao=show&id=<?= e($s['id']) ?>"><?= e(dh_data_curta($s['data'])) ?></a><br><small><?= e($s['hora']) ?></small></td>
                <td><?= e(DH_UNIDADES[$s['unidade']]) ?></td>
                <td><?= e($bandas[$s['banda_id']]['nome'] ?? '—') ?><br><small><?= e($s['tributo']) ?></small></td>
                <td class="num"><?= e(dh_moeda($s['cache'])) ?></td>
                <td class="num"><?= e(dh_moeda($s['consumacao'])) ?></td>
                <td><?= agenda_status_tag($s['status']) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$shows): ?><tr><td colspan="6">Nenhum show neste mês.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
      <p class="dica">Shows cancelados não entram nos totais.</p>
    <?php
}

function agenda_tela_importar(): void
{
    global $csrf, $usuario;
    $revisar = !empty($_GET['revisar']) && isset($_SESSION['importacao']);
    $linhas = $revisar ? $_SESSION['importacao'] : [];
    $bandas = $revisar ? ($_SESSION['importacao_bandas'] ?? []) : [];
    ?>
      <p><a class="voltar" href="./?acao=usuarios">‹ Voltar</a></p>
      <h1>Importar do Google Agenda</h1>
      <?php if (!$revisar): ?>
        <p class="dica">No Google Agenda (no computador): passe o mouse na agenda dos shows → <strong>⋮ → Configurações e compartilhamento</strong> → role até <strong>"Integrar agenda"</strong> → copie o <strong>"Endereço secreto no formato iCal"</strong> e cole abaixo.</p>
        <p class="dica">Só os shows <strong>de hoje em diante</strong> entram na agenda. Os eventos antigos servem para cadastrar as bandas e completar tributo, cachê e consumação: quando o evento traz só o nome da banda, vale o tributo completo escrito em outro evento dela.</p>
        <form method="post" action="./?acao=importar-ler" enctype="multipart/form-data" class="form-cerveja">
          <?= $csrf ?>
          <label>Endereço secreto no formato iCal <input name="url" type="url" placeholder="https://calendar.google.com/calendar/ical/.../basic.ics"></label>
          <label>…ou envie o arquivo .ics exportado <input type="file" name="arquivo" accept=".ics,text/calendar"></label>
          <label>De qual unidade é essa agenda? <small>(vale para os eventos que não dizem a unidade no texto)</small>
            <select name="unidade"><?php foreach (DH_UNIDADES as $u => $nomeU): ?><option value="<?= $u ?>" <?= $u === ($usuario['unidade'] ?? '') ? 'selected' : '' ?>><?= e($nomeU) ?></option><?php endforeach; ?></select>
          </label>
          <button class="btn primario">Ler eventos</button>
        </form>
      <?php else: ?>
        <form method="post" action="./?acao=importar-confirmar" class="importacao">
          <?= $csrf ?>
          <h2>Shows de hoje em diante (<?= count($linhas) ?>)</h2>
          <?php if (!$linhas): ?><p class="dica">Nenhum show futuro nessa agenda.</p><?php else: ?>
            <p class="dica">Confira banda, tributo, unidade, horário e valores de cada show (o texto original fica embaixo). Reservas e outros compromissos já foram deixados de fora. Desmarque o que não quiser importar.</p>
          <?php endif; ?>
          <?php foreach ($linhas as $n => $l): ?>
            <fieldset data-tipo="show">
              <label class="check"><input type="checkbox" name="l[<?= $n ?>][incluir]" value="1" checked> <strong><?= e(dh_data_curta($l['data'])) ?>/<?= e(substr($l['data'], 0, 4)) ?></strong></label>
              <div class="duas">
                <label>Banda <input name="l[<?= $n ?>][banda]" value="<?= e($l['banda']) ?>"></label>
                <label>Tributo <input name="l[<?= $n ?>][tributo]" value="<?= e($l['tributo']) ?>"></label>
              </div>
              <div class="quatro">
                <label>Data <input type="date" name="l[<?= $n ?>][data]" value="<?= e($l['data']) ?>"></label>
                <label>Hora <input type="time" name="l[<?= $n ?>][hora]" value="<?= e($l['hora']) ?>"></label>
                <label>Unidade <select name="l[<?= $n ?>][unidade]"><?php foreach (DH_UNIDADES as $u => $nomeU): ?><option value="<?= $u ?>" <?= $u === $l['unidade'] ? 'selected' : '' ?>><?= e($nomeU) ?></option><?php endforeach; ?></select></label>
                <label>Situação <select name="l[<?= $n ?>][status]"><?php foreach (DH_STATUS_SHOW as $k => $rot): ?><option value="<?= $k ?>" <?= $k === $l['status'] ? 'selected' : '' ?>><?= e($rot) ?></option><?php endforeach; ?></select></label>
              </div>
              <div class="duas">
                <label>Cachê <input name="l[<?= $n ?>][cache]" value="<?= e(dh_num($l['cache'])) ?>" inputmode="decimal"></label>
                <label>Consumação <input name="l[<?= $n ?>][consumacao]" value="<?= e(dh_num($l['consumacao'])) ?>" inputmode="decimal"></label>
              </div>
              <input type="hidden" name="l[<?= $n ?>][instagram]" value="<?= e($l['instagram'] ?? '') ?>">
              <small class="original"><?= e($l['original']) ?><?php if (!empty($l['completado'])): ?><br><strong>Completado pelo histórico: <?= e(implode(', ', $l['completado'])) ?></strong><?php endif; ?></small>
            </fieldset>
          <?php endforeach; ?>

          <h2>Bandas aprendidas com o histórico (<?= count($bandas) ?>)</h2>
          <?php if ($bandas): ?>
            <p class="dica">Bandas novas e o que falta nas já cadastradas, tirado de todos os eventos da agenda (inclusive os antigos). Tributos: um por linha, já no texto mais completo. Cachê e consumação são os do show mais recente e só preenchem o que está vazio no cadastro.</p>
            <details class="bandas-hist">
              <summary>Ver e editar as <?= count($bandas) ?> bandas</summary>
              <?php foreach ($bandas as $n => $b): ?>
                <fieldset data-tipo="banda">
                  <label class="check"><input type="checkbox" name="b[<?= $n ?>][incluir]" value="1" <?= dh_nome_e_tributo($b['nome']) ? '' : 'checked' ?>> <strong><?= $b['nova'] ? 'Nova' : 'Completar' ?></strong>
                    <span class="original"><?= $b['shows'] ? e($b['shows'] . ($b['shows'] > 1 ? ' shows' : ' show') . ', o último em ' . dh_data_curta($b['ultimo']) . '/' . substr($b['ultimo'], 0, 4)) : 'só shows futuros' ?></span></label>
                  <label>Banda <input name="b[<?= $n ?>][nome]" value="<?= e($b['nome']) ?>" <?= $b['nova'] ? '' : 'readonly' ?>></label>
                  <label>Tributos <textarea name="b[<?= $n ?>][tributos]" rows="<?= max(1, count($b['tributos'])) ?>"><?= e(implode("\n", $b['tributos'])) ?></textarea></label>
                  <div class="duas">
                    <label>Cachê <input name="b[<?= $n ?>][cache]" value="<?= e(dh_num($b['cache'])) ?>" inputmode="decimal"></label>
                    <label>Consumação <input name="b[<?= $n ?>][consumacao]" value="<?= e(dh_num($b['consumacao'])) ?>" inputmode="decimal"></label>
                  </div>
                  <input type="hidden" name="b[<?= $n ?>][instagram]" value="<?= e($b['instagram']) ?>">
                </fieldset>
              <?php endforeach; ?>
            </details>
          <?php else: ?>
            <p class="dica">O cadastro das bandas já tem tudo o que essa agenda informa.</p>
          <?php endif; ?>
          <input type="hidden" name="json" id="json-importacao">
          <input type="hidden" name="json_bandas" id="json-bandas">
          <div class="barra-salvar"><button class="btn primario">Importar</button></div>
        </form>
        <script>
          document.querySelector('.importacao').addEventListener('submit', function () {
            var grupos = { show: [], banda: [] };
            this.querySelectorAll('fieldset').forEach(function (fs) {
              var l = {};
              fs.querySelectorAll('input, select, textarea').forEach(function (c) {
                var m = c.name.match(/\]\[(\w+)\]$/);
                if (!m) return;
                l[m[1]] = c.type === 'checkbox' ? (c.checked ? '1' : '') : c.value;
                c.disabled = true;
              });
              grupos[fs.dataset.tipo].push(l);
            });
            document.getElementById('json-importacao').value = JSON.stringify(grupos.show);
            document.getElementById('json-bandas').value = JSON.stringify(grupos.banda);
          });
        </script>
      <?php endif; ?>
    <?php
}
