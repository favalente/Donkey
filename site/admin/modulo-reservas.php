<?php
// Aba Reservas: calendário das reservas importadas do Google Agenda, uma casa por vez, e o link para a equipe.

function reservas_acao(string $acao): bool
{
    global $telaErro;
    switch ($acao) {
        case 'reservas':
            exigir('reservas');
            return true;

        case 'reservas-atualizar':
            exigir_post();
            exigir('reservas');
            $telaErro = 'reservas';
            $erro = dh_reservas_sincronizar(true);
            if ($erro !== '') {
                throw new RuntimeException($erro);
            }
            ir('./?acao=reservas', 'Reservas atualizadas do Google Agenda: ' . count(dh_reservas()['reservas']) . ' no total.');

        case 'reservas-config':
            // Endereço da agenda e opções (só o administrador)
            exigir_post();
            exigir('usuarios');
            $telaErro = 'reservas';
            $r = dh_reservas();
            $url = trim((string) ($_POST['ics_url'] ?? ''));
            if ($url !== '' && $url !== '••••') {
                $url = preg_replace('#^webcal://#i', 'https://', $url);
                if (!preg_match('#^https://calendar\.google\.com/#i', $url)) {
                    throw new RuntimeException('Cole o "endereço secreto no formato iCal" do Google Agenda (começa com https://calendar.google.com/).');
                }
                $r['config']['ics_url'] = $url;
            }
            if (!empty($_POST['tirar_url'])) {
                $r['config']['ics_url'] = '';
                $r['reservas'] = [];
            }
            $r['config']['unidade'] = isset(DH_UNIDADES[$_POST['unidade'] ?? '']) ? $_POST['unidade'] : 'aldeota';
            $r['config']['so_reservas'] = !empty($_POST['so_reservas']);
            $r['atualizado_em'] = 0;
            dh_reservas_salvar($r);
            $erro = $r['config']['ics_url'] !== '' ? dh_reservas_sincronizar(true) : '';
            if ($erro !== '') {
                throw new RuntimeException('Salvo, mas a importação falhou: ' . $erro);
            }
            ir('./?acao=reservas', $r['config']['ics_url'] !== '' ? 'Agenda das reservas salva e importada: ' . count(dh_reservas()['reservas']) . ' reservas.' : 'Agenda das reservas desligada.');

        case 'reservas-link':
            // Link da equipe: cria ou troca (o antigo para de funcionar)
            exigir_post();
            exigir('usuarios');
            $r = dh_reservas();
            $r['config']['token'] = bin2hex(random_bytes(16));
            dh_reservas_salvar($r);
            ir('./?acao=reservas#link-equipe', 'Novo link da equipe criado.');
    }
    return false;
}

function reservas_tela(string $tela): bool
{
    if ($tela !== 'reservas') {
        return false;
    }
    global $csrf, $usuario;
    dh_reservas_sincronizar();
    $r = dh_reservas();
    $mes = dh_mes_param();
    if (isset(DH_UNIDADES[$_GET['unidade'] ?? ''])) {
        $_SESSION['reservas_sede'] = $_GET['unidade'];
    }
    $un = $_SESSION['reservas_sede'] ?? (isset(DH_UNIDADES[$usuario['unidade'] ?? '']) ? $usuario['unidade'] : array_key_first(DH_UNIDADES));
    $porDia = dh_reservas_por_dia($r, $mes, $un);
    $hoje = date('Y-m-d');
    $proximas = array_values(array_filter($r['reservas'], fn($x) => $x['unidade'] === $un && $x['data'] >= $hoje && $x['data'] <= date('Y-m-d', strtotime('+7 days'))));
    usort($proximas, fn($a, $b) => strcmp($a['data'] . $a['hora'], $b['data'] . $b['hora']));
    ?>
      <div class="titulo-acao"><h1>Reservas</h1>
        <?php if ($r['config']['ics_url'] !== ''): ?><form method="post" action="./?acao=reservas-atualizar"><?= $csrf ?><button class="btn-acao">↻ Atualizar</button></form><?php endif; ?>
      </div>
      <?php if ($r['config']['ics_url'] === ''): ?>
        <p class="aviso">Ainda sem a agenda do Google. <?= pode('usuarios') ? 'Cole o endereço dela em <a href="#config-reservas">⚙ Agenda do Google</a>, no fim da página.' : 'Peça para o administrador ligar a agenda das reservas.' ?></p>
      <?php else: ?>
        <p class="dica">Vem do Google Agenda, sozinho (a cada 10 minutos). Última leitura: <?= $r['atualizado_em'] ? e(date('d/m H:i', (int) $r['atualizado_em'])) : '—' ?>.<?php if ($r['erro'] !== ''): ?> <b style="color:var(--erro)">Falhou: <?= e($r['erro']) ?></b><?php endif; ?></p>
      <?php endif; ?>

      <nav class="sede-escolha" aria-label="Reservas de qual casa">
        <?php foreach (DH_UNIDADES as $u => $nomeU): ?>
          <a class="sede-opcao u-<?= $u ?><?= $un === $u ? ' ativa' : '' ?>" href="./?acao=reservas&amp;mes=<?= e($mes) ?>&amp;unidade=<?= $u ?>"><?= $un === $u ? '✓ ' : '' ?><?= e($nomeU) ?></a>
        <?php endforeach; ?>
      </nav>

      <?php reservas_calendario($porDia, $mes, './?acao=reservas&mes=', 'u-' . $un); ?>

      <h2>Próximos 7 dias · <?= e(DH_UNIDADES[$un]) ?></h2>
      <?php if (!$proximas): ?><p class="dica">Nenhuma reserva nos próximos 7 dias.</p><?php endif; ?>
      <ul class="lista-reservas">
        <?php foreach ($proximas as $x): ?>
          <li><button type="button" class="cal-reserva" data-info="<?= e(json_encode(reserva_info($x), JSON_UNESCAPED_UNICODE)) ?>">
            <span class="lr-data"><strong><?= date('d', strtotime($x['data'])) ?></strong><?= e(mb_substr(DH_DIAS_SEMANA[(int) date('w', strtotime($x['data']))], 0, 3)) ?></span>
            <span class="lr-info"><strong><?= e(dh_reserva_rotulo($x)) ?></strong><small><?= $x['hora'] !== '' ? e($x['hora']) : 'Dia inteiro' ?></small></span>
          </button></li>
        <?php endforeach; ?>
      </ul>

      <?php if (pode('usuarios')): ?>
        <h2 id="link-equipe">Link para a equipe</h2>
        <?php if ($r['config']['token'] !== ''): $link = dh_url_site() . 'reservas/?t=' . $r['config']['token']; ?>
          <p class="dica">Quem tiver o link vê o calendário das reservas (só ver, sem login). Mande no grupo da equipe.</p>
          <p><code class="link-equipe"><?= e($link) ?></code></p>
          <p class="botoes-link">
            <button type="button" class="btn secundario" onclick="navigator.clipboard.writeText(<?= e(json_encode($link)) ?>).then(function () { alert('Link copiado.'); })">📋 Copiar link</button>
            <a class="btn secundario" href="https://wa.me/?text=<?= e(rawurlencode('Calendário das reservas da Donkey Head Cervejaria: ' . $link)) ?>" target="_blank" rel="noopener">Enviar no WhatsApp</a>
          </p>
        <?php endif; ?>
        <form method="post" action="./?acao=reservas-link" <?= $r['config']['token'] !== '' ? 'onsubmit="return confirm(\'Trocar o link? O link antigo para de funcionar.\');"' : '' ?>><?= $csrf ?><button class="btn <?= $r['config']['token'] !== '' ? 'secundario' : 'primario' ?>"><?= $r['config']['token'] !== '' ? 'Trocar o link (o antigo para de funcionar)' : 'Criar o link da equipe' ?></button></form>

        <details class="config-reservas" id="config-reservas" <?= $r['config']['ics_url'] === '' ? 'open' : '' ?>>
          <summary>⚙ Agenda do Google</summary>
          <p class="dica">No Google Agenda (no computador): passe o mouse na agenda das reservas → <strong>⋮ → Configurações e compartilhamento</strong> → <strong>"Integrar agenda"</strong> → copie o <strong>"Endereço secreto no formato iCal"</strong> e cole abaixo. Fica guardado só no servidor.</p>
          <form method="post" action="./?acao=reservas-config" class="form-cerveja">
            <?= $csrf ?>
            <label>Endereço secreto no formato iCal <input name="ics_url" type="url" placeholder="<?= $r['config']['ics_url'] !== '' ? 'já cadastrado (cole outro para trocar)' : 'https://calendar.google.com/calendar/ical/.../basic.ics' ?>"></label>
            <label>Casa das reservas <small>(quando o evento não diz Aldeota ou Maraponga)</small>
              <select name="unidade"><?php foreach (DH_UNIDADES as $u => $nomeU): ?><option value="<?= $u ?>" <?= $r['config']['unidade'] === $u ? 'selected' : '' ?>><?= e($nomeU) ?></option><?php endforeach; ?></select></label>
            <label class="check"><input type="checkbox" name="so_reservas" value="1" <?= $r['config']['so_reservas'] ? 'checked' : '' ?>> Essa agenda também tem shows e outros compromissos: trazer só o que for reserva</label>
            <?php if ($r['config']['ics_url'] !== ''): ?><label class="check"><input type="checkbox" name="tirar_url" value="1"> Desligar a agenda (apaga as reservas importadas)</label><?php endif; ?>
            <button class="btn primario">Salvar e importar</button>
          </form>
        </details>
      <?php endif; ?>

      <?php reservas_dialogo(); ?>
    <?php
    return true;
}

function reserva_info(array $x): array
{
    return dh_reserva_info($x);
}

// Calendário do mês (mesmo padrão da Agenda e dos Contratos): tocar na reserva mostra os detalhes
function reservas_calendario(array $porDia, string $mes, string $nav, string $classe): void
{
    $ini = $mes . '-01';
    $fim = date('Y-m-t', strtotime($ini));
    $hoje = date('Y-m-d');
    $ant = dh_mes_somar($mes, -1);
    $prox = dh_mes_somar($mes, 1);
    $mesAnt = DH_MESES[(int) substr($ant, 5, 2)];
    $mesProx = DH_MESES[(int) substr($prox, 5, 2)];
    ?>
      <div class="cal-admin cal-reservas">
        <?php if ($mes !== date('Y-m')): ?><p class="centro"><a class="btn secundario hoje-btn" data-manter-posicao href="<?= e($nav . date('Y-m')) ?>">Voltar para o mês atual</a></p><?php endif; ?>
        <div class="mes-nav cal-nav">
          <a class="btn mes-btn" data-mes="anterior" href="<?= e($nav . $ant) ?>" aria-label="<?= e($mesAnt) ?>">‹ <span class="mes-longo"><?= e($mesAnt) ?></span><span class="mes-curto"><?= e(mb_substr($mesAnt, 0, 3)) ?></span></a>
          <strong><?= e(DH_MESES[(int) substr($mes, 5, 2)]) ?> <small><?= e(substr($mes, 0, 4)) ?></small></strong>
          <a class="btn mes-btn" data-mes="proximo" href="<?= e($nav . $prox) ?>" aria-label="<?= e($mesProx) ?>"><span class="mes-longo"><?= e($mesProx) ?></span><span class="mes-curto"><?= e(mb_substr($mesProx, 0, 3)) ?></span> ›</a>
        </div>
        <div class="calendario">
          <?php foreach (['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'] as $d): ?><div class="cal-semana"><?= $d ?></div><?php endforeach; ?>
          <?php for ($i = 0; $i < (int) date('w', strtotime($ini)); $i++): ?><div class="cal-vazio"></div><?php endfor; ?>
          <?php for ($t = strtotime($ini); $t <= strtotime($fim); $t = strtotime('+1 day', $t)): $dia = date('Y-m-d', $t); ?>
            <div class="cal-dia so-ver<?= $dia < $hoje ? ' passado' : '' ?><?= $dia === $hoje ? ' hoje' : '' ?>">
              <span class="cal-num"><?= date('j', $t) ?></span>
              <?php foreach ($porDia[$dia] ?? [] as $x): ?>
                <button type="button" class="cal-show cal-reserva <?= e($classe) ?>" data-info="<?= e(json_encode(reserva_info($x), JSON_UNESCAPED_UNICODE)) ?>"><?= e(dh_reserva_rotulo($x)) ?></button>
              <?php endforeach; ?>
            </div>
          <?php endfor; ?>
          <?php for ($i = (int) date('w', strtotime($fim)); $i < 6; $i++): ?><div class="cal-vazio"></div><?php endfor; ?>
        </div>
      </div>
      <script>
        (function () {
          var cal = document.querySelector('.cal-reservas');
          if (cal && window.dhArrastarMes) dhArrastarMes(cal.querySelector('.calendario'), cal.querySelector('[data-mes=anterior]'), cal.querySelector('[data-mes=proximo]'));
        })();
      </script>
    <?php
}

// Janela com os detalhes da reserva (painel e link da equipe)
function reservas_dialogo(): void
{
    ?>
      <dialog class="cal-menu reserva-detalhe" id="reserva-detalhe">
        <p class="cal-menu-quando"></p>
        <h3 class="rd-titulo"></h3>
        <p class="rd-pessoas"></p>
        <p class="rd-texto"></p>
        <div class="cal-menu-botoes"><button type="button" class="btn secundario" data-fechar>Fechar</button></div>
      </dialog>
      <script>
        (function () {
          var d = document.getElementById('reserva-detalhe');
          document.querySelectorAll('.cal-reserva').forEach(function (b) {
            b.addEventListener('click', function (ev) {
              ev.stopPropagation();
              var i = JSON.parse(b.dataset.info);
              d.querySelector('.cal-menu-quando').textContent = i.quando + (i.casa ? ' · ' + i.casa : '');
              d.querySelector('.rd-titulo').textContent = i.titulo;
              d.querySelector('.rd-pessoas').textContent = i.pessoas;
              d.querySelector('.rd-texto').textContent = i.texto;
              d.showModal();
            });
          });
          d.querySelector('[data-fechar]').addEventListener('click', function () { d.close(); });
          d.addEventListener('click', function (e) { if (e.target === d) d.close(); });
        })();
      </script>
    <?php
}
