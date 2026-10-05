<?php

/**
 * Plugin Chat GLPI - download de anexo (só para quem participa da conversa)
 */

include('../../../inc/includes.php');

Session::checkLoginUser();
if (!PluginChatglpiConfig::podeUsar()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

PluginChatglpiAnexo::entregar((int) ($_GET['id'] ?? 0), (int) Session::getLoginUserID(), !empty($_GET['ver']));
