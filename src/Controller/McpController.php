<?php

declare(strict_types=1);

namespace ControleOnline\Controller;

use ControleOnline\Service\McpServerService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Minimal HTTP MCP endpoint (Model Context Protocol).
 * Read-only tools only in this delivery.
 *
 * @see https://modelcontextprotocol.io/
 */
#[Route('/mcp')]
class McpController
{
    public function __construct(
        private readonly McpServerService $mcpServer,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    /**
     * MCP JSON-RPC entry (initialize, tools/list, tools/call, resources/list, ...).
     * GET returns a short discovery payload; POST handles JSON-RPC methods.
     */
    #[Route('', name: 'controleonline_mcp', methods: ['GET', 'POST', 'OPTIONS'])]
    #[Route('/{tenantDomain}', name: 'controleonline_mcp_tenant', methods: ['GET', 'POST', 'OPTIONS'], requirements: ['tenantDomain' => '[A-Za-z0-9.-]+'])]
    public function __invoke(Request $request, ?string $tenantDomain = null): Response
    {
        if ($request->getMethod() === 'OPTIONS') {
            return new Response('', Response::HTTP_NO_CONTENT, $this->corsHeaders());
        }

        if (!$this->authorizationChecker->isGranted('ROLE_HUMAN')) {
            return new JsonResponse(
                ['error' => 'Authentication required'],
                Response::HTTP_UNAUTHORIZED,
                $this->corsHeaders() + [
                    'WWW-Authenticate' => 'Bearer realm="ControleOnline MCP", resource_metadata="/.well-known/oauth-protected-resource"',
                ]
            );
        }

        if ($request->getMethod() === 'GET') {
            return new JsonResponse(
                $this->mcpServer->discovery(),
                Response::HTTP_OK,
                $this->corsHeaders()
            );
        }

        $payload = json_decode($request->getContent() ?: '{}', true);
        if (!is_array($payload)) {
            return new JsonResponse(
                ['jsonrpc' => '2.0', 'error' => ['code' => -32700, 'message' => 'Parse error'], 'id' => null],
                Response::HTTP_BAD_REQUEST,
                $this->corsHeaders()
            );
        }

        $result = $this->mcpServer->handle($payload);

        return new JsonResponse($result, Response::HTTP_OK, $this->corsHeaders());
    }

    /**
     * @return array<string, string>
     */
    private function corsHeaders(): array
    {
        return [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, Accept',
            'Content-Type' => 'application/json',
        ];
    }
}
