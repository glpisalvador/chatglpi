<?php

/**
 * Plugin Chat GLPI - configurações (chave/valor), direito nativo e utilitários
 */
class PluginChatglpiConfig extends CommonDBTM
{
    // $rightname não é redeclarada: é tipada (string) no GLPI 12 e sem tipo no 11.

    public const TABELA = 'glpi_plugin_chatglpi_configs';
    public const DIREITO = 'plugin_chatglpi';

    public static function getTypeName($nb = 0): string
    {
        return 'Chat GLPI';
    }

    public static function getTable($classname = null)
    {
        return self::TABELA;
    }

    public static function canView(): bool
    {
        return self::ehAdmin();
    }

    public static function canCreate(): bool
    {
        return self::ehAdmin();
    }

    public static function canUpdate(): bool
    {
        return self::ehAdmin();
    }

    public static function canDelete(): bool
    {
        return self::ehAdmin();
    }

    public static function canPurge(): bool
    {
        return self::ehAdmin();
    }

    public static function ehAdmin(): bool
    {
        return (bool) Session::getLoginUserID() && Session::haveRight('config', UPDATE);
    }

    public static function podeUsar(): bool
    {
        return (bool) Session::getLoginUserID() && Session::haveRight(self::DIREITO, READ);
    }

    public static function podeGerenciarSalas(): bool
    {
        return (bool) Session::getLoginUserID() && (self::ehAdmin() || Session::haveRight(self::DIREITO, UPDATE));
    }

    // =====================================================================
    // Chave/valor
    // =====================================================================

    public static function padroes(): array
    {
        return [
            'nome_chat'          => 'Chat',
            'intervalo_aberto'   => '4',
            'intervalo_fechado'  => '20',
            'minutos_ausente'    => '5',
            'segundos_online'    => '90',
            'prazo_edicao'       => '15',
            'retencao_dias'      => '0',
            'anexos_ativos'      => '1',
            'anexo_max_mb'       => '10',
            'anexo_extensoes'    => 'pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,jpg,jpeg,png,gif,webp,zip,rar,7z,log',
            'restringir_entidades' => '0',
            'acompanhamento_privado' => '1',
        ];
    }

    private static ?array $chatCache = null;

    public static function getConfig(string $name, $default = null)
    {
        global $DB;
        if (self::$chatCache === null) {
            self::$chatCache = [];
            if ($DB->tableExists(self::TABELA)) {
                foreach ($DB->request(['SELECT' => ['name', 'value'], 'FROM' => self::TABELA]) as $row) {
                    self::$chatCache[$row['name']] = $row['value'];
                }
            }
        }
        if (array_key_exists($name, self::$chatCache)) {
            return self::$chatCache[$name];
        }
        return $default ?? (self::padroes()[$name] ?? null);
    }

    public static function setConfig(string $name, $value): bool
    {
        global $DB;
        if (count($DB->request(['FROM' => self::TABELA, 'WHERE' => ['name' => $name], 'LIMIT' => 1])) > 0) {
            $ok = (bool) $DB->update(self::TABELA, ['value' => $value], ['name' => $name]);
        } else {
            $ok = (bool) $DB->insert(self::TABELA, ['name' => $name, 'value' => $value]);
        }
        self::$chatCache = null;
        return $ok;
    }

    public static function getAllConfigs(): array
    {
        global $DB;
        $todas = [];
        foreach ($DB->request(['FROM' => self::TABELA]) as $row) {
            $todas[$row['name']] = $row['value'];
        }
        return $todas;
    }

    public static function getArrayConfig(string $name): array
    {
        $lista = json_decode((string) self::getConfig($name), true);
        return is_array($lista) ? $lista : [];
    }

    public static function setArrayConfig(string $name, array $value): bool
    {
        return self::setConfig($name, json_encode(array_values($value)));
    }

    public static function inteiro(string $name, int $min, int $max): int
    {
        return max($min, min($max, (int) self::getConfig($name)));
    }

    public static function extensoes(): array
    {
        $lista = array_filter(array_map(static fn($e) => strtolower(trim($e, " .\t")), explode(',', (string) self::getConfig('anexo_extensoes'))));
        return array_values(array_unique(array_filter($lista, static fn($e) => preg_match('/^[a-z0-9]{1,10}$/', $e) && !in_array($e, ['php', 'phtml', 'phar', 'js', 'html', 'htm', 'svg', 'exe', 'sh', 'bat'], true))));
    }

    /** Limite efetivo de anexo em bytes: o menor entre a configuração e os limites do PHP */
    public static function limiteAnexo(): int
    {
        $bytes = static function ($v): int {
            $v = trim((string) $v);
            $n = (float) $v;
            $u = strtolower(substr($v, -1));
            return (int) ($n * ($u === 'g' ? 1073741824 : ($u === 'm' ? 1048576 : ($u === 'k' ? 1024 : 1))));
        };
        $limites = [self::inteiro('anexo_max_mb', 1, 100) * 1048576];
        foreach (['upload_max_filesize', 'post_max_size'] as $ini) {
            $b = $bytes(ini_get($ini));
            if ($b > 0) {
                $limites[] = $b;
            }
        }
        return min($limites);
    }

    /** Configuração entregue ao navegador na primeira sincronização */
    public static function paraNavegador(): array
    {
        return [
            'nome'             => (string) self::getConfig('nome_chat'),
            'intervaloAberto'  => self::inteiro('intervalo_aberto', 2, 60),
            'intervaloFechado' => self::inteiro('intervalo_fechado', 5, 300),
            'minutosAusente'   => self::inteiro('minutos_ausente', 1, 120),
            'prazoEdicao'      => self::inteiro('prazo_edicao', 0, 1440),
            'anexos'           => (bool) (int) self::getConfig('anexos_ativos'),
            'anexoMaxMb'       => round(self::limiteAnexo() / 1048576, 1),
            'extensoes'        => self::extensoes(),
            'gerenciaSalas'    => self::podeGerenciarSalas(),
            'privadoPadrao'    => (bool) (int) self::getConfig('acompanhamento_privado'),
            'paginaChat'       => self::url('chat.php'),
            'paginaSalas'      => self::url('sala.php'),
        ];
    }

    // =====================================================================
    // Utilitários
    // =====================================================================

    public static function e($texto): string
    {
        return htmlspecialchars((string) $texto, ENT_QUOTES, 'UTF-8');
    }

    public static function url(string $arquivo, array $params = []): string
    {
        global $CFG_GLPI;
        return $CFG_GLPI['root_doc'] . '/plugins/chatglpi/front/' . $arquivo . ($params ? '?' . http_build_query($params) : '');
    }

    /** GLPI 11 exige token CSRF nos POST; no 12 a proteção é por cabeçalho e o token foi removido */
    public static function tokenCsrf(): string
    {
        // Sem sessão aberta (sincronização libera a sessão cedo) o token não seria guardado
        return (version_compare(GLPI_VERSION, '12.0.0-dev', '<') && session_status() === PHP_SESSION_ACTIVE) ? Session::getNewCSRFToken() : '';
    }

    public static function pastaAnexos(): string
    {
        return GLPI_PLUGIN_DOC_DIR . '/chatglpi/anexos';
    }

    /** Nome curto do usuário para o chat ("Nome Sobrenome", ou o login) */
    public static function nomeUsuario(int $id): string
    {
        static $cache = [];
        if (!isset($cache[$id])) {
            $u = new User();
            if ($id > 0 && $u->getFromDB($id)) {
                $nome = trim(($u->fields['firstname'] ?? '') . ' ' . ($u->fields['realname'] ?? ''));
                $cache[$id] = $nome !== '' ? $nome : (string) $u->fields['name'];
            } else {
                $cache[$id] = 'Usuário #' . $id;
            }
        }
        return $cache[$id];
    }

    /** Iniciais para o avatar */
    public static function iniciais(string $nome): string
    {
        $partes = preg_split('/\s+/u', trim($nome)) ?: [];
        $ini = mb_substr($partes[0] ?? '?', 0, 1);
        if (count($partes) > 1) {
            $ini .= mb_substr(end($partes), 0, 1);
        }
        return mb_strtoupper($ini);
    }

    /** URL da foto do usuário, quando houver */
    public static function foto(int $id): string
    {
        static $cache = [];
        if (!array_key_exists($id, $cache)) {
            $u = new User();
            $cache[$id] = ($u->getFromDB($id) && !empty($u->fields['picture'])) ? User::getThumbnailURLForPicture($u->fields['picture']) : '';
        }
        return (string) $cache[$id];
    }
}
