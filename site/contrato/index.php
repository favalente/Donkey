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
// Contrato sendo editado pela Donkey (já tinha número): o link avisa e volta a funcionar quando for gerado de novo
$emAjuste = $k && $k['status'] === 'rascunho' && $k['numero'] !== '';
if (!$k || $k['status'] === 'rascunho') {
    http_response_code($emAjuste ? 200 : 404);
    $k = null;
}
$aviso = $_SESSION['contrato_aviso'] ?? '';
unset($_SESSION['contrato_aviso']);
$pend = $_SESSION['contrato_codigo'] ?? null;
// O código vale só para o texto que o cliente leu (se o contrato for editado, pede outro)
if ($k && (($pend['id'] ?? '') !== $k['id'] || ($pend['doc'] ?? '') !== ($k['hash'] ?? ''))) {
    $pend = null;
}
$erro = '';
$podeAssinar = $k && $k['status'] === 'enviado' && empty($k['assinaturas']['contratante']);
$preencher = $k && $k['status'] === 'preenchimento';
$consulta = $k && $k['status'] === 'consulta';
$cervejas = $preencher ? dh_cervejas_evento() : [];

// Cupom de desconto: o formulário confere o código antes de enviar (limite de tentativas por sessão)
if ($preencher && isset($_GET['cupom'])) {
    header('Content-Type: application/json; charset=utf-8');
    $_SESSION['cupom_tentativas'] = ($_SESSION['cupom_tentativas'] ?? 0) + 1;
    try {
        if ($_SESSION['cupom_tentativas'] > 15) {
            throw new RuntimeException('Muitas tentativas. Fale com a Donkey Head Cervejaria.');
        }
        $cp = dh_cupom_valido($c, (string) $_GET['cupom']);
        echo json_encode(['ok' => true, 'codigo' => $cp['codigo'], 'tipo' => $cp['tipo'], 'valor' => (float) $cp['valor'], 'msg' => 'Cupom ' . $cp['codigo'] . ' aplicado: ' . dh_cupom_rotulo($cp) . '.']);
    } catch (RuntimeException $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

// PDF do contrato (o mesmo que vai anexado no e-mail)
if ($k && isset($_GET['pdf']) && in_array($k['status'], ['enviado', 'assinado', 'cancelado'], true)) {
    $arquivo = dh_contrato_pdf($k);
    header('Content-Type: application/pdf');
    header('Content-Length: ' . strlen($arquivo));
    header('Content-Disposition: attachment; filename="' . dh_contrato_pdf_nome($k) . '"');
    header('Cache-Control: private, no-store');
    echo $arquivo;
    exit;
}

// Comprovante de pagamento (quem tem o link do contrato pode ver)
if ($k && isset($_GET['comprovante'])) {
    dh_comprovante_mostrar($k['campos']['comprovante'] ?? null);
}

// Arquivo maior que o limite do servidor: o PHP descarta o formulário inteiro
if ($preencher && $_SERVER['REQUEST_METHOD'] === 'POST' && !$_POST && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $erro = 'O comprovante é grande demais. Envie um print ou o PDF do comprovante.';
}

// Pedido de chope: o cliente preenche os dados do cabeçalho, escolhe rótulos e litragem e o contrato é gerado
if ($preencher && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['op'] ?? '') === 'preencher') {
    try {
        if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('A página ficou aberta muito tempo. Tente de novo.');
        }
        $txt = fn($n, $max = 200) => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($_POST[$n] ?? ''))), 0, $max);
        $f = $k['campos'];
        $f['contratante_nome'] = $txt('nome', 120);
        $f['contratante_doc'] = dh_so_digitos((string) ($_POST['cpf'] ?? ''));
        $f['contratante_telefone'] = $txt('celular', 30);
        $f['contratante_email'] = trim((string) ($_POST['email'] ?? ''));
        $f['entrega_endereco'] = $txt('endereco', 250);
        $f['contratante_endereco'] = $f['entrega_endereco'];
        $f['entrega_data'] = (string) ($_POST['entrega_data'] ?? '');
        $f['devolucao_data'] = (string) ($_POST['devolucao_data'] ?? '');
        $f['entrega_hora'] = (string) ($_POST['entrega_hora'] ?? '');
        $f['entrega_hora_estimada'] = true;
        $f['copos_pacotes'] = min(200, max(0, (int) ($_POST['copos_pacotes'] ?? 0)));
        // Fortaleza: frete fixo; outra cidade: o cliente digita o valor combinado com o vendedor
        $foraDeFortaleza = ($_POST['local'] ?? '') === 'outra';
        $f['cidade'] = $foraDeFortaleza ? $txt('cidade', 80) : 'Fortaleza/CE';
        $f['frete'] = $foraDeFortaleza ? dh_valor($_POST['frete_outra'] ?? '') : number_format(DH_FRETE_FORTALEZA, 2, '.', '');
        // O comprovante é guardado assim que chega, para o cliente não ter de anexar de novo se algo no formulário estiver errado
        if (($_FILES['comprovante']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $cp = dh_comprovante_salvar($k['id'], $_FILES['comprovante']);
            $c = dh_contratos();
            $i = dh_contrato_por_token($c, $token);
            if ($c['contratos'][$i]['status'] !== 'preenchimento') {
                dh_comprovante_apagar($cp);
                throw new RuntimeException('Este pedido já foi enviado.');
            }
            dh_comprovante_apagar($c['contratos'][$i]['campos']['comprovante'] ?? null);
            $c['contratos'][$i]['campos']['comprovante'] = $cp;
            dh_contratos_salvar($c);
            $k = $c['contratos'][$i];
            $f['comprovante'] = $cp;
        }
        $porId = array_column($cervejas, null, 'id');
        $f['itens'] = [];
        foreach (['itens' => false, 'comodato' => true] as $campo => $emComodato) {
            foreach ((array) ($_POST[$campo] ?? []) as $it) {
                $cid = (string) ($it['cerveja'] ?? '');
                $litros = dh_valor($it['litros'] ?? '');
                if ($cid === '' && $litros === '') {
                    continue;
                }
                if (!isset($porId[$cid]) || $litros === '' || (float) $litros <= 0 || (float) $litros > 5000) {
                    throw new RuntimeException('Escolha o rótulo e digite a litragem de cada linha' . ($emComodato ? ' do chope em comodato.' : ' do chope.'));
                }
                $f['itens'][] = ['descricao' => $porId[$cid]['nome'], 'cerveja' => $cid, 'litros' => $litros, 'valor_litro' => dh_valor($porId[$cid]['preco_evento']), 'comodato' => $emComodato];
            }
        }
        if (mb_strlen($f['contratante_nome']) < 5 || strpos($f['contratante_nome'], ' ') === false) {
            throw new RuntimeException('Escreva o seu nome completo.');
        }
        if (!dh_cpf_valido($f['contratante_doc'])) {
            throw new RuntimeException('CPF inválido. Confira os números.');
        }
        if (strlen(dh_so_digitos($f['contratante_telefone'])) < 10) {
            throw new RuntimeException('Informe o celular com DDD.');
        }
        if (!filter_var($f['contratante_email'], FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('E-mail inválido.');
        }
        if ($f['entrega_endereco'] === '') {
            throw new RuntimeException('Informe o endereço de entrega.');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['entrega_data']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['devolucao_data'])) {
            throw new RuntimeException('Informe as datas de recebimento e de devolução do equipamento.');
        }
        if ($f['entrega_data'] < date('Y-m-d')) {
            throw new RuntimeException('A data de recebimento não pode ser no passado.');
        }
        if ($f['devolucao_data'] < $f['entrega_data']) {
            throw new RuntimeException('A devolução não pode ser antes do recebimento.');
        }
        if (!array_filter($f['itens'], fn($it) => !$it['comodato'])) {
            throw new RuntimeException('Escolha ao menos um rótulo de chope e a litragem.');
        }
        if (!in_array($_POST['local'] ?? '', ['fortaleza', 'outra'], true)) {
            throw new RuntimeException('Responda se a entrega é em Fortaleza.');
        }
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $f['entrega_hora'])) {
            throw new RuntimeException('Informe o horário de entrega estimado.');
        }
        if ($foraDeFortaleza && mb_strlen($f['cidade']) < 3) {
            throw new RuntimeException('Informe a cidade da entrega.');
        }
        if ($foraDeFortaleza && ($f['frete'] === '' || (float) $f['frete'] > 20000)) {
            throw new RuntimeException('Digite o valor do frete combinado com o vendedor (use 0 se for grátis).');
        }
        $f['cupom'] = '';
        $f['desconto'] = '';
        if (trim((string) ($_POST['cupom'] ?? '')) !== '') {
            $cp = dh_cupom_valido(dh_contratos(), (string) $_POST['cupom']);
            $f['cupom'] = $cp['codigo'];
            $f['desconto'] = number_format(dh_cupom_desconto($cp, dh_comodato_chope($f)), 2, '.', '');
        }
        if (empty($f['comprovante'])) {
            throw new RuntimeException('Anexe o comprovante de pagamento. O contrato só vale com ele.');
        }
        $c = dh_contratos();
        $i = dh_contrato_por_token($c, $token);
        if ($c['contratos'][$i]['status'] !== 'preenchimento') {
            throw new RuntimeException('Este pedido já foi enviado.');
        }
        if (empty($c['config']['representante_cpf'])) {
            throw new RuntimeException('Não foi possível gerar o contrato agora. Fale com a Donkey Head Cervejaria.');
        }
        if ($f['cupom'] !== '') {
            // Confere de novo (outro pedido pode ter usado o último cupom) e conta o uso
            dh_cupom_valido($c, $f['cupom']);
            $c['cupons'][$f['cupom']]['usos'] = (int) $c['cupons'][$f['cupom']]['usos'] + 1;
        }
        $k = $c['contratos'][$i];
        $f['comprovante'] = $k['campos']['comprovante'] ?? $f['comprovante'];
        $k['campos'] = $f;
        dh_cliente_registrar($k);
        $k['numero'] = dh_contrato_numero($c);
        dh_contrato_congelar($k, $c['config']);
        $c['contratos'][$i] = $k;
        dh_contratos_salvar($c);
        dh_email_equipe('Pedido de chope preenchido nº ' . $k['numero'],
            '<p>' . e($f['contratante_nome']) . ' preencheu o pedido de chope nº <b>' . e($k['numero']) . '</b> (entrega em ' . e($f['cidade']) . ', ' . e(dh_data_br($f['entrega_data'])) . ', total ' . e(dh_moeda(dh_comodato_total($f)))
            . ($f['copos_pacotes'] > 0 ? ', ' . $f['copos_pacotes'] . ' pacote(s) de copos personalizados' : '')
            . ($f['cupom'] !== '' ? ', com o cupom ' . e($f['cupom']) . ' (− ' . e(dh_moeda($f['desconto'])) . ')' : '') . ($foraDeFortaleza ? ', frete combinado de ' . e(dh_moeda($f['frete'])) : '') . ').</p>'
            . '<p><a href="' . e(dh_url_site() . 'admin/?acao=contrato-comprovante&id=' . $k['id']) . '">Ver o comprovante de pagamento</a> (entre no painel) e confira se o valor caiu.</p>'
            . '<p>Quando o cliente assinar, falta a assinatura da Donkey Head Cervejaria no painel.</p>');
        $_SESSION['contrato_aviso'] = 'Pedido registrado! Confira o contrato abaixo e assine no final da página.';
        header('Location: ./?t=' . $token . '#assinar', true, 303);
        exit;
    } catch (RuntimeException $e) {
        $erro = $e->getMessage();
    }
}

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
                'tentativas' => 0, 'doc' => $k['hash'], 'nome' => $nome, 'cpf' => dh_so_digitos($cpf), 'email' => $email];
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
                dh_email_equipe('Cliente assinou o contrato nº ' . $k['numero'],
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
  <link rel="stylesheet" href="contrato.css?v=9">
</head>
<body>
  <div class="barra no-print">
    <img src="../assets/img/logo-donkey-head.png" alt="" width="34" height="48"><strong>Donkey Head Cervejaria</strong>
    <?php if ($k && !$preencher && !$consulta): ?><a class="btn" href="./?t=<?= e($token) ?>&amp;pdf=1">⬇ Baixar PDF</a><?php endif; ?>
  </div>
  <main>
    <?php if (!$k): ?>
      <?php if ($emAjuste): ?>
        <div class="papel"><h1>Contrato em ajuste</h1><p>A Donkey Head Cervejaria está ajustando este contrato. Volte neste mesmo link em instantes para conferir e assinar.</p></div>
      <?php else: ?>
        <div class="papel"><h1>Contrato não encontrado</h1><p>Este link não existe ou foi trocado. Fale com a Donkey Head Cervejaria.</p></div>
      <?php endif; ?>
    <?php else: ?>
      <?php if ($aviso): ?><p class="msg ok no-print"><?= e($aviso) ?></p><?php endif; ?>
      <?php if ($erro): ?><p class="msg erro no-print"><?= e($erro) ?></p><?php endif; ?>
      <?php if ($preencher): ?>
        <?php include __DIR__ . '/pedido-form.php'; ?>
      <?php elseif ($consulta): ?>
        <div class="papel">
          <h1>Pedido recebido</h1>
          <p>Olá, <?= e($f['contratante_nome']) ?>! A entrega é em <strong><?= e($f['cidade']) ?></strong>, então a Donkey Head Cervejaria vai calcular o frete. Você recebe o aviso por e-mail (<?= e($f['contratante_email']) ?>) e é só voltar neste mesmo link para conferir e assinar o contrato.</p>
          <p class="legal">Chope: <?= e(dh_moeda(dh_comodato_total($f))) ?> + frete sob consulta.</p>
        </div>
      <?php else: ?>
      <?php if ($k['status'] === 'cancelado'): ?><p class="msg erro">Este contrato foi cancelado pela Donkey Head Cervejaria e não vale mais.</p><?php endif; ?>
      <?php if ($podeAssinar): ?><p class="msg no-print">Leia o contrato abaixo. A assinatura fica no final da página.</p><?php endif; ?>

      <article class="papel">
        <header class="timbre"><img src="../assets/img/logo-donkey-head.png" alt="Donkey Head Cervejaria" width="56"><span><?= e(DH_EMPRESA['razao']) ?><br>CNPJ <?= e(DH_EMPRESA['cnpj']) ?></span></header>
        <?= dh_contrato_exibir($k['documento']) ?>
        <div class="assinam">
          <?php foreach (['contratada' => 'CONTRATADA', 'contratante' => 'CONTRATANTE'] as $parte => $rot): $a = $k['assinaturas'][$parte] ?? null; ?>
            <div><p class="traco"><?= $a ? 'Assinado eletronicamente por ' . e($a['nome']) . '<br>em ' . e(date('d/m/Y H:i', strtotime($a['em']))) : '&nbsp;' ?></p><p><strong><?= $rot ?></strong></p></div>
          <?php endforeach; ?>
        </div>
        <footer class="rodape-doc"><?= e(DH_EMPRESA['razao']) ?> · CNPJ <?= e(DH_EMPRESA['cnpj']) ?> · <?= e(DH_EMPRESA['endereco']) ?> · <?= e(DH_EMPRESA['telefone']) ?><br>Contrato nº <?= e($k['numero']) ?> · Código de integridade: <?= e($k['hash']) ?></footer>
      </article>

      <?php if (!empty($f['comprovante'])): ?><p class="no-print"><a class="btn" href="./?t=<?= e($token) ?>&amp;comprovante=1" target="_blank">📎 Ver o comprovante de pagamento</a></p><?php endif; ?>

      <article class="papel quebra"><?= dh_contrato_relatorio($k) ?></article>

      <?php if ($podeAssinar): ?>
        <section class="assinar no-print" id="assinar">
          <h2>Assinar o contrato</h2>
          <?php if (!$pend): ?>
            <form method="post">
              <?= $csrf ?><input type="hidden" name="op" value="codigo">
              <label>Seu nome completo <input name="nome" required autocomplete="name" value="<?= e($_POST['nome'] ?? ($k['tipo'] === 'comodato' ? $f['contratante_nome'] : '')) ?>"></label>
              <label>Seu CPF <input name="cpf" required inputmode="numeric" autocomplete="off" placeholder="000.000.000-00" value="<?= e($_POST['cpf'] ?? (strlen(dh_so_digitos($f['contratante_doc'] ?? '')) === 11 ? dh_cpf_formatar($f['contratante_doc']) : '')) ?>"></label>
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
    <?php endif; ?>
  </main>
</body>
</html>
