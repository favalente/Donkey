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
// Cliente da base: os dados já vêm preenchidos (o painel escolheu o cliente ao criar o pedido)
$cidadeBase = (string) ($f['cidade'] ?? '');
$local = (string) ($_POST['local'] ?? ($cidadeBase === '' ? '' : (stripos($cidadeBase, 'fortaleza') !== false ? 'fortaleza' : 'outra')));
$doc = dh_so_digitos($f['contratante_doc']);
$comprovante = $k['campos']['comprovante'] ?? null;
// Produtos que o pedido pergunta ("Deseja copos personalizados?", "Deseja tirador de chope?"…)
$adicionais = dh_produtos_pedido();
$qtdExtra = fn(string $id) => max(0, (int) ($_POST['extras'][$id] ?? 0));
$freteFortaleza = dh_frete_fortaleza();
$instrucoes = trim((string) ($c['config']['pagamento_instrucoes'] ?? ''));
?>
<form method="post" class="papel pedido" id="pedido" enctype="multipart/form-data">
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
        <button type="button" class="tirar-linha" aria-label="Remover este rótulo" title="Remover">×</button>
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
            <button type="button" class="tirar-linha" aria-label="Remover este rótulo" title="Remover">×</button>
          </div>
        <?php endforeach; ?>
      </div>
      <button type="button" class="link" data-mais="linhas-comodato" data-nome="comodato">+ Adicionar outro rótulo em comodato</button>
    </div>
  </div>

  <?php foreach ($adicionais as $ad): $q = $qtdExtra($ad['id']); ?>
    <div class="caixa-comodato caixa-extra" data-extra="<?= e($ad['id']) ?>" data-preco="<?= e($ad['preco']) ?>" data-nome="<?= e($ad['nome']) ?>" data-unidade="<?= e($ad['unidade']) ?>">
      <label class="check"><input type="checkbox" class="quer-extra" <?= $q ? 'checked' : '' ?>> <strong>Deseja <?= e(mb_strtolower($ad['nome'])) ?>?</strong></label>
      <p class="legal"><?= $ad['detalhe'] !== '' ? e(mb_strtoupper(mb_substr($ad['detalhe'], 0, 1)) . mb_substr($ad['detalhe'], 1)) . '. ' : '' ?><?= e(dh_moeda($ad['preco'])) ?><?= $ad['unidade'] !== '' ? ' por ' . e($ad['unidade']) : '' ?>.</p>
      <div class="area-extra" <?= $q ? '' : 'hidden' ?>>
        <div class="contador">
          <button type="button" class="menos" data-passo="-1" aria-label="Menos um">−</button>
          <input name="extras[<?= e($ad['id']) ?>]" class="qtd-extra" inputmode="numeric" value="<?= $q ?>" aria-label="Quantidade de <?= e(mb_strtolower($ad['nome'])) ?>">
          <button type="button" class="mais" data-passo="1" aria-label="Mais um">+</button>
          <span class="info-extra"></span>
        </div>
      </div>
    </div>
  <?php endforeach; ?>

  <div class="cupom">
    <label for="cupom">Tem cupom de desconto?</label>
    <div class="cupom-linha"><input id="cupom" name="cupom" autocapitalize="characters" autocomplete="off" maxlength="30" placeholder="Código do cupom" value="<?= $v('cupom') ?>"><button type="button" class="btn" id="bt-cupom">Aplicar</button></div>
    <p class="cupom-msg" id="cupom-msg" aria-live="polite"></p>
  </div>

  <table class="itens resumo"><tr><td>Chope</td><td id="t-chope">R$ 0,00</td></tr><tr id="l-desconto" hidden><td id="t-desconto-rot">Desconto</td><td id="t-desconto">R$ 0,00</td></tr><tr id="l-extras" hidden><td>Adicionais</td><td id="t-extras">R$ 0,00</td></tr><tr><td>Frete (instalação e retirada)</td><td id="t-frete">—</td></tr><tr class="total"><td>Valor total</td><td id="t-total">R$ 0,00</td></tr></table>

  <h2>2. Equipamentos em comodato</h2>
  <p class="legal">Já incluídos no pedido, com o valor de reposição em caso de dano ou perda.</p>
  <table class="itens"><tr><th>Equipamento</th><th>Qtd.</th><th>Valor de reposição</th></tr>
    <?php foreach ($f['equipamentos'] as $eq): ?><tr><td><?= e($eq['descricao']) ?></td><td><?= (int) $eq['quantidade'] ?></td><td><?= e(dh_moeda($eq['valor'])) ?></td></tr><?php endforeach; ?>
  </table>

  <h2>3. Seus dados</h2>
  <label>Nome completo <input name="nome" required autocomplete="name" value="<?= $v('nome', $f['contratante_nome']) ?>"></label>
  <div class="duas">
    <label>CPF <input name="cpf" required inputmode="numeric" placeholder="000.000.000-00" value="<?= $v('cpf', $doc !== '' ? dh_cpf_formatar($doc) : '') ?>"></label>
    <label>Celular <input name="celular" required inputmode="tel" autocomplete="tel" placeholder="(85) 99999-9999" value="<?= $v('celular', $f['contratante_telefone']) ?>"></label>
  </div>
  <label>Endereço de entrega <input name="endereco" required autocomplete="street-address" value="<?= $v('endereco', $f['entrega_endereco']) ?>"></label>
  <div class="duas">
    <label>Data de recebimento do equipamento <input type="date" name="entrega_data" required min="<?= date('Y-m-d') ?>" value="<?= $v('entrega_data') ?>"></label>
    <label>Horário de entrega estimado <input type="time" name="entrega_hora" required value="<?= $v('entrega_hora') ?>"></label>
  </div>
  <p class="obs">O horário é apenas uma estimativa; o mesmo deve ser combinado diretamente com o entregador.</p>
  <label>Data de devolução do equipamento <input type="date" name="devolucao_data" required min="<?= date('Y-m-d') ?>" value="<?= $v('devolucao_data') ?>"></label>
  <p class="obs">O equipamento deve ser devolvido no máximo no dia seguinte ao evento, exceto quando acordado com a Donkey Head Cervejaria.</p>
  <p class="rotulo-pedido">A entrega é em Fortaleza?</p>
  <div class="opcoes-local">
    <label class="check"><input type="radio" name="local" value="fortaleza" required <?= $local === 'fortaleza' ? 'checked' : '' ?>> Sim, em Fortaleza <small>(frete de <?= e(dh_moeda($freteFortaleza)) ?>, instalação e retirada)</small></label>
    <label class="check"><input type="radio" name="local" value="outra" <?= $local === 'outra' ? 'checked' : '' ?>> Não, em outra cidade <small>(digite o valor combinado com o vendedor)</small></label>
  </div>
  <div class="duas" id="campo-cidade" <?= $local === 'outra' ? '' : 'hidden' ?>>
    <label>Cidade da entrega <input name="cidade" value="<?= $v('cidade', $local === 'outra' ? $cidadeBase : '') ?>" placeholder="Ex.: Guaramiranga/CE"></label>
    <label>Valor do frete (R$) <input name="frete_outra" id="frete-outra" inputmode="decimal" value="<?= $v('frete_outra') ?>" placeholder="Combinado com o vendedor"></label>
  </div>
  <label>E-mail <small>(para a cópia do contrato e o código de assinatura)</small><input type="email" name="email" required autocapitalize="none" autocomplete="email" value="<?= $v('email', $f['contratante_email']) ?>"></label>

  <h2>4. Pagamento</h2>
  <p>Forma de pagamento: <strong><?= e(DH_PAGAMENTOS[$f['pagamento']] ?? '') ?></strong> · Valor a pagar: <strong id="t-pagar">R$ 0,00</strong></p>
  <?php if ($instrucoes !== ''): ?><p class="dados-pagamento"><?= nl2br(e($instrucoes)) ?></p><?php endif; ?>
  <?php if (trim($f['observacoes']) !== ''): ?><p class="observacoes" style="color:#c62828;font-weight:bold">Observações: <?= nl2br(e($f['observacoes'])) ?></p><?php endif; ?>
  <?php if ($comprovante): ?>
    <p class="comprovante-ok">✓ Comprovante recebido: <a href="./?t=<?= e($token) ?>&amp;comprovante=1" target="_blank"><?= e($comprovante['nome']) ?></a></p>
    <label>Trocar o comprovante <small>(opcional)</small><input type="file" name="comprovante" accept="image/*,application/pdf"></label>
  <?php else: ?>
    <label>Comprovante de pagamento <small>(foto, print ou PDF; o contrato só vale com ele)</small><input type="file" name="comprovante" required accept="image/*,application/pdf"></label>
  <?php endif; ?>
  <button class="btn grande" id="bt-pedido">Continuar para o contrato</button>
</form>
<script>
  (function () {
    var form = document.getElementById('pedido'), freteFortaleza = <?= json_encode($freteFortaleza) ?>, cupom = null;
    // Plural simples em português: tirador → tiradores, taça → taças, barril → barris
    function plural(u) {
      if (/[rz]$/i.test(u)) return u + 'es';
      if (/l$/i.test(u)) return u.slice(0, -1) + 'is';
      if (/m$/i.test(u)) return u.slice(0, -1) + 'ns';
      if (/s$/i.test(u)) return u;
      return u + 's';
    }
    function brl(n) { return n.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }); }
    function num(x) { x = (x || '').trim(); if (x.indexOf(',') >= 0) x = x.replace(/\./g, '').replace(',', '.'); return parseFloat(x) || 0; }
    function total() {
      var s = 0;
      document.querySelectorAll('#linhas-chope .linha-chope').forEach(function (l) {
        var op = l.querySelector('select').selectedOptions[0];
        s += (op && op.dataset.preco ? parseFloat(op.dataset.preco) : 0) * num(l.querySelector('input').value);
      });
      var local = (form.querySelector('[name=local]:checked') || {}).value;
      var frete = local === 'fortaleza' ? freteFortaleza : (local === 'outra' ? num(document.getElementById('frete-outra').value) : 0);
      // Adicionais (copos, tirador…): quantidade × preço do cadastro
      var copos = 0;
      document.querySelectorAll('.caixa-extra').forEach(function (cx) {
        var q = cx.querySelector('.quer-extra').checked ? Math.max(0, parseInt(cx.querySelector('.qtd-extra').value, 10) || 0) : 0, v = q * parseFloat(cx.dataset.preco);
        cx.querySelector('.info-extra').textContent = q + (cx.dataset.unidade ? ' ' + (q === 1 ? cx.dataset.unidade : plural(cx.dataset.unidade)) : '') + ' · ' + brl(v);
        copos += v;
      });
      document.getElementById('l-extras').hidden = !copos;
      document.getElementById('t-extras').textContent = brl(copos);
      var desc = cupom ? Math.min(s, Math.round((cupom.tipo === 'pct' ? s * cupom.valor / 100 : cupom.valor) * 100) / 100) : 0;
      document.getElementById('t-chope').textContent = brl(s);
      document.getElementById('l-desconto').hidden = !desc;
      document.getElementById('t-desconto-rot').textContent = cupom ? 'Desconto (cupom ' + cupom.codigo + ')' : 'Desconto';
      document.getElementById('t-desconto').textContent = '− ' + brl(desc);
      document.getElementById('t-frete').textContent = local ? brl(frete) : 'Fortaleza: ' + brl(freteFortaleza);
      document.getElementById('t-total').textContent = brl(s - desc + copos + frete);
      document.getElementById('t-pagar').textContent = brl(s - desc + copos + frete);
      document.getElementById('campo-cidade').hidden = local !== 'outra';
      document.querySelectorAll('#campo-cidade input').forEach(function (x) { x.required = local === 'outra'; });
    }
    // Cupom: confere no servidor e mostra o desconto no total
    var cupomInput = document.getElementById('cupom'), cupomMsg = document.getElementById('cupom-msg');
    function aplicarCupom() {
      var cod = cupomInput.value.trim();
      cupom = null; cupomMsg.textContent = ''; cupomMsg.className = 'cupom-msg';
      if (!cod) { total(); return; }
      fetch('./?t=<?= rawurlencode($token) ?>&cupom=' + encodeURIComponent(cod), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          cupomMsg.textContent = j.msg; cupomMsg.className = 'cupom-msg ' + (j.ok ? 'ok' : 'erro');
          cupom = j.ok ? j : null; if (j.ok) cupomInput.value = j.codigo;
          total();
        })
        .catch(function () { cupomMsg.textContent = 'Não foi possível conferir o cupom agora.'; cupomMsg.className = 'cupom-msg erro'; });
    }
    document.getElementById('bt-cupom').addEventListener('click', aplicarCupom);
    cupomInput.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); aplicarCupom(); } });
    cupomInput.addEventListener('input', function () { if (cupom) { cupom = null; cupomMsg.textContent = ''; total(); } });
    if (cupomInput.value.trim()) aplicarCupom();
    document.querySelectorAll('[data-mais]').forEach(function (b) {
      b.addEventListener('click', function () {
        // Índice novo que não repete os que já existem (linhas podem ter sido removidas)
        var caixa = document.getElementById(b.dataset.mais), modelo = caixa.querySelector('.linha-chope'), n = Date.now();
        var nova = modelo.cloneNode(true);
        nova.querySelector('select').name = b.dataset.nome + '[' + n + '][cerveja]'; nova.querySelector('select').value = '';
        nova.querySelector('input').name = b.dataset.nome + '[' + n + '][litros]'; nova.querySelector('input').value = '';
        caixa.appendChild(nova);
      });
    });
    // × remove a linha; se for a única, só limpa
    form.addEventListener('click', function (ev) {
      var x = ev.target.closest('.tirar-linha');
      if (!x) return;
      var linha = x.closest('.linha-chope');
      if (linha.parentNode.querySelectorAll('.linha-chope').length > 1) linha.remove();
      else linha.querySelectorAll('select, input').forEach(function (c) { c.value = ''; });
      total();
    });
    // Adicionais: marcar mostra o contador (começa em 1); desmarcar zera
    document.querySelectorAll('.caixa-extra').forEach(function (cx) {
      var quer = cx.querySelector('.quer-extra'), campo = cx.querySelector('.qtd-extra');
      quer.addEventListener('change', function () {
        cx.querySelector('.area-extra').hidden = !quer.checked;
        campo.value = quer.checked ? Math.max(1, parseInt(campo.value, 10) || 0) : 0;
        total();
      });
      cx.querySelectorAll('[data-passo]').forEach(function (b) {
        b.addEventListener('click', function () { campo.value = Math.max(1, (parseInt(campo.value, 10) || 0) + parseInt(b.dataset.passo, 10)); total(); });
      });
      if (!quer.checked) campo.value = 0;
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
