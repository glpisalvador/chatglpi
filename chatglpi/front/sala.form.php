<?php

/**
 * Plugin Chat GLPI - formulário da sala (criar, atualizar, excluir)
 */

include('../../../inc/includes.php');

Session::checkLoginUser();

$sala = new PluginChatglpiSala();

if (isset($_POST['add'])) {
    $sala->check(-1, CREATE, $_POST);
    $id = (int) $sala->add($_POST);
    Html::redirect($id ? $sala->getFormURLWithID($id) : PluginChatglpiConfig::url('sala.form.php'));
} elseif (isset($_POST['update'])) {
    $sala->check((int) $_POST['id'], UPDATE);
    $sala->update($_POST);
    Html::back();
} elseif (isset($_POST['purge']) || isset($_POST['delete'])) {
    $sala->check((int) $_POST['id'], PURGE);
    $sala->delete($_POST, true);
    $sala->redirectToList();
}

$id = (int) ($_GET['id'] ?? 0);
if ($id > 0) {
    $sala->check($id, READ);
} elseif (!PluginChatglpiSala::canCreate()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}
Html::header(PluginChatglpiSala::getTypeName(1), $_SERVER['PHP_SELF'] ?? '', 'tools', 'PluginChatglpiMenu', 'sala');
$sala->display(['id' => $id]);
Html::footer();
