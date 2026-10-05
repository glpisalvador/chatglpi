<?php

/**
 * Plugin Chat GLPI - atalho da configuração (marketplace)
 */

include('../../../inc/includes.php');

Session::checkLoginUser();
Html::redirect(PluginChatglpiConfig::url('config.form.php'));
