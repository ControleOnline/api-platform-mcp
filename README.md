[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/controleonline/api-platform-mcp/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/controleonline/api-platform-mcp/?branch=master)

# mcp

Integração do [Model Context Protocol (MCP)](https://modelcontextprotocol.io/) com a API ControleOnline.

Expõe o endpoint HTTP `/mcp` para agentes de IA (LLMs) descobrirem e invocarem **apenas tools de consulta (read-only)** nesta entrega.

## Escopo atual

- Transporte HTTP em `/mcp`
- Tools/resources de leitura (listagens e consultas autenticadas ou públicas já existentes)
- Sem tools de mutação (POST/PUT/PATCH/DELETE)

## Instalacao

[Instalacao na wiki](https://github.com/ControleOnline/api-platform-mcp/wiki/Instalacao)

## Links obrigatorios

- [Documentacao para clientes](http://ajuda.controleonline.com/)
- [Site institucional](http://controleonline.com/)
- [Wiki tecnica](https://github.com/ControleOnline/api-platform-mcp/wiki)
