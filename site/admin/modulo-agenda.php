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

// Guarda a foto original (sem perder qualidade, para o designer) e versões reduzidas para o site
function dh_banda_foto_salvar(array $arq, string $bandaId): array
{
    if ($arq['error'] !== UPLOAD_ERR_OK) {
        $max = ini_get('upload_max_filesize');
        throw new RuntimeException('Falha no envio de "' . $arq['name'] . '" (código ' . $arq['error'] . '). O limite do servidor é ' . $max . ' por foto.');
    }
    return dh_banda_foto_de_arquivo($arq['tmp_name'], $bandaId, true);
}

function dh_banda_foto_de_arquivo(string $caminho, string $bandaId, bool $upload = false): array
{
    $info = @getimagesize($caminho);
    $tipos = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    if (!$info || !isset($tipos[$info[2]])) {
        throw new RuntimeException('A foto precisa ser JPG, PNG ou WEBP.');
    }
    $img = @imagecreatefromstring((string) file_get_contents($caminho));
    if (!$img) {
        throw new RuntimeException('Não foi possível ler a foto.');
    }
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $giro = [3 => 180, 6 => -90, 8 => 90][(@exif_read_data($caminho)['Orientation'] ?? 1)] ?? 0;
        if ($giro) {
            $img = imagerotate($img, $giro, 0);
        }
    }
    $fid = dh_id_novo('f');
    $dir = DH_DIR_BANDAS . '/' . $bandaId;
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $original = $dir . '/original-' . $fid . '.' . $tipos[$info[2]];
    $ok = $upload ? move_uploaded_file($caminho, $original) : copy($caminho, $original);
    if (!$ok) {
        throw new RuntimeException('Não foi possível gravar a foto. Verifique a permissão da pasta "uploads".');
    }
    dh_gravar_jpeg($img, $dir . '/media-' . $fid . '.jpg', 1200, 82);
    dh_gravar_jpeg($img, $dir . '/mini-' . $fid . '.jpg', 420, 74);
    $rel = 'uploads/bandas/' . $bandaId . '/';
    return ['id' => $fid, 'original' => $rel . basename($original), 'media' => $rel . 'media-' . $fid . '.jpg',
        'mini' => $rel . 'mini-' . $fid . '.jpg', 'largura' => $info[0], 'altura' => $info[1]];
}

function dh_banda_foto_apagar(array $f): void
{
    foreach (['original', 'media', 'mini'] as $k) {
        if (strpos($f[$k] ?? '', 'uploads/bandas/') === 0) {
            @unlink(DH_RAIZ . '/' . $f[$k]);
        }
    }
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
    while (preg_match('/^\s*(pag[oa]s?|paho|cancelad[oa])\s*[-–:]\s*/iu', $titulo, $mp)) {
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
    $meta = '/\b(donkey|data\s*:|cach[eê]|consuma|consumo\b|cons\s+\d|r\$|maraponga|aldeota|barril)|\b\d{1,2}\/\d{1,2}|\b[aà]s\s*(\d|-|$)|\b\d{1,2}[:h]\d{2}h?\b|\b(segunda|terça|terca|quarta|quinta|sexta|s[aá]bado|domingo)\b|\b\d{1,2}\s*(de\s+)?(jan|fev|mar|abr|mai|jun|jul|ago|set|out|nov|dez)[a-zç]*\.?(\s|$)/iu';
    $chave = '/\b(tribut\w*|cover|homenagem|especial|ac[uú]stico)\b/iu';
    $partes = [];
    foreach (dh_segmentos($titulo) as $seg) {
        if (preg_match($meta, $seg, $mm, PREG_OFFSET_CAPTURE)) {
            $seg = trim(substr($seg, 0, $mm[0][1]), " ,.-–:");
        }
        // sobra sem palavra de verdade ("as", "-") é descartada
        if (mb_strlen(preg_replace('/[^\p{L}\d]/u', '', $seg)) >= 3) {
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
    $tributo = trim(implode(', ', $tributo), " ,");
    $temTributo = $tributo !== '';
    if ($banda === '' && $tributo !== '') {
        // Só o tributo foi escrito: usa como nome, para revisar
        $banda = $tributo;
        $tributo = '';
    }
    $cache = dh_valor($mc[1] ?? '');
    $reserva = preg_match('/reservad|n[uú]mero de pessoas|\bpessoas\b|corporativ|confraterniza|degusta|reuni[aã]o|influencer|anivers|pedir |\bnome:/iu', $tudo);
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
        'original' => $ev['titulo'] . ($ev['descricao'] !== '' ? ' — ' . mb_substr(preg_replace('/\s+/', ' ', $ev['descricao']), 0, 160) : ''),
        'uid' => $ev['uid'],
    ];
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
            if (!isset($bandas[$bandaId])) {
                throw new RuntimeException('Escolha a banda (cadastre-a antes em "Bandas", se for nova).');
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
                'status' => isset(DH_STATUS_SHOW[$_POST['status'] ?? '']) ? $_POST['status'] : 'a_confirmar',
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
            exigir('bandas');
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
            $b['instagram'] = mb_substr(ltrim(trim((string) ($_POST['instagram'] ?? '')), '@'), 0, 60);
            $b['contato'] = mb_substr(trim((string) ($_POST['contato'] ?? '')), 0, 120);
            $b['obs'] = mb_substr(trim((string) ($_POST['obs'] ?? '')), 0, 1000);
            if (pode('valores')) {
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
            exigir('bandas');
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
            $desde = (string) ($_POST['desde'] ?? '');
            $unidadePadrao = isset(DH_UNIDADES[$_POST['unidade'] ?? '']) ? $_POST['unidade'] : 'aldeota';
            $chaves = [];
            foreach ($a['bandas'] as $b) {
                $chaves[dh_chave_banda($b['nome'])] = $b['nome'];
            }
            $linhas = [];
            foreach (dh_ics_eventos($ics) as $ev) {
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde) && $ev['data'] < $desde) {
                    continue;
                }
                $l = dh_ics_interpretar($ev, $a['config']['hora_padrao'], $unidadePadrao, $chaves);
                // Usa o nome do cadastro quando a banda já existe ("SONORAH" -> "Banda Sonorah")
                $l['banda'] = $chaves[dh_chave_banda($l['banda'])] ?? $l['banda'];
                if ($l['status'] === '') {
                    $l['status'] = $l['data'] < date('Y-m-d') ? 'pago' : 'confirmado';
                }
                // Reservas e outros compromissos nem aparecem na revisão
                if ($l['show']) {
                    $linhas[] = $l;
                }
            }
            if (!$linhas) {
                throw new RuntimeException('Nenhum show encontrado a partir dessa data (reservas e outros compromissos são ignorados).');
            }
            $_SESSION['importacao'] = $linhas;
            ir('./?acao=importar&revisar=1', count($linhas) . ' shows encontrados. Revise antes de importar.');

        case 'importar-confirmar':
            exigir_post();
            exigir('importar');
            $telaErro = 'importar';
            $a = dh_agenda();
            $porNome = [];
            foreach ($a['bandas'] as $i => $b) {
                $porNome[dh_chave_banda($b['nome'])] = $i;
            }
            $novasBandas = $novosShows = $repetidos = 0;
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
                if ($tributo !== '' && !in_array($tributo, $b['tributos'], true)) {
                    $b['tributos'][] = $tributo;
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
            unset($_SESSION['importacao']);
            ir('./?acao=agenda', "Importação concluída: $novosShows shows e $novasBandas bandas novas" . ($repetidos ? " ($repetidos já existiam e foram ignorados)" : '') . '.');
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
    return '<em class="tag st-' . e($st) . '">' . e(DH_STATUS_SHOW[$st] ?? $st) . '</em>';
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
      <div class="mes-nav">
        <a class="btn mini" href="<?= e($base . '&mes=' . dh_mes_somar($mes, -1)) ?>">‹</a>
        <strong><?= e(dh_mes_nome($mes)) ?></strong>
        <a class="btn mini" href="<?= e($base . '&mes=' . dh_mes_somar($mes, 1)) ?>">›</a>
      </div>
      <div class="abas">
        <a href="./?acao=agenda&mes=<?= e($mes) ?>" class="<?= !$un ? 'ativo' : '' ?>">Todas</a>
        <?php foreach (DH_UNIDADES as $u => $nomeU): ?>
          <a href="./?acao=agenda&mes=<?= e($mes) ?>&unidade=<?= $u ?>" class="<?= $un === $u ? 'ativo' : '' ?>"><?= e($nomeU) ?></a>
        <?php endforeach; ?>
      </div>
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
    $s = $s ?? ['id' => '', 'data' => (string) ($_GET['data'] ?? ''), 'hora' => $a['config']['hora_padrao'], 'unidade' => $usuario['unidade'] ?? '' ?: 'aldeota', 'banda_id' => '',
        'tributo' => '', 'cache' => '', 'consumacao' => '', 'status' => 'a_confirmar', 'obs' => ''];
    if ($erro && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $s = array_merge($s, array_intersect_key($_POST, $s));
    }
    if (!pode('agenda_editar')) {
        echo '<p class="erro">Seu usuário só pode ver a agenda.</p>';
        return;
    }
    $bandas = $a['bandas'];
    ?>
      <p><a href="./?acao=agenda<?= $s['data'] ? '&mes=' . e(substr($s['data'], 0, 7)) : '' ?>">‹ Voltar para a Agenda</a></p>
      <h1><?= $s['id'] ? 'Editar show' : 'Novo show' ?></h1>
      <?php if (!$bandas): ?><p class="aviso">Cadastre a banda antes, em <a href="./?acao=banda">Bandas → Nova banda</a>.</p><?php endif; ?>
      <form method="post" action="./?acao=show-salvar" class="form-cerveja" id="form-show">
        <?= $csrf ?>
        <input type="hidden" name="id" value="<?= e($s['id']) ?>">
        <div class="duas">
          <label>Data <input type="date" name="data" value="<?= e($s['data']) ?>" required></label>
          <label>Horário <input type="time" name="hora" value="<?= e($s['hora']) ?>" required></label>
        </div>
        <label>Unidade
          <select name="unidade"><?php foreach (DH_UNIDADES as $u => $nomeU): ?><option value="<?= $u ?>" <?= $u === $s['unidade'] ? 'selected' : '' ?>><?= e($nomeU) ?></option><?php endforeach; ?></select>
        </label>
        <label>Banda
          <select name="banda_id" id="banda" required>
            <option value="">—</option>
            <?php foreach ($bandas as $b): ?>
              <option value="<?= e($b['id']) ?>" data-tributos="<?= e(json_encode($b['tributos'], JSON_UNESCAPED_UNICODE)) ?>" data-cache="<?= e(dh_num($b['cache'])) ?>" data-consumacao="<?= e(dh_num($b['consumacao'])) ?>" <?= $b['id'] === $s['banda_id'] ? 'selected' : '' ?>><?= e($b['nome']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Tributo
          <select name="tributo" id="tributo" data-atual="<?= e($s['tributo']) ?>"></select>
        </label>
        <label id="tributo-outro" hidden>Nome do tributo novo <input name="tributo_outro" maxlength="120" placeholder="ex.: Tributo ao Queen"></label>
        <div class="duas">
          <label>Cachê (R$) <input name="cache" value="<?= e(dh_num($s['cache'])) ?>" inputmode="decimal" id="cache"></label>
          <label>Consumação (R$) <input name="consumacao" value="<?= e(dh_num($s['consumacao'])) ?>" inputmode="decimal" id="consumacao"></label>
        </div>
        <label>Situação
          <select name="status"><?php foreach (DH_STATUS_SHOW as $k => $rot): ?><option value="<?= $k ?>" <?= $k === $s['status'] ? 'selected' : '' ?>><?= e($rot) ?></option><?php endforeach; ?></select>
        </label>
        <p class="dica">"A confirmar" e "Cancelado" não aparecem no site.</p>
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
          var banda = document.getElementById('banda'), trib = document.getElementById('tributo');
          var outro = document.getElementById('tributo-outro'), primeira = true;
          function montar() {
            var op = banda.options[banda.selectedIndex];
            var lista = op && op.dataset.tributos ? JSON.parse(op.dataset.tributos) : [];
            var atual = primeira ? trib.dataset.atual : '';
            if (atual && lista.indexOf(atual) < 0) lista.push(atual);
            trib.innerHTML = '<option value="">— sem tributo —</option>' + lista.map(function (t) {
              var o = document.createElement('option'); o.value = o.textContent = t; if (t === atual || (!atual && lista.length === 1)) o.selected = true; return o.outerHTML;
            }).join('') + '<option value="__outro">+ Outro tributo…</option>';
            outro.hidden = true;
            // Ao trocar de banda, traz o cachê e a consumação padrão dela
            if (!primeira && op && op.value) {
              document.getElementById('cache').value = op.dataset.cache || '';
              document.getElementById('consumacao').value = op.dataset.consumacao || '';
            }
            primeira = false;
          }
          banda.addEventListener('change', montar);
          trib.addEventListener('change', function () { outro.hidden = trib.value !== '__outro'; });
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
    $b = $b ?? ['id' => '', 'nome' => '', 'tributos' => [], 'cache' => '', 'consumacao' => '', 'instagram' => '', 'contato' => '', 'obs' => '', 'fotos' => []];
    if ($erro && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['nome'])) {
        $b['nome'] = $_POST['nome'];
        $b['tributos'] = dh_tributos_texto((string) ($_POST['tributos'] ?? ''));
    }
    $hoje = date('Y-m-d');
    $shows = array_values(array_filter($a['shows'], fn($s) => $s['banda_id'] === $b['id'] && $s['data'] >= $hoje && $s['status'] !== 'cancelado'));
    ?>
      <p><a href="./?acao=bandas">‹ Voltar para Bandas</a></p>
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
        <label>Nome da banda <input name="nome" value="<?= e($b['nome']) ?>" required maxlength="80"></label>
        <label>Tributos que ela toca <small>(um por linha)</small>
          <textarea name="tributos" rows="3" placeholder="Tributo ao Legião Urbana&#10;Tributo ao Barão Vermelho"><?= e(implode("\n", $b['tributos'])) ?></textarea>
        </label>
        <?php if (pode('valores')): ?>
          <div class="duas">
            <label>Cachê padrão (R$) <input name="cache" value="<?= e(dh_num($b['cache'])) ?>" inputmode="decimal"></label>
            <label>Consumação padrão (R$) <input name="consumacao" value="<?= e(dh_num($b['consumacao'])) ?>" inputmode="decimal"></label>
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
    global $csrf;
    $linhas = !empty($_GET['revisar']) ? ($_SESSION['importacao'] ?? []) : [];
    ?>
      <p><a href="./?acao=usuarios">‹ Voltar</a></p>
      <h1>Importar do Google Agenda</h1>
      <?php if (!$linhas): ?>
        <p class="dica">No Google Agenda (no computador): passe o mouse na agenda dos shows → <strong>⋮ → Configurações e compartilhamento</strong> → role até <strong>"Integrar agenda"</strong> → copie o <strong>"Endereço secreto no formato iCal"</strong> e cole abaixo.</p>
        <form method="post" action="./?acao=importar-ler" enctype="multipart/form-data" class="form-cerveja">
          <?= $csrf ?>
          <label>Endereço secreto no formato iCal <input name="url" type="url" placeholder="https://calendar.google.com/calendar/ical/.../basic.ics"></label>
          <label>…ou envie o arquivo .ics exportado <input type="file" name="arquivo" accept=".ics,text/calendar"></label>
          <label>Unidade dos shows que não disserem a unidade no texto
            <select name="unidade"><?php foreach (DH_UNIDADES as $u => $nomeU): ?><option value="<?= $u ?>" <?= $u === 'maraponga' ? 'selected' : '' ?>><?= e($nomeU) ?></option><?php endforeach; ?></select>
          </label>
          <label>Importar eventos a partir de <input type="date" name="desde" value="2023-01-01"></label>
          <button class="btn primario">Ler eventos</button>
        </form>
      <?php else: ?>
        <p class="dica">Confira banda, tributo, unidade, horário e valores de cada evento (o texto original fica embaixo). Reservas e outros compromissos já foram deixados de fora. Desmarque o que não quiser importar. Bandas com o mesmo nome são juntadas num só cadastro.</p>
        <form method="post" action="./?acao=importar-confirmar" class="importacao">
          <?= $csrf ?>
          <?php foreach ($linhas as $n => $l): ?>
            <fieldset>
              <label class="check"><input type="checkbox" name="l[<?= $n ?>][incluir]" value="1" <?= !empty($l['show']) ? 'checked' : '' ?>> <strong><?= e(dh_data_curta($l['data'])) ?>/<?= e(substr($l['data'], 0, 4)) ?></strong></label>
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
              <small class="original"><?= e($l['original']) ?></small>
            </fieldset>
          <?php endforeach; ?>
          <input type="hidden" name="json" id="json-importacao">
          <div class="barra-salvar"><button class="btn primario">Importar shows marcados</button></div>
        </form>
        <script>
          document.querySelector('.importacao').addEventListener('submit', function () {
            var linhas = [];
            this.querySelectorAll('fieldset').forEach(function (fs) {
              var l = {};
              fs.querySelectorAll('input, select').forEach(function (c) {
                var m = c.name.match(/\]\[(\w+)\]$/);
                if (!m) return;
                l[m[1]] = c.type === 'checkbox' ? (c.checked ? '1' : '') : c.value;
                c.disabled = true;
              });
              linhas.push(l);
            });
            document.getElementById('json-importacao').value = JSON.stringify(linhas);
          });
        </script>
      <?php endif; ?>
    <?php
}
