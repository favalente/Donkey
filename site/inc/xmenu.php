<?php
// Tap list vinda do cardápio xMenu (Netcontroll), com autorização do suporte do xMenu.
// Lê a categoria "TAP LIST" da loja e junta com o catálogo do painel (foto, descrição).

// Unidade => código da loja no xMenu
const DH_XMENU_LOJAS = ['aldeota' => '20476', 'maraponga' => '20409'];
// Cervejas novas na tap list desta unidade são cadastradas sozinhas no catálogo do site
const DH_XMENU_AUTOCADASTRO = 'aldeota';
const DH_XMENU_GRUPO = 'TAP LIST';
const DH_XMENU_CACHE_SEG = 60;

function dh_xmenu_url(string $loja): string
{
    $q = static fn($v) => rawurlencode("'" . $v . "'");
    return 'https://adm.netcontroll.com.br/wsxmenu3/WSXMenu5.asmx/NSCarregaProdutos0203'
        . '?Parceiro=' . $q($loja) . '&Tipo=' . $q('2') . '&cliente=' . $q('') . '&idioma=' . $q('1')
        . '&versao=' . $q('5.0') . '&camp=' . $q('') . '&exclusivoCamp=' . $q('') . '&format=JSON'
        . '&callback=cb&funcaoRetorno=' . $q('cb') . '&user=' . $q('VBETONI') . '&key=' . $q('netcontroll@xmenu')
        . '&T=' . $q((string) time());
}

function dh_xmenu_baixar(string $loja): ?array
{
    $url = dh_xmenu_url($loja);
    $cabecalhos = ['Referer: https://pedido.xmenu.com.br/', 'User-Agent: DonkeyHeadSite/1.0'];
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_HTTPHEADER => $cabecalhos]);
        $corpo = curl_exec($ch);
        $ok = curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => ['timeout' => 6, 'header' => implode("\r\n", $cabecalhos)]]);
        $corpo = @file_get_contents($url, false, $ctx);
        $ok = $corpo !== false;
    }
    if (!$ok || !is_string($corpo)) {
        return null;
    }
    // Resposta vem como JSONP: cb([...]);
    $ini = strpos($corpo, '(');
    $fim = strrpos($corpo, ')');
    $lista = $ini !== false && $fim ? json_decode(substr($corpo, $ini + 1, $fim - $ini - 1), true) : null;
    return is_array($lista) && ($lista === [] || isset($lista[0])) ? $lista : null;
}

// Produtos do grupo Tap List, guardados em cache por 1 minuto (e usados como reserva se o xMenu cair)
function dh_xmenu_produtos(string $unidade): ?array
{
    $loja = DH_XMENU_LOJAS[$unidade] ?? null;
    if (!$loja) {
        return null;
    }
    $cache = DH_RAIZ . '/dados/xmenu-' . $unidade . '.json';
    $guardado = json_decode((string) @file_get_contents($cache), true);
    if (is_array($guardado) && time() - ($guardado['t'] ?? 0) < DH_XMENU_CACHE_SEG) {
        return $guardado['produtos'];
    }
    $todos = dh_xmenu_baixar($loja);
    if ($todos === null) {
        if (is_array($guardado)) {
            // Evita tentar de novo a cada visita enquanto o xMenu estiver fora
            $guardado['t'] = time();
            @file_put_contents($cache, json_encode($guardado, JSON_UNESCAPED_UNICODE), LOCK_EX);
            return $guardado['produtos'];
        }
        return null;
    }
    $produtos = array_values(array_filter($todos, fn($p) => stripos((string) ($p['NomeGrupo'] ?? ''), DH_XMENU_GRUPO) === 0));
    usort($produtos, fn($a, $b) => ($a['Ordem'] ?? 0) <=> ($b['Ordem'] ?? 0));
    $produtos = array_map(fn($p) => [
        'nome' => (string) $p['Nome'],
        'descricao' => (string) ($p['Descricao'] ?? ''),
        'preco' => (float) ($p['PrecoVenda'] ?? 0),
        'foto' => (string) ($p['httpFoto'] ?? ''),
    ], $produtos);
    if (!is_dir(dirname($cache))) {
        mkdir(dirname($cache), 0755, true);
    }
    @file_put_contents($cache, json_encode(['t' => time(), 'produtos' => $produtos], JSON_UNESCAPED_UNICODE), LOCK_EX);
    return $produtos;
}

// "STIGMAPA 300ML" -> ["STIGMAPA", "300 ml"]
function dh_xmenu_separar_tamanho(string $nome): array
{
    $nome = trim(preg_replace('/\s+/u', ' ', $nome));
    if (preg_match('/^(.*?)\s*(\d+(?:[.,]\d+)?)\s*(ml|l|litros?)$/iu', $nome, $m)) {
        $un = strtolower($m[3]) === 'ml' ? 'ml' : 'L';
        return [trim($m[1]), $m[2] . ' ' . $un];
    }
    return [$nome, ''];
}

// Acha a cerveja do catálogo pelo nome do xMenu: igual primeiro, depois a de nome mais curto que contém
function dh_xmenu_casar(string $nome, array $cervejas): ?array
{
    $alvo = dh_slug($nome);
    $melhor = null;
    foreach ($cervejas as $c) {
        $nomes = array_filter([dh_slug($c['nome']), $c['id'], dh_slug($c['nome_xmenu'] ?? '')]);
        if (in_array($alvo, $nomes, true)) {
            return $c;
        }
        foreach ($nomes as $n) {
            if (strpos('-' . $n . '-', '-' . $alvo . '-') !== false && (!$melhor || strlen($c['id']) < strlen($melhor['id']))) {
                $melhor = $c;
            }
        }
    }
    return $melhor;
}

// Tap list montada a partir do xMenu, ou null se a unidade não usa xMenu / ainda não há dados
function dh_xmenu_taplist(string $unidade, array $cervejas): ?array
{
    $produtos = dh_xmenu_produtos($unidade);
    if ($produtos === null) {
        return null;
    }
    $itens = [];
    foreach ($produtos as $p) {
        [$base, $tam] = dh_xmenu_separar_tamanho($p['nome']);
        $chave = dh_slug($base);
        if (!isset($itens[$chave])) {
            $c = dh_xmenu_casar($base, $cervejas);
            if (!$c && $unidade === DH_XMENU_AUTOCADASTRO) {
                $c = dh_xmenu_autocadastrar($base, $p);
                if ($c) {
                    $cervejas[] = $c;
                }
            }
            if (!$c) {
                // Cerveja ainda não cadastrada no painel: usa o que vier do xMenu
                $c = dh_xmenu_ficha($base, $p['descricao']) + ['id' => null, 'imagem' => 'assets/img/rotulo-padrao.png'];
            }
            $itens[$chave] = $c + ['precos' => []];
            $itens[$chave]['precos'] = [];
        }
        if ($p['preco'] > 0) {
            $itens[$chave]['precos'][] = ['tam' => $tam ?: 'dose', 'valor' => $p['preco']];
        }
    }
    return array_values($itens);
}

// Nome, estilo, ABV, IBU e descrição a partir do texto do xMenu
// ("Estilo: Catharina Sour | ABV: 4% | IBU: 3" ou um campo por linha)
function dh_xmenu_ficha(string $base, string $desc): array
{
    preg_match('/Estilo:\s*([^|\n]+)/iu', $desc, $mE);
    preg_match('/ABV:\s*([\d.,]+)/iu', $desc, $mA);
    preg_match('/IBU:\s*([\d.,]+)/iu', $desc, $mI);
    $texto = preg_replace('/^\s*(Estilo|ABV|IBU|SRM|EBC)\s*:.*$/imu', '', $desc);
    $texto = trim(preg_replace("/\n{3,}/", "\n\n", str_replace("\r", '', $texto)));
    // Nome todo em maiúsculas no xMenu vira "Primeira Letra Maiúscula"
    $nome = $base === mb_strtoupper($base, 'UTF-8') ? mb_convert_case(mb_strtolower($base, 'UTF-8'), MB_CASE_TITLE, 'UTF-8') : $base;
    return [
        'nome' => $nome,
        'estilo' => trim($mE[1] ?? ''),
        'abv' => str_replace(',', '.', $mA[1] ?? ''),
        'ibu' => $mI[1] ?? '',
        'descricao' => $texto,
    ];
}

// Baixa a foto do produto no xMenu e grava no site (JPEG reduzido). Devolve o caminho ou null.
function dh_xmenu_baixar_foto(string $url, string $id): ?string
{
    if (!preg_match('#^https://adm\.netcontroll\.com\.br/#', $url) || !function_exists('curl_init') || !function_exists('imagecreatefromstring')) {
        return null;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_HTTPHEADER => ['Referer: https://pedido.xmenu.com.br/', 'User-Agent: DonkeyHeadSite/1.0']]);
    $bin = curl_exec($ch);
    $ok = curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
    curl_close($ch);
    $img = $ok && is_string($bin) && strlen($bin) < 15000000 ? @imagecreatefromstring($bin) : false;
    if (!$img) {
        return null;
    }
    $rel = 'uploads/cervejas/' . $id . '-xmenu.jpg';
    dh_gravar_jpeg($img, DH_RAIZ . '/' . $rel, 900);
    return $rel;
}

// Cria no catálogo uma cerveja que apareceu na tap list do xMenu e ainda não existia no site
function dh_xmenu_autocadastrar(string $base, array $produto): ?array
{
    try {
        $dados = dh_carregar();
        $existente = dh_xmenu_casar($base, $dados['cervejas']);
        if ($existente) {
            return $existente;
        }
        $ficha = dh_xmenu_ficha($base, $produto['descricao']);
        $id = dh_slug($ficha['nome']);
        $porId = dh_por_id($dados);
        for ($i = 2; isset($porId[$id]); $i++) {
            $id = dh_slug($ficha['nome']) . '-' . $i;
        }
        $c = $ficha + [
            'id' => $id,
            'pais' => '',
            'nome_xmenu' => $base,
            'preco_300' => '',
            'preco_1l' => '',
            'imagem' => dh_xmenu_baixar_foto($produto['foto'] ?? '', $id) ?? 'assets/img/rotulo-padrao.png',
            'ativa' => true,
            'origem' => 'xmenu',
        ];
        $dados['cervejas'][] = $c;
        usort($dados['cervejas'], fn($a, $b) => strcasecmp(dh_slug($a['nome']), dh_slug($b['nome'])));
        dh_salvar($dados);
        return $c;
    } catch (Throwable $e) {
        return null;
    }
}
