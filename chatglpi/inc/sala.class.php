<?php

/**
 * Plugin Chat GLPI - salas (item nativo com formulário, busca e histórico)
 * Membros: os do grupo vinculado e os escolhidos manualmente. Sala sem grupo e sem membros é aberta a todos do chat.
 */
class PluginChatglpiSala extends CommonDBTM
{
    // $rightname e $dohistory não são redeclaradas (tipadas no GLPI 12); o histórico é ligado no construtor.

    public const TABELA = 'glpi_plugin_chatglpi_salas';

    public function __construct()
    {
        parent::__construct();
        $this->dohistory = true;
    }

    public static function getTypeName($nb = 0): string
    {
        return $nb > 1 ? 'Salas do chat' : 'Sala do chat';
    }

    public static function getTable($classname = null)
    {
        return self::TABELA;
    }

    public static function getIcon()
    {
        return 'ti ti-messages';
    }

    public static function canView(): bool
    {
        return PluginChatglpiConfig::podeGerenciarSalas();
    }

    public static function canCreate(): bool
    {
        return PluginChatglpiConfig::podeGerenciarSalas();
    }

    public static function canUpdate(): bool
    {
        return PluginChatglpiConfig::podeGerenciarSalas();
    }

    public static function canDelete(): bool
    {
        return PluginChatglpiConfig::podeGerenciarSalas();
    }

    public static function canPurge(): bool
    {
        return PluginChatglpiConfig::podeGerenciarSalas();
    }

    public function canViewItem(): bool
    {
        return self::canView();
    }

    public function canCreateItem(): bool
    {
        return self::canCreate();
    }

    public function canUpdateItem(): bool
    {
        return self::canUpdate();
    }

    public function canDeleteItem(): bool
    {
        return self::canDelete();
    }

    public function canPurgeItem(): bool
    {
        return self::canPurge();
    }

    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs);
        $this->addStandardTab('Log', $tabs, $options);
        return $tabs;
    }

    public function rawSearchOptions()
    {
        return [
            ['id' => 'common', 'name' => self::getTypeName(1)],
            ['id' => 1, 'table' => self::TABELA, 'field' => 'name', 'name' => __('Name'), 'datatype' => 'itemlink', 'massiveaction' => false],
            ['id' => 2, 'table' => self::TABELA, 'field' => 'id', 'name' => 'ID', 'datatype' => 'number', 'massiveaction' => false],
            ['id' => 3, 'table' => 'glpi_groups', 'field' => 'completename', 'linkfield' => 'groups_id', 'name' => 'Grupo vinculado', 'datatype' => 'dropdown'],
            ['id' => 4, 'table' => self::TABELA, 'field' => 'is_active', 'name' => __('Active'), 'datatype' => 'bool'],
            ['id' => 5, 'table' => self::TABELA, 'field' => 'comment', 'name' => 'Descrição', 'datatype' => 'text', 'htmltext' => true],
            ['id' => 6, 'table' => self::TABELA, 'field' => 'date_creation', 'name' => __('Creation date'), 'datatype' => 'datetime', 'massiveaction' => false],
            ['id' => 7, 'table' => self::TABELA, 'field' => 'date_mod', 'name' => __('Last update'), 'datatype' => 'datetime', 'massiveaction' => false],
        ];
    }

    public static function obterLinha(int $id): ?array
    {
        global $DB;
        return $DB->request(['FROM' => self::TABELA, 'WHERE' => ['id' => $id], 'LIMIT' => 1])->current() ?: null;
    }

    /** Membros escolhidos manualmente (participantes marcados como membro na conversa da sala) */
    public static function membrosManuais(int $sala): array
    {
        global $DB;
        $conversa = PluginChatglpiConversa::daSala($sala);
        return array_map('intval', array_column(iterator_to_array($DB->request(['SELECT' => ['users_id'], 'FROM' => PluginChatglpiConversa::PARTICIPANTES, 'WHERE' => ['conversas_id' => $conversa, 'is_membro' => 1]]), false), 'users_id'));
    }

    public static function ehAberta(array $s): bool
    {
        return (int) $s['groups_id'] === 0 && count(self::membrosManuais((int) $s['id'])) === 0;
    }

    public static function membros(int $sala): array
    {
        global $DB;
        $s = self::obterLinha($sala);
        if (!$s) {
            return [];
        }
        if (self::ehAberta($s)) {
            return PluginChatglpiPresenca::usuariosDoChat();
        }
        $ids = self::membrosManuais($sala);
        if ((int) $s['groups_id'] > 0) {
            foreach ($DB->request(['SELECT' => ['users_id'], 'FROM' => 'glpi_groups_users', 'WHERE' => ['groups_id' => (int) $s['groups_id']]]) as $r) {
                $ids[] = (int) $r['users_id'];
            }
        }
        return array_values(array_unique($ids));
    }

    public static function usuarioAcessa(int $sala, int $usuario): bool
    {
        global $DB;
        $s = self::obterLinha($sala);
        if (!$s || !(int) $s['is_active']) {
            return false;
        }
        if (self::ehAberta($s)) {
            return true;
        }
        if (in_array($usuario, self::membrosManuais($sala), true)) {
            return true;
        }
        return (int) $s['groups_id'] > 0 && count($DB->request(['FROM' => 'glpi_groups_users', 'WHERE' => ['groups_id' => (int) $s['groups_id'], 'users_id' => $usuario], 'LIMIT' => 1])) > 0;
    }

    public static function salasDoUsuario(int $usuario): array
    {
        global $DB;
        $lista = [];
        foreach ($DB->request(['FROM' => self::TABELA, 'WHERE' => ['is_active' => 1], 'ORDER' => 'name']) as $s) {
            if (self::usuarioAcessa((int) $s['id'], $usuario)) {
                $lista[] = $s;
            }
        }
        return $lista;
    }

    // =====================================================================
    // Formulário nativo
    // =====================================================================

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);
        $this->showFormHeader($options);
        $e = [PluginChatglpiConfig::class, 'e'];

        echo '<tr class="tab_bg_1"><td>' . __('Name') . ' <span class="required">*</span></td><td>';
        echo '<input type="text" class="form-control" name="name" maxlength="255" required value="' . $e($this->fields['name'] ?? '') . '">';
        echo '</td><td>' . __('Active') . '</td><td>';
        Dropdown::showYesNo('is_active', $this->isNewItem() ? 1 : (int) $this->fields['is_active']);
        echo '</td></tr>';

        echo '<tr class="tab_bg_1"><td>Grupo vinculado</td><td>';
        Group::dropdown(['name' => 'groups_id', 'value' => (int) ($this->fields['groups_id'] ?? 0), 'entity' => $_SESSION['glpiactiveentities'] ?? [], 'width' => '100%']);
        echo '<div class="chatglpi-ajuda">Os membros do grupo entram na sala automaticamente.</div>';
        echo '</td><td>Outros membros</td><td>';
        User::dropdown([
            'name'     => '_membros',
            'multiple' => true,
            'value'    => $this->isNewItem() ? [] : self::membrosManuais((int) $ID),
            'right'    => 'all',
            'entity'   => $_SESSION['glpiactiveentities'] ?? [],
            'width'    => '100%',
        ]);
        echo '<input type="hidden" name="_membros_enviados" value="1">';
        echo '</td></tr>';

        echo '<tr class="tab_bg_1"><td>Descrição</td><td colspan="3">';
        Html::textarea(['name' => 'comment', 'value' => $this->fields['comment'] ?? '', 'enable_richtext' => true, 'cols' => 100, 'rows' => 5]);
        echo '</td></tr>';

        echo '<tr class="tab_bg_1"><td colspan="4"><p class="text-muted chatglpi-dica"><i class="ti ti-info-circle"></i> Sem grupo e sem outros membros, a sala fica aberta a todos que usam o chat. Desativar a sala a esconde do chat sem apagar as mensagens.</p></td></tr>';

        $this->showFormButtons($options);
        return true;
    }

    public function prepareInputForAdd($input)
    {
        return $this->validar($input);
    }

    public function prepareInputForUpdate($input)
    {
        return $this->validar($input);
    }

    private function validar($input)
    {
        if (isset($input['name'])) {
            $input['name'] = trim((string) $input['name']);
            if ($input['name'] === '') {
                Session::addMessageAfterRedirect('Informe o nome da sala.', false, ERROR);
                return false;
            }
        } elseif ($this->isNewItem()) {
            Session::addMessageAfterRedirect('Informe o nome da sala.', false, ERROR);
            return false;
        }
        if ($this->isNewItem()) {
            $input['users_id'] = (int) Session::getLoginUserID();
        }
        return $input;
    }

    public function post_addItem()
    {
        PluginChatglpiConversa::daSala((int) $this->getID());
        $this->gravarMembros();
    }

    public function post_updateItem($history = true)
    {
        $this->gravarMembros();
    }

    private function gravarMembros(): void
    {
        global $DB;
        if (empty($this->input['_membros_enviados']) && !isset($this->input['_membros'])) {
            return;
        }
        $novos = array_values(array_unique(array_filter(array_map('intval', (array) ($this->input['_membros'] ?? [])))));
        $conversa = PluginChatglpiConversa::daSala((int) $this->getID());
        $DB->update(PluginChatglpiConversa::PARTICIPANTES, ['is_membro' => 0], ['conversas_id' => $conversa, 'is_membro' => 1] + ($novos ? ['NOT' => ['users_id' => $novos]] : []));
        foreach ($novos as $u) {
            PluginChatglpiConversa::garantirParticipante($conversa, $u, true);
        }
    }

    /** Ao excluir de vez: apaga a conversa da sala, as mensagens e os anexos */
    public function cleanDBonPurge()
    {
        global $DB;
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => PluginChatglpiConversa::TABELA, 'WHERE' => ['tipo' => 'sala', 'salas_id' => (int) $this->getID()]]) as $c) {
            PluginChatglpiManutencao::apagarConversa((int) $c['id']);
        }
    }
}
