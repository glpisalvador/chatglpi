<?php

use Glpi\DBAL\QueryExpression;

/**
 * Plugin Chat GLPI - conversas diretas e de salas, participantes, leitura e acesso
 */
class PluginChatglpiConversa extends CommonGLPI
{
    public const TABELA = 'glpi_plugin_chatglpi_conversas';
    public const PARTICIPANTES = 'glpi_plugin_chatglpi_participantes';

    public static function getTypeName($nb = 0): string
    {
        return $nb > 1 ? 'Conversas' : 'Conversa';
    }

    public static function obter(int $id): ?array
    {
        global $DB;
        $linha = $DB->request(['FROM' => self::TABELA, 'WHERE' => ['id' => $id], 'LIMIT' => 1])->current();
        return $linha ?: null;
    }

    /** Conversa direta entre dois usuários (cria se não existir) */
    public static function direta(int $a, int $b): int
    {
        global $DB;
        if ($a <= 0 || $b <= 0 || $a === $b) {
            return 0;
        }
        $chave = min($a, $b) . '-' . max($a, $b);
        $linha = $DB->request(['SELECT' => ['id'], 'FROM' => self::TABELA, 'WHERE' => ['chave' => $chave], 'LIMIT' => 1])->current();
        if ($linha) {
            $id = (int) $linha['id'];
        } else {
            $DB->insert(self::TABELA, ['tipo' => 'direta', 'chave' => $chave]);
            $id = (int) $DB->insertId();
        }
        self::garantirParticipante($id, $a, true);
        self::garantirParticipante($id, $b, true);
        return $id;
    }

    /** Conversa de uma sala (cria se não existir) */
    public static function daSala(int $sala): int
    {
        global $DB;
        $linha = $DB->request(['SELECT' => ['id'], 'FROM' => self::TABELA, 'WHERE' => ['tipo' => 'sala', 'salas_id' => $sala], 'LIMIT' => 1])->current();
        if ($linha) {
            return (int) $linha['id'];
        }
        $DB->insert(self::TABELA, ['tipo' => 'sala', 'salas_id' => $sala, 'chave' => 'sala-' . $sala]);
        return (int) $DB->insertId();
    }

    public static function garantirParticipante(int $conversa, int $usuario, bool $membro = false): void
    {
        global $DB;
        $linha = $DB->request(['FROM' => self::PARTICIPANTES, 'WHERE' => ['conversas_id' => $conversa, 'users_id' => $usuario], 'LIMIT' => 1])->current();
        if (!$linha) {
            $c = self::obter($conversa);
            $DB->insert(self::PARTICIPANTES, [
                'conversas_id' => $conversa,
                'users_id'     => $usuario,
                'is_membro'    => $membro ? 1 : 0,
                // Quem entra numa sala começa com tudo lido
                'ultima_lida'  => ($c && $c['tipo'] === 'sala') ? (int) $c['ultima_mensagem'] : 0,
            ]);
        } elseif ($membro && !(int) $linha['is_membro']) {
            $DB->update(self::PARTICIPANTES, ['is_membro' => 1], ['id' => $linha['id']]);
        }
    }

    public static function participante(int $conversa, int $usuario): ?array
    {
        global $DB;
        $linha = $DB->request(['FROM' => self::PARTICIPANTES, 'WHERE' => ['conversas_id' => $conversa, 'users_id' => $usuario], 'LIMIT' => 1])->current();
        return $linha ?: null;
    }

    /** O usuário pode ler e escrever na conversa? */
    public static function temAcesso(int $conversa, int $usuario): bool
    {
        $c = self::obter($conversa);
        if (!$c) {
            return false;
        }
        if ($c['tipo'] === 'direta') {
            $p = self::participante($conversa, $usuario);
            return $p !== null && (int) $p['is_membro'] === 1;
        }
        return PluginChatglpiSala::usuarioAcessa((int) $c['salas_id'], $usuario);
    }

    /** IDs de quem participa (direta: as duas pessoas; sala: membros da sala) */
    public static function membros(int $conversa): array
    {
        global $DB;
        $c = self::obter($conversa);
        if (!$c) {
            return [];
        }
        if ($c['tipo'] === 'direta') {
            return array_map('intval', array_column(iterator_to_array($DB->request(['SELECT' => ['users_id'], 'FROM' => self::PARTICIPANTES, 'WHERE' => ['conversas_id' => $conversa, 'is_membro' => 1]]), false), 'users_id'));
        }
        return PluginChatglpiSala::membros((int) $c['salas_id']);
    }

    /** O outro usuário de uma conversa direta */
    public static function outro(array $c, int $usuario): int
    {
        if ($c['tipo'] !== 'direta' || empty($c['chave'])) {
            return 0;
        }
        [$a, $b] = array_map('intval', explode('-', (string) $c['chave']) + [0, 0]);
        return $a === $usuario ? $b : $a;
    }

    public static function titulo(array $c, int $usuario): string
    {
        if ($c['tipo'] === 'direta') {
            return PluginChatglpiConfig::nomeUsuario(self::outro($c, $usuario));
        }
        $s = PluginChatglpiSala::obterLinha((int) $c['salas_id']);
        return $s ? (string) $s['name'] : 'Sala removida';
    }

    /** Conversas de um usuário com resumo (última mensagem, não lidas) */
    public static function listar(int $usuario): array
    {
        global $DB;
        $ids = [];
        foreach ($DB->request(['SELECT' => ['conversas_id'], 'FROM' => self::PARTICIPANTES, 'WHERE' => ['users_id' => $usuario, 'is_membro' => 1, 'is_oculta' => 0]]) as $p) {
            $ids[(int) $p['conversas_id']] = true;
        }
        foreach (PluginChatglpiSala::salasDoUsuario($usuario) as $sala) {
            $ids[self::daSala((int) $sala['id'])] = true;
        }
        if (!$ids) {
            return [];
        }
        $conversas = iterator_to_array($DB->request(['FROM' => self::TABELA, 'WHERE' => ['id' => array_keys($ids)]]), false);
        $participacoes = [];
        foreach ($DB->request(['FROM' => self::PARTICIPANTES, 'WHERE' => ['users_id' => $usuario, 'conversas_id' => array_keys($ids)]]) as $p) {
            $participacoes[(int) $p['conversas_id']] = $p;
        }
        $lista = [];
        foreach ($conversas as $c) {
            $id = (int) $c['id'];
            if ($c['tipo'] === 'direta' && (int) $c['ultima_mensagem'] === 0) {
                continue;
            }
            if ($c['tipo'] === 'sala' && !PluginChatglpiSala::usuarioAcessa((int) $c['salas_id'], $usuario)) {
                continue;
            }
            $p = $participacoes[$id] ?? null;
            $lida = (int) ($p['ultima_lida'] ?? ($c['tipo'] === 'sala' ? (int) $c['ultima_mensagem'] : 0));
            $lista[] = self::resumo($c, $usuario, $lida, (int) ($p['is_silenciada'] ?? 0));
        }
        usort($lista, static fn($a, $b) => [$b['nao_lidas'] > 0, $b['ultima_id']] <=> [$a['nao_lidas'] > 0, $a['ultima_id']]);
        return $lista;
    }

    public static function resumo(array $c, int $usuario, int $lida, int $silenciada = 0): array
    {
        global $DB;
        $id = (int) $c['id'];
        $ultima = (int) $c['ultima_mensagem'] > 0 ? $DB->request(['FROM' => PluginChatglpiMensagem::TABELA, 'WHERE' => ['id' => (int) $c['ultima_mensagem']], 'LIMIT' => 1])->current() : null;
        $naoLidas = (int) ($DB->request([
            'COUNT' => 'n',
            'FROM'  => PluginChatglpiMensagem::TABELA,
            'WHERE' => ['conversas_id' => $id, 'id' => ['>', $lida], 'is_apagada' => 0, 'NOT' => ['users_id' => $usuario]],
        ])->current()['n'] ?? 0);
        $titulo = self::titulo($c, $usuario);
        $outro = self::outro($c, $usuario);
        $previa = '';
        if ($ultima) {
            $previa = (int) $ultima['is_apagada'] ? 'Mensagem apagada' : ((int) $ultima['anexos_id'] && trim((string) $ultima['conteudo']) === '' ? 'Anexo' : mb_strimwidth(preg_replace('/\s+/u', ' ', (string) $ultima['conteudo']), 0, 90, '…'));
            if ($ultima['tipo'] === 'evento') {
                $previa = ((int) $ultima['users_id'] === $usuario ? 'Você' : explode(' ', PluginChatglpiConfig::nomeUsuario((int) $ultima['users_id']))[0]) . ' ' . $previa;
            } elseif ($c['tipo'] === 'sala' && $ultima['tipo'] === 'texto') {
                $previa = ((int) $ultima['users_id'] === $usuario ? 'Você' : explode(' ', PluginChatglpiConfig::nomeUsuario((int) $ultima['users_id']))[0]) . ': ' . $previa;
            } elseif ((int) $ultima['users_id'] === $usuario && $ultima['tipo'] === 'texto') {
                $previa = 'Você: ' . $previa;
            }
        }
        return [
            'id'         => $id,
            'tipo'       => $c['tipo'],
            'titulo'     => $titulo,
            'ini'        => $c['tipo'] === 'direta' ? PluginChatglpiConfig::iniciais($titulo) : '',
            'foto'       => $outro ? PluginChatglpiConfig::foto($outro) : '',
            'outro'      => $outro,
            'status'     => $outro ? PluginChatglpiPresenca::statusDe($outro) : '',
            'previa'     => $previa,
            'data'       => $ultima ? self::dataCurta((string) $ultima['date_creation']) : '',
            'ultima_id'  => (int) $c['ultima_mensagem'],
            'nao_lidas'  => $naoLidas,
            'silenciada' => (bool) $silenciada,
        ];
    }

    public static function dataCurta(string $data): string
    {
        $t = strtotime($data);
        if (!$t) {
            return '';
        }
        if (date('Y-m-d', $t) === date('Y-m-d')) {
            return date('H:i', $t);
        }
        if (date('Y-m-d', $t) === date('Y-m-d', strtotime('-1 day'))) {
            return 'Ontem';
        }
        return date('d/m', $t);
    }

    /** Total de não lidas (sem as conversas silenciadas) */
    public static function totalNaoLidas(array $lista): int
    {
        $total = 0;
        foreach ($lista as $c) {
            if (!$c['silenciada']) {
                $total += $c['nao_lidas'];
            }
        }
        return $total;
    }

    public static function marcarLida(int $conversa, int $usuario, int $ate = 0): void
    {
        global $DB;
        $c = self::obter($conversa);
        if (!$c) {
            return;
        }
        $ate = $ate > 0 ? min($ate, (int) $c['ultima_mensagem']) : (int) $c['ultima_mensagem'];
        self::garantirParticipante($conversa, $usuario, false);
        $DB->update(self::PARTICIPANTES, ['ultima_lida' => $ate], ['conversas_id' => $conversa, 'users_id' => $usuario, 'ultima_lida' => ['<', $ate]]);
    }

    /** Até qual mensagem o outro participante leu (conversa direta) */
    public static function lidaPeloOutro(array $c, int $usuario): int
    {
        $outro = self::outro($c, $usuario);
        if (!$outro) {
            return 0;
        }
        $p = self::participante((int) $c['id'], $outro);
        return (int) ($p['ultima_lida'] ?? 0);
    }

    public static function alternar(int $conversa, int $usuario, string $campo): ?int
    {
        global $DB;
        if (!in_array($campo, ['is_silenciada', 'is_oculta'], true)) {
            return null;
        }
        self::garantirParticipante($conversa, $usuario, false);
        $p = self::participante($conversa, $usuario);
        $novo = (int) $p[$campo] ? 0 : 1;
        $DB->update(self::PARTICIPANTES, [$campo => $novo], ['id' => $p['id']]);
        return $novo;
    }

    /** Ao chegar mensagem nova, a conversa volta a aparecer para quem a tinha ocultado */
    public static function reexibir(int $conversa): void
    {
        global $DB;
        $DB->update(self::PARTICIPANTES, ['is_oculta' => 0], ['conversas_id' => $conversa, 'is_oculta' => 1]);
    }

    /** Dados da conversa para abrir no painel */
    public static function cabecalho(int $conversa, int $usuario): array
    {
        $c = self::obter($conversa);
        $p = self::participante($conversa, $usuario);
        $outro = self::outro($c, $usuario);
        $dados = [
            'id'         => $conversa,
            'tipo'       => $c['tipo'],
            'titulo'     => self::titulo($c, $usuario),
            'outro'      => $outro,
            'foto'       => $outro ? PluginChatglpiConfig::foto($outro) : '',
            'ini'        => PluginChatglpiConfig::iniciais(self::titulo($c, $usuario)),
            'status'     => $outro ? PluginChatglpiPresenca::statusDe($outro) : '',
            'silenciada' => (bool) (int) ($p['is_silenciada'] ?? 0),
            'membros'    => 0,
            'descricao'  => '',
        ];
        if ($c['tipo'] === 'sala') {
            $s = PluginChatglpiSala::obterLinha((int) $c['salas_id']);
            $dados['membros'] = count(self::membros($conversa));
            $dados['descricao'] = $s ? mb_strimwidth(trim(html_entity_decode(strip_tags((string) $s['comment']), ENT_QUOTES, 'UTF-8')), 0, 160, '…') : '';
        }
        return $dados;
    }
}
