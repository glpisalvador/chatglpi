<?php

/**
 * Plugin Chat GLPI - endpoints AJAX (JSON)
 * GET: sincronizar, abrir, historico, pessoas, entidades | POST: enviar, editar, apagar, lida, status, preferencias,
 * silenciar, ocultar, salvar_chamado, abrir_chamado
 */

while (ob_get_level() > 0) {
    ob_end_clean();
}
ob_start();
include('../../../inc/includes.php');
while (ob_get_level() > 0) {
    ob_end_clean();
}

register_shutdown_function(static function (): void {
    $erro = error_get_last();
    if ($erro && in_array($erro['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['success' => false, 'message' => 'Erro interno ao processar a solicitação.']);
    }
});

function chatglpi_responder(array $dados): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $dados['new_token'] = PluginChatglpiConfig::tokenCsrf();
    echo json_encode($dados, JSON_UNESCAPED_UNICODE);
    exit;
}

$usuario = (int) Session::getLoginUserID();
if ($usuario <= 0 || !PluginChatglpiConfig::podeUsar()) {
    chatglpi_responder(['success' => false, 'message' => 'Sem permissão para usar o chat.', 'sem_acesso' => true]);
}
// A sessão não precisa ficar presa durante a resposta (o chat consulta com frequência)
$acao = (string) ($_REQUEST['action'] ?? '');
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
$C = PluginChatglpiConversa::class;
$M = PluginChatglpiMensagem::class;

/** Conversa pedida, com verificação de acesso */
$conversaPedida = static function () use ($usuario, $C): int {
    $id = (int) ($_REQUEST['conversa'] ?? 0);
    if ($id <= 0 || !$C::temAcesso($id, $usuario)) {
        chatglpi_responder(['success' => false, 'message' => 'Conversa não encontrada ou sem acesso.']);
    }
    return $id;
};

$exigirPost = static function () use ($post): void {
    if (!$post) {
        chatglpi_responder(['success' => false, 'message' => 'Método não permitido.']);
    }
};

switch ($acao) {
    case 'sincronizar':
        $aberta = (int) ($_GET['aberta'] ?? 0);
        $ultimo = (int) ($_GET['ultimo'] ?? 0);
        $desde = preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) ($_GET['desde'] ?? '')) ? (string) $_GET['desde'] : '';
        $digitando = (int) ($_GET['digitando'] ?? 0);
        if ($digitando > 0 && !$C::temAcesso($digitando, $usuario)) {
            $digitando = 0;
        }
        session_write_close();
        PluginChatglpiPresenca::sinal($usuario, !empty($_GET['ativo']), $digitando);
        $conversas = $C::listar($usuario);
        $eu = PluginChatglpiPresenca::obter($usuario);
        $resposta = [
            'success'   => true,
            'agora'     => date('Y-m-d H:i:s'),
            'total'     => $C::totalNaoLidas($conversas),
            'conversas' => $conversas,
            'eu'        => [
                'id'        => $usuario,
                'nome'      => PluginChatglpiConfig::nomeUsuario($usuario),
                'status'    => PluginChatglpiPresenca::efetivo($eu, true),
                'escolhido' => (string) ($eu['status'] ?? 'online'),
                'notificar' => (bool) (int) ($eu['notificar_navegador'] ?? 1),
                'som'       => (bool) (int) ($eu['tocar_som'] ?? 0),
            ],
        ];
        if (!empty($_GET['primeira'])) {
            $resposta['config'] = PluginChatglpiConfig::paraNavegador();
        }
        if ($aberta > 0 && $C::temAcesso($aberta, $usuario)) {
            $c = $C::obter($aberta);
            $novas = $ultimo > 0 ? $M::listar($aberta, $usuario, $ultimo) : [];
            if ($novas && !empty($_GET['visivel'])) {
                $C::marcarLida($aberta, $usuario, (int) end($novas)['id']);
            }
            $resposta['aberta'] = [
                'id'        => $aberta,
                'novas'     => $novas,
                'alteradas' => $M::alteradas($aberta, $usuario, $desde, $ultimo),
                'digitando' => PluginChatglpiPresenca::digitando($aberta, $usuario),
                'lida_ate'  => $C::lidaPeloOutro($c, $usuario),
                'status'    => ($o = $C::outro($c, $usuario)) ? PluginChatglpiPresenca::statusDe($o) : '',
            ];
        }
        if (!empty($_GET['pessoas'])) {
            $resposta['pessoas'] = PluginChatglpiPresenca::pessoas($usuario, (string) ($_GET['busca'] ?? ''), (int) ($_GET['grupo'] ?? 0));
        }
        chatglpi_responder($resposta);

        // no break
    case 'abrir':
        $id = (int) ($_GET['conversa'] ?? 0);
        if ($id <= 0 && (int) ($_GET['usuario'] ?? 0) > 0) {
            $outro = (int) $_GET['usuario'];
            if (!PluginChatglpiPresenca::podeConversarCom($outro)) {
                chatglpi_responder(['success' => false, 'message' => 'Esta pessoa não usa o chat.']);
            }
            $id = $C::direta($usuario, $outro);
        }
        if ($id <= 0 || !$C::temAcesso($id, $usuario)) {
            chatglpi_responder(['success' => false, 'message' => 'Conversa não encontrada ou sem acesso.']);
        }
        $mensagens = $M::listar($id, $usuario, 0, 0, 50);
        if (!empty($_GET['visivel'])) {
            $C::marcarLida($id, $usuario);
        }
        $c = $C::obter($id);
        chatglpi_responder([
            'success'   => true,
            'conversa'  => $C::cabecalho($id, $usuario),
            'mensagens' => $mensagens,
            'mais'      => count($mensagens) === 50,
            'lida_ate'  => $C::lidaPeloOutro($c, $usuario),
            'agora'     => date('Y-m-d H:i:s'),
        ]);

        // no break
    case 'historico':
        $id = $conversaPedida();
        $antes = (int) ($_GET['antes'] ?? 0);
        $mensagens = $M::listar($id, $usuario, 0, $antes, 50);
        chatglpi_responder(['success' => true, 'mensagens' => $mensagens, 'mais' => count($mensagens) === 50]);

        // no break
    case 'membros':
        $id = $conversaPedida();
        $lista = [];
        foreach ($C::membros($id) as $u) {
            $nome = PluginChatglpiConfig::nomeUsuario($u);
            $lista[] = ['id' => $u, 'nome' => $nome, 'ini' => PluginChatglpiConfig::iniciais($nome), 'foto' => PluginChatglpiConfig::foto($u), 'status' => PluginChatglpiPresenca::statusDe($u)];
        }
        usort($lista, static fn($a, $b) => strcmp(mb_strtolower($a['nome']), mb_strtolower($b['nome'])));
        chatglpi_responder(['success' => true, 'membros' => $lista]);

        // no break
    case 'entidades':
        chatglpi_responder(['success' => true, 'entidades' => PluginChatglpiChamado::entidades(), 'atual' => (int) ($_SESSION['glpiactive_entity'] ?? 0)]);

        // no break
    case 'enviar':
        $exigirPost();
        $id = $conversaPedida();
        $texto = (string) ($_POST['texto'] ?? '');
        $anexo = 0;
        if (!empty($_FILES['arquivo']['name'])) {
            [$ok, $msg, $anexo] = PluginChatglpiAnexo::salvar($_FILES['arquivo'], $id, $usuario);
            if (!$ok) {
                chatglpi_responder(['success' => false, 'message' => $msg]);
            }
        }
        [$ok, $msg, $mid] = $M::enviar($id, $usuario, $texto, $anexo);
        if (!$ok) {
            if ($anexo) {
                PluginChatglpiAnexo::remover($anexo);
            }
            chatglpi_responder(['success' => false, 'message' => $msg]);
        }
        chatglpi_responder(['success' => true, 'mensagem' => $M::formatar($M::obter($mid), $usuario)]);

        // no break
    case 'editar':
    case 'apagar':
        $exigirPost();
        $m = $M::obter((int) ($_POST['id'] ?? 0));
        if (!$m || !$C::temAcesso((int) $m['conversas_id'], $usuario)) {
            chatglpi_responder(['success' => false, 'message' => 'Mensagem não encontrada.']);
        }
        [$ok, $msg] = $acao === 'editar' ? $M::editar((int) $m['id'], $usuario, (string) ($_POST['texto'] ?? '')) : $M::apagar((int) $m['id'], $usuario);
        chatglpi_responder(['success' => $ok, 'message' => $msg, 'mensagem' => $ok ? $M::formatar($M::obter((int) $m['id']), $usuario) : null]);

        // no break
    case 'lida':
        $exigirPost();
        $id = $conversaPedida();
        $C::marcarLida($id, $usuario, (int) ($_POST['ate'] ?? 0));
        chatglpi_responder(['success' => true]);

        // no break
    case 'status':
        $exigirPost();
        $ok = PluginChatglpiPresenca::definirStatus($usuario, (string) ($_POST['status'] ?? ''));
        chatglpi_responder(['success' => $ok, 'message' => $ok ? '' : 'Status inválido.']);

        // no break
    case 'preferencias':
        $exigirPost();
        $p = [];
        foreach (['notificar_navegador', 'tocar_som'] as $c) {
            if (isset($_POST[$c])) {
                $p[$c] = (int) $_POST[$c];
            }
        }
        PluginChatglpiPresenca::preferencias($usuario, $p);
        chatglpi_responder(['success' => true]);

        // no break
    case 'silenciar':
    case 'ocultar':
        $exigirPost();
        $id = $conversaPedida();
        $novo = $C::alternar($id, $usuario, $acao === 'silenciar' ? 'is_silenciada' : 'is_oculta');
        chatglpi_responder(['success' => $novo !== null, 'valor' => $novo]);

        // no break
    case 'salvar_chamado':
        $exigirPost();
        $id = $conversaPedida();
        [$ok, $msg, $fid] = PluginChatglpiChamado::salvar($id, $usuario, (int) ltrim((string) ($_POST['chamado'] ?? ''), '# '), (int) ($_POST['quantidade'] ?? 50), !empty($_POST['privado']));
        chatglpi_responder(['success' => $ok, 'message' => $msg, 'link' => $ok ? Ticket::getFormURLWithID((int) ltrim((string) $_POST['chamado'], '# ')) : '']);

        // no break
    case 'abrir_chamado':
        $exigirPost();
        $id = $conversaPedida();
        [$ok, $msg, $tid] = PluginChatglpiChamado::abrir($id, $usuario, $_POST);
        chatglpi_responder(['success' => $ok, 'message' => $msg, 'link' => $ok ? Ticket::getFormURLWithID($tid) : '', 'chamado' => $tid]);

        // no break
    default:
        chatglpi_responder(['success' => false, 'message' => 'Ação desconhecida.']);
}
