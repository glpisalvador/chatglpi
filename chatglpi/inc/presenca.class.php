<?php

use Glpi\DBAL\QueryExpression;

/**
 * Plugin Chat GLPI - presença (online, ausente, ocupado, invisível), "digitando" e preferências
 */
class PluginChatglpiPresenca extends CommonGLPI
{
    public const TABELA = 'glpi_plugin_chatglpi_presencas';

    public static function getTypeName($nb = 0): string
    {
        return 'Presença';
    }

    public static function statusPossiveis(): array
    {
        return [
            'online'    => ['Online', 'ti ti-circle-filled'],
            'ausente'   => ['Ausente', 'ti ti-clock'],
            'ocupado'   => ['Ocupado', 'ti ti-minus'],
            'invisivel' => ['Invisível', 'ti ti-eye-off'],
            'offline'   => ['Offline', 'ti ti-circle'],
        ];
    }

    public static function obter(int $usuario): array
    {
        global $DB;
        $linha = $DB->request(['FROM' => self::TABELA, 'WHERE' => ['users_id' => $usuario], 'LIMIT' => 1])->current();
        return $linha ?: ['users_id' => $usuario, 'status' => 'online', 'ultimo_sinal' => null, 'ultima_atividade' => null, 'digitando_conversa' => 0, 'digitando_ate' => null, 'notificar_navegador' => 1, 'tocar_som' => 0];
    }

    private static function gravar(int $usuario, array $dados): void
    {
        global $DB;
        if (count($DB->request(['FROM' => self::TABELA, 'WHERE' => ['users_id' => $usuario], 'LIMIT' => 1])) > 0) {
            $DB->update(self::TABELA, $dados, ['users_id' => $usuario]);
        } else {
            $DB->insert(self::TABELA, $dados + ['users_id' => $usuario]);
        }
    }

    /** Sinal de vida enviado a cada sincronização */
    public static function sinal(int $usuario, bool $ativo, int $digitando = 0): void
    {
        $agora = date('Y-m-d H:i:s');
        $dados = ['ultimo_sinal' => $agora];
        if ($ativo) {
            $dados['ultima_atividade'] = $agora;
        }
        if ($digitando > 0) {
            $dados['digitando_conversa'] = $digitando;
            $dados['digitando_ate'] = date('Y-m-d H:i:s', time() + 6);
        } else {
            $dados['digitando_conversa'] = 0;
        }
        $atual = self::obter($usuario);
        if (empty($atual['ultima_atividade']) && !$ativo) {
            $dados['ultima_atividade'] = $agora;
        }
        self::gravar($usuario, $dados);
    }

    public static function definirStatus(int $usuario, string $status): bool
    {
        if (!in_array($status, ['online', 'ocupado', 'invisivel'], true)) {
            return false;
        }
        self::gravar($usuario, ['status' => $status, 'ultima_atividade' => date('Y-m-d H:i:s'), 'ultimo_sinal' => date('Y-m-d H:i:s')]);
        return true;
    }

    public static function preferencias(int $usuario, array $p): void
    {
        $dados = [];
        foreach (['notificar_navegador', 'tocar_som'] as $c) {
            if (array_key_exists($c, $p)) {
                $dados[$c] = (int) (bool) $p[$c];
            }
        }
        if ($dados) {
            self::gravar($usuario, $dados);
        }
    }

    /** Status visto pelos outros */
    public static function efetivo(array $p, bool $paraSiMesmo = false): string
    {
        $limiteOnline = PluginChatglpiConfig::inteiro('segundos_online', 30, 3600);
        if (empty($p['ultimo_sinal']) || strtotime((string) $p['ultimo_sinal']) < time() - $limiteOnline) {
            return 'offline';
        }
        $status = (string) ($p['status'] ?? 'online');
        if ($status === 'invisivel') {
            return $paraSiMesmo ? 'invisivel' : 'offline';
        }
        if ($status === 'online' && !empty($p['ultima_atividade']) && strtotime((string) $p['ultima_atividade']) < time() - 60 * PluginChatglpiConfig::inteiro('minutos_ausente', 1, 120)) {
            return 'ausente';
        }
        return $status;
    }

    /** IDs de usuários ativos que podem usar o chat (opcionalmente nas entidades ativas de quem pergunta) */
    public static function usuariosDoChat(): array
    {
        global $DB;
        $where = [
            'glpi_users.is_active'  => 1,
            'glpi_users.is_deleted' => 0,
            'glpi_profilerights.name' => PluginChatglpiConfig::DIREITO,
            new QueryExpression($DB->quoteName('glpi_profilerights.rights') . ' & ' . READ . ' = ' . READ),
        ];
        if ((int) PluginChatglpiConfig::getConfig('restringir_entidades') && !empty($_SESSION['glpiactiveentities'])) {
            $where['glpi_profiles_users.entities_id'] = array_values(array_map('intval', $_SESSION['glpiactiveentities']));
        }
        $ids = [];
        foreach ($DB->request([
            'SELECT'     => ['glpi_users.id'],
            'DISTINCT'   => true,
            'FROM'       => 'glpi_users',
            'INNER JOIN' => [
                'glpi_profiles_users' => ['ON' => ['glpi_profiles_users' => 'users_id', 'glpi_users' => 'id']],
                'glpi_profilerights'  => ['ON' => ['glpi_profilerights' => 'profiles_id', 'glpi_profiles_users' => 'profiles_id']],
            ],
            'WHERE'      => $where,
        ]) as $r) {
            $ids[] = (int) $r['id'];
        }
        return $ids;
    }

    public static function podeConversarCom(int $outro): bool
    {
        return $outro > 0 && $outro !== (int) Session::getLoginUserID() && in_array($outro, self::usuariosDoChat(), true);
    }

    /** Pessoas para a lista: busca, grupo; online primeiro */
    public static function pessoas(int $usuario, string $busca = '', int $grupo = 0, int $limite = 300): array
    {
        global $DB;
        $ids = array_values(array_diff(self::usuariosDoChat(), [$usuario]));
        if ($grupo > 0) {
            $membros = array_map('intval', array_column(iterator_to_array($DB->request(['SELECT' => ['users_id'], 'FROM' => 'glpi_groups_users', 'WHERE' => ['groups_id' => $grupo]]), false), 'users_id'));
            $ids = array_values(array_intersect($ids, $membros));
        }
        if (!$ids) {
            return [];
        }
        $presencas = [];
        foreach ($DB->request(['FROM' => self::TABELA, 'WHERE' => ['users_id' => $ids]]) as $p) {
            $presencas[(int) $p['users_id']] = $p;
        }
        $termo = mb_strtolower(trim($busca));
        $ordem = ['online' => 0, 'ocupado' => 1, 'ausente' => 2, 'offline' => 3];
        $lista = [];
        foreach ($ids as $id) {
            $nome = PluginChatglpiConfig::nomeUsuario($id);
            if ($termo !== '' && !str_contains(mb_strtolower($nome), $termo)) {
                continue;
            }
            $status = isset($presencas[$id]) ? self::efetivo($presencas[$id]) : 'offline';
            $lista[] = [
                'id'     => $id,
                'nome'   => $nome,
                'ini'    => PluginChatglpiConfig::iniciais($nome),
                'foto'   => PluginChatglpiConfig::foto($id),
                'status' => $status,
                'visto'  => ($status === 'offline' && !empty($presencas[$id]['ultimo_sinal']) && ($presencas[$id]['status'] ?? '') !== 'invisivel') ? Html::convDateTime($presencas[$id]['ultimo_sinal']) : '',
            ];
        }
        usort($lista, static fn($a, $b) => [$ordem[$a['status']] ?? 9, mb_strtolower($a['nome'])] <=> [$ordem[$b['status']] ?? 9, mb_strtolower($b['nome'])]);
        return array_slice($lista, 0, $limite);
    }

    public static function statusDe(int $usuario): string
    {
        $p = self::obter($usuario);
        return self::efetivo($p);
    }

    /** Nomes de quem está digitando na conversa agora */
    public static function digitando(int $conversa, int $exceto): array
    {
        global $DB;
        $nomes = [];
        foreach ($DB->request(['SELECT' => ['users_id'], 'FROM' => self::TABELA, 'WHERE' => ['digitando_conversa' => $conversa, 'digitando_ate' => ['>=', date('Y-m-d H:i:s')], 'NOT' => ['users_id' => $exceto]]]) as $r) {
            $nomes[] = PluginChatglpiConfig::nomeUsuario((int) $r['users_id']);
        }
        return $nomes;
    }
}
