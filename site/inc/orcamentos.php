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

// Proposta em HTML (a mesma para a página do cliente e para o PDF)
function dh_orcamento_html(array $k, string $raiz = ''): string
{
    $c = $k['cliente'];
    $linhas = '';
    foreach (array_values($k['itens']) as $n => $it) {
        $linhas .= '<tr><td class="n">' . ($n + 1) . '</td><td class="desc"><strong>' . e($it['descricao']) . '</strong>' . ($it['detalhe'] !== '' ? '<small>(' . e($it['detalhe']) . ')</small>' : '') . '</td>'
            . '<td class="num vu">' . ($it['valor'] !== '' ? e(dh_moeda($it['valor'])) : '') . '</td><td class="num">' . e($it['quant']) . '</td><td class="num">' . e(dh_moeda(dh_orcamento_item_total($it))) . '</td></tr>';
    }
    $emitido = $k['emitido_em'] ?: $k['criado_em'];
    // Layout em tabelas: funciona igual na página e no gerador de PDF
    $h = '<div class="orc">';
    $h .= '<table class="orc-topo"><tr><td class="orc-titulo"><h1>PROPOSTA</h1>'
        . ($k['data_evento'] !== '' ? '<p class="orc-data">Data do Evento: <strong>' . e(dh_data_br($k['data_evento'])) . '</strong></p>' : '')
        . '<p class="orc-empresa">' . e(DH_EMPRESA['razao']) . '<br>CNPJ: ' . e(DH_EMPRESA['cnpj']) . '<br>' . e(DH_EMPRESA['endereco']) . '<br>Contato: ' . e(DH_EMPRESA['telefone']) . '</p></td>'
        . '<td class="orc-para"><img src="' . e($raiz) . 'assets/img/logo-donkey-head.png" alt="Donkey Head Cervejaria">' . ($c['nome'] !== '' || $c['doc'] !== '' ? '<p class="rot">Para:</p>' : '') . '<p class="nome">' . e($c['nome']) . '</p>'
        . ($c['doc'] !== '' ? '<p>' . e(dh_cpf_formatar($c['doc'])) . '</p>' : '') . '</td></tr></table>';
    $h .= '<p class="orc-numero">Proposta nº ' . e($k['numero']) . '</p>';
    $h .= '<table class="orc-itens"><thead><tr><th class="n"></th><th>Descrição</th><th class="num vu">Valor Un</th><th class="num">Quant.</th><th class="num">Total</th></tr></thead><tbody>' . $linhas
        . '</tbody><tfoot>'
        . (dh_orcamento_desconto($k) > 0 ? '<tr class="sub"><td class="n"></td><td></td><td class="vu"></td><td class="num">Subtotal</td><td class="num">' . e(dh_moeda(dh_orcamento_subtotal($k))) . '</td></tr>'
            . '<tr class="sub"><td class="n"></td><td></td><td class="vu"></td><td class="num">Desconto</td><td class="num">− ' . e(dh_moeda(dh_orcamento_desconto($k))) . '</td></tr>' : '')
        . '<tr><td class="n"></td><td></td><td class="vu"></td><td class="num">Total</td><td class="num">' . e(dh_moeda(dh_orcamento_total($k))) . '</td></tr></tfoot></table>';
    if (trim($k['observacoes']) !== '') {
        $h .= '<p class="orc-obs">' . nl2br(e($k['observacoes'])) . '</p>';
    }
    $h .= '<table class="orc-rodape"><tr><td class="orc-cond"><h3>Condições de pagamento:</h3><p>' . e(DH_ORCAMENTO_PAGAMENTO) . '</p>'
        . '<h3>Proposta válida por ' . (int) $k['validade'] . ' dias</h3><p class="mini">até ' . e(dh_data_br(dh_orcamento_validade($k))) . '</p>'
        . (trim($k['banco']) !== '' ? '<h3>Dados bancários:</h3><p>' . nl2br(e($k['banco'])) . '</p>' : '') . '</td>'
        . '<td class="orc-assina"><p class="traco">' . e($k['assinante'] ?? '') . '</p><p>Fortaleza, ' . e(dh_data_extenso(substr($emitido, 0, 10))) . '</p></td></tr></table>';
    return $h . '</div>';
}

// Estilo da proposta (página e PDF), no visual do modelo: faixas cinza-escuro, tabela cinza
const DH_ORCAMENTO_CSS = <<<'CSS'
.orc { font-family: "DejaVu Sans", Arial, sans-serif; color: #2b2b2b; font-size: 10pt; }
.orc, .orc * { overflow-wrap: normal; word-break: normal; }
.orc table { width: 100%; border-collapse: collapse; }
.orc-topo td { background: #3c3c3e; color: #fff; vertical-align: top; padding: 20px 24px; }
.orc-titulo h1 { margin: 0 0 6px; font-size: 26pt; letter-spacing: 1px; color: #fff; text-align: left; }
.orc-data { margin: 0 0 12px; font-size: 12pt; }
.orc-empresa { margin: 0; font-size: 8.5pt; line-height: 1.5; }
.orc-para { width: 34%; text-align: right; }
.orc-para img { width: 64px; margin: 0 0 6px auto; display: block; }
.orc-para p { margin: 0; }
.orc-para .rot { font-size: 14pt; font-weight: bold; }
.orc-para .nome { font-size: 11pt; font-weight: bold; text-transform: uppercase; }
.orc-numero { color: #6b6b6b; font-size: 8.5pt; margin: 10px 2px 8px; text-align: left; }
.orc-itens th { background: #4a4a4c; color: #fff; padding: 8px; text-align: left; font-size: 9pt; }
.orc-itens td { background: #efefef; border-bottom: 2px solid #fff; padding: 10px 8px; vertical-align: top; }
.orc-itens .n { width: 18px; text-align: center; font-weight: bold; }
.orc-itens td.desc strong { display: block; text-transform: uppercase; }
.orc-itens td.desc small { font-size: 7.5pt; color: #555; }
.orc-itens .num { text-align: right; white-space: nowrap; }
.orc-itens tfoot td { background: #e2e2e2; font-weight: bold; font-size: 11pt; }
.orc-itens tfoot tr.sub td { background: #efefef; font-weight: normal; font-size: 9.5pt; }
.orc-obs { margin: 12px 2px 0; font-size: 9pt; }
.orc-rodape { margin-top: 18px; }
.orc-rodape td { vertical-align: top; }
.orc-cond { width: 58%; }
.orc-rodape h3 { margin: 12px 0 2px; font-size: 11pt; }
.orc-rodape p { margin: 0; font-size: 9pt; line-height: 1.5; }
.orc-rodape .mini { color: #6b6b6b; font-size: 8pt; }
.orc-assina { text-align: center; padding-top: 60px; }
.orc-assina .traco { border-top: 1px solid #2b2b2b; padding-top: 4px; font-weight: bold; min-height: 14px; margin: 0 10px 4px; }
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
    $html = '<!doctype html><html><head><meta charset="utf-8"><style>@page { margin: 14mm 14mm 16mm; } body { margin: 0; }' . DH_ORCAMENTO_CSS . '</style></head><body>'
        . dh_orcamento_html($k, $raiz) . '</body></html>';
    $pdf = new \Dompdf\Dompdf($op);
    $pdf->loadHtml($html, 'UTF-8');
    $pdf->setPaper('A4');
    $pdf->render();
    return (string) $pdf->output();
}
