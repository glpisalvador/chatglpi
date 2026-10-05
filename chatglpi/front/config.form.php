<?php

/**
 * Plugin Chat GLPI - configuração
 */

include('../../../inc/includes.php');

Session::checkLoginUser();
if (!PluginChatglpiConfig::ehAdmin()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

$C = PluginChatglpiConfig::class;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_action'])) {
    switch ($_POST['save_action']) {
        case 'salvar_geral':
            $nome = trim((string) ($_POST['nome_chat'] ?? ''));
            $C::setConfig('nome_chat', mb_substr($nome !== '' ? $nome : 'Chat', 0, 60));
            $C::setConfig('intervalo_aberto', (string) max(2, min(60, (int) ($_POST['intervalo_aberto'] ?? 4))));
            $C::setConfig('intervalo_fechado', (string) max(5, min(300, (int) ($_POST['intervalo_fechado'] ?? 20))));
            $C::setConfig('minutos_ausente', (string) max(1, min(120, (int) ($_POST['minutos_ausente'] ?? 5))));
            $C::setConfig('prazo_edicao', (string) max(0, min(1440, (int) ($_POST['prazo_edicao'] ?? 15))));
            $C::setConfig('restringir_entidades', !empty($_POST['restringir_entidades']) ? '1' : '0');
            $C::setConfig('acompanhamento_privado', !empty($_POST['acompanhamento_privado']) ? '1' : '0');
            Session::addMessageAfterRedirect('Configuração salva.', false, INFO);
            break;
        case 'salvar_anexos':
            $C::setConfig('anexos_ativos', !empty($_POST['anexos_ativos']) ? '1' : '0');
            $C::setConfig('anexo_max_mb', (string) max(1, min(100, (int) ($_POST['anexo_max_mb'] ?? 10))));
            $C::setConfig('anexo_extensoes', implode(',', array_unique(array_filter(array_map(static fn($e) => strtolower(trim($e, " .\t")), explode(',', (string) ($_POST['anexo_extensoes'] ?? '')))))));
            Session::addMessageAfterRedirect('Configuração de anexos salva.', false, INFO);
            break;
        case 'salvar_retencao':
            $C::setConfig('retencao_dias', (string) max(0, min(36500, (int) ($_POST['retencao_dias'] ?? 0))));
            Session::addMessageAfterRedirect('Retenção salva.', false, INFO);
            break;
    }
}

global $DB;
$e = [$C, 'e'];
$interruptor = static function (string $nome, bool $marcado, string $rotulo) use ($e): string {
    $id = 'chatglpi_' . $nome;
    return '<div class="form-check form-switch chatglpi-switch"><input type="hidden" name="' . $nome . '" value="0">'
        . '<input class="form-check-input" type="checkbox" role="switch" id="' . $id . '" name="' . $nome . '" value="1"' . ($marcado ? ' checked' : '') . '>'
        . '<label class="form-check-label" for="' . $id . '">' . $e($rotulo) . '</label></div>';
};
$botao = '<tr class="tab_bg_2"><td colspan="4" class="center"><button type="submit" class="btn btn-primary chatglpi-btn-salvar"><i class="ti ti-device-floppy"></i> Salvar</button></td></tr>';
$acao = $e($C::url('config.form.php'));

Html::header('Configuração do chat', $_SERVER['PHP_SELF'] ?? '', 'tools', 'PluginChatglpiMenu', 'config');
PluginChatglpiMenu::abas('config');
echo '<div class="chatglpi-config">';

echo '<form method="post" action="' . $acao . '"><input type="hidden" name="save_action" value="salvar_geral">';
echo '<table class="tab_cadre_fixe chatglpi-tabela-form"><tr class="tab_bg_2"><th colspan="4"><i class="ti ti-settings"></i> Geral</th></tr>';
echo '<tr class="tab_bg_1"><td class="chatglpi-rotulo">Nome exibido do chat</td><td><input type="text" class="form-control" name="nome_chat" maxlength="60" value="' . $e($C::getConfig('nome_chat')) . '"></td>';
echo '<td class="chatglpi-rotulo">Prazo para editar ou apagar (minutos)</td><td><input type="number" min="0" max="1440" class="form-control" name="prazo_edicao" value="' . $C::inteiro('prazo_edicao', 0, 1440) . '"><div class="chatglpi-ajuda">0 desliga a edição e a exclusão de mensagens.</div></td></tr>';
echo '<tr class="tab_bg_1"><td class="chatglpi-rotulo">Atualização com o chat aberto (segundos)</td><td><input type="number" min="2" max="60" class="form-control" name="intervalo_aberto" value="' . $C::inteiro('intervalo_aberto', 2, 60) . '"></td>';
echo '<td class="chatglpi-rotulo">Atualização com o chat fechado (segundos)</td><td><input type="number" min="5" max="300" class="form-control" name="intervalo_fechado" value="' . $C::inteiro('intervalo_fechado', 5, 300) . '"><div class="chatglpi-ajuda">Também vale quando a aba do navegador está em segundo plano.</div></td></tr>';
echo '<tr class="tab_bg_1"><td class="chatglpi-rotulo">Ficar "Ausente" após (minutos sem uso)</td><td><input type="number" min="1" max="120" class="form-control" name="minutos_ausente" value="' . $C::inteiro('minutos_ausente', 1, 120) . '"></td>';
echo '<td class="chatglpi-rotulo">Pessoas visíveis</td><td>' . $interruptor('restringir_entidades', (bool) (int) $C::getConfig('restringir_entidades'), 'Só de entidades ativas de quem usa') . '</td></tr>';
echo '<tr class="tab_bg_1"><td class="chatglpi-rotulo">Salvar no chamado</td><td colspan="3">' . $interruptor('acompanhamento_privado', (bool) (int) $C::getConfig('acompanhamento_privado'), 'Sugerir acompanhamento privado') . '</td></tr>';
echo $botao . '</table>';
Html::closeForm();

echo '<form method="post" action="' . $acao . '"><input type="hidden" name="save_action" value="salvar_anexos">';
echo '<table class="tab_cadre_fixe chatglpi-tabela-form"><tr class="tab_bg_2"><th colspan="4"><i class="ti ti-paperclip"></i> Anexos</th></tr>';
echo '<tr class="tab_bg_1"><td class="chatglpi-rotulo">Permitir anexos e imagens</td><td>' . $interruptor('anexos_ativos', (bool) (int) $C::getConfig('anexos_ativos'), 'Sim') . '</td>';
echo '<td class="chatglpi-rotulo">Tamanho máximo (MB)</td><td><input type="number" min="1" max="100" class="form-control" name="anexo_max_mb" value="' . $C::inteiro('anexo_max_mb', 1, 100) . '"><div class="chatglpi-ajuda">O limite do PHP do servidor também vale (upload_max_filesize: ' . $e(ini_get('upload_max_filesize')) . ').</div></td></tr>';
echo '<tr class="tab_bg_1"><td class="chatglpi-rotulo">Extensões permitidas</td><td colspan="3"><input type="text" class="form-control" name="anexo_extensoes" value="' . $e($C::getConfig('anexo_extensoes')) . '"><div class="chatglpi-ajuda">Separadas por vírgula. Arquivos executáveis e páginas (php, js, html, svg, exe…) são sempre recusados.</div></td></tr>';
$ocupado = (int) ($DB->request(['SELECT' => [new \Glpi\DBAL\QueryExpression('COALESCE(SUM(' . $DB->quoteName('tamanho') . '), 0) AS t')], 'FROM' => PluginChatglpiAnexo::TABELA])->current()['t'] ?? 0);
echo '<tr class="tab_bg_1"><td class="chatglpi-rotulo">Em uso</td><td colspan="3">' . countElementsInTable(PluginChatglpiAnexo::TABELA) . ' anexo(s) · ' . $e(PluginChatglpiAnexo::tamanhoLegivel($ocupado)) . '</td></tr>';
echo $botao . '</table>';
Html::closeForm();

echo '<form method="post" action="' . $acao . '"><input type="hidden" name="save_action" value="salvar_retencao">';
echo '<table class="tab_cadre_fixe chatglpi-tabela-form"><tr class="tab_bg_2"><th colspan="4"><i class="ti ti-history"></i> Retenção</th></tr>';
echo '<tr class="tab_bg_1"><td class="chatglpi-rotulo">Apagar mensagens com mais de (dias)</td><td><input type="number" min="0" max="36500" class="form-control" name="retencao_dias" value="' . $C::inteiro('retencao_dias', 0, 36500) . '"><div class="chatglpi-ajuda">0 guarda para sempre. A limpeza é feita pela tarefa automática "ChatglpiManutencao" (de hora em hora).</div></td>';
echo '<td class="chatglpi-rotulo">Mensagens guardadas</td><td>' . number_format(countElementsInTable(PluginChatglpiMensagem::TABELA), 0, ',', '.') . '</td></tr>';
echo $botao . '</table>';
Html::closeForm();

echo '<p class="text-muted chatglpi-dica"><i class="ti ti-info-circle"></i> Quem pode usar o chat e gerenciar salas é definido pelo direito nativo "Chat", na aba de mesmo nome em Administração › Perfis.</p>';
echo '</div>';
Html::footer();
