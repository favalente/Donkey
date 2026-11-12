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
                    $link = str_starts_with($x['link'], './?') ? $x['link'] : $link;
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
            foreach (notificacoes_minhas() as $x) {
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
            exigir_post();
            dh_notif_prefs_salvar($usuario['login'], array_map('strval', (array) ($_POST['tipos'] ?? [])));
            ir('./?acao=notificacoes-config', 'Preferências salvas.');

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
    global $csrf, $usuario;
    $n = dh_notificacoes();
    $chaves = dh_push_chaves();
    $tipos = array_filter(DH_NOTIF_TIPOS, fn($t, $k) => pode($t[0]) && dh_notif_tipo_para($k, $usuario), ARRAY_FILTER_USE_BOTH);
    ?>
      <h1>🔔 Notificações</h1>
      <?php notificacoes_abas('notificacoes-config'); ?>

      <?php if ($chaves): ?>
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
        <p class="dica">Vale para este aparelho; ative também nos outros que usar. Os interruptores abaixo valem para todos os seus aparelhos e para o 🔔 do painel.</p>
      <?php endif; ?>

      <h2>O que você quer receber</h2>
      <?php if (!$tipos): ?>
        <p class="dica">Nenhuma notificação para as abas que o seu usuário vê.</p>
      <?php else: ?>
        <form method="post" action="./?acao=notificacoes-config-salvar" class="notif-prefs" id="notif-prefs">
          <?= $csrf ?>
          <?php foreach ($tipos as $k => [$perm, $ic, $nome, $desc, $quem]): ?>
            <label class="interruptor">
              <span class="int-ic"><?= $ic ?></span>
              <span class="int-txt"><strong><?= e($nome) ?></strong><small><?= e($desc) ?></small></span>
              <input type="checkbox" name="tipos[]" value="<?= e($k) ?>" <?= dh_notif_quer($n, $usuario['login'], $k) ? 'checked' : '' ?>>
              <span class="int-chave" aria-hidden="true"></span>
            </label>
          <?php endforeach; ?>
          <noscript><button class="btn primario">Salvar</button></noscript>
        </form>
        <script>
          // Salva na hora ao mexer no interruptor
          document.querySelectorAll('#notif-prefs input[type=checkbox]').forEach(function (c) {
            c.addEventListener('change', function () { document.getElementById('notif-prefs').submit(); });
          });
        </script>
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
