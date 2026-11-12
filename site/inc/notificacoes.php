<?php
// Notificações do painel (sino 🔔 no topo): cada usuário vê as da área que ele acessa e marca como lidas.
// Ex.: contrato assinado, cliente assinou, pedido de chope preenchido, orçamento aprovado.

declare(strict_types=1);

const DH_ARQ_NOTIFICACOES = DH_RAIZ . '/dados/notificacoes.json';
const DH_NOTIFICACOES_MAX = 300;
// Tipos de notificação: permissão que precisa ter, ícone, nome, explicação e quem recebe
// ('admin' = só administradores, 'equipe' = só quem não é administrador, 'todos')
const DH_NOTIF_TIPOS = [
    'contrato_assinado' => ['contratos', '✅', 'Contrato assinado', 'Quando o contrato fica assinado pelas duas partes', 'admin'],
    'cliente_assinou' => ['contratos', '✍️', 'Cliente assinou', 'Quando o cliente assina e falta a assinatura da Donkey', 'admin'],
    'pedido_chope' => ['contratos', '🍺', 'Pedido de chope preenchido', 'Quando o cliente preenche o pedido de chope', 'admin'],
    'donkey_assinou' => ['contratos', '🖊️', 'Contrato assinado pela Donkey', 'Quando a Donkey Head Cervejaria assina um contrato ou pedido', 'equipe'],
    'orcamento_aprovado' => ['orcamentos', '👍', 'Orçamento aprovado', 'Quando o cliente aprova um orçamento', 'todos'],
    'brassagem_caderno' => ['brassagem', '🧪', 'Caderno de brassagem atualizado', 'Lote novo, anotação ou mudança de situação feita por outra pessoa', 'todos'],
    'brassagem_lembrete' => ['brassagem', '📓', 'Lembrete diário dos cadernos', 'Todo dia, para atualizar os cadernos', 'equipe'],
    'evento_lembrete' => ['contratos', '📅', 'Lembrete de evento', 'Na véspera e no dia de cada evento ou entrega de chope', 'todos'],
];
// Funções que recebem o lembrete de evento além das que veem Contratos
const DH_NOTIF_PAPEIS_EXTRA = ['evento_lembrete' => ['cervejaria']];

// Funções (Administrador, Financeiro, Cervejaria…) que recebem cada tipo: o que o administrador
// escolheu em Notificações → ⚙ Configurar ou, sem escolha, as funções que veem a aba daquele tipo
function dh_notif_papeis(string $tipo, ?array $n = null): array
{
    $n ??= dh_notificacoes();
    if (isset($n['papeis'][$tipo]) && is_array($n['papeis'][$tipo])) {
        return $n['papeis'][$tipo];
    }
    [$perm, , , , $quem] = DH_NOTIF_TIPOS[$tipo] + [4 => 'todos'];
    $r = array_merge(DH_PERMISSOES[$perm] ?? [], DH_NOTIF_PAPEIS_EXTRA[$tipo] ?? []);
    if ($quem !== 'todos') {
        $r = array_filter($r, fn($p) => ($quem === 'admin') === ($p === 'admin'));
    }
    return array_values(array_unique($r));
}

// O tipo vale para esse usuário? (pela função dele)
function dh_notif_tipo_para(string $tipo, array $usuario, ?array $n = null): bool
{
    return in_array($usuario['papel'] ?? '', dh_notif_papeis($tipo, $n), true);
}

// Logins que recebem o tipo (menos quem fez a ação)
function dh_notif_destinatarios_tipo(string $tipo, string $exceto = '', ?array $n = null): array
{
    $u = json_decode((string) @file_get_contents(DH_ARQ_USUARIOS), true);
    $r = [];
    foreach (is_array($u) ? $u : [] as $l => $x) {
        if ((string) $l !== $exceto && dh_notif_tipo_para($tipo, $x, $n)) {
            $r[] = (string) $l;
        }
    }
    return $r;
}

function dh_notif_papeis_salvar(array $papeis): void
{
    $fp = @fopen(DH_ARQ_NOTIFICACOES . '.lock', 'c');
    if ($fp) {
        flock($fp, LOCK_EX);
    }
    $n = dh_notificacoes();
    foreach (DH_NOTIF_TIPOS as $tipo => $_) {
        $n['papeis'][$tipo] = array_values(array_intersect(array_keys(DH_PAPEIS), (array) ($papeis[$tipo] ?? [])));
    }
    dh_notificacoes_salvar($n);
    if ($fp) {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

// O usuário quer esse tipo? (padrão: sim)
function dh_notif_quer(array $n, string $login, string $tipo): bool
{
    return $tipo === '' || ($n['prefs'][$login][$tipo] ?? true) !== false;
}

function dh_notif_prefs_salvar(string $login, array $ligados): void
{
    $fp = @fopen(DH_ARQ_NOTIFICACOES . '.lock', 'c');
    if ($fp) {
        flock($fp, LOCK_EX);
    }
    $n = dh_notificacoes();
    foreach (DH_NOTIF_TIPOS as $tipo => $_) {
        $n['prefs'][$login][$tipo] = in_array($tipo, $ligados, true);
    }
    dh_notificacoes_salvar($n);
    if ($fp) {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

function dh_notificacoes(): array
{
    $n = json_decode((string) @file_get_contents(DH_ARQ_NOTIFICACOES), true);
    return (is_array($n) ? $n : []) + ['itens' => [], 'lidos' => [], 'todas_ate' => [], 'vistas_ate' => [], 'prefs' => [], 'papeis' => []];
}

function dh_notificacoes_salvar(array $n): void
{
    $dir = dirname(DH_ARQ_NOTIFICACOES);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $tmp = DH_ARQ_NOTIFICACOES . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, json_encode($n, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) === false || !rename($tmp, DH_ARQ_NOTIFICACOES)) {
        @unlink($tmp);
    }
}

// Cria uma notificação para quem tem a permissão $perm (ex.: 'contratos'). $link é relativo ao painel (./?acao=...).
// $login = quem fez a ação (não recebe a própria notificação). Nunca interrompe quem chamou.
function dh_notificar(string $perm, string $icone, string $titulo, string $texto = '', string $link = '', string $login = '', array $para = [], string $tipo = ''): void
{
    try {
        $fp = @fopen(DH_ARQ_NOTIFICACOES . '.lock', 'c');
        if ($fp) {
            flock($fp, LOCK_EX);
        }
        $n = dh_notificacoes();
        // Notificação de um tipo: vai para as funções escolhidas para ele (Notificações → ⚙ Configurar)
        if (!$para && $tipo !== '' && isset(DH_NOTIF_TIPOS[$tipo])) {
            $para = dh_notif_destinatarios_tipo($tipo, $login, $n);
            if (!$para) {
                if ($fp) {
                    flock($fp, LOCK_UN);
                    fclose($fp);
                }
                return;
            }
        }
        $n['itens'][] = ['id' => dh_id_novo('n'), 'em' => date('Y-m-d H:i:s'), 'perm' => $perm, 'icone' => $icone,
            'titulo' => mb_substr($titulo, 0, 160), 'texto' => mb_substr($texto, 0, 300), 'link' => $link, 'login' => $login, 'tipo' => $tipo] + ($para ? ['para' => array_values($para)] : []);
        $n['itens'] = array_slice($n['itens'], -DH_NOTIFICACOES_MAX);
        $ids = array_flip(array_column($n['itens'], 'id'));
        foreach ($n['lidos'] as $l => $lidos) {
            $n['lidos'][$l] = array_values(array_filter((array) $lidos, fn($id) => isset($ids[$id])));
        }
        dh_notificacoes_salvar($n);
        if ($fp) {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
        // Celulares: depois que a página já foi entregue a quem fez a ação (não deixa a assinatura esperando)
        $destino = array_values(array_filter($para ?: dh_notificacao_destinatarios($perm, $login), fn($l) => dh_notif_quer($n, $l, $tipo)));
        if ($destino && dh_push_inscricoes()) {
            register_shutdown_function(function () use ($destino) {
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_write_close(); // libera a sessão antes (a próxima página não espera o envio)
                }
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                }
                try {
                    dh_push_enviar($destino);
                } catch (Throwable $t) {
                }
            });
        }
    } catch (Throwable $t) {
        // notificação nunca impede a assinatura ou o pedido
    }
}

// Notificações que o usuário pode ver (mais novas primeiro), cada uma com 'lida' => bool.
// $pode: função que diz se o usuário tem a permissão.
function dh_notificacoes_do_usuario(string $login, callable $pode, ?array $n = null): array
{
    $n ??= dh_notificacoes();
    $ate = $n['todas_ate'][$login] ?? '';
    $lidos = array_flip($n['lidos'][$login] ?? []);
    $r = [];
    foreach (array_reverse($n['itens']) as $it) {
        $visivel = (isset($it['para']) ? in_array($login, $it['para'], true) : (($it['login'] ?? '') !== $login && $pode($it['perm'])))
            && dh_notif_quer($n, $login, (string) ($it['tipo'] ?? ''));
        if (!$visivel) {
            continue;
        }
        $it['lida'] = $it['em'] <= $ate || isset($lidos[$it['id']]);
        $it['vista'] = $it['lida'] || $it['em'] <= ($n['vistas_ate'][$login] ?? '');
        $r[] = $it;
    }
    return $r;
}

// Marca como lidas: lista de ids, ou ['*'] para todas
function dh_notificacoes_marcar(string $login, array $ids): void
{
    $fp = @fopen(DH_ARQ_NOTIFICACOES . '.lock', 'c');
    if ($fp) {
        flock($fp, LOCK_EX);
    }
    $n = dh_notificacoes();
    if ($ids === ['*']) {
        $n['todas_ate'][$login] = date('Y-m-d H:i:s');
        $n['lidos'][$login] = [];
    } else {
        $n['lidos'][$login] = array_values(array_unique(array_merge($n['lidos'][$login] ?? [], $ids)));
    }
    dh_notificacoes_salvar($n);
    if ($fp) {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

// ---------------------------------------------------------------------------------------------
// Notificação no celular (Web Push): o aparelho do usuário recebe um "toque" sem conteúdo e o
// painel (sw.js) busca a notificação nova dele, então nada do contrato passa pelo serviço de push.

const DH_ARQ_PUSH = DH_RAIZ . '/dados/push.json';
const DH_ARQ_PUSH_CHAVES = DH_RAIZ . '/dados/push-chaves.json';

function dh_b64url(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

// Par de chaves VAPID do site (criado uma vez no servidor)
function dh_push_chaves(): ?array
{
    $k = json_decode((string) @file_get_contents(DH_ARQ_PUSH_CHAVES), true);
    if (is_array($k) && !empty($k['privada']) && !empty($k['publica'])) {
        return $k;
    }
    if (!function_exists('openssl_pkey_new')) {
        return null;
    }
    $pk = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if (!$pk || !openssl_pkey_export($pk, $pem)) {
        return null;
    }
    $d = openssl_pkey_get_details($pk);
    $pub = "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
    $k = ['privada' => $pem, 'publica' => dh_b64url($pub)];
    if (!is_dir(dirname(DH_ARQ_PUSH_CHAVES))) {
        mkdir(dirname(DH_ARQ_PUSH_CHAVES), 0755, true);
    }
    file_put_contents(DH_ARQ_PUSH_CHAVES, json_encode($k), LOCK_EX);
    return $k;
}

function dh_push_inscricoes(): array
{
    $p = json_decode((string) @file_get_contents(DH_ARQ_PUSH), true);
    return is_array($p) ? $p : [];
}

function dh_push_inscricoes_salvar(array $p): void
{
    if (!is_dir(dirname(DH_ARQ_PUSH))) {
        mkdir(dirname(DH_ARQ_PUSH), 0755, true);
    }
    $tmp = DH_ARQ_PUSH . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, json_encode($p, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) === false || !rename($tmp, DH_ARQ_PUSH)) {
        @unlink($tmp);
    }
}

// Só endereços dos serviços de push dos navegadores (Chrome/Android, Firefox, Safari, Edge)
function dh_push_endpoint_valido(string $url): bool
{
    $h = (string) parse_url($url, PHP_URL_HOST);
    return str_starts_with($url, 'https://') && strlen($url) < 1000
        && (bool) preg_match('/(^|\.)(fcm\.googleapis\.com|push\.services\.mozilla\.com|push\.apple\.com|notify\.windows\.com)$/', $h);
}

function dh_push_inscrever(string $login, string $endpoint): void
{
    $p = dh_push_inscricoes();
    foreach ($p as $l => $lista) {
        $p[$l] = array_values(array_filter($lista, fn($x) => $x['endpoint'] !== $endpoint));
    }
    $p[$login][] = ['endpoint' => $endpoint, 'em' => date('Y-m-d H:i:s')];
    $p[$login] = array_slice($p[$login], -10);
    dh_push_inscricoes_salvar(array_filter($p));
}

function dh_push_cancelar(string $endpoint): void
{
    $p = dh_push_inscricoes();
    foreach ($p as $l => $lista) {
        $p[$l] = array_values(array_filter($lista, fn($x) => $x['endpoint'] !== $endpoint));
    }
    dh_push_inscricoes_salvar(array_filter($p));
}

// JWT ES256 do VAPID para o servidor de push
function dh_push_jwt(string $endpoint, array $chaves): ?string
{
    $u = parse_url($endpoint);
    $cab = dh_b64url(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $corpo = dh_b64url(json_encode(['aud' => $u['scheme'] . '://' . $u['host'], 'exp' => time() + 12 * 3600, 'sub' => 'mailto:' . DH_EMPRESA['email']]));
    if (!openssl_sign($cab . '.' . $corpo, $der, $chaves['privada'], OPENSSL_ALGO_SHA256)) {
        return null;
    }
    // DER (SEQUENCE de dois INTEGER) → r||s com 32 bytes cada
    $pos = 3 + (ord($der[1]) & 0x80 ? (ord($der[1]) & 0x7f) : 0);
    $lr = ord($der[$pos]);
    $r = substr($der, $pos + 1, $lr);
    $pos += 2 + $lr;
    $s = substr($der, $pos + 1, ord($der[$pos]));
    $fix = fn($x) => str_pad(ltrim($x, "\0"), 32, "\0", STR_PAD_LEFT);
    return $cab . '.' . $corpo . '.' . dh_b64url($fix($r) . $fix($s));
}

// Manda o "toque" para os aparelhos desses usuários; remove inscrições que o navegador cancelou
function dh_push_enviar(array $logins): void
{
    $chaves = dh_push_chaves();
    $insc = dh_push_inscricoes();
    if (!$chaves || !function_exists('curl_multi_init')) {
        return;
    }
    $mh = curl_multi_init();
    $hs = [];
    foreach (array_unique($logins) as $login) {
        foreach ($insc[$login] ?? [] as $x) {
            $jwt = dh_push_jwt($x['endpoint'], $chaves);
            if (!$jwt) {
                continue;
            }
            $ch = curl_init($x['endpoint']);
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => '', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_HTTPHEADER => ['TTL: 86400', 'Urgency: high', 'Content-Length: 0', 'Authorization: vapid t=' . $jwt . ', k=' . $chaves['publica']]]);
            curl_multi_add_handle($mh, $ch);
            $hs[] = [$ch, $x['endpoint']];
        }
    }
    if (!$hs) {
        return;
    }
    do {
        $st = curl_multi_exec($mh, $ativos);
        if ($ativos) {
            curl_multi_select($mh, 1.0);
        }
    } while ($ativos && $st === CURLM_OK);
    foreach ($hs as [$ch, $endpoint]) {
        $cod = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($cod === 404 || $cod === 410) {
            dh_push_cancelar($endpoint);
        }
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
}

// Quem recebe uma notificação com a permissão $perm (menos quem fez a ação)
function dh_notificacao_destinatarios(string $perm, string $login): array
{
    $u = json_decode((string) @file_get_contents(DH_ARQ_USUARIOS), true);
    $r = [];
    foreach (is_array($u) ? $u : [] as $l => $x) {
        if ($l !== $login && dh_usuario_pode($x, $perm)) {
            $r[] = (string) $l;
        }
    }
    return $r;
}

// Abriu a lista do sino: o número do sino zera (as não lidas continuam destacadas na lista)
function dh_notificacoes_vistas(string $login): void
{
    $fp = @fopen(DH_ARQ_NOTIFICACOES . '.lock', 'c');
    if ($fp) {
        flock($fp, LOCK_EX);
    }
    $n = dh_notificacoes();
    $n['vistas_ate'][$login] = date('Y-m-d H:i:s');
    dh_notificacoes_salvar($n);
    if ($fp) {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}
