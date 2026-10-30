<?php
// Orçamentos (propostas) enviados antes do contrato. Painel → Orçamentos; o cliente vê pelo link
// /orcamento/?t=..., baixa o PDF e pode aprovar. Dados em dados/orcamentos.json (só no servidor).

const DH_ARQ_ORCAMENTOS = DH_RAIZ . '/dados/orcamentos.json';
const DH_ORCAMENTO_STATUS = ['rascunho' => 'Em aberto', 'aprovado' => 'Aprovado pelo cliente', 'recusado' => 'Recusado', 'contrato' => 'Virou contrato'];
const DH_ORCAMENTO_PADRAO = [
    'validade' => 15,
    // Dados bancários: preenchidos no painel (⚙ Padrões do orçamento); ficam só no servidor
    'banco' => '',
];

function dh_orcamentos(): array
{
    $o = json_decode((string) @file_get_contents(DH_ARQ_ORCAMENTOS), true);
    $o = is_array($o) ? $o : [];
    $o += ['orcamentos' => [], 'config' => [], 'seq' => []];
    $o['config'] += DH_ORCAMENTO_PADRAO;
    return $o;
}

function dh_orcamentos_salvar(array $o): void
{
    if (@file_put_contents(DH_ARQ_ORCAMENTOS, json_encode($o, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
        throw new RuntimeException('Não foi possível gravar o orçamento. Confira a permissão da pasta dados.');
    }
}

function dh_orcamento_indice(array $o, string $id): ?int
{
    foreach ($o['orcamentos'] as $i => $x) {
        if ($x['id'] === $id) {
            return $i;
        }
    }
    return null;
}

function dh_orcamento_por_token(array $o, string $token): ?int
{
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        return null;
    }
    foreach ($o['orcamentos'] as $i => $x) {
        if (hash_equals($x['token'], $token)) {
            return $i;
        }
    }
    return null;
}

// ORC-2026-001, ORC-2026-002…
function dh_orcamento_numero(array &$o): string
{
    $ano = date('Y');
    $o['seq'][$ano] = ($o['seq'][$ano] ?? 0) + 1;
    return sprintf('ORC-%s-%03d', $ano, $o['seq'][$ano]);
}

// Total do item: o informado ou valor unitário × a quantidade ("300L" conta como 300)
function dh_orcamento_item_total(array $it): float
{
    if (($it['total'] ?? '') !== '') {
        return (float) $it['total'];
    }
    $qtd = (float) str_replace(',', '.', preg_replace('/[^\d,.]/', '', (string) $it['quant']));
    return round((float) ($it['valor'] ?: 0) * $qtd, 2);
}

// Pagamento dos orçamentos: sempre Pix, antecipado
const DH_ORCAMENTO_PAGAMENTO = 'Pix, antecipado';

function dh_orcamento_subtotal(array $k): float
{
    return array_sum(array_map('dh_orcamento_item_total', $k['itens']));
}

// Desconto digitado em R$, abatido no fim (nunca maior que o subtotal)
function dh_orcamento_desconto(array $k): float
{
    return min(dh_orcamento_subtotal($k), max(0, (float) (($k['desconto'] ?? '') ?: 0)));
}

function dh_orcamento_total(array $k): float
{
    return dh_orcamento_subtotal($k) - dh_orcamento_desconto($k);
}

function dh_orcamento_validade(array $k): string
{
    return date('Y-m-d', strtotime(substr($k['emitido_em'] ?: $k['criado_em'], 0, 10) . ' +' . (int) $k['validade'] . ' days'));
}

// Sugestões de itens no formulário: chope da tabela do delivery, frete, copos e tirador
function dh_orcamento_sugestoes(): array
{
    $s = [];
    foreach (dh_cervejas_evento() as $c) {
        $s[] = ['descricao' => 'BARRIL CHOPE ' . mb_strtoupper($c['nome']), 'detalhe' => 'inclui locação/instalação/retirada da chopeira', 'valor' => $c['preco_evento'], 'unidade' => 'L'];
    }
    $s[] = ['descricao' => 'TIRADOR DE CHOPE FREELANCER', 'detalhe' => '', 'valor' => '', 'unidade' => ''];
    $s[] = ['descricao' => 'COPOS PERSONALIZADOS (PACOTE COM ' . DH_COPOS['qtd'] . ')', 'detalhe' => '', 'valor' => number_format(DH_COPOS['valor'], 2, '.', ''), 'unidade' => ''];
    $s[] = ['descricao' => 'FRETE FORTALEZA', 'detalhe' => 'instalação e retirada', 'valor' => number_format(DH_FRETE_FORTALEZA, 2, '.', ''), 'unidade' => ''];
    return $s;
}

// Proposta em HTML (a mesma para a página do cliente e para o PDF), no visual do contrato:
// timbre, título com número, quadros de identificação, tabela de itens e assinatura.
function dh_orcamento_html(array $k, string $raiz = ''): string
{
    $c = $k['cliente'];
    $linha = fn($rot, $val) => '<tr><th>' . e($rot) . '</th><td>' . ($val === '' ? '&nbsp;' : e($val)) . '</td></tr>';
    $emitido = $k['emitido_em'] ?: $k['criado_em'];
    $h = '<div class="orc">';
    $h .= '<div class="timbre"><img src="' . e($raiz) . 'assets/img/logo-donkey-head.png" alt="Donkey Head Cervejaria"><span>' . e(DH_EMPRESA['razao']) . '<br>CNPJ ' . e(DH_EMPRESA['cnpj']) . '</span></div>';
    $h .= '<h1>PROPOSTA COMERCIAL</h1><p class="numero">Orçamento nº ' . e($k['numero']) . ' · emitido em ' . e(dh_data_br(substr($emitido, 0, 10))) . '</p>';
    // Só nome e CPF/CNPJ do cliente (e a data do evento, se houver); tudo opcional
    $dados = ($c['nome'] !== '' ? $linha('PARA:', $c['nome']) : '') . ($c['doc'] !== '' ? $linha('CPF/CNPJ:', dh_cpf_formatar($c['doc'])) : '')
        . ($k['data_evento'] !== '' ? $linha('DATA DO EVENTO:', dh_data_br($k['data_evento'])) : '');
    if ($dados !== '') {
        $h .= '<h2>DADOS DA PROPOSTA</h2><table class="quadro">' . $dados . '</table>';
    }
    $h .= '<h2>ITENS</h2><table class="itens"><tr><th class="n">#</th><th>Descrição</th><th class="num vu">Valor un.</th><th class="num">Quant.</th><th class="num">Total</th></tr>';
    foreach (array_values($k['itens']) as $n => $it) {
        $h .= '<tr><td class="n">' . ($n + 1) . '</td><td><strong>' . e($it['descricao']) . '</strong>' . ($it['detalhe'] !== '' ? '<br><small>' . e($it['detalhe']) . '</small>' : '') . '</td>'
            . '<td class="num vu">' . ($it['valor'] !== '' ? e(dh_moeda($it['valor'])) : '') . '</td><td class="num">' . e($it['quant']) . '</td><td class="num">' . e(dh_moeda(dh_orcamento_item_total($it))) . '</td></tr>';
    }
    if (dh_orcamento_desconto($k) > 0) {
        $h .= '<tr><td class="n"></td><td>Subtotal</td><td class="vu"></td><td></td><td class="num">' . e(dh_moeda(dh_orcamento_subtotal($k))) . '</td></tr>'
            . '<tr><td class="n"></td><td>Desconto</td><td class="vu"></td><td></td><td class="num">− ' . e(dh_moeda(dh_orcamento_desconto($k))) . '</td></tr>';
    }
    $h .= '<tr class="total"><td class="n"></td><td>VALOR TOTAL</td><td class="vu"></td><td></td><td class="num">' . e(dh_moeda(dh_orcamento_total($k))) . '</td></tr></table>';
    if (trim($k['observacoes']) !== '') {
        $h .= '<p><strong>Observações:</strong> ' . nl2br(e($k['observacoes'])) . '</p>';
    }
    $h .= '<h2>CONDIÇÕES</h2><table class="quadro">' . $linha('PAGAMENTO:', DH_ORCAMENTO_PAGAMENTO)
        . $linha('VALIDADE:', (int) $k['validade'] . ' dias (até ' . dh_data_br(dh_orcamento_validade($k)) . ')') . '</table>';
    // Dados bancários e assinatura lado a lado (no celular, um embaixo do outro)
    $h .= '<table class="fim"><tr><td class="fim-banco">' . (trim($k['banco']) !== '' ? '<h3>Dados bancários</h3><p class="banco">' . nl2br(e($k['banco'])) . '</p>' : '') . '</td>'
        . '<td class="fim-assina"><p class="local-data">Fortaleza/CE, ' . e(dh_data_extenso(substr($emitido, 0, 10))) . '.</p>'
        . '<div class="assina"><p class="traco">' . e($k['assinante'] ?? '') . '</p><p><strong>' . e(DH_EMPRESA['razao']) . '</strong></p></div></td></tr></table>';
    $h .= '<p class="rodape-doc">' . e(DH_EMPRESA['razao']) . ' · CNPJ ' . e(DH_EMPRESA['cnpj']) . ' · ' . e(DH_EMPRESA['endereco']) . ' · ' . e(DH_EMPRESA['telefone']) . ' · ' . e(DH_EMPRESA['email']) . '</p>';
    return $h . '</div>';
}

// Estilo da proposta (o mesmo do contrato). A página do cliente já carrega contrato.css; o PDF usa isto.
const DH_ORCAMENTO_CSS = <<<'CSS'
.orc { font-family: "DejaVu Sans", Arial, sans-serif; color: #1f1a16; font-size: 9.5pt; line-height: 1.45; }
.orc .timbre { border-bottom: 2px solid #1f1a16; padding-bottom: 6px; margin-bottom: 12px; font-size: 8pt; color: #5f564e; }
.orc .timbre img { width: 34px; vertical-align: middle; margin-right: 8px; }
.orc .timbre span { display: inline-block; vertical-align: middle; }
.orc h1 { font-size: 14pt; text-align: center; margin: 4px 0; }
.orc .numero { text-align: center; color: #5f564e; margin: 0 0 10px; }
.orc h2 { font-size: 10pt; margin: 10px 0 5px; padding: 4px 8px; background: #f3ede6; border-left: 3px solid #e9a23b; }
.orc h3 { font-size: 9.5pt; margin: 10px 0 4px; }
.orc p { margin: 0 0 6px; }
.orc table.quadro, .orc table.itens { width: 100%; border-collapse: collapse; margin: 0 0 8px; }
.orc .quadro th, .orc .quadro td, .orc .itens th, .orc .itens td { border: 1px solid #d9cfc4; padding: 4px 7px; vertical-align: top; text-align: left; font-size: 8.8pt; }
.orc .quadro th { width: 32%; background: #faf7f3; }
.orc .itens th { background: #faf7f3; }
.orc .itens .n { width: 20px; text-align: center; }
.orc .itens .num { text-align: right; white-space: nowrap; }
.orc .itens small { color: #5f564e; font-size: 7.8pt; }
.orc .itens .total td { font-weight: bold; background: #fff7e8; }
.orc .banco { font-size: 8.8pt; }
.orc .local-data { margin-top: 10px; }
.orc table.fim { width: 100%; border-collapse: collapse; margin-top: 8px; }
.orc .fim td { vertical-align: top; padding: 0; }
.orc .fim-banco { width: 46%; padding-right: 12px; }
.orc .fim-assina { text-align: center; }
.orc .assina { width: 90%; margin: 30px auto 0; text-align: center; }
.orc .assina p { text-align: center; }
.orc .assina .traco { border-top: 1px solid #1f1a16; padding-top: 4px; min-height: 14px; margin: 0 0 2px; font-weight: bold; }
.orc .rodape-doc { margin-top: 12px; padding-top: 6px; border-top: 1px solid #d9cfc4; font-size: 7pt; color: #5f564e; }
CSS;

function dh_orcamento_pdf_nome(array $k): string
{
    return 'Orcamento-' . preg_replace('/[^A-Za-z0-9-]/', '', $k['numero']) . '-Donkey-Head-Cervejaria.pdf';
}

function dh_orcamento_pdf(array $k): string
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
    $raiz = realpath(DH_RAIZ) . '/';
    $html = '<!doctype html><html><head><meta charset="utf-8"><style>@page { margin: 14mm 15mm 14mm; } body { margin: 0; }' . DH_ORCAMENTO_CSS . '</style></head><body>'
        . dh_orcamento_html($k, $raiz) . '</body></html>';
    $pdf = new \Dompdf\Dompdf($op);
    $pdf->loadHtml($html, 'UTF-8');
    $pdf->setPaper('A4');
    $pdf->render();
    return (string) $pdf->output();
}
