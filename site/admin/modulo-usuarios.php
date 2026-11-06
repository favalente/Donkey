<?php
// Painel: usuários da equipe e o que cada função pode fazer (só administrador).

function usuarios_acao(string $acao): bool
{
    global $usuarios, $usuario, $telaErro;
    switch ($acao) {
        case 'usuarios':
        case 'permissoes':
            exigir('usuarios');
            return true;

        case 'permissoes-salvar':
            // Abas que o usuário vê (no lugar das da função); "padrão" volta às da função
            exigir_post();
            exigir('usuarios');
            $telaErro = 'permissoes';
            $login = (string) ($_POST['login'] ?? '');
            if (!isset($usuarios[$login])) {
                throw new RuntimeException('Usuário não encontrado.');
            }
            if ($usuarios[$login]['papel'] === 'admin') {
                throw new RuntimeException('O administrador sempre vê tudo.');
            }
            if (!empty($_POST['padrao'])) {
                unset($usuarios[$login]['permissoes']);
                $msg = 'voltou às abas padrão da função ' . DH_PAPEIS[$usuarios[$login]['papel']] . '.';
            } else {
                $abas = array_values(array_intersect(array_keys(DH_ABAS), (array) ($_POST['abas'] ?? [])));
                // Editar pressupõe ver
                foreach (['agenda_editar' => 'agenda', 'bandas_editar' => 'bandas'] as $editar => $ver) {
                    if (in_array($editar, $abas, true) && !in_array($ver, $abas, true)) {
                        $abas[] = $ver;
                    }
                }
                if (!$abas) {
                    throw new RuntimeException('Marque pelo menos uma aba (ou exclua o usuário).');
                }
                $usuarios[$login]['permissoes'] = $abas;
                $msg = 'vê ' . count($abas) . ' aba(s): ' . implode(', ', array_map(fn($k) => DH_ABAS[$k][0], $abas)) . '.';
            }
            dh_usuarios_salvar($usuarios);
            ir('./?acao=permissoes&login=' . rawurlencode($login), ($usuarios[$login]['nome'] ?? $login) . ' ' . $msg);

        case 'usuario-salvar':
            exigir_post();
            exigir('usuarios');
            $telaErro = 'usuarios';
            $login = strtolower(trim((string) ($_POST['login'] ?? '')));
            $novo = !isset($usuarios[$login]);
            $papel = (string) ($_POST['papel'] ?? '');
            $senha = (string) ($_POST['senha'] ?? '');
            if (!login_valido($login)) {
                throw new RuntimeException('Usuário: use de 3 a 30 letras minúsculas, números, ponto ou traço (sem espaço).');
            }
            if (!isset(DH_PAPEIS[$papel])) {
                throw new RuntimeException('Escolha a função do usuário.');
            }
            if ($novo && strlen($senha) < 8) {
                throw new RuntimeException('A senha precisa ter pelo menos 8 caracteres.');
            }
            if (!$novo && $senha !== '' && strlen($senha) < 8) {
                throw new RuntimeException('A nova senha precisa ter pelo menos 8 caracteres.');
            }
            if ($login === $usuario['login'] && $papel !== 'admin') {
                throw new RuntimeException('Você não pode tirar o seu próprio acesso de administrador.');
            }
            $u = $usuarios[$login] ?? ['login' => $login];
            $u['nome'] = mb_substr(trim((string) ($_POST['nome'] ?? '')), 0, 60) ?: $login;
            // Mudou a função: as abas voltam às da nova função (dá para ajustar depois em Permissões)
            if (($u['papel'] ?? '') !== $papel) {
                unset($u['permissoes']);
            }
            $u['papel'] = $papel;
            $u['unidade'] = isset(DH_UNIDADES[$_POST['unidade'] ?? '']) ? $_POST['unidade'] : '';
            if ($senha !== '') {
                $u['hash'] = password_hash($senha, PASSWORD_DEFAULT);
            }
            $usuarios[$login] = $u;
            dh_usuarios_salvar($usuarios);
            ir('./?acao=usuarios', $novo ? 'Usuário "' . $login . '" criado.' : 'Usuário "' . $login . '" atualizado.');

        case 'usuario-excluir':
            exigir_post();
            exigir('usuarios');
            $telaErro = 'usuarios';
            $login = (string) ($_POST['login'] ?? '');
            if ($login === $usuario['login']) {
                throw new RuntimeException('Você não pode excluir o seu próprio usuário.');
            }
            unset($usuarios[$login]);
            dh_usuarios_salvar($usuarios);
            ir('./?acao=usuarios', 'Usuário "' . $login . '" excluído.');
    }
    return false;
}

function usuarios_tela(string $tela): bool
{
    global $usuarios, $usuario, $csrf;
    if ($tela === 'permissoes') {
        usuarios_tela_permissoes();
        return true;
    }
    if ($tela !== 'usuarios') {
        return false;
    }
    $acessos = [
        'admin' => 'Tudo, inclusive usuários e importação',
        'financeiro' => 'Agenda e bandas com cachê e consumação, relatório financeiro',
        'designer' => 'Só visualiza: agenda do mês e bandas (nome, tributo, Instagram e fotos para baixar)',
        'barman' => 'Tap list e cervejas',
        'cervejaria' => 'Só a Brassagem e as Receitas: tanques, cadernos dos lotes e receitas do BeerSmith',
    ];
    ?>
      <div class="titulo-acao">
        <h1>Usuários</h1>
        <a class="btn primario" href="./?acao=permissoes">🔐 Permissões</a>
        <?php if (pode('importar')): ?><a class="btn secundario" href="./?acao=importar">Importar Google Agenda</a><?php endif; ?>
      </div>
      <ul class="lista">
        <?php foreach ($usuarios as $u): ?>
          <li>
            <details class="usuario">
              <summary>
                <span class="info"><strong><?= e($u['nome']) ?></strong><span><?= e($u['login']) ?> · <?= e(DH_PAPEIS[$u['papel']]) ?><?= isset($u['permissoes']) && $u['papel'] !== 'admin' ? ' (personalizado)' : '' ?><?= !empty($u['unidade']) ? ' · ' . e(DH_UNIDADES[$u['unidade']]) : '' ?></span></span>
                <span class="seta">›</span>
              </summary>
              <form method="post" action="./?acao=usuario-salvar" class="form-cerveja">
                <?= $csrf ?>
                <input type="hidden" name="login" value="<?= e($u['login']) ?>">
                <label>Nome <input name="nome" value="<?= e($u['nome']) ?>" maxlength="60"></label>
                <label>Função
                  <select name="papel"><?php foreach (DH_PAPEIS as $k => $rot): ?><option value="<?= $k ?>" <?= $k === $u['papel'] ? 'selected' : '' ?>><?= e($rot) ?></option><?php endforeach; ?></select>
                </label>
                <label>Unidade padrão nos shows novos
                  <select name="unidade"><option value="">—</option><?php foreach (DH_UNIDADES as $k => $rot): ?><option value="<?= $k ?>" <?= $k === ($u['unidade'] ?? '') ? 'selected' : '' ?>><?= e($rot) ?></option><?php endforeach; ?></select>
                </label>
                <label>Nova senha <small>(deixe em branco para manter)</small><input type="password" name="senha" autocomplete="new-password"></label>
                <button class="btn primario">Salvar</button>
              </form>
              <div class="abas-usuario">
                <p><b>Abas que aparecem:</b> <?= $u['papel'] === 'admin' ? 'todas (administrador)' : e(implode(', ', array_map(fn($k) => DH_ABAS[$k][0], $u['permissoes'] ?? abas_da_funcao($u['papel'])))) ?></p>
                <?php if ($u['papel'] !== 'admin'): ?><a class="btn secundario" href="./?acao=permissoes&amp;login=<?= e(rawurlencode($u['login'])) ?>">🔐 Escolher as abas de <?= e($u['nome']) ?></a><?php endif; ?>
              </div>
              <?php if ($u['login'] !== $usuario['login']): ?>
                <form method="post" action="./?acao=usuario-excluir" class="excluir" onsubmit="return confirm('Excluir o usuário <?= e($u['login']) ?>?');">
                  <?= $csrf ?><input type="hidden" name="login" value="<?= e($u['login']) ?>">
                  <button class="btn perigo">Excluir usuário</button>
                </form>
              <?php endif; ?>
            </details>
          </li>
        <?php endforeach; ?>
      </ul>

      <h2>Novo usuário</h2>
      <form method="post" action="./?acao=usuario-salvar" class="form-cerveja">
        <?= $csrf ?>
        <div class="duas">
          <label>Usuário (para entrar) <input name="login" required autocapitalize="none" placeholder="ex.: joao" maxlength="30"></label>
          <label>Nome <input name="nome" placeholder="ex.: João (financeiro)" maxlength="60"></label>
        </div>
        <label>Função
          <select name="papel" required>
            <option value="">—</option>
            <?php foreach (DH_PAPEIS as $k => $rot): ?><option value="<?= $k ?>"><?= e($rot) ?> · <?= e($acessos[$k]) ?></option><?php endforeach; ?>
          </select>
        </label>
        <label>Unidade padrão nos shows novos
          <select name="unidade"><option value="">—</option><?php foreach (DH_UNIDADES as $k => $rot): ?><option value="<?= $k ?>"><?= e($rot) ?></option><?php endforeach; ?></select>
        </label>
        <label>Senha <small>(mínimo 8 caracteres; a pessoa pode trocar depois)</small><input type="password" name="senha" required autocomplete="new-password"></label>
        <button class="btn primario">Criar usuário</button>
      </form>
      <dl class="acessos">
        <?php foreach ($acessos as $k => $txt): ?><dt><?= e(DH_PAPEIS[$k]) ?></dt><dd><?= e($txt) ?></dd><?php endforeach; ?>
      </dl>
    <?php
    return true;
}

// Escolhe o usuário e marca as abas que ele vê
function usuarios_tela_permissoes(): void
{
    global $usuarios, $csrf;
    $lista = array_filter($usuarios, fn($u) => $u['papel'] !== 'admin');
    $login = (string) ($_GET['login'] ?? $_POST['login'] ?? array_key_first($lista) ?? '');
    $u = $lista[$login] ?? null;
    $proprias = $u && isset($u['permissoes']);
    $marcadas = $u ? ($_SERVER['REQUEST_METHOD'] === 'POST' ? (array) ($_POST['abas'] ?? []) : ($u['permissoes'] ?? abas_da_funcao($u['papel']))) : [];
    ?>
      <p><a class="voltar" href="./?acao=usuarios">‹ Voltar para Usuários</a></p>
      <h1>🔐 Permissões</h1>
      <p class="dica">Escolha o usuário e marque as abas que aparecem para ele. Administradores veem tudo. Um usuário novo começa com as abas da função dele.</p>
      <?php if (!$lista): ?><p class="aviso">Nenhum usuário além dos administradores. <a href="./?acao=usuarios">Crie um usuário</a> primeiro.</p><?php return; endif; ?>
      <form method="get" class="form-cerveja escolher-usuario">
        <input type="hidden" name="acao" value="permissoes">
        <label>Usuário
          <select name="login" onchange="this.form.submit()">
            <?php foreach ($lista as $x): ?><option value="<?= e($x['login']) ?>" <?= $x['login'] === $login ? 'selected' : '' ?>><?= e($x['nome']) ?> (<?= e($x['login']) ?>) · <?= e(DH_PAPEIS[$x['papel']]) ?><?= isset($x['permissoes']) ? ' · personalizado' : '' ?></option><?php endforeach; ?>
          </select>
        </label>
        <noscript><button class="btn secundario">Abrir</button></noscript>
      </form>
      <?php if ($u): ?>
        <p class="dica"><?= $proprias ? 'Abas escolhidas para ' . e($u['nome']) . '.' : 'Usando as abas padrão da função ' . e(DH_PAPEIS[$u['papel']]) . '.' ?></p>
        <form method="post" action="./?acao=permissoes-salvar" class="form-cerveja">
          <?= $csrf ?><input type="hidden" name="login" value="<?= e($u['login']) ?>">
          <div class="grade-abas">
            <?php foreach (DH_ABAS as $k => [$rot, $desc]): ?>
              <label class="check aba-check"><input type="checkbox" name="abas[]" value="<?= $k ?>" <?= in_array($k, $marcadas, true) ? 'checked' : '' ?>> <span><b><?= e($rot) ?></b><?= $desc !== '' ? '<small>' . e($desc) . '</small>' : '' ?></span></label>
            <?php endforeach; ?>
          </div>
          <div class="barra-salvar"><button class="btn primario">Salvar permissões de <?= e($u['nome']) ?></button></div>
        </form>
        <?php if ($proprias): ?>
          <form method="post" action="./?acao=permissoes-salvar" onsubmit="return confirm('Voltar <?= e(addslashes($u['nome'])) ?> às abas padrão da função <?= e(DH_PAPEIS[$u['papel']]) ?>?');"><?= $csrf ?><input type="hidden" name="login" value="<?= e($u['login']) ?>"><input type="hidden" name="padrao" value="1"><button class="link">Voltar às abas padrão da função <?= e(DH_PAPEIS[$u['papel']]) ?></button></form>
        <?php endif; ?>
      <?php endif; ?>
    <?php
}
