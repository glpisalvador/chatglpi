<?php

/**
 * Plugin Chat GLPI - aba "Chat" no perfil (direito nativo plugin_chatglpi)
 */
class PluginChatglpiProfile extends CommonGLPI
{
    public static function getTypeName($nb = 0): string
    {
        return 'Chat';
    }

    public static function getAllRights(): array
    {
        return [[
            'itemtype' => 'PluginChatglpiMenu',
            'label'    => 'Chat',
            'field'    => PluginChatglpiConfig::DIREITO,
            'rights'   => [READ => 'Usar o chat', UPDATE => 'Gerenciar salas'],
        ]];
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if ($item instanceof Profile && !$item->isNewItem()) {
            return self::createTabEntry('Chat', 0, null, 'ti ti-messages');
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if (!$item instanceof Profile) {
            return true;
        }
        $perfil = new Profile();
        $perfil->getFromDB($item->getID());
        $pode = Session::haveRight('profile', UPDATE);
        echo '<div class="spaced">';
        if ($pode) {
            echo '<form method="post" action="' . PluginChatglpiConfig::e(Profile::getFormURL()) . '">';
        }
        $perfil->displayRightsChoiceMatrix(self::getAllRights(), [
            'canedit'       => $pode,
            'default_class' => 'tab_bg_2',
            'title'         => 'Chat',
        ]);
        if ($pode) {
            echo '<div class="center">' . Html::hidden('id', ['value' => $item->getID()])
                . Html::submit(_sx('button', 'Save'), ['name' => 'update', 'class' => 'btn btn-primary']) . '</div>';
            Html::closeForm();
        }
        echo '<p class="text-muted" style="font-size:12px;"><i class="ti ti-info-circle"></i> "Usar o chat" mostra o ícone do chat na barra e permite conversar; "Gerenciar salas" permite criar e alterar as salas.</p>';
        echo '</div>';
        return true;
    }
}
