[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/controleonline/api-platform-mcp/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/controleonline/api-platform-mcp/?branch=master)

# mcp

Integração do [Model Context Protocol (MCP)](https://modelcontextprotocol.io/) com a API ControleOnline.

Expõe o endpoint HTTP `/mcp` para agentes de IA (LLMs) consultarem dados e, quando a aplicação registra um provider de escrita autorizado, criarem operações de negócio limitadas.

## Escopo atual

- Transporte HTTP em `/mcp`
- Autenticação OAuth 2.1 com Authorization Code + PKCE; a pessoa autentica na tela ControleOnline (`MANAGER_APP`)
- Consultas limitadas ao tenant do token e às empresas acessíveis pelo usuário (`mycompanies`)
- `securityFilter` da API aplicado às consultas de pedidos, vendas e faturas; produtos e estoque filtrados explicitamente pelas empresas acessíveis
- Projeções fixas sem documentos, descrições livres, dados de contato ou serialização genérica de entidades
- Escritas disponíveis somente com provider explicitamente registrado pela aplicação; o pacote permanece somente para consulta sem esse provider
- Movimentações de estoque feitas por pedidos de compra, venda e transferência; os triggers do banco atualizam os saldos

## Tools disponíveis

- `list_my_companies`: empresas habilitadas que o usuário pode acessar.
- `list_query_datasets`: áreas de dados habilitadas: `sales`, `orders`, `invoices`, `products`, `inventory`, `wallets`, `employees`, `clients`, `suppliers`, `salespeople`, `commissions`, `configs`, `devices`, `displays` e `production_queue`.
- `query_business_data`: consulta com intervalo opcional de datas, empresa e limite de até 100 linhas. Para perguntas por período, informe `from` e `to`; use `list_my_companies` antes para resolver `company_id`.
- `dataset=orders` consulta pedidos de todos os tipos; `dataset=sales` consulta somente vendas encerradas.
- `configs` consulta somente metadados da configuração `devices`; valores de configuração e segredos não são retornados. `devices` lista aliases e tipos configurados para as empresas acessíveis.
- `displays` lista displays e filas vinculadas às empresas acessíveis; `production_queue` retorna itens de preparação de pedidos de venda e seu status operacional.
- Pedidos e faturas usam somente empresas acessíveis ao usuário: cliente/fornecedor nos pedidos e pagador/recebedor nas faturas.
- Pessoas e comissões são lidas por vínculos ativos com essas empresas; comissões só são incluídas quando `PeopleLinkService` autoriza o usuário a gerenciar a empresa do vendedor.
- Projeções de pessoas retornam nome e vínculo, sem documentos, telefone, e-mail, endereço ou credenciais.
- `query_business_data` com `aggregate: true`: total e quantidade de vendas encerradas no intervalo (sem truncar o agregado ao limite de linhas).
- `health_check` e `list_capabilities`: estado e descoberta do servidor.
- `write_business_data` (quando habilitada pela aplicação): configuração de devices, produtos e pedidos de compra, venda e transferência, sempre sob as regras e permissões da API.

Datas de vendas e faturas usam o fuso `APP_TIMEZONE` da API. O escopo de empresas é recalculado no banco do tenant para cada solicitação; `app-domain`, `Origin` e `Referer` enviados pelo cliente não alteram o tenant de um token.

## Configuração e validação local

1. Instale a versão da task dos módulos API `controleonline/mcp` e `controleonline/users` no `api-community` e registre o provider de consultas no container.
2. Confirme que `app-community/config/env.local.js` tem `MANAGER_APP` definido para a tela de consentimento OAuth. O módulo `ui-login` hospeda essa tela e preserva o pedido durante o login.
3. Configure o cliente MCP para conectar a `https://api.controleonline.com/mcp`; o cliente inicia o OAuth via metadata, abre a tela ControleOnline e guarda o Bearer token localmente.
4. Execute os testes dos módulos MCP e users e `bin/console lint:container --env=test` no `api-community`.

O fluxo usa `cache.app` e o `lock.factory` da Symfony para consumir cada código de autorização uma só vez. Em instalações com mais de um nó API, configure ambos com armazenamento compartilhado entre os nós.

Mais detalhes: [fluxo OAuth e isolamento por tenant](docs/oauth-multitenancy.md).

## Instalacao

[Instalacao na wiki](https://github.com/ControleOnline/api-platform-mcp/wiki/Instalacao)

## Links obrigatorios

- [Documentacao para clientes](http://ajuda.controleonline.com/)
- [Site institucional](http://controleonline.com/)
- [Wiki tecnica](https://github.com/ControleOnline/api-platform-mcp/wiki)
