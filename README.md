# Chat GLPI para GLPI

> Autor: **GLPI Salvador** · Licença: **GPLv2+** · Compatível com GLPI **11.0.0 a 12.x**

**Chat interno** para a equipe, dentro do GLPI: conversas diretas entre usuários e salas por grupo ou por lista de membros. O chat fica sempre à mão num ícone na barra superior.

## O que o plugin faz

### Conversas
- **Diretas**, entre duas pessoas, e **salas**:
  - os membros de uma sala vêm do grupo vinculado e dos escolhidos manualmente;
  - uma sala sem grupo e sem membros fica aberta a todos que usam o chat.
- Mensagens com formatação, **edição** dentro de um prazo configurável e exclusão.
- Contador de **não lidas** e confirmação de leitura.
- **Silenciar** ou **ocultar** conversas.
- Busca de pessoas e filtro por entidade.

### Presença
- Situação **online**, **ausente**, **ocupado** ou **invisível**. A pessoa passa a ausente sozinha depois de alguns minutos sem uso.
- Indicação de **"digitando…"**.

### Anexos
- Arquivos guardados fora da pasta pública e entregues **só para quem participa** da conversa.
- Limite de tamanho e extensões permitidas configuráveis. O limite nunca passa do permitido pelo PHP.

### Onde o chat aparece
- **Ícone na barra superior**, com o número de não lidas, que abre um **painel lateral**.
- **Página em tela cheia** em *Ferramentas → Chat*.
- **Notificações do navegador**, opcionais.

### Integração com chamados
- **Salvar no chamado:** grava a conversa como acompanhamento de um chamado, privado por padrão.
- **Abrir chamado** a partir da conversa, com a outra pessoa como requerente.
- Aba **Chat** no chamado, com as conversas salvas nele.

### Manutenção
Uma tarefa automática aplica a **retenção** de mensagens e remove os anexos órfãos.

## Configuração e direitos

- **Direito nativo** "Chat" na aba do perfil: **usar** o chat e **gerenciar salas**.
  - A instalação dá "usar" a todos os perfis da interface padrão.
  - Os administradores recebem os dois direitos.
- **Salas** são itens nativos, com lista, formulário e histórico.
- Opções da página de configuração:
  - nome do chat;
  - intervalos de atualização com o chat aberto e fechado;
  - minutos até ficar ausente;
  - prazo de edição e retenção;
  - anexos: ligados ou não, tamanho e extensões;
  - restringir pessoas por entidade;
  - acompanhamento privado ao salvar no chamado.

---

## Download e instalação

1. Baixe o arquivo `chatglpi-X.Y.Z.zip` da **[última versão](../../releases/latest)**. Use o arquivo anexado à release, não o "Source code".
2. Descompacte dentro da pasta `plugins/` do GLPI. O resultado deve ser `plugins/chatglpi/setup.php`.
3. Ajuste o dono dos arquivos para o usuário do servidor web, por exemplo:
   ```bash
   chown -R www-data:www-data /var/www/glpi/plugins/chatglpi
   ```
4. No GLPI, vá em **Configurar → Plugins** e clique em **Instalar** e depois em **Ativar**. Pela linha de comando:
   ```bash
   php bin/console plugin:install chatglpi -u <usuário administrador>
   php bin/console plugin:activate chatglpi
   ```

A instalação cria as tabelas, as configurações padrão e as ações automáticas do plugin, e funciona num GLPI sem nada configurado antes.

### Atualização

Substitua a pasta `plugins/chatglpi` pela versão nova e rode **Instalar** de novo, ou `php bin/console plugin:install chatglpi -f`. Depois, ative o plugin. As tabelas e colunas novas são criadas sem perder os dados.

### Desinstalação

A desinstalação **não apaga as tabelas do plugin**: reinstalar recupera os dados.

## Versões

O histórico, com o que mudou em cada versão e o arquivo para download, está em **[Releases](../../releases)**. Cada versão entrou por um **[pull request](../../pulls?q=is%3Apr)**.

## Licença

Distribuído sob a **GNU General Public License v2.0 ou posterior**. Veja o arquivo [LICENSE](LICENSE).