<?php
// Usuários do painel e o que cada um pode ver (usado no painel e nas notificações enviadas pelas páginas públicas)

declare(strict_types=1);

const DH_ARQ_USUARIOS = DH_RAIZ . '/dados/usuarios.json';
const DH_PAPEIS = ['admin' => 'Administrador', 'financeiro' => 'Financeiro', 'designer' => 'Designer', 'barman' => 'Barman', 'cervejaria' => 'Cervejaria'];
// O que cada função pode fazer no painel
const DH_PERMISSOES = [
    'taplist' => ['admin', 'barman'],
    'cervejas' => ['admin', 'barman'],
    'galeria' => ['admin'],
    'agenda' => ['admin', 'financeiro', 'designer'],
    'bandas' => ['admin', 'financeiro', 'designer'],
    // Designer só vê: editar banda, fotos e link é para admin e financeiro
    'bandas_editar' => ['admin', 'financeiro'],
    'agenda_editar' => ['admin', 'financeiro'],
    'valores' => ['admin', 'financeiro'],
    'relatorio' => ['admin', 'financeiro'],
    'contratos' => ['admin', 'financeiro'],
    'orcamentos' => ['admin', 'financeiro'],
    'clientes' => ['admin', 'financeiro'],
    'vendas' => ['admin', 'financeiro'],
    'brassagem' => ['admin', 'cervejaria'],
    'receitas' => ['admin', 'cervejaria'],
    'escala' => ['admin', 'financeiro'],
    'reservas' => ['admin', 'financeiro'],
    'entregas' => ['admin', 'cervejaria'],
    'freelancers' => ['admin', 'financeiro'],
    'usuarios' => ['admin'],
    'importar' => ['admin'],
];
// Abas que se liberam por usuário em Usuários → 🔐 Permissões (o administrador sempre vê tudo;
// Usuários e Importar Google Agenda são só do administrador). Sem escolha própria, vale a função.
const DH_ABAS = [
    'taplist' => ['Tap List', 'torneiras e tap list da TV'],
    'cervejas' => ['Cervejas', 'cadastro das cervejas'],
    'agenda' => ['Agenda: ver', 'shows do mês'],
    'agenda_editar' => ['Agenda: criar e editar shows', ''],
    'bandas' => ['Bandas: ver', 'nome, tributo, Instagram e fotos'],
    'bandas_editar' => ['Bandas: editar', 'dados, fotos e link da banda'],
    'valores' => ['Valores dos shows', 'cachê e consumação'],
    'relatorio' => ['Financeiro → Bandas', 'cachês do mês'],
    'freelancers' => ['Financeiro → Freelancers', 'pagamento semanal'],
    'vendas' => ['Financeiro → Chope', 'relatórios de vendas de chope'],
    'orcamentos' => ['Orçamentos', ''],
    'contratos' => ['Contratos', 'pedidos, contratos, produtos e cupons'],
    'clientes' => ['Clientes', 'base de clientes'],
    'escala' => ['Escala', 'escala da equipe e cadastro dos freelancers'],
    'reservas' => ['Reservas', 'calendário das reservas (do Google Agenda)'],
    'entregas' => ['Chope Delivery', 'pedidos de chope a entregar: só nome, data, endereço, o que levar e contato'],
    'brassagem' => ['Brassagem', 'tanques e cadernos'],
    'receitas' => ['Receitas', ''],
    'galeria' => ['Galeria', 'fotos do site'],
];

function dh_usuario_pode(array $usuario, string $permissao): bool
{
    // Abas escolhidas para o usuário (Permissões) valem no lugar da função, menos para o administrador
    if (($usuario['papel'] ?? '') !== 'admin' && isset($usuario['permissoes']) && isset(DH_ABAS[$permissao])) {
        return in_array($permissao, $usuario['permissoes'], true);
    }
    return in_array($usuario['papel'] ?? '', DH_PERMISSOES[$permissao] ?? [], true);
}
