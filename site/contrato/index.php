<?php
// Contrato para leitura e assinatura do cliente: www.donkeyheadcervejaria.com.br/contrato/?t=...
// O cliente confirma nome, CPF e e-mail, recebe um código por e-mail e assina. Depois de assinado
// pelas duas partes, a mesma página mostra o contrato com o relatório de assinaturas (e vira PDF pelo navegador).
require __DIR__ . '/../inc/dados.php';

header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
session_name('dh_contrato');
session_start();
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

$token = (string) ($_GET['t'] ?? '');
$c = dh_contratos();
$i = dh_contrato_por_token($c, $token);
$k = $i !== null ? $c['contratos'][$i] : null;
if (!$k || $k['status'] === 'rascunho') {
    http_response_code(404);
    $k = null;
}
$erro = '';
$aviso = $_SESSION['contrato_aviso'] ?? '';
unset($_SESSION['contrato_aviso']);
$pend = $_SESSION['contrato_codigo'] ?? null;
if ($k && ($pend['id'] ?? '') !== $k['id']) {
    $pend = null;
}
$podeAssinar = $k && $k['status'] === 'enviado' && empty($k['assinaturas']['contratante']);

if ($podeAssinar && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('A página ficou aberta muito tempo. Tente de novo.');
        }
        $op = (string) ($_POST['op'] ?? '');
        if ($op === 'codigo') {
            $nome = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($_POST['nome'] ?? ''))), 0, 120);
            $cpf = (string) ($_POST['cpf'] ?? '');
            $email = trim((string) ($_POST['email'] ?? ''));
            if (mb_strlen($nome) < 5 || strpos($nome, ' ') === false) {
                throw new RuntimeException('Escreva o seu nome completo.');
            }
            if (!dh_cpf_valido($cpf)) {
                throw new RuntimeException('CPF inválido. Confira os números.');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('E-mail inválido.');
            }
            if (empty($_POST['aceite'])) {
                throw new RuntimeException('Marque que leu e concorda com o contrato.');
            }
            if ($pend && time() - $pend['enviado'] < 60) {
                throw new RuntimeException('Aguarde um minuto para pedir um novo código.');
            }
            $codigo = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $_SESSION['contrato_codigo'] = ['id' => $k['id'], 'hash' => hash('sha256', $codigo . $k['token']), 'ate' => time() + 900, 'enviado' => time(),
                'tentativas' => 0, 'nome' => $nome, 'cpf' => dh_so_digitos($cpf), 'email' => $email];
            $ok = dh_email($email, 'Seu código para assinar o contrato nº ' . $k['numero'] . ' · Donkey Head Cervejaria',
                '<p>Use este código para assinar o contrato nº <b>' . e($k['numero']) . '</b> da Donkey Head Cervejaria:</p>'
                . '<p style="font-size:32px;font-weight:bold;letter-spacing:8px;margin:12px 0">' . $codigo . '</p>'
                . '<p>Ele vale por 15 minutos. Se não foi você que pediu, ignore este e-mail.</p>');
            if (!$ok) {
                unset($_SESSION['contrato_codigo']);
                throw new RuntimeException('Não foi possível enviar o e-mail agora. Tente de novo em instantes ou fale com a Donkey Head Cervejaria.');
            }
            $_SESSION['contrato_aviso'] = 'Enviamos um código de 6 números para ' . $email . '. Confira também a caixa de spam.';
        } elseif ($op === 'assinar') {
            if (!$pend) {
                throw new RuntimeException('Peça o código primeiro.');
            }
            if (time() > $pend['ate']) {
                unset($_SESSION['contrato_codigo']);
                throw new RuntimeException('O código venceu. Peça um novo.');
            }
            if ($pend['tentativas'] >= 5) {
                unset($_SESSION['contrato_codigo']);
                throw new RuntimeException('Muitas tentativas. Peça um novo código.');
            }
            $_SESSION['contrato_codigo']['tentativas']++;
            if (!hash_equals($pend['hash'], hash('sha256', dh_so_digitos((string) ($_POST['codigo'] ?? '')) . $k['token']))) {
                throw new RuntimeException('Código errado. Confira no seu e-mail.');
            }
            // Relê do arquivo (a Donkey pode ter assinado nesse meio tempo)
            $c = dh_contratos();
            $i = dh_contrato_por_token($c, $token);
            if ($c['contratos'][$i]['status'] !== 'enviado' || !empty($c['contratos'][$i]['assinaturas']['contratante'])) {
                throw new RuntimeException('Este contrato não está mais disponível para assinatura.');
            }
            $c['contratos'][$i]['assinaturas']['contratante'] = dh_contrato_assinatura($pend['nome'], $pend['cpf'], $pend['email'],
                'Código de 6 dígitos enviado ao e-mail ' . $pend['email'] . ' · aceite dos termos na página do contrato', (string) ($_POST['geo'] ?? ''));
            $fechou = dh_contrato_concluir($c['contratos'][$i]);
            dh_contratos_salvar($c);
            if (!$fechou) {
                dh_email(DH_EMPRESA['email'], 'Cliente assinou o contrato nº ' . $k['numero'],
                    '<p>' . e($pend['nome']) . ' assinou o contrato nº <b>' . e($k['numero']) . '</b>. Falta a assinatura da Donkey Head Cervejaria no painel.</p>');
            }
            unset($_SESSION['contrato_codigo']);
            $_SESSION['contrato_aviso'] = $fechou ? 'Contrato assinado! Uma cópia foi enviada para o seu e-mail.' : 'Assinatura registrada! Assim que a Donkey Head Cervejaria assinar, você recebe a cópia por e-mail.';
        }
        header('Location: ./?t=' . $token, true, 303);
        exit;
    } catch (RuntimeException $e) {
        $erro = $e->getMessage();
    }
}
$csrf = '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">';
$f = $k['campos'] ?? [];
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <meta name="referrer" content="no-referrer">
  <title><?= $k ? 'Contrato nº ' . e($k['numero']) . ' · ' : '' ?>Donkey Head Cervejaria</title>
  <link rel="icon" href="../favicon.ico">
  <link rel="stylesheet" href="contrato.css?v=1">
</head>
<body>
  <div class="barra no-print">
    <img src="../assets/img/logo-donkey-head.png" alt="" width="34" height="48"><strong>Donkey Head Cervejaria</strong>
    <?php if ($k): ?><button type="button" class="btn" onclick="window.print()">⬇ Baixar PDF / Imprimir</button><?php endif; ?>
  </div>
  <main>
    <?php if (!$k): ?>
      <div class="papel"><h1>Contrato não encontrado</h1><p>Este link não existe ou foi trocado. Fale com a Donkey Head Cervejaria.</p></div>
    <?php else: ?>
      <?php if ($aviso): ?><p class="msg ok no-print"><?= e($aviso) ?></p><?php endif; ?>
      <?php if ($erro): ?><p class="msg erro no-print"><?= e($erro) ?></p><?php endif; ?>
      <?php if ($k['status'] === 'cancelado'): ?><p class="msg erro">Este contrato foi cancelado pela Donkey Head Cervejaria e não vale mais.</p><?php endif; ?>
      <?php if ($podeAssinar): ?><p class="msg no-print">Leia o contrato abaixo. A assinatura fica no final da página.</p><?php endif; ?>

      <article class="papel">
        <header class="timbre"><img src="../assets/img/logo-donkey-head.png" alt="Donkey Head Cervejaria" width="56"><span><?= e(DH_EMPRESA['razao']) ?><br>CNPJ <?= e(DH_EMPRESA['cnpj']) ?></span></header>
        <?= $k['documento'] ?>
        <div class="assinam">
          <?php foreach (['contratada' => 'CONTRATADA', 'contratante' => 'CONTRATANTE'] as $parte => $rot): $a = $k['assinaturas'][$parte] ?? null; ?>
            <div><p class="traco"><?= $a ? 'Assinado eletronicamente por ' . e($a['nome']) . '<br>em ' . e(date('d/m/Y H:i', strtotime($a['em']))) : '&nbsp;' ?></p><p><strong><?= $rot ?></strong></p></div>
          <?php endforeach; ?>
        </div>
        <footer class="rodape-doc"><?= e(DH_EMPRESA['razao']) ?> · CNPJ <?= e(DH_EMPRESA['cnpj']) ?> · <?= e(DH_EMPRESA['endereco']) ?> · <?= e(DH_EMPRESA['telefone']) ?><br>Contrato nº <?= e($k['numero']) ?> · Código de integridade: <?= e($k['hash']) ?></footer>
      </article>

      <article class="papel quebra"><?= dh_contrato_relatorio($k) ?></article>

      <?php if ($podeAssinar): ?>
        <section class="assinar no-print" id="assinar">
          <h2>Assinar o contrato</h2>
          <?php if (!$pend): ?>
            <form method="post">
              <?= $csrf ?><input type="hidden" name="op" value="codigo">
              <label>Seu nome completo <input name="nome" required autocomplete="name" value="<?= e($_POST['nome'] ?? '') ?>"></label>
              <label>Seu CPF <input name="cpf" required inputmode="numeric" autocomplete="off" placeholder="000.000.000-00" value="<?= e($_POST['cpf'] ?? '') ?>"></label>
              <label>Seu e-mail <small>(o código chega aqui)</small><input type="email" name="email" required autocapitalize="none" value="<?= e($_POST['email'] ?? $f['contratante_email']) ?>"></label>
              <label class="check"><input type="checkbox" name="aceite" value="1" required> Li e concordo com todas as cláusulas do contrato nº <?= e($k['numero']) ?>.</label>
              <button class="btn grande">Receber o código por e-mail</button>
            </form>
          <?php else: ?>
            <p>Assinando como <strong><?= e($pend['nome']) ?></strong> (CPF <?= e(dh_cpf_mascara($pend['cpf'])) ?>). Digite o código enviado para <strong><?= e($pend['email']) ?></strong>:</p>
            <form method="post" id="form-codigo">
              <?= $csrf ?><input type="hidden" name="op" value="assinar"><input type="hidden" name="geo" id="geo">
              <label>Código de 6 números <input name="codigo" required inputmode="numeric" autocomplete="one-time-code" maxlength="6" class="codigo"></label>
              <button class="btn grande">✍ Assinar o contrato</button>
            </form>
            <form method="post" class="reenviar">
              <?= $csrf ?><input type="hidden" name="op" value="codigo"><input type="hidden" name="nome" value="<?= e($pend['nome']) ?>"><input type="hidden" name="cpf" value="<?= e($pend['cpf']) ?>"><input type="hidden" name="email" value="<?= e($pend['email']) ?>"><input type="hidden" name="aceite" value="1">
              <button class="link">Não chegou? Enviar outro código</button>
            </form>
            <script>
              if (navigator.geolocation) navigator.geolocation.getCurrentPosition(function (p) {
                document.getElementById('geo').value = p.coords.latitude.toFixed(5) + ',' + p.coords.longitude.toFixed(5);
              }, function () {}, { timeout: 8000 });
            </script>
          <?php endif; ?>
          <p class="legal">Assinatura eletrônica com validade jurídica (MP 2.200-2/2001, art. 10, § 2º, e Lei 14.063/2020). Ficam registrados nome, CPF, e-mail confirmado, data e hora, IP e aparelho.</p>
        </section>
      <?php endif; ?>
    <?php endif; ?>
  </main>
</body>
</html>
