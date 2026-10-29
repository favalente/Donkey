<?php
// Confere se um contrato é autêntico: pelo número (DH-2026-001) ou pelo código de integridade (SHA-256)
require __DIR__ . '/../inc/dados.php';

header('X-Robots-Tag: noindex, nofollow');
$q = strtolower(trim((string) ($_GET['q'] ?? '')));
$achado = null;
if ($q !== '') {
    foreach (dh_contratos()['contratos'] as $k) {
        if ($k['status'] !== 'rascunho' && ($q === strtolower($k['numero']) || (strlen($q) === 64 && hash_equals($k['hash'], $q)))) {
            $achado = $k;
        }
    }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Verificar contrato · Donkey Head Cervejaria</title>
  <link rel="icon" href="../favicon.ico">
  <link rel="stylesheet" href="contrato.css?v=7">
</head>
<body>
  <div class="barra"><img src="../assets/img/logo-donkey-head.png" alt="" width="34" height="48"><strong>Donkey Head Cervejaria</strong></div>
  <main>
    <article class="papel">
      <h1>Verificar contrato</h1>
      <p>Informe o número do contrato (ex.: DH-2026-001) ou o código de integridade que aparece no rodapé do documento.</p>
      <form method="get" class="verificar">
        <input name="q" value="<?= e($_GET['q'] ?? '') ?>" required placeholder="DH-2026-001 ou código de integridade">
        <button class="btn">Verificar</button>
      </form>
      <?php if ($q !== '' && !$achado): ?>
        <p class="msg erro">Nenhum contrato encontrado com esse número ou código. Se o código do seu documento não aparece aqui, o documento pode ter sido alterado.</p>
      <?php elseif ($achado): ?>
        <p class="msg ok">✓ Contrato encontrado nos registros da Donkey Head Cervejaria.</p>
        <table class="quadro">
          <tr><th>Contrato</th><td>nº <?= e($achado['numero']) ?> · <?= e(DH_CONTRATO_TIPOS[$achado['tipo']]) ?></td></tr>
          <tr><th>Situação</th><td><?= e(DH_CONTRATO_STATUS[$achado['status']]) ?></td></tr>
          <tr><th>Gerado em</th><td><?= e(date('d/m/Y H:i:s', strtotime($achado['enviado_em']))) ?></td></tr>
          <tr><th>Código de integridade</th><td class="hash"><?= e($achado['hash']) ?></td></tr>
          <?php foreach (['contratada' => 'Contratada', 'contratante' => 'Contratante'] as $parte => $rot): $a = $achado['assinaturas'][$parte] ?? null; ?>
            <tr><th><?= $rot ?></th><td><?= $a ? e($a['nome']) . ' · CPF ' . e(dh_cpf_mascara($a['cpf'])) . ' · assinado em ' . e(date('d/m/Y H:i', strtotime($a['em']))) : 'Ainda não assinou' ?></td></tr>
          <?php endforeach; ?>
        </table>
        <p class="legal">Compare o código de integridade acima com o que aparece no rodapé do seu documento: se forem iguais, o texto é exatamente o que foi assinado.</p>
      <?php endif; ?>
    </article>
  </main>
</body>
</html>
