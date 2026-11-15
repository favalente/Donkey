<?php
// Aba Contagem: contagem de estoque semanal do barman (por casa e por lugar), com histórico e
// comparação com a contagem anterior.

function contagem_casa(): string
{
    global $usuario;
    if (isset(DH_CONTAGEM_CASAS[$_GET['casa'] ?? ''])) {
        $_SESSION['contagem_casa'] = $_GET['casa'];
    }
    return $_SESSION['contagem_casa'] ?? (isset(DH_UNIDADES[$usuario['unidade'] ?? '']) ? $usuario['unidade'] : array_key_first(DH_UNIDADES));
}

function contagem_indice(array $c, string $id): ?int
{
    foreach ($c['contagens'] as $i => $x) {
        if ($x['id'] === $id) {
            return $i;
        }
    }
    return null;
}

// Cervejas engatadas agora na casa (nome curto do xMenu, quando tiver)
function contagem_engatadas(string $casa): array
{
    $r = [];
    foreach (dh_taplist(dh_carregar(), $casa) as $cv) {
        $r[] = trim($cv['nome_xmenu'] ?? '') !== '' ? $cv['nome_xmenu'] : $cv['nome'];
    }
    return array_values(array_unique($r));
}

function contagem_acao(string $acao): bool
{
    global $usuario, $telaErro;
    switch ($acao) {
        case 'contagem':
        case 'contagem-editar':
        case 'contagem-ver':
            exigir('contagem');
            return true;

        case 'contagem-nova':
            exigir_post();
            exigir('contagem');
            $telaErro = 'contagem';
            $casa = isset(DH_CONTAGEM_CASAS[$_POST['casa'] ?? '']) ? $_POST['casa'] : contagem_casa();
            $c = dh_contagem();
            $modo = (string) ($_POST['modo'] ?? '');
            $linhas = [];
            if ($modo === 'copiar') {
                $ant = dh_contagem_anterior($c, $casa);
                foreach ($ant['linhas'] ?? [] as $l) {
                    $linhas[] = $l; // mesmas quantidades: só corrige o que mudou
                }
            } elseif ($modo === 'texto') {
                $linhas = dh_contagem_do_texto((string) ($_POST['texto'] ?? ''));
                if (!$linhas) {
                    throw new RuntimeException('Não encontrei itens com quantidade nesse texto. Cole a contagem como mandava no WhatsApp (ex.: "3 fardo guaraná zero").');
                }
            }
            if ($modo === 'engatadas' && $casa !== 'fabrica') {
                foreach (contagem_engatadas($casa) as $nome) {
                    $linhas[] = ['local' => 'Câmara produção', 'nome' => $nome, 'unidade' => 'barril', 'qtd' => 1.0, 'obs' => '', 'litros' => '50', 'barris' => []];
                }
            }
            // Ainda não grava: a contagem só é guardada ao tocar em Salvar ou Enviar
            $_SESSION['contagem_nova'] = ['id' => '', 'casa' => $casa, 'data' => date('Y-m-d'), 'status' => 'rascunho', 'por' => $usuario['nome'], 'em' => date('Y-m-d H:i:s'), 'linhas' => $linhas];
            ir('./?acao=contagem-editar&nova=1');

        case 'contagem-salvar':
            exigir_post();
            exigir('contagem');
            $c = dh_contagem();
            $id = (string) ($_POST['id'] ?? '');
            if ($id === '') {
                // Primeira vez que essa contagem é salva
                $nova = $_SESSION['contagem_nova'] ?? null;
                $casaN = isset(DH_CONTAGEM_CASAS[$_POST['casa'] ?? '']) ? $_POST['casa'] : ($nova['casa'] ?? contagem_casa());
                $c['contagens'][] = ['id' => dh_id_novo('k'), 'casa' => $casaN, 'data' => date('Y-m-d'), 'status' => 'rascunho', 'por' => $usuario['nome'], 'em' => date('Y-m-d H:i:s'), 'linhas' => []];
                $i = count($c['contagens']) - 1;
                unset($_SESSION['contagem_nova']);
            } else {
                $i = contagem_indice($c, $id);
            }
            if ($i === null) {
                throw new RuntimeException('Contagem não encontrada.');
            }
            $telaErro = 'contagem';
            $recebidas = json_decode((string) ($_POST['json'] ?? ''), true);
            $linhas = [];
            foreach (is_array($recebidas) ? $recebidas : [] as $l) {
                $nome = mb_strimwidth(trim((string) ($l['nome'] ?? '')), 0, 80);
                if ($nome === '') {
                    continue;
                }
                $linhas[] = ['local' => mb_strimwidth(trim((string) ($l['local'] ?? '')) ?: 'Estoque', 0, 60), 'nome' => $nome,
                    'unidade' => in_array($l['unidade'] ?? '', DH_CONTAGEM_UNIDADES, true) ? $l['unidade'] : 'un',
                    'qtd' => dh_qtd_ler((string) ($l['qtd'] ?? '')), 'obs' => mb_strimwidth(trim((string) ($l['obs'] ?? '')), 0, 80),
                    'litros' => in_array((string) ($l['litros'] ?? ''), DH_CONTAGEM_LITROS, true) ? (string) $l['litros'] : '',
                    'barris' => array_values(array_map('strval', array_filter((array) ($l['barris'] ?? []), fn($b) => isset(DH_CONTAGEM_STATUS[(string) $b]))))];
                $k = count($linhas) - 1;
                if ($linhas[$k]['unidade'] === 'barril') {
                    $linhas[$k]['barris'] = dh_contagem_barris($linhas[$k]);
                } else {
                    $linhas[$k]['barris'] = [];
                    $linhas[$k]['litros'] = '';
                }
            }
            $data = (string) ($_POST['data'] ?? '');
            $c['contagens'][$i]['linhas'] = $linhas;
            $c['contagens'][$i]['data'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) ? $data : $c['contagens'][$i]['data'];
            $c['contagens'][$i]['alterado_por'] = $usuario['nome'];
            $c['contagens'][$i]['alterado_em'] = date('Y-m-d H:i:s');
            $enviar = !empty($_POST['enviar']);
            $primeiraVez = $enviar && $c['contagens'][$i]['status'] !== 'enviada';
            if ($enviar) {
                $c['contagens'][$i]['status'] = 'enviada';
                $c['contagens'][$i]['enviada_em'] ??= date('Y-m-d H:i:s');
            }
            dh_contagem_salvar($c);
            $x = $c['contagens'][$i];
            if ($primeiraVez) {
                dh_notificar('contagem', '📦', 'Contagem de estoque · ' . dh_contagem_casa_nome($x['casa']), count($linhas) . ' itens, contados por ' . $usuario['nome'] . ' em ' . dh_data_br($x['data']) . '.',
                    './?acao=contagem-ver&id=' . $x['id'], $usuario['login'], [], 'contagem_enviada');
            }
            ir($enviar ? './?acao=contagem-ver&id=' . $x['id'] : './?acao=contagem-editar&id=' . $x['id'], $enviar ? 'Contagem enviada. Obrigado!' : 'Contagem salva (rascunho). Dá para continuar depois.');

        case 'contagem-excluir':
            exigir_post();
            exigir('contagem');
            $c = dh_contagem();
            $i = contagem_indice($c, (string) ($_POST['id'] ?? ''));
            if ($i !== null) {
                array_splice($c['contagens'], $i, 1);
                dh_contagem_salvar($c);
            }
            ir('./?acao=contagem', 'Contagem apagada.');
    }
    return false;
}

function contagem_tela(string $tela): bool
{
    switch ($tela) {
        case 'contagem':
            contagem_tela_lista();
            return true;
        case 'contagem-editar':
            contagem_tela_editar();
            return true;
        case 'contagem-ver':
            contagem_tela_ver();
            return true;
    }
    return false;
}

function contagem_tela_lista(): void
{
    global $csrf;
    $casa = contagem_casa();
    $c = dh_contagem();
    $daCasa = array_values(array_filter($c['contagens'], fn($x) => $x['casa'] === $casa));
    usort($daCasa, fn($a, $b) => [$b['data'], $b['em']] <=> [$a['data'], $a['em']]);
    $rascunho = array_values(array_filter($daCasa, fn($x) => $x['status'] === 'rascunho'))[0] ?? null;
    $ant = dh_contagem_anterior($c, $casa);
    ?>
      <h1>📦 Contagem de estoque</h1>
      <nav class="sede-escolha" aria-label="Contagem de qual casa">
        <?php foreach (DH_CONTAGEM_CASAS as $u => $nomeU): ?>
          <a class="sede-opcao u-<?= $u ?><?= $casa === $u ? ' ativa' : '' ?>" href="./?acao=contagem&amp;casa=<?= $u ?>"><?= $casa === $u ? '✓ ' : '' ?><?= e($nomeU) ?></a>
        <?php endforeach; ?>
      </nav>

      <?php if ($rascunho): ?>
        <a class="btn primario largo" href="./?acao=contagem-editar&amp;id=<?= e($rascunho['id']) ?>">✏️ Continuar a contagem de <?= e(dh_data_br($rascunho['data'])) ?></a>
        <p class="dica centro">Contagem começada e ainda não enviada.</p>
      <?php endif; ?>

      <h2>Nova contagem</h2>
      <div class="ct-novas">
        <?php if ($ant): ?>
          <form method="post" action="./?acao=contagem-nova"><?= $csrf ?><input type="hidden" name="casa" value="<?= e($casa) ?>"><input type="hidden" name="modo" value="copiar">
            <button class="ct-nova"><span>📋</span><strong>Copiar a contagem de <?= e(date('d/m', strtotime($ant['data']))) ?></strong><small>Vêm todos os <?= count($ant['linhas']) ?> itens com as quantidades; é só corrigir o que mudou.</small></button></form>
        <?php endif; ?>
        <?php if ($casa !== 'fabrica'): ?>
        <form method="post" action="./?acao=contagem-nova"><?= $csrf ?><input type="hidden" name="casa" value="<?= e($casa) ?>"><input type="hidden" name="modo" value="engatadas">
          <button class="ct-nova"><span>🍺</span><strong>Começar pelas cervejas engatadas</strong><small><?= $casa === 'fabrica' ? 'A câmara de produção vem com o chope engatado nas duas casas; adicione o resto.' : 'A câmara de produção vem com o chope que está na tap list; adicione o resto.' ?></small></button></form>
        <?php else: ?>
        <form method="post" action="./?acao=contagem-nova"><?= $csrf ?><input type="hidden" name="casa" value="<?= e($casa) ?>"><input type="hidden" name="modo" value="vazia">
          <button class="ct-nova"><span>🏭</span><strong>Começar uma contagem vazia</strong><small>Câmara produção e câmara estoque, para adicionar as cervejas dos tanques.</small></button></form>
        <?php endif; ?>
        <button type="button" class="ct-nova" onclick="var f=document.getElementById('ct-colar-form'); f.hidden=!f.hidden; if(!f.hidden) f.querySelector('textarea').focus();"><span>📝</span><strong>Colar a contagem do WhatsApp</strong><small>Para aproveitar a última contagem que foi mandada em texto.</small></button>
        <form method="post" action="./?acao=contagem-nova" id="ct-colar-form" class="ct-colar-form" hidden><?= $csrf ?><input type="hidden" name="casa" value="<?= e($casa) ?>"><input type="hidden" name="modo" value="texto">
          <label>Cole aqui a mensagem da contagem <textarea name="texto" rows="8" placeholder="Câmara produção&#10;Softhop 1 barril&#10;&#10;Estoque seco&#10;3 fardo guaraná zero&#10;1/2 fardo de fanta uva"></textarea></label>
          <button class="btn primario largo">Ler o texto</button>
        </form>
      </div>

      <h2>Contagens</h2>
      <?php if (!$daCasa): ?><p class="dica">Nenhuma contagem ainda.</p><?php endif; ?>
      <ul class="lista-reservas">
        <?php foreach (array_slice($daCasa, 0, 30) as $x): ?>
          <li><a class="ct-hist" href="./?acao=<?= $x['status'] === 'enviada' ? 'contagem-ver' : 'contagem-editar' ?>&amp;id=<?= e($x['id']) ?>">
            <span class="lr-data"><strong><?= date('d', strtotime($x['data'])) ?></strong><?= e(mb_substr(DH_MESES[(int) date('n', strtotime($x['data']))], 0, 3)) ?></span>
            <span class="lr-info"><strong><?= count($x['linhas']) ?> itens<?= $x['status'] === 'rascunho' ? ' · <em class="tag cinza">rascunho</em>' : '' ?></strong><small>por <?= e($x['por']) ?></small></span>
          </a></li>
        <?php endforeach; ?>
      </ul>
    <?php
}

function contagem_tela_editar(): void
{
    global $csrf;
    $c = dh_contagem();
    if (!empty($_GET['nova']) && isset($_SESSION['contagem_nova'])) {
        $x = $_SESSION['contagem_nova']; // ainda não gravada
    } else {
        $i = contagem_indice($c, (string) ($_GET['id'] ?? ''));
        if ($i === null) {
            echo '<p class="dica">Contagem não encontrada. <a href="./?acao=contagem">Voltar</a></p>';
            return;
        }
        $x = $c['contagens'][$i];
    }
    // Sugestões de nomes: o que já foi contado nessa casa e as cervejas cadastradas
    $nomes = [];
    foreach ($c['contagens'] as $y) {
        foreach ($y['casa'] === $x['casa'] ? $y['linhas'] : [] as $l) {
            $nomes[$l['nome']] = true;
        }
    }
    foreach (dh_carregar()['cervejas'] as $cv) {
        $nomes[$cv['nome']] = true;
    }
    $locais = array_keys(dh_contagem_por_local($x['linhas']));
    $jaTem = array_map(fn($l) => dh_sem_acento(mb_strtolower($l)), $locais);
    foreach (dh_contagem_locais($x['casa']) as $lc) {
        if (!in_array(dh_sem_acento(mb_strtolower($lc)), $jaTem, true)) {
            $locais[] = $lc;
        }
    }
    $porLocal = dh_contagem_por_local($x['linhas']);
    $linha = function (array $l): void {
        ?>
          <div class="ct-item">
            <input class="ct-n" value="<?= e($l['nome']) ?>" list="ct-nomes" aria-label="Item" placeholder="Nome do item">
            <div class="ct-qtd">
              <button type="button" class="ct-menos" aria-label="Menos">−</button>
              <input class="ct-q" inputmode="decimal" value="<?= e($l['qtd'] === null ? '' : dh_num(rtrim(rtrim(number_format((float) $l['qtd'], 2, '.', ''), '0'), '.'))) ?>" placeholder="?" aria-label="Quantidade">
              <button type="button" class="ct-mais" aria-label="Mais">+</button>
            </div>
            <select class="ct-u" aria-label="Unidade"><?php foreach (DH_CONTAGEM_UNIDADES as $u): ?><option <?= $u === $l['unidade'] ? 'selected' : '' ?>><?= e($u) ?></option><?php endforeach; ?></select>
            <div class="ct-barril"<?= $l['unidade'] !== 'barril' ? ' hidden' : '' ?>>
              <select class="ct-l" aria-label="Tamanho do barril"><?php foreach (DH_CONTAGEM_LITROS as $lt): ?><option value="<?= $lt ?>" <?= ($l['litros'] ?? '50') === $lt || (($l['litros'] ?? '') === '' && $lt === '50') ? 'selected' : '' ?>><?= $lt ?> litros</option><?php endforeach; ?></select>
              <div class="ct-barris"><?php foreach ($l['unidade'] === 'barril' ? dh_contagem_barris($l) : [] as $n => $st): ?><label>Barril <?= $n + 1 ?> <select class="ct-st"><?php foreach (DH_CONTAGEM_STATUS as $k => $rot): ?><option value="<?= $k ?>" <?= (string) $st === (string) $k ? 'selected' : '' ?>><?= e($rot) ?></option><?php endforeach; ?></select></label><?php endforeach; ?></div>
            </div>
            <input class="ct-o" value="<?= e($l['obs']) ?>" placeholder="obs." aria-label="Observação">
            <button type="button" class="ct-tirar" aria-label="Tirar este item" title="Tirar">×</button>
          </div>
        <?php
    };
    ?>
      <p><a class="voltar" href="./?acao=contagem">‹ Contagem</a></p>
      <h1>📦 Contagem · <?= e(dh_contagem_casa_nome($x['casa'])) ?></h1>
      <p class="dica">Digite a quantidade de cada item (meio fardo: <b>0,5</b> ou <b>1/2</b>). Os botões − e + mudam de 1 em 1. Toque em <b>Salvar</b> para continuar depois, e em <b>Enviar</b> quando terminar.</p>
      <datalist id="ct-nomes"><?php foreach (array_keys($nomes) as $n): ?><option value="<?= e($n) ?>"><?php endforeach; ?></datalist>
      <form method="post" action="./?acao=contagem-salvar" id="ct-form" class="ct-form">
        <?= $csrf ?><input type="hidden" name="id" value="<?= e($x['id']) ?>"><input type="hidden" name="casa" value="<?= e($x['casa']) ?>"><input type="hidden" name="json" id="ct-json"><input type="hidden" name="enviar" id="ct-enviar" value="">
        <label>Data da contagem <input type="date" name="data" value="<?= e($x['data']) ?>"></label>
        <div id="ct-locais">
          <?php foreach ($locais as $lc): ?>
            <section class="ct-local">
              <h2><input class="ct-local-nome" value="<?= e($lc) ?>" aria-label="Lugar"></h2>
              <div class="ct-itens"><?php foreach ($porLocal[$lc] ?? [] as $l) { $linha($l); } ?></div>
              <button type="button" class="link ct-add">+ Adicionar item</button>
            </section>
          <?php endforeach; ?>
        </div>
        <button type="button" class="btn secundario" id="ct-add-local">+ Outro lugar</button>
        <template id="ct-modelo"><?php $linha(['nome' => '', 'qtd' => null, 'unidade' => 'un', 'obs' => '', 'litros' => '50', 'barris' => [], 'local' => '']); ?></template>
        <div class="barra-salvar ct-barra">
          <button class="btn secundario" data-enviar="">💾 Salvar</button>
          <button class="btn primario" data-enviar="1">✅ Enviar contagem</button>
        </div>
      </form>
      <?php if ($x['id'] === ''): ?><p class="dica centro">Ainda não foi salva: toque em <b>Salvar</b> para guardar.</p><?php else: ?>
      <form method="post" action="./?acao=contagem-excluir" class="excluir" onsubmit="return confirm('Apagar esta contagem?');"><?= $csrf ?><input type="hidden" name="id" value="<?= e($x['id']) ?>"><button class="btn perigo">Apagar contagem</button></form><?php endif; ?>
      <script>
      (function () {
        var form = document.getElementById('ct-form'), locais = document.getElementById('ct-locais'), modelo = document.getElementById('ct-modelo');
        function num(v) { v = (v || '').replace(/\s/g, ''); var t = 0; if (!v) return null; v.split('+').forEach(function (p) { if (!p) return; var f = p.match(/^(\d+)\/(\d+)$/); t += f ? f[1] / f[2] : parseFloat(p.replace(',', '.')) || 0; }); return t; }
        function txt(n) { return String(Math.round(n * 100) / 100).replace('.', ','); }
        locais.addEventListener('click', function (e) {
          var b = e.target.closest('button');
          if (!b) return;
          var it = b.closest('.ct-item');
          if (b.classList.contains('ct-mais') || b.classList.contains('ct-menos')) {
            var q = it.querySelector('.ct-q'), v = num(q.value) || 0;
            v = Math.max(0, v + (b.classList.contains('ct-mais') ? 1 : -1));
            q.value = txt(v);
            barris(it);
          } else if (b.classList.contains('ct-tirar')) {
            if (!it.querySelector('.ct-n').value || confirm('Tirar ' + it.querySelector('.ct-n').value + ' da contagem?')) it.remove();
          } else if (b.classList.contains('ct-add')) {
            var novo = modelo.content.firstElementChild.cloneNode(true), lista = b.closest('.ct-local').querySelector('.ct-itens'), ult = lista.querySelector('.ct-item:last-child .ct-u');
            var camara = /c[aâ]mara/i.test(b.closest('.ct-local').querySelector('.ct-local-nome').value || '');
            if (camara) { novo.querySelector('.ct-u').value = 'barril'; novo.querySelector('.ct-q').value = '1'; } // câmara: barril, 1 unidade
            else if (ult) novo.querySelector('.ct-u').value = ult.value; // mesma unidade do item de cima
            lista.appendChild(novo);
            barris(novo);
            novo.querySelector('.ct-n').focus();
          }
        });
        // Barril: um campo de situação para cada barril (produção já vem mexido; estoque, cheio)
        var STATUS = <?= json_encode(DH_CONTAGEM_STATUS, JSON_UNESCAPED_UNICODE) ?>;
        function barris(it) {
          var cx = it.querySelector('.ct-barris'), barril = it.querySelector('.ct-u').value === 'barril';
          it.querySelector('.ct-barril').hidden = !barril;
          var n = barril ? Math.max(0, Math.floor(num(it.querySelector('.ct-q').value) || 0)) : 0;
          if (n > 30) n = 30;
          var atuais = cx.querySelectorAll('label');
          var local = (it.closest('.ct-local').querySelector('.ct-local-nome').value || '');
          var padrao = /produ/i.test(local) ? '80' : '100';
          for (var k = atuais.length; k < n; k++) {
            var lb = document.createElement('label'), sel = document.createElement('select');
            sel.className = 'ct-st';
            Object.keys(STATUS).forEach(function (v) { var o = document.createElement('option'); o.value = v; o.textContent = STATUS[v]; if (v === padrao) o.selected = true; sel.appendChild(o); });
            lb.textContent = 'Barril ' + (k + 1) + ' '; lb.appendChild(sel); cx.appendChild(lb);
          }
          for (var j = atuais.length - 1; j >= n; j--) atuais[j].remove();
        }
        locais.addEventListener('change', function (e) {
          if (e.target.classList.contains('ct-u') || e.target.classList.contains('ct-q')) barris(e.target.closest('.ct-item'));
        });
        locais.addEventListener('input', function (e) { if (e.target.classList.contains('ct-q')) barris(e.target.closest('.ct-item')); });
        document.getElementById('ct-add-local').addEventListener('click', function () {
          var s = document.createElement('section'); s.className = 'ct-local';
          s.innerHTML = '<h2><input class="ct-local-nome" placeholder="Nome do lugar (ex.: Bar de cima)" aria-label="Lugar"></h2><div class="ct-itens"></div><button type="button" class="link ct-add">+ Adicionar item</button>';
          locais.appendChild(s); s.querySelector('input').focus();
        });
        form.querySelectorAll('[data-enviar]').forEach(function (b) {
          b.addEventListener('click', function () { document.getElementById('ct-enviar').value = b.dataset.enviar; });
        });
        form.addEventListener('submit', function (e) {
          var linhas = [], vazios = 0;
          locais.querySelectorAll('.ct-local').forEach(function (s) {
            var local = s.querySelector('.ct-local-nome').value.trim() || 'Estoque';
            s.querySelectorAll('.ct-item').forEach(function (it) {
              var nome = it.querySelector('.ct-n').value.trim(), q = it.querySelector('.ct-q').value.trim();
              if (!nome) return;
              if (!q) vazios++;
              var u = it.querySelector('.ct-u').value;
              linhas.push({ local: local, nome: nome, qtd: q, unidade: u, obs: it.querySelector('.ct-o').value.trim(),
                litros: u === 'barril' ? it.querySelector('.ct-l').value : '',
                barris: u === 'barril' ? [].map.call(it.querySelectorAll('.ct-st'), function (x) { return x.value; }) : [] });
            });
          });
          if (document.getElementById('ct-enviar').value === '1' && vazios && !confirm(vazios + ' ' + (vazios > 1 ? 'itens estão' : 'item está') + ' sem quantidade. Enviar mesmo assim?')) { e.preventDefault(); return; }
          document.getElementById('ct-json').value = JSON.stringify(linhas);
        });
      })();
      </script>
    <?php
}

function contagem_tela_ver(): void
{
    $c = dh_contagem();
    $i = contagem_indice($c, (string) ($_GET['id'] ?? ''));
    if ($i === null) {
        echo '<p class="dica">Contagem não encontrada. <a href="./?acao=contagem">Voltar</a></p>';
        return;
    }
    $x = $c['contagens'][$i];
    $ant = dh_contagem_anterior($c, $x['casa'], $x['data'], $x['id']);
    $antes = [];
    foreach ($ant['linhas'] ?? [] as $l) {
        $antes[dh_contagem_chave($l)] = $l['qtd'];
    }
    $texto = dh_contagem_texto($x);
    ?>
      <p><a class="voltar" href="./?acao=contagem">‹ Contagem</a></p>
      <h1>📦 Contagem · <?= e(dh_contagem_casa_nome($x['casa'])) ?> · <?= e(date('d/m', strtotime($x['data']))) ?></h1>
      <p class="dica">Por <?= e($x['por']) ?><?= !empty($x['alterado_por']) && $x['alterado_por'] !== $x['por'] ? ' · alterada por ' . e($x['alterado_por']) : '' ?>.<?= $ant ? ' Comparando com a contagem de ' . e(date('d/m', strtotime($ant['data']))) . '.' : '' ?></p>
      <p class="botoes-link">
        <a class="btn secundario" href="https://wa.me/?text=<?= e(rawurlencode($texto)) ?>" target="_blank" rel="noopener">Enviar no WhatsApp</a>
        <button type="button" class="btn secundario" onclick="window.print()">🖨 Imprimir</button>
        <a class="btn secundario" href="./?acao=contagem-editar&amp;id=<?= e($x['id']) ?>">✏️ Corrigir</a>
      </p>
      <?php foreach (dh_contagem_por_local($x['linhas']) as $local => $ls): ?>
        <h2><?= e($local) ?></h2>
        <table class="ct-tabela">
          <?php foreach ($ls as $l): $k = dh_contagem_chave($l); $tinha = array_key_exists($k, $antes) ? $antes[$k] : false; $dif = $tinha !== false && $tinha !== null && $l['qtd'] !== null ? round($l['qtd'] - $tinha, 2) : null; ?>
            <tr>
              <td><?= e($l['nome']) ?><?= $l['obs'] !== '' ? ' <small>(' . e($l['obs']) . ')</small>' : '' ?></td>
              <td class="ct-num"><b><?= e(dh_qtd_txt($l['qtd'])) ?></b> <?= e(dh_contagem_unidade_txt($l['unidade'], $l['qtd']) . dh_contagem_detalhe_barril($l)) ?></td>
              <td class="ct-dif"><?php if ($ant && $tinha === false): ?><em class="tag ct-novo">novo</em><?php elseif ($dif): ?><span class="<?= $dif < 0 ? 'ct-baixou' : 'ct-subiu' ?>"><?= $dif > 0 ? '+' : '−' ?><?= e(dh_qtd_txt(abs($dif))) ?></span><?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
        </table>
      <?php endforeach; ?>
      <?php if ($ant): $agora = array_flip(array_map('dh_contagem_chave', $x['linhas'])); $sumiram = array_filter($ant['linhas'], fn($l) => !isset($agora[dh_contagem_chave($l)])); ?>
        <?php if ($sumiram): ?>
          <h2>Não aparecem mais</h2>
          <p class="dica">Estavam na contagem de <?= e(date('d/m', strtotime($ant['data']))) ?>: <?= e(implode(', ', array_map(fn($l) => $l['nome'] . ' (' . dh_qtd_txt($l['qtd']) . ' ' . dh_contagem_unidade_txt($l['unidade'], $l['qtd']) . ')', $sumiram))) ?>.</p>
        <?php endif; ?>
      <?php endif; ?>
    <?php
}
