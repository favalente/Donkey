<?php
// Painel: usuários da equipe e o que cada função pode fazer (só administrador).

function usuarios_acao(string $acao): bool
{
    global $usuarios, $usuario, $telaErro;
    switch ($acao) {
        case 'usuarios':
            exigir('usuarios');
            return true;

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
        <?php if (pode('importar')): ?><a class="btn secundario" href="./?acao=importar">Importar Google Agenda</a><?php endif; ?>
      </div>
      <ul class="lista">
        <?php foreach ($usuarios as $u): ?>
          <li>
            <details class="usuario">
              <summary>
                <span class="info"><strong><?= e($u['nome']) ?></strong><span><?= e($u['login']) ?> · <?= e(DH_PAPEIS[$u['papel']]) ?><?= !empty($u['unidade']) ? ' · ' . e(DH_UNIDADES[$u['unidade']]) : '' ?></span></span>
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
