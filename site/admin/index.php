<?php
// Painel da Donkey Head: tap list, cervejas, galeria, agenda de shows e usuários.
require __DIR__ . '/../inc/dados.php';
require __DIR__ . '/modulo-agenda.php';
require __DIR__ . '/modulo-usuarios.php';

const DH_ARQ_ADMIN = DH_RAIZ . '/dados/admin.json';
const DH_ARQ_USUARIOS = DH_RAIZ . '/dados/usuarios.json';
const DH_PAPEIS = ['admin' => 'Administrador', 'financeiro' => 'Financeiro', 'designer' => 'Designer', 'barman' => 'Barman'];
// O que cada função pode fazer no painel
const DH_PERMISSOES = [
    'taplist' => ['admin', 'barman'],
    'cervejas' => ['admin', 'barman'],
    'galeria' => ['admin'],
    'agenda' => ['admin', 'financeiro', 'designer'],
    'bandas' => ['admin', 'financeiro', 'designer'],
    // Designer só vê: editar banda, fotos e link é para admin e financeiro
    'bandas_editar' => ['admin', 'financeiro'],
    'agenda_editar' => ['admin', 'financeiro'],
    'valores' => ['admin', 'financeiro'],
    'relatorio' => ['admin', 'financeiro'],
    'usuarios' => ['admin'],
    'importar' => ['admin'],
];
const DH_ARQ_FALHAS = DH_RAIZ . '/dados/login-falhas.json';
const DH_DIR_UPLOAD = DH_RAIZ . '/uploads/cervejas';
const DH_DIR_GALERIA = DH_RAIZ . '/uploads/galeria';
const DH_MAX_FALHAS = 5;
const DH_BLOQUEIO_MIN = 15;
// "Manter conectado": aparelhos lembrados ficam em dados/lembrar.json (só o hash do código)
const DH_ARQ_LEMBRAR = DH_RAIZ . '/dados/lembrar.json';
const DH_LEMBRAR_DIAS = 180;

header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

session_name('dh_admin');
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Strict',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'path' => dirname($_SERVER['SCRIPT_NAME']) . '/',
]);
session_start();

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

// Usuários do painel. Na primeira vez, a senha única antiga vira o usuário "admin".
function dh_usuarios(): array
{
    $u = json_decode((string) @file_get_contents(DH_ARQ_USUARIOS), true);
    if (is_array($u)) {
        return $u;
    }
    $antigo = json_decode((string) @file_get_contents(DH_ARQ_ADMIN), true);
    if (!empty($antigo['hash'])) {
        $u = ['admin' => ['login' => 'admin', 'nome' => 'Administrador', 'papel' => 'admin', 'hash' => $antigo['hash']]];
        gravar_json(DH_ARQ_USUARIOS, $u);
        return $u;
    }
    return [];
}

function dh_usuarios_salvar(array $u): void
{
    ksort($u);
    gravar_json(DH_ARQ_USUARIOS, $u);
}

function pode(string $permissao): bool
{
    global $usuario;
    return $usuario && in_array($usuario['papel'], DH_PERMISSOES[$permissao] ?? [], true);
}

function exigir(string $permissao): void
{
    if (!pode($permissao)) {
        throw new RuntimeException('Seu usuário não tem acesso a essa parte do painel.');
    }
}

function login_valido(string $login): bool
{
    return (bool) preg_match('/^[a-z0-9._-]{3,30}$/', $login);
}

function gravar_json(string $arq, array $dados): void
{
    if (!is_dir(dirname($arq))) {
        mkdir(dirname($arq), 0755, true);
    }
    file_put_contents($arq, json_encode($dados), LOCK_EX);
}

function ir(string $destino, ?string $msg = null): void
{
    if ($msg) {
        $_SESSION['flash'] = $msg;
    }
    // Caminho absoluto do painel, para o redirecionamento funcionar em qualquer servidor
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/';
    header('Location: ' . $base . ltrim($destino, './'));
    exit;
}

function exigir_post(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Sessão expirada. Volte e tente novamente.');
    }
}

function ip_cliente(): string
{
    return hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '');
}

function bloqueado(): bool
{
    $f = json_decode((string) @file_get_contents(DH_ARQ_FALHAS), true) ?: [];
    $r = $f[ip_cliente()] ?? null;
    return $r && $r['n'] >= DH_MAX_FALHAS && time() - $r['t'] < DH_BLOQUEIO_MIN * 60;
}

function registrar_falha(bool $limpar = false): void
{
    $f = json_decode((string) @file_get_contents(DH_ARQ_FALHAS), true) ?: [];
    foreach ($f as $k => $r) {
        if (time() - $r['t'] > DH_BLOQUEIO_MIN * 60) {
            unset($f[$k]);
        }
    }
    if ($limpar) {
        unset($f[ip_cliente()]);
    } else {
        $r = $f[ip_cliente()] ?? ['n' => 0];
        $f[ip_cliente()] = ['n' => $r['n'] + 1, 't' => time()];
    }
    gravar_json(DH_ARQ_FALHAS, $f);
}

// Valida a foto enviada e devolve a imagem GD já na orientação certa
function abrir_foto(array $arq)
{
    if ($arq['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Falha no envio da foto (código ' . $arq['error'] . '). Tente uma imagem menor.');
    }
    $info = @getimagesize($arq['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
        throw new RuntimeException('A foto precisa ser JPG, PNG ou WEBP.');
    }
    if (!function_exists('imagecreatefromstring')) {
        throw new RuntimeException('O servidor não tem a extensão GD do PHP ativa. Ative-a no painel da Locaweb.');
    }
    $img = @imagecreatefromstring((string) file_get_contents($arq['tmp_name']));
    if (!$img) {
        throw new RuntimeException('Não foi possível ler a foto.');
    }
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $ori = (@exif_read_data($arq['tmp_name'])['Orientation'] ?? 1);
        $giro = [3 => 180, 6 => -90, 8 => 90][$ori] ?? 0;
        if ($giro) {
            $img = imagerotate($img, $giro, 0);
        }
    }
    return $img;
}

function salvar_imagem(array $arq, string $id): string
{
    $nome = $id . '-' . date('YmdHis') . '.jpg';
    dh_gravar_jpeg(abrir_foto($arq), DH_DIR_UPLOAD . '/' . $nome, 900);
    return 'uploads/cervejas/' . $nome;
}

// Arquivos de upload separados por envio (um campo "fotos[]" com várias imagens)
function lista_uploads(array $campo): array
{
    $lista = [];
    foreach ((array) ($campo['name'] ?? []) as $i => $nome) {
        if ($nome !== '' && $nome !== null) {
            $lista[] = ['name' => $nome, 'tmp_name' => $campo['tmp_name'][$i], 'error' => $campo['error'][$i]];
        }
    }
    return $lista;
}

function apagar_upload(string $caminho): void
{
    if (strpos($caminho, 'uploads/cervejas/') === 0 || strpos($caminho, 'uploads/galeria/') === 0) {
        @unlink(DH_RAIZ . '/' . $caminho);
    }
}

function lembrar_cookie(string $valor, int $expira): void
{
    setcookie('dh_lembrar', $valor, [
        'expires' => $expira,
        'path' => dirname($_SERVER['SCRIPT_NAME']) . '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
}

// Trocar a senha desconecta os aparelhos lembrados
function lembrar_marca(array $u): string
{
    return hash('sha256', $u['hash']);
}

function lembrar_criar(array $u): void
{
    $lista = json_decode((string) @file_get_contents(DH_ARQ_LEMBRAR), true) ?: [];
    $lista = array_filter($lista, fn($x) => ($x['expira'] ?? 0) > time());
    $sel = bin2hex(random_bytes(9));
    $val = bin2hex(random_bytes(24));
    $expira = time() + DH_LEMBRAR_DIAS * 86400;
    $lista[$sel] = ['login' => $u['login'], 'valor' => hash('sha256', $val), 'senha' => lembrar_marca($u), 'expira' => $expira];
    gravar_json(DH_ARQ_LEMBRAR, $lista);
    lembrar_cookie($sel . '.' . $val, $expira);
}

// Login de quem marcou "manter conectado", ou null
function lembrar_login(array $usuarios): ?string
{
    if (!preg_match('/^([a-f0-9]{18})\.([a-f0-9]{48})$/', (string) ($_COOKIE['dh_lembrar'] ?? ''), $m)) {
        return null;
    }
    $lista = json_decode((string) @file_get_contents(DH_ARQ_LEMBRAR), true) ?: [];
    $x = $lista[$m[1]] ?? null;
    if (!$x || $x['expira'] < time() || !hash_equals($x['valor'], hash('sha256', $m[2]))
        || !isset($usuarios[$x['login']]) || !hash_equals($x['senha'], lembrar_marca($usuarios[$x['login']]))) {
        lembrar_cookie('', time() - 3600);
        return null;
    }
    return $x['login'];
}

function lembrar_apagar(): void
{
    if (preg_match('/^([a-f0-9]{18})\./', (string) ($_COOKIE['dh_lembrar'] ?? ''), $m)) {
        $lista = json_decode((string) @file_get_contents(DH_ARQ_LEMBRAR), true) ?: [];
        unset($lista[$m[1]]);
        gravar_json(DH_ARQ_LEMBRAR, $lista);
    }
    lembrar_cookie('', time() - 3600);
}

$usuarios = dh_usuarios();
if (empty($_SESSION['login']) && $usuarios && ($lembrado = lembrar_login($usuarios))) {
    session_regenerate_id(true);
    $_SESSION['login'] = $lembrado;
}
$usuario = isset($_SESSION['login'], $usuarios[$_SESSION['login']]) ? $usuarios[$_SESSION['login']] : null;
$erro = null;
// Tela inicial de cada função
$inicio = $usuario ? (pode('taplist') ? 'taplist' : (pode('agenda') ? 'agenda' : 'galeria')) : 'login';
$acao = $_GET['acao'] ?? $inicio;

// Primeiro acesso: cria o usuário administrador
if (!$usuarios) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        exigir_post();
        $login = strtolower(trim((string) ($_POST['login'] ?? '')));
        $s = (string) ($_POST['senha'] ?? '');
        if (!login_valido($login)) {
            $erro = 'Usuário: use de 3 a 30 letras minúsculas, números, ponto ou traço.';
        } elseif (strlen($s) < 8) {
            $erro = 'A senha precisa ter pelo menos 8 caracteres.';
        } elseif ($s !== ($_POST['senha2'] ?? '')) {
            $erro = 'As senhas não conferem.';
        } else {
            $novo = ['login' => $login, 'nome' => 'Administrador', 'papel' => 'admin', 'hash' => password_hash($s, PASSWORD_DEFAULT)];
            dh_usuarios_salvar([$login => $novo]);
            session_regenerate_id(true);
            $_SESSION['login'] = $login;
            lembrar_criar($novo);
            ir('./', 'Usuário criado! Bem-vindo ao painel.');
        }
    }
    $tela = 'primeiro-acesso';
} elseif (!$usuario) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        exigir_post();
        $login = strtolower(trim((string) ($_POST['login'] ?? '')));
        if (bloqueado()) {
            $erro = 'Muitas tentativas erradas. Aguarde ' . DH_BLOQUEIO_MIN . ' minutos.';
        } elseif (isset($usuarios[$login]) && password_verify((string) ($_POST['senha'] ?? ''), $usuarios[$login]['hash'])) {
            registrar_falha(true);
            session_regenerate_id(true);
            $_SESSION['login'] = $login;
            if (!empty($_POST['lembrar'])) {
                lembrar_criar($usuarios[$login]);
            }
            ir('./');
        } else {
            registrar_falha();
            sleep(1);
            $erro = 'Usuário ou senha incorretos.';
        }
    }
    $tela = 'login';
} else {
    $tela = $acao;
    $dados = dh_carregar();
    $porId = dh_por_id($dados);

    $telaErro = null;
    try {
        switch ($acao) {
            case 'sair':
                exigir_post();
                lembrar_apagar();
                session_destroy();
                ir('./');

            case 'salvar-taplist':
                exigir_post();
                exigir('taplist');
                $u = (string) ($_POST['unidade'] ?? '');
                if (!isset(DH_UNIDADES[$u])) {
                    ir('./');
                }
                $lista = array_values(array_filter((array) ($_POST['torneira'] ?? []), fn($id) => isset($porId[$id])));
                $dados['torneiras'][$u] = $lista;
                dh_salvar($dados);
                ir('./?unidade=' . $u, 'Tap list da ' . DH_UNIDADES[$u] . ' atualizada no site!');

            case 'salvar-cerveja':
                exigir_post();
                exigir('cervejas');
                $id = (string) ($_POST['id'] ?? '');
                $nova = !isset($porId[$id]);
                $nome = trim((string) ($_POST['nome'] ?? ''));
                if ($nome === '') {
                    throw new RuntimeException('Informe o nome da cerveja.');
                }
                if ($nova) {
                    $base = dh_slug($nome);
                    $id = $base;
                    for ($i = 2; isset($porId[$id]); $i++) {
                        $id = $base . '-' . $i;
                    }
                }
                $num = fn($k) => str_replace(',', '.', trim((string) ($_POST[$k] ?? '')));
                $c = $porId[$id] ?? ['id' => $id, 'imagem' => ''];
                unset($c['srm'], $c['ebc']);
                $c = array_merge($c, [
                    'nome' => $nome,
                    'estilo' => trim((string) ($_POST['estilo'] ?? '')),
                    'pais' => in_array($_POST['pais'] ?? '', DH_PAISES, true) ? $_POST['pais'] : '',
                    'categoria' => isset(DH_CATEGORIAS[(int) ($_POST['categoria'] ?? 0)]) ? (int) $_POST['categoria'] : 0,
                    'abv' => $num('abv'),
                    'ibu' => $num('ibu'),
                    'nome_xmenu' => trim((string) ($_POST['nome_xmenu'] ?? '')),
                    'preco_300' => $num('preco_300'),
                    'preco_1l' => $num('preco_1l'),
                    'descricao' => trim(str_replace("\r\n", "\n", (string) ($_POST['descricao'] ?? ''))),
                    'ativa' => !empty($_POST['ativa']),
                ]);
                if (!empty($_FILES['imagem']['name'])) {
                    $antiga = $c['imagem'];
                    $c['imagem'] = salvar_imagem($_FILES['imagem'], $id);
                    apagar_upload($antiga);
                }
                if ($c['imagem'] === '') {
                    $c['imagem'] = 'assets/img/rotulo-padrao.png';
                }
                if ($nova) {
                    $dados['cervejas'][] = $c;
                } else {
                    foreach ($dados['cervejas'] as $i => $x) {
                        if ($x['id'] === $id) {
                            $dados['cervejas'][$i] = $c;
                        }
                    }
                }
                usort($dados['cervejas'], fn($a, $b) => strcasecmp(dh_slug($a['nome']), dh_slug($b['nome'])));
                dh_salvar($dados);
                ir('./?acao=cervejas', '"' . $nome . '" salva!');

            case 'excluir-cerveja':
                exigir_post();
                exigir('cervejas');
                $id = (string) ($_POST['id'] ?? '');
                if (isset($porId[$id])) {
                    $dados['cervejas'] = array_values(array_filter($dados['cervejas'], fn($c) => $c['id'] !== $id));
                    foreach ($dados['torneiras'] as $u => $lista) {
                        $dados['torneiras'][$u] = array_values(array_filter($lista, fn($x) => $x !== $id));
                    }
                    apagar_upload($porId[$id]['imagem']);
                    dh_salvar($dados);
                }
                ir('./?acao=cervejas', '"' . ($porId[$id]['nome'] ?? '') . '" excluída.');

            case 'trocar-senha':
                if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                    exigir_post();
                    $s = (string) ($_POST['senha'] ?? '');
                    if (!password_verify((string) ($_POST['atual'] ?? ''), $usuario['hash'])) {
                        $erro = 'Senha atual incorreta.';
                    } elseif (strlen($s) < 8) {
                        $erro = 'A nova senha precisa ter pelo menos 8 caracteres.';
                    } elseif ($s !== ($_POST['senha2'] ?? '')) {
                        $erro = 'As senhas não conferem.';
                    } else {
                        $usuarios[$usuario['login']]['hash'] = password_hash($s, PASSWORD_DEFAULT);
                        dh_usuarios_salvar($usuarios);
                        ir('./', 'Senha alterada.');
                    }
                }
                break;

            case 'galeria-enviar':
                exigir_post();
                exigir('galeria');
                $arquivos = lista_uploads($_FILES['fotos'] ?? []);
                if (!$arquivos) {
                    throw new RuntimeException('Escolha pelo menos uma foto.');
                }
                $fotos = dh_galeria();
                $legenda = mb_substr(trim((string) ($_POST['legenda'] ?? '')), 0, 120);
                $novas = [];
                foreach ($arquivos as $arq) {
                    $img = abrir_foto($arq);
                    $id = 'f' . date('YmdHis') . bin2hex(random_bytes(3));
                    dh_gravar_jpeg($img, DH_DIR_GALERIA . '/' . $id . '.jpg', 1400, 80);
                    dh_gravar_jpeg($img, DH_DIR_GALERIA . '/mini/' . $id . '.jpg', 520, 72);
                    $novas[] = ['id' => $id, 'foto' => 'uploads/galeria/' . $id . '.jpg', 'mini' => 'uploads/galeria/mini/' . $id . '.jpg', 'legenda' => $legenda];
                }
                dh_galeria_salvar(array_merge($novas, $fotos));
                ir('./?acao=galeria', count($novas) === 1 ? 'Foto adicionada no início da galeria!' : count($novas) . ' fotos adicionadas no início da galeria!');

            case 'galeria-alterar':
                exigir_post();
                exigir('galeria');
                $fotos = dh_galeria();
                $id = (string) ($_POST['id'] ?? '');
                $i = array_search($id, array_column($fotos, 'id'), true);
                if ($i === false) {
                    ir('./?acao=galeria');
                }
                $op = (string) ($_POST['op'] ?? '');
                $msg = null;
                if ($op === 'legenda') {
                    $fotos[$i]['legenda'] = mb_substr(trim((string) ($_POST['legenda'] ?? '')), 0, 120);
                    $msg = 'Legenda salva.';
                } elseif ($op === 'subir' && $i > 0) {
                    [$fotos[$i - 1], $fotos[$i]] = [$fotos[$i], $fotos[$i - 1]];
                } elseif ($op === 'descer' && $i < count($fotos) - 1) {
                    [$fotos[$i + 1], $fotos[$i]] = [$fotos[$i], $fotos[$i + 1]];
                } elseif ($op === 'primeira') {
                    array_unshift($fotos, array_splice($fotos, $i, 1)[0]);
                } elseif ($op === 'excluir') {
                    $f = array_splice($fotos, $i, 1)[0];
                    apagar_upload($f['foto']);
                    apagar_upload($f['mini']);
                    $msg = 'Foto excluída.';
                }
                dh_galeria_salvar($fotos);
                ir('./?acao=galeria#' . $id, $msg);

            case 'galeria':
                exigir('galeria');
                break;
            case 'editar':
            case 'cervejas':
                exigir('cervejas');
                break;
            case 'taplist':
                exigir('taplist');
                break;

            default:
                // Agenda, bandas, relatório, importação e usuários ficam em arquivos próprios
                if (!agenda_acao($acao) && !usuarios_acao($acao)) {
                    $tela = $inicio;
                }
        }
    } catch (RuntimeException $ex) {
        $erro = $ex->getMessage();
        $tela = $acao === 'salvar-cerveja' ? 'editar' : (strpos($acao, 'galeria') === 0 ? 'galeria' : (pode('cervejas') ? 'cervejas' : $inicio));
        $tela = $telaErro ?? $tela;
    }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$csrf = '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">';
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Painel · Donkey Head Cervejaria</title>
  <link rel="icon" href="../favicon.ico">
  <link rel="stylesheet" href="admin.css?v=17">
  <script src="../assets/js/arrastar-mes.js?v=2"></script>
</head>
<body>
<?php if ($tela === 'primeiro-acesso' || $tela === 'login'): ?>
  <main class="login">
    <img src="../assets/img/logo-donkey-head.png" alt="Donkey Head Cervejaria" width="110">
    <h1>Painel Donkey Head Cervejaria</h1>
    <?php if ($tela === 'primeiro-acesso'): ?>
      <p>Primeiro acesso: crie o usuário administrador do painel. Depois, em "Usuários", você cria os acessos da equipe.</p>
    <?php endif; ?>
    <?php if ($erro): ?><p class="erro"><?= e($erro) ?></p><?php endif; ?>
    <form method="post">
      <?= $csrf ?>
      <label>Usuário <input name="login" required autofocus autocapitalize="none" autocomplete="username" value="<?= e($_POST['login'] ?? '') ?>"></label>
      <label>Senha <input type="password" name="senha" required autocomplete="<?= $tela === 'login' ? 'current-password' : 'new-password' ?>"></label>
      <?php if ($tela === 'primeiro-acesso'): ?>
        <label>Repita a senha <input type="password" name="senha2" required autocomplete="new-password"></label>
      <?php else: ?>
        <label class="check"><input type="checkbox" name="lembrar" value="1" checked> Manter conectado neste aparelho</label>
      <?php endif; ?>
      <button class="btn primario"><?= $tela === 'login' ? 'Entrar' : 'Criar usuário' ?></button>
    </form>
  </main>
<?php else: ?>
  <header class="topo">
    <a class="marca" href="./"><img src="../assets/img/logo-donkey-head.png" alt="" width="28"> Painel</a>
    <nav>
      <?php if (pode('taplist')): ?><a href="./?acao=taplist" class="<?= $tela === 'taplist' ? 'ativo' : '' ?>"><span class="ic" aria-hidden="true">🍺</span>Tap List</a><?php endif; ?>
      <?php if (pode('cervejas')): ?><a href="./?acao=cervejas" class="<?= in_array($tela, ['cervejas', 'editar'], true) ? 'ativo' : '' ?>"><span class="ic" aria-hidden="true">🏷️</span>Cervejas</a><?php endif; ?>
      <?php if (pode('agenda')): ?><a href="./?acao=agenda" class="<?= in_array($tela, ['agenda', 'show'], true) ? 'ativo' : '' ?>"><span class="ic" aria-hidden="true">📅</span>Agenda</a><?php endif; ?>
      <?php if (pode('bandas')): ?><a href="./?acao=bandas" class="<?= in_array($tela, ['bandas', 'banda'], true) ? 'ativo' : '' ?>"><span class="ic" aria-hidden="true">🎸</span>Bandas</a><?php endif; ?>
      <?php if (pode('relatorio')): ?><a href="./?acao=relatorio" class="<?= $tela === 'relatorio' ? 'ativo' : '' ?>"><span class="ic" aria-hidden="true">💰</span>Financeiro</a><?php endif; ?>
      <?php if (pode('galeria')): ?><a href="./?acao=galeria" class="<?= $tela === 'galeria' ? 'ativo' : '' ?>"><span class="ic" aria-hidden="true">🖼️</span>Galeria</a><?php endif; ?>
      <?php if (pode('usuarios')): ?><a href="./?acao=usuarios" class="<?= in_array($tela, ['usuarios', 'importar'], true) ? 'ativo' : '' ?>"><span class="ic" aria-hidden="true">👥</span>Usuários</a><?php endif; ?>
      <a href="../" target="_blank"><span class="ic" aria-hidden="true">🌐</span>Ver site</a>
    </nav>
  </header>
  <main class="conteudo">
    <?php if ($flash): ?><p class="ok" role="status">✓ <?= e($flash) ?></p><?php endif; ?>
    <?php if ($erro): ?><p class="erro" role="alert">⚠ <?= e($erro) ?></p><?php endif; ?>

    <?php if ($tela === 'taplist'):
        $uSel = isset(DH_UNIDADES[$_GET['unidade'] ?? '']) ? $_GET['unidade'] : array_key_first(DH_UNIDADES);
        $opcoes = $dados['cervejas'];
    ?>
      <h1>Tap List</h1>
      <div class="abas">
        <?php foreach (DH_UNIDADES as $u => $nomeU): ?>
          <a href="./?unidade=<?= $u ?>" class="<?= $u === $uSel ? 'ativo' : '' ?>"><?= e($nomeU) ?> <small><?= count(dh_taplist($dados, $u)) ?></small></a>
        <?php endforeach; ?>
      </div>
      <?php if (dh_usa_xmenu($uSel)): $doXmenu = dh_xmenu_taplist($uSel, $dados['cervejas']); ?>
        <p><a class="btn primario largo" href="../tv/imagem.php?unidade=<?= e($uSel) ?>" target="_blank">Gerar imagem para a TV</a></p>
        <p class="aviso">A tap list da <?= e(DH_UNIDADES[$uSel]) ?> vem <strong>automaticamente do xMenu</strong> (categoria "TAP LIST"). Para trocar uma cerveja, mude no xMenu: o site e a TV atualizam em até 1 minuto. Preços também vêm do xMenu.</p>
        <?php if ($doXmenu === null): ?>
          <p class="erro">Não foi possível ler o xMenu agora. Tente de novo em alguns minutos.</p>
        <?php else: ?>
          <ul class="lista">
            <?php foreach ($doXmenu as $n => $c): ?>
              <li><a href="./?acao=<?= $c['id'] ? 'editar&id=' . e($c['id']) : 'editar&nome=' . e(rawurlencode($c['nome'])) ?>">
                <span class="num-tap"><?= $n + 1 ?></span>
                <img src="../<?= e($c['imagem']) ?>" alt="" width="56" height="56" loading="lazy">
                <span class="info">
                  <strong><?= e($c['nome']) ?></strong>
                  <span><?= e(implode(' · ', array_map(fn($p) => $p['tam'] . ' ' . dh_preco($p['valor']), $c['precos']))) ?></span>
                  <?php if (!$c['id']): ?><span class="tags"><em class="tag alerta">Sem cadastro: toque para adicionar foto e descrição</em></span><?php endif; ?>
                </span>
                <span class="seta">›</span>
              </a></li>
            <?php endforeach; ?>
          </ul>
          <p class="dica">Se uma cerveja aparecer "sem cadastro" mas já existir em Cervejas com outro nome, preencha o campo <strong>Nome no xMenu</strong> dela.</p>
        <?php endif; ?>
      <?php else: ?>
      <p class="dica">Escolha a cerveja de cada torneira e toque em <strong>Salvar</strong>. Para desengatar, escolha "— vazia —". Só aparecem aqui as cervejas cadastradas em <a href="./?acao=cervejas">Cervejas</a>.</p>
      <form method="post"" action="./?acao=salvar-taplist" class="taplist">
        <?= $csrf ?>
        <input type="hidden" name="unidade" value="<?= e($uSel) ?>">
        <?php $atuais = $dados['torneiras'][$uSel]; $linhas = max(count($atuais) + 3, 8);
        for ($i = 0; $i < $linhas; $i++): $sel = $atuais[$i] ?? ''; ?>
          <label class="linha<?= $sel ? ' cheia' : '' ?>">
            <span class="num"><?= $i + 1 ?></span>
            <select name="torneira[]">
              <option value="">— vazia —</option>
              <?php foreach ($opcoes as $c): ?>
                <option value="<?= e($c['id']) ?>" <?= $c['id'] === $sel ? 'selected' : '' ?>><?= e($c['nome']) ?> · <?= e($c['estilo']) ?><?= empty($c['ativa']) ? ' (oculta)' : '' ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        <?php endfor; ?>
        <p class="dica">Precisa de mais torneiras? Salve, e novas linhas vazias aparecem.</p>
        <div class="barra-salvar"><button class="btn primario">Salvar tap list da <?= e(DH_UNIDADES[$uSel]) ?></button></div>
      </form>
      <?php endif; ?>

    <?php elseif ($tela === 'cervejas'):
        $onde = [];
        foreach ($dados['torneiras'] as $u => $lista) {
            foreach ($lista as $id) {
                $onde[$id][] = DH_UNIDADES[$u];
            }
        }
    ?>
      <div class="titulo-acao">
        <h1>Cervejas <small><?= count($dados['cervejas']) ?></small></h1>
        <a class="btn primario" href="./?acao=editar">+ Nova cerveja</a>
      </div>
      <input type="search" id="busca" placeholder="Buscar por nome ou estilo…" class="busca">
      <ul class="lista">
        <?php foreach ($dados['cervejas'] as $c): ?>
          <li data-busca="<?= e(mb_strtolower($c['nome'] . ' ' . $c['estilo'])) ?>">
            <a href="./?acao=editar&id=<?= e($c['id']) ?>">
              <img src="../<?= e($c['imagem']) ?>" alt="" width="56" height="56" loading="lazy">
              <span class="info">
                <strong><?= e($c['nome']) ?></strong>
                <span><?= e($c['estilo']) ?><?= $c['abv'] !== '' ? ' · ' . e(dh_num($c['abv'])) . '%' : '' ?></span>
                <span class="tags">
                  <?php if (empty($c['ativa'])): ?><em class="tag cinza">Oculta no site</em><?php endif; ?>
                  <?php if (($c['origem'] ?? '') === 'xmenu'): ?><em class="tag cinza">Cadastrada pelo xMenu: revise</em><?php endif; ?>
                  <?php foreach ($onde[$c['id']] ?? [] as $u): ?><em class="tag">Engatada · <?= e($u) ?></em><?php endforeach; ?>
                </span>
              </span>
              <span class="seta">›</span>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>

    <?php elseif ($tela === 'editar'):
        $id = (string) ($_GET['id'] ?? $_POST['id'] ?? '');
        $c = $porId[$id] ?? ['id' => '', 'nome' => '', 'estilo' => '', 'pais' => '', 'abv' => '', 'ibu' => '', 'preco_300' => '', 'preco_1l' => '', 'descricao' => '', 'imagem' => '', 'ativa' => true];
        if ($erro && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $c = array_merge($c, array_intersect_key($_POST, $c));
            $c['ativa'] = !empty($_POST['ativa']);
        }
    ?>
      <p><a class="voltar" href="./?acao=cervejas">‹ Voltar para Cervejas</a></p>
      <h1><?= $c['id'] ? 'Editar ' . e($c['nome']) : 'Nova cerveja' ?></h1>
      <form method="post" action="./?acao=salvar-cerveja" enctype="multipart/form-data" class="form-cerveja">
        <?= $csrf ?>
        <input type="hidden" name="id" value="<?= e($c['id']) ?>">
        <div class="foto-atual">
          <?php if ($c['imagem']): ?><img src="../<?= e($c['imagem']) ?>" alt="Rótulo atual" id="previa"><?php else: ?><img src="" alt="" id="previa" hidden><?php endif; ?>
          <label>Foto do rótulo <?= $c['imagem'] ? '(envie para trocar)' : '' ?>
            <input type="file" name="imagem" accept="image/jpeg,image/png,image/webp" id="arquivo">
          </label>
          <small>De preferência quadrada. Pode tirar a foto direto do celular.</small>
        </div>
        <label>Nome <input name="nome" value="<?= e($c['nome'] ?: ($_GET['nome'] ?? '')) ?>" required maxlength="80"></label>
        <label>Nome no xMenu <small>(só se for diferente do nome acima, sem o tamanho. Ex.: "Premium Lager")</small>
          <input name="nome_xmenu" value="<?= e($c['nome_xmenu'] ?? '') ?>" maxlength="80"></label>
        <div class="duas">
          <label>Estilo <input name="estilo" value="<?= e($c['estilo']) ?>" placeholder="Ex.: American IPA" maxlength="80"></label>
          <label>Escola / país
            <select name="pais">
              <option value="">—</option>
              <?php foreach (DH_PAISES as $p): ?><option <?= $p === $c['pais'] ? 'selected' : '' ?>><?= e($p) ?></option><?php endforeach; ?>
            </select>
          </label>
        </div>
        <label>Grupo na TV <small>(ordem da tap list, da mais leve para a mais forte)</small>
          <select name="categoria">
            <option value="0">Automático pelo estilo (agora: <?= e(DH_CATEGORIAS[dh_categoria(['estilo' => $c['estilo'], 'nome' => $c['nome']])]) ?>)</option>
            <?php foreach (DH_CATEGORIAS as $k => $rot): ?><option value="<?= $k ?>" <?= (int) ($c['categoria'] ?? 0) === $k ? 'selected' : '' ?>><?= $k ?>. <?= e($rot) ?></option><?php endforeach; ?>
          </select>
        </label>
        <div class="duas">
          <label>ABV (%) <input name="abv" value="<?= e(dh_num($c['abv'])) ?>" inputmode="decimal" maxlength="6"></label>
          <label>IBU <input name="ibu" value="<?= e(dh_num($c['ibu'])) ?>" inputmode="decimal" maxlength="6"></label>
        </div>
        <div class="duas">
          <label>Preço 300 ml (R$) <input name="preco_300" value="<?= e(dh_num($c['preco_300'] ?? '')) ?>" inputmode="decimal" maxlength="8" placeholder="Ex.: 16,00"></label>
          <label>Preço 1 litro (R$) <input name="preco_1l" value="<?= e(dh_num($c['preco_1l'] ?? '')) ?>" inputmode="decimal" maxlength="8" placeholder="Ex.: 42,00"></label>
        </div>
        <label>Descrição <small>(deixe uma linha em branco entre os parágrafos)</small>
          <textarea name="descricao" rows="9"><?= e($c['descricao']) ?></textarea>
        </label>
        <label class="check"><input type="checkbox" name="ativa" value="1" <?= !empty($c['ativa']) ? 'checked' : '' ?>> Mostrar no catálogo "Nossas cervejas" do site</label>
        <div class="barra-salvar"><button class="btn primario">Salvar cerveja</button></div>
      </form>
      <?php if ($c['id']): ?>
        <form method="post" action="./?acao=excluir-cerveja" class="excluir" onsubmit="return confirm('Excluir &quot;<?= e($c['nome']) ?>&quot; de vez? Para só esconder do site, desmarque &quot;Mostrar no catálogo&quot;.');">
          <?= $csrf ?>
          <input type="hidden" name="id" value="<?= e($c['id']) ?>">
          <button class="btn perigo">Excluir cerveja</button>
        </form>
      <?php endif; ?>

    <?php elseif ($tela === 'galeria'): $fotos = dh_galeria(); ?>
      <h1>Galeria <small><?= count($fotos) ?> fotos</small></h1>
      <form method="post" action="./?acao=galeria-enviar" enctype="multipart/form-data" class="enviar-fotos" id="form-fotos">
        <?= $csrf ?>
        <label>Novas fotos <small>(pode escolher várias de uma vez)</small>
          <input type="file" name="fotos[]" accept="image/jpeg,image/png,image/webp" multiple required id="fotos">
        </label>
        <label>Legenda <small>(opcional, ex.: nome do prato)</small>
          <input name="legenda" maxlength="120" placeholder="Ex.: Burger Donkey na brasa">
        </label>
        <button class="btn primario" id="btn-enviar">Adicionar à galeria</button>
        <p class="dica" id="progresso" hidden></p>
      </form>
      <p class="dica">As fotos novas entram no início. As 12 primeiras aparecem logo de cara no site.</p>
      <ul class="grade-fotos">
        <?php foreach ($fotos as $n => $f): ?>
          <li id="<?= e($f['id']) ?>">
            <a href="../<?= e($f['foto']) ?>" target="_blank"><img src="../<?= e($f['mini']) ?>" alt="" loading="lazy"></a>
            <span class="pos"><?= $n + 1 ?></span>
            <form method="post" action="./?acao=galeria-alterar" class="legenda">
              <?= $csrf ?><input type="hidden" name="id" value="<?= e($f['id']) ?>"><input type="hidden" name="op" value="legenda">
              <input name="legenda" value="<?= e($f['legenda']) ?>" maxlength="120" placeholder="Legenda">
              <button class="btn mini" title="Salvar legenda">✓</button>
            </form>
            <form method="post" action="./?acao=galeria-alterar" class="botoes">
              <?= $csrf ?><input type="hidden" name="id" value="<?= e($f['id']) ?>">
              <button class="btn mini" name="op" value="primeira" title="Mover para o início" <?= $n === 0 ? 'disabled' : '' ?>>⇤</button>
              <button class="btn mini" name="op" value="subir" title="Mover para trás" <?= $n === 0 ? 'disabled' : '' ?>>←</button>
              <button class="btn mini" name="op" value="descer" title="Mover para frente" <?= $n === count($fotos) - 1 ? 'disabled' : '' ?>>→</button>
              <button class="btn mini perigo" name="op" value="excluir" title="Excluir foto" aria-label="Excluir foto" onclick="return confirm('Excluir esta foto da galeria?')">✕</button>
            </form>
          </li>
        <?php endforeach; ?>
      </ul>

    <?php elseif ($tela === 'trocar-senha'): ?>
      <h1>Trocar senha</h1>
      <form method="post" action="./?acao=trocar-senha" class="form-cerveja">
        <?= $csrf ?>
        <label>Senha atual <input type="password" name="atual" required autocomplete="current-password"></label>
        <label>Nova senha <input type="password" name="senha" required autocomplete="new-password"></label>
        <label>Repita a nova senha <input type="password" name="senha2" required autocomplete="new-password"></label>
        <button class="btn primario">Trocar senha</button>
      </form>
    <?php else: agenda_tela($tela) || usuarios_tela($tela); endif; ?>

    <footer class="rodape">
      <span><?= e($usuario['nome']) ?> (<?= e(DH_PAPEIS[$usuario['papel']]) ?>) · <a href="./?acao=trocar-senha">Trocar senha</a></span>
      <form method="post" action="./?acao=sair" onsubmit="return confirm('Sair do painel neste aparelho?');"><?= $csrf ?><button class="link">Sair</button></form>
    </footer>
  </main>
  <script>
    var busca = document.getElementById('busca');
    if (busca) busca.addEventListener('input', function () {
      var q = busca.value.toLowerCase().trim();
      document.querySelectorAll('.lista li').forEach(function (li) {
        li.hidden = q && li.getAttribute('data-busca').indexOf(q) === -1;
      });
    });
    var arq = document.getElementById('arquivo');
    if (arq) arq.addEventListener('change', function () {
      var p = document.getElementById('previa');
      if (arq.files[0]) { p.src = URL.createObjectURL(arq.files[0]); p.hidden = false; }
    });
    // Galeria: reduz as fotos no próprio celular antes de enviar (envio rápido e sem limite de tamanho do servidor)
    var formFotos = document.getElementById('form-fotos');
    if (formFotos && window.fetch && window.FormData && document.createElement('canvas').toBlob) {
      formFotos.addEventListener('submit', function (ev) {
        var arquivos = document.getElementById('fotos').files;
        if (!arquivos.length) return;
        ev.preventDefault();
        var btn = document.getElementById('btn-enviar'), prog = document.getElementById('progresso');
        btn.disabled = true; prog.hidden = false;
        var dados = new FormData(formFotos);
        dados.delete('fotos[]');
        var feitos = 0;
        function reduzir(arq) {
          return createImageBitmap(arq, { imageOrientation: 'from-image' }).then(function (bmp) {
            var esc = Math.min(1, 1600 / Math.max(bmp.width, bmp.height));
            var c = document.createElement('canvas');
            c.width = Math.round(bmp.width * esc); c.height = Math.round(bmp.height * esc);
            c.getContext('2d').drawImage(bmp, 0, 0, c.width, c.height);
            return new Promise(function (ok) { c.toBlob(ok, 'image/jpeg', 0.85); });
          }).catch(function () { return arq; });
        }
        Array.prototype.reduce.call(arquivos, function (fila, arq) {
          return fila.then(function () {
            prog.textContent = 'Preparando foto ' + (++feitos) + ' de ' + arquivos.length + '…';
            return reduzir(arq).then(function (b) { dados.append('fotos[]', b, (arq.name || 'foto').replace(/\.\w+$/, '') + '.jpg'); });
          });
        }, Promise.resolve()).then(function () {
          prog.textContent = 'Enviando…';
          return fetch(formFotos.action, { method: 'POST', body: dados, credentials: 'same-origin', redirect: 'manual' });
        }).then(function (r) {
          // Sucesso = redirecionamento (não seguido aqui, para a mensagem aparecer na próxima página)
          if (r.type === 'opaqueredirect' || r.redirected) { location.href = './?acao=galeria'; return; }
          // Erro de validação: mostra a página de resposta (com a mensagem)
          return r.text().then(function (html) { document.open(); document.write(html); document.close(); });
        }).catch(function () {
          prog.textContent = 'Falha no envio. Verifique a internet e tente de novo.';
          btn.disabled = false;
        });
      });
    }
    document.querySelectorAll('.taplist select').forEach(function (s) {
      s.addEventListener('change', function () { s.parentNode.classList.toggle('cheia', !!s.value); });
    });
  </script>
<?php endif; ?>
</body>
</html>
