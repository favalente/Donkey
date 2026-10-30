<?php
// Produtos e serviços vendidos junto com o chope (copos, tirador, frete…), com preço editável no
// painel (Contratos → Produtos e preços). O pedido do cliente pergunta pelos marcados "no pedido";
// a página do Chope Delivery, as sugestões do orçamento e o contrato puxam o preço daqui.
// O preço fica gravado no contrato na hora do pedido: mudar aqui não altera contratos já gerados.

const DH_ARQ_PRODUTOS = DH_RAIZ . '/dados/produtos.json';
const DH_PRODUTOS_PADRAO = [
    ['id' => 'frete-fortaleza', 'nome' => 'Frete Fortaleza', 'detalhe' => 'instalação e retirada', 'preco' => '40.00', 'unidade' => 'evento', 'no_pedido' => false, 'fixo' => true, 'ativo' => true],
    ['id' => 'copos', 'nome' => 'Copos personalizados', 'detalhe' => 'pacote com 50 copos', 'preco' => '40.00', 'unidade' => 'pacote', 'no_pedido' => true, 'fixo' => false, 'ativo' => true],
    ['id' => 'tirador', 'nome' => 'Tirador de chope', 'detalhe' => 'profissional para servir o chope no evento', 'preco' => '150.00', 'unidade' => 'tirador', 'no_pedido' => true, 'fixo' => false, 'ativo' => true],
];

function dh_produtos(): array
{
    $l = json_decode((string) @file_get_contents(DH_ARQ_PRODUTOS), true);
    $l = is_array($l) && $l ? $l : DH_PRODUTOS_PADRAO;
    // O frete de Fortaleza sempre existe (o pedido usa o preço dele)
    if (!in_array('frete-fortaleza', array_column($l, 'id'), true)) {
        array_unshift($l, DH_PRODUTOS_PADRAO[0]);
    }
    return array_map(fn($p) => $p + ['detalhe' => '', 'unidade' => '', 'no_pedido' => false, 'fixo' => false, 'ativo' => true], $l);
}

function dh_produtos_salvar(array $l): void
{
    if (@file_put_contents(DH_ARQ_PRODUTOS, json_encode(array_values($l), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) === false) {
        throw new RuntimeException('Não foi possível gravar os produtos. Confira a permissão da pasta dados.');
    }
}

function dh_produto(string $id): ?array
{
    foreach (dh_produtos() as $p) {
        if ($p['id'] === $id) {
            return $p;
        }
    }
    return null;
}

function dh_frete_fortaleza(): float
{
    return (float) (dh_produto('frete-fortaleza')['preco'] ?? DH_FRETE_FORTALEZA);
}

// Os que o pedido do cliente pergunta ("Deseja …?")
function dh_produtos_pedido(): array
{
    return array_values(array_filter(dh_produtos(), fn($p) => $p['ativo'] && $p['no_pedido'] && !$p['fixo']));
}

// Adicionais que podem entrar num contrato (todos os ativos, menos o frete)
function dh_produtos_adicionais(): array
{
    return array_values(array_filter(dh_produtos(), fn($p) => $p['ativo'] && !$p['fixo']));
}

// Item do contrato com o preço do momento
function dh_produto_extra(array $p, int $qtd): array
{
    return ['id' => $p['id'], 'descricao' => $p['nome'], 'detalhe' => $p['detalhe'], 'valor' => number_format((float) $p['preco'], 2, '.', ''), 'unidade' => $p['unidade'], 'qtd' => $qtd];
}

// Adicionais de um contrato. Contratos antigos tinham só "copos_pacotes" (pacote com 50 a R$ 40).
function dh_comodato_extras(array $f): array
{
    $extras = array_values(array_filter((array) ($f['extras'] ?? []), fn($x) => (int) ($x['qtd'] ?? 0) > 0));
    $pc = (int) ($f['copos_pacotes'] ?? 0);
    if ($pc > 0 && !in_array('copos', array_column($extras, 'id'), true)) {
        $extras[] = ['id' => 'copos', 'descricao' => 'Copos personalizados', 'detalhe' => 'pacote com 50 copos', 'valor' => '40.00', 'unidade' => 'pacote', 'qtd' => $pc];
    }
    return $extras;
}

function dh_comodato_extras_total(array $f): float
{
    return array_sum(array_map(fn($x) => (float) $x['valor'] * (int) $x['qtd'], dh_comodato_extras($f)));
}
