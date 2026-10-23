<?php
// Leitura e gravação do catálogo de cervejas e das torneiras.
// Os dados ficam em dados/cervejas.json (criado pelo admin). Enquanto ele não existe,
// o site usa dados-iniciais/cervejas.json, que veio do site antigo.

const DH_RAIZ = __DIR__ . '/..';
const DH_ARQ_DADOS = DH_RAIZ . '/dados/cervejas.json';
const DH_ARQ_INICIAL = DH_RAIZ . '/dados-iniciais/cervejas.json';
const DH_UNIDADES = ['aldeota' => 'Aldeota', 'maraponga' => 'Maraponga'];
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
