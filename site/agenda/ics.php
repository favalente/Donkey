<?php
// Agenda de shows no formato iCalendar, para assinar no Google Agenda.
// Pública: agenda/ics.php · Equipe (com cachê e situação): agenda/ics.php?equipe=1&t=TOKEN
require __DIR__ . '/../inc/dados.php';

$a = dh_agenda();
$equipe = !empty($_GET['equipe']);
if ($equipe && ($a['config']['token_equipe'] === '' || !hash_equals($a['config']['token_equipe'], (string) ($_GET['t'] ?? '')))) {
    http_response_code(403);
    exit('Link inválido.');
}
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="' . ($equipe ? 'donkey-head-equipe' : 'donkey-head-shows') . '.ics"');
header('Cache-Control: no-store');
if ($equipe) {
    header('X-Robots-Tag: noindex');
}
echo dh_agenda_ics($a, $equipe);
