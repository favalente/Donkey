<?php
// Agenda de shows: cadastro de bandas e programação das duas unidades.
// Fica em dados/agenda.json (gravado pelo painel). Cachê e consumação são internos:
// nunca saem nas páginas públicas nem na agenda pública (.ics).

const DH_ARQ_AGENDA = DH_RAIZ . '/dados/agenda.json';
const DH_DIR_BANDAS = DH_RAIZ . '/uploads/bandas';
const DH_STATUS_SHOW = ['a_confirmar' => 'A confirmar', 'confirmado' => 'Confirmado', 'pago' => 'Pago', 'cancelado' => 'Cancelado'];
// Só estes aparecem para o público
const DH_STATUS_PUBLICOS = ['confirmado', 'pago'];
const DH_DIAS_SEMANA = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];
const DH_MESES = [1 => 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];

function dh_agenda(): array
{
    $a = json_decode((string) @file_get_contents(DH_ARQ_AGENDA), true);
    $a = is_array($a) ? $a : [];
    $a += ['bandas' => [], 'shows' => [], 'config' => []];
    $a['config'] += ['hora_padrao' => '20:30', 'token_equipe' => ''];
    // O padrão antigo (21:00) vira 20:30, a não ser que alguém tenha escolhido o horário no painel
    if ($a['config']['hora_padrao'] === '21:00' && empty($a['config']['hora_escolhida'])) {
        $a['config']['hora_padrao'] = '20:30';
    }
    $a = dh_agenda_bandas_iniciais($a);
    $a = dh_agenda_limpar_nomes($a);
    return $a;
}

// Nome da banda sem a palavra "banda": "Banda Silverside" -> "Silverside", "Iago Vital e Banda" -> "Iago Vital"
function dh_nome_sem_banda(string $nome): string
{
    $n = preg_replace('/^\s*a\s+banda\b/iu', '', $nome);
    $n = preg_replace('/\bbanda\b/iu', ' ', $n);
    $n = preg_replace('/\s+(e|&)\s*$/iu', '', trim($n));
    $n = trim(preg_replace('/\s+/u', ' ', $n), " -–:,");
    return $n !== '' ? $n : trim($nome);
}

// Nomes que são, na verdade, um tributo (erro da importação): "Tributo Divas Miss Rock"
function dh_nome_e_tributo(string $nome): bool
{
    return preg_match('/^\s*(tributo|tributos|trib\.?|cover|homenagem)\b/iu', $nome) === 1;
}

// Uma vez só: tira "banda" dos nomes, junta bandas que ficaram com o mesmo nome e apaga as
// "bandas" que eram tributo. Se uma delas tiver show, o show passa para a banda citada no nome;
// sem banda reconhecível, ela fica para ser revista no painel. Guarda uma cópia antes.
function dh_agenda_limpar_nomes(array $a): array
{
    if (!empty($a['config']['nomes_limpos']) || !$a['bandas']) {
        return $a;
    }
    $dir = dirname(DH_ARQ_AGENDA) . '/backup';
    if (is_file(DH_ARQ_AGENDA)) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @copy(DH_ARQ_AGENDA, $dir . '/agenda-antes-limpeza-nomes.json');
    }
    $comShow = [];
    foreach ($a['shows'] as $s) {
        $comShow[$s['banda_id']] = true;
    }
    foreach ($a['bandas'] as &$b) {
        if (!dh_nome_e_tributo($b['nome'])) {
            $b['nome'] = dh_nome_sem_banda($b['nome']);
        }
    }
    unset($b);
    // Bandas com o mesmo nome viram uma só (a que tem mais dados fica)
    $peso = fn($b) => count($b['fotos'] ?? []) * 10 + count($b['drive'] ?? []) * 5 + count($b['tributos'] ?? []) + ($b['cache'] !== '' ? 1 : 0);
    $porChave = [];
    $trocar = [];
    foreach ($a['bandas'] as $i => $b) {
        $k = dh_chave_banda($b['nome']);
        if (dh_nome_e_tributo($b['nome']) || $k === '') {
            continue;
        }
        if (!isset($porChave[$k])) {
            $porChave[$k] = $i;
            continue;
        }
        $j = $porChave[$k];
        [$fica, $sai] = $peso($a['bandas'][$i]) > $peso($a['bandas'][$j]) ? [$i, $j] : [$j, $i];
        $f = &$a['bandas'][$fica];
        $o = $a['bandas'][$sai];
        $f['tributos'] = array_values(array_unique(array_merge($f['tributos'] ?? [], $o['tributos'] ?? [])));
        $f['fotos'] = array_merge($f['fotos'] ?? [], $o['fotos'] ?? []);
        $f['drive'] = array_values(array_unique(array_merge($f['drive'] ?? [], $o['drive'] ?? [])));
        foreach (['cache', 'consumacao', 'instagram', 'contato', 'obs'] as $c) {
            if (($f[$c] ?? '') === '' && ($o[$c] ?? '') !== '') {
                $f[$c] = $o[$c];
            }
        }
        unset($f);
        $trocar[$a['bandas'][$sai]['id']] = $a['bandas'][$fica]['id'];
        $a['bandas'][$sai]['_sai'] = true;
        $porChave[$k] = $fica;
    }
    // "Bandas" que eram tributo
    foreach ($a['bandas'] as $i => $b) {
        if (!dh_nome_e_tributo($b['nome'])) {
            continue;
        }
        if (empty($comShow[$b['id']])) {
            $a['bandas'][$i]['_sai'] = true;
            continue;
        }
        // "Tributo Guns e Kiss, Fets Domino": a banda de verdade está no fim do nome
        $pal = preg_split('/[\s,]+/u', trim($b['nome']));
        for ($n = 1; $n < count($pal); $n++) {
            $k = dh_chave_banda(implode(' ', array_slice($pal, $n)));
            if (strlen($k) >= 4 && isset($porChave[$k])) {
                $trocar[$b['id']] = $a['bandas'][$porChave[$k]]['id'];
                $a['bandas'][$i]['_sai'] = true;
                $tributo = trim(implode(' ', array_slice($pal, 0, $n)), ' ,');
                foreach ($a['shows'] as &$s) {
                    if ($s['banda_id'] === $b['id'] && $s['tributo'] === '') {
                        $s['tributo'] = $tributo;
                    }
                }
                unset($s);
                break;
            }
        }
    }
    foreach ($a['shows'] as &$s) {
        $s['banda_id'] = $trocar[$s['banda_id']] ?? $s['banda_id'];
    }
    unset($s);
    $a['bandas'] = array_values(array_filter($a['bandas'], fn($b) => empty($b['_sai'])));
    $a['config']['nomes_limpos'] = date('Y-m-d H:i');
    try {
        dh_agenda_salvar($a);
    } catch (RuntimeException $e) {
        // Sem permissão de gravar: tenta de novo na próxima vez
    }
    return $a;
}

// Chave para reconhecer a mesma banda escrita de jeitos diferentes:
// "Banda Sonorah" = "SONORAH" = "Banda - Sonorah"; "Di Sousa Band" = "Di Sousa"; "Os Analógicos" = "Analógicos"
function dh_chave_banda(string $nome): string
{
    // "Michel Angelo & The Nightrain" = "Michel Angelo e Nightrain"
    $k = dh_slug(str_replace('&', ' e ', $nome));
    $k = preg_replace('/(^|-)the-/', '$1', $k);
    $k = preg_replace('/^(a-)?banda-/', '', $k);
    $k = preg_replace('/^(os|as|o|a)-/', '', $k);
    $k = preg_replace('/-(e-)?banda$|-band$/', '', $k);
    // "Red Blush" = "RedBlush", "Full Rock" = "Fullrock"
    return str_replace('-', '', $k);
}

// Na primeira vez, cadastra as bandas das pastas do Google Drive (dados-iniciais/bandas-drive.json)
function dh_agenda_bandas_iniciais(array $a): array
{
    $arq = DH_RAIZ . '/dados-iniciais/bandas-drive.json';
    $lista = json_decode((string) @file_get_contents($arq), true);
    if (!empty($a['config']['bandas_drive']) || !is_array($lista)) {
        return $a;
    }
    $existentes = [];
    foreach ($a['bandas'] as $i => $b) {
        $existentes[dh_chave_banda($b['nome'])] = $i;
    }
    foreach ($lista as $n => $item) {
        $k = dh_chave_banda($item['nome']);
        if (isset($existentes[$k])) {
            $a['bandas'][$existentes[$k]]['drive'] = $item['drive'];
            continue;
        }
        $a['bandas'][] = ['id' => 'b' . date('ymdHis') . sprintf('%03d', $n), 'nome' => $item['nome'], 'tributos' => [], 'cache' => '', 'consumacao' => '',
            'instagram' => '', 'contato' => '', 'obs' => '', 'fotos' => [], 'drive' => $item['drive']];
        $existentes[$k] = count($a['bandas']) - 1;
    }
    $a['config']['bandas_drive'] = date('Y-m-d H:i');
    try {
        dh_agenda_salvar($a);
    } catch (RuntimeException $e) {
        // Sem permissão de gravar: tenta de novo na próxima vez
    }
    return $a;
}

function dh_agenda_salvar(array $a): void
{
    $dir = dirname(DH_ARQ_AGENDA);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    usort($a['bandas'], fn($x, $y) => strcasecmp(dh_slug($x['nome']), dh_slug($y['nome'])));
    usort($a['shows'], fn($x, $y) => strcmp($x['data'] . $x['hora'], $y['data'] . $y['hora']));
    $json = json_encode($a, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $tmp = DH_ARQ_AGENDA . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) === false || !rename($tmp, DH_ARQ_AGENDA)) {
        @unlink($tmp);
        throw new RuntimeException('Não foi possível gravar a agenda. Verifique a permissão da pasta "dados".');
    }
    $bkp = $dir . '/backup';
    if (!is_dir($bkp)) {
        mkdir($bkp, 0755, true);
    }
    @copy(DH_ARQ_AGENDA, $bkp . '/agenda-' . date('Y-m-d') . '.json');
}

function dh_bandas_por_id(array $a): array
{
    $m = [];
    foreach ($a['bandas'] as $b) {
        $m[$b['id']] = $b;
    }
    return $m;
}

function dh_id_novo(string $prefixo): string
{
    return $prefixo . date('ymdHis') . bin2hex(random_bytes(2));
}

// Foto principal da banda (versão reduzida para o site) ou o logo da casa
function dh_banda_foto(?array $b): string
{
    return $b['fotos'][0]['media'] ?? 'assets/img/rotulo-padrao.png';
}

// "2026-11-07" -> "Sábado, 07/11"
function dh_data_curta(string $data): string
{
    $t = strtotime($data);
    return DH_DIAS_SEMANA[(int) date('w', $t)] . ', ' . date('d/m', $t);
}

// Shows que o público vê, a partir de uma data (inclusive), já com os dados da banda
function dh_shows_publicos(array $a, string $de, ?string $ate = null, ?string $unidade = null): array
{
    $bandas = dh_bandas_por_id($a);
    $lista = [];
    foreach ($a['shows'] as $s) {
        if (!in_array($s['status'], DH_STATUS_PUBLICOS, true) || $s['data'] < $de || ($ate && $s['data'] > $ate)) {
            continue;
        }
        if ($unidade && $s['unidade'] !== $unidade) {
            continue;
        }
        $b = $bandas[$s['banda_id']] ?? null;
        $lista[] = [
            'id' => $s['id'],
            'data' => $s['data'],
            'hora' => $s['hora'],
            'unidade' => $s['unidade'],
            'banda' => $b['nome'] ?? 'Atração',
            'tributo' => $s['tributo'],
            'instagram' => $b['instagram'] ?? '',
            'foto' => dh_banda_foto($b),
        ];
    }
    return $lista;
}

function dh_moeda($v): string
{
    return is_numeric($v) ? 'R$ ' . number_format((float) $v, 2, ',', '.') : '—';
}

// Texto seguro para um campo de .ics
function dh_ics_txt(string $s): string
{
    return str_replace(["\\", ';', ',', "\r\n", "\n"], ['\\\\', '\\;', '\\,', '\\n', '\\n'], $s);
}

// Agenda no formato iCalendar (para assinar no Google Agenda). $equipe inclui valores e status.
function dh_agenda_ics(array $a, bool $equipe): string
{
    $bandas = dh_bandas_por_id($a);
    $linhas = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Donkey Head//Agenda//PT-BR', 'CALSCALE:GREGORIAN',
        'X-WR-CALNAME:' . ($equipe ? 'Donkey Head · Shows (equipe)' : 'Donkey Head · Shows'), 'X-WR-TIMEZONE:America/Fortaleza'];
    foreach ($a['shows'] as $s) {
        if (!$equipe && !in_array($s['status'], DH_STATUS_PUBLICOS, true)) {
            continue;
        }
        if ($equipe && $s['status'] === 'cancelado') {
            continue;
        }
        $b = $bandas[$s['banda_id']] ?? ['nome' => 'Atração'];
        $ini = strtotime($s['data'] . ' ' . $s['hora']);
        $titulo = $b['nome'] . ($s['tributo'] !== '' ? ' · ' . $s['tributo'] : '') . ' (' . DH_UNIDADES[$s['unidade']] . ')';
        $desc = $s['tributo'] !== '' ? 'Tributo: ' . $s['tributo'] : '';
        if ($equipe) {
            $desc .= "\nCachê: " . dh_moeda($s['cache']) . "\nConsumação: " . dh_moeda($s['consumacao'])
                . "\nSituação: " . DH_STATUS_SHOW[$s['status']] . ($s['obs'] !== '' ? "\n" . $s['obs'] : '');
            $titulo = ($s['status'] === 'a_confirmar' ? '[a confirmar] ' : '') . $titulo;
        }
        $linhas[] = 'BEGIN:VEVENT';
        $linhas[] = 'UID:' . $s['id'] . '@donkeyheadcervejaria.com.br';
        $linhas[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
        $linhas[] = 'DTSTART;TZID=America/Fortaleza:' . date('Ymd\THis', $ini);
        $linhas[] = 'DTEND;TZID=America/Fortaleza:' . date('Ymd\THis', $ini + 3 * 3600);
        $linhas[] = 'SUMMARY:' . dh_ics_txt($titulo);
        $linhas[] = 'DESCRIPTION:' . dh_ics_txt(trim($desc));
        $linhas[] = 'LOCATION:' . dh_ics_txt('Donkey Head ' . DH_UNIDADES[$s['unidade']]);
        $linhas[] = 'END:VEVENT';
    }
    $linhas[] = 'END:VCALENDAR';
    // Linhas longas são dobradas em 75 bytes, como pede o formato
    $saida = '';
    foreach ($linhas as $l) {
        while (strlen($l) > 75) {
            $corte = 75;
            while ($corte > 0 && (ord($l[$corte]) & 0xC0) === 0x80) {
                $corte--;
            }
            $saida .= substr($l, 0, $corte) . "\r\n";
            $l = ' ' . substr($l, $corte);
        }
        $saida .= $l . "\r\n";
    }
    return $saida;
}

// Cartão de show usado na página inicial e na página da agenda ($raiz: caminho até a raiz do site)
function dh_cartao_show(array $s, string $raiz = ''): string
{
    $t = strtotime($s['data']);
    $html = '<article class="show">';
    $html .= '<div class="show-foto"><img src="' . e($raiz . $s['foto']) . '" alt="' . e($s['banda']) . '" loading="lazy">';
    $html .= '<span class="show-data"><strong>' . date('d', $t) . '</strong>' . e(mb_strtoupper(mb_substr(DH_MESES[(int) date('n', $t)], 0, 3))) . '</span></div>';
    $html .= '<div class="show-info"><p class="show-quando">' . e(DH_DIAS_SEMANA[(int) date('w', $t)]) . ' · ' . e(str_replace(':', 'h', $s['hora'])) . ' · <span>' . e(DH_UNIDADES[$s['unidade']]) . '</span></p>';
    $html .= '<h3>' . e($s['banda']) . '</h3>';
    if ($s['tributo'] !== '') {
        $html .= '<p class="show-tributo">' . e($s['tributo']) . '</p>';
    }
    if ($s['instagram'] !== '') {
        $html .= '<a class="show-insta" href="https://www.instagram.com/' . e(rawurlencode($s['instagram'])) . '/" target="_blank" rel="noopener">@' . e($s['instagram']) . '</a>';
    }
    return $html . '</div></article>';
}
