<?php
// Verificação dos lembretes de evento (inc/lembretes.php) para um agendador externo, se houver
// (ex.: cron da hospedagem ou monitor de site a cada 30 minutos). Só envia o que estiver na hora e
// nunca repete um lembrete, então pode ser chamada à vontade.
const DH_PODE_DEMORAR = true;
require __DIR__ . '/inc/dados.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
echo "ok\n";
