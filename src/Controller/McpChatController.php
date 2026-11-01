<?php

declare(strict_types=1);

namespace ControleOnline\Controller;

use ControleOnline\Service\McpChatOrchestratorService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * App-facing chat endpoint (not MCP JSON-RPC).
 * Static path /mcp/chat must not be captured by /mcp/{tenantDomain}.
 */
#[Route('/mcp')]
class McpChatController
{
    public function __construct(
        private readonly McpChatOrchestratorService $orchestrator,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    #[Route('/chat', name: 'controleonline_mcp_chat', methods: ['POST', 'OPTIONS'], priority: 10)]
    public function chat(Request $request): Response
    {
        if ($request->getMethod() === 'OPTIONS') {
            return new Response('', Response::HTTP_NO_CONTENT, $this->corsHeaders());
        }
        if (!$this->authorizationChecker->isGranted('ROLE_HUMAN')) {
            return new JsonResponse(['error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED, $this->corsHeaders());
        }
        $payload = json_decode($request->getContent() ?: '{}', true);
        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST, $this->corsHeaders());
        }
        try {
            $result = $this->orchestrator->chat($payload);
            return new JsonResponse($result, Response::HTTP_OK, $this->corsHeaders());
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST, $this->corsHeaders());
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_GATEWAY, $this->corsHeaders());
        }
    }

    #[Route('/chat/providers', name: 'controleonline_mcp_chat_providers', methods: ['GET', 'OPTIONS'], priority: 10)]
    public function providers(Request $request): Response
    {
        if ($request->getMethod() === 'OPTIONS') {
            return new Response('', Response::HTTP_NO_CONTENT, $this->corsHeaders());
        }
        if (!$this->authorizationChecker->isGranted('ROLE_HUMAN')) {
            return new JsonResponse(['error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED, $this->corsHeaders());
        }
        // company resolved inside list via token; optional ?company_id=
        $companyId = $request->query->getInt('company_id') ?: null;
        try {
            $company = $this->orchestrator->resolveCompany($companyId);
            $list = $this->orchestrator->listProviders($company);
            return new JsonResponse(['providers' => $list], Response::HTTP_OK, $this->corsHeaders());
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST, $this->corsHeaders());
        }
    }

    /** @return array<string, string> */
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
