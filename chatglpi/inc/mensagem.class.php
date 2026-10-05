<?php

/**
 * Plugin Chat GLPI - mensagens: envio, edição, exclusão, leitura e formatação
 */
class PluginChatglpiMensagem extends CommonGLPI
{
    public const TABELA = 'glpi_plugin_chatglpi_mensagens';
    public const LIMITE_TEXTO = 10000;

    public static function getTypeName($nb = 0): string
    {
        return $nb > 1 ? 'Mensagens' : 'Mensagem';
    }

    public static function obter(int $id): ?array
    {
        global $DB;
        $linha = $DB->request(['FROM' => self::TABELA, 'WHERE' => ['id' => $id], 'LIMIT' => 1])->current();
        return $linha ?: null;
    }

    /** @return array{0: bool, 1: string, 2: int} */
    public static function enviar(int $conversa, int $usuario, string $texto, int $anexo = 0, string $tipo = 'texto'): array
    {
        global $DB;
        $texto = trim(str_replace("\r\n", "\n", $texto));
        if ($texto === '' && $anexo <= 0) {
            return [false, 'Escreva uma mensagem.', 0];
        }
        if (mb_strlen($texto) > self::LIMITE_TEXTO) {
            return [false, 'A mensagem passa de ' . self::LIMITE_TEXTO . ' caracteres.', 0];
        }
        // Numa sala, quem ainda não tem registro de leitura passa a ter (assim a mensagem conta como não lida para todos)
        $c = PluginChatglpiConversa::obter($conversa);
        if ($c && $c['tipo'] === 'sala') {
            $existentes = array_map('intval', array_column(iterator_to_array($DB->request(['SELECT' => ['users_id'], 'FROM' => PluginChatglpiConversa::PARTICIPANTES, 'WHERE' => ['conversas_id' => $conversa]]), false), 'users_id'));
            foreach (array_diff(PluginChatglpiConversa::membros($conversa), $existentes) as $membro) {
                $DB->insert(PluginChatglpiConversa::PARTICIPANTES, ['conversas_id' => $conversa, 'users_id' => (int) $membro, 'is_membro' => 0, 'ultima_lida' => (int) $c['ultima_mensagem']]);
            }
        }
        $DB->insert(self::TABELA, [
            'conversas_id' => $conversa,
            'users_id'     => $usuario,
            'tipo'         => $tipo === 'evento' ? 'evento' : 'texto',
            'conteudo'     => $texto,
            'anexos_id'    => $anexo,
        ]);
        $id = (int) $DB->insertId();
        if ($id <= 0) {
            return [false, 'Não foi possível gravar a mensagem.', 0];
        }
        $DB->update(PluginChatglpiConversa::TABELA, ['ultima_mensagem' => $id], ['id' => $conversa]);
        PluginChatglpiConversa::reexibir($conversa);
        PluginChatglpiConversa::marcarLida($conversa, $usuario, $id);
        return [true, '', $id];
    }

    public static function podeAlterar(array $m, int $usuario): bool
    {
        $prazo = PluginChatglpiConfig::inteiro('prazo_edicao', 0, 1440);
        return (int) $m['users_id'] === $usuario && $m['tipo'] === 'texto' && !(int) $m['is_apagada']
            && $prazo > 0 && strtotime((string) $m['date_creation']) >= time() - $prazo * 60;
    }

    /** @return array{0: bool, 1: string} */
    public static function editar(int $id, int $usuario, string $texto): array
    {
        global $DB;
        $m = self::obter($id);
        if (!$m || !self::podeAlterar($m, $usuario)) {
            return [false, 'Esta mensagem não pode mais ser editada.'];
        }
        $texto = trim(str_replace("\r\n", "\n", $texto));
        if ($texto === '' && !(int) $m['anexos_id']) {
            return [false, 'A mensagem não pode ficar vazia.'];
        }
        if (mb_strlen($texto) > self::LIMITE_TEXTO) {
            return [false, 'A mensagem passa de ' . self::LIMITE_TEXTO . ' caracteres.'];
        }
        $DB->update(self::TABELA, ['conteudo' => $texto, 'is_editada' => 1, 'date_mod' => date('Y-m-d H:i:s')], ['id' => $id]);
        return [true, ''];
    }

    /** @return array{0: bool, 1: string} */
    public static function apagar(int $id, int $usuario): array
    {
        global $DB;
        $m = self::obter($id);
        if (!$m || !self::podeAlterar($m, $usuario)) {
            return [false, 'Esta mensagem não pode mais ser apagada.'];
        }
        $DB->update(self::TABELA, ['conteudo' => '', 'is_apagada' => 1, 'date_mod' => date('Y-m-d H:i:s')], ['id' => $id]);
        if ((int) $m['anexos_id']) {
            PluginChatglpiAnexo::remover((int) $m['anexos_id']);
        }
        return [true, ''];
    }

    /** Mensagens da conversa: as mais recentes, as posteriores a um id ou as anteriores a um id */
    public static function listar(int $conversa, int $usuario, int $depois = 0, int $antes = 0, int $limite = 50): array
    {
        global $DB;
        $where = ['conversas_id' => $conversa];
        if ($depois > 0) {
            $where['id'] = ['>', $depois];
        } elseif ($antes > 0) {
            $where['id'] = ['<', $antes];
        }
        $linhas = iterator_to_array($DB->request([
            'FROM'  => self::TABELA,
            'WHERE' => $where,
            'ORDER' => $depois > 0 ? 'id ASC' : 'id DESC',
            'LIMIT' => $depois > 0 ? 200 : $limite,
        ]), false);
        if ($depois <= 0) {
            $linhas = array_reverse($linhas);
        }
        return array_map(static fn($m) => self::formatar($m, $usuario), $linhas);
    }

    /** Mensagens já entregues que foram editadas ou apagadas desde um instante */
    public static function alteradas(int $conversa, int $usuario, string $desde, int $ate): array
    {
        global $DB;
        if ($desde === '' || $ate <= 0) {
            return [];
        }
        $linhas = $DB->request([
            'FROM'  => self::TABELA,
            'WHERE' => ['conversas_id' => $conversa, 'id' => ['<=', $ate], 'date_mod' => ['>', $desde], 'OR' => [['is_editada' => 1], ['is_apagada' => 1]]],
            'LIMIT' => 100,
        ]);
        $lista = [];
        foreach ($linhas as $m) {
            $lista[] = self::formatar($m, $usuario);
        }
        return $lista;
    }

    public static function formatar(array $m, int $usuario): array
    {
        $autor = (int) $m['users_id'];
        $nome = PluginChatglpiConfig::nomeUsuario($autor);
        $apagada = (bool) (int) $m['is_apagada'];
        $dados = [
            'id'       => (int) $m['id'],
            'autor'    => $autor,
            'nome'     => $nome,
            'ini'      => PluginChatglpiConfig::iniciais($nome),
            'foto'     => PluginChatglpiConfig::foto($autor),
            'minha'    => $autor === $usuario,
            'tipo'     => (string) $m['tipo'],
            'html'     => $apagada ? '' : self::renderizar((string) $m['conteudo']),
            'texto'    => ($autor === $usuario && !$apagada) ? (string) $m['conteudo'] : '',
            'hora'     => date('H:i', strtotime((string) $m['date_creation'])),
            'dia'      => date('Y-m-d', strtotime((string) $m['date_creation'])),
            'data'     => Html::convDateTime($m['date_creation']),
            'editada'  => (bool) (int) $m['is_editada'],
            'apagada'  => $apagada,
            'alterar'  => self::podeAlterar($m, $usuario),
            'anexo'    => null,
        ];
        if (!$apagada && (int) $m['anexos_id'] > 0) {
            $dados['anexo'] = PluginChatglpiAnexo::resumo((int) $m['anexos_id']);
        }
        return $dados;
    }

    /** Texto em HTML seguro: escapa, quebra linhas, cria links de URLs e de chamados (#123) */
    public static function renderizar(string $texto): string
    {
        global $CFG_GLPI;
        $html = htmlspecialchars($texto, ENT_QUOTES, 'UTF-8');
        $html = preg_replace_callback('~\bhttps?://[^\s<>"\']+~i', static function ($m) {
            $url = rtrim($m[0], '.,;:!?)');
            $resto = substr($m[0], strlen($url));
            return '<a href="' . $url . '" target="_blank" rel="noopener noreferrer">' . $url . '</a>' . $resto;
        }, $html);
        $html = preg_replace_callback('/(^|[\s(])#(\d{1,9})\b/u', static function ($m) use ($CFG_GLPI) {
            return $m[1] . '<a href="' . $CFG_GLPI['root_doc'] . '/front/ticket.form.php?id=' . (int) $m[2] . '" target="_blank" class="chatglpi-ref" title="Abrir o chamado ' . (int) $m[2] . '">#' . (int) $m[2] . '</a>';
        }, $html);
        $html = preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/u', '<strong>$1</strong>', $html);
        return nl2br($html, false);
    }

    /** Texto simples de várias mensagens (para chamados) */
    public static function paraChamado(array $mensagens): string
    {
        $linhas = '';
        $diaAnterior = '';
        foreach ($mensagens as $m) {
            if ($m['apagada']) {
                continue;
            }
            if ($m['dia'] !== $diaAnterior) {
                $linhas .= '<tr><td colspan="2" style="padding:6px 8px;background:#f8f9fa;color:#6c757d;font-size:11px;font-weight:600;">' . htmlspecialchars(Html::convDate($m['dia']), ENT_QUOTES, 'UTF-8') . '</td></tr>';
                $diaAnterior = $m['dia'];
            }
            $corpo = $m['html'];
            if ($m['anexo']) {
                $corpo .= ($corpo !== '' ? '<br>' : '') . '[Anexo: ' . htmlspecialchars($m['anexo']['nome'], ENT_QUOTES, 'UTF-8') . ']';
            }
            if ($m['tipo'] === 'evento') {
                $corpo = '<em>' . $corpo . '</em>';
            }
            $linhas .= '<tr><td style="padding:4px 8px;vertical-align:top;white-space:nowrap;color:#495057;font-size:12px;"><strong>' . htmlspecialchars($m['nome'], ENT_QUOTES, 'UTF-8') . '</strong><br><span style="color:#999;font-size:11px;">' . $m['hora'] . '</span></td>'
                . '<td style="padding:4px 8px;vertical-align:top;font-size:12px;color:#333;">' . $corpo . '</td></tr>';
        }
        return '<table style="border-collapse:collapse;width:100%;border:1px solid #dee2e6;">' . $linhas . '</table>';
    }
}
