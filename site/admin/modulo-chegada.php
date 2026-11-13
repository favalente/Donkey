<?php
// Caderno de chegada: tela bem simples para quem anota a hora que cada pessoa chega. Abre direto no dia de
// hoje, com quem está na escala; um toque em "CHEGOU AGORA" anota a hora (ou digita a hora certa).
// A hora vai para o campo "Chegou às" da Escala, que calcula o desconto de atraso.

// Dia de trabalho: até as 6h da manhã ainda conta como o dia anterior (a casa fecha depois da meia-noite)
function chegada_dia(): string
{
    return (int) date('G') < 6 ? date('Y-m-d', strtotime('-1 day')) : date('Y-m-d');
}

function chegada_acao(string $acao): bool
{
    global $usuario;
    switch ($acao) {
        case 'chegada':
            exigir('chegada');
            return true;

        case 'chegada-salvar':
            exigir_post();
            exigir('chegada');
            $dia = chegada_dia();
            $k = (int) ($_POST['k'] ?? -1);
            $pessoa = (string) ($_POST['pessoa'] ?? '');
            $hora = ($_POST['agora'] ?? '') === '1' ? date('H:i') : dh_escala_hora((string) ($_POST['hora'] ?? ''));
            $apagar = ($_POST['apagar'] ?? '') === '1';
            $e = dh_escala();
            $pos = $e['dias'][$dia]['posicoes'][$k] ?? null;
            // Confere que a linha ainda é da mesma pessoa (a escala pode ter mudado)
            if (!$pos || $pos['pessoa'] !== $pessoa) {
                ir('./?acao=chegada', 'A escala de hoje mudou. Confira de novo.');
            }
            if (!$apagar && $hora === '') {
                ir('./?acao=chegada#p' . $k, 'Digite a hora assim: 1730 (para 17:30).');
            }
            $e['dias'][$dia]['posicoes'][$k]['chegada'] = $apagar ? '' : $hora;
            $e['dias'][$dia]['posicoes'][$k]['chegada_por'] = $usuario['nome'];
            dh_escala_salvar($e);
            $nome = dh_escala_pessoa($e, $pessoa)['nome'] ?? '';
            ir('./?acao=chegada#p' . $k, $apagar ? $nome . ': hora apagada.' : $nome . ' chegou às ' . $hora . '.');
    }
    return false;
}

function chegada_tela(string $tela): bool
{
    if ($tela !== 'chegada') {
        return false;
    }
    global $csrf;
    $dia = chegada_dia();
    $e = dh_escala();
    $posicoes = $e['dias'][$dia]['posicoes'] ?? [];
    $linhas = [];
    foreach ($posicoes as $k => $p) {
        $pe = dh_escala_pessoa($e, $p['pessoa'] ?? '');
        if ($pe) {
            $linhas[] = [$k, $p, $pe];
        }
    }
    // Quem ainda não chegou primeiro, por horário de entrada; depois quem já chegou
    usort($linhas, fn($a, $b) => [($a[1]['chegada'] ?? '') !== '', $a[1]['entrada'], $a[2]['nome']] <=> [($b[1]['chegada'] ?? '') !== '', $b[1]['entrada'], $b[2]['nome']]);
    $faltam = count(array_filter($linhas, fn($l) => ($l[1]['chegada'] ?? '') === ''));
    ?>
      <div class="caderno">
        <h1 class="caderno-dia">📒 <?= e(mb_strtoupper(DH_DIAS_SEMANA[(int) date('w', strtotime($dia))])) ?><small><?= e(date('d/m', strtotime($dia))) ?></small></h1>
        <?php if (!$linhas): ?>
          <p class="caderno-vazio">Hoje não tem ninguém na escala.</p>
        <?php else: ?>
          <p class="caderno-faltam"><?= $faltam ? '⏳ Faltam chegar: <b>' . $faltam . '</b>' : '✅ Todos chegaram' ?></p>
        <?php endif; ?>
        <?php foreach ($linhas as [$k, $p, $pe]): $chegou = ($p['chegada'] ?? '') !== ''; ?>
          <div class="caderno-linha<?= $chegou ? ' chegou' : '' ?>" id="p<?= $k ?>">
            <div class="caderno-nome">
              <strong><?= e($pe['nome']) ?></strong>
              <span>entra <?= e($p['entrada']) ?></span>
            </div>
            <?php if ($chegou): ?>
              <div class="caderno-hora-ok">✅ <?= e($p['chegada']) ?></div>
              <details class="caderno-corrigir">
                <summary>corrigir</summary>
                <form method="post" action="./?acao=chegada-salvar"><?= $csrf ?><input type="hidden" name="k" value="<?= $k ?>"><input type="hidden" name="pessoa" value="<?= e($p['pessoa']) ?>">
                  <input name="hora" inputmode="numeric" maxlength="5" placeholder="17:30" aria-label="Hora que chegou" value="<?= e($p['chegada']) ?>"><button class="caderno-btn-ok">OK</button>
                </form>
                <form method="post" action="./?acao=chegada-salvar" onsubmit="return confirm('Apagar a hora de <?= e(addslashes($pe['nome'])) ?>?');"><?= $csrf ?><input type="hidden" name="k" value="<?= $k ?>"><input type="hidden" name="pessoa" value="<?= e($p['pessoa']) ?>"><input type="hidden" name="apagar" value="1"><button class="caderno-apagar">apagar</button></form>
              </details>
            <?php else: ?>
              <form method="post" action="./?acao=chegada-salvar" class="caderno-agora"><?= $csrf ?><input type="hidden" name="k" value="<?= $k ?>"><input type="hidden" name="pessoa" value="<?= e($p['pessoa']) ?>"><input type="hidden" name="agora" value="1">
                <button>CHEGOU AGORA</button>
              </form>
              <form method="post" action="./?acao=chegada-salvar" class="caderno-digitar"><?= $csrf ?><input type="hidden" name="k" value="<?= $k ?>"><input type="hidden" name="pessoa" value="<?= e($p['pessoa']) ?>">
                <input name="hora" inputmode="numeric" maxlength="5" placeholder="ou digite: 1730" aria-label="Hora que <?= e($pe['nome']) ?> chegou"><button class="caderno-btn-ok">OK</button>
              </form>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php
    return true;
}
