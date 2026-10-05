<?php

/**
 * Plugin Chat GLPI - lista de salas (busca nativa)
 */

include('../../../inc/includes.php');

Session::checkLoginUser();
if (!PluginChatglpiSala::canView()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

Html::header(PluginChatglpiSala::getTypeName(2), $_SERVER['PHP_SELF'] ?? '', 'tools', 'PluginChatglpiMenu', 'sala');
PluginChatglpiMenu::abas('sala');
Search::show('PluginChatglpiSala');
Html::footer();
