# Chat IA (/ai) e atendimento WhatsApp

Issue: ControleOnline/api-platform-mcp#82 · Parent: ControleOnline/app-community#1335

| Classe | Papel |
| --- | --- |
| `McpChatService` | Orquestra: `POST /mcp/chat` grava a pergunta e enfileira `McpChat`; o worker roda a IA, grava a resposta e emite websocket |
| `McpChatLlmClient` | Loop de tool-use (OpenAI-compatible/Grok, Anthropic Messages, Gemini), até 8 rodadas |
| `McpChatToolbox` | Tools por canal: **web** = tools MCP somente leitura executadas como o usuário que perguntou; **whatsapp** = apenas `handoff_to_human` |
| `McpChatPrompts` | Prompts por modo e histórico (mensagens de funcionários rotuladas `[Interno]`) |
| `McpChatProviderConfig` | Config `ai-providers` da empresa (chaves mascaradas para a UI) |

## Modos

- **Chat interno** (funcionário no /ai): Task `ia` com announce `{"channel":"mcp"}`, privada do autor. Tools MCP somente leitura, executadas como o funcionário (worker usa `Integration.user`). Websocket só para o device que perguntou.
- **Conversa de cliente** (WhatsApp, e-mail ou cliente logado no /ai): a IA responde enquanto `tasks.ai_agent` estiver preenchido. O que a IA escreve é público e sai pela conexão da conversa. Tools: `classify_task` (título e etapa do CRM/suporte) e `handoff_to_human` (ia → relationship/support e remove a IA). Sem acesso a dados internos. Websocket para devices `MANAGER`.
- **Funcionário responde o cliente** no CRM/Suporte (`POST /task_interations`, `visibility: public`): a mensagem vai ao cliente e a IA deixa a conversa.
- **Atribuir IA** (`POST /mcp/chat/assign {task_id, instructions?, provider?}`): coloca a IA num ticket de CRM/suporte; as instruções viram nota interna e a IA faz o primeiro contato (telefone/e-mail do cliente são copiados para o ticket se faltarem).
- **Assumir** (`POST /mcp/chat/handoff {task_id, type}`): funcionário tira a IA do ticket.

## Eventos websocket (`store: websocket`)

`mcp_chat_reply` com `status` `processing|done|error`, `task_id`, `interaction_id`, `user_interaction_id`, `channel`, `handoff`;
`conversation_message` quando chega mensagem do cliente.

## Correções em relação à 1.0.27

- `Task::setAlterDate` não existia → o worker quebrava após a resposta da IA (nenhuma resposta chegava ao /ai).
- Modelo padrão Claude `claude-sonnet-4` (ID inválido) → `claude-opus-5-5`; `temperature` removido (rejeitado pelos modelos atuais).
- Histórico enviava respostas da IA como `user` e duplicava a pergunta atual.
- Websocket ia para todos os devices da empresa e gravava linhas `Websocket` com `device=null`.
