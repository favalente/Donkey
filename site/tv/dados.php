<?php
// Tap list de uma unidade em JSON, consultada pela tela da TV a cada minuto.
// Ninguém espera esta resposta: é aqui que rodam os lembretes de evento (inc/lembretes.php).
const DH_PODE_DEMORAR = true;
require __DIR__ . '/../inc/dados.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

echo json_encode(dh_tap_tv($_GET['unidade'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
