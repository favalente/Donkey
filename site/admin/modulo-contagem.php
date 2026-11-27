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

        case 'contagem-pedido':
            exigir('contagem');
            if (!pode('usuarios')) {
                throw new RuntimeException('Só o administrador vê a sugestão de pedido.');
            }
            return true;

        case 'insumos-importar':
            // Planilha de pedido de lúpulo: kg de cada lúpulo por brassagem de cada cerveja
            exigir_post();
            exigir('usuarios');
            $telaErro = 'contagem-pedido';
            if (($_FILES['planilha']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Escolha a planilha (.xlsx).');
            }
            $lido = dh_insumos_ler_planilha($_FILES['planilha']['tmp_name'], dh_prog_grupos(dh_brassagem()));
            $cfg = dh_insumos_cfg();
            $cfg['lupulos'] = $lido['lupulos'] + $cfg['lupulos'];
            $cfg['atualizado_em'] = date('Y-m-d H:i:s');
            dh_insumos_cfg_salvar($cfg);
            ir('./?acao=contagem-pedido#lupulo-receitas', 'Planilha lida: lúpulo de ' . count($lido['lupulos']) . ' cervejas.' . ($lido['sem_cerveja'] ? ' Sem receita correspondente: ' . implode(', ', $lido['sem_cerveja']) . '.' : ''));

        case 'insumos-salvar':
            exigir_post();
            exigir('usuarios');
            $telaErro = 'contagem-pedido';
            $cfg = dh_insumos_cfg();
            $grupos = dh_prog_grupos(dh_brassagem());
            foreach ((array) ($_POST['lup'] ?? []) as $g => $txt) {
                if (!isset($grupos[$g])) {
                    continue;
                }
                $l = [];
                foreach (preg_split('/\r?\n/', (string) $txt) as $ln) {
                    if (preg_match('/^\s*(.+?)\s*[:=]?\s*(\d+(?:[.,]\d+)?)\s*(kg|g)?\s*$/iu', $ln, $m)) {
                        $kg = (float) str_replace(',', '.', $m[2]) / (strtolower($m[3] ?? '') === 'g' ? 1000 : 1);
                        if ($kg > 0) {
                            $l[dh_insumo_lupulo($m[1])] = round($kg, 3);
                        }
                    }
                }
                if ($l) {
                    $cfg['lupulos'][$g] = $l;
                } else {
                    unset($cfg['lupulos'][$g]);
                }
            }
            $cfg['atualizado_em'] = date('Y-m-d H:i:s');
            dh_insumos_cfg_salvar($cfg);
            ir('./?acao=contagem-pedido#lupulo-receitas', 'Lúpulo por brassagem salvo.');

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
                if ($ant && $ant['data'] === date('Y-m-d')) {
                    // A de hoje já existe: corrige ela em vez de criar outra igual
                    ir('./?acao=contagem-editar&id=' . $ant['id'], 'A contagem de hoje já existe: é só corrigir.');
                }
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
            $_SESSION['contagem_nova'] = ['id' => '', 'token' => bin2hex(random_bytes(8)), 'casa' => $casa, 'data' => date('Y-m-d'), 'status' => 'rascunho', 'por' => $usuario['nome'], 'em' => date('Y-m-d H:i:s'), 'linhas' => $linhas];
            ir('./?acao=contagem-editar&nova=1');

        case 'contagem-salvar':
            exigir_post();
            exigir('contagem');
            $c = dh_contagem();
            $id = (string) ($_POST['id'] ?? '');
            $token = (string) ($_POST['token'] ?? '');
            if ($id === '' && $token !== '' && isset($_SESSION['contagem_criadas'][$token])) {
                // Mesmo formulário enviado de novo (dois toques, voltar e reenviar): atualiza a que já foi criada
                $id = $_SESSION['contagem_criadas'][$token];
            }
            if ($id === '') {
                // Primeira vez que essa contagem é salva
                $nova = $_SESSION['contagem_nova'] ?? null;
                $casaN = isset(DH_CONTAGEM_CASAS[$_POST['casa'] ?? '']) ? $_POST['casa'] : ($nova['casa'] ?? contagem_casa());
                $c['contagens'][] = ['id' => dh_id_novo('k'), 'casa' => $casaN, 'data' => date('Y-m-d'), 'status' => 'rascunho', 'por' => $usuario['nome'], 'em' => date('Y-m-d H:i:s'), 'linhas' => []];
                $i = count($c['contagens']) - 1;
                if ($token !== '') {
                    $_SESSION['contagem_criadas'][$token] = $c['contagens'][$i]['id'];
                }
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
                if ($linhas[$k]['unidade'] === 'saca') {
                    $linhas[$k]['saca_kg'] = in_array((string) ($l['saca_kg'] ?? ''), DH_CONTAGEM_SACA_KG, true) ? (string) $l['saca_kg'] : '25';
                    $ak = dh_qtd_ler((string) ($l['aberta_kg'] ?? ''));
                    $linhas[$k]['aberta_kg'] = $ak !== null && $ak > 0 ? round($ak, 2) : 0;
                }
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
        case 'contagem-pedido':
            contagem_tela_pedido();
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
      <h1>📦 Inventário / Contagem</h1>
      <nav class="sede-escolha" aria-label="Contagem de qual casa">
        <?php foreach (DH_CONTAGEM_CASAS as $u => $nomeU): ?>
          <a class="sede-opcao u-<?= $u ?><?= $casa === $u ? ' ativa' : '' ?>" href="./?acao=contagem&amp;casa=<?= $u ?>"><?= $casa === $u ? '✓ ' : '' ?><?= e($nomeU) ?></a>
        <?php endforeach; ?>
      </nav>

      <?php if ($rascunho): ?>
        <a class="btn primario largo" href="./?acao=contagem-editar&amp;id=<?= e($rascunho['id']) ?>">✏️ Continuar a contagem de <?= e(dh_data_br($rascunho['data'])) ?></a>
        <p class="dica centro">Contagem começada e ainda não enviada.</p>
      <?php endif; ?>

      <?php $hoje = array_values(array_filter($daCasa, fn($x) => $x['data'] === date('Y-m-d') && $x['status'] === 'enviada'))[0] ?? null; ?>
      <?php if ($hoje): ?>
        <a class="btn primario largo" href="./?acao=contagem-editar&amp;id=<?= e($hoje['id']) ?>">✏️ Corrigir a contagem de hoje</a>
        <p class="dica centro">A contagem de hoje já foi enviada. Para mudar alguma coisa, corrija ela (não comece outra).</p>
        <details class="ct-outra"><summary>Começar outra contagem mesmo assim</summary>
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
          <button class="ct-nova"><span>🏭</span><strong>Começar uma contagem vazia</strong><small>Câmaras de produção e estoque, maltes, lúpulos e leveduras.</small></button></form>
        <?php endif; ?>
        <button type="button" class="ct-nova" onclick="var f=document.getElementById('ct-colar-form'); f.hidden=!f.hidden; if(!f.hidden) f.querySelector('textarea').focus();"><span>📝</span><strong>Colar a contagem do WhatsApp</strong><small>Para aproveitar a última contagem que foi mandada em texto.</small></button>
        <form method="post" action="./?acao=contagem-nova" id="ct-colar-form" class="ct-colar-form" hidden><?= $csrf ?><input type="hidden" name="casa" value="<?= e($casa) ?>"><input type="hidden" name="modo" value="texto">
          <label>Cole aqui a mensagem da contagem <textarea name="texto" rows="8" placeholder="Câmara produção&#10;Softhop 1 barril&#10;&#10;Estoque seco&#10;3 fardo guaraná zero&#10;1/2 fardo de fanta uva"></textarea></label>
          <button class="btn primario largo">Ler o texto</button>
        </form>
      </div>
      <?php if ($hoje): ?></details><?php endif; ?>

      <?php if (pode('usuarios')): ?><p><a class="btn secundario largo" href="./?acao=contagem-pedido">🧾 Sugestão de pedido de malte e lúpulo</a></p><?php endif; ?>
      <h2>Contagens</h2>
      <?php if (!$daCasa): ?><p class="dica">Nenhuma contagem ainda.</p><?php endif; ?>
      <ul class="lista-reservas">
        <?php foreach (array_slice($daCasa, 0, 30) as $x): ?>
          <li><a class="ct-hist" href="./?acao=<?= $x['status'] === 'enviada' ? 'contagem-ver' : 'contagem-editar' ?>&amp;id=<?= e($x['id']) ?>">
            <span class="lr-data"><strong><?= date('d', strtotime($x['data'])) ?></strong><?= e(mb_substr(DH_MESES[(int) date('n', strtotime($x['data']))], 0, 3)) ?></span>
            <span class="lr-info"><strong><?= count($x['linhas']) ?> ite<?= count($x['linhas']) === 1 ? 'm' : 'ns' ?><?= $x['status'] === 'rascunho' ? ' · <em class="tag cinza">rascunho</em>' : '' ?></strong><small>por <?= e($x['por']) ?> · <?= e(date('H:i', strtotime($x['em']))) ?></small></span>
          </a>
          <form method="post" action="./?acao=contagem-excluir" class="ct-hist-apagar" onsubmit="return confirm('Apagar a contagem de <?= e(date('d/m', strtotime($x['data']))) ?> (<?= count($x['linhas']) ?> itens, <?= e(date('H:i', strtotime($x['em']))) ?>)?');"><?= $csrf ?><input type="hidden" name="id" value="<?= e($x['id']) ?>"><button aria-label="Apagar contagem" title="Apagar">🗑</button></form></li>
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
            <div class="ct-saca"<?= $l['unidade'] !== 'saca' ? ' hidden' : '' ?>>
              <span class="ct-sk-txt">saca de 25 kg</span>
              <label>+ kg na aberta <input class="ct-ak" inputmode="decimal" value="<?= !empty($l['aberta_kg']) ? e(dh_num($l['aberta_kg'])) : '' ?>" placeholder="0"></label>
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
        <?= $csrf ?><input type="hidden" name="id" value="<?= e($x['id']) ?>"><input type="hidden" name="token" value="<?= e($x['token'] ?? '') ?>"><input type="hidden" name="casa" value="<?= e($x['casa']) ?>"><input type="hidden" name="json" id="ct-json"><input type="hidden" name="enviar" id="ct-enviar" value="">
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
            var setor = b.closest('.ct-local').querySelector('.ct-local-nome').value || '';
            if (/malte/i.test(setor)) novo.querySelector('.ct-u').value = 'saca'; // malte: sacas + kg da aberta
            else if (/l[uú]pulo/i.test(setor)) novo.querySelector('.ct-u').value = 'kg';
            else if (/levedura/i.test(setor)) novo.querySelector('.ct-u').value = 'pacote';
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
          it.querySelector('.ct-saca').hidden = it.querySelector('.ct-u').value !== 'saca';
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
              var u = it.querySelector('.ct-u').value;
              if (!q && !(u === 'saca' && it.querySelector('.ct-ak').value.trim())) vazios++; // malte só com a saca aberta: ok
              linhas.push({ local: local, nome: nome, qtd: q, unidade: u, obs: it.querySelector('.ct-o').value.trim(),
                litros: u === 'barril' ? it.querySelector('.ct-l').value : '',
                saca_kg: u === 'saca' ? '25' : '', aberta_kg: u === 'saca' ? it.querySelector('.ct-ak').value.trim() : '',
                barris: u === 'barril' ? [].map.call(it.querySelectorAll('.ct-st'), function (x) { return x.value; }) : [] });
            });
          });
          if (document.getElementById('ct-enviar').value === '1' && vazios && !confirm(vazios + ' ' + (vazios > 1 ? 'itens estão' : 'item está') + ' sem quantidade. Enviar mesmo assim?')) { e.preventDefault(); return; }
          document.getElementById('ct-json').value = JSON.stringify(linhas);
          // Um toque só: evita salvar duas vezes enquanto a internet está lenta
          if (form.dataset.enviando) { e.preventDefault(); return; }
          form.dataset.enviando = '1';
          form.querySelectorAll('[data-enviar]').forEach(function (b) { b.style.opacity = '.6'; });
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
        $antes[dh_contagem_chave($l)] = dh_contagem_total($l);
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
        <?php if ($x['casa'] === 'fabrica' && pode('usuarios')): ?><a class="btn primario" href="./?acao=contagem-pedido">🧾 Sugestão de pedido</a><?php endif; ?>
      </p>
      <form method="post" action="./?acao=contagem-excluir" class="botoes-link" onsubmit="return confirm('Apagar esta contagem? Não dá para desfazer.');"><?= $csrf ?><input type="hidden" name="id" value="<?= e($x['id']) ?>"><button class="btn perigo">🗑 Apagar contagem</button></form>
      <?php foreach (dh_contagem_por_local($x['linhas']) as $local => $ls): ?>
        <h2><?= e($local) ?></h2>
        <table class="ct-tabela">
          <?php foreach ($ls as $l): $k = dh_contagem_chave($l); $tinha = array_key_exists($k, $antes) ? $antes[$k] : false; $dif = $tinha !== false && $tinha !== null && dh_contagem_total($l) !== null ? round(dh_contagem_total($l) - $tinha, 2) : null; ?>
            <tr>
              <td><?= e($l['nome']) ?><?= $l['obs'] !== '' ? ' <small>(' . e($l['obs']) . ')</small>' : '' ?></td>
              <td class="ct-num"><?php [$qn, $qr] = dh_contagem_qtd_partes($l); ?><b><?= e($qn) ?></b> <?= e($qr) ?></td>
              <td class="ct-dif"><?php if ($ant && $tinha === false): ?><em class="tag ct-novo">novo</em><?php elseif ($dif): ?><span class="<?= $dif < 0 ? 'ct-baixou' : 'ct-subiu' ?>"><?= $dif > 0 ? '+' : '−' ?><?= e($l['unidade'] === 'saca' ? dh_num(abs($dif)) . ' kg' : dh_qtd_txt(abs($dif))) ?></span><?php endif; ?></td>
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

// 🧾 Sugestão de pedido de malte e lúpulo: brassagens previstas × receitas − estoque da fábrica
function contagem_tela_pedido(): void
{
    $b = dh_brassagem();
    $grupos = dh_prog_grupos($b);
    $ritmo = dh_insumos_ritmo($b, $grupos);
    $semanas = in_array((int) ($_GET['semanas'] ?? 4), [2, 3, 4, 6, 8], true) ? (int) ($_GET['semanas'] ?? 4) : 4;
    $mudou = isset($_GET['b']) && is_array($_GET['b']);
    $bras = [];
    foreach ($grupos as $k => $g) {
        $bras[$k] = $mudou ? max(0, min(40, (int) ($_GET['b'][$k] ?? 0))) : (int) round(($ritmo['por_semana'][$k] ?? 0) * $semanas);
    }
    $ped = dh_insumos_pedido($b, $grupos, $bras, $ritmo['ultima_ne']);
    $kg = fn($x) => dh_num(round($x, 1)) . ' kg';
    $saca = fn($x) => $x >= DH_SACA_KG ? floor($x / DH_SACA_KG + 1e-9) . ' sc' . (fmod($x, DH_SACA_KG) > 0.05 ? ' + ' . dh_num(round(fmod($x, DH_SACA_KG), 1)) . ' kg' : '') : $kg($x);
    $txt = "*Pedido de malte e lúpulo · Donkey Head Cervejaria*\n";
    $mal = array_filter($ped['malte'], fn($l) => $l['pedir'] > 0);
    $lup = array_filter($ped['lupulo'], fn($l) => $l['pedir'] > 0);
    if ($mal) {
        $txt .= "\n*Malte*\n" . implode("\n", array_map(fn($l) => $l['nome'] . ': ' . $l['pedir'] . ' saca' . ($l['pedir'] > 1 ? 's' : ''), $mal)) . "\n";
    }
    if ($lup) {
        $txt .= "\n*Lúpulo*\n" . implode("\n", array_map(fn($l) => $l['nome'] . ': ' . dh_num($l['pedir']) . ' kg', $lup)) . "\n";
    }
    $ativos = array_filter($grupos, fn($g) => $g['categoria'] !== 'fora');
    $fora = array_filter($grupos, fn($g) => $g['categoria'] === 'fora');
    ?>
      <p><a class="voltar" href="./?acao=contagem">‹ Inventário / Contagem</a></p>
      <h1>🧾 Sugestão de pedido</h1>
      <p class="dica">Malte e lúpulo que vão ser usados nas próximas semanas (brassagens × receita), menos o estoque da última contagem da fábrica<?= $ped['estoque_data'] !== '' ? ' (' . e(date('d/m', strtotime($ped['estoque_data']))) . ')' : '' ?>. Malte em sacas de <?= DH_SACA_KG ?> kg. As brassagens começam pelo ritmo das últimas 12 semanas; mude os números e toque em <b>Recalcular</b>.</p>
      <?php if ($ped['estoque_data'] === ''): ?><p class="aviso">Ainda não tem contagem enviada da Fábrica: o estoque conta como zero.</p><?php endif; ?>
      <form method="get" class="form-cerveja ped-form">
        <input type="hidden" name="acao" value="contagem-pedido">
        <label>Período <select name="semanas" onchange="this.form.querySelectorAll('.ped-n').forEach(function (x) { x.disabled = true; }); this.form.submit()"><?php foreach ([2, 3, 4, 6, 8] as $s): ?><option value="<?= $s ?>" <?= $s === $semanas ? 'selected' : '' ?>>Próximas <?= $s ?> semanas</option><?php endforeach; ?></select></label>
        <h2>Brassagens no período</h2>
        <div class="ped-bras">
          <?php foreach ([$ativos, $fora] as $n => $lista): if (!$lista) continue; if ($n === 1): ?><details class="ped-fora"<?= array_filter(array_intersect_key($bras, $fora)) ? ' open' : '' ?>><summary>Fora de linha</summary><?php endif; ?>
            <?php foreach ($lista as $k => $g): ?>
              <label class="ped-linha"><span><?= e($k === 'newengland' ? 'New England (alternando)' : $g['nome']) ?><small><?= isset($ritmo['por_semana'][$k]) ? dh_num(round($ritmo['por_semana'][$k] * 4, 1)) . ' por mês nas últimas 12 semanas' : 'sem brassagem nas últimas 12 semanas' ?></small></span>
                <input class="ped-n" type="number" min="0" max="40" name="b[<?= e($k) ?>]" value="<?= $bras[$k] ?>" inputmode="numeric"></label>
            <?php endforeach; ?>
          <?php if ($n === 1): ?></details><?php endif; endforeach; ?>
        </div>
        <?php if ($ped['ne']): ?><p class="dica">New England, na ordem: <?= e(implode(' → ', array_map(fn($x) => (['all my love' => 'All My Love', 'caldo de bila' => 'Caldo de Bila', 'citranela' => 'Citranela'][$x] ?? $x) . ' (' . DH_NE_RODIZIO[$x] . ' ' . DH_NE_LUPULO_KG . ' kg)', $ped['ne']))) ?>.</p><?php endif; ?>
        <button class="btn primario">Recalcular</button>
      </form>

      <?php foreach (['malte' => '🌾 Malte', 'lupulo' => '🌿 Lúpulo'] as $t => $tit): ?>
        <h2><?= $tit ?></h2>
        <?php if (!$ped[$t]): ?><p class="dica">Nada no período.</p><?php continue; endif; ?>
        <div class="tabela-rolar"><table class="ct-tabela ped-tabela">
          <tr><th></th><th>Vai usar</th><th>Estoque</th><th>Pedir</th></tr>
          <?php foreach ($ped[$t] as $l): ?>
            <tr class="<?= $l['pedir'] > 0 ? 'ped-pedir' : '' ?>"><td><?= e($l['nome']) ?></td><td><?= e($t === 'malte' ? $saca($l['precisa']) : $kg($l['precisa'])) ?></td><td><?= e($t === 'malte' ? $saca($l['estoque']) : $kg($l['estoque'])) ?></td>
              <td><b><?= $l['pedir'] > 0 ? ($t === 'malte' ? $l['pedir'] . ' saca' . ($l['pedir'] > 1 ? 's' : '') : e(dh_num($l['pedir'])) . ' kg') : '—' ?></b></td></tr>
          <?php endforeach; ?>
        </table></div>
      <?php endforeach; ?>
      <?php if ($ped['sem']): ?><p class="aviso">Sem equivalência com os maltes da contagem (entram pelo nome da receita): <?= e(implode(', ', $ped['sem'])) ?>.</p><?php endif; ?>
      <?php if ($ped['sem_receita']): ?><p class="aviso">Sem receita em Receitas (entra só o lúpulo): <?= e(implode(', ', $ped['sem_receita'])) ?>.</p><?php endif; ?>
      <?php if ($mal || $lup): ?>
        <p class="botoes-link" id="enviar-pedido"><a class="btn primario" href="https://wa.me/?text=<?= e(rawurlencode($txt)) ?>" target="_blank" rel="noopener">Enviar o pedido no WhatsApp</a>
          <button type="button" class="btn secundario" onclick="navigator.clipboard.writeText(<?= e(json_encode($txt, JSON_UNESCAPED_UNICODE)) ?>).then(function () { alert('Pedido copiado!'); })">Copiar</button></p>
      <?php else: ?><p class="dica">O estoque dá conta do período: nada a pedir.</p><?php endif; ?>
    
      <?php $cfg = dh_insumos_cfg(); $gr = dh_prog_grupos($b); global $csrf; ?>
      <details class="config-reservas" id="lupulo-receitas"<?= !$cfg['lupulos'] ? ' open' : '' ?>>
        <summary>🌿 Lúpulo por brassagem de cada cerveja</summary>
        <p class="dica">Quando a cerveja tem lúpulo aqui, a sugestão usa estes números no lugar dos da receita. Um lúpulo por linha, em kg por brassagem (ex.: <b>Citra 1,41</b>). New England fica de fora (5 kg do lúpulo de cada uma).<?= $cfg['atualizado_em'] !== '' ? ' Atualizado em ' . e(date('d/m/Y', strtotime($cfg['atualizado_em']))) . '.' : '' ?></p>
        <form method="post" action="./?acao=insumos-importar" enctype="multipart/form-data" class="form-cerveja">
          <?= $csrf ?>
          <label>Importar a planilha de pedido de lúpulo (.xlsx) <small>(colunas Velas, APA, IPA, Soft, Asa, 4 X Lager, Miolo…; vale o bloco da Aldeota)</small><input type="file" name="planilha" accept=".xlsx" required></label>
          <button class="btn secundario">📥 Importar planilha</button>
        </form>
        <form method="post" action="./?acao=insumos-salvar" class="form-cerveja ped-lup">
          <?= $csrf ?>
          <?php foreach ($gr as $k => $g): if ($k === 'newengland' || ($g['categoria'] === 'fora' && empty($cfg['lupulos'][$k]))) continue; ?>
            <label><?= e($g['nome']) ?><textarea name="lup[<?= e($k) ?>]" rows="<?= max(1, count($cfg['lupulos'][$k] ?? [])) ?>" placeholder="(usa a receita)"><?= e(implode("\n", array_map(fn($n, $kg) => $n . ' ' . dh_num($kg), array_keys($cfg['lupulos'][$k] ?? []), $cfg['lupulos'][$k] ?? []))) ?></textarea></label>
          <?php endforeach; ?>
          <button class="btn primario">Salvar</button>
        </form>
      </details>
    <?php
}
