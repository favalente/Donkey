<?php
// Reservas de mesa: importadas do Google Agenda (endereço secreto iCal, guardado só no servidor) e
// mostradas no painel (aba Reservas) e num link para a equipe (/reservas/?t=...), em calendário.
// A importação roda sozinha quando alguém abre a aba ou o link, no máximo a cada 10 minutos.

declare(strict_types=1);

const DH_ARQ_RESERVAS = DH_RAIZ . '/dados/reservas.json';
const DH_RESERVAS_INTERVALO = 600;

function dh_reservas(): array
{
    $r = json_decode((string) @file_get_contents(DH_ARQ_RESERVAS), true);
    $r = (is_array($r) ? $r : []) + ['config' => [], 'reservas' => [], 'atualizado_em' => 0, 'erro' => ''];
    $r['config'] += ['ics_url' => '', 'unidade' => 'aldeota', 'so_reservas' => false, 'token' => ''];
    return $r;
}

function dh_reservas_salvar(array $r): void
{
    if (!is_dir(dirname(DH_ARQ_RESERVAS))) {
        mkdir(dirname(DH_ARQ_RESERVAS), 0755, true);
    }
    $tmp = DH_ARQ_RESERVAS . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) === false || !rename($tmp, DH_ARQ_RESERVAS)) {
        @unlink($tmp);
        throw new RuntimeException('Não foi possível gravar as reservas. Verifique a permissão da pasta "dados".');
    }
}

// Evento do Google Agenda -> reserva: nome, número de pessoas e casa tirados do texto
function dh_reserva_de_evento(array $ev, string $unidadePadrao): array
{
    $tudo = $ev['titulo'] . "\n" . $ev['descricao'] . "\n" . $ev['local'];
    $pessoas = preg_match('/(\d{1,3})\s*(?:pessoas|pax|pessoa|lugares|convidados)\b/iu', $tudo, $m) ? (int) $m[1] : null;
    $unidade = $unidadePadrao;
    foreach (DH_UNIDADES as $u => $nome) {
        if (preg_match('/\b' . preg_quote(dh_sem_acento(mb_strtolower($nome)), '/') . '\b/u', dh_sem_acento(mb_strtolower($tudo)))) {
            $unidade = $u;
        }
    }
    return ['uid' => $ev['uid'], 'data' => $ev['data'], 'hora' => $ev['hora'], 'titulo' => mb_substr($ev['titulo'], 0, 160),
        'descricao' => mb_substr($ev['descricao'], 0, 1500), 'local' => mb_substr($ev['local'], 0, 200), 'pessoas' => $pessoas, 'unidade' => $unidade];
}

// Parece reserva? (para agenda que mistura shows e reservas)
function dh_evento_parece_reserva(array $ev): bool
{
    return (bool) preg_match('/reserv|n[uú]mero de pessoas|\bpessoas\b|\bpax\b|corporativ|confraterniza|anivers|\bnome:|mesa/iu', $ev['titulo'] . "\n" . $ev['descricao']);
}

// Busca a agenda do Google e troca as reservas importadas. $forcar ignora o intervalo.
// Devolve a mensagem de erro ('' se deu certo ou se não era hora).
function dh_reservas_sincronizar(bool $forcar = false): string
{
    $r = dh_reservas();
    if ($r['config']['ics_url'] === '' || (!$forcar && time() - (int) $r['atualizado_em'] < DH_RESERVAS_INTERVALO)) {
        return '';
    }
    $trava = @fopen(DH_ARQ_RESERVAS . '.lock', 'c');
    if (!$trava || !flock($trava, LOCK_EX | LOCK_NB)) {
        return '';
    }
    try {
        $eventos = dh_ics_eventos(dh_ics_baixar($r['config']['ics_url']));
        $desde = date('Y-m-d', strtotime('-400 days'));
        $lista = [];
        foreach ($eventos as $ev) {
            if ($ev['data'] < $desde || ($r['config']['so_reservas'] && !dh_evento_parece_reserva($ev))) {
                continue;
            }
            $lista[] = dh_reserva_de_evento($ev, $r['config']['unidade']);
        }
        $r = dh_reservas();
        $r['reservas'] = $lista;
        $r['atualizado_em'] = time();
        $r['erro'] = '';
        dh_reservas_salvar($r);
        $erro = '';
    } catch (Throwable $e) {
        $r = dh_reservas();
        $r['atualizado_em'] = time(); // não tenta de novo a cada acesso
        $r['erro'] = $e->getMessage();
        dh_reservas_salvar($r);
        $erro = $e->getMessage();
    }
    flock($trava, LOCK_UN);
    fclose($trava);
    return $erro;
}

// Reservas de um mês (e de uma casa), por dia
function dh_reservas_por_dia(array $r, string $mes, string $unidade): array
{
    $por = [];
    foreach ($r['reservas'] as $x) {
        if (substr($x['data'], 0, 7) === $mes && $x['unidade'] === $unidade) {
            $por[$x['data']][] = $x;
        }
    }
    foreach ($por as &$l) {
        usort($l, fn($a, $b) => strcmp($a['hora'], $b['hora']));
    }
    return $por;
}

// Campos que vêm escritos no texto da reserva ("Nome: Juliana Souza Número de pessoas: 25 Ambiente: Rooftop Data: …")
const DH_RESERVA_ROTULOS = 'nome|n[uú]mero de pessoas|quantidade de pessoas|pessoas|ambiente|local|data|dia|hor[aá]rio|hora|telefone|tel|whats(?:app)?|contato|obs(?:erva[cç][aã]o)?|anivers[aá]rio|evento';

function dh_reserva_campo(string $texto, string $rotulo): string
{
    $proximo = '(?=\s*(?:\*|•|\||(?:' . DH_RESERVA_ROTULOS . ')\s*:)|\s*$)';
    return preg_match('/(?:^|[\s\*•|])(?:' . $rotulo . ')\s*:\s*(.+?)' . $proximo . '/isu', $texto, $m) ? trim($m[1], " \t\n-–,.;") : '';
}

// Nome, pessoas e ambiente (piso) da reserva, para o quadradinho do calendário
function dh_reserva_campos(array $x): array
{
    $texto = $x['titulo'] . "\n" . $x['descricao'];
    $nome = dh_reserva_campo($texto, 'nome');
    $amb = dh_reserva_campo($texto, 'ambiente|local');
    $pes = preg_match('/(?:n[uú]mero|quantidade)\s+de\s+pessoas\s*:?\s*(\d{1,3})/iu', $texto, $m) ? (int) $m[1] : $x['pessoas'];
    if ($nome === '') {
        // Sem "Nome:": o título, sem "Reserva" e sem o número de pessoas, encurtado
        $t = trim(preg_replace('/^\s*reserva(do|da)?\s*[:\-–]?\s*/iu', '', preg_replace('/^[^\p{L}\d]+/u', '', $x['titulo'])));
        $t = trim(preg_replace('/[\s\-–,·]*\(?\d{1,3}\s*(pessoas|pessoa|pax|lugares|convidados)\)?/iu', '', $t), " -–,·");
        $nome = mb_strimwidth($t, 0, 40, '…');
    }
    return ['nome' => $nome !== '' ? mb_strimwidth($nome, 0, 40, '…') : 'Reserva', 'pessoas' => $pes, 'ambiente' => mb_strimwidth($amb, 0, 25, '…')];
}

// "Juliana Souza · 25p · Rooftop" (quadradinho do calendário)
function dh_reserva_rotulo(array $x): string
{
    $c = dh_reserva_campos($x);
    return $c['nome'] . ($c['pessoas'] ? ' · ' . $c['pessoas'] . 'p' : '') . ($c['ambiente'] !== '' ? ' · ' . $c['ambiente'] : '');
}

// Tudo o que veio do Google, com uma linha para cada informação, para a janela de detalhes
function dh_reserva_info(array $x): array
{
    $c = dh_reserva_campos($x);
    $quebrar = fn($t) => trim(preg_replace('/\s*(\*(?=\p{L})|(?:' . DH_RESERVA_ROTULOS . ')\s*:)/iu', "\n$1", preg_replace('/^[^\p{L}\d\*]+/u', '', $t)));
    $texto = $quebrar($x['titulo']) . ($x['descricao'] !== '' ? "\n\n" . $x['descricao'] : '') . ($x['local'] !== '' ? "\n" . $x['local'] : '');
    return ['titulo' => $c['nome'], 'quando' => DH_DIAS_SEMANA[(int) date('w', strtotime($x['data']))] . ', ' . date('d/m', strtotime($x['data'])) . ($x['hora'] !== '' ? ' · ' . $x['hora'] : ''),
        'pessoas' => trim(($c['pessoas'] ? $c['pessoas'] . ' pessoas' : '') . ($c['ambiente'] !== '' ? ' · ' . $c['ambiente'] : ''), ' ·'),
        'casa' => DH_UNIDADES[$x['unidade']] ?? '', 'texto' => preg_replace("/\n{3,}/", "\n\n", $texto)];
}
