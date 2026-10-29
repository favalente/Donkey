<?php
// Vendas de chope (barris): histórico importado da ZapSign + contratos de comodato assinados no site.
// Alimenta Painel → Relatórios. Os dados ficam só no servidor (dados/vendas-historico.json).

const DH_ARQ_VENDAS_HIST = DH_RAIZ . '/dados/vendas-historico.json';

// Rótulos: palavras que aparecem nas descrições digitadas na ZapSign → nome do rótulo
const DH_ROTULOS_CHAVES = [
    'Velas do Mucuripe' => ['velas', 'mucuripe', 'mucuirpe'],
    'StigmApa' => ['stigm', 'stigmapa'],
    'Miolo de Pote' => ['miolo', 'blond', 'blonde'],
    'Sofhop' => ['sofhop', 'softhop', 'softhope'],
    'Mel da Goiaba' => ['goiaba'],
    'Mandacaripa' => ['mandacaripa'],
    'Severina Sour Cajú' => ['caju', 'severina'],
    'Session IPA' => ['session'],
    'DH Premium Lager' => ['premium', 'premiun', 'premiu', 'premuin', 'lager', 'larger', 'langer', 'lague', 'large', 'pilsen', 'lounge', 'mais leve'],
];

// Bairros de Fortaleza e cidades vizinhas (para achar o local no endereço digitado)
const DH_CIDADES_VIZINHAS = [
    'Caucaia' => ['caucaia', 'cumbuco', 'icarai', 'tabuba', 'capuan'],
    'Eusébio' => ['eusebio', 'alphaville', 'coite'],
    'Aquiraz' => ['aquiraz', 'porto das dunas', 'prainha', 'beach park', 'iguape'],
    'Maracanaú' => ['maracanau'],
    'Maranguape' => ['maranguape'],
    'Pacatuba' => ['pacatuba'],
    'Itaitinga' => ['itaitinga'],
    'Horizonte' => ['horizonte'],
    'Pacajus' => ['pacajus'],
    'Cascavel' => ['cascavel'],
    'Beberibe' => ['beberibe', 'morro branco'],
    'Guaramiranga' => ['guaramiranga'],
    'Quixadá' => ['quixada'],
    'São Gonçalo do Amarante' => ['sao goncalo do amarante', 'taiba', 'pecem'],
    'Paracuru' => ['paracuru'],
    'Trairi' => ['trairi', 'flecheiras'],
];
const DH_BAIRROS_FORTALEZA = ['Aldeota', 'Meireles', 'Mucuripe', 'Varjota', 'Papicu', 'Cocó', 'Dionísio Torres', 'Joaquim Távora', 'Fátima', 'Benfica',
    'Centro', 'Praia de Iracema', 'Praia do Futuro', 'Vicente Pinzon', 'Cais do Porto', 'Guararapes', 'Luciano Cavalcante', 'Edson Queiroz',
    'Cidade dos Funcionários', 'Cambeba', 'Messejana', 'Parquelândia', 'Parque Manibura', 'Sapiranga', 'Lagoa Sapiranga', 'Água Fria', 'Salinas',
    'José de Alencar', 'Cajazeiras', 'Passaré', 'Serrinha', 'Itaperi', 'Montese', 'Parangaba', 'Maraponga', 'Mondubim', 'Jóquei Clube',
    'Henrique Jorge', 'Rodolfo Teófilo', 'Damas', 'Jardim América', 'Bom Futuro', 'São João do Tauape', 'Alto da Balança', 'Aerolândia',
    'Dias Macedo', 'Boa Vista', 'Castelão', 'Jangurussu', 'Barroso', 'Conjunto Ceará', 'Antônio Bezerra', 'Presidente Kennedy', 'Monte Castelo',
    'Farias Brito', 'Jacarecanga', 'Álvaro Weyne', 'Carlito Pamplona', 'Pirambu', 'Barra do Ceará', 'Quintino Cunha', 'Padre Andrade',
    'Amadeu Furtado', 'Parque Araxá', 'Dunas', 'Manuel Dias Branco', 'De Lourdes', 'Sabiaguaba', 'Coaçu', 'Paupina', 'Lagoa Redonda', 'Curió',
    'Guajeru', 'Alagadiço', 'São Gerardo', 'Pici', 'Planalto Ayrton Senna', 'Cidade 2000', 'Granja Portugal', 'Dendê', 'Engenheiro Luciano Cavalcante',
    'Bairro de Fátima', 'Parque Iracema', 'Jardim das Oliveiras', 'Vila União', 'Vila Velha'];

function dh_vendas_historico(): array
{
    $h = json_decode((string) @file_get_contents(DH_ARQ_VENDAS_HIST), true);
    return is_array($h) ? $h + ['pedidos' => [], 'importado_em' => ''] : ['pedidos' => [], 'importado_em' => ''];
}

function dh_vendas_historico_salvar(array $h): void
{
    if (@file_put_contents(DH_ARQ_VENDAS_HIST, json_encode($h, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
        throw new RuntimeException('Não foi possível gravar o histórico de vendas. Confira a permissão da pasta dados.');
    }
}

// Texto sem acento e em minúsculas, para comparar
function dh_sem_acento(string $s): string
{
    $t = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    return strtolower($t === false ? $s : $t);
}

// ---- Leitura da planilha .xlsx (primeira aba) sem biblioteca: o .xlsx é um zip de XMLs ----

function dh_xlsx_linhas(string $arquivo): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('O servidor não tem suporte a arquivos .xlsx (ZipArchive).');
    }
    $z = new ZipArchive();
    if ($z->open($arquivo) !== true) {
        throw new RuntimeException('Não consegui abrir a planilha. Envie o arquivo .xlsx baixado da ZapSign.');
    }
    $textos = [];
    if (($xml = $z->getFromName('xl/sharedStrings.xml')) !== false) {
        $ss = simplexml_load_string($xml);
        foreach ($ss->si as $si) {
            // Texto simples (<t>) ou com formatação (<r><t>)
            $textos[] = isset($si->t) ? (string) $si->t : implode('', array_map('strval', $si->xpath('.//*[local-name()="t"]')));
        }
    }
    $aba = $z->getFromName('xl/worksheets/sheet1.xml');
    $z->close();
    if ($aba === false) {
        throw new RuntimeException('A planilha não tem a aba com as respostas.');
    }
    $folha = simplexml_load_string($aba);
    $linhas = [];
    foreach ($folha->sheetData->row as $row) {
        $l = [];
        foreach ($row->c as $c) {
            $col = 0;
            foreach (str_split(preg_replace('/\d/', '', (string) $c['r'])) as $letra) {
                $col = $col * 26 + (ord($letra) - 64);
            }
            $t = (string) $c['t'];
            $v = $t === 's' ? ($textos[(int) $c->v] ?? '') : ($t === 'inlineStr' ? (string) $c->is->t : (string) $c->v);
            $l[$col - 1] = $v;
        }
        if ($l) {
            $max = max(array_keys($l));
            $linhas[] = array_map(fn($i) => $l[$i] ?? '', range(0, $max));
        }
    }
    return $linhas;
}

// ---- Limpeza dos campos digitados na ZapSign ----

// "R$ 1.600,00" / "$1,680.00" / "1000" → 1600.0 (null se não der para ler)
function dh_zap_dinheiro(string $s): ?float
{
    $s = preg_replace('/[^\d,.]/', '', $s);
    if ($s === '' || !preg_match('/\d/', $s)) {
        return null;
    }
    $virg = strrpos($s, ',');
    $ponto = strrpos($s, '.');
    if ($virg !== false && ($ponto === false || $virg > $ponto)) {
        $s = str_replace(['.', ','], ['', '.'], $s);
    } elseif ($ponto !== false && $virg !== false) {
        $s = str_replace(',', '', $s);
    } elseif ($ponto !== false && preg_match('/\.\d{3}$/', $s) && substr_count($s, '.') >= 1 && strlen($s) > 4) {
        $s = str_replace('.', '', $s);
    }
    return is_numeric($s) ? (float) $s : null;
}

// "14/04/2023", "2023-04-11 15:03:47.906" ou número de data do Excel → "2023-04-14"
function dh_zap_data(string $v): string
{
    $v = trim($v);
    if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})#', $v, $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
        return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m)) {
        return "$m[1]-$m[2]-$m[3]";
    }
    if (is_numeric($v) && (float) $v > 20000 && (float) $v < 80000) {
        return gmdate('Y-m-d', (int) round(((float) $v - 25569) * 86400));
    }
    return '';
}

// Rótulos citados na descrição ("Premium larger", "VELAS DO MUCUIRPE - 100L ; STIGMAPA - 50L")
function dh_zap_rotulos(string $desc): array
{
    $d = ' ' . preg_replace('/[^a-z0-9]+/', ' ', dh_sem_acento($desc)) . ' ';
    $achados = [];
    foreach (DH_ROTULOS_CHAVES as $rotulo => $chaves) {
        foreach ($chaves as $ch) {
            if (strpos($d, ' ' . $ch) !== false) {
                $achados[] = $rotulo;
                break;
            }
        }
    }
    // "Apa" sozinho é a StigmApa
    if (!$achados && preg_match('/ apa /', $d)) {
        $achados[] = 'StigmApa';
    }
    return $achados;
}

// Bairro e cidade a partir do endereço digitado
function dh_zap_local(string $endereco): array
{
    $e = ' ' . preg_replace('/[^a-z0-9]+/', ' ', dh_sem_acento($endereco)) . ' ';
    foreach (DH_CIDADES_VIZINHAS as $cidade => $chaves) {
        foreach ($chaves as $ch) {
            if (strpos($e, ' ' . $ch . ' ') !== false) {
                return ['', $cidade];
            }
        }
    }
    $bairros = DH_BAIRROS_FORTALEZA;
    usort($bairros, fn($a, $b) => strlen($b) <=> strlen($a));
    foreach ($bairros as $b) {
        if (strpos($e, ' ' . preg_replace('/[^a-z0-9]+/', ' ', dh_sem_acento($b)) . ' ') !== false) {
            return [$b === 'Bairro de Fátima' ? 'Fátima' : ($b === 'Engenheiro Luciano Cavalcante' ? 'Luciano Cavalcante' : $b), 'Fortaleza'];
        }
    }
    return ['', 'Fortaleza'];
}

// Importa a planilha baixada da ZapSign. Reimportar a mesma planilha atualiza (a chave é o Doc ID).
function dh_zap_importar(string $arquivo): array
{
    $linhas = dh_xlsx_linhas($arquivo);
    if (count($linhas) < 2) {
        throw new RuntimeException('A planilha está vazia.');
    }
    $cab = array_map(fn($c) => trim(preg_replace('/[^a-z0-9]+/', ' ', dh_sem_acento((string) $c))), $linhas[0]);
    $col = function (string ...$nomes) use ($cab): ?int {
        foreach ($nomes as $n) {
            $i = array_search($n, $cab, true);
            if ($i !== false) {
                return $i;
            }
        }
        return null;
    };
    $c = ['id' => $col('doc id'), 'criado' => $col('data criado'), 'nome' => $col('nome completo'), 'cel' => $col('celular'), 'doc' => $col('cpf', 'cpf cnpj'),
        'devolucao' => $col('data devolucao'), 'recebimento' => $col('data recebimento'), 'desc' => $col('descricao do chope'), 'email' => $col('email'),
        'endereco' => $col('endereco de entrega'), 'litros' => $col('quant litro'), 'vlitro' => $col('valor litro r'), 'total' => $col('valor total r')];
    if ($c['id'] === null || $c['total'] === null || $c['recebimento'] === null) {
        throw new RuntimeException('Esta planilha não parece ser a de contratos de locação de equipamento da ZapSign (faltam as colunas Doc ID, Data Recebimento ou Valor Total).');
    }
    $v = fn(array $l, string $k) => $c[$k] === null ? '' : trim((string) ($l[$c[$k]] ?? ''));
    $hist = dh_vendas_historico();
    $porId = array_column($hist['pedidos'], null, 'id');
    $novos = $atualizados = 0;
    foreach (array_slice($linhas, 1) as $l) {
        if ($v($l, 'id') === '') {
            continue;
        }
        $alertas = [];
        $criado = dh_zap_data($v($l, 'criado'));
        $data = dh_zap_data($v($l, 'recebimento'));
        // Data do evento digitada com ano errado (ex.: data de nascimento): vale a data do contrato
        if ($criado !== '' && ($data === '' || abs(strtotime($data) - strtotime($criado)) > 400 * 86400)) {
            if ($data !== '') {
                $alertas[] = 'data de recebimento "' . $v($l, 'recebimento') . '" fora do esperado; usada a data do contrato';
            }
            $data = $criado;
        }
        $desc = $v($l, 'desc');
        $rotulos = dh_zap_rotulos($desc);
        // Litros: o campo às vezes traz a lista inteira de opções ("30L, 50L, 80L..."); aí vale a descrição
        $campoLitros = $v($l, 'litros');
        $litros = null;
        if (preg_match('/^\s*(\d+)\s*l\s*$/i', $campoLitros, $m)) {
            $litros = (float) $m[1];
        } elseif (preg_match_all('/(\d+)\s*(?:l|lt|lts|litros)\b/i', $desc, $mm)) {
            $litros = (float) array_sum($mm[1]);
        }
        $vl = dh_zap_dinheiro($v($l, 'vlitro'));
        $total = dh_zap_dinheiro($v($l, 'total'));
        // Valor do litro e total trocados de campo
        if ($total !== null && $total < 50 && $vl !== null && $vl >= 100) {
            [$vl, $total] = [$total, $vl];
            $alertas[] = 'valor do litro e valor total estavam trocados';
        }
        if ($vl !== null && ($vl < 5 || $vl > 60)) {
            $vl = null;
        }
        if ($litros === null && $total && $vl) {
            $litros = round($total / $vl);
            $alertas[] = 'litragem calculada pelo valor total';
        }
        // Total muito diferente de litros × valor do litro (ex.: R$ 6.000 para 30 L a R$ 20)
        if ($total !== null && $litros && $vl && ($total > 3 * $litros * $vl || $total < $litros * $vl / 3)) {
            $alertas[] = 'valor total ' . dh_moeda($total) . ' corrigido para litros × valor do litro';
            $total = $litros * $vl;
        }
        $semValor = $total === null || $total < 50;
        if ($semValor) {
            $alertas[] = 'sem valor (cortesia ou teste): fica fora do faturamento';
        }
        if (!$rotulos) {
            $alertas[] = 'rótulo não identificado';
        }
        $endereco = $v($l, 'endereco');
        [$bairro, $cidade] = dh_zap_local($endereco);
        $d = dh_sem_acento($desc);
        $p = [
            'id' => 'zap-' . $v($l, 'id'),
            'origem' => 'ZapSign',
            'criado' => $criado,
            'data' => $data,
            'devolucao' => dh_zap_data($v($l, 'devolucao')),
            'nome' => trim(preg_replace('/\s+/', ' ', $v($l, 'nome'))),
            'doc' => dh_so_digitos($v($l, 'doc')),
            'telefone' => $v($l, 'cel'),
            'email' => strtolower($v($l, 'email')),
            'endereco' => $endereco,
            'bairro' => $bairro,
            'cidade' => $cidade,
            'rotulos' => $rotulos,
            'litros' => $litros,
            'valor' => $semValor ? null : round($total, 2),
            'valor_litro' => $vl,
            'consignado' => strpos($d, 'consign') !== false || strpos($d, 'comodato') !== false,
            'copos' => strpos($d, 'copo') !== false || strpos($d, 'taca') !== false,
            'descricao' => mb_substr($desc, 0, 300),
            'alertas' => $alertas,
            'duplicado' => false,
        ];
        isset($porId[$p['id']]) ? $atualizados++ : $novos++;
        $porId[$p['id']] = $p;
    }
    // Mesmo cliente, mesma data e mesmo valor = contrato reenviado na ZapSign: conta uma vez só
    $pedidos = array_values($porId);
    usort($pedidos, fn($a, $b) => (int) substr($a['id'], 4) <=> (int) substr($b['id'], 4));
    $vistos = [];
    $duplicados = 0;
    foreach ($pedidos as $i => $p) {
        $chave = ($p['doc'] ?: dh_sem_acento($p['nome'])) . '|' . $p['data'] . '|' . $p['valor'];
        $pedidos[$i]['duplicado'] = isset($vistos[$chave]);
        if ($pedidos[$i]['duplicado']) {
            $duplicados++;
        }
        $vistos[$chave] = true;
    }
    $hist['pedidos'] = $pedidos;
    $hist['importado_em'] = date('Y-m-d H:i:s');
    dh_vendas_historico_salvar($hist);
    // Clientes entram na base (sem autorização de novidades)
    foreach ($pedidos as $p) {
        if (!$p['duplicado']) {
            dh_cliente_registrar(['id' => $p['id'], 'campos' => ['contratante_doc' => $p['doc'], 'contratante_nome' => $p['nome'], 'contratante_telefone' => $p['telefone'],
                'contratante_email' => $p['email'], 'entrega_endereco' => $p['endereco'], 'cidade' => $p['cidade'] === 'Fortaleza' ? '' : $p['cidade']]]);
        }
    }
    return ['novos' => $novos, 'atualizados' => $atualizados, 'duplicados' => $duplicados, 'total' => count($pedidos),
        'alertas' => count(array_filter($pedidos, fn($p) => $p['alertas'] && !$p['duplicado']))];
}

// ---- Pedidos para os relatórios: histórico (sem duplicados) + contratos de comodato assinados no site ----

function dh_vendas_pedidos(): array
{
    $lista = array_values(array_filter(dh_vendas_historico()['pedidos'], fn($p) => !$p['duplicado']));
    foreach (dh_contratos()['contratos'] as $k) {
        if ($k['tipo'] !== 'comodato' || $k['status'] !== 'assinado') {
            continue;
        }
        $f = $k['campos'];
        $comprado = array_filter($f['itens'], fn($it) => empty($it['comodato']));
        $rotulos = [];
        foreach ($comprado as $it) {
            $rotulos = array_merge($rotulos, dh_zap_rotulos($it['descricao']) ?: [trim($it['descricao'])]);
        }
        [$bairro, $cidade] = dh_zap_local($f['entrega_endereco'] . ' ' . ($f['cidade'] ?? ''));
        $litros = array_sum(array_map(fn($it) => (float) $it['litros'], $comprado));
        $lista[] = [
            'id' => $k['id'], 'origem' => 'Site', 'criado' => substr($k['enviado_em'] ?? '', 0, 10), 'data' => $f['entrega_data'],
            'nome' => $f['contratante_nome'], 'doc' => dh_so_digitos($f['contratante_doc']), 'telefone' => $f['contratante_telefone'], 'email' => $f['contratante_email'],
            'endereco' => $f['entrega_endereco'], 'bairro' => $bairro, 'cidade' => $cidade, 'rotulos' => array_values(array_unique($rotulos)),
            'litros' => $litros ?: null, 'valor' => dh_comodato_total($f), 'valor_litro' => null,
            'consignado' => (bool) array_filter($f['itens'], fn($it) => !empty($it['comodato'])), 'copos' => (int) ($f['copos_pacotes'] ?? 0) > 0,
            'descricao' => '', 'alertas' => [], 'duplicado' => false, 'numero' => $k['numero'],
        ];
    }
    usort($lista, fn($a, $b) => strcmp($a['data'], $b['data']));
    return $lista;
}

// Chave do cliente para contar recompra (CPF/CNPJ; sem documento, o nome)
function dh_vendas_cliente_chave(array $p): string
{
    return $p['doc'] !== '' ? $p['doc'] : 'nome:' . dh_sem_acento($p['nome']);
}

// Faixa de tamanho do pedido
function dh_vendas_faixa(?float $litros): string
{
    if (!$litros) {
        return 'Não informado';
    }
    return $litros <= 30 ? '30 L' : ($litros <= 50 ? '50 L' : ($litros <= 100 ? '51 a 100 L' : 'Mais de 100 L'));
}
