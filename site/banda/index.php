<?php
// Página da banda: pelo link secreto (enviado pelo painel), a própria banda atualiza
// as fotos e o Instagram. Não mostra nem altera mais nada do cadastro.
require __DIR__ . '/../inc/dados.php';

header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
session_start();
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
const DH_MAX_FOTOS_BANDA = 30;

$token = (string) ($_GET['t'] ?? '');
$a = dh_agenda();
$i = dh_banda_por_token($a, $token);
$erro = '';
$aviso = $_SESSION['banda_aviso'] ?? '';
unset($_SESSION['banda_aviso']);

if ($i !== null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('A página ficou aberta muito tempo. Tente de novo.');
        }
        $b = &$a['bandas'][$i];
        $op = (string) ($_POST['op'] ?? 'salvar');
        if ($op === 'excluir') {
            $k = array_search((string) ($_POST['foto'] ?? ''), array_column($b['fotos'], 'id'), true);
            if ($k !== false) {
                dh_banda_foto_apagar(array_splice($b['fotos'], $k, 1)[0]);
            }
            $msg = 'Foto excluída.';
        } elseif ($op === 'principal') {
            $k = array_search((string) ($_POST['foto'] ?? ''), array_column($b['fotos'], 'id'), true);
            if ($k !== false) {
                array_unshift($b['fotos'], array_splice($b['fotos'], $k, 1)[0]);
            }
            $msg = 'Foto principal escolhida.';
        } else {
            $insta = trim((string) ($_POST['instagram'] ?? ''));
            if ($insta !== '' && dh_instagram($insta) === '') {
                throw new RuntimeException('Instagram inválido. Escreva só o nome do perfil, ex.: @suabanda');
            }
            $b['instagram'] = dh_instagram($insta);
            // Cada foto é salva por si: uma com problema não derruba as outras
            $enviadas = 0;
            $problemas = [];
            $campo = $_FILES['fotos'] ?? [];
            foreach ((array) ($campo['name'] ?? []) as $n => $nomeArq) {
                if ($nomeArq === '' || $nomeArq === null) {
                    continue;
                }
                if (count($b['fotos']) >= DH_MAX_FOTOS_BANDA) {
                    $problemas[] = 'limite de ' . DH_MAX_FOTOS_BANDA . ' fotos atingido (exclua alguma para enviar mais)';
                    break;
                }
                try {
                    $b['fotos'][] = dh_banda_foto_salvar(['name' => $nomeArq, 'tmp_name' => $campo['tmp_name'][$n], 'error' => $campo['error'][$n]], $b['id']);
                    $enviadas++;
                } catch (RuntimeException $e) {
                    $problemas[] = $e->getMessage();
                }
            }
            $msg = $enviadas ? "Pronto! $enviadas " . ($enviadas > 1 ? 'fotos enviadas' : 'foto enviada') . '.' : 'Dados salvos.';
            if ($problemas) {
                $msg .= ' Atenção: ' . implode(' ', array_unique($problemas));
            }
        }
        $b['atualizado_pela_banda'] = date('Y-m-d H:i');
        unset($b);
        dh_agenda_salvar($a);
        $_SESSION['banda_aviso'] = $msg;
        header('Location: ./?t=' . $token, true, 303);
        exit;
    } catch (RuntimeException $e) {
        unset($b);
        $erro = $e->getMessage();
        $a = dh_agenda();
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $erro = 'As fotos passaram do limite de envio. Mande menos fotos de cada vez.';
}
if ($i === null) {
    http_response_code(404);
}
$b = $i !== null ? $a['bandas'][$i] : null;
$csrf = '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">';
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <meta name="referrer" content="no-referrer">
  <title><?= $b ? e($b['nome']) . ' · ' : '' ?>Donkey Head Cervejaria</title>
  <meta name="theme-color" content="#16110d">
  <link rel="icon" href="../favicon.ico">
  <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/style.css?v=10">
  <style>
    .pg-banda { max-width: 640px; }
    .pg-banda label { display: block; font-weight: 600; margin-bottom: 18px; }
    .pg-banda input[type=text], .pg-banda input[type=file] { display: block; width: 100%; margin-top: 6px; padding: 14px; border-radius: 12px; border: 1px solid var(--line); background: #0f0b08; color: var(--text); font: inherit; }
    .pg-banda small { color: var(--muted); font-weight: 400; }
    .pg-banda .msg { padding: 14px 16px; border-radius: 12px; margin-bottom: 20px; font-weight: 600; }
    .pg-banda .msg.ok { background: rgba(76, 175, 80, .15); color: #8fd694; }
    .pg-banda .msg.erro { background: rgba(229, 83, 61, .15); color: #f19a8b; }
    .pg-banda .btn { width: 100%; justify-content: center; }
    .fotos-banda { list-style: none; margin: 0 0 24px; padding: 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 12px; }
    .fotos-banda li { position: relative; border-radius: 12px; overflow: hidden; border: 1px solid var(--line); background: var(--surface); }
    .fotos-banda img { display: block; width: 100%; aspect-ratio: 1; object-fit: cover; }
    .fotos-banda .tag { position: absolute; top: 8px; left: 8px; background: var(--amber); color: #1a120a; font-size: .7rem; font-weight: 700; padding: 2px 8px; border-radius: 999px; text-transform: uppercase; }
    .fotos-banda form { display: flex; gap: 6px; padding: 8px; }
    .fotos-banda button { flex: 1; padding: 8px 4px; border-radius: 8px; border: 1px solid var(--line); background: none; color: var(--text); font: inherit; font-size: .8rem; cursor: pointer; }
    .fotos-banda button.perigo { color: #f19a8b; }
    .enviando { display: none; color: var(--amber); font-weight: 600; text-align: center; margin-top: 12px; }
  </style>
</head>
<body>
  <header class="site-header">
    <div class="container nav">
      <span class="brand">
        <img src="../assets/img/logo-donkey-head.png" alt="" width="34" height="48">
        <span>Donkey Head Cervejaria</span>
      </span>
    </div>
  </header>
  <main class="section">
    <div class="container pg-banda">
      <?php if (!$b): ?>
        <p class="eyebrow">Link da banda</p>
        <h1 class="titulo-pagina">Link inválido</h1>
        <p>Esse link não existe ou foi trocado. Peça um link novo para a equipe da Donkey Head.</p>
      <?php else: ?>
        <p class="eyebrow">Área da banda</p>
        <h1 class="titulo-pagina"><?= e($b['nome']) ?></h1>
        <p>Envie as fotos da banda e confira o Instagram. É o que aparece na agenda de shows da Donkey Head. Mande fotos em boa qualidade: a original é guardada para o nosso designer.</p>
        <?php if ($aviso): ?><p class="msg ok"><?= e($aviso) ?></p><?php endif; ?>
        <?php if ($erro): ?><p class="msg erro"><?= e($erro) ?></p><?php endif; ?>

        <form method="post" enctype="multipart/form-data" id="form-banda">
          <?= $csrf ?>
          <label>Instagram da banda
            <input type="text" name="instagram" value="<?= $b['instagram'] !== '' ? '@' . e($b['instagram']) : '' ?>" placeholder="@suabanda" autocapitalize="none" autocomplete="off" maxlength="80">
          </label>
          <label>Adicionar fotos <small>(pode escolher várias; JPG, PNG ou WEBP, até <?= e(ini_get('upload_max_filesize')) ?> cada)</small>
            <input type="file" name="fotos[]" accept="image/jpeg,image/png,image/webp" multiple>
          </label>
          <button class="btn btn-primary">Salvar</button>
          <p class="enviando" id="enviando">Enviando… não feche a página.</p>
        </form>

        <?php if ($b['fotos']): ?>
          <h2 style="font-size:2rem;margin:40px 0 12px">Fotos enviadas <small>(<?= count($b['fotos']) ?>)</small></h2>
          <p><small>A foto principal é a que aparece no site.</small></p>
          <ul class="fotos-banda">
            <?php foreach ($b['fotos'] as $n => $f): ?>
              <li>
                <img src="../<?= e($f['mini']) ?>" alt="" loading="lazy">
                <?php if ($n === 0): ?><span class="tag">Principal</span><?php endif; ?>
                <form method="post">
                  <?= $csrf ?><input type="hidden" name="foto" value="<?= e($f['id']) ?>">
                  <?php if ($n > 0): ?><button name="op" value="principal">★ Principal</button><?php endif; ?>
                  <button name="op" value="excluir" class="perigo" onclick="return confirm('Excluir esta foto?')">Excluir</button>
                </form>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
        <script>
          document.getElementById('form-banda').addEventListener('submit', function () {
            document.getElementById('enviando').style.display = 'block';
          });
        </script>
      <?php endif; ?>
    </div>
  </main>
</body>
</html>
