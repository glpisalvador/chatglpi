<?php

/**
 * Plugin Chat GLPI - tarefa automática: retenção de mensagens, anexos órfãos e "digitando" antigos
 */
class PluginChatglpiManutencao extends CommonDBTM
{
    public static function getTypeName($nb = 0): string
    {
        return 'Chat GLPI';
    }

    public static function getTable($classname = null)
    {
        return PluginChatglpiConfig::TABELA;
    }

    public static function cronInfo($name)
    {
        return ['description' => 'Chat GLPI: retenção de mensagens e limpeza de anexos'];
    }

    public static function cronChatglpiManutencao($task = null)
    {
        $n = self::executar();
        if ($task instanceof CronTask) {
            $task->addVolume($n);
        }
        return 1;
    }

    /** @return int itens removidos */
    public static function executar(): int
    {
        global $DB;
        $removidos = 0;

        // Mensagens fora da retenção (0 = guardar para sempre)
        $dias = PluginChatglpiConfig::inteiro('retencao_dias', 0, 36500);
        if ($dias > 0) {
            $limite = date('Y-m-d H:i:s', strtotime('-' . $dias . ' days'));
            do {
                $lote = iterator_to_array($DB->request(['SELECT' => ['id', 'anexos_id'], 'FROM' => PluginChatglpiMensagem::TABELA, 'WHERE' => ['date_creation' => ['<', $limite]], 'LIMIT' => 500]), false);
                foreach ($lote as $m) {
                    if ((int) $m['anexos_id']) {
                        PluginChatglpiAnexo::remover((int) $m['anexos_id']);
                    }
                }
                if ($lote) {
                    $DB->delete(PluginChatglpiMensagem::TABELA, ['id' => array_column($lote, 'id')]);
                    $removidos += count($lote);
                }
            } while (count($lote) === 500);
        }

        // Anexos enviados que nunca viraram mensagem (mais de um dia)
        foreach ($DB->request([
            'SELECT'    => [PluginChatglpiAnexo::TABELA . '.id'],
            'FROM'      => PluginChatglpiAnexo::TABELA,
            'LEFT JOIN' => [PluginChatglpiMensagem::TABELA => ['ON' => [PluginChatglpiMensagem::TABELA => 'anexos_id', PluginChatglpiAnexo::TABELA => 'id']]],
            'WHERE'     => [PluginChatglpiMensagem::TABELA . '.id' => null, PluginChatglpiAnexo::TABELA . '.date_creation' => ['<', date('Y-m-d H:i:s', strtotime('-1 day'))]],
            'LIMIT'     => 1000,
        ]) as $a) {
            PluginChatglpiAnexo::remover((int) $a['id']);
            $removidos++;
        }

        $DB->update(PluginChatglpiPresenca::TABELA, ['digitando_conversa' => 0], ['digitando_conversa' => ['>', 0], 'digitando_ate' => ['<', date('Y-m-d H:i:s')]]);
        return $removidos;
    }

    /** Remove uma conversa inteira (mensagens, anexos e participantes) */
    public static function apagarConversa(int $conversa): void
    {
        global $DB;
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => PluginChatglpiAnexo::TABELA, 'WHERE' => ['conversas_id' => $conversa]]) as $a) {
            PluginChatglpiAnexo::remover((int) $a['id']);
        }
        $DB->delete(PluginChatglpiMensagem::TABELA, ['conversas_id' => $conversa]);
        $DB->delete(PluginChatglpiConversa::PARTICIPANTES, ['conversas_id' => $conversa]);
        $DB->delete(PluginChatglpiConversa::TABELA, ['id' => $conversa]);
    }
}
