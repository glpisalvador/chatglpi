<?php

/**
 * Plugin Chat GLPI - GLPI 11 e 12 (sucessor do "notificacaochat")
 * Chat interno: conversas diretas e salas, presença, anexos, integração com chamados.
 */

define('PLUGIN_CHATGLPI_VERSION', '1.0.0');
define('PLUGIN_CHATGLPI_MIN_GLPI', '11.0.0');
define('PLUGIN_CHATGLPI_MAX_GLPI', '12.99.99');

function plugin_init_chatglpi(): void
{
    global $PLUGIN_HOOKS;

    // Chave literal: a constante Hooks::CSRF_COMPLIANT não existe no GLPI 12
    $PLUGIN_HOOKS['csrf_compliant']['chatglpi'] = true;

    $plugin = new Plugin();
    if (!$plugin->isActivated('chatglpi')) {
        return;
    }

    Plugin::registerClass('PluginChatglpiMenu');
    Plugin::registerClass('PluginChatglpiSala');
    Plugin::registerClass('PluginChatglpiProfile', ['addtabon' => ['Profile']]);
    Plugin::registerClass('PluginChatglpiChamado', ['addtabon' => ['Ticket']]);

    $PLUGIN_HOOKS['config_page']['chatglpi'] = 'front/config.form.php';
    $PLUGIN_HOOKS['menu_toadd']['chatglpi'] = ['tools' => 'PluginChatglpiMenu'];

    // Ícone do chat na barra: só para quem está logado e pode usar
    if (Session::getLoginUserID() && PluginChatglpiConfig::podeUsar()) {
        $PLUGIN_HOOKS['add_css']['chatglpi'] = ['css/chatglpi.css'];
        $PLUGIN_HOOKS['add_javascript']['chatglpi'] = ['js/chatglpi.js'];
    }
}

function plugin_version_chatglpi(): array
{
    return [
        'name'         => 'Chat GLPI',
        'version'      => PLUGIN_CHATGLPI_VERSION,
        'author'       => 'GLPI Salvador',
        'license'      => 'GPLv2+',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_CHATGLPI_MIN_GLPI,
                'max' => PLUGIN_CHATGLPI_MAX_GLPI,
            ],
            'php'  => ['min' => '8.1'],
        ],
    ];
}

function plugin_chatglpi_check_prerequisites(): bool
{
    return version_compare(GLPI_VERSION, PLUGIN_CHATGLPI_MIN_GLPI, '>=');
}

function plugin_chatglpi_check_config($verbose = false): bool
{
    return true;
}
