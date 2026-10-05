<?php

/**
 * Plugin Chat GLPI - página do chat em tela cheia (o mesmo chat do painel lateral)
 */

include('../../../inc/includes.php');

Session::checkLoginUser();
if (!PluginChatglpiConfig::podeUsar()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

$titulo = (string) PluginChatglpiConfig::getConfig('nome_chat') ?: 'Chat';
Html::header($titulo, $_SERVER['PHP_SELF'] ?? '', 'tools', 'PluginChatglpiMenu', 'chat');
PluginChatglpiMenu::abas('chat');
echo '<div class="chatglpi-pagina" data-chatglpi-pagina data-abrir-conversa="' . (int) ($_GET['conversa'] ?? 0) . '" data-abrir-usuario="' . (int) ($_GET['usuario'] ?? 0) . '">'
    . '<div class="chatglpi-carregando"><i class="ti ti-loader"></i> Carregando…</div></div>';
Html::footer();
