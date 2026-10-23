<?php
// Enquanto a hospedagem estiver em PHP antigo (o site novo precisa de 7.4+), mostra o site anterior.
// Ao atualizar o PHP no painel da Locaweb, o site novo entra no ar sozinho.
if (version_compare(PHP_VERSION, '7.4.0', '<')) {
    include dirname(__FILE__) . '/site-antigo.php';
    exit;
}
require __DIR__ . '/inicio.php';
