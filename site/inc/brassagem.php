<?php
// Brassagem: o caderno de produção em versão digital. Cada tanque da fábrica (única, atende as duas sedes) tem no máximo um lote
// ativo; o lote guarda o cabeçalho do caderno (cerveja, lote, levedura, PF/OG em Brix e litragem em cm)
// e o acompanhamento da fermentação e maturação (data, Brix, pressão, temperatura e anotação).
// Painel → Brassagem. Dados em dados/brassagem.json (só no servidor), com cópia diária em dados/backup.

const DH_ARQ_BRASSAGEM = DH_RAIZ . '/dados/brassagem.json';
const DH_LOTE_STATUS = ['fermentando' => 'Fermentando', 'maturando' => 'Maturando', 'finalizado' => 'Finalizado'];

function dh_brassagem(): array
{
    $b = json_decode((string) @file_get_contents(DH_ARQ_BRASSAGEM), true);
    $b = (is_array($b) ? $b : []) + ['tanques' => [], 'lotes' => []];
    // Tanques da fábrica: 1 a 11, todos de 500 L menos o 10 (450 L). Entram uma vez só, enquanto não
    // houver nenhum tanque; depois o cadastro é pelo painel (⚙ Tanques).
    if (!$b['tanques'] && empty($b['tanques_iniciais'])) {
        foreach (range(1, 11) as $n) {
            $b['tanques'][] = ['id' => sprintf('t%02d', $n), 'nome' => 'Tanque ' . $n, 'capacidade' => $n === 10 ? '450' : '500', 'litros_cm' => '', 'ativo' => true];
        }
        $b['tanques_iniciais'] = true;
    }
    return $b;
}

function dh_brassagem_salvar(array $b): void
{
    $dir = dirname(DH_ARQ_BRASSAGEM);
    $json = json_encode($b, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $tmp = DH_ARQ_BRASSAGEM . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) === false || !rename($tmp, DH_ARQ_BRASSAGEM)) {
        @unlink($tmp);
        throw new RuntimeException('Não foi possível gravar a brassagem. Verifique a permissão da pasta "dados".');
    }
    // O caderno é o histórico da produção: guarda uma cópia por dia
    if (!is_dir($dir . '/backup')) {
        mkdir($dir . '/backup', 0755, true);
    }
    @copy(DH_ARQ_BRASSAGEM, $dir . '/backup/brassagem-' . date('Y-m-d') . '.json');
}

function dh_brassagem_indice(array $lista, string $id): ?int
{
    foreach ($lista as $i => $x) {
        if ($x['id'] === $id) {
            return $i;
        }
    }
    return null;
}

function dh_tanque(array $b, string $id): ?array
{
    $i = dh_brassagem_indice($b['tanques'], $id);
    return $i === null ? null : $b['tanques'][$i];
}

// Lote que está no tanque agora (fermentando ou maturando)
function dh_tanque_lote(array $b, string $tanque): ?array
{
    foreach ($b['lotes'] as $l) {
        if ($l['tanque'] === $tanque && $l['status'] !== 'finalizado') {
            return $l;
        }
    }
    return null;
}

// Próximo número de lote: o maior número já usado + 1
function dh_lote_proximo(array $b): string
{
    $nums = array_map(fn($l) => (int) preg_replace('/\D/', '', $l['lote']), $b['lotes']);
    return $nums ? (string) (max($nums) + 1) : '';
}

// Dia da produção (dia 0 = dia da brassagem)
function dh_lote_dia(array $l, ?string $ate = null): int
{
    if (!$l['data']) {
        return 0;
    }
    return (int) floor((strtotime($ate ?? date('Y-m-d')) - strtotime($l['data'])) / 86400);
}

// Anotações do acompanhamento, da mais antiga para a mais nova. As mudanças de situação (maturação,
// finalização, reabertura) entram como anotação automática; lotes finalizados antes disso ganham a
// linha da finalização aqui, só para mostrar.
function dh_lote_registros(array $l): array
{
    $r = $l['registros'] ?? [];
    if (($l['status'] ?? '') === 'finalizado' && !empty($l['finalizado_em']) && !array_filter($r, fn($x) => ($x['evento'] ?? '') === 'finalizado')) {
        $r[] = ['id' => '', 'data' => substr($l['finalizado_em'], 0, 10), 'brix' => '', 'pressao' => '', 'temp' => '', 'texto' => '✅ Lote finalizado: tanque liberado.',
            'por' => '', 'em' => $l['finalizado_em'], 'evento' => 'finalizado'];
    }
    usort($r, fn($a, $b) => [$a['data'], $a['em']] <=> [$b['data'], $b['em']]);
    return $r;
}

// Última medida de um campo (brix, pressao, temp) no acompanhamento
function dh_lote_ultima(array $l, string $campo): ?array
{
    foreach (array_reverse(dh_lote_registros($l)) as $r) {
        if (($r[$campo] ?? '') !== '') {
            return $r;
        }
    }
    return null;
}

// Litragem medida em cm na régua da panela: cada cm são 7,5 litros efetivos
const DH_LITROS_POR_CM = 7.5;

function dh_litros_cm(string $cm): ?float
{
    return $cm !== '' ? round((float) $cm * DH_LITROS_POR_CM, 1) : null;
}

function dh_brix_sg(string $brix): ?string
{
    if ($brix === '') {
        return null;
    }
    $bx = (float) $brix;
    return number_format(1 + $bx / (258.6 - (($bx / 258.2) * 227.1)), 3, ',', '');
}

// Cervejas do cadastro do site (Painel → Cervejas), em ordem de nome, para escolher a do tanque
function dh_brassagem_cervejas(): array
{
    $l = array_values(array_filter(dh_carregar()['cervejas'] ?? [], fn($c) => ($c['nome'] ?? '') !== ''));
    usort($l, fn($a, $b) => strnatcasecmp($a['nome'], $b['nome']));
    return $l;
}

// A cerveja do cadastro de um lote (pelo id escolhido; lotes antigos, pelo nome)
function dh_lote_cerveja(array $l, ?array $cervejas = null): ?array
{
    foreach ($cervejas ?? dh_brassagem_cervejas() as $c) {
        if ((($l['cerveja_id'] ?? '') !== '' && $c['id'] === $l['cerveja_id']) || mb_strtolower($c['nome']) === mb_strtolower($l['cerveja'])) {
            return $c;
        }
    }
    return null;
}

// ---------------------------------------------------------------------------------------------
// Lembrete diário para a equipe da cervejaria atualizar os cadernos: 🔔 (e celular) para quem tem a
// Brassagem e não é administrador, e e-mail para os endereços configurados em ⚙ Tanques. Sai uma vez
// por dia, a partir da hora escolhida (a verificação roda com os lembretes, inc/lembretes.php).
const DH_BRASSAGEM_LEMBRETE_PADRAO = ['ativo' => true, 'hora' => 9, 'emails' => []];

function dh_brassagem_lembrete_config(array $b): array
{
    return ($b['config']['lembrete'] ?? []) + DH_BRASSAGEM_LEMBRETE_PADRAO;
}

// Usuários que recebem o lembrete no 🔔: têm a Brassagem e não são administradores
function dh_brassagem_lembrete_logins(): array
{
    $u = json_decode((string) @file_get_contents(DH_ARQ_USUARIOS), true);
    $r = [];
    foreach (is_array($u) ? $u : [] as $login => $x) {
        if (($x['papel'] ?? '') !== 'admin' && dh_usuario_pode($x, 'brassagem')) {
            $r[] = (string) $login;
        }
    }
    return $r;
}

// Texto do lembrete: cada lote no tanque, com o dia de produção e a última anotação
function dh_brassagem_lembrete_texto(array $b): array
{
    $hoje = date('Y-m-d');
    $linhas = [];
    foreach ($b['lotes'] as $l) {
        if ($l['status'] === 'finalizado') {
            continue;
        }
        $t = dh_tanque($b, $l['tanque']);
        $regs = dh_lote_registros($l);
        $ult = $regs ? end($regs)['data'] : $l['data'];
        $dias = $ult ? (int) floor((strtotime($hoje) - strtotime($ult)) / 86400) : null;
        $linhas[] = ['tanque' => $t['nome'] ?? 'Tanque ?', 'lote' => $l['lote'], 'cerveja' => $l['cerveja'], 'status' => DH_LOTE_STATUS[$l['status']] ?? $l['status'],
            'dia' => dh_lote_dia($l), 'ultima' => $ult, 'dias_sem' => $dias, 'id' => $l['id']];
    }
    usort($linhas, fn($x, $y) => strnatcmp($x['tanque'], $y['tanque']));
    return $linhas;
}

// Envia o lembrete do dia, se estiver na hora e ainda não saiu. $enviados: chaves já enviadas (lembretes.json).
// Devolve a chave enviada ou null.
function dh_brassagem_lembrete_rodar(array $enviados): ?string
{
    $b = dh_brassagem();
    $cfg = dh_brassagem_lembrete_config($b);
    $chave = 'brassagem-diario|' . date('Y-m-d');
    if (empty($cfg['ativo']) || (int) date('G') < (int) $cfg['hora'] || isset($enviados[$chave])) {
        return null;
    }
    $lotes = dh_brassagem_lembrete_texto($b);
    if (!$lotes) {
        return $chave; // nenhum tanque com cerveja: nada a lembrar hoje
    }
    $atrasados = array_filter($lotes, fn($x) => $x['dias_sem'] !== null && $x['dias_sem'] >= 1);
    $resumo = count($lotes) . ' tanque' . (count($lotes) > 1 ? 's' : '') . ' com cerveja'
        . ($atrasados ? ' · ' . count($atrasados) . ' sem anotação hoje' : '');
    $logins = dh_brassagem_lembrete_logins();
    if ($logins) {
        dh_notificar('brassagem', '📓', 'Lembrete: atualize os cadernos de brassagem', $resumo . '.', './?acao=brassagem', '', $logins, 'brassagem_lembrete');
    }
    $emails = $cfg['emails'] ?? [];
    if ($emails) {
        $td = 'style="padding:6px 8px;border-bottom:1px solid #e3d9cc"';
        $html = '<p>Bom dia! Hora de atualizar os cadernos de brassagem de hoje (' . date('d/m/Y') . ').</p>'
            . '<table style="border-collapse:collapse;width:100%;max-width:640px;font-size:14px"><tr><th ' . $td . ' align="left">Tanque</th><th ' . $td . ' align="left">Lote</th><th ' . $td . ' align="left">Situação</th><th ' . $td . ' align="left">Última anotação</th></tr>';
        foreach ($lotes as $x) {
            $ult = $x['ultima'] ? dh_data_br($x['ultima']) . ($x['dias_sem'] === 0 ? ' (hoje)' : ' (há ' . $x['dias_sem'] . ' dia' . ($x['dias_sem'] > 1 ? 's' : '') . ')') : '—';
            $html .= '<tr><td ' . $td . '><b>' . e($x['tanque']) . '</b></td><td ' . $td . '>' . e($x['lote'] . ' · ' . $x['cerveja']) . '</td><td ' . $td . '>' . e($x['status'] . ' · dia ' . $x['dia']) . '</td>'
                . '<td ' . $td . ($x['dias_sem'] >= 1 ? ' style="padding:6px 8px;border-bottom:1px solid #e3d9cc;color:#a32020;font-weight:bold"' : '') . '>' . e($ult) . '</td></tr>';
        }
        $html .= '</table><p><a href="' . e(dh_url_site() . 'admin/?acao=brassagem') . '" style="display:inline-block;background:#e9a23b;color:#1a120a;padding:10px 18px;border-radius:999px;text-decoration:none;font-weight:bold">Abrir os cadernos</a></p>';
        foreach ($emails as $email) {
            dh_email($email, 'Lembrete: atualize os cadernos de brassagem · ' . date('d/m'), $html);
        }
    }
    return $chave;
}
