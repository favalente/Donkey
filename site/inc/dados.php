<?php
// Leitura e gravação do catálogo de cervejas e das torneiras.
// Os dados ficam em dados/cervejas.json (criado pelo admin). Enquanto ele não existe,
// o site usa dados-iniciais/cervejas.json, que veio do site antigo.

date_default_timezone_set('America/Fortaleza');

const DH_RAIZ = __DIR__ . '/..';
require_once __DIR__ . '/xmenu.php';
const DH_ARQ_DADOS = DH_RAIZ . '/dados/cervejas.json';
const DH_ARQ_INICIAL = DH_RAIZ . '/dados-iniciais/cervejas.json';
const DH_ARQ_GALERIA = DH_RAIZ . '/dados/galeria.json';
const DH_ARQ_GALERIA_INICIAL = DH_RAIZ . '/dados-iniciais/galeria.json';
const DH_UNIDADES = ['aldeota' => 'Aldeota', 'maraponga' => 'Maraponga'];
// Grupos da tap list na TV, da cerveja mais leve para a mais forte
const DH_CATEGORIAS = [1 => 'Leves e refrescantes', 2 => 'Maltadas e equilibradas', 3 => 'Lupuladas', 4 => 'Fortes e intensas'];
const DH_PAISES = ['Alemanha', 'Bélgica', 'Brasil', 'EUA', 'Inglaterra', 'Irlanda', 'República Tcheca', 'Outro'];

function dh_carregar(): array
{
    $arq = is_file(DH_ARQ_DADOS) ? DH_ARQ_DADOS : DH_ARQ_INICIAL;
    $dados = json_decode((string) @file_get_contents($arq), true);
    if (!is_array($dados)) {
        $dados = [];
    }
    $dados += ['cervejas' => [], 'torneiras' => [], 'atualizado' => null];
    foreach (DH_UNIDADES as $u => $_) {
        $dados['torneiras'][$u] = array_values($dados['torneiras'][$u] ?? []);
    }
    if ($arq === DH_ARQ_DADOS && empty($dados['migracoes']['cervejas_novas_2026'])) {
        $dados = dh_cervejas_novas_2026($dados);
    }
    if ($arq === DH_ARQ_DADOS && empty($dados['migracoes']['variacoes_2026'])) {
        $dados = dh_cervejas_variacoes_2026($dados);
    }
    if ($arq === DH_ARQ_DADOS && empty($dados['migracoes']['jegue_brown_ibu_20'])) {
        // IBU da Jegue Brown é 20, como no rótulo
        foreach ($dados['cervejas'] as $i => $c) {
            if (dh_slug((string) $c['nome']) === 'jegue-brown') {
                $dados['cervejas'][$i]['ibu'] = '20';
            }
        }
        $dados['migracoes']['jegue_brown_ibu_20'] = date('c');
        try {
            dh_salvar($dados);
        } catch (RuntimeException $e) {
            // tenta de novo na próxima leitura
        }
    }
    if ($arq === DH_ARQ_DADOS && empty($dados['migracoes']['preco_1l_aldeota_2026'])) {
        // "Preço 1 litro" vazio recebe o preço da Aldeota da planilha de produtos (base do chope delivery)
        foreach ($dados['cervejas'] as $i => $c) {
            if (!is_numeric($c['preco_1l'] ?? '') || (float) $c['preco_1l'] <= 0) {
                $v = dh_preco_planilha_aldeota((string) $c['nome']) ?? dh_preco_planilha_aldeota((string) ($c['nome_xmenu'] ?? ''));
                if ($v) {
                    $dados['cervejas'][$i]['preco_1l'] = number_format($v, 2, '.', '');
                }
            }
        }
        $dados['migracoes']['preco_1l_aldeota_2026'] = date('c');
        try {
            dh_salvar($dados);
        } catch (RuntimeException $e) {
            // tenta de novo na próxima leitura
        }
    }
    return $dados;
}

// Variações enviadas em out/2026 (uma vez só): Peste Negra Toffee e Carvalho (mesmo rótulo da Peste
// Negra) e Severina Sour Manga, Graviola e Abacaxi com Hortelã (a Graviola não está no xMenu).
function dh_cervejas_variacoes_2026(array $dados): array
{
    $peste = fn(string $extra, string $notas) => "Escura, intensamente saborosa, forte, orientada ao sabor do malte tostado. Em sua receita está presente aveia, melaço e $extra. O conjunto remete a notas de café, chocolate, frutas secas e $notas. Apesar da sua potência, o álcool está muito bem inserido. Na receita utilizamos maltes importados de dupla tosta para oferecer uma maior complexidade.";
    $severina = fn(string $fruta) => "Versão com $fruta.\n\nBerliner Weiss é uma cerveja de trigo originalmente alemã, ácida, clara, refrescante e de baixo teor alcoólico. Possui acidez limpa e moderada, além de alta carbonatação para dar um efeito frisante. O nome Severina Sour faz alusão à Catharina Sour, uma Berliner que utiliza frutas da região sul do Brasil, já a Severina Sour é uma série que engloba frutas tropicais da região nordeste.";
    $basePeste = ['estilo' => 'Imperial Stout', 'pais' => 'Inglaterra', 'categoria' => 4, 'abv' => '12.0', 'ibu' => '100', 'preco_1l' => '62.00', 'imagem' => 'assets/img/cervejas/peste_negra.jpg'];
    $baseSev = ['estilo' => 'Catharina Sour', 'pais' => 'Brasil', 'categoria' => 1, 'abv' => '4', 'ibu' => '3', 'preco_1l' => '40.00'];
    $novas = [
        ['id' => 'peste-negra-toffee', 'nome' => 'Peste Negra Toffee', 'nome_xmenu' => 'PESTE NEGRA TOFFEE', 'descricao' => $peste('toffee', 'toffee')] + $basePeste,
        ['id' => 'peste-negra-carvalho', 'nome' => 'Peste Negra Carvalho', 'nome_xmenu' => 'PESTE NEGRA CARVALHO', 'descricao' => $peste('maturação com carvalho', 'madeira')] + $basePeste,
        ['id' => 'severina-sour-manga', 'nome' => 'Severina Sour (Manga)', 'nome_xmenu' => 'SEVERINA SOUR (MANGA)', 'imagem' => 'assets/img/cervejas/severina-sour-manga.jpg', 'descricao' => $severina('manga')] + $baseSev,
        ['id' => 'severina-sour-graviola', 'nome' => 'Severina Sour (Graviola)', 'nome_xmenu' => 'SEVERINA SOUR (GRAVIOLA)', 'imagem' => 'assets/img/cervejas/severina-sour-graviola.jpg', 'descricao' => $severina('graviola')] + $baseSev,
        ['id' => 'severina-sour-abacaxi-com-hortela', 'nome' => 'Severina Sour (Abacaxi com Hortelã)', 'nome_xmenu' => 'SEVERINA SOUR (ABACAXI COM HORTELÃ)', 'imagem' => 'assets/img/cervejas/severina-sour-abacaxi.jpg', 'descricao' => $severina('abacaxi com hortelã')] + $baseSev,
    ];
    foreach ($novas as $n) {
        $achou = false;
        foreach ($dados['cervejas'] as $i => $c) {
            if ($c['id'] === $n['id'] || dh_slug((string) $c['nome']) === dh_slug($n['nome']) || dh_slug((string) ($c['nome_xmenu'] ?? '')) === dh_slug($n['nome'])) {
                $dados['cervejas'][$i] = array_merge($c, $n, ['id' => $c['id'], 'ativa' => true]);
                $achou = true;
            }
        }
        if (!$achou) {
            $dados['cervejas'][] = $n + ['preco_300' => '', 'ativa' => true];
        }
    }
    usort($dados['cervejas'], fn($a, $b) => strcasecmp(dh_slug($a['nome']), dh_slug($b['nome'])));
    $dados['migracoes']['variacoes_2026'] = date('c');
    try {
        dh_salvar($dados);
    } catch (RuntimeException $e) {
        // tenta de novo na próxima leitura
    }
    return $dados;
}

// Cervejas novas enviadas em out/2026 (uma vez só). Se o xMenu já tiver cadastrado alguma sozinho,
// a ficha dela é completada em vez de criar outra.
function dh_cervejas_novas_2026(array $dados): array
{
    $novas = [
        ['id' => 'cacau-brownieipa', 'nome' => 'Cacau BrownieIPA', 'estilo' => 'Brown IPA', 'pais' => 'EUA', 'categoria' => 3, 'abv' => '6.0', 'ibu' => '65',
            'nome_xmenu' => 'CACAU BROWNIEIPA', 'imagem' => 'assets/img/cervejas/cacau-brownieipa.jpg',
            'descricao' => "Uma American Brown IPA com chocolate. Traz coloração marrom escura, corpo médio e final seco. No aroma, notas intensas de malte tostado, avelã, cacau e caramelo. O amargor elevado une-se ao do cacau, trazendo equilíbrio e complexidade.\n\nA combinação de lúpulos americanos de amargor, tosta de diferentes maltes e o toque de chocolate, traz um sabor especial e único para esta cerveja. Lúpulos americanos de perfil resinoso e cítrico complementam a experiência. Ideal para quem busca intensidade, amargor.\n\nÉ doce? Não! É Nutella? Jamais! É IPA? Demais!\n\nEsta é uma IPA de cor marrom com um toque de chocolate, possui um potente amargor, você deverá sentir uma mescla do amargor do cacau com o dos lúpulos. Traz também notas de avelã e caramelo."],
        ['id' => 'santa-stout', 'nome' => 'Santa Stout', 'estilo' => 'Imperial Stout', 'pais' => 'Inglaterra', 'categoria' => 4, 'abv' => '12.0', 'ibu' => '100',
            'nome_xmenu' => 'SANTA STOUT', 'imagem' => 'assets/img/rotulo-padrao.png',
            'descricao' => "Imperial Stout com avelã e panetone.\n\nCriada especialmente para o período natalino, a Santa Stout é uma cerveja de lote super limitado e exclusivo, perfeita para celebrar em grande estilo. Com uma coloração escura e um perfil intensamente saboroso, ela combina potência e sofisticação em cada gole.\n\nSua composição alia avelã e panetone, transformando-a em uma verdadeira sobremesa líquida. O conjunto revela notas marcantes de café, chocolate, avelã e, claro, panetone, transportando você diretamente para o clima de Natal.\n\nPara alcançar uma complexidade única, utilizamos maltes importados de dupla tosta, garantindo uma profundidade de sabores que surpreende a cada degustação. A Santa Stout é robusta, intensa e inesquecível — um verdadeiro presente para os amantes de cervejas artesanais."],
        ['id' => 'maracutaia', 'nome' => 'Maracutaia', 'estilo' => 'Catharina Sour (com maracujá e pitaia)', 'pais' => 'Brasil', 'categoria' => 1, 'abv' => '4.5', 'ibu' => '4',
            'nome_xmenu' => 'MARACUTAIA', 'imagem' => 'assets/img/cervejas/maracutaia.jpg',
            'descricao' => "Tão boa que se fosse licitação você não conseguiria pagar por ela. Ela tá tão linda que já ganhou um cargo comissionado 😍\n\nUma Sour Ale com adição de maracujá e pitaia, adições estas feitas na maturação para dar maior sensação de fruta bem fresca, um verdadeiro suquinho 🤤\n\nTrouxemos pra você uma cerveja refrescante com acidez média e limpa, a alta carbonatação completa o conjunto perfeito para o nosso clima.\n\nEm uma explicação simplificada poderíamos dizer que a Catharina Sour é como se fosse o estilo alemão Berliner Weiss um pouco mais forte e com adições de frutas frescas na maturação."],
        ['id' => 'jegue-brown', 'nome' => 'Jegue Brown', 'estilo' => 'English Brown Ale', 'pais' => 'Inglaterra', 'categoria' => 2, 'abv' => '4.5', 'ibu' => '20',
            'nome_xmenu' => 'JEGUE BROWN', 'imagem' => 'assets/img/cervejas/jegue-brown.jpg',
            'descricao' => "Uma English Brown Ale que prioriza o sabor do malte, trazendo notas principais de caramelo, pão tostado e quem sabe um pouco de café e rapadura, o amargor é baixo. Com 4,5% de teor alcoólico, ela é leve, fácil de beber (mesmo sendo escura). Ela lembra o aconchego de uma mesa de café do sertão."],
        ['id' => 'azedinha', 'nome' => 'Azedinha', 'estilo' => 'Sour de Melancia', 'pais' => 'Alemanha', 'categoria' => 1, 'abv' => '3.5', 'ibu' => '5',
            'nome_xmenu' => 'AZEDINHA (SOUR DE MELANCIA)', 'imagem' => 'assets/img/cervejas/azedinha.jpg',
            'descricao' => "Lembra de um bombom antigo chamado azedinho? Dentre seus vários sabores, ele tinha um de melancia, a ideia desta cerveja é trazer à memória aquele sabor de infância.\n\nEsta cerveja tem como base o estilo Berliner Weiss, uma cerveja de trigo originalmente alemã, ácida, clara, refrescante e de baixíssimo teor alcoólico. Isso a deixa muuuuuito refrescante e fácil de beber, pode tomar muitas sem medo que você não vai se embriagar hahahaah."],
        ['id' => 'dragao-do-mar', 'nome' => 'Dragão do Mar', 'estilo' => 'Gose com Goiaba e Pitaia', 'pais' => 'Alemanha', 'categoria' => 1, 'abv' => '4.0', 'ibu' => '5',
            'nome_xmenu' => 'DRAGÃO DO MAR', 'imagem' => 'assets/img/cervejas/dragao-do-mar.jpg',
            'descricao' => "Gose com goiaba e pitaia, em versão low carb."],
    ];
    $chave = fn(string $n) => dh_slug(preg_replace('/\s*[\[(].*$/u', '', $n));
    foreach ($novas as $n) {
        $achou = false;
        foreach ($dados['cervejas'] as $i => $c) {
            if ($c['id'] === $n['id'] || $chave((string) $c['nome']) === $n['id'] || $chave((string) ($c['nome_xmenu'] ?? '')) === $n['id']) {
                $dados['cervejas'][$i] = array_merge($c, $n, ['id' => $c['id'], 'ativa' => true]);
                $achou = true;
            }
        }
        if (!$achou) {
            $dados['cervejas'][] = $n + ['preco_300' => '', 'preco_1l' => '', 'ativa' => true];
        }
    }
    // A Brut IPA do xMenu é a Brut de Sauvin do catálogo
    foreach ($dados['cervejas'] as $i => $c) {
        if (dh_slug((string) $c['nome']) === 'brut-de-sauvin' && empty($c['nome_xmenu'])) {
            $dados['cervejas'][$i]['nome_xmenu'] = 'BRUT IPA';
        }
    }
    usort($dados['cervejas'], fn($a, $b) => strcasecmp(dh_slug($a['nome']), dh_slug($b['nome'])));
    $dados['migracoes']['cervejas_novas_2026'] = date('c');
    try {
        dh_salvar($dados);
    } catch (RuntimeException $e) {
        // Sem permissão de gravar agora: tenta de novo na próxima leitura
    }
    return $dados;
}

function dh_salvar(array $dados): void
{
    $dir = dirname(DH_ARQ_DADOS);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $dados['atualizado'] = date('c');
    $json = json_encode($dados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $tmp = DH_ARQ_DADOS . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) === false || !rename($tmp, DH_ARQ_DADOS)) {
        @unlink($tmp);
        throw new RuntimeException('Não foi possível gravar os dados. Verifique a permissão da pasta "dados".');
    }
    // Cópia de segurança diária (mantém as 30 mais recentes)
    $bkp = $dir . '/backup';
    if (!is_dir($bkp)) {
        mkdir($bkp, 0755, true);
    }
    @copy(DH_ARQ_DADOS, $bkp . '/cervejas-' . date('Y-m-d') . '.json');
    $antigos = glob($bkp . '/cervejas-*.json') ?: [];
    sort($antigos);
    foreach (array_slice($antigos, 0, max(0, count($antigos) - 30)) as $velho) {
        @unlink($velho);
    }
}

function dh_por_id(array $dados): array
{
    $mapa = [];
    foreach ($dados['cervejas'] as $c) {
        $mapa[$c['id']] = $c;
    }
    return $mapa;
}

function dh_slug(string $texto): string
{
    $t = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto) ?: $texto;
    $t = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $t));
    return trim($t, '-') ?: 'cerveja';
}

function e($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

// Número no formato brasileiro (5.4 -> 5,4)
function dh_num($v): string
{
    return str_replace('.', ',', (string) $v);
}

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

// Preço no formato R$ 16,00 (vazio se não informado)
function dh_preco($v): string
{
    return is_numeric($v) ? 'R$ ' . number_format((float) $v, 2, ',', '.') : '';
}

// Torneiras de uma unidade: do xMenu quando a unidade está ligada a ele, senão do painel.
// Cada item é a cerveja do catálogo (ou dados do xMenu, se não cadastrada) com "precos".
function dh_taplist(array $dados, string $unidade): array
{
    $xmenu = dh_xmenu_taplist($unidade, $dados['cervejas']);
    if ($xmenu !== null) {
        return $xmenu;
    }
    $porId = dh_por_id($dados);
    $lista = [];
    foreach ($dados['torneiras'][$unidade] ?? [] as $id) {
        if (!isset($porId[$id])) {
            continue;
        }
        $c = $porId[$id];
        $c['precos'] = [];
        foreach (['preco_300' => '300 ml', 'preco_1l' => '1 L'] as $k => $tam) {
            if (is_numeric($c[$k] ?? '')) {
                $c['precos'][] = ['tam' => $tam, 'valor' => (float) $c[$k]];
            }
        }
        $lista[] = $c;
    }
    return $lista;
}

function dh_usa_xmenu(string $unidade): bool
{
    return isset(DH_XMENU_LOJAS[$unidade]);
}

// Grupo da cerveja na TV: o escolhido no painel ou, se for "automático", deduzido do estilo
function dh_categoria(array $c): int
{
    $cat = (int) ($c['categoria'] ?? 0);
    if (isset(DH_CATEGORIAS[$cat])) {
        return $cat;
    }
    $e = mb_strtolower(($c['estilo'] ?? '') . ' ' . ($c['nome'] ?? ''), 'UTF-8');
    if (preg_match('/session/u', $e)) {
        return 2;
    }
    if (preg_match('/stout|porter|imperial|tripel|dubbel|quadrupel|strong|barley|wee heavy|doppelbock/u', $e)) {
        return 4;
    }
    // American Blonde (ex.: Miolo de Pote) leva lúpulo americano: entra nas lupuladas
    if (preg_match('/ipa|apa|pale ale|american blonde/u', $e)) {
        return 3;
    }
    if (preg_match('/lager|pils|helles|weiss|weizen|wheat|wit|sour|berliner|gose|golden|k[oö]lsch|fruit/u', $e)) {
        return 1;
    }
    return 2;
}

// Torneiras de uma unidade, no formato usado pela tela da TV: agrupadas da mais leve para a mais forte
function dh_tap_tv(string $unidade): array
{
    if (!isset(DH_UNIDADES[$unidade])) {
        $unidade = array_key_first(DH_UNIDADES);
    }
    $taps = [];
    foreach (dh_taplist(dh_carregar(), $unidade) as $n => $c) {
        $p300 = null;
        foreach ($c['precos'] as $p) {
            if (stripos($p['tam'], '300') === 0) {
                $p300 = $p['valor'];
            }
        }
        $taps[] = [
            'n' => $n + 1,
            'cat' => dh_categoria($c),
            // Na TV vale o nome curto do xMenu, quando a cerveja tiver um
            'nome' => trim($c['nome_xmenu'] ?? '') !== '' ? $c['nome_xmenu'] : $c['nome'],
            'estilo' => $c['estilo'],
            'abv' => $c['abv'] !== '' ? dh_num($c['abv']) . '%' : '',
            'ibu' => $c['ibu'] !== '' ? $c['ibu'] . ' IBU' : '',
            'ibuNum' => (string) $c['ibu'],
            'p300' => dh_preco($p300 ?? ($c['precos'][0]['valor'] ?? '')),
            'precos' => array_map(fn($p) => ['tam' => strtoupper($p['tam']), 'valor' => dh_preco($p['valor'])], array_slice($c['precos'], 0, 2)),
            'img' => '../' . $c['imagem'],
        ];
    }
    // Ordena por grupo, mantendo a ordem do xMenu/painel dentro de cada grupo
    usort($taps, fn($a, $b) => [$a['cat'], $a['n']] <=> [$b['cat'], $b['n']]);
    return ['unidade' => DH_UNIDADES[$unidade], 'grupos' => DH_CATEGORIAS, 'taps' => $taps];
}

function dh_galeria(): array
{
    $arq = is_file(DH_ARQ_GALERIA) ? DH_ARQ_GALERIA : DH_ARQ_GALERIA_INICIAL;
    $g = json_decode((string) @file_get_contents($arq), true);
    return is_array($g['fotos'] ?? null) ? $g['fotos'] : [];
}

function dh_galeria_salvar(array $fotos): void
{
    $dir = dirname(DH_ARQ_GALERIA);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $json = json_encode(['fotos' => array_values($fotos)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $tmp = DH_ARQ_GALERIA . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) === false || !rename($tmp, DH_ARQ_GALERIA)) {
        @unlink($tmp);
        throw new RuntimeException('Não foi possível gravar a galeria. Verifique a permissão da pasta "dados".');
    }
}

// Grava a imagem em JPEG com no máximo $max px no maior lado (regravar remove qualquer conteúdo estranho)
function dh_gravar_jpeg($img, string $destino, int $max, int $qualidade = 82): void
{
    if (!is_dir(dirname($destino))) {
        mkdir(dirname($destino), 0755, true);
    }
    $w = imagesx($img);
    $h = imagesy($img);
    $escala = min(1, $max / max($w, $h));
    $nw = (int) round($w * $escala);
    $nh = (int) round($h * $escala);
    $nova = imagecreatetruecolor($nw, $nh);
    imagefill($nova, 0, 0, imagecolorallocate($nova, 255, 255, 255));
    imagecopyresampled($nova, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagejpeg($nova, $destino, $qualidade);
}
require_once __DIR__ . '/agenda.php';
require_once __DIR__ . '/contratos.php';
require_once __DIR__ . '/clientes.php';
require_once __DIR__ . '/vendas.php';
