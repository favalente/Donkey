<?php
// Contratos com assinatura eletrônica (MP 2.200-2/2001, art. 10, §2º, e Lei 14.063/2020).
// O painel monta o contrato; ao enviar, o texto é congelado e ganha um código de integridade (SHA-256).
// A Donkey assina pelo painel (senha do administrador) e o cliente pelo link, confirmando com um código
// enviado por e-mail. Tudo fica em dados/contratos.json (bloqueado na web), com IP, aparelho e horário.

const DH_ARQ_CONTRATOS = DH_RAIZ . '/dados/contratos.json';
const DH_CONTRATO_TIPOS = [
    'evento' => 'Evento corporativo no pub',
    'comodato' => 'Venda de chope com comodato de equipamentos',
];
// Frete de instalação e retirada em Fortaleza; outras cidades, sob consulta (a Donkey define no painel)
const DH_FRETE_FORTALEZA = 40;
// Copos personalizados vendidos junto com o chope
const DH_COPOS = ['qtd' => 50, 'valor' => 40];
// Comprovante de pagamento que o cliente anexa ao pedido (fica em dados/, sem acesso direto pela web)
const DH_DIR_COMPROVANTES = DH_RAIZ . '/dados/comprovantes';
const DH_COMPROVANTE_TIPOS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/heic' => 'heic', 'image/heif' => 'heif', 'application/pdf' => 'pdf'];
const DH_COMPROVANTE_MAX = 15 * 1024 * 1024;
const DH_CONTRATO_STATUS = ['rascunho' => 'Rascunho', 'preenchimento' => 'Aguardando o cliente preencher', 'consulta' => 'Aguardando valor do frete', 'enviado' => 'Aguardando assinatura', 'assinado' => 'Assinado', 'cancelado' => 'Cancelado'];
const DH_EMPRESA = [
    'razao' => 'DONKEY HEAD BREWPUB CERVEJARIA LTDA',
    'cnpj' => '27.454.745/0001-87',
    'endereco' => 'Rua Norvinda Pires, 42, Aldeota, Fortaleza/CE',
    'telefone' => '(85) 98212-4149',
    'email' => 'contato@donkeyheadcervejaria.com.br',
];
// Equipamentos do comodato, com o valor de reposição de cada um
const DH_EQUIPAMENTOS = [
    ['Chopeira Slim inox com duas torneiras italianas 220 volts (engate rápido)', 4500],
    ['Válvula extratora KEG P inox engate rápido', 350],
    ['Válvula redutora de pressão com 2 saídas engate rápido e manômetro', 450],
    ['Cilindro de CO2 de 6 kg em alumínio', 1200],
];
const DH_ANEXO_EVENTO = "1. RODÍZIO DE FINGER FOODS + CERVEJA ARTESANAL + BEBIDAS NÃO ALCOÓLICAS

RODÍZIO DE FINGER FOODS
Escolha dentre 9 opções abaixo:
Pastéis de Queijo
Feijão Verde Cremoso
Chicken Balls
Croquetas de Queijo Gouda
Chicken K-Pop
Batatas Cross-Trax
Delikatessen de Mini Burgers
Melted Fries
Arroz de cupim sertanejo

CERVEJA ARTESANAL LIBERADA
02 ESTILOS DE CERVEJAS:
1. DH Premium Lager
2. Asa Branca (Weiss)*
*o rótulo 2 pode ser alterado de acordo com a disponibilidade em estoque no dia do evento, mantendo a mesma qualidade.

BEBIDAS NÃO ALCOÓLICAS
ÁGUA MINERAL: Com e sem gás
REFRIGERANTES VARIADOS: Coca-Cola, Sprite, Guaraná
SUCOS: Laranja e limão

2. ADICIONAIS:
SOBREMESA (uma opção por pessoa):
Brownie Na Taça
Cheese Ice Cream
Valor: R$ 15 por pessoa

PREMIUM COCKTAILS (liberado):
Caipirinha (Limão, Morango, Kiwi)
Caipiroska (Limão, Morango, Kiwi)
Gin Tônica (Morango, Maçã Verde, Laranja)
Mojito
Moscow Mule
Valor: R$59,90 por pessoa";
// Adicionais do evento (valor por pessoa)
const DH_EVENTO_ADICIONAIS = ['sobremesa' => ['Sobremesa', 15], 'cocktails' => ['Premium Cocktails (liberado)', 59.90]];
const DH_PAGAMENTOS = ['cartao' => 'Cartão', 'pix' => 'Pix', 'transferencia' => 'Transferência'];

function dh_contratos(): array
{
    $c = json_decode((string) @file_get_contents(DH_ARQ_CONTRATOS), true);
    $c = is_array($c) ? $c : [];
    return $c + ['contratos' => [], 'config' => [], 'seq' => []];
}

function dh_contratos_salvar(array $c): void
{
    $dir = dirname(DH_ARQ_CONTRATOS);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $json = json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $tmp = DH_ARQ_CONTRATOS . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) === false || !rename($tmp, DH_ARQ_CONTRATOS)) {
        @unlink($tmp);
        throw new RuntimeException('Não foi possível gravar os contratos. Verifique a permissão da pasta "dados".');
    }
    // Contrato assinado é prova: guarda uma cópia por dia e nunca apaga
    if (!is_dir($dir . '/backup')) {
        mkdir($dir . '/backup', 0755, true);
    }
    @copy(DH_ARQ_CONTRATOS, $dir . '/backup/contratos-' . date('Y-m-d') . '.json');
}

function dh_contrato_por_token(array $c, string $token): ?int
{
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        return null;
    }
    foreach ($c['contratos'] as $i => $x) {
        if (hash_equals($x['token'], $token)) {
            return $i;
        }
    }
    return null;
}

function dh_contrato_indice(array $c, string $id): ?int
{
    foreach ($c['contratos'] as $i => $x) {
        if ($x['id'] === $id) {
            return $i;
        }
    }
    return null;
}

// "DH-2026-007"
function dh_contrato_numero(array &$c): string
{
    $ano = date('Y');
    $c['seq'][$ano] = ($c['seq'][$ano] ?? 0) + 1;
    return sprintf('DH-%s-%03d', $ano, $c['seq'][$ano]);
}

function dh_so_digitos(string $s): string
{
    return preg_replace('/\D/', '', $s);
}

// Número para o link do WhatsApp: (85) 99999-8888 -> 5585999998888
function dh_whatsapp_numero(string $tel): string
{
    $n = dh_so_digitos($tel);
    return in_array(strlen($n), [10, 11], true) ? '55' . $n : $n;
}

function dh_cpf_valido(string $cpf): bool
{
    $n = dh_so_digitos($cpf);
    if (strlen($n) !== 11 || preg_match('/^(\d)\1{10}$/', $n)) {
        return false;
    }
    for ($t = 9; $t < 11; $t++) {
        $s = 0;
        for ($i = 0; $i < $t; $i++) {
            $s += (int) $n[$i] * ($t + 1 - $i);
        }
        if ((int) $n[$t] !== ((10 * $s) % 11) % 10) {
            return false;
        }
    }
    return true;
}

function dh_cnpj_valido(string $cnpj): bool
{
    $n = dh_so_digitos($cnpj);
    if (strlen($n) !== 14 || preg_match('/^(\d)\1{13}$/', $n)) {
        return false;
    }
    foreach ([12, 13] as $t) {
        $s = 0;
        $p = $t - 7;
        for ($i = 0; $i < $t; $i++) {
            $s += (int) $n[$i] * $p--;
            if ($p < 2) {
                $p = 9;
            }
        }
        if ((int) $n[$t] !== ($s % 11 < 2 ? 0 : 11 - $s % 11)) {
            return false;
        }
    }
    return true;
}

function dh_cpf_formatar(string $cpf): string
{
    $n = dh_so_digitos($cpf);
    if (strlen($n) === 11) {
        return substr($n, 0, 3) . '.' . substr($n, 3, 3) . '.' . substr($n, 6, 3) . '-' . substr($n, 9);
    }
    if (strlen($n) === 14) {
        return substr($n, 0, 2) . '.' . substr($n, 2, 3) . '.' . substr($n, 5, 3) . '/' . substr($n, 8, 4) . '-' . substr($n, 12);
    }
    return $cpf;
}

// CPF escondido para mostrar em páginas: ***.620.423-**
function dh_cpf_mascara(string $cpf): string
{
    $f = dh_cpf_formatar($cpf);
    return strlen(dh_so_digitos($cpf)) === 11 ? '***' . substr($f, 3, 8) . '-**' : $f;
}

function dh_data_extenso(string $data): string
{
    $t = strtotime($data);
    return $t ? date('d', $t) . ' de ' . mb_strtolower(DH_MESES[(int) date('n', $t)]) . ' de ' . date('Y', $t) : '';
}

// 50.00 -> "50"; 12.50 -> "12,5"
function dh_litros($v): string
{
    return dh_num(rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.'));
}

function dh_data_br(string $data): string
{
    $t = strtotime($data);
    return $data !== '' && $t ? date('d/m/Y', $t) : '';
}

// Valor total do evento: convidados × (pacote por pessoa + adicionais por pessoa)
function dh_evento_valores(array $f): array
{
    $adic = 0.0;
    foreach (DH_EVENTO_ADICIONAIS as $k => [, $v]) {
        if (!empty($f['adicionais'][$k])) {
            $adic += $v;
        }
    }
    $porPessoa = (float) ($f['valor_pessoa'] ?: 0) + $adic;
    $total = $f['total'] !== '' ? (float) $f['total'] : $porPessoa * (int) ($f['convidados'] ?: 0);
    return ['por_pessoa' => $porPessoa, 'total' => $total];
}

// Cervejas oferecidas para evento (com valor do litro cadastrado em Cervejas), por nome
function dh_cervejas_evento(): array
{
    $lista = array_values(array_filter(dh_carregar()['cervejas'], fn($c) => is_numeric($c['preco_evento'] ?? '') && (float) $c['preco_evento'] > 0));
    usort($lista, fn($a, $b) => strcoll(dh_slug($a['nome']), dh_slug($b['nome'])));
    return $lista;
}

// Valor do chope comprado (o chope em comodato não entra)
function dh_comodato_chope(array $f): float
{
    $t = 0.0;
    foreach ($f['itens'] ?? [] as $it) {
        if (empty($it['comodato'])) {
            $t += (float) ($it['litros'] ?: 0) * (float) ($it['valor_litro'] ?: 0);
        }
    }
    return $t;
}

// Copos personalizados: pacotes × valor do pacote
function dh_comodato_copos(array $f): float
{
    return max(0, (int) ($f['copos_pacotes'] ?? 0)) * DH_COPOS['valor'];
}

// Total a pagar: chope comprado − desconto do cupom (só no chope) + copos + frete
function dh_comodato_total(array $f): float
{
    $chope = dh_comodato_chope($f);
    return $chope - min($chope, (float) (($f['desconto'] ?? '') ?: 0)) + dh_comodato_copos($f) + (float) ($f['frete'] ?: 0);
}

// ---- Cupons de desconto (valem sobre o chope, não sobre o frete) ----

function dh_cupom_codigo(string $s): string
{
    return mb_substr(strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $s)), 0, 30);
}

// Devolve o cupom se ele pode ser usado hoje; senão, explica o motivo
function dh_cupom_valido(array $c, string $codigo): array
{
    $cp = $c['cupons'][dh_cupom_codigo($codigo)] ?? null;
    if (!$cp || empty($cp['ativo'])) {
        throw new RuntimeException('Cupom não encontrado. Confira o código.');
    }
    if ($cp['validade'] !== '' && $cp['validade'] < date('Y-m-d')) {
        throw new RuntimeException('Este cupom venceu em ' . dh_data_br($cp['validade']) . '.');
    }
    if ((int) $cp['limite'] > 0 && (int) $cp['usos'] >= (int) $cp['limite']) {
        throw new RuntimeException('Este cupom já foi usado o máximo de vezes.');
    }
    return $cp;
}

function dh_cupom_desconto(array $cp, float $chope): float
{
    $d = $cp['tipo'] === 'pct' ? $chope * (float) $cp['valor'] / 100 : (float) $cp['valor'];
    return round(min($chope, max(0, $d)), 2);
}

function dh_cupom_rotulo(array $cp): string
{
    return $cp['tipo'] === 'pct' ? dh_num((float) $cp['valor']) . '% no chope' : dh_moeda($cp['valor']) . ' no chope';
}

// ---- Comprovante de pagamento ----

// Confere e grava o arquivo enviado; devolve os dados que vão para o contrato
function dh_comprovante_salvar(string $id, array $arq): array
{
    if (($arq['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_INI_SIZE || ($arq['error'] ?? 0) === UPLOAD_ERR_FORM_SIZE || ($arq['size'] ?? 0) > DH_COMPROVANTE_MAX) {
        throw new RuntimeException('O comprovante passou de 15 MB. Envie um print ou o PDF do comprovante.');
    }
    if (($arq['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($arq['tmp_name'])) {
        throw new RuntimeException('Não foi possível receber o comprovante. Tente de novo.');
    }
    $tipo = class_exists('finfo') ? (string) (new finfo(FILEINFO_MIME_TYPE))->file($arq['tmp_name']) : (string) mime_content_type($arq['tmp_name']);
    if (!isset(DH_COMPROVANTE_TIPOS[$tipo])) {
        throw new RuntimeException('O comprovante precisa ser uma foto (JPG, PNG) ou um PDF.');
    }
    if (!is_dir(DH_DIR_COMPROVANTES) && !@mkdir(DH_DIR_COMPROVANTES, 0755, true)) {
        throw new RuntimeException('Não foi possível guardar o comprovante agora. Fale com a Donkey Head Cervejaria.');
    }
    $nome = preg_replace('/[^a-z0-9]/', '', $id) . '-' . bin2hex(random_bytes(6)) . '.' . DH_COMPROVANTE_TIPOS[$tipo];
    if (!move_uploaded_file($arq['tmp_name'], DH_DIR_COMPROVANTES . '/' . $nome)) {
        throw new RuntimeException('Não foi possível guardar o comprovante agora. Tente de novo.');
    }
    return [
        'arquivo' => $nome,
        'nome' => mb_substr(preg_replace('/[^\p{L}\p{N} ._()-]/u', '', (string) $arq['name']), 0, 80) ?: 'comprovante.' . DH_COMPROVANTE_TIPOS[$tipo],
        'tipo' => $tipo,
        'tamanho' => (int) filesize(DH_DIR_COMPROVANTES . '/' . $nome),
        'sha256' => hash_file('sha256', DH_DIR_COMPROVANTES . '/' . $nome),
        'em' => date('Y-m-d H:i:s'),
    ];
}

function dh_comprovante_apagar(?array $cp): void
{
    if (!empty($cp['arquivo']) && preg_match('/^[a-z0-9-]+\.[a-z]+$/', $cp['arquivo'])) {
        @unlink(DH_DIR_COMPROVANTES . '/' . $cp['arquivo']);
    }
}

// Mostra o comprovante no navegador (imagem/PDF) ou baixa (HEIC)
function dh_comprovante_mostrar(?array $cp): void
{
    $arq = !empty($cp['arquivo']) && preg_match('/^[a-z0-9-]+\.[a-z]+$/', $cp['arquivo']) ? DH_DIR_COMPROVANTES . '/' . $cp['arquivo'] : '';
    if ($arq === '' || !is_file($arq)) {
        http_response_code(404);
        exit('Comprovante não encontrado.');
    }
    $ver = in_array($cp['tipo'], ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true);
    header('Content-Type: ' . $cp['tipo']);
    header('Content-Length: ' . filesize($arq));
    header('Content-Disposition: ' . ($ver ? 'inline' : 'attachment') . '; filename="' . str_replace('"', '', $cp['nome']) . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    readfile($arq);
    exit;
}

function dh_contrato_total(array $k): float
{
    return $k['tipo'] === 'evento' ? dh_evento_valores($k['campos'])['total'] : dh_comodato_total($k['campos']);
}

// ---- Texto do contrato (HTML). É este HTML que é congelado e recebe o código de integridade. ----

function dh_contrato_documento(array $k, array $config): string
{
    $f = $k['campos'];
    $rep = $config['representante_nome'] ?? '';
    $linha = fn($rot, $val) => '<tr><th>' . e($rot) . '</th><td>' . ($val === '' ? '&nbsp;' : e($val)) . '</td></tr>';
    $h = '<h1>' . e($k['tipo'] === 'evento' ? 'CONTRATO DE LOCAÇÃO DE ESPAÇO E PRESTAÇÃO DE SERVIÇOS PARA EVENTO' : 'CONTRATO DE COMPRA E VENDA DE CHOPE COM COMODATO DE EQUIPAMENTOS') . '</h1>';
    $h .= '<p class="numero">Contrato nº ' . e($k['numero']) . '</p>';
    $h .= '<h2>SEÇÃO I – IDENTIFICAÇÃO DAS PARTES</h2><table class="quadro">';
    $h .= $linha('CONTRATADA:', DH_EMPRESA['razao']) . $linha('CNPJ:', DH_EMPRESA['cnpj']) . $linha('ENDEREÇO:', DH_EMPRESA['endereco'])
        . $linha('REPRESENTANTE:', $rep) . $linha('TELEFONE:', DH_EMPRESA['telefone']) . $linha('E-MAIL:', DH_EMPRESA['email']);
    $h .= '</table><table class="quadro">';
    $h .= $linha('NOME/RAZÃO SOCIAL DO CONTRATANTE:', $f['contratante_nome']) . $linha('CPF/CNPJ DO CONTRATANTE:', dh_cpf_formatar($f['contratante_doc']))
        . $linha('ENDEREÇO:', $f['contratante_endereco']) . $linha('TELEFONE:', $f['contratante_telefone']) . $linha('E-MAIL:', $f['contratante_email']);
    $h .= '</table>';
    $h .= $k['tipo'] === 'evento' ? dh_contrato_evento($f, $linha) : dh_contrato_comodato($f, $linha);
    // No evento, o comprovante vai em quadro próprio no fim (no comodato ele já está na Seção II)
    if ($k['tipo'] === 'evento' && !empty($f['comprovante'])) {
        $cp = $f['comprovante'];
        $h .= '<h2>COMPROVANTE DE PAGAMENTO</h2><table class="quadro">' . $linha('Comprovante:', 'Anexado em ' . date('d/m/Y \à\s H:i', strtotime($cp['em'])) . ' (arquivo "' . $cp['nome'] . '", código SHA-256 ' . $cp['sha256'] . ')') . '</table>'
            . '<p>O comprovante de pagamento identificado acima integra este contrato.</p>';
    }
    $h .= '<p class="local-data">Fortaleza/CE, ' . e(dh_data_extenso($k['enviado_em'] ?? date('Y-m-d'))) . '.</p>';
    return $h;
}

function dh_contrato_evento(array $f, callable $linha): string
{
    $v = dh_evento_valores($f);
    $adic = [];
    foreach (DH_EVENTO_ADICIONAIS as $chave => [$nome, $valor]) {
        if (!empty($f['adicionais'][$chave])) {
            $adic[] = $nome . ' (' . dh_moeda($valor) . ' por pessoa)';
        }
    }
    $pag = [];
    foreach (DH_PAGAMENTOS as $chave => $rot) {
        $pag[] = $rot . ' (' . ($f['pagamento'] === $chave ? 'X' : '  ') . ')';
    }
    $h = '<h2>SEÇÃO II – CONDIÇÕES COMERCIAIS DA PRESTAÇÃO DE SERVIÇOS</h2><table class="quadro">';
    $h .= $linha('Espaço Disponibilizado:', $f['espaco']) . $linha('Data do Evento:', dh_data_br($f['data']))
        . $linha('Horário de Início do Evento:', $f['hora_inicio']) . $linha('Horário de Término do Evento:', $f['hora_fim'])
        . $linha('Cardápio:', 'Anexo I')
        . $linha('Valor do Pacote por pessoa (com adicionais caso possua):', dh_moeda($v['por_pessoa']) . ($adic ? ' (inclui ' . implode('; ', $adic) . ')' : ''))
        . $linha('Valor por Convidado Adicional:', $f['valor_adicional'] !== '' ? dh_moeda($f['valor_adicional']) : '')
        . ($f['atracao'] !== '' ? $linha('Atração:', $f['atracao']) : '')
        . $linha('Quantidade de Convidados:', (string) $f['convidados']) . $linha('Qtd. Máxima de Participantes:', (string) $f['max_participantes'])
        . $linha('PREÇO TOTAL (com adicionais caso possua):', dh_moeda($v['total']))
        . $linha('Forma de pagamento:', implode('   ', $pag))
        . $linha('Responsável pelo evento:', $f['responsavel_nome']) . $linha('Telefone do responsável:', $f['responsavel_telefone'])
        . $linha('E-mail do responsável:', $f['responsavel_email']);
    $h .= '</table>';
    $h .= <<<'HTML'
<h2>SEÇÃO III – TERMOS E CONDIÇÕES GERAIS DA PRESTAÇÃO DE SERVIÇOS</h2>
<p>As partes acima, de comum consenso, pactuam o presente instrumento particular, que se regerá pelas cláusulas abaixo:</p>
<h3>I | DO OBJETO DO CONTRATO</h3>
<p>1.1. Constitui objeto do presente instrumento a locação de espaço e a prestação de serviços para realização do evento, nas condições previstas neste Contrato, na Seção II e no Anexo I.</p>
<h3>II | DAS CONDIÇÕES GERAIS DO EVENTO</h3>
<h4>Duração do Evento</h4>
<p>2.1. O evento terá a duração regular descrita na Seção II deste contrato. A extensão do horário poderá ser solicitada pelo CONTRATANTE e concedida mediante prévia consulta e anuência da CONTRATADA no próprio evento, condicionada à disponibilidade operacional e ao pagamento do valor proporcional correspondente às horas adicionais de espaço e serviços, apurado com base no valor hora do contrato.</p>
<h4>Cardápio</h4>
<p>2.2. O cardápio do evento seguirá aquele previsto no Anexo I, previamente ajustado entre as partes.</p>
<h4>Alteração do Layout e Organização do Espaço</h4>
<p>2.3. A alteração no layout do espaço locado e a instalação de equipamento ou acessórios deverá ser precedida de autorização da CONTRATADA.</p>
<p>2.4. É expressamente proibida a fixação de pregos, parafusos, ganchos, fitas ou quaisquer objetos desta natureza nas estruturas, paredes, forros e mobiliário do estabelecimento. O descumprimento desta Cláusula acarretará multa não compensatória no valor de R$ 500 (quinhentos reais), sem prejuízo da cobrança dos valores necessários à reparação e indenização dos danos incorridos.</p>
<p>2.5. O início da montagem das decorações realizadas pelo CONTRATANTE poderá ocorrer conforme horário previamente acordado com a CONTRATADA. Toda readequação do espaço, organização e limpeza deverá ser realizada de modo a não comprometer o funcionamento regular do estabelecimento.</p>
<h4>Da Responsabilidade Civil e Criminal</h4>
<p>2.6. O CONTRATANTE declara estar ciente de que a CONTRATADA não se responsabiliza por objetos pessoais dos participantes do evento deixados nas dependências da CONTRATADA. Recomenda-se que os participantes mantenham consigo seus pertences de valor ou utilizem opções seguras de armazenamento, quando disponíveis.</p>
<p>2.7. A CONTRATADA não se responsabiliza por danos a veículos estacionados em vias públicas durante o evento.</p>
<h4>Cumprimento das Normas de Segurança e Regulamentações</h4>
<p>2.8. O CONTRATANTE compromete-se a seguir integralmente todas as normas de segurança e regulamentações estabelecidas pela CONTRATADA durante o evento.</p>
<p>2.9. Fica expressamente proibido fumar em áreas não permitidas, bem como ultrapassar os limites de barulho estabelecidos pela CONTRATADA ou por leis municipais. O CONTRATANTE compromete-se a reduzir o volume de qualquer som, incluindo música ao vivo, som mecânico ou outros equipamentos sonoros, a partir das 00h00min (meia-noite). O nível de ruído deverá estar em conformidade com as normas locais e as diretrizes da CONTRATADA, sob pena de interrupção das atividades sonoras e aplicação de multas, se necessário.</p>
<h3>III | OBRIGAÇÕES DO CONTRATANTE</h3>
<p>3.1. O CONTRATANTE deverá seguir todas as obrigações previstas nas condições gerais do evento.</p>
<p>3.2. O CONTRATANTE é responsável por todos os danos causados à CONTRATADA e/ou a terceiros durante o evento, sejam esses danos causados por ele, por seus convidados ou por terceiros contratados, incluindo, mas sem se limitar, a objetos ou equipamentos danificados ou extraviados, que são de inteira responsabilidade do CONTRATANTE. O CONTRATANTE deverá pagar a indenização até o décimo dia útil após receber notificação da CONTRATADA, que deverá detalhar os danos e o valor a ser pago.</p>
<p>3.3. A responsabilidade mencionada na cláusula acima inclui multas de órgãos públicos ou indenizações a terceiros cobradas da CONTRATADA por violações legais cometidas pelo CONTRATANTE, seus convidados ou terceiros contratados.</p>
<p>3.4. O CONTRATANTE deve retirar seus materiais, como fotos, bolos e presentes, bem como equipamentos de terceiros, do espaço após o término do evento. Além disso, o CONTRATANTE autoriza previamente o descarte dos materiais não retirados em até 48 (quarenta e oito) horas do evento.</p>
<h3>IV | OBRIGAÇÕES DA CONTRATADA</h3>
<p>4.1. A CONTRATADA deverá disponibilizar ao CONTRATANTE o espaço contratado, na data e horários estabelecidos neste Contrato, em condições adequadas de uso, conservação, funcionamento e segurança para a realização do evento.</p>
<p>4.2. A CONTRATADA deverá prestar integralmente os serviços contratados e disponibilizar os alimentos, bebidas, equipamentos e demais itens previstos neste Contrato e no Anexo I, observadas as condições, quantidades e características acordadas entre as partes.</p>
<p>4.3. A CONTRATADA não poderá suprimir ou reduzir itens do cardápio, bebidas ou serviços contratados sem a prévia e expressa autorização do CONTRATANTE, ficando, contudo, autorizada a substituir itens do cardápio, rótulos de cerveja e demais insumos em razão de sazonalidade, indisponibilidade de estoque ou falha de fornecedor, por itens de qualidade e valor equivalentes ou superiores, comunicando o CONTRATANTE sempre que possível, sem que a substituição configure descumprimento contratual ou dê ensejo a abatimento do preço.</p>
<p>4.4. A CONTRATADA deverá manter, durante todo o evento, pessoal em quantidade suficiente e devidamente capacitado para a adequada prestação dos serviços contratados.</p>
<p>4.5. A CONTRATADA será responsável pela regularidade, qualidade e segurança dos serviços prestados, bem como dos alimentos e bebidas fornecidos no evento, devendo observar a legislação e as normas sanitárias, de segurança e demais disposições aplicáveis à sua atividade e ao estabelecimento.</p>
<p>4.6. A CONTRATADA responderá perante o CONTRATANTE pelos danos diretos comprovadamente decorrentes de atos ou omissões culposos de seus empregados, prepostos e fornecedores por ela contratados na execução do evento.</p>
<p>4.7. A responsabilidade contratual da CONTRATADA, na relação entre as partes, fica limitada ao valor total deste contrato, excluídos danos indiretos e lucros cessantes, ressalvada a hipótese de dolo. Não se incluem na responsabilidade da CONTRATADA os danos decorrentes de atos do CONTRATANTE, de seus convidados ou de terceiros por ele contratados, que permanecem regidos pelas Cláusulas 3.2 e 3.3.</p>
<p>4.8. A CONTRATADA não se responsabiliza por itens pessoais esquecidos no local do evento, como celulares, bolsas e carteiras.</p>
<h3>V | DO VALOR DO CONTRATO</h3>
<p>5.1. O valor total contratado deverá ser pago de acordo com as informações expostas no quadro resumo (Seção II).</p>
<p>5.2. O CONTRATANTE deverá efetuar o pagamento de 50% (cinquenta por cento) do valor total do contrato de forma antecipada, como condição de garantia de reserva da data do evento.</p>
<p>5.3. O atraso no pagamento autoriza a CONTRATADA a suspender o cumprimento de suas obrigações até o saneamento da inadimplência e permite a rescisão por justa causa, com aplicação das penalidades previstas.</p>
<p>5.4. Em caso de atraso no pagamento, o CONTRATANTE deverá pagar à CONTRATADA o valor principal, corrigido pelo IPCA (IBGE), além de multa de 10% e juros de 1% ao mês. Fica acordado que o CONTRATANTE arcará com honorários advocatícios de 20% e, se houver cobrança judicial, com custas processuais e outras despesas. Em caso de atraso ou inadimplência, a CONTRATADA pode registrar o nome do CONTRATANTE em órgãos de proteção ao crédito (como SPC ou SERASA) e protestar a dívida para garantir seu crédito.</p>
<p>5.5. Produtos ou serviços extras que não estejam contemplados no pacote contratado pelo CONTRATANTE serão cobrados de acordo com os preços estabelecidos no cardápio do restaurante e deverão ser quitados ao final do evento.</p>
<h3>VI | DA RESCISÃO</h3>
<p>6.1. O presente contrato poderá ser rescindido por justa causa pela parte inocente, independentemente de notificação ou interpelação judicial ou extrajudicial, respondendo a parte que der causa à rescisão pelas penalidades previstas neste instrumento. Rescindido o contrato por culpa do CONTRATANTE, inclusive por inadimplemento, aplicar-se-ão aos valores pagos as regras da Cláusula 6.3; rescindido por culpa da CONTRATADA, esta restituirá integralmente os valores recebidos.</p>
<p>6.2. Se o evento for cancelado, adiado ou suspenso por caso fortuito, força maior ou fato relativo ao poder público, um novo contrato poderá ser celebrado com base na disponibilidade de datas e preços vigentes, sem prejuízo da devolução integral dos valores caso não haja disponibilidade de qualquer das partes. Se a suspensão ou remarcação do evento ocorrer por culpa do CONTRATANTE, será cobrado um adicional de 15% sobre o valor total do evento como indenização pelos prejuízos.</p>
<p>6.3. O cancelamento imotivado poderá ser solicitado pelo CONTRATANTE, mediante comunicação escrita, com a restituição dos valores pagos deduzida a multa aplicável, sujeitando-o às seguintes penalidades compensatórias:</p>
<ol type="a">
<li>da data de assinatura do Contrato até 21 dias antes da data de realização do evento, com multa de 30% (trinta por cento) sobre o valor total do contrato;</li>
<li>de 20 a 11 dias antes, com multa de 40% (quarenta por cento) sobre o valor total do contrato;</li>
<li>de 10 a 1 dia antes da data de realização do evento, com multa de 50% (cinquenta por cento) sobre o valor total do contrato;</li>
<li>caso o cancelamento seja realizado pelo CONTRATANTE na data do evento, ou em caso de não comparecimento, o CONTRATANTE pagará 100% (cem por cento) do valor total do contrato, compensando-se os valores já pagos.</li>
</ol>
<p>Parágrafo primeiro. A multa aplicável será compensada com os valores pagos pelo CONTRATANTE; caso a multa supere tais valores, a diferença será imediatamente exigível pela CONTRATADA.</p>
<p>Parágrafo segundo. As penalidades desta cláusula aplicam-se exclusivamente ao cancelamento imotivado pelo CONTRATANTE. Ressalvadas as hipóteses de justa causa (Cláusulas 5.3, 6.1 e 6.4) e de caso fortuito ou força maior (Cláusula 6.2), a CONTRATADA somente poderá cancelar imotivadamente o evento mediante aviso prévio de, no mínimo, 30 (trinta) dias, hipótese em que restituirá integralmente os valores recebidos, sem incidência das penalidades previstas nesta cláusula.</p>
<p>6.4. A CONTRATADA pode rescindir motivadamente o contrato se houver desvio de finalidade ou descumprimento do evento contratado, aplicando-se ao CONTRATANTE as penalidades previstas na Cláusula 6.3, conforme a antecedência da rescisão, observado o mínimo de 10% (dez por cento) sobre o valor total do contrato, que será imediatamente exigível, deduzindo-se a penalidade dos valores pagos e não sendo devida a devolução integral dos valores recebidos quando a rescisão decorrer de culpa do CONTRATANTE.</p>
<h3>VII | DAS DISPOSIÇÕES FINAIS</h3>
<p>7.1. Para dirimir quaisquer controvérsias oriundas do presente contrato, as partes elegem o foro da comarca de Fortaleza/CE.</p>
<p>7.2. E assim, por estarem de pleno acordo e ajustadas, as partes assinam o presente instrumento eletronicamente, de livre vontade, conscientes e espontaneamente, reconhecendo a validade e a eficácia da assinatura eletrônica, nos termos do art. 10, § 2º, da Medida Provisória nº 2.200-2/2001 e da Lei nº 14.063/2020, e dispensando a assinatura de testemunhas, conforme o art. 784, § 4º, do Código de Processo Civil.</p>
HTML;
    $h .= '<h2 class="anexo">ANEXO I – MENU</h2><div class="anexo-texto">' . nl2br(e($f['cardapio'])) . '</div>';
    return $h;
}

function dh_contrato_comodato(array $f, callable $linha): string
{
    $h = '<h2>SEÇÃO II – CONDIÇÕES COMERCIAIS</h2><table class="quadro">';
    $cidade = trim($f['cidade'] ?? '');
    $h .= $linha('Endereço de entrega:', $f['entrega_endereco'] . ($cidade !== '' && mb_stripos($f['entrega_endereco'], $cidade) === false ? ' – ' . $cidade : ''))
        . $linha('Entrega dos equipamentos:', !empty($f['entrega_hora_estimada'])
            ? dh_data_br($f['entrega_data']) . ', previsão por volta das ' . $f['entrega_hora'] . ' (horário estimado; o horário exato é combinado diretamente com o entregador)'
            : trim(dh_data_br($f['entrega_data']) . ' ' . ($f['entrega_hora'] !== '' ? 'às ' . $f['entrega_hora'] : '')))
        . $linha('Devolução (coleta) dos equipamentos:', trim(dh_data_br($f['devolucao_data']) . ' ' . ($f['devolucao_hora'] !== '' ? 'às ' . $f['devolucao_hora'] : '')))
        . $linha('Forma de pagamento:', DH_PAGAMENTOS[$f['pagamento']] ?? '');
    if (!empty($f['comprovante'])) {
        $cp = $f['comprovante'];
        $h .= $linha('Comprovante de pagamento:', 'Anexado ' . (($cp['por'] ?? '') === 'donkey' ? 'pela CONTRATADA' : 'pelo CONTRATANTE') . ' em ' . date('d/m/Y \à\s H:i', strtotime($cp['em'])) . ' (arquivo "' . $cp['nome'] . '", código SHA-256 ' . $cp['sha256'] . ')');
    }
    $comprado = array_filter($f['itens'], fn($it) => empty($it['comodato']));
    $emComodato = array_filter($f['itens'], fn($it) => !empty($it['comodato']));
    $temCopos = (int) ($f['copos_pacotes'] ?? 0) > 0;
    $h .= '</table><h3>' . ($temCopos ? 'Chope e copos comprados' : 'Chope comprado') . '</h3><table class="itens"><tr><th>Item</th><th>' . ($temCopos ? 'Valor (litro ou pacote)' : 'Valor do litro') . '</th><th>Quantidade</th><th>Valor total</th></tr>';
    foreach ($comprado as $it) {
        $h .= '<tr><td>' . e($it['descricao']) . '</td><td>' . e(dh_moeda($it['valor_litro'])) . '</td><td>' . e(dh_litros($it['litros'])) . ' L</td><td>'
            . e(dh_moeda((float) $it['litros'] * (float) $it['valor_litro'])) . '</td></tr>';
    }
    if ((float) (($f['desconto'] ?? '') ?: 0) > 0) {
        $h .= '<tr><td colspan="3">Desconto' . (($f['cupom'] ?? '') !== '' ? ' (cupom ' . e($f['cupom']) . ')' : '') . '</td><td>− ' . e(dh_moeda(min(dh_comodato_chope($f), (float) $f['desconto']))) . '</td></tr>';
    }
    if (($pc = (int) ($f['copos_pacotes'] ?? 0)) > 0) {
        $h .= '<tr><td>Copos personalizados (pacote com ' . DH_COPOS['qtd'] . ')</td><td>' . e(dh_moeda(DH_COPOS['valor'])) . ' o pacote</td><td>' . $pc . ' pacote' . ($pc > 1 ? 's' : '')
            . ' (' . $pc * DH_COPOS['qtd'] . ' copos)</td><td>' . e(dh_moeda(dh_comodato_copos($f))) . '</td></tr>';
    }
    $frete = $f['frete'] !== '' && (float) $f['frete'] > 0;
    $h .= '<tr><td colspan="3">Frete (instalação e retirada)</td><td>' . ($frete ? e(dh_moeda($f['frete'])) : 'Incluso') . '</td></tr>';
    $h .= '<tr class="total"><td colspan="3">VALOR TOTAL</td><td>' . e(dh_moeda(dh_comodato_total($f))) . '</td></tr></table>';
    if ($emComodato) {
        $h .= '<h3>Chope em comodato (não incluído no valor total)</h3><table class="itens"><tr><th>Item</th><th>Quantidade</th><th>Valor do litro, se consumido</th></tr>';
        foreach ($emComodato as $it) {
            $h .= '<tr><td>' . e($it['descricao']) . '</td><td>' . e(dh_litros($it['litros'])) . ' L</td><td>' . e(dh_moeda($it['valor_litro'])) . '</td></tr>';
        }
        $h .= '</table>';
    }
    $h .= '<h3>Equipamentos cedidos em comodato (com valor de reposição)</h3><table class="itens"><tr><th>Equipamento</th><th>Quantidade</th><th>Valor de reposição (unidade)</th></tr>';
    foreach ($f['equipamentos'] as $eq) {
        $h .= '<tr><td>' . e($eq['descricao']) . '</td><td>' . (int) $eq['quantidade'] . '</td><td>' . e(dh_moeda($eq['valor'])) . '</td></tr>';
    }
    $h .= '</table>';
    if (trim($f['observacoes']) !== '') {
        $h .= '<p><strong>Observações:</strong> ' . nl2br(e($f['observacoes'])) . '</p>';
    }
    $h .= <<<'HTML'
<h2>SEÇÃO III – CLÁUSULAS</h2>
<h3>I | DO OBJETO</h3>
<p>1.1. Constitui objeto do presente instrumento a venda, pela CONTRATADA ao CONTRATANTE, do chope descrito na Seção II.</p>
<p>1.2. Em razão da compra, a CONTRATADA cede ao CONTRATANTE, em comodato (empréstimo gratuito), os equipamentos descritos na Seção II, pelo período compreendido entre a entrega e a devolução, exclusivamente para o serviço do chope adquirido.</p>
<p>1.3. Havendo chope em comodato descrito na Seção II, ele é entregue junto com o pedido sem custo inicial. Os barris de chope em comodato que forem abertos serão cobrados pelo valor do litro ali indicado, e os barris lacrados serão devolvidos na coleta, sem cobrança.</p>
<h3>II | DO PAGAMENTO</h3>
<p>2.1. O valor total indicado na Seção II será pago na forma ali informada, até a data da entrega, salvo condição diferente registrada nas observações.</p>
<p>2.2. Em caso de atraso no pagamento, o valor devido será corrigido pelo IPCA (IBGE), acrescido de multa de 10% e juros de 1% ao mês.</p>
<!--2.3-->
<h3>III | DA ENTREGA, DO USO E DA CONSERVAÇÃO</h3>
<p>3.1. O CONTRATANTE declara receber os equipamentos em perfeitas condições de uso e obriga-se a conservá-los da mesma forma, sendo responsável por qualquer dano aos equipamentos, independentemente de culpa, fato atípico ou fato natural.</p>
<p>3.2. Os equipamentos devem permanecer no endereço de entrega, sendo vedado ao CONTRATANTE cedê-los, emprestá-los ou sublocá-los a terceiros, bem como abri-los, desmontá-los ou alterar suas características.</p>
<h3>IV | DA DEVOLUÇÃO</h3>
<p>4.1. Os equipamentos e os barris serão coletados pela CONTRATADA no endereço de entrega, na data e no horário de devolução indicados na Seção II, cabendo ao CONTRATANTE garantir o acesso e a presença de um responsável no momento da coleta.</p>
<p>4.2. Os equipamentos devem ser devolvidos, no máximo, no dia seguinte ao evento, exceto quando acordado de forma diferente com a CONTRATADA.</p>
<p>4.3. Não sendo possível a coleta na data combinada por motivo atribuível ao CONTRATANTE, as despesas de nova coleta correrão por conta do CONTRATANTE.</p>
<h3>V | DOS DANOS, DA PERDA E DA NÃO DEVOLUÇÃO</h3>
<p>5.1. Em caso de dano, perda, furto, roubo ou não devolução de qualquer equipamento ou barril, o CONTRATANTE pagará à CONTRATADA o valor de reposição indicado na Seção II, ou o custo do reparo, se este for menor, até o décimo dia útil após receber notificação da CONTRATADA detalhando os danos e o valor devido.</p>
<h3>VI | DO CONSUMO RESPONSÁVEL</h3>
<p>6.1. O CONTRATANTE é responsável pelo serviço da bebida e compromete-se a não fornecer bebida alcoólica a menores de 18 (dezoito) anos, nos termos da lei.</p>
<h3>VII | DO CANCELAMENTO</h3>
<p>7.1. O cancelamento pelo CONTRATANTE deverá ser comunicado por escrito com antecedência mínima de 48 (quarenta e oito) horas da data de entrega. Após esse prazo, poderão ser cobradas as despesas já realizadas pela CONTRATADA, como frete e chope separado para o pedido.</p>
<h3>VIII | DOS DADOS PESSOAIS</h3>
<p>8.1. Os dados pessoais informados neste contrato serão utilizados pela CONTRATADA para a sua execução, cobrança, cumprimento de obrigações legais e para facilitar pedidos futuros do CONTRATANTE e para análises internas de vendas, nos termos da Lei nº 13.709/2018 (LGPD).</p>
<h3>IX | DAS DISPOSIÇÕES FINAIS</h3>
<p>9.1. Para dirimir quaisquer controvérsias oriundas do presente contrato, as partes elegem o foro da comarca de Fortaleza/CE.</p>
<p>9.2. E assim, por estarem de pleno acordo e ajustadas, as partes assinam o presente instrumento eletronicamente, de livre vontade, reconhecendo a validade e a eficácia da assinatura eletrônica, nos termos do art. 10, § 2º, da Medida Provisória nº 2.200-2/2001 e da Lei nº 14.063/2020, e dispensando a assinatura de testemunhas, conforme o art. 784, § 4º, do Código de Processo Civil.</p>
HTML;
    // O comprovante anexado faz parte do contrato
    if (!empty($f['comprovante'])) {
        $h = str_replace('<!--2.3-->', '<p>2.3. O comprovante de pagamento identificado na Seção II integra este contrato.</p>', $h);
    }
    return str_replace("<!--2.3-->\n", '', $h);
}

// Relatório de assinaturas (vai junto do documento, como o da ZapSign)
function dh_contrato_relatorio(array $k): string
{
    $h = '<section class="relatorio"><h2>Relatório de assinaturas</h2>';
    $h .= '<table class="quadro"><tr><th>Documento</th><td>Contrato nº ' . e($k['numero']) . ' · ' . e(DH_CONTRATO_TIPOS[$k['tipo']]) . '</td></tr>';
    $h .= '<tr><th>Situação</th><td>' . e(DH_CONTRATO_STATUS[$k['status']]) . '</td></tr>';
    $h .= '<tr><th>Código de integridade (SHA-256 do documento)</th><td class="hash">' . e($k['hash']) . '</td></tr>';
    $h .= '<tr><th>Gerado em</th><td>' . e(date('d/m/Y H:i:s', strtotime($k['enviado_em']))) . ' (horário de Fortaleza)</td></tr></table>';
    foreach (['contratada' => 'CONTRATADA', 'contratante' => 'CONTRATANTE'] as $parte => $rot) {
        $a = $k['assinaturas'][$parte] ?? null;
        $h .= '<div class="assinatura' . ($a ? ' feita' : '') . '"><p class="parte">' . $rot . '</p>';
        if (!$a) {
            $h .= '<p>Aguardando assinatura.</p></div>';
            continue;
        }
        $h .= '<p class="nome">' . e($a['nome']) . ' · CPF ' . e(dh_cpf_mascara($a['cpf'])) . '</p>';
        $h .= '<p>Assinado eletronicamente em ' . e(date('d/m/Y \à\s H:i:s', strtotime($a['em']))) . ' (horário de Fortaleza)</p>';
        $h .= '<p>Autenticação: ' . e($a['metodo']) . '</p>';
        $h .= '<p>IP: ' . e($a['ip']) . ($a['geo'] !== '' ? ' · Localização aproximada: ' . e($a['geo']) : '') . '</p>';
        $h .= '<p class="agente">Dispositivo: ' . e($a['agente']) . '</p></div>';
    }
    $h .= '<p class="legal">Assinaturas eletrônicas com validade jurídica conforme a MP 2.200-2/2001 (art. 10, § 2º) e a Lei 14.063/2020. '
        . 'Confira a autenticidade em ' . e(dh_url_site()) . 'contrato/verificar.php informando o número do contrato ou o código de integridade.</p></section>';
    return $h;
}

// Congela o texto do contrato: a partir daqui ele não muda mais
function dh_contrato_congelar(array &$k, array $config): void
{
    $k['enviado_em'] = date('Y-m-d H:i:s');
    $k['documento'] = dh_contrato_documento($k, $config);
    $k['hash'] = hash('sha256', $k['documento']);
    $k['status'] = 'enviado';
}

function dh_contrato_assinatura(string $nome, string $cpf, string $email, string $metodo, string $geo = ''): array
{
    return [
        'nome' => $nome,
        'cpf' => dh_so_digitos($cpf),
        'email' => $email,
        'metodo' => $metodo,
        'em' => date('Y-m-d H:i:s'),
        'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        'agente' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300),
        'geo' => preg_match('/^-?\d{1,3}\.\d{2,7},\s?-?\d{1,3}\.\d{2,7}$/', $geo) ? $geo : '',
    ];
}

// Depois de cada assinatura: se as duas partes assinaram, fecha o contrato e manda as cópias
function dh_contrato_concluir(array &$k): bool
{
    if ($k['status'] !== 'enviado' || empty($k['assinaturas']['contratada']) || empty($k['assinaturas']['contratante'])) {
        return false;
    }
    $k['status'] = 'assinado';
    $k['concluido_em'] = date('Y-m-d H:i:s');
    dh_contrato_enviar_copia($k, array_merge([$k['assinaturas']['contratante']['email']], dh_emails_equipe()));
    return true;
}

// Manda a cópia do contrato assinado; devolve os e-mails que falharam (e => motivo)
function dh_contrato_enviar_copia(array $k, array $para): array
{
    $link = dh_url_site() . 'contrato/?t=' . $k['token'];
    // O contrato vai em PDF anexo; se o PDF não puder ser gerado, o texto completo vai no corpo do e-mail
    try {
        $anexos = [['nome' => dh_contrato_pdf_nome($k), 'tipo' => 'application/pdf', 'dados' => dh_contrato_pdf($k)]];
    } catch (\Throwable $ex) {
        $anexos = [];
    }
    $texto = '<p>O contrato nº <b>' . e($k['numero']) . '</b> (' . e(DH_CONTRATO_TIPOS[$k['tipo']]) . ') foi assinado pelas duas partes.</p>'
        . ($anexos ? '<p>📎 <b>O contrato assinado segue em PDF, anexo a este e-mail</b>, com o relatório de assinaturas.</p>' : '')
        . '<p>Código de integridade: <span style="font-family:monospace;font-size:12px">' . e($k['hash']) . '</span></p>'
        . '<p><a href="' . e($link) . '">Abrir o contrato assinado</a> (a página também confere a autenticidade).</p>';
    $falhas = [];
    foreach (array_unique(array_filter($para)) as $email) {
        if (!dh_email($email, 'Contrato assinado nº ' . $k['numero'] . ' · Donkey Head Cervejaria', $texto . ($anexos ? '' : '<hr>' . $k['documento'] . dh_contrato_relatorio($k)), $erro, $anexos)) {
            $falhas[$email] = $erro;
        }
    }
    return $falhas;
}

// Quem recebe os avisos e as cópias dos contratos: o e-mail da Donkey + os extras das Configurações
function dh_emails_equipe(): array
{
    return array_values(array_unique([DH_EMPRESA['email'], ...(array) (dh_contratos()['config']['emails_copia'] ?? [])]));
}

// Avisos do andamento (pedido preenchido, cliente assinou): só para o e-mail da Donkey.
// A lista de cópia recebe apenas o contrato final, assinado pelas duas partes.
function dh_email_equipe(string $assunto, string $html): void
{
    dh_email(DH_EMPRESA['email'], $assunto, $html);
}

// Lista de e-mails digitada no painel (um por linha, ou separados por vírgula)
function dh_emails_lista(string $texto): array
{
    $lista = [];
    foreach (preg_split('/[\s,;]+/', mb_strtolower(trim($texto))) as $email) {
        if ($email === '') {
            continue;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('E-mail inválido: ' . $email);
        }
        $lista[] = $email;
    }
    return array_values(array_unique($lista));
}

// E-mail pelo SMTP autenticado da Locaweb (conta contato@). A senha é digitada no painel
// (Contratos → Configurações) e fica só no servidor, em dados/email.json. Sem senha, tenta o mail() do PHP.
const DH_ARQ_EMAIL = DH_RAIZ . '/dados/email.json';
const DH_ARQ_EMAIL_LOG = DH_RAIZ . '/dados/emails-log.json';
const DH_SMTP_HOST = 'email-ssl.com.br';
const DH_SMTP_PORTA = 587;

function dh_email_config(): array
{
    $c = json_decode((string) @file_get_contents(DH_ARQ_EMAIL), true);
    return is_array($c) ? $c : [];
}

function dh_email_config_salvar(array $c): void
{
    if (@file_put_contents(DH_ARQ_EMAIL, json_encode($c, JSON_PRETTY_PRINT), LOCK_EX) === false) {
        throw new RuntimeException('Não foi possível gravar a configuração de e-mail.');
    }
    @chmod(DH_ARQ_EMAIL, 0600);
}

// Últimos envios (para conferir se saiu e ver o erro, quando houver)
function dh_email_log(): array
{
    $l = json_decode((string) @file_get_contents(DH_ARQ_EMAIL_LOG), true);
    return is_array($l) ? $l : [];
}

function dh_email_registrar(string $para, string $assunto, string $via, bool $ok, string $erro): void
{
    $l = dh_email_log();
    array_unshift($l, ['em' => date('Y-m-d H:i:s'), 'para' => $para, 'assunto' => mb_substr($assunto, 0, 120), 'via' => $via, 'ok' => $ok, 'erro' => mb_substr($erro, 0, 300)]);
    @file_put_contents(DH_ARQ_EMAIL_LOG, json_encode(array_slice($l, 0, 100), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

// Conversa SMTP mínima: STARTTLS + AUTH LOGIN. Devolve '' se deu certo, ou a mensagem de erro.
function dh_smtp_enviar(string $usuario, string $senha, string $para, string $mensagem): string
{
    // DH_SMTP_TESTE (só nos testes locais): servidor falso, com certificado próprio
    $teste = (string) getenv('DH_SMTP_TESTE');
    $alvo = $teste !== '' ? $teste : DH_SMTP_HOST . ':' . DH_SMTP_PORTA;
    $ctx = stream_context_create(['ssl' => $teste !== '' ? ['verify_peer' => false, 'verify_peer_name' => false] : ['peer_name' => DH_SMTP_HOST]]);
    $s = @stream_socket_client('tcp://' . $alvo, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$s) {
        return 'Não conectou em ' . $alvo . " ($errstr)";
    }
    stream_set_timeout($s, 20);
    $ler = function () use ($s): string {
        $r = '';
        while (($linha = fgets($s, 1024)) !== false) {
            $r .= $linha;
            if (strlen($linha) < 4 || $linha[3] === ' ') {
                break;
            }
        }
        return $r;
    };
    $cmd = function (?string $c, string $espera) use ($s, $ler): void {
        if ($c !== null) {
            fwrite($s, $c . "\r\n");
        }
        $r = $ler();
        if (strpos($r, $espera) !== 0) {
            throw new RuntimeException(trim($r) ?: 'o servidor não respondeu');
        }
    };
    try {
        $cmd(null, '220');
        $cmd('EHLO donkeyheadcervejaria.com.br', '250');
        $cmd('STARTTLS', '220');
        if (!@stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
            throw new RuntimeException('falha ao ativar TLS');
        }
        $cmd('EHLO donkeyheadcervejaria.com.br', '250');
        $cmd('AUTH LOGIN', '334');
        $cmd(base64_encode($usuario), '334');
        try {
            $cmd(base64_encode($senha), '235');
        } catch (RuntimeException $e) {
            throw new RuntimeException('Usuário ou senha do e-mail recusados (' . $e->getMessage() . ')');
        }
        $cmd('MAIL FROM:<' . $usuario . '>', '250');
        $cmd('RCPT TO:<' . $para . '>', '25');
        $cmd('DATA', '354');
        // Linhas que começam com ponto ganham outro ponto (regra do SMTP)
        $cmd(preg_replace('/^\./m', '..', $mensagem) . "\r\n.", '250');
        fwrite($s, "QUIT\r\n");
        return '';
    } catch (RuntimeException $e) {
        return $e->getMessage();
    } finally {
        fclose($s);
    }
}

// $anexos: [['nome' => 'arquivo.pdf', 'tipo' => 'application/pdf', 'dados' => '...'], ...]
function dh_email(string $para, string $assunto, string $html, ?string &$erro = null, array $anexos = []): bool
{
    $erro = '';
    if (!filter_var($para, FILTER_VALIDATE_EMAIL)) {
        $erro = 'E-mail do destinatário inválido.';
        return false;
    }
    $de = DH_EMPRESA['email'];
    $corpo = '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#222;line-height:1.5;max-width:720px">'
        . '<p><img src="' . e(dh_url_site()) . 'assets/img/logo-donkey-head.png" alt="Donkey Head Cervejaria" width="70"></p>' . $html
        . '<p style="color:#777;font-size:12px;margin-top:24px">Donkey Head Cervejaria · ' . e(DH_EMPRESA['endereco']) . ' · ' . e(DH_EMPRESA['telefone']) . '</p></body></html>';
    $cab = [
        'MIME-Version: 1.0',
        'From: =?UTF-8?B?' . base64_encode('Donkey Head Cervejaria') . '?= <' . $de . '>',
        'Reply-To: ' . $de,
    ];
    $htmlCod = rtrim(chunk_split(base64_encode($corpo)));
    if (!$anexos) {
        $cab[] = 'Content-Type: text/html; charset=UTF-8';
        $cab[] = 'Content-Transfer-Encoding: base64';
        $corpoCod = $htmlCod;
    } else {
        // Com anexo: mensagem em partes (o texto em HTML + cada arquivo)
        $b = 'dh-' . bin2hex(random_bytes(12));
        $cab[] = 'Content-Type: multipart/mixed; boundary="' . $b . '"';
        $corpoCod = "--$b\nContent-Type: text/html; charset=UTF-8\nContent-Transfer-Encoding: base64\n\n" . $htmlCod . "\n";
        foreach ($anexos as $a) {
            $nome = str_replace('"', '', $a['nome']);
            $corpoCod .= "--$b\nContent-Type: " . $a['tipo'] . '; name="' . $nome . "\"\nContent-Transfer-Encoding: base64\nContent-Disposition: attachment; filename=\"" . $nome . "\"\n\n"
                . rtrim(chunk_split(base64_encode($a['dados']))) . "\n";
        }
        $corpoCod .= "--$b--";
    }
    $assuntoCod = '=?UTF-8?B?' . base64_encode($assunto) . '?=';
    if (getenv('DH_EMAIL_TESTE')) {
        // Ambiente de teste: grava o e-mail num arquivo em vez de enviar (e os anexos ao lado)
        foreach ($anexos as $a) {
            @file_put_contents(dirname(getenv('DH_EMAIL_TESTE')) . '/' . basename($a['nome']), $a['dados']);
        }
        @file_put_contents(getenv('DH_EMAIL_TESTE'), "PARA: $para\nASSUNTO: $assunto\nANEXOS: " . implode(', ', array_column($anexos, 'nome')) . "\n$html\n----\n", FILE_APPEND);
        return true;
    }
    $senha = (string) (dh_email_config()['senha'] ?? '');
    if ($senha !== '') {
        $msg = implode("\r\n", array_merge(['Date: ' . date('r'), 'To: <' . $para . '>', 'Subject: ' . $assuntoCod,
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@donkeyheadcervejaria.com.br>'], $cab)) . "\r\n\r\n" . str_replace(["\r\n", "\n"], ["\n", "\r\n"], $corpoCod);
        $erro = dh_smtp_enviar($de, $senha, $para, $msg);
        dh_email_registrar($para, $assunto . ($anexos ? ' (com PDF)' : ''), 'SMTP', $erro === '', $erro);
        return $erro === '';
    }
    $ok = @mail($para, $assuntoCod, $corpoCod, implode("\r\n", $cab), '-f' . $de);
    $erro = $ok ? '' : 'O servidor recusou o envio pelo mail() do PHP. Cadastre a senha do e-mail em Contratos → Configurações.';
    dh_email_registrar($para, $assunto . ($anexos ? ' (com PDF)' : ''), 'mail() do PHP (sem senha)', $ok, $erro);
    return $ok;
}

// ---- PDF do contrato (Dompdf, em inc/vendor; instalado pelo composer.json da raiz do repositório) ----

function dh_contrato_pdf_nome(array $k): string
{
    return 'Contrato-' . preg_replace('/[^A-Za-z0-9-]/', '', $k['numero']) . '-Donkey-Head-Cervejaria.pdf';
}

// O mesmo conteúdo da página do contrato: timbre, texto, assinaturas, rodapé e relatório de assinaturas
function dh_contrato_pdf(array $k): string
{
    require_once __DIR__ . '/vendor/autoload.php';
    $cache = DH_RAIZ . '/dados/cache-pdf';
    if (!is_dir($cache)) {
        @mkdir($cache, 0755, true);
    }
    $op = new \Dompdf\Options();
    $op->setDefaultFont('DejaVu Sans');
    $op->setIsRemoteEnabled(false);
    $op->setChroot([realpath(DH_RAIZ)]);
    $op->setFontCache($cache);
    $op->setTempDir($cache);
    $assinam = '';
    foreach (['contratada' => 'CONTRATADA', 'contratante' => 'CONTRATANTE'] as $parte => $rot) {
        $a = $k['assinaturas'][$parte] ?? null;
        $assinam .= '<td><div class="traco">' . ($a ? 'Assinado eletronicamente por ' . e($a['nome']) . '<br>em ' . e(date('d/m/Y H:i', strtotime($a['em']))) : '&nbsp;') . '</div><strong>' . $rot . '</strong></td>';
    }
    $css = <<<'CSS'
@page { margin: 18mm 16mm 20mm; }
body { font-family: "DejaVu Sans", sans-serif; font-size: 9.5pt; line-height: 1.45; color: #1f1a16; }
.timbre { border-bottom: 2px solid #1f1a16; padding-bottom: 6px; margin-bottom: 12px; font-size: 8pt; color: #5f564e; }
.timbre img { width: 34px; vertical-align: middle; margin-right: 8px; }
h1 { font-size: 13pt; text-align: center; margin: 4px 0; }
.numero { text-align: center; color: #5f564e; margin: 0 0 10px; }
h2 { font-size: 10pt; margin: 14px 0 6px; padding: 4px 8px; background: #f3ede6; border-left: 3px solid #e9a23b; }
h3 { font-size: 9.5pt; margin: 10px 0 4px; }
p { margin: 0 0 6px; text-align: justify; }
table.quadro, table.itens { width: 100%; border-collapse: collapse; margin: 0 0 8px; }
.quadro th, .quadro td, .itens th, .itens td { border: 1px solid #d9cfc4; padding: 4px 6px; vertical-align: top; text-align: left; font-size: 8.5pt; }
.quadro th { width: 38%; background: #faf7f3; }
.itens th { background: #faf7f3; }
.itens .total td { font-weight: bold; background: #fff7e8; }
.hash { font-size: 7pt; word-wrap: break-word; }
.local-data { margin-top: 14px; }
table.assinam { width: 100%; margin-top: 28px; }
table.assinam td { width: 50%; padding: 0 14px; text-align: center; vertical-align: bottom; }
.traco { border-top: 1px solid #1f1a16; padding-top: 4px; min-height: 28px; font-size: 8pt; color: #5f564e; }
.rodape-doc { margin-top: 18px; padding-top: 6px; border-top: 1px solid #d9cfc4; font-size: 7pt; color: #5f564e; }
.relatorio { page-break-before: always; }
.assinatura { border: 1px solid #d9cfc4; padding: 8px 10px; margin: 8px 0; page-break-inside: avoid; }
.assinatura.feita { border-color: #2e9d4a; }
.assinatura p { margin: 0 0 2px; text-align: left; }
.assinatura .parte { font-weight: bold; color: #5f564e; font-size: 8pt; }
.assinatura .nome { font-weight: bold; }
.assinatura .agente { color: #5f564e; font-size: 7pt; }
.legal { font-size: 7.5pt; color: #5f564e; }
CSS;
    $html = '<!doctype html><html><head><meta charset="utf-8"><style>' . $css . '</style></head><body>'
        . '<div class="timbre"><img src="' . e(realpath(DH_RAIZ . '/assets/img/logo-donkey-head.png')) . '">' . e(DH_EMPRESA['razao']) . ' · CNPJ ' . e(DH_EMPRESA['cnpj']) . '</div>'
        . $k['documento']
        . '<table class="assinam"><tr>' . $assinam . '</tr></table>'
        . '<div class="rodape-doc">' . e(DH_EMPRESA['razao']) . ' · CNPJ ' . e(DH_EMPRESA['cnpj']) . ' · ' . e(DH_EMPRESA['endereco']) . ' · ' . e(DH_EMPRESA['telefone'])
        . '<br>Contrato nº ' . e($k['numero']) . ' · Código de integridade: <span class="hash">' . e($k['hash']) . '</span></div>'
        . dh_contrato_relatorio($k)
        . '</body></html>';
    $pdf = new \Dompdf\Dompdf($op);
    $pdf->loadHtml($html, 'UTF-8');
    $pdf->setPaper('A4');
    $pdf->render();
    $fonte = $pdf->getFontMetrics()->getFont('DejaVu Sans');
    $pdf->getCanvas()->page_text(42, 815, 'Contrato nº ' . $k['numero'] . ' · Donkey Head Cervejaria · página {PAGE_NUM} de {PAGE_COUNT}', $fonte, 7, [0.37, 0.34, 0.31]);
    return (string) $pdf->output();
}

function dh_contrato_campos_padrao(string $tipo): array
{
    $base = ['contratante_nome' => '', 'contratante_doc' => '', 'contratante_endereco' => '', 'contratante_telefone' => '', 'contratante_email' => '', 'pagamento' => 'pix'];
    if ($tipo === 'evento') {
        return $base + ['espaco' => '', 'data' => '', 'hora_inicio' => '', 'hora_fim' => '', 'valor_pessoa' => '', 'valor_adicional' => '', 'atracao' => 'Banda de POP Rock Nacional',
            'convidados' => '', 'max_participantes' => '', 'adicionais' => [], 'total' => '', 'responsavel_nome' => '', 'responsavel_telefone' => '', 'responsavel_email' => '',
            'cardapio' => DH_ANEXO_EVENTO];
    }
    return $base + ['entrega_endereco' => '', 'cidade' => '', 'entrega_data' => '', 'entrega_hora' => '', 'devolucao_data' => '', 'devolucao_hora' => '', 'frete' => '', 'observacoes' => '',
        'itens' => [['descricao' => 'DH Premium Lager', 'litros' => '50', 'valor_litro' => '13']],
        'equipamentos' => array_map(fn($eq) => ['descricao' => $eq[0], 'valor' => (string) $eq[1], 'quantidade' => 1], DH_EQUIPAMENTOS)];
}

// O que falta para poder enviar
function dh_contrato_pendencias(array $k): array
{
    $f = $k['campos'];
    $faltam = [];
    if ($f['contratante_nome'] === '') {
        $faltam[] = 'nome do contratante';
    }
    if (!dh_cpf_valido($f['contratante_doc']) && !dh_cnpj_valido($f['contratante_doc'])) {
        $faltam[] = 'CPF ou CNPJ válido do contratante';
    }
    if (!filter_var($f['contratante_email'], FILTER_VALIDATE_EMAIL)) {
        $faltam[] = 'e-mail do contratante (é para onde vai o código de assinatura)';
    }
    if ($f['contratante_telefone'] === '') {
        $faltam[] = 'telefone do contratante';
    }
    if ($f['pagamento'] === '') {
        $faltam[] = 'forma de pagamento';
    }
    if ($k['tipo'] === 'evento') {
        foreach (['espaco' => 'espaço', 'data' => 'data do evento', 'hora_inicio' => 'horário de início', 'hora_fim' => 'horário de término', 'valor_pessoa' => 'valor por pessoa', 'convidados' => 'quantidade de convidados'] as $c => $rot) {
            if ($f[$c] === '') {
                $faltam[] = $rot;
            }
        }
    } else {
        foreach (['entrega_endereco' => 'endereço de entrega', 'entrega_data' => 'data de entrega', 'devolucao_data' => 'data de devolução'] as $c => $rot) {
            if ($f[$c] === '') {
                $faltam[] = $rot;
            }
        }
        if (!$f['itens']) {
            $faltam[] = 'ao menos um item de chope';
        }
        if (!$f['equipamentos']) {
            $faltam[] = 'ao menos um equipamento';
        }
    }
    return $faltam;
}
