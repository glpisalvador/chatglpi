<?php

/**
 * Plugin Chat GLPI - item no menu Ferramentas
 */
class PluginChatglpiMenu extends CommonGLPI
{
    public static function getTypeName($nb = 0): string
    {
        return (string) PluginChatglpiConfig::getConfig('nome_chat') ?: 'Chat';
    }

    public static function getMenuName(): string
    {
        return self::getTypeName();
    }

    public static function getIcon()
    {
        return 'ti ti-messages';
    }

    public static function canView(): bool
    {
        return PluginChatglpiConfig::podeUsar();
    }

    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }
        $base = '/plugins/chatglpi/front/';
        $menu = [
            'title'   => self::getMenuName(),
            'page'    => $base . 'chat.php',
            'icon'    => 'ti ti-messages',
            'options' => [
                'chat' => ['title' => 'Conversas', 'page' => $base . 'chat.php', 'icon' => 'ti ti-messages'],
            ],
        ];
        if (PluginChatglpiConfig::podeGerenciarSalas()) {
            $menu['options']['sala'] = [
                'title' => 'Salas',
                'page'  => $base . 'sala.php',
                'icon'  => 'ti ti-users-group',
                'links' => ['search' => $base . 'sala.php', 'add' => $base . 'sala.form.php'],
            ];
            $menu['links'] = ['search' => $base . 'sala.php', 'add' => $base . 'sala.form.php'];
        }
        if (PluginChatglpiConfig::ehAdmin()) {
            $menu['options']['config'] = ['title' => 'Configuração', 'page' => $base . 'config.form.php', 'icon' => 'ti ti-settings'];
        }
        return $menu;
    }

    /** Abas de navegação entre as páginas do plugin */
    public static function abas(string $ativa): void
    {
        $e = [PluginChatglpiConfig::class, 'e'];
        $abas = ['chat' => ['ti ti-messages', 'Conversas', 'chat.php']];
        if (PluginChatglpiConfig::podeGerenciarSalas()) {
            $abas['sala'] = ['ti ti-users-group', 'Salas', 'sala.php'];
        }
        if (PluginChatglpiConfig::ehAdmin()) {
            $abas['config'] = ['ti ti-settings', 'Configuração', 'config.form.php'];
        }
        if (count($abas) < 2) {
            return;
        }
        echo '<ul class="nav nav-tabs chatglpi-modulos">';
        foreach ($abas as $k => [$icone, $rotulo, $pagina]) {
            echo '<li class="nav-item"><a class="nav-link' . ($k === $ativa ? ' active' : '') . '" href="' . $e(PluginChatglpiConfig::url($pagina)) . '"><i class="' . $icone . '"></i> ' . $e($rotulo) . '</a></li>';
        }
        echo '</ul>';
    }
}
