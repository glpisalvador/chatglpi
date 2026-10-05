<?php

/**
 * Plugin Chat GLPI - integração com chamados: salvar a conversa como acompanhamento, abrir chamado e aba "Chat"
 */
class PluginChatglpiChamado extends CommonGLPI
{
    public const TABELA = 'glpi_plugin_chatglpi_chamados';

    public static function getTypeName($nb = 0): string
    {
        return 'Chat';
    }

    // ------------------------------------------------------------------ aba no chamado

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if (!$item instanceof Ticket || $item->isNewItem() || !PluginChatglpiConfig::podeUsar()) {
            return '';
        }
        $n = 0;
        if ($_SESSION['glpishow_count_on_tabs'] ?? false) {
            $n = (int) countElementsInTable(self::TABELA, ['tickets_id' => (int) $item->getID()]);
        }
        return self::createTabEntry('Chat', $n, null, 'ti ti-messages');
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        global $DB;
        if (!$item instanceof Ticket) {
            return true;
        }
        $e = [PluginChatglpiConfig::class, 'e'];
        $usuario = (int) Session::getLoginUserID();
        $linhas = iterator_to_array($DB->request(['FROM' => self::TABELA, 'WHERE' => ['tickets_id' => (int) $item->getID()], 'ORDER' => 'id DESC']), false);
        echo '<div class="chatglpi-aba-chamado">';
        echo '<div class="card chatglpi-card"><div class="card-header chatglpi-card-header"><h5><i class="ti ti-messages"></i> Conversas do chat neste chamado</h5></div><div class="card-body p-0">';
        if (!$linhas) {
            echo '<div class="chatglpi-vazio"><i class="ti ti-mood-empty"></i> Nenhuma conversa foi salva neste chamado. No chat, use "Salvar no chamado" ou "Abrir chamado".</div>';
        } else {
            echo '<div class="table-responsive"><table class="table table-hover table-sm chatglpi-tabela"><thead><tr><th>Data</th><th>Por</th><th>Ação</th><th>Conversa</th><th class="text-end">Mensagens</th><th class="text-end"></th></tr></thead><tbody>';
            foreach ($linhas as $l) {
                $c = PluginChatglpiConversa::obter((int) $l['conversas_id']);
                $acesso = $c && PluginChatglpiConversa::temAcesso((int) $c['id'], $usuario);
                echo '<tr><td class="text-nowrap">' . $e(Html::convDateTime($l['date_creation'])) . '</td>';
                echo '<td>' . $e(PluginChatglpiConfig::nomeUsuario((int) $l['users_id'])) . '</td>';
                echo '<td>' . ($l['acao'] === 'abrir' ? '<span class="chatglpi-pill chatglpi-pill-info">Chamado aberto pelo chat</span>' : '<span class="chatglpi-pill">Conversa salva</span>') . '</td>';
                echo '<td>' . ($c ? $e(($c['tipo'] === 'sala' ? 'Sala ' : 'Conversa com ') . ($acesso ? PluginChatglpiConversa::titulo($c, $usuario) : 'outros usuários')) : '<span class="text-muted">removida</span>') . '</td>';
                echo '<td class="text-end">' . (int) $l['quantidade'] . '</td><td class="text-end">';
                if ($acesso) {
                    echo '<a class="btn btn-sm btn-outline-secondary" href="' . $e(PluginChatglpiConfig::url('chat.php', ['conversa' => (int) $c['id']])) . '"><i class="ti ti-messages"></i> Abrir no chat</a>';
                }
                echo '</td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</div></div></div>';
        return true;
    }

    // ------------------------------------------------------------------ salvar no chamado

    /** @return array{0: bool, 1: string, 2: int} */
    public static function salvar(int $conversa, int $usuario, int $ticketId, int $quantidade, bool $privado): array
    {
        global $DB;
        $ticket = new Ticket();
        if ($ticketId <= 0 || !$ticket->getFromDB($ticketId) || (int) $ticket->fields['is_deleted']) {
            return [false, 'Chamado #' . $ticketId . ' não encontrado.', 0];
        }
        if (!$ticket->can($ticketId, READ)) {
            return [false, 'Você não tem acesso ao chamado #' . $ticketId . '.', 0];
        }
        $quantidade = max(1, min(500, $quantidade));
        $mensagens = array_values(array_filter(PluginChatglpiMensagem::listar($conversa, $usuario, 0, 0, $quantidade), static fn($m) => !$m['apagada']));
        if (!$mensagens) {
            return [false, 'Não há mensagens para salvar.', 0];
        }
        $c = PluginChatglpiConversa::obter($conversa);
        $titulo = ($c['tipo'] === 'sala' ? 'Sala "' : 'Conversa com ') . PluginChatglpiConversa::titulo($c, $usuario) . ($c['tipo'] === 'sala' ? '"' : '');
        $conteudo = '<p><strong>Conversa do chat</strong> · ' . htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8') . ' · ' . count($mensagens) . ' mensagem(ns)</p>' . PluginChatglpiMensagem::paraChamado($mensagens);
        $input = ['itemtype' => 'Ticket', 'items_id' => $ticketId, 'content' => $conteudo, 'is_private' => $privado ? 1 : 0, 'users_id' => $usuario];
        $f = new ITILFollowup();
        if (!$f->can(-1, CREATE, $input)) {
            return [false, 'Você não pode adicionar acompanhamentos ao chamado #' . $ticketId . '.', 0];
        }
        $fid = (int) $f->add($input);
        if ($fid <= 0) {
            return [false, 'O GLPI não aceitou o acompanhamento.', 0];
        }
        $DB->insert(self::TABELA, ['tickets_id' => $ticketId, 'conversas_id' => $conversa, 'itilfollowups_id' => $fid, 'users_id' => $usuario, 'acao' => 'salvar', 'quantidade' => count($mensagens)]);
        PluginChatglpiMensagem::enviar($conversa, $usuario, 'salvou ' . count($mensagens) . ' mensagem(ns) no chamado #' . $ticketId, 0, 'evento');
        return [true, count($mensagens) . ' mensagem(ns) salvas no chamado #' . $ticketId . '.', $fid];
    }

    // ------------------------------------------------------------------ abrir chamado

    /** Entidades em que o usuário pode abrir chamado (sem as filhas, como nos demais seletores) */
    public static function entidades(): array
    {
        global $DB;
        $ativas = array_values(array_map('intval', $_SESSION['glpiactiveentities'] ?? []));
        if (!$ativas) {
            return [];
        }
        $todas = [];
        foreach ($DB->request(['SELECT' => ['id', 'completename', 'entities_id'], 'FROM' => 'glpi_entities', 'WHERE' => ['id' => $ativas], 'ORDER' => 'completename']) as $r) {
            $todas[] = ['id' => (int) $r['id'], 'nome' => html_entity_decode((string) $r['completename'], ENT_QUOTES, 'UTF-8'), 'filha' => (int) $r['id'] > 0 && (int) $r['entities_id'] > 0];
        }
        $maes = array_values(array_filter($todas, static fn($x) => !$x['filha']));
        return array_map(static fn($x) => ['id' => $x['id'], 'nome' => $x['nome']], $maes ?: $todas);
    }

    /** @return array{0: bool, 1: string, 2: int} */
    public static function abrir(int $conversa, int $usuario, array $in): array
    {
        global $DB;
        $titulo = trim((string) ($in['titulo'] ?? ''));
        $descricao = trim((string) ($in['descricao'] ?? ''));
        $entidade = (int) ($in['entidade'] ?? -1);
        $incluir = max(0, min(200, (int) ($in['incluir'] ?? 0)));
        if ($titulo === '') {
            return [false, 'Informe o título do chamado.', 0];
        }
        if (!in_array($entidade, array_column(self::entidades(), 'id'), true)) {
            return [false, 'Escolha a entidade do chamado.', 0];
        }
        $c = PluginChatglpiConversa::obter($conversa);
        $requerente = (int) ($in['requerente'] ?? 0);
        if ($requerente <= 0 || !in_array($requerente, PluginChatglpiConversa::membros($conversa), true)) {
            $requerente = $c['tipo'] === 'direta' ? PluginChatglpiConversa::outro($c, $usuario) : $usuario;
        }
        $conteudo = $descricao !== '' ? '<p>' . nl2br(htmlspecialchars($descricao, ENT_QUOTES, 'UTF-8'), false) . '</p>' : '';
        $mensagens = [];
        if ($incluir > 0) {
            $mensagens = array_values(array_filter(PluginChatglpiMensagem::listar($conversa, $usuario, 0, 0, $incluir), static fn($m) => !$m['apagada'] && $m['tipo'] === 'texto'));
            if ($mensagens) {
                $conteudo .= '<p><strong>Conversa do chat</strong></p>' . PluginChatglpiMensagem::paraChamado($mensagens);
            }
        }
        if ($conteudo === '') {
            $conteudo = '<p>' . htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8') . '</p>';
        }
        $input = [
            'name'        => mb_substr($titulo, 0, 250),
            'content'     => $conteudo,
            'entities_id' => $entidade,
            '_actors'     => ['requester' => [['itemtype' => 'User', 'items_id' => $requerente, 'use_notification' => 1]]],
        ];
        $t = new Ticket();
        if (!$t->can(-1, CREATE, $input)) {
            return [false, 'Você não pode abrir chamados nessa entidade.', 0];
        }
        $id = (int) $t->add($input);
        if ($id <= 0) {
            return [false, 'O GLPI não aceitou o chamado (verifique os campos obrigatórios).', 0];
        }
        $DB->insert(self::TABELA, ['tickets_id' => $id, 'conversas_id' => $conversa, 'users_id' => $usuario, 'acao' => 'abrir', 'quantidade' => count($mensagens)]);
        PluginChatglpiMensagem::enviar($conversa, $usuario, 'abriu o chamado #' . $id . ' — ' . mb_substr($titulo, 0, 200), 0, 'evento');
        return [true, 'Chamado #' . $id . ' aberto.', $id];
    }
}
