<?php

/**
 * Plugin Chat GLPI - instalação e desinstalação (a desinstalação nunca remove tabelas)
 */

function plugin_chatglpi_install(): bool
{
    global $DB;

    $o = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC';

    $tabelas = [
        'glpi_plugin_chatglpi_configs' => "CREATE TABLE `glpi_plugin_chatglpi_configs` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `name` varchar(255) NOT NULL,
            `value` longtext,
            `date_mod` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `name` (`name`)
        ) $o",
        'glpi_plugin_chatglpi_salas' => "CREATE TABLE `glpi_plugin_chatglpi_salas` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `name` varchar(255) NOT NULL DEFAULT '',
            `comment` longtext,
            `groups_id` int unsigned NOT NULL DEFAULT 0,
            `is_active` tinyint(1) NOT NULL DEFAULT 1,
            `users_id` int unsigned NOT NULL DEFAULT 0,
            `date_creation` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            `date_mod` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `groups_id` (`groups_id`),
            KEY `is_active` (`is_active`)
        ) $o",
        'glpi_plugin_chatglpi_conversas' => "CREATE TABLE `glpi_plugin_chatglpi_conversas` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `tipo` varchar(10) NOT NULL DEFAULT 'direta',
            `chave` varchar(50) DEFAULT NULL,
            `salas_id` int unsigned NOT NULL DEFAULT 0,
            `ultima_mensagem` int unsigned NOT NULL DEFAULT 0,
            `date_creation` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            `date_mod` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `chave` (`chave`),
            KEY `salas_id` (`salas_id`)
        ) $o",
        'glpi_plugin_chatglpi_participantes' => "CREATE TABLE `glpi_plugin_chatglpi_participantes` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `conversas_id` int unsigned NOT NULL DEFAULT 0,
            `users_id` int unsigned NOT NULL DEFAULT 0,
            `is_membro` tinyint(1) NOT NULL DEFAULT 1,
            `ultima_lida` int unsigned NOT NULL DEFAULT 0,
            `is_silenciada` tinyint(1) NOT NULL DEFAULT 0,
            `is_oculta` tinyint(1) NOT NULL DEFAULT 0,
            `date_creation` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `conversa_usuario` (`conversas_id`, `users_id`),
            KEY `users_id` (`users_id`)
        ) $o",
        'glpi_plugin_chatglpi_mensagens' => "CREATE TABLE `glpi_plugin_chatglpi_mensagens` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `conversas_id` int unsigned NOT NULL DEFAULT 0,
            `users_id` int unsigned NOT NULL DEFAULT 0,
            `tipo` varchar(10) NOT NULL DEFAULT 'texto',
            `conteudo` longtext,
            `anexos_id` int unsigned NOT NULL DEFAULT 0,
            `is_editada` tinyint(1) NOT NULL DEFAULT 0,
            `is_apagada` tinyint(1) NOT NULL DEFAULT 0,
            `date_creation` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            `date_mod` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `conversa` (`conversas_id`, `id`),
            KEY `users_id` (`users_id`),
            KEY `date_creation` (`date_creation`),
            KEY `date_mod` (`date_mod`)
        ) $o",
        'glpi_plugin_chatglpi_anexos' => "CREATE TABLE `glpi_plugin_chatglpi_anexos` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `conversas_id` int unsigned NOT NULL DEFAULT 0,
            `users_id` int unsigned NOT NULL DEFAULT 0,
            `nome` varchar(255) NOT NULL DEFAULT '',
            `arquivo` varchar(255) NOT NULL DEFAULT '',
            `mime` varchar(150) NOT NULL DEFAULT '',
            `tamanho` int unsigned NOT NULL DEFAULT 0,
            `date_creation` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `conversas_id` (`conversas_id`)
        ) $o",
        'glpi_plugin_chatglpi_presencas' => "CREATE TABLE `glpi_plugin_chatglpi_presencas` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `users_id` int unsigned NOT NULL DEFAULT 0,
            `status` varchar(10) NOT NULL DEFAULT 'online',
            `ultimo_sinal` timestamp NULL DEFAULT NULL,
            `ultima_atividade` timestamp NULL DEFAULT NULL,
            `digitando_conversa` int unsigned NOT NULL DEFAULT 0,
            `digitando_ate` timestamp NULL DEFAULT NULL,
            `notificar_navegador` tinyint(1) NOT NULL DEFAULT 1,
            `tocar_som` tinyint(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `users_id` (`users_id`),
            KEY `ultimo_sinal` (`ultimo_sinal`)
        ) $o",
        'glpi_plugin_chatglpi_chamados' => "CREATE TABLE `glpi_plugin_chatglpi_chamados` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `tickets_id` int unsigned NOT NULL DEFAULT 0,
            `conversas_id` int unsigned NOT NULL DEFAULT 0,
            `itilfollowups_id` int unsigned NOT NULL DEFAULT 0,
            `users_id` int unsigned NOT NULL DEFAULT 0,
            `acao` varchar(10) NOT NULL DEFAULT 'salvar',
            `quantidade` int unsigned NOT NULL DEFAULT 0,
            `date_creation` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `tickets_id` (`tickets_id`),
            KEY `conversas_id` (`conversas_id`)
        ) $o",
    ];
    foreach ($tabelas as $nome => $sql) {
        if (!$DB->tableExists($nome, false)) {
            $DB->doQuery($sql);
        }
    }

    foreach (PluginChatglpiConfig::padroes() as $nome => $valor) {
        if (count($DB->request(['FROM' => 'glpi_plugin_chatglpi_configs', 'WHERE' => ['name' => $nome]])) === 0) {
            $DB->insert('glpi_plugin_chatglpi_configs', ['name' => $nome, 'value' => $valor]);
        }
    }

    // Pasta dos anexos, protegida contra acesso direto
    $pasta = GLPI_PLUGIN_DOC_DIR . '/chatglpi/anexos';
    if (!is_dir($pasta)) {
        @mkdir($pasta, 0755, true);
    }
    if (is_dir(dirname($pasta)) && !is_file(dirname($pasta) . '/.htaccess')) {
        @file_put_contents(dirname($pasta) . '/.htaccess', "Require all denied\nOrder Deny,Allow\nDeny from all\n");
    }

    // Direito nativo: perfis da interface padrão usam o chat; quem administra o GLPI também gerencia salas
    foreach ($DB->request(['SELECT' => ['id', 'interface'], 'FROM' => 'glpi_profiles']) as $p) {
        $pid = (int) $p['id'];
        $existe = $DB->request(['FROM' => 'glpi_profilerights', 'WHERE' => ['profiles_id' => $pid, 'name' => PluginChatglpiConfig::DIREITO], 'LIMIT' => 1]);
        if (count($existe) > 0) {
            continue;
        }
        $direito = $p['interface'] === 'central' ? READ : 0;
        $config = $DB->request(['SELECT' => ['rights'], 'FROM' => 'glpi_profilerights', 'WHERE' => ['profiles_id' => $pid, 'name' => 'config'], 'LIMIT' => 1])->current();
        if ($config && ((int) $config['rights'] & UPDATE)) {
            $direito |= READ | UPDATE;
        }
        $DB->insert('glpi_profilerights', ['profiles_id' => $pid, 'name' => PluginChatglpiConfig::DIREITO, 'rights' => $direito]);
        if (isset($_SESSION['glpiactiveprofile']['id']) && (int) $_SESSION['glpiactiveprofile']['id'] === $pid) {
            $_SESSION['glpiactiveprofile'][PluginChatglpiConfig::DIREITO] = $direito;
        }
    }

    CronTask::register('PluginChatglpiManutencao', 'ChatglpiManutencao', HOUR_TIMESTAMP, [
        'mode'    => CronTask::MODE_EXTERNAL,
        'state'   => CronTask::STATE_WAITING,
        'comment' => 'Chat GLPI: apaga mensagens e anexos fora da retenção e limpa presenças antigas',
    ]);

    return true;
}

/** Desinstalar mantém todas as tabelas, anexos e direitos (os dados ficam para uma reinstalação) */
function plugin_chatglpi_uninstall(): bool
{
    return true;
}
