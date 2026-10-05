<?php

/**
 * Plugin Chat GLPI - anexos das mensagens (arquivos fora da pasta pública, entregues com verificação de acesso)
 */
class PluginChatglpiAnexo extends CommonGLPI
{
    public const TABELA = 'glpi_plugin_chatglpi_anexos';
    public const IMAGENS = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    public static function getTypeName($nb = 0): string
    {
        return $nb > 1 ? 'Anexos' : 'Anexo';
    }

    public static function obter(int $id): ?array
    {
        global $DB;
        $linha = $DB->request(['FROM' => self::TABELA, 'WHERE' => ['id' => $id], 'LIMIT' => 1])->current();
        return $linha ?: null;
    }

    /**
     * Grava um arquivo enviado ($_FILES[...]).
     * @return array{0: bool, 1: string, 2: int}
     */
    public static function salvar(array $arquivo, int $conversa, int $usuario): array
    {
        global $DB;
        if (!(int) PluginChatglpiConfig::getConfig('anexos_ativos')) {
            return [false, 'O envio de anexos está desativado.', 0];
        }
        if (($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($arquivo['tmp_name'] ?? ''))) {
            return [false, 'O arquivo não chegou ao servidor (verifique o tamanho).', 0];
        }
        $max = PluginChatglpiConfig::limiteAnexo();
        $tamanho = (int) filesize($arquivo['tmp_name']);
        if ($tamanho <= 0 || $tamanho > $max) {
            return [false, 'O arquivo passa do tamanho máximo de ' . number_format($max / 1048576, 1, ',', '.') . ' MB.', 0];
        }
        $nome = self::nomeSeguro((string) ($arquivo['name'] ?? 'arquivo'));
        $ext = strtolower(pathinfo($nome, PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, PluginChatglpiConfig::extensoes(), true)) {
            return [false, 'Tipo de arquivo não permitido (.' . $ext . ').', 0];
        }
        $mime = (string) (mime_content_type($arquivo['tmp_name']) ?: 'application/octet-stream');
        if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true) && !in_array($mime, self::IMAGENS, true)) {
            return [false, 'O conteúdo do arquivo não corresponde a uma imagem.', 0];
        }
        $sub = date('Ym');
        $pasta = PluginChatglpiConfig::pastaAnexos() . '/' . $sub;
        if (!is_dir($pasta) && !@mkdir($pasta, 0755, true)) {
            return [false, 'Não foi possível criar a pasta de anexos.', 0];
        }
        $destino = $sub . '/' . uniqid('', true) . '.' . $ext;
        if (!move_uploaded_file($arquivo['tmp_name'], PluginChatglpiConfig::pastaAnexos() . '/' . $destino)) {
            return [false, 'Não foi possível gravar o arquivo.', 0];
        }
        $DB->insert(self::TABELA, ['conversas_id' => $conversa, 'users_id' => $usuario, 'nome' => $nome, 'arquivo' => $destino, 'mime' => $mime, 'tamanho' => $tamanho]);
        return [true, '', (int) $DB->insertId()];
    }

    public static function nomeSeguro(string $nome): string
    {
        $nome = basename(str_replace('\\', '/', $nome));
        $nome = preg_replace('/[\x00-\x1F\x7F"<>|:*?\/\\\\]+/u', '_', $nome);
        $nome = trim((string) $nome, " .");
        return mb_substr($nome !== '' ? $nome : 'arquivo', 0, 200);
    }

    public static function caminho(array $a): string
    {
        $base = realpath(PluginChatglpiConfig::pastaAnexos());
        $arq = realpath(PluginChatglpiConfig::pastaAnexos() . '/' . $a['arquivo']);
        return ($base && $arq && str_starts_with($arq, $base . DIRECTORY_SEPARATOR)) ? $arq : '';
    }

    public static function resumo(int $id): ?array
    {
        $a = self::obter($id);
        if (!$a) {
            return null;
        }
        $imagem = in_array($a['mime'], self::IMAGENS, true);
        return [
            'id'      => (int) $a['id'],
            'nome'    => (string) $a['nome'],
            'tamanho' => self::tamanhoLegivel((int) $a['tamanho']),
            'imagem'  => $imagem,
            'url'     => PluginChatglpiConfig::url('anexo.php', ['id' => (int) $a['id']]),
            'ver'     => $imagem ? PluginChatglpiConfig::url('anexo.php', ['id' => (int) $a['id'], 'ver' => 1]) : '',
        ];
    }

    public static function tamanhoLegivel(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
        }
        return max(1, (int) round($bytes / 1024)) . ' KB';
    }

    public static function remover(int $id): void
    {
        global $DB;
        $a = self::obter($id);
        if (!$a) {
            return;
        }
        $arq = self::caminho($a);
        if ($arq !== '' && is_file($arq)) {
            @unlink($arq);
        }
        $DB->delete(self::TABELA, ['id' => $id]);
    }

    /** Envia o arquivo ao navegador, se o usuário tiver acesso à conversa */
    public static function entregar(int $id, int $usuario, bool $inline): void
    {
        $a = self::obter($id);
        if (!$a || !PluginChatglpiConversa::temAcesso((int) $a['conversas_id'], $usuario)) {
            throw new \Glpi\Exception\Http\AccessDeniedHttpException();
        }
        $arq = self::caminho($a);
        if ($arq === '' || !is_file($arq)) {
            throw new \Glpi\Exception\Http\NotFoundHttpException();
        }
        $inline = $inline && in_array($a['mime'], self::IMAGENS, true);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: ' . ($inline ? $a['mime'] : 'application/octet-stream'));
        header('Content-Length: ' . filesize($arq));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=86400');
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename=\"" . str_replace('"', '', $a['nome']) . "\"; filename*=UTF-8''" . rawurlencode($a['nome']));
        readfile($arq);
        exit;
    }
}
