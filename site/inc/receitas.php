<?php
// Receitas das cervejas (Painel → Receitas): uma por cerveja do cadastro, importadas do BeerSmith
// (BeerXML .xml ou o arquivo nativo .bsmx) ou criadas à mão. A brassagem puxa a receita da cerveja
// escolhida e guarda uma cópia no lote (se a receita mudar depois, o caderno do lote não muda).
// Dados em dados/receitas.json (só no servidor), com cópia diária em dados/backup.

const DH_ARQ_RECEITAS = DH_RAIZ . '/dados/receitas.json';
const DH_RECEITA_MAX_BYTES = 5 * 1024 * 1024;

function dh_receitas(): array
{
    $r = json_decode((string) @file_get_contents(DH_ARQ_RECEITAS), true);
    return (is_array($r) ? $r : []) + ['receitas' => []];
}

function dh_receitas_salvar(array $r): void
{
    $dir = dirname(DH_ARQ_RECEITAS);
    $json = json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $tmp = DH_ARQ_RECEITAS . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) === false || !rename($tmp, DH_ARQ_RECEITAS)) {
        @unlink($tmp);
        throw new RuntimeException('Não foi possível gravar as receitas. Verifique a permissão da pasta "dados".');
    }
    if (!is_dir($dir . '/backup')) {
        mkdir($dir . '/backup', 0755, true);
    }
    @copy(DH_ARQ_RECEITAS, $dir . '/backup/receitas-' . date('Y-m-d') . '.json');
}

function dh_receita_vazia(): array
{
    return ['id' => '', 'cerveja_id' => '', 'nome' => '', 'estilo' => '', 'volume_l' => '', 'fervura_min' => '', 'eficiencia' => '',
        'og' => '', 'fg' => '', 'abv' => '', 'ibu' => '', 'cor_ebc' => '', 'fermentaveis' => [], 'lupulos' => [], 'leveduras' => [],
        'outros' => [], 'mostura' => [], 'notas' => '', 'origem' => 'manual', 'arquivo' => '', 'versoes' => []];
}

// Receita da cerveja do cadastro (a mais recente, se houver mais de uma ligada a ela)
function dh_receita_da_cerveja(array $r, string $cervejaId): ?array
{
    $achada = null;
    foreach ($r['receitas'] as $x) {
        if ($cervejaId !== '' && $x['cerveja_id'] === $cervejaId && (!$achada || ($x['atualizado_em'] ?? '') > ($achada['atualizado_em'] ?? ''))) {
            $achada = $x;
        }
    }
    return $achada;
}

// Densidade (SG) → °Brix, para comparar com o refratômetro da fábrica
function dh_sg_brix(string $sg): ?string
{
    $g = (float) str_replace(',', '.', $sg);
    if ($g < 1 || $g > 1.2) {
        return null;
    }
    return number_format((((182.4601 * $g - 775.6821) * $g + 1262.7794) * $g - 669.5622), 1, ',', '');
}

function dh_sg_formatar(string $sg): string
{
    return $sg === '' ? '' : number_format((float) $sg, 3, ',', '');
}

// Primeiro número de um texto do BeerSmith ("1.050 SG", "30.2 IBUs", "5,4 %")
function dh_receita_num(?string $v): string
{
    return preg_match('/-?\d+(?:[.,]\d+)?/', (string) $v, $m) ? rtrim(rtrim(number_format((float) str_replace(',', '.', $m[0]), 4, '.', ''), '0'), '.') : '';
}

// ---------- Importação do BeerSmith ----------

function dh_receitas_importar(string $conteudo, string $arquivo): array
{
    if ($conteudo === '' || strlen($conteudo) > DH_RECEITA_MAX_BYTES) {
        throw new RuntimeException('Arquivo vazio ou grande demais (máximo 5 MB).');
    }
    // BeerSmith costuma gravar em Windows-1252 e com entidades HTML (&rsquo;), que o XML não aceita
    if (!mb_check_encoding($conteudo, 'UTF-8')) {
        $conteudo = mb_convert_encoding($conteudo, 'UTF-8', 'Windows-1252');
    }
    // Daqui em diante o texto é UTF-8, mesmo que o arquivo declare outra codificação
    $conteudo = preg_replace('/(<\?xml[^>]*encoding=["\'])[^"\']+/i', '$1UTF-8', $conteudo);
    $conteudo = preg_replace_callback('/&([a-zA-Z][a-zA-Z0-9]*);/', function ($m) {
        if (in_array($m[1], ['amp', 'lt', 'gt', 'quot', 'apos'], true)) {
            return $m[0];
        }
        $c = html_entity_decode($m[0], ENT_HTML5, 'UTF-8');
        return $c === $m[0] ? '&amp;' . $m[1] . ';' : htmlspecialchars($c, ENT_XML1, 'UTF-8');
    }, $conteudo);
    // O .bsmx às vezes não tem um elemento raiz único
    if (!preg_match('/^\s*(<\?xml[^>]*\?>\s*)?<([A-Za-z_][\w.-]*)[^>]*>.*<\/\2>\s*$/s', $conteudo)) {
        $conteudo = '<raiz>' . preg_replace('/<\?xml[^>]*\?>/', '', $conteudo) . '</raiz>';
    }
    $doc = new DOMDocument();
    $doc->recover = true;
    $ok = @$doc->loadXML($conteudo, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT | LIBXML_PARSEHUGE);
    if (!$ok || !$doc->documentElement) {
        throw new RuntimeException('Não consegui ler o arquivo. No BeerSmith, exporte a receita em BeerXML (.xml) ou envie o .bsmx.');
    }
    $xp = new DOMXPath($doc);
    $lista = [];
    foreach ($xp->query('//*[local-name()="RECIPE"]') as $no) {
        $lista[] = dh_receita_beerxml($xp, $no);
    }
    if (!$lista) {
        foreach ($xp->query('//*[local-name()="Recipe"][*[local-name()="F_R_NAME"]]') as $no) {
            $lista[] = dh_receita_bsmx($xp, $no);
        }
    }
    $lista = array_values(array_filter($lista, fn($x) => $x['nome'] !== ''));
    if (!$lista) {
        throw new RuntimeException('Nenhuma receita encontrada no arquivo. No BeerSmith, use Arquivo → Exportar → BeerXML, ou envie o .bsmx da receita.');
    }
    foreach ($lista as &$x) {
        $x['arquivo'] = mb_substr($arquivo, 0, 120);
    }
    return $lista;
}

function dh_xml_txt(DOMXPath $xp, DOMNode $no, string $tag): string
{
    $n = $xp->query('./*[local-name()="' . $tag . '"]', $no)->item(0);
    return $n ? trim($n->textContent) : '';
}

// BeerXML 1.0: kg, litros, °C, minutos; densidade em SG
function dh_receita_beerxml(DOMXPath $xp, DOMNode $r): array
{
    $t = fn(DOMNode $n, string $tag) => dh_xml_txt($xp, $n, $tag);
    $filhos = fn(string $grupo, string $item) => iterator_to_array($xp->query('./*[local-name()="' . $grupo . '"]/*[local-name()="' . $item . '"]', $r));
    $x = dh_receita_vazia();
    $x['origem'] = 'beerxml';
    $x['nome'] = $t($r, 'NAME');
    $estilo = $xp->query('./*[local-name()="STYLE"]', $r)->item(0);
    $x['estilo'] = $estilo ? $t($estilo, 'NAME') : '';
    $x['volume_l'] = dh_receita_num($t($r, 'BATCH_SIZE'));
    $x['fervura_min'] = dh_receita_num($t($r, 'BOIL_TIME'));
    $x['eficiencia'] = dh_receita_num($t($r, 'EFFICIENCY'));
    $x['og'] = dh_receita_num($t($r, 'EST_OG') ?: $t($r, 'OG'));
    $x['fg'] = dh_receita_num($t($r, 'EST_FG') ?: $t($r, 'FG'));
    $x['abv'] = dh_receita_num($t($r, 'EST_ABV') ?: $t($r, 'ABV'));
    $x['ibu'] = dh_receita_num($t($r, 'IBU') ?: $t($r, 'EST_IBU'));
    $cor = $t($r, 'EST_COLOR');
    if ($cor !== '') {
        $n = (float) dh_receita_num($cor);
        $x['cor_ebc'] = (string) round(stripos($cor, 'ebc') !== false ? $n : $n * 1.97, 1);
    }
    foreach ($filhos('FERMENTABLES', 'FERMENTABLE') as $f) {
        $x['fermentaveis'][] = ['nome' => $t($f, 'NAME'), 'kg' => dh_receita_num($t($f, 'AMOUNT')), 'tipo' => $t($f, 'TYPE')];
    }
    foreach ($filhos('HOPS', 'HOP') as $h) {
        $x['lupulos'][] = ['nome' => $t($h, 'NAME'), 'g' => (string) round((float) $t($h, 'AMOUNT') * 1000, 1), 'alfa' => dh_receita_num($t($h, 'ALPHA')),
            'tempo_min' => dh_receita_num($t($h, 'TIME')), 'uso' => $t($h, 'USE')];
    }
    foreach ($filhos('YEASTS', 'YEAST') as $y) {
        $x['leveduras'][] = ['nome' => $t($y, 'NAME'), 'lab' => $t($y, 'LABORATORY'), 'produto' => $t($y, 'PRODUCT_ID')];
    }
    foreach ($filhos('MISCS', 'MISC') as $m) {
        $peso = in_array(strtolower($t($m, 'AMOUNT_IS_WEIGHT')), ['true', '1'], true);
        $q = (float) $t($m, 'AMOUNT');
        $x['outros'][] = ['nome' => $t($m, 'NAME'), 'qtd' => (string) round($peso ? $q * 1000 : $q * 1000, 2), 'unidade' => $peso ? 'g' : 'ml', 'uso' => $t($m, 'USE')];
    }
    foreach ($filhos('WATERS', 'WATER') as $w) {
        $x['outros'][] = ['nome' => $t($w, 'NAME'), 'qtd' => dh_receita_num($t($w, 'AMOUNT')), 'unidade' => 'L', 'uso' => 'Água'];
    }
    foreach ($xp->query('./*[local-name()="MASH"]/*[local-name()="MASH_STEPS"]/*[local-name()="MASH_STEP"]', $r) as $s) {
        $x['mostura'][] = ['nome' => $t($s, 'NAME'), 'temp_c' => dh_receita_num($t($s, 'STEP_TEMP')), 'tempo_min' => dh_receita_num($t($s, 'STEP_TIME'))];
    }
    $x['notas'] = trim($t($r, 'NOTES') . "\n\n" . $t($r, 'TASTE_NOTES'));
    return $x;
}

// .bsmx (BeerSmith 2/3): pesos em onças, volumes em onças fluidas, temperaturas em °F
function dh_receita_bsmx(DOMXPath $xp, DOMNode $r): array
{
    $t = fn(DOMNode $n, string $tag) => dh_xml_txt($xp, $n, $tag);
    $todos = fn(string $tag) => iterator_to_array($xp->query('.//*[local-name()="' . $tag . '"]', $r));
    $f2c = fn(string $f) => $f === '' ? '' : (string) round(((float) $f - 32) * 5 / 9, 1);
    $x = dh_receita_vazia();
    $x['origem'] = 'bsmx';
    $x['nome'] = $t($r, 'F_R_NAME');
    $estilo = $xp->query('.//*[local-name()="F_R_STYLE"]', $r)->item(0);
    $x['estilo'] = $estilo ? $t($estilo, 'F_S_NAME') : '';
    $equip = $xp->query('.//*[local-name()="F_R_EQUIPMENT"]', $r)->item(0);
    if ($equip) {
        $vol = (float) $t($equip, 'F_E_BATCH_VOL');
        $x['volume_l'] = $vol > 0 ? (string) round($vol * 0.0295735, 1) : '';
        $x['fervura_min'] = dh_receita_num($t($equip, 'F_E_BOIL_TIME'));
        $x['eficiencia'] = dh_receita_num($t($equip, 'F_E_EFFICIENCY'));
    }
    foreach (['og' => ['F_R_DESIRED_OG', 'F_R_OG_MEASURED'], 'fg' => ['F_R_DESIRED_FG', 'F_R_FG_MEASURED']] as $k => $tags) {
        foreach ($tags as $tag) {
            $v = (float) $t($r, $tag);
            if ($v > 1.0005 && $v < 1.2) {
                $x[$k] = (string) round($v, 3);
                break;
            }
        }
    }
    $ibu = (float) $t($r, 'F_R_DESIRED_IBU');
    $x['ibu'] = $ibu > 0 ? (string) round($ibu, 1) : '';
    foreach ($todos('Grain') as $g) {
        $x['fermentaveis'][] = ['nome' => $t($g, 'F_G_NAME'), 'kg' => (string) round((float) $t($g, 'F_G_AMOUNT') / 35.274, 3), 'tipo' => ''];
    }
    $usos = ['0' => 'Boil', '1' => 'Dry Hop', '2' => 'Mash', '3' => 'First Wort', '4' => 'Aroma'];
    foreach ($todos('Hops') as $h) {
        $uso = $t($h, 'F_H_USE');
        $x['lupulos'][] = ['nome' => $t($h, 'F_H_NAME'), 'g' => (string) round((float) $t($h, 'F_H_AMOUNT') * 28.3495, 1), 'alfa' => dh_receita_num($t($h, 'F_H_ALPHA')),
            'tempo_min' => $uso === '1' ? (string) round((float) $t($h, 'F_H_DRY_HOP_TIME') * 1440) : dh_receita_num($t($h, 'F_H_BOIL_TIME')), 'uso' => $usos[$uso] ?? ''];
    }
    foreach ($todos('Yeast') as $y) {
        $x['leveduras'][] = ['nome' => $t($y, 'F_Y_NAME'), 'lab' => $t($y, 'F_Y_LAB'), 'produto' => $t($y, 'F_Y_PRODUCT_ID')];
    }
    foreach ($todos('Misc') as $m) {
        $x['outros'][] = ['nome' => $t($m, 'F_M_NAME'), 'qtd' => dh_receita_num($t($m, 'F_M_AMOUNT')), 'unidade' => '', 'uso' => ''];
    }
    foreach ($todos('MashStep') as $s) {
        $x['mostura'][] = ['nome' => $t($s, 'F_MS_NAME'), 'temp_c' => $f2c($t($s, 'F_MS_STEP_TEMP')), 'tempo_min' => dh_receita_num($t($s, 'F_MS_STEP_TIME'))];
    }
    $x['notas'] = trim($t($r, 'F_R_NOTES'));
    $x['fermentaveis'] = array_values(array_filter($x['fermentaveis'], fn($i) => $i['nome'] !== ''));
    $x['lupulos'] = array_values(array_filter($x['lupulos'], fn($i) => $i['nome'] !== ''));
    $x['leveduras'] = array_values(array_filter($x['leveduras'], fn($i) => $i['nome'] !== ''));
    return $x;
}

// Cerveja do cadastro com o nome mais parecido com o da receita ("Premium Lager v3" → "DH Premium Lager")
function dh_receita_casar_cerveja(string $nome, array $cervejas): string
{
    $norm = fn($s) => trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9 ]/', ' ', strtolower(dh_sem_acento($s)))));
    $alvo = $norm($nome);
    $melhor = '';
    $pontos = 0;
    foreach ($cervejas as $c) {
        $n = $norm($c['nome']);
        if ($n === '' || $alvo === '') {
            continue;
        }
        $p = $n === $alvo ? 100 : (str_contains($alvo, $n) || str_contains($n, $alvo) ? 80 : 0);
        if (!$p) {
            $pa = array_filter(explode(' ', $alvo), fn($w) => strlen($w) > 2 && !in_array($w, ['dh', 'the', 'ipa', 'ale'], true));
            $pc = array_filter(explode(' ', $n), fn($w) => strlen($w) > 2 && !in_array($w, ['dh', 'the', 'ipa', 'ale'], true));
            $comum = count(array_intersect($pa, $pc));
            $p = $comum ? (int) (60 * $comum / max(count($pa), count($pc))) : 0;
        }
        if ($p > $pontos) {
            [$melhor, $pontos] = [$c['id'], $p];
        }
    }
    return $pontos >= 30 ? $melhor : '';
}

// Resumo da receita que vai para o lote (cópia do momento da brassagem)
function dh_receita_copia(array $x): array
{
    return array_intersect_key($x, array_flip(['id', 'nome', 'estilo', 'volume_l', 'og', 'fg', 'abv', 'ibu', 'cor_ebc', 'fermentaveis', 'lupulos', 'leveduras', 'outros', 'mostura', 'notas', 'atualizado_em']));
}
