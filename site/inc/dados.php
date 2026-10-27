<?php
// Leitura e gravação do catálogo de cervejas e das torneiras.
// Os dados ficam em dados/cervejas.json (criado pelo admin). Enquanto ele não existe,
// o site usa dados-iniciais/cervejas.json, que veio do site antigo.

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
    if (preg_match('/ipa|apa|pale ale/u', $e)) {
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
            'nome' => $c['nome'],
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
