<?php
// Sino 🔔 do painel: notificações de cada usuário (contrato assinado, cliente assinou, pedido preenchido,
// orçamento aprovado) e a ativação das notificações no celular.

function notificacoes_minhas(): array
{
    global $usuario;
    return $usuario ? dh_notificacoes_do_usuario($usuario['login'], 'pode') : [];
}

function notificacoes_nao_lidas(): int
{
    return count(array_filter(notificacoes_minhas(), fn($x) => !$x['vista']));
}

function notificacoes_acao(string $acao): bool
{
    global $usuario;
    switch ($acao) {
        case 'notificacoes':
            if (array_filter(notificacoes_minhas(), fn($x) => !$x['vista'])) {
                dh_notificacoes_vistas($usuario['login']);
            }
            return true;

        case 'notificacao-abrir':
            exigir_post();
            $id = (string) ($_POST['id'] ?? '');
            $link = './?acao=notificacoes';
            foreach (notificacoes_minhas() as $x) {
                if ($x['id'] === $id) {
                    dh_notificacoes_marcar($usuario['login'], [$id]);
                    if (str_starts_with($x['link'], './?') && ($x['perm'] === '' || pode($x['perm']))) {
                        $link = $x['link'];
                    } elseif (preg_match('/^\.\/\?acao=contrato&id=([\w-]+)$/', $x['link'], $m) && pode('entregas')) {
                        $link = './?acao=entrega&id=' . $m[1]; // sem acesso ao contrato: abre só o card da entrega
                    }
                }
            }
            ir($link);

        case 'notificacoes-lidas':
            exigir_post();
            $id = (string) ($_POST['id'] ?? '*');
            dh_notificacoes_marcar($usuario['login'], [$id === '' ? '*' : $id]);
            ir('./?acao=notificacoes', $id === '*' ? 'Todas as notificações marcadas como lidas.' : null);

        // Lido pelo sw.js quando chega o "toque" no celular: a notificação mais nova não lida
        case 'notificacoes-json':
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            $nova = null;
            foreach (dh_notificacoes_do_usuario($usuario['login'], 'pode', null, 'push') as $x) {
                if (!$x['lida']) {
                    $nova = $x;
                    break;
                }
            }
            echo json_encode($nova ? ['id' => $nova['id'], 'titulo' => $nova['icone'] . ' ' . $nova['titulo'], 'texto' => $nova['texto'],
                'url' => './?acao=notificacoes&abrir=' . rawurlencode($nova['id'])] : new stdClass(), JSON_UNESCAPED_UNICODE);
            exit;

        case 'notificacoes-config':
            return true;

        case 'notificacoes-config-salvar':
            // O próprio usuário ou, para o administrador, qualquer usuário (escolhido no topo, como em Permissões)
            exigir_post();
            global $usuarios;
            $alvo = (string) ($_POST['login'] ?? $usuario['login']);
            $admin = pode('usuarios');
            if ($alvo !== $usuario['login'] && (!$admin || !isset($usuarios[$alvo]))) {
                throw new RuntimeException('Usuário não encontrado.');
            }
            $email = trim((string) ($_POST['email'] ?? ''));
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('E-mail inválido.');
            }
            dh_notif_prefs_salvar($alvo, (array) ($_POST['canais'] ?? []), isset($_POST['email']) ? $email : null, $admin);
            ir('./?acao=notificacoes-config' . ($alvo !== $usuario['login'] ? '&login=' . rawurlencode($alvo) : ''), 'Notificações de ' . ($usuarios[$alvo]['nome'] ?? $alvo) . ' salvas.');

        case 'notificacoes-usuario-padrao':
            // Volta a pessoa ao padrão da função (tabela "Quem recebe")
            exigir_post();
            exigir('usuarios');
            $alvo = (string) ($_POST['login'] ?? '');
            dh_notif_prefs_limpar($alvo);
            ir('./?acao=notificacoes-config&login=' . rawurlencode($alvo), 'Voltou ao padrão da função.');

        case 'notificacoes-extras-salvar':
            exigir_post();
            exigir('usuarios');
            $extras = [];
            foreach ((array) ($_POST['extras'] ?? []) as $tipo => $txt) {
                $lista = dh_emails_lista((string) $txt);
                $extras[$tipo] = $lista;
            }
            dh_notif_extras_salvar($extras);
            ir('./?acao=notificacoes-config#emails-fora', 'E-mails de fora salvos.');

        case 'notificacoes-papeis-salvar':
            exigir_post();
            exigir('usuarios');
            dh_notif_papeis_salvar((array) ($_POST['papeis'] ?? []));
            ir('./?acao=notificacoes-config#quem-recebe', 'Quem recebe cada notificação: salvo.');

        case 'push-inscrever':
            exigir_post();
            $endpoint = (string) ($_POST['endpoint'] ?? '');
            if (!dh_push_endpoint_valido($endpoint)) {
                http_response_code(400);
                exit('endereço inválido');
            }
            dh_push_inscrever($usuario['login'], $endpoint);
            exit('ok');

        case 'push-cancelar':
            exigir_post();
            dh_push_cancelar((string) ($_POST['endpoint'] ?? ''));
            exit('ok');

        case 'push-testar':
            exigir_post();
            dh_notificar('', '🔔', 'Teste de notificação', 'Se você está vendo isto no celular, está tudo certo.', './?acao=notificacoes', '', [$usuario['login']]);
            ir('./?acao=notificacoes-config', 'Teste enviado. Deve chegar no celular em alguns segundos.');
    }
    return false;
}

function notificacoes_tela(string $tela): bool
{
    global $csrf, $usuario;
    if ($tela === 'notificacoes-config') {
        notificacoes_tela_config();
        return true;
    }
    if ($tela !== 'notificacoes') {
        return false;
    }
    $itens = notificacoes_minhas();
    $naoLidas = count(array_filter($itens, fn($x) => !$x['lida']));
    $chaves = dh_push_chaves();
    $abrir = (string) ($_GET['abrir'] ?? '');
    ?>
      <h1>🔔 Notificações <?php if ($naoLidas): ?><small><?= $naoLidas ?> nova<?= $naoLidas > 1 ? 's' : '' ?></small><?php endif; ?></h1>

      <?php notificacoes_abas('notificacoes'); ?>
      <?php if ($chaves): ?><p class="dica" id="push-dica" hidden>📲 Quer receber no celular? Ative em <a href="./?acao=notificacoes-config">⚙ Configurar</a>.</p><?php endif; ?>

      <?php if ($naoLidas > 1): ?>
        <form method="post" action="./?acao=notificacoes-lidas" class="notif-todas"><?= $csrf ?><input type="hidden" name="id" value="*"><button class="link">Marcar todas como lidas</button></form>
      <?php endif; ?>

      <div class="notif-lista">
        <?php foreach (array_slice($itens, 0, 60) as $x): ?>
          <form method="post" action="./?acao=notificacao-abrir" class="notif<?= $x['lida'] ? '' : ' nova' ?>"<?= $x['id'] === $abrir ? ' data-abrir="1"' : '' ?>>
            <?= $csrf ?><input type="hidden" name="id" value="<?= e($x['id']) ?>">
            <button><span class="notif-ic"><?= e($x['icone']) ?></span><span><strong><?= e($x['titulo']) ?></strong><?php if ($x['texto'] !== ''): ?><small><?= e($x['texto']) ?></small><?php endif; ?><em><?= e(dh_data_br(substr($x['em'], 0, 10))) ?> às <?= e(substr($x['em'], 11, 5)) ?></em></span></button>
          </form>
        <?php endforeach; ?>
        <?php if (!$itens): ?><p class="dica">Nenhuma notificação ainda. Aqui aparecem contratos assinados, pedidos de chope preenchidos e orçamentos aprovados.</p><?php endif; ?>
      </div>

      <script>
      (function () {
        // Veio tocando na notificação do celular: abre direto o contrato/pedido
        var f = document.querySelector('form.notif[data-abrir]');
        if (f) { f.submit(); return; }
        var dica = document.getElementById('push-dica');
        if (dica && 'serviceWorker' in navigator && 'PushManager' in window) {
          navigator.serviceWorker.getRegistration().then(function (r) {
            if (!r) { dica.hidden = false; return; }
            r.pushManager.getSubscription().then(function (sub) { dica.hidden = !!sub; });
          });
        }
      })();
      </script>
    <?php
    return true;
}

function notificacoes_abas(string $atual): void
{
    ?>
      <div class="abas">
        <a href="./?acao=notificacoes" class="<?= $atual === 'notificacoes' ? 'ativo' : '' ?>">🔔 Recebidas</a>
        <a href="./?acao=notificacoes-config" class="<?= $atual === 'notificacoes-config' ? 'ativo' : '' ?>">⚙ Configurar</a>
      </div>
    <?php
}

// Configurar: ativar no aparelho (geral) e um interruptor para cada tipo de notificação
function notificacoes_tela_config(): void
{
    global $csrf, $usuario, $usuarios;
    $n = dh_notificacoes();
    $chaves = dh_push_chaves();
    $admin = pode('usuarios');
    $login = $admin ? (string) ($_GET['login'] ?? $usuario['login']) : $usuario['login'];
    $alvo = $login === $usuario['login'] ? $usuario : ($usuarios[$login] ?? null);
    if (!$alvo) {
        $login = $usuario['login'];
        $alvo = $usuario;
    }
    $proprio = $login === $usuario['login'];
    // Administrador vê todos os tipos (pode ligar um aviso para quem a função não daria); os outros, só os seus
    $recebe = fn($k) => dh_notif_tipo_para($k, $alvo, $n, $login);
    $tipos = $admin ? DH_NOTIF_TIPOS : array_filter(DH_NOTIF_TIPOS, fn($t, $k) => $recebe($k), ARRAY_FILTER_USE_BOTH);
    $personalizado = (bool) array_filter((array) ($n['prefs'][$login] ?? []), fn($p) => is_array($p) && !empty($p['_admin']));
    ?>
      <h1>🔔 Notificações</h1>
      <?php notificacoes_abas('notificacoes-config'); ?>

      <?php if ($chaves && $proprio): ?>
        <h2>Notificação no celular</h2>
        <div class="push-caixa" id="push-caixa" data-chave="<?= e($chaves['publica']) ?>" data-csrf="<?= e($_SESSION['csrf']) ?>" hidden>
          <div>
            <strong>📲 Neste aparelho</strong>
            <p class="dica" id="push-estado">Ative para receber na barra de notificações deste aparelho, mesmo com o painel fechado.</p>
          </div>
          <button type="button" class="btn primario" id="push-ativar">Ativar neste aparelho</button>
          <span class="push-ok" id="push-ok" hidden>
            <form method="post" action="./?acao=push-testar"><?= $csrf ?><button class="btn-acao">Enviar um teste</button></form>
            <button type="button" class="btn-acao" id="push-desativar">Desativar</button>
          </span>
        </div>
        <p class="dica" id="push-ios" hidden>No iPhone: toque em <b>Compartilhar → Adicionar à Tela de Início</b>, abra o painel pelo ícone e volte aqui para ativar.</p>
        <p class="dica">Vale para este aparelho; ative também nos outros que usar. Abaixo você escolhe, para cada aviso, se recebe no 🔔 do painel, no 📲 celular e/ou por ✉️ e-mail.</p>
      <?php endif; ?>

      <?php if ($admin): ?>
        <h2 id="por-pessoa">Quem recebe o quê</h2>
        <p class="dica">Escolha a pessoa e marque, em cada aviso, se ela recebe no 🔔 sino, no 📲 celular e/ou por ✉️ e-mail — como em 🔐 Permissões. Tudo desmarcado = não recebe aquele aviso. Os e-mails de contratos, lembretes de evento e cadernos de brassagem também saem daqui.</p>
        <form method="get" class="form-cerveja escolher-usuario">
          <input type="hidden" name="acao" value="notificacoes-config">
          <label>Usuário
            <select name="login" onchange="this.form.submit()">
              <?php foreach ($usuarios as $x): ?><option value="<?= e($x['login']) ?>" <?= $x['login'] === $login ? 'selected' : '' ?>><?= e($x['nome']) ?> (<?= e($x['login']) ?>) · <?= e(DH_PAPEIS[$x['papel']] ?? $x['papel']) ?><?= $x['login'] === $usuario['login'] ? ' · você' : '' ?></option><?php endforeach; ?>
            </select>
          </label>
          <noscript><button class="btn secundario">Abrir</button></noscript>
        </form>
      <?php else: ?>
        <h2>O que você quer receber</h2>
      <?php endif; ?>
      <?php if (!$tipos): ?>
        <p class="dica">Nenhuma notificação para as abas que o seu usuário vê.</p>
      <?php else: ?>
        <?php if ($admin): ?><p class="dica"><?= $personalizado ? 'Avisos escolhidos para ' . e($alvo['nome'] ?? $login) . '.' : 'Usando o padrão da função ' . e(DH_PAPEIS[$alvo['papel']] ?? '') . ' (tabela no fim da página). Os avisos esmaecidos ela não recebe hoje; marque um canal para ela passar a receber.' ?></p><?php endif; ?>
        <form method="post" action="./?acao=notificacoes-config-salvar" class="notif-prefs" id="notif-prefs">
          <?= $csrf ?><input type="hidden" name="login" value="<?= e($login) ?>">
          <label class="notif-email"><?= $proprio ? 'Seu e-mail para as notificações' : 'E-mail de ' . e($alvo['nome'] ?? $login) . ' para as notificações' ?> <small>(para as marcadas com ✉️<?= ($alvo['papel'] ?? '') === 'admin' ? '; em branco, vão para ' . e(DH_EMPRESA['email']) : '' ?>)</small>
            <input type="email" name="email" value="<?= e($n['emails'][$login] ?? '') ?>" placeholder="<?= ($alvo['papel'] ?? '') === 'admin' ? e(DH_EMPRESA['email']) : 'email@exemplo.com' ?>" autocomplete="<?= $proprio ? 'email' : 'off' ?>"></label>
          <div class="notif-canais-topo" aria-hidden="true"><span></span><?php foreach (DH_NOTIF_CANAIS as [$ci, $cr]): ?><span><?= $ci ?><small><?= e($cr) ?></small></span><?php endforeach; ?></div>
          <?php foreach ($tipos as $k => [$perm, $ic, $nome, $desc, $quem]): $vale = $recebe($k); ?>
            <div class="notif-tipo<?= $vale ? '' : ' fora' ?>">
              <span class="int-ic"><?= $ic ?></span>
              <span class="int-txt"><strong><?= e($nome) ?></strong><small><?= e($desc) ?></small></span>
              <?php foreach (DH_NOTIF_CANAIS as $canal => [$ci, $cr]): ?>
                <label class="notif-canal" title="<?= e($cr) ?>"><input type="hidden" name="canais[<?= e($k) ?>][_]" value="1"><input type="checkbox" name="canais[<?= e($k) ?>][<?= $canal ?>]" value="1" <?= $vale && dh_notif_canal($n, $login, $k, $canal) ? 'checked' : '' ?> aria-label="<?= e($nome . ' · ' . $cr) ?>"><span><?= $ci ?></span></label>
              <?php endforeach; ?>
            </div>
          <?php endforeach; ?>
          <button class="btn primario">Salvar</button>
        </form>
        <?php if ($admin && $personalizado): ?>
          <form method="post" action="./?acao=notificacoes-usuario-padrao" onsubmit="return confirm('Voltar <?= e(addslashes($alvo['nome'] ?? $login)) ?> ao padrão da função?');"><?= $csrf ?><input type="hidden" name="login" value="<?= e($login) ?>"><button class="link">Voltar ao padrão da função <?= e(DH_PAPEIS[$alvo['papel']] ?? '') ?></button></form>
        <?php endif; ?>
        <script>
          // Salva na hora ao tocar num canal
          document.querySelectorAll('#notif-prefs input[type=checkbox], #notif-prefs input[type=email]').forEach(function (c) {
            c.addEventListener('change', function () { document.getElementById('notif-prefs').submit(); });
          });
        </script>
      <?php endif; ?>

      <?php if ($admin): ?>
        <h2 id="emails-fora">✉️ E-mails de fora (sem login no painel)</h2>
        <p class="dica">Para quem não tem usuário (ex.: contador, financeiro, fornecedor): recebe só o e-mail daquele aviso. Um por linha. Em "Hoje vai para" estão todos os endereços que recebem cada e-mail agora (usuários com ✉️ + os de fora).</p>
        <form method="post" action="./?acao=notificacoes-extras-salvar" class="form-cerveja emails-fora">
          <?= $csrf ?>
          <?php foreach (DH_NOTIF_TIPOS as $k => [$perm, $ic, $nome]): $hoje = dh_notif_emails_tipo($k); ?>
            <label><?= $ic ?> <?= e($nome) ?>
              <textarea name="extras[<?= e($k) ?>]" rows="1" autocapitalize="none" spellcheck="false" placeholder="email@exemplo.com"><?= e(implode("\n", $n['extras'][$k] ?? [])) ?></textarea>
              <small>Hoje vai para: <?= $hoje ? e(implode(', ', $hoje)) : 'ninguém' ?></small></label>
          <?php endforeach; ?>
          <button class="btn primario">Salvar e-mails de fora</button>
        </form>
      <?php endif; ?>

      <?php if (pode('usuarios')): ?>
        <h2 id="quem-recebe">Padrão por função</h2>
        <p class="dica">Vale para quem não foi personalizado acima. Quem tem a aba liberada em Usuários → 🔐 Permissões já recebe as notificações dela, sozinho. Aqui você marca as <b>funções</b> que recebem cada tipo, inclusive quem não tem a aba (ex.: lembrete de evento para a Cervejaria; o aviso aparece, mas o contrato não abre). Cada pessoa ainda pode desligar as suas em ⚙ Configurar.</p>
        <form method="post" action="./?acao=notificacoes-papeis-salvar" class="form-cerveja">
          <?= $csrf ?>
          <div class="tabela-rolar">
            <table class="tabela-papeis">
              <thead><tr><th></th><?php foreach (DH_PAPEIS as $p => $rot): ?><th><?= e($rot) ?></th><?php endforeach; ?></tr></thead>
              <tbody>
                <?php foreach (DH_NOTIF_TIPOS as $k => [$perm, $ic, $nome]): $marcados = dh_notif_papeis($k, $n); ?>
                  <tr><th><?= $ic ?> <?= e($nome) ?></th><?php foreach (DH_PAPEIS as $p => $rot): ?><td><input type="checkbox" name="papeis[<?= e($k) ?>][]" value="<?= e($p) ?>" aria-label="<?= e($nome . ' · ' . $rot) ?>" <?= in_array($p, $marcados, true) ? 'checked' : '' ?>></td><?php endforeach; ?></tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <button class="btn primario">Salvar quem recebe</button>
        </form>
      <?php endif; ?>

      <script>
      (function () {
        var caixa = document.getElementById('push-caixa');
        if (!caixa) return;
        var ios = /iPhone|iPad/.test(navigator.userAgent) && !window.navigator.standalone;
        if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
          if (ios) document.getElementById('push-ios').hidden = false;
          return;
        }
        caixa.hidden = false;
        var estado = document.getElementById('push-estado'), ativar = document.getElementById('push-ativar'), ok = document.getElementById('push-ok');
        function chave(b64) {
          var s = atob((b64 + '==='.slice((b64.length + 3) % 4)).replace(/-/g, '+').replace(/_/g, '/')), a = new Uint8Array(s.length);
          for (var i = 0; i < s.length; i++) a[i] = s.charCodeAt(i);
          return a;
        }
        function enviar(acao, endpoint) {
          var d = new FormData(); d.append('csrf', caixa.dataset.csrf); d.append('endpoint', endpoint);
          return fetch('./?acao=' + acao, { method: 'POST', body: d, credentials: 'same-origin' });
        }
        function mostrar(ativo) {
          ativar.hidden = ativo; ok.hidden = !ativo;
          estado.textContent = ativo ? '✓ Ativado neste aparelho. As notificações ligadas abaixo chegam na barra do celular, mesmo com o painel fechado.'
            : (Notification.permission === 'denied' ? 'As notificações estão bloqueadas para este site. Libere nas configurações do navegador (cadeado ao lado do endereço → Notificações) e tente de novo.'
            : 'Ative para receber na barra de notificações deste aparelho, mesmo com o painel fechado.');
        }
        navigator.serviceWorker.register('sw.js').then(function (reg) {
          reg.pushManager.getSubscription().then(function (s) {
            if (s) enviar('push-inscrever', s.endpoint);
            mostrar(!!s);
          });
          ativar.onclick = function () {
            ativar.disabled = true;
            Notification.requestPermission().then(function (p) {
              if (p !== 'granted') { mostrar(false); ativar.disabled = false; return; }
              return reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: chave(caixa.dataset.chave) }).then(function (s) {
                return enviar('push-inscrever', s.endpoint).then(function () { mostrar(true); });
              });
            }).catch(function (e) { estado.textContent = 'Não deu para ativar: ' + e.message; }).then(function () { ativar.disabled = false; });
          };
          document.getElementById('push-desativar').onclick = function () {
            reg.pushManager.getSubscription().then(function (s) {
              if (!s) return mostrar(false);
              enviar('push-cancelar', s.endpoint); s.unsubscribe().then(function () { mostrar(false); });
            });
          };
        });
      })();
      </script>
    <?php
}
