# Chat IA (/ai) e atendimento WhatsApp

Issue: ControleOnline/api-platform-mcp#82 · Parent: ControleOnline/app-community#1335

| Classe | Papel |
| --- | --- |
| `McpChatService` | Orquestra: `POST /mcp/chat` grava a pergunta e enfileira `McpChat`; o worker roda a IA, grava a resposta e emite websocket |
| `McpChatLlmClient` | Loop de tool-use (OpenAI-compatible/Grok, Anthropic Messages, Gemini), até 8 rodadas |
| `McpChatToolbox` | Tools por canal: **web** = tools MCP somente leitura executadas como o usuário que perguntou; **whatsapp** = apenas `handoff_to_human` |
| `McpChatProviderConfig` | Config `ai-providers` da empresa (chaves mascaradas para a UI) |

## Canais

- **web**: Task `ia` privada do usuário. Worker usa `Integration.user` como token, então o escopo de empresas das tools é o do usuário. Websocket só para o device que enviou (`device` header).
- **whatsapp**: job criado por `WhatsAppCloudService` (api-platform-integration). Cliente externo **não** acessa dados internos. Resposta `public` → `TaskInterationService::notifyClient` → WhatsApp. `handoff_to_human` converte a task em `relationship` (comercial/CRM) ou `support` (analista) e a IA para de responder. Websocket para devices `MANAGER` da empresa.

## Eventos websocket (`store: websocket`)

`mcp_chat_reply` com `status` `processing|done|error`, `task_id`, `interaction_id`, `user_interaction_id`, `channel`, `handoff`;
`conversation_message` quando chega mensagem do cliente.

## Correções em relação à 1.0.27

- `Task::setAlterDate` não existia → o worker quebrava após a resposta da IA (nenhuma resposta chegava ao /ai).
- Modelo padrão Claude `claude-sonnet-4` (ID inválido) → `claude-opus-5-5`; `temperature` removido (rejeitado pelos modelos atuais).
- Histórico enviava respostas da IA como `user` e duplicava a pergunta atual.
- Websocket ia para todos os devices da empresa e gravava linhas `Websocket` com `device=null`.
