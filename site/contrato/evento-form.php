<?php
// Formulário do evento corporativo no pub (incluído por contrato/index.php enquanto o cliente não preencheu).
// A Donkey Head Cervejaria já definiu o pacote (espaço, valor por pessoa, atração, menu); o cliente completa
// os dados dele, a data, o horário, os convidados, os adicionais e o responsável pelo evento.
if (!isset($k, $f, $csrf)) {
    http_response_code(404);
    exit;
}
$v = fn($n, $padrao = '') => e($_POST[$n] ?? $padrao);
$doc = dh_so_digitos($f['contratante_doc']);
$adicMarcados = $_POST ? (array) ($_POST['adicionais'] ?? []) : array_keys(array_filter((array) $f['adicionais']));
$max = (int) ($f['max_participantes'] ?: 0);
?>
<form method="post" class="papel pedido" id="pedido">
  <?= $csrf ?><input type="hidden" name="op" value="preencher_evento">
  <header class="timbre"><img src="../assets/img/logo-donkey-head-mini.png" alt="Donkey Head Cervejaria" width="56"><span><?= e(DH_EMPRESA['razao']) ?><br>CNPJ <?= e(DH_EMPRESA['cnpj']) ?></span></header>
  <h1>Evento corporativo no pub</h1>
  <p class="numero">Preencha os dados do evento e os seus dados. Em seguida, você confere e assina o contrato.</p>

  <h2>1. O pacote</h2>
  <table class="itens">
    <tr><td>Espaço</td><td><?= e($f['espaco']) ?></td></tr>
    <tr><td>Valor por pessoa</td><td><?= e(dh_moeda($f['valor_pessoa'])) ?></td></tr>
    <?php if ($max): ?><tr><td>Máximo de participantes</td><td><?= $max ?></td></tr><?php endif; ?>
    <?php if (trim($f['atracao']) !== ''): ?><tr><td>Atração</td><td><?= e($f['atracao']) ?></td></tr><?php endif; ?>
  </table>
  <?php if (trim($f['cardapio']) !== ''): ?>
    <details class="cardapio-evento"><summary>Ver o que está incluído (menu)</summary><p class="legal"><?= nl2br(e($f['cardapio'])) ?></p></details>
  <?php endif; ?>

  <h2>2. O evento</h2>
  <label>Data do evento <input type="date" name="data" required min="<?= date('Y-m-d') ?>" value="<?= $v('data', $f['data']) ?>"></label>
  <div class="duas">
    <label>Início <input type="time" name="hora_inicio" required value="<?= $v('hora_inicio', $f['hora_inicio']) ?>"></label>
    <label>Término <input type="time" name="hora_fim" required value="<?= $v('hora_fim', $f['hora_fim']) ?>"></label>
  </div>
  <label>Quantidade de convidados <input name="convidados" id="convidados" required inputmode="numeric" <?= $max ? 'max="' . $max . '"' : '' ?> value="<?= $v('convidados', $f['convidados']) ?>" placeholder="<?= $max ? 'Até ' . $max : 'Ex.: 30' ?>"></label>
  <p class="rotulo-pedido">Adicionais por pessoa <small>(opcional)</small></p>
  <div class="opcoes-local">
    <?php foreach (DH_EVENTO_ADICIONAIS as $ch => [$nome, $valor]): ?>
      <label class="check"><input type="checkbox" name="adicionais[]" value="<?= $ch ?>" data-valor="<?= $valor ?>" <?= in_array($ch, $adicMarcados, true) ? 'checked' : '' ?>> <?= e($nome) ?> <small>+ <?= e(dh_moeda($valor)) ?> por pessoa</small></label>
    <?php endforeach; ?>
  </div>
  <table class="itens resumo"><tr><td>Por pessoa</td><td id="t-pessoa">—</td></tr><tr class="total"><td>Valor total</td><td id="t-total">—</td></tr></table>

  <h2>3. Seus dados</h2>
  <div class="busca-cadastro">
    <label>CPF ou CNPJ <small>(se já fez evento ou pedido com a gente, puxamos o seu cadastro)</small>
      <span class="cupom-linha"><input name="cpf" id="cpf" required inputmode="numeric" placeholder="CPF ou CNPJ" value="<?= $v('cpf', $doc !== '' ? dh_cpf_formatar($doc) : '') ?>"><button type="button" class="btn" id="bt-cadastro">Buscar meu cadastro</button></span></label>
    <p class="cupom-msg" id="cadastro-msg" aria-live="polite"></p>
    <div class="cupom-linha" id="cadastro-codigo" hidden><input id="codigo-cadastro" inputmode="numeric" maxlength="6" autocomplete="one-time-code" placeholder="Código de 6 números" aria-label="Código recebido por e-mail"><button type="button" class="btn" id="bt-codigo">Usar meus dados</button></div>
  </div>
  <label>Nome completo ou razão social <input name="nome" required autocomplete="name" value="<?= $v('nome', $f['contratante_nome']) ?>"></label>
  <div class="duas">
    <label>Celular <input name="celular" required inputmode="tel" autocomplete="tel" placeholder="(85) 99999-9999" value="<?= $v('celular', $f['contratante_telefone']) ?>"></label>
    <label>E-mail <small>(para a cópia do contrato e o código de assinatura)</small><input type="email" name="email" required autocapitalize="none" autocomplete="email" value="<?= $v('email', $f['contratante_email']) ?>"></label>
  </div>
  <label>Endereço <input name="endereco" required autocomplete="street-address" value="<?= $v('endereco', $f['contratante_endereco']) ?>"></label>

  <h2>4. Responsável no dia do evento</h2>
  <p class="legal">Deixe em branco se for você mesmo.</p>
  <label>Nome <input name="responsavel_nome" value="<?= $v('responsavel_nome', $f['responsavel_nome']) ?>"></label>
  <div class="duas">
    <label>Celular <input name="responsavel_telefone" inputmode="tel" value="<?= $v('responsavel_telefone', $f['responsavel_telefone']) ?>"></label>
    <label>E-mail <input type="email" name="responsavel_email" autocapitalize="none" value="<?= $v('responsavel_email', $f['responsavel_email']) ?>"></label>
  </div>

  <h2>5. Pagamento</h2>
  <p>Forma de pagamento: <strong><?= e(DH_PAGAMENTOS[$f['pagamento']] ?? '') ?></strong> · Valor: <strong id="t-pagar">—</strong></p>
  <?php $instrucoes = trim((string) ($c['config']['pagamento_instrucoes'] ?? '')); if ($instrucoes !== ''): ?><p class="dados-pagamento"><?= nl2br(e($instrucoes)) ?></p><?php endif; ?>
  <button class="btn grande" id="bt-pedido">Continuar para o contrato</button>
</form>
<script>
  (function () {
    var form = document.getElementById('pedido'), base = <?= json_encode((float) $f['valor_pessoa']) ?>, max = <?= $max ?>;
    function brl(n) { return n.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }); }
    function total() {
      var pp = base;
      form.querySelectorAll('[name="adicionais[]"]:checked').forEach(function (x) { pp += parseFloat(x.dataset.valor) || 0; });
      var n = Math.max(0, parseInt(document.getElementById('convidados').value, 10) || 0);
      document.getElementById('t-pessoa').textContent = brl(pp);
      document.getElementById('t-total').textContent = n ? brl(pp * n) + ' (' + n + ' convidados)' : '—';
      document.getElementById('t-pagar').textContent = n ? brl(pp * n) : '—';
    }
    form.addEventListener('input', total);
    form.addEventListener('change', total);
    total();
  })();
  // Cadastro: CPF → código no e-mail do cadastro → dados preenchidos
  (function () {
    var form = document.getElementById('pedido'), msg = document.getElementById('cadastro-msg'), caixa = document.getElementById('cadastro-codigo');
    function enviar(dados, depois) {
      var fd = new FormData(); fd.append('csrf', form.querySelector('[name=csrf]').value);
      Object.keys(dados).forEach(function (k) { fd.append(k, dados[k]); });
      msg.textContent = 'Aguarde…'; msg.className = 'cupom-msg';
      fetch(location.href, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); })
        .then(function (j) { msg.textContent = j.msg; msg.className = 'cupom-msg ' + (j.ok ? 'ok' : 'erro'); if (j.ok) depois(j); })
        .catch(function () { msg.textContent = 'Não foi possível buscar agora. Preencha os dados abaixo.'; msg.className = 'cupom-msg erro'; });
    }
    document.getElementById('bt-cadastro').addEventListener('click', function () {
      enviar({ op: 'cadastro_buscar', cpf: document.getElementById('cpf').value }, function (j) {
        caixa.hidden = !j.enviado; if (j.enviado) document.getElementById('codigo-cadastro').focus();
      });
    });
    document.getElementById('bt-codigo').addEventListener('click', function () {
      enviar({ op: 'cadastro_codigo', codigo: document.getElementById('codigo-cadastro').value }, function (j) {
        var d = j.dados; caixa.hidden = true;
        ['nome', 'cpf', 'celular', 'email', 'endereco'].forEach(function (k) { if (d[k]) form.querySelector('[name=' + k + ']').value = d[k]; });
      });
    });
    document.getElementById('codigo-cadastro').addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); document.getElementById('bt-codigo').click(); } });
    document.getElementById('cpf').addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); document.getElementById('bt-cadastro').click(); } });
  })();
</script>
