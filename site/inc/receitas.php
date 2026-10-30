<?php
// Receitas das cervejas (Painel → Receitas): uma por cerveja do cadastro, só com o que a fábrica usa:
// insumos, pré-fervura, OG e FG (em °Brix, como no refratômetro), litragem da lavagem e, se quiser,
// o print da receita. Dá para preencher à mão ou importar do BeerSmith (BeerXML .xml ou .bsmx). A brassagem puxa a receita da cerveja
// escolhida e guarda uma cópia no lote (se a receita mudar depois, o caderno do lote não muda).
// Dados em dados/receitas.json (só no servidor), com cópia diária em dados/backup.

const DH_ARQ_RECEITAS = DH_RAIZ . '/dados/receitas.json';
const DH_RECEITA_MAX_BYTES = 5 * 1024 * 1024;
const DH_DIR_RECEITAS_IMG = DH_RAIZ . '/dados/receitas';
const DH_RECEITA_IMG_TIPOS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

function dh_receitas(): array
{
    $r = json_decode((string) @file_get_contents(DH_ARQ_RECEITAS), true);
    $r = (is_array($r) ? $r : []) + ['receitas' => []];
    $r['receitas'] = array_map('dh_receita_simplificar', $r['receitas']);
    return $r;
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
    return ['id' => '', 'cerveja_id' => '', 'nome' => '', 'volume_l' => '', 'insumos' => [], 'pre_fervura' => '', 'og' => '', 'fg' => '', 'lavagem_l' => '',
        'cor_ebc' => '', 'abv' => '', 'ibu' => '', 'levedura' => '', 'fg_refr' => false, 'brix_refr' => false, 'imagem' => '', 'origem' => 'manual', 'arquivo' => '', 'versoes' => []];
}

// Receita no formato da fábrica. A importação do BeerSmith (e receitas gravadas antes da simplificação)
// vem com fermentáveis, lúpulos etc. e OG/FG em densidade: tudo vira a lista de insumos e °Brix.
function dh_receita_simplificar(array $x): array
{
    if (!isset($x['insumos'])) {
        $ins = [];
        foreach ($x['fermentaveis'] ?? [] as $f) {
            $ins[] = ['qtd' => $f['kg'], 'unidade' => 'kg', 'nome' => $f['nome']];
        }
        foreach ($x['lupulos'] ?? [] as $h) {
            $ins[] = ['qtd' => $h['g'], 'unidade' => 'g', 'nome' => $h['nome'] . (($h['alfa'] ?? '') !== '' ? ' [' . dh_num($h['alfa']) . '%]' : '') . (($h['uso'] ?? '') !== '' ? ' – ' . dh_receita_uso_lupulo($h) : '')];
        }
        foreach ($x['leveduras'] ?? [] as $y) {
            $x['levedura'] = ($x['levedura'] ?? '') !== '' ? $x['levedura'] : $y['nome'];
            $ins[] = ['qtd' => $y['qtd'] ?? '', 'unidade' => ($y['qtd'] ?? '') !== '' ? 'pct' : '', 'nome' => trim($y['nome'] . ($y['lab'] !== '' || $y['produto'] !== '' ? ' (' . trim($y['lab'] . ' ' . $y['produto']) . ')' : ''))];
        }
        foreach ($x['outros'] ?? [] as $m) {
            $ins[] = ['qtd' => $m['qtd'], 'unidade' => $m['unidade'], 'nome' => $m['nome'] . (($m['uso'] ?? '') !== '' && $m['uso'] !== 'Água' ? ' – ' . (['boil' => 'Fervura', 'mash' => 'Mostura', 'primary' => 'Fermentação', 'secondary' => 'Maturação', 'bottling' => 'Envase'][strtolower($m['uso'])] ?? $m['uso']) : '')];
        }
        $x['insumos'] = $ins;
        // Receitas antigas tinham OG/FG em densidade; as importações novas já vêm em °Brix
        foreach (empty($x['brix']) ? ['og', 'fg'] : [] as $k) {
            $v = (float) ($x[$k] ?? 0);
            $x[$k] = $v >= 1 && $v < 1.2 ? str_replace(',', '.', (string) dh_sg_brix((string) $v)) : (string) ($x[$k] ?? '');
        }
    }
    $x = array_merge(dh_receita_vazia(), array_intersect_key($x, dh_receita_vazia()), array_intersect_key($x, array_flip(['atualizado_em', 'importado_por'])));
    // O BeerSmith dá pré-fervura e OG em °Plato; no refratômetro, °Brix = °Plato × 1,04 (fator do mosto).
    // Receitas importadas antes desta conversão: a FG tinha sido convertida com fator 1,0, volta ao °Plato real.
    if (!$x['brix_refr'] && $x['origem'] !== 'manual') {
        if ($x['fg_refr'] && $x['fg'] !== '' && $x['og'] !== '') {
            $x['fg'] = dh_brix_de_sg(dh_refratometro_sg((float) $x['og'], (float) $x['fg'], 1.0));
            $x['fg_refr'] = false;
        }
        foreach (['pre_fervura', 'og'] as $k) {
            $x[$k] = $x[$k] !== '' ? (string) round((float) $x[$k] * DH_REFRATOMETRO_FATOR, 1) : '';
        }
    }
    $x['brix_refr'] = true;
    // FG como o refratômetro lê no fim da fermentação (o álcool faz a leitura subir). A FG do BeerSmith
    // é o extrato real (°Plato): vira a leitura esperada no refratômetro, com a correção de álcool.
    if (!$x['fg_refr'] && $x['fg'] !== '' && $x['og'] !== '') {
        $x['fg'] = (string) round(dh_refratometro_leitura((float) $x['og'], dh_plato_sg((float) $x['fg'])), 1);
    }
    $x['fg_refr'] = true;
    $x['versoes'] = array_map(fn($v) => array_diff_key(dh_receita_simplificar($v), ['versoes' => 1]), $x['versoes']);
    return $x;
}

function dh_receita_uso_lupulo(array $h): string
{
    $uso = ['boil' => 'Fervura', 'dry hop' => 'Dry hop', 'mash' => 'Mostura', 'first wort' => 'First wort', 'aroma' => 'Whirlpool'][strtolower($h['uso'])] ?? $h['uso'];
    if (($h['tempo_min'] ?? '') === '') {
        return $uso;
    }
    $min = (float) $h['tempo_min'];
    return $uso . ' ' . (strtolower($h['uso']) === 'dry hop' && $min >= 1440 ? dh_num(round($min / 1440, 1)) . ' dias' : dh_num($min) . ' min');
}

// Print da receita (foto ou captura do BeerSmith), guardado fora da parte pública do site
function dh_receita_imagem_salvar(string $id, array $arq): string
{
    if (($arq['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($arq['tmp_name']) || ($arq['size'] ?? 0) > 15 * 1024 * 1024) {
        throw new RuntimeException('Não foi possível receber o print da receita (máximo 15 MB).');
    }
    $tipo = (string) (new finfo(FILEINFO_MIME_TYPE))->file($arq['tmp_name']);
    if (!isset(DH_RECEITA_IMG_TIPOS[$tipo])) {
        throw new RuntimeException('O print da receita precisa ser uma imagem JPG, PNG ou WebP.');
    }
    if (!is_dir(DH_DIR_RECEITAS_IMG) && !@mkdir(DH_DIR_RECEITAS_IMG, 0755, true)) {
        throw new RuntimeException('Não foi possível guardar o print agora. Confira a permissão da pasta dados.');
    }
    $nome = preg_replace('/[^a-z0-9]/', '', $id) . '-' . bin2hex(random_bytes(4)) . '.' . DH_RECEITA_IMG_TIPOS[$tipo];
    if (!move_uploaded_file($arq['tmp_name'], DH_DIR_RECEITAS_IMG . '/' . $nome)) {
        throw new RuntimeException('Não foi possível guardar o print agora. Tente de novo.');
    }
    return $nome;
}

function dh_receita_imagem_caminho(string $nome): ?string
{
    $f = DH_DIR_RECEITAS_IMG . '/' . basename($nome);
    return $nome !== '' && is_file($f) ? $f : null;
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
    // Caracteres de controle (o BeerSmith grava alguns, como &#1;) não são aceitos no XML
    $conteudo = preg_replace(['/&#0*([0-8]|1[124-9]|2\d|3[01]);/', '/&#x0*([0-8bcef]|1[0-9a-f]);/i', '/[\x00-\x08\x0B\x0C\x0E-\x1F]/'], '', $conteudo);
    $doc = new DOMDocument();
    $doc->recover = true;
    $antes = libxml_use_internal_errors(true);
    $ok = $doc->loadXML($conteudo, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT | LIBXML_PARSEHUGE);
    libxml_clear_errors();
    libxml_use_internal_errors($antes);
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
    return array_map(function ($x) use ($arquivo) {
        unset($x['insumos']);
        $x['arquivo'] = mb_substr($arquivo, 0, 120);
        return dh_receita_simplificar($x);
    }, $lista);
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
    // Pré-fervura: os pontos da OG espalhados no volume antes da fervura
    $ferv = (float) dh_receita_num($t($r, 'BOIL_SIZE'));
    if ($x['og'] !== '' && $ferv > 0 && (float) $x['volume_l'] > 0) {
        $x['pre_fervura'] = dh_brix_de_sg(1 + ((float) $x['og'] - 1) * (float) $x['volume_l'] / $ferv);
    }
    foreach (['og', 'fg'] as $k) {
        $x[$k] = $x[$k] !== '' ? dh_brix_de_sg((float) $x[$k]) : '';
    }
    $x['brix'] = true;
    return $x;
}

function dh_plato_sg(float $p): float
{
    return 1 + $p / (258.6 - ($p / 258.2) * 227.1);
}

// Correção de álcool do refratômetro (Sean Terrill, 2011), com o fator de correção do mosto 1,04
// (°Brix lido = °Plato × 1,04, o padrão do BeerSmith). Leituras em °Brix → densidade real (SG).
const DH_REFRATOMETRO_FATOR = 1.04;

function dh_refratometro_sg(float $ogBrix, float $leituraBrix, float $fator = DH_REFRATOMETRO_FATOR): float
{
    $o = $ogBrix / $fator;
    $f = $leituraBrix / $fator;
    return 1 - 0.0044993 * $o + 0.011774 * $f + 0.00027581 * $o ** 2 - 0.0012717 * $f ** 2 - 0.0000072800 * $o ** 3 + 0.000063293 * $f ** 3;
}

// O inverso: que leitura o refratômetro vai mostrar quando a densidade real chegar a $sg
function dh_refratometro_leitura(float $ogBrix, float $sg): float
{
    [$a, $b] = [0.0, max($ogBrix, 1.0)];
    for ($n = 0; $n < 50; $n++) {
        $m = ($a + $b) / 2;
        if (dh_refratometro_sg($ogBrix, $m) < $sg) {
            $a = $m;
        } else {
            $b = $m;
        }
    }
    return ($a + $b) / 2;
}

// OG em °Brix (refratômetro, mosto sem álcool) → densidade
function dh_refratometro_og_sg(float $ogBrix): float
{
    return dh_plato_sg($ogBrix / DH_REFRATOMETRO_FATOR);
}

function dh_brix_de_sg(float $sg): string
{
    return $sg > 1 && $sg < 1.2 ? (string) round(-616.868 + 1111.14 * $sg - 630.272 * $sg ** 2 + 135.997 * $sg ** 3, 1) : '';
}

// .bsmx (BeerSmith 2/3): pesos em onças, volumes em onças fluidas, temperaturas em °F. O arquivo guarda
// os ingredientes e o equipamento; OG, pré-fervura, FG, ABV e cor o BeerSmith calcula na tela, e aqui
// são calculados do mesmo jeito (o IBU de cada lúpulo vem pronto no arquivo). °Brix ≈ °Plato no mosto.
function dh_receita_bsmx(DOMXPath $xp, DOMNode $r): array
{
    $t = fn(DOMNode $n, string $tag) => dh_xml_txt($xp, $n, $tag);
    $num = fn(DOMNode $n, string $tag) => (float) str_replace(',', '.', $t($n, $tag));
    $ing = fn(string $tag) => iterator_to_array($xp->query('./*[local-name()="Ingredients"]//*[local-name()="' . $tag . '"]', $r));
    $L = 0.0295735;
    $x = dh_receita_vazia();
    unset($x['insumos']);
    $x['origem'] = 'bsmx';
    $x['brix'] = true;
    $x['nome'] = $t($r, 'F_R_NAME');
    $equip = $xp->query('./*[local-name()="F_R_EQUIPMENT"]', $r)->item(0);
    $lote = $equip ? $num($equip, 'F_E_BATCH_VOL') * $L : 0;
    $ferv = $equip ? $num($equip, 'F_E_BOIL_VOL') * $L : 0;
    $trub = $equip ? $num($equip, 'F_E_TRUB_LOSS') * $L : 0;
    $efic = $equip ? $num($equip, 'F_E_EFFICIENCY') / 100 : 0.75;
    $x['volume_l'] = $lote > 0 ? (string) round($lote, 1) : '';
    // Grãos: pontos de densidade (lb × rendimento × 46,214 × eficiência; açúcar e extrato não passam pela mostura) e cor (Morey)
    $pontos = $pontosDepois = $mcu = $kgGraos = 0;
    foreach ($ing('Grain') as $g) {
        $lb = $num($g, 'F_G_AMOUNT') / 16;
        $tipo = (int) $num($g, 'F_G_TYPE');
        $p = $lb * $num($g, 'F_G_YIELD') / 100 * 46.214 * (in_array($tipo, [1, 2, 4], true) ? 1 : $efic);
        if ($num($g, 'F_G_ADD_AFTER_BOIL') > 0) {
            $pontosDepois += $p;
        } else {
            $pontos += $p;
        }
        $mcu += $lb * $num($g, 'F_G_COLOR');
        if (!in_array($tipo, [1, 2, 4], true)) {
            $kgGraos += $lb * 0.453592;
        }
        $x['fermentaveis'][] = ['nome' => $t($g, 'F_G_NAME'), 'kg' => (string) round($lb * 0.453592, 2), 'tipo' => ''];
    }
    $usos = ['0' => 'Boil', '1' => 'Dry Hop', '2' => 'Mash', '3' => 'First Wort', '4' => 'Aroma'];
    $ibu = 0;
    foreach ($ing('Hops') as $h) {
        $uso = (string) (int) $num($h, 'F_H_USE');
        $ibu += $num($h, 'F_H_IBU_CONTRIB');
        $x['lupulos'][] = ['nome' => $t($h, 'F_H_NAME'), 'g' => (string) round($num($h, 'F_H_AMOUNT') * 28.3495, 1), 'alfa' => dh_receita_num($t($h, 'F_H_ALPHA')),
            'tempo_min' => $uso === '1' ? (string) round($num($h, 'F_H_DRY_HOP_TIME') * 1440) : dh_receita_num($t($h, 'F_H_BOIL_TIME')), 'uso' => $usos[$uso] ?? ''];
    }
    $aten = 0;
    foreach ($ing('Yeast') as $y) {
        $x['leveduras'][] = ['nome' => $t($y, 'F_Y_NAME'), 'lab' => $t($y, 'F_Y_LAB'), 'produto' => $t($y, 'F_Y_PRODUCT_ID'), 'qtd' => dh_receita_num($t($y, 'F_Y_AMOUNT'))];
        $aten = $aten ?: ($num($y, 'F_Y_MIN_ATTENUATION') + $num($y, 'F_Y_MAX_ATTENUATION')) / 200;
    }
    $agua = 0;
    foreach ($ing('Water') as $w) {
        $agua += $num($w, 'F_W_AMOUNT') * $L;
        $x['outros'][] = ['nome' => $t($w, 'F_W_NAME'), 'qtd' => (string) round($num($w, 'F_W_AMOUNT') * $L, 1), 'unidade' => 'L', 'uso' => 'Água'];
    }
    foreach ($ing('Misc') as $m) {
        $x['outros'][] = ['nome' => $t($m, 'F_M_NAME'), 'qtd' => dh_receita_num($t($m, 'F_M_AMOUNT')), 'unidade' => '', 'uso' => ''];
    }
    // Volumes do BeerSmith: o mosto rende o lote + a perda de trub; antes da fervura está no volume de pré-fervura
    $volFinal = ($lote + $trub) / 3.78541;
    if ($volFinal > 0 && $pontos + $pontosDepois > 0) {
        $og = 1 + ($pontos + $pontosDepois) / $volFinal / 1000;
        $x['og'] = dh_brix_de_sg($og);
        if ($ferv > 0) {
            $x['pre_fervura'] = dh_brix_de_sg(1 + $pontos / ($ferv / 3.78541) / 1000);
        }
        if ($aten > 0) {
            $fg = 1 + ($og - 1) * (1 - $aten);
            $x['fg'] = dh_brix_de_sg($fg);
            $x['abv'] = (string) round(($og - $fg) * 131.25, 1);
        }
        $x['cor_ebc'] = (string) round(1.4922 * (($mcu / ($lote / 3.78541)) ** 0.6859) * 1.97, 1);
    }
    $x['ibu'] = $ibu > 0 ? (string) round($ibu, 1) : '';
    // Lavagem: o que falta para chegar ao volume de pré-fervura depois da água da mostura (o grão segura ~1 L/kg)
    $mostura = 0;
    foreach ($xp->query('./*[local-name()="F_R_MASH"]//*[local-name()="MashStep"]', $r) as $ms) {
        if ((int) $num($ms, 'F_MS_TYPE') === 0) {
            $mostura += $num($ms, 'F_MS_INFUSION') * $L;
        }
    }
    $mostura = $mostura ?: $agua;
    // Se a lavagem estiver anotada no nome da água ("Agua (lavagem 37L)"), vale a anotação
    foreach ($x['outros'] ?? [] as $o) {
        if (preg_match('/lavagem\D{0,3}(\d+(?:[.,]\d+)?)\s*l/i', $o['nome'], $m)) {
            $x['lavagem_l'] = str_replace(',', '.', $m[1]);
        }
    }
    if ($x['lavagem_l'] === '' && $ferv > 0 && $mostura > 0) {
        $lav = $ferv - ($mostura - $kgGraos);
        $x['lavagem_l'] = $lav > 0 ? (string) round($lav) : '0';
    }
    foreach (['fermentaveis', 'lupulos', 'leveduras'] as $k) {
        $x[$k] = array_values(array_filter($x[$k] ?? [], fn($i) => $i['nome'] !== ''));
    }
    return $x;
}

// Cerveja do cadastro com o nome mais parecido com o da receita ("Premium Lager v3" → "DH Premium Lager")
function dh_receita_casar_cerveja(string $nome, array $cervejas): string
{
    // "Galo Campina 420L 2026" → "galo campina" (tira litragem, eficiência e ano)
    $norm = fn($s) => trim(preg_replace('/\s+/', ' ', preg_replace('/\b(\d+(l|kg)?|\d+ ?%|dh|c|com|sem|e|de|da|do)\b/', ' ', preg_replace('/[^a-z0-9% ]/', ' ', strtolower(dh_sem_acento($s))))));
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

// Cópia da receita que vai para o lote (a do dia da brassagem)
function dh_receita_copia(array $x): array
{
    return array_diff_key(dh_receita_simplificar($x), array_flip(['versoes', 'origem', 'arquivo']));
}
