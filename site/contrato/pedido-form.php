<?php
// Formulário do pedido de chope (incluído por contrato/index.php enquanto o cliente não preencheu)
if (!isset($k, $f, $cervejas)) {
    http_response_code(404);
    exit;
}
$v = fn($n, $padrao = '') => e($_POST[$n] ?? $padrao);
$opcoes = function (string $sel) use ($cervejas): string {
    $h = '<option value="">Escolha o rótulo</option>';
    foreach ($cervejas as $cv) {
        $h .= '<option value="' . e($cv['id']) . '" data-preco="' . e($cv['preco_evento']) . '"' . ($cv['id'] === $sel ? ' selected' : '') . '>'
            . e($cv['nome']) . ($cv['estilo'] !== '' ? ' · ' . e($cv['estilo']) : '') . ' · ' . e(dh_moeda($cv['preco_evento'])) . '/L</option>';
    }
    return $h;
};
$itens = (array) ($_POST['itens'] ?? [['cerveja' => '', 'litros' => '']]);
$comodato = array_values(array_filter((array) ($_POST['comodato'] ?? []), fn($x) => ($x['cerveja'] ?? '') !== '' || ($x['litros'] ?? '') !== ''));
$local = (string) ($_POST['local'] ?? '');
?>
<form method="post" class="papel pedido" id="pedido">
  <?= $csrf ?><input type="hidden" name="op" value="preencher">
  <header class="timbre"><img src="../assets/img/logo-donkey-head.png" alt="Donkey Head Cervejaria" width="56"><span><?= e(DH_EMPRESA['razao']) ?><br>CNPJ <?= e(DH_EMPRESA['cnpj']) ?></span></header>
  <h1>Pedido de chope</h1>
  <p class="numero">Escolha o chope, preencha os seus dados e, em seguida, assine o contrato.</p>

  <h2>1. Chope</h2>
  <?php if (!$cervejas): ?><p class="msg erro">Os rótulos para evento ainda não foram cadastrados. Fale com a Donkey Head Cervejaria.</p><?php endif; ?>
  <div id="linhas-chope">
    <?php foreach ($itens as $n => $it): ?>
      <div class="linha-chope">
        <label>Rótulo <select name="itens[<?= $n ?>][cerveja]"><?= $opcoes((string) ($it['cerveja'] ?? '')) ?></select></label>
        <label>Litros <input name="itens[<?= $n ?>][litros]" inputmode="numeric" value="<?= e($it['litros'] ?? '') ?>" placeholder="Ex.: 50"></label>
      </div>
    <?php endforeach; ?>
  </div>
  <button type="button" class="link" data-mais="linhas-chope" data-nome="itens">+ Adicionar outro rótulo</button>

  <div class="caixa-comodato">
    <label class="check"><input type="checkbox" id="quer-comodato" <?= $comodato ? 'checked' : '' ?>> <strong>Chope em comodato?</strong></label>
    <p class="legal">Barris extras entregues junto com o pedido, sem custo inicial. Os que forem abertos são cobrados pelo valor do litro; os lacrados voltam na coleta, sem cobrança.</p>
    <div id="area-comodato" <?= $comodato ? '' : 'hidden' ?>>
      <div id="linhas-comodato">
        <?php foreach ($comodato ?: [['cerveja' => '', 'litros' => '']] as $n => $it): ?>
          <div class="linha-chope">
            <label>Rótulo <select name="comodato[<?= $n ?>][cerveja]"><?= $opcoes((string) ($it['cerveja'] ?? '')) ?></select></label>
            <label>Litros <input name="comodato[<?= $n ?>][litros]" inputmode="numeric" value="<?= e($it['litros'] ?? '') ?>" placeholder="Ex.: 50"></label>
          </div>
        <?php endforeach; ?>
      </div>
      <button type="button" class="link" data-mais="linhas-comodato" data-nome="comodato">+ Adicionar outro rótulo em comodato</button>
    </div>
  </div>

  <table class="itens resumo"><tr><td>Chope</td><td id="t-chope">R$ 0,00</td></tr><tr><td>Frete (instalação e retirada)</td><td id="t-frete">—</td></tr><tr class="total"><td>Valor total</td><td id="t-total">R$ 0,00</td></tr></table>

  <h2>2. Equipamentos em comodato</h2>
  <p class="legal">Já incluídos no pedido, com o valor de reposição em caso de dano ou perda.</p>
  <table class="itens"><tr><th>Equipamento</th><th>Qtd.</th><th>Valor de reposição</th></tr>
    <?php foreach ($f['equipamentos'] as $eq): ?><tr><td><?= e($eq['descricao']) ?></td><td><?= (int) $eq['quantidade'] ?></td><td><?= e(dh_moeda($eq['valor'])) ?></td></tr><?php endforeach; ?>
  </table>

  <h2>3. Seus dados</h2>
  <label>Nome completo <input name="nome" required autocomplete="name" value="<?= $v('nome') ?>"></label>
  <div class="duas">
    <label>CPF <input name="cpf" required inputmode="numeric" placeholder="000.000.000-00" value="<?= $v('cpf') ?>"></label>
    <label>Celular <input name="celular" required inputmode="tel" autocomplete="tel" placeholder="(85) 99999-9999" value="<?= $v('celular') ?>"></label>
  </div>
  <label>Endereço de entrega <input name="endereco" required autocomplete="street-address" value="<?= $v('endereco') ?>"></label>
  <div class="duas">
    <label>Data de recebimento do equipamento <input type="date" name="entrega_data" required min="<?= date('Y-m-d') ?>" value="<?= $v('entrega_data') ?>"></label>
    <label>Data de devolução do equipamento <input type="date" name="devolucao_data" required min="<?= date('Y-m-d') ?>" value="<?= $v('devolucao_data') ?>"></label>
  </div>
  <p class="rotulo-pedido">A entrega é em Fortaleza?</p>
  <div class="opcoes-local">
    <label class="check"><input type="radio" name="local" value="fortaleza" required <?= $local === 'fortaleza' ? 'checked' : '' ?>> Sim, em Fortaleza <small>(frete de <?= e(dh_moeda(DH_FRETE_FORTALEZA)) ?>, instalação e retirada)</small></label>
    <label class="check"><input type="radio" name="local" value="outra" <?= $local === 'outra' ? 'checked' : '' ?>> Não, em outra cidade <small>(frete sob consulta)</small></label>
  </div>
  <label id="campo-cidade" <?= $local === 'outra' ? '' : 'hidden' ?>>Cidade da entrega <input name="cidade" value="<?= $v('cidade') ?>" placeholder="Ex.: Guaramiranga/CE"></label>
  <label>E-mail <small>(para a cópia do contrato e o código de assinatura)</small><input type="email" name="email" required autocapitalize="none" autocomplete="email" value="<?= $v('email') ?>"></label>
  <p class="legal">Forma de pagamento: <?= e(DH_PAGAMENTOS[$f['pagamento']] ?? '') ?><?= trim($f['observacoes']) !== '' ? ' · ' . e($f['observacoes']) : '' ?></p>
  <button class="btn grande" id="bt-pedido">Continuar para o contrato</button>
</form>
<script>
  (function () {
    var form = document.getElementById('pedido'), freteFortaleza = <?= json_encode(DH_FRETE_FORTALEZA) ?>;
    function brl(n) { return n.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }); }
    function total() {
      var s = 0;
      document.querySelectorAll('#linhas-chope .linha-chope').forEach(function (l) {
        var op = l.querySelector('select').selectedOptions[0];
        s += (op && op.dataset.preco ? parseFloat(op.dataset.preco) : 0) * (parseFloat((l.querySelector('input').value || '').replace(',', '.')) || 0);
      });
      var local = (form.querySelector('[name=local]:checked') || {}).value;
      document.getElementById('t-chope').textContent = brl(s);
      document.getElementById('t-frete').textContent = local === 'fortaleza' ? brl(freteFortaleza) : (local === 'outra' ? 'Sob consulta' : 'Fortaleza: ' + brl(freteFortaleza));
      document.getElementById('t-total').textContent = local === 'outra' ? brl(s) + ' + frete' : brl(s + (local === 'fortaleza' ? freteFortaleza : 0));
      document.getElementById('campo-cidade').hidden = local !== 'outra';
      document.getElementById('bt-pedido').textContent = local === 'outra' ? 'Enviar pedido para calcular o frete' : 'Continuar para o contrato';
    }
    document.querySelectorAll('[data-mais]').forEach(function (b) {
      b.addEventListener('click', function () {
        var caixa = document.getElementById(b.dataset.mais), modelo = caixa.querySelector('.linha-chope'), n = caixa.children.length;
        var nova = modelo.cloneNode(true);
        nova.querySelector('select').name = b.dataset.nome + '[' + n + '][cerveja]'; nova.querySelector('select').value = '';
        nova.querySelector('input').name = b.dataset.nome + '[' + n + '][litros]'; nova.querySelector('input').value = '';
        caixa.appendChild(nova);
      });
    });
    var quer = document.getElementById('quer-comodato'), area = document.getElementById('area-comodato');
    quer.addEventListener('change', function () {
      area.hidden = !quer.checked;
      // Desmarcou: limpa as linhas para não ir chope em comodato sem querer
      if (!quer.checked) area.querySelectorAll('select, input').forEach(function (x) { x.value = ''; });
    });
    form.addEventListener('input', total);
    form.addEventListener('change', total);
    total();
  })();
</script>
