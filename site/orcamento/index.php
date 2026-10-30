<?php
// Orçamento para o cliente: www.donkeyheadcervejaria.com.br/orcamento/?t=...
// Mostra a proposta, baixa em PDF e o cliente pode aprovar (a Donkey recebe o aviso por e-mail).
require __DIR__ . '/../inc/dados.php';

header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
session_name('dh_contrato');
session_start();
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$token = (string) ($_GET['t'] ?? '');
$o = dh_orcamentos();
$i = dh_orcamento_por_token($o, $token);
$k = $i !== null ? $o['orcamentos'][$i] : null;
if (!$k) {
    http_response_code(404);
}
if ($k && isset($_GET['pdf'])) {
    $arq = dh_orcamento_pdf($k);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . dh_orcamento_pdf_nome($k) . '"');
    header('Content-Length: ' . strlen($arq));
    echo $arq;
    exit;
}
$erro = '';
$aviso = $_SESSION['orc_aviso'] ?? '';
unset($_SESSION['orc_aviso']);
$vencido = $k && dh_orcamento_validade($k) < date('Y-m-d');
if ($k && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('A página ficou aberta muito tempo. Tente de novo.');
        }
        if ($k['status'] !== 'rascunho') {
            throw new RuntimeException('Este orçamento não está mais aberto para aprovação.');
        }
        if ($vencido) {
            throw new RuntimeException('A validade deste orçamento venceu. Fale com a Donkey Head Cervejaria para atualizar.');
        }
        $nome = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($_POST['nome'] ?? ''))), 0, 120);
        if (mb_strlen($nome) < 3) {
            throw new RuntimeException('Escreva o seu nome para aprovar.');
        }
        $o['orcamentos'][$i]['status'] = 'aprovado';
        $o['orcamentos'][$i]['aprovado'] = ['nome' => $nome, 'em' => date('Y-m-d H:i:s'), 'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? '')];
        dh_orcamentos_salvar($o);
        dh_email_equipe('Orçamento aprovado: ' . $k['numero'] . ' · ' . $k['cliente']['nome'],
            '<p><b>' . e($nome) . '</b> aprovou o orçamento <b>' . e($k['numero']) . '</b> (' . e($k['cliente']['nome']) . ', ' . e(dh_moeda(dh_orcamento_total($k))) . ').</p>'
            . '<p>No painel, em Orçamentos, toque em "Virar contrato" para gerar o contrato.</p>');
        $_SESSION['orc_aviso'] = 'Orçamento aprovado! A Donkey Head Cervejaria vai entrar em contato para enviar o contrato.';
        header('Location: ./?t=' . $token, true, 303);
        exit;
    } catch (RuntimeException $e) {
        $erro = $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <meta name="referrer" content="no-referrer">
  <title><?= $k ? 'Orçamento ' . e($k['numero']) . ' · ' : '' ?>Donkey Head Cervejaria</title>
  <link rel="icon" href="../favicon.ico">
  <link rel="stylesheet" href="../contrato/contrato.css?v=9">
  <style>
    <?= DH_ORCAMENTO_CSS ?>
    .orc { font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; font-size: 15px; }
    .orc-topo { border-radius: 8px; }
    .papel { padding: clamp(12px, 4vw, 40px); }
    @media (max-width: 600px) {
      .orc { font-size: 14px; }
      .orc-topo tr, .orc-rodape tr { display: flex; flex-direction: column; }
      .orc-topo td { padding: 16px; }
      .orc-para { width: auto; text-align: left; padding-top: 0 !important; }
      .orc-para img { margin: 0 0 6px; }
      .orc-titulo h1 { font-size: 30px; }
      .orc-itens .vu { display: none; }
      .orc-itens td, .orc-itens th { padding: 8px 5px; }
      .orc-cond { width: auto; }
      .orc-assina { padding-top: 40px; }
    }
    .aprovar { background: #fff; border: 2px solid var(--ambar); border-radius: 8px; padding: 20px; margin-bottom: 40px; }
    .aprovar input { display: block; width: 100%; margin: 4px 0 12px; padding: 14px; font: inherit; font-size: 1.05rem; border: 2px solid var(--linha); border-radius: 10px; }
  </style>
</head>
<body>
  <div class="barra no-print">
    <img src="../assets/img/logo-donkey-head.png" alt="" width="34" height="48"><strong>Donkey Head Cervejaria</strong>
    <?php if ($k): ?><a class="btn" href="./?t=<?= e($token) ?>&amp;pdf=1">⬇ Baixar PDF</a><?php endif; ?>
  </div>
  <main>
    <?php if (!$k): ?>
      <div class="papel"><h1>Orçamento não encontrado</h1><p>Este link não existe ou foi trocado. Fale com a Donkey Head Cervejaria.</p></div>
    <?php else: ?>
      <?php if ($aviso): ?><p class="msg ok"><?= e($aviso) ?></p><?php endif; ?>
      <?php if ($erro): ?><p class="msg erro"><?= e($erro) ?></p><?php endif; ?>
      <article class="papel"><?= dh_orcamento_html($k, '../') ?></article>
      <?php if ($k['status'] === 'rascunho' && !$vencido): ?>
        <section class="aprovar" id="aprovar">
          <h2>Aprovar este orçamento</h2>
          <p>Gostou? Aprove aqui e a Donkey Head Cervejaria envia o contrato para assinatura.</p>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <label>Seu nome <input name="nome" required autocomplete="name"></label>
            <button class="btn grande">✅ Aprovar orçamento</button>
          </form>
        </section>
      <?php elseif ($k['status'] === 'aprovado'): ?>
        <p class="msg ok">Orçamento aprovado por <?= e($k['aprovado']['nome'] ?? '') ?> em <?= e(date('d/m/Y', strtotime($k['aprovado']['em'] ?? 'now'))) ?>.</p>
      <?php elseif ($vencido && $k['status'] === 'rascunho'): ?>
        <p class="msg">A validade deste orçamento venceu em <?= e(dh_data_br(dh_orcamento_validade($k))) ?>. Fale com a Donkey Head Cervejaria para atualizar os valores.</p>
      <?php endif; ?>
    <?php endif; ?>
  </main>
</body>
</html>
