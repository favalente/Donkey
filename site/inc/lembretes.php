<?php
// Lembretes de evento por e-mail para quem recebe a cópia dos contratos (dh_emails_equipe): um na véspera
// e outro no dia. A expedição só trabalha de segunda a sexta: evento no domingo tem o lembrete da véspera
// na sexta (os eventos do fim de semana são preparados na sexta). Valem os contratos assinados e os que
// aguardam assinatura; o dia do evento é a entrega do chope (comodato) ou a data do evento.
//
// A hospedagem não tem agendador: a verificação roda no fim de um acesso, no máximo a cada 20 minutos e
// só a partir das 7h. Para ninguém esperar o envio dos e-mails, ela só roda na consulta da TV da tap list
// (a cada minuto, sem ninguém olhando), em /lembretes.php (para um agendador externo, se houver) ou, se o
// servidor libera a resposta antes (fastcgi_finish_request), em qualquer acesso. O que já foi enviado
// fica em dados/lembretes.json, por contrato, tipo de lembrete e data do evento.

const DH_ARQ_LEMBRETES = DH_RAIZ . '/dados/lembretes.json';
const DH_LEMBRETES_INTERVALO = 1200;
const DH_LEMBRETES_HORA = 7;
const DH_DIAS_SEMANA_CURTOS = [1 => 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb', 'dom'];

function dh_lembretes(): array
{
    $l = json_decode((string) @file_get_contents(DH_ARQ_LEMBRETES), true);
    return (is_array($l) ? $l : []) + ['verificado_em' => 0, 'enviados' => []];
}

function dh_lembretes_salvar(array $l): void
{
    // Só guarda os envios dos últimos 60 dias
    $l['enviados'] = array_filter($l['enviados'], fn($em) => $em >= date('Y-m-d', strtotime('-60 days')));
    @file_put_contents(DH_ARQ_LEMBRETES, json_encode($l, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

// Dia do lembrete da véspera: o dia anterior; evento no domingo, a sexta
function dh_lembrete_vespera(string $data): string
{
    return date('Y-m-d', strtotime($data . ((int) date('N', strtotime($data)) === 7 ? ' -2 days' : ' -1 day')));
}

function dh_contrato_data_evento(array $k): string
{
    $d = (string) ($k['tipo'] === 'evento' ? ($k['campos']['data'] ?? '') : ($k['campos']['entrega_data'] ?? ''));
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : '';
}

// Chamada em todo acesso (inc/dados.php): agenda a verificação para depois da resposta
function dh_lembretes_agendar(): void
{
    $semEspera = function_exists('fastcgi_finish_request') || defined('DH_PODE_DEMORAR');
    if (PHP_SAPI === 'cli' || !$semEspera || getenv('DH_SEM_LEMBRETES') || (int) date('G') < DH_LEMBRETES_HORA) {
        return;
    }
    $ultima = (int) (dh_lembretes()['verificado_em'] ?? 0);
    if (time() - $ultima < DH_LEMBRETES_INTERVALO) {
        return;
    }
    register_shutdown_function(function () {
        ignore_user_abort(true);
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        try {
            dh_lembretes_rodar();
        } catch (Throwable $e) {
            error_log('Lembretes de evento: ' . $e->getMessage());
        }
    });
}

// Envia o que estiver na hora. Devolve a lista do que foi enviado agora.
function dh_lembretes_rodar(): array
{
    $trava = @fopen(DH_RAIZ . '/dados/lembretes.lock', 'c');
    if (!$trava || !flock($trava, LOCK_EX | LOCK_NB)) {
        return [];
    }
    $l = dh_lembretes();
    $l['verificado_em'] = time();
    dh_lembretes_salvar($l);
    $hoje = date('Y-m-d');
    $feitos = [];
    foreach (dh_contratos()['contratos'] as $k) {
        $data = dh_contrato_data_evento($k);
        if ($data === '' || $data < $hoje || !in_array($k['status'], ['assinado', 'enviado'], true)) {
            continue;
        }
        // No dia, o lembrete do dia; antes, o da véspera (se a verificação atrasar, ainda sai até o dia anterior)
        $tipo = $data === $hoje ? 'dia' : ($hoje >= dh_lembrete_vespera($data) ? 'vespera' : null);
        $chave = $k['id'] . '|' . $tipo . '|' . $data;
        if (!$tipo || isset($l['enviados'][$chave])) {
            continue;
        }
        [$assunto, $html] = dh_lembrete_email($k, $tipo, $data);
        foreach (dh_emails_equipe() as $email) {
            dh_email($email, $assunto, $html);
        }
        // Também no 🔔 e no celular, para as funções escolhidas (Notificações → ⚙ Configurar)
        $partes = explode(' · ', preg_replace('/ · Donkey Head Cervejaria$/u', '', $assunto), 2);
        dh_notificar('contratos', '📅', $partes[0], ($partes[1] ?? '') . ($k['numero'] !== '' ? ' · contrato nº ' . $k['numero'] : ''),
            './?acao=contrato&id=' . rawurlencode($k['id']), '', [], 'evento_lembrete');
        $l = dh_lembretes();
        $l['enviados'][$chave] = $hoje;
        dh_lembretes_salvar($l);
        $feitos[] = $assunto;
    }
    // Reservas do Google Agenda: importa sozinho, sem ninguém precisar abrir a aba (no máximo a cada 10 min)
    try {
        dh_reservas_sincronizar();
    } catch (Throwable $e) {
        error_log('Reservas: ' . $e->getMessage());
    }
    // Lembrete diário dos cadernos de brassagem
    try {
        $chave = dh_brassagem_lembrete_rodar(dh_lembretes()['enviados']);
        if ($chave) {
            $l = dh_lembretes();
            $l['enviados'][$chave] = date('Y-m-d');
            dh_lembretes_salvar($l);
            $feitos[] = 'Lembrete da brassagem';
        }
    } catch (Throwable $e) {
        error_log('Lembrete da brassagem: ' . $e->getMessage());
    }
    flock($trava, LOCK_UN);
    fclose($trava);
    return $feitos;
}

// "amanhã (sáb 08/11)", "hoje (sáb 08/11)", "domingo (09/11)"
function dh_lembrete_quando(string $tipo, string $data): string
{
    $dia = DH_DIAS_SEMANA_CURTOS[(int) date('N', strtotime($data))] . ' ' . date('d/m', strtotime($data));
    if ($tipo === 'dia') {
        return 'hoje (' . $dia . ')';
    }
    return $data === date('Y-m-d', strtotime('+1 day')) ? 'amanhã (' . $dia . ')' : $dia;
}

function dh_lembrete_email(array $k, string $tipo, string $data): array
{
    $f = $k['campos'];
    $quando = dh_lembrete_quando($tipo, $data);
    $td = 'style="padding:6px 8px;border-bottom:1px solid #e3d9cc;vertical-align:top"';
    $linha = fn($rot, $val) => $val === '' ? '' : '<tr><td ' . $td . '><b>' . e($rot) . '</b></td><td ' . $td . '>' . $val . '</td></tr>';
    $situacao = $k['status'] === 'assinado' ? 'Assinado' : 'Aguardando assinatura';
    if ($k['tipo'] === 'evento') {
        $titulo = 'Evento ' . $quando;
        $resumo = $f['contratante_nome'];
        $h = $linha('Cliente', e($f['contratante_nome']) . ($f['contratante_telefone'] !== '' ? ' · ' . e($f['contratante_telefone']) : ''))
            . $linha('Data', e(dh_data_br($data)) . ($f['hora_inicio'] !== '' ? ' · ' . e($f['hora_inicio']) . ($f['hora_fim'] !== '' ? ' às ' . e($f['hora_fim']) : '') : ''))
            . $linha('Espaço', e($f['espaco']))
            . $linha('Convidados', e((string) $f['convidados']))
            . $linha('Atração', e($f['atracao'] ?? ''))
            . $linha('Responsável no dia', e(trim(($f['responsavel_nome'] ?? '') . ' ' . ($f['responsavel_telefone'] ?? ''))));
    } else {
        $itens = array_values(array_filter($f['itens'] ?? [], fn($it) => trim($it['descricao'] ?? '') !== ''));
        $litros = array_sum(array_map(fn($it) => empty($it['comodato']) ? (float) $it['litros'] : 0, $itens));
        $titulo = 'Chope ' . $quando;
        $resumo = $f['contratante_nome'] . ($litros > 0 ? ' · ' . dh_num($litros) . ' L' : '');
        $chope = implode('<br>', array_map(fn($it) => e(dh_num($it['litros'])) . ' L · ' . e($it['descricao']) . (!empty($it['comodato']) ? ' <i>(em comodato)</i>' : ''), $itens));
        $extras = implode('<br>', array_map(fn($x) => (int) $x['qtd'] . ' × ' . e($x['descricao']) . ($x['detalhe'] !== '' ? ' (' . e($x['detalhe']) . ')' : ''), dh_comodato_extras($f)));
        $equip = implode('<br>', array_map(fn($eq) => (int) $eq['quantidade'] . ' × ' . e($eq['descricao']), $f['equipamentos'] ?? []));
        $h = $linha('Cliente', e($f['contratante_nome']) . ($f['contratante_telefone'] !== '' ? ' · ' . e($f['contratante_telefone']) : ''))
            . $linha('Entrega', e(dh_data_br($f['entrega_data'])) . ($f['entrega_hora'] !== '' ? ' · ' . e($f['entrega_hora']) . (!empty($f['entrega_hora_estimada']) ? ' (estimado)' : '') : ''))
            . $linha('Endereço', e($f['entrega_endereco']) . (trim($f['cidade'] ?? '') !== '' ? ' · ' . e($f['cidade']) : ''))
            . $linha('Devolução', $f['devolucao_data'] !== '' ? e(dh_data_br($f['devolucao_data'])) . ($f['devolucao_hora'] !== '' ? ' · ' . e($f['devolucao_hora']) : '') : '')
            . $linha('Chope', $chope)
            . $linha('Opcionais', $extras)
            . $linha('Equipamentos', $equip)
            . $linha('Valor total', e(dh_moeda(dh_comodato_total($f))) . (!empty($f['comprovante']) ? ' · comprovante anexado' : ''));
    }
    $h .= $linha('Observações', trim($f['observacoes'] ?? '') !== '' ? '<b style="color:#c62828">' . nl2br(e($f['observacoes'])) . '</b>' : '')
        . $linha('Contrato', ($k['numero'] !== '' ? 'nº ' . e($k['numero']) . ' · ' : '') . e($situacao));
    $nota = $tipo === 'vespera' && (int) date('N', strtotime($data)) === 7 ? '<p>O evento é no domingo: este é o lembrete de sexta, para preparar tudo hoje.</p>' : '';
    $link = dh_url_site() . 'admin/?acao=contrato&id=' . rawurlencode($k['id']);
    $html = '<p style="font-size:18px"><b>Lembrete: ' . e($titulo) . '</b></p>' . $nota
        . '<table style="border-collapse:collapse;width:100%;max-width:640px;font-size:14px">' . $h . '</table>'
        . '<p><a href="' . e($link) . '" style="display:inline-block;background:#e9a23b;color:#1a120a;padding:10px 18px;border-radius:999px;text-decoration:none;font-weight:bold">Abrir o contrato no painel</a></p>';
    return ['Lembrete: ' . $titulo . ' · ' . $resumo . ' · Donkey Head Cervejaria', $html];
}

// Próximos lembretes (para a tela de Configurações): [data do envio, tipo, contrato]
function dh_lembretes_proximos(int $dias = 14): array
{
    $hoje = date('Y-m-d');
    $ate = date('Y-m-d', strtotime('+' . $dias . ' days'));
    $enviados = dh_lembretes()['enviados'];
    $lista = [];
    foreach (dh_contratos()['contratos'] as $k) {
        $data = dh_contrato_data_evento($k);
        if ($data === '' || $data < $hoje || $data > $ate || !in_array($k['status'], ['assinado', 'enviado'], true)) {
            continue;
        }
        foreach (['vespera' => max($hoje, dh_lembrete_vespera($data)), 'dia' => $data] as $tipo => $quando) {
            if ($tipo === 'vespera' && $data === $hoje) {
                continue;
            }
            $lista[] = ['envio' => $quando, 'tipo' => $tipo, 'data' => $data, 'k' => $k, 'enviado' => isset($enviados[$k['id'] . '|' . $tipo . '|' . $data])];
        }
    }
    usort($lista, fn($a, $b) => [$a['envio'], $a['data']] <=> [$b['envio'], $b['data']]);
    return $lista;
}
