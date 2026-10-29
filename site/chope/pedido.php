<?php
// Pedido de chope aberto pelo próprio cliente (botão "Fazer meu pedido" da página do Chope Delivery).
// Cria um pedido em branco e leva o cliente ao mesmo formulário do link que o painel gera: ele escolhe
// os rótulos, preenche os dados, anexa o comprovante e assina. A Donkey só assina no painel para aprovar.
require __DIR__ . '/../inc/dados.php';

session_name('dh_contrato');
session_start();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ./', true, 303);
    exit;
}
$c = dh_contratos();

// Mesmo visitante: continua o pedido que já abriu
$aberto = (string) ($_SESSION['pedido_site'] ?? '');
if ($aberto !== '') {
    $i = dh_contrato_por_token($c, $aberto);
    if ($i !== null && $c['contratos'][$i]['status'] === 'preenchimento') {
        header('Location: ../contrato/?t=' . $aberto, true, 303);
        exit;
    }
}

// Pedidos do site nunca preenchidos somem depois de 3 dias
$limite = date('Y-m-d H:i:s', strtotime('-3 days'));
foreach ($c['contratos'] as $n => $k) {
    if (($k['origem'] ?? '') === 'site' && $k['status'] === 'preenchimento' && $k['criado_em'] < $limite) {
        dh_comprovante_apagar($k['campos']['comprovante'] ?? null);
        unset($c['contratos'][$n]);
    }
}
$c['contratos'] = array_values($c['contratos']);

// No máximo 5 pedidos novos por hora vindos do mesmo endereço
$ip = hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
$umaHora = date('Y-m-d H:i:s', strtotime('-1 hour'));
$recentes = array_filter($c['contratos'], fn($k) => ($k['origem'] ?? '') === 'site' && ($k['origem_ip'] ?? '') === $ip && $k['criado_em'] >= $umaHora);
if (count($recentes) >= 5) {
    http_response_code(429);
    header('Location: ./?muitos=1', true, 303);
    exit;
}

$k = ['id' => dh_id_novo('c'), 'token' => bin2hex(random_bytes(16)), 'numero' => '', 'tipo' => 'comodato', 'status' => 'preenchimento',
    'criado_por' => 'Cliente pelo site', 'criado_em' => date('Y-m-d H:i:s'), 'assinaturas' => [], 'campos' => dh_contrato_campos_padrao('comodato'),
    'origem' => 'site', 'origem_ip' => $ip, 'referencia' => 'Pedido pelo site'];
$k['campos']['itens'] = [];
$k['campos']['pagamento'] = 'pix';
$c['contratos'][] = $k;
dh_contratos_salvar($c);
$_SESSION['pedido_site'] = $k['token'];
header('Location: ../contrato/?t=' . $k['token'], true, 303);
exit;
