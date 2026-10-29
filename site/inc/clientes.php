<?php
// Base de clientes: alimentada pelos contratos (pedido de chope, evento e comodato).
// Serve para puxar os dados no próximo pedido e, para quem autorizou, para prospecção (LGPD:
// o envio de novidades depende do consentimento, que fica registrado com data e pode ser retirado).
// Fica em dados/clientes.json (bloqueado na web), chaveado pelo CPF/CNPJ (só números).

const DH_ARQ_CLIENTES = DH_RAIZ . '/dados/clientes.json';

function dh_clientes(): array
{
    $c = json_decode((string) @file_get_contents(DH_ARQ_CLIENTES), true);
    return is_array($c) ? $c : [];
}

function dh_clientes_salvar(array $c): void
{
    $dir = dirname(DH_ARQ_CLIENTES);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    uasort($c, fn($a, $b) => strcoll(dh_slug($a['nome']), dh_slug($b['nome'])));
    $json = json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $tmp = DH_ARQ_CLIENTES . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) === false || !rename($tmp, DH_ARQ_CLIENTES)) {
        @unlink($tmp);
        throw new RuntimeException('Não foi possível gravar a base de clientes. Verifique a permissão da pasta "dados".');
    }
}

// Registra ou atualiza o cliente com os dados mais recentes do contrato.
// $novidades: true = autorizou receber novidades; false = recusou/retirou; null = não perguntado (mantém).
function dh_cliente_registrar(array $k, ?bool $novidades = null): void
{
    $f = $k['campos'];
    $doc = dh_so_digitos($f['contratante_doc'] ?? '');
    if ($doc === '' || (!dh_cpf_valido($doc) && !dh_cnpj_valido($doc))) {
        return;
    }
    try {
        $todos = dh_clientes();
        $cl = $todos[$doc] ?? ['doc' => $doc, 'criado_em' => date('Y-m-d H:i:s'), 'contratos' => [], 'novidades' => false, 'novidades_em' => ''];
        $cl['nome'] = $f['contratante_nome'];
        $cl['telefone'] = $f['contratante_telefone'];
        $cl['email'] = $f['contratante_email'];
        $endereco = trim($f['entrega_endereco'] ?? '') !== '' ? $f['entrega_endereco'] : ($f['contratante_endereco'] ?? '');
        if (trim($endereco) !== '') {
            $cl['endereco'] = $endereco;
        }
        if (trim($f['cidade'] ?? '') !== '') {
            $cl['cidade'] = $f['cidade'];
        }
        if (!in_array($k['id'], $cl['contratos'], true)) {
            $cl['contratos'][] = $k['id'];
        }
        if ($novidades !== null && $novidades !== $cl['novidades']) {
            $cl['novidades'] = $novidades;
            $cl['novidades_em'] = date('Y-m-d H:i:s');
        }
        $cl['atualizado_em'] = date('Y-m-d H:i:s');
        $todos[$doc] = $cl;
        dh_clientes_salvar($todos);
    } catch (RuntimeException $e) {
        // A base de clientes não pode impedir o contrato
    }
}

// Resumo de compras do cliente a partir dos contratos dele
function dh_cliente_resumo(array $cl, array $contratos): array
{
    $porId = array_column($contratos, null, 'id');
    $r = ['qtd' => 0, 'assinados' => 0, 'total' => 0.0, 'ultimo' => ''];
    foreach ($cl['contratos'] as $id) {
        $k = $porId[$id] ?? null;
        if (!$k || in_array($k['status'], ['rascunho', 'cancelado'], true)) {
            continue;
        }
        $r['qtd']++;
        $quando = $k['tipo'] === 'evento' ? ($k['campos']['data'] ?? '') : ($k['campos']['entrega_data'] ?? '');
        $r['ultimo'] = max($r['ultimo'], $quando);
        if ($k['status'] === 'assinado') {
            $r['assinados']++;
            $r['total'] += dh_contrato_total($k);
        }
    }
    return $r;
}
