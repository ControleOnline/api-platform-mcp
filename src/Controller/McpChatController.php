<?php

declare(strict_types=1);

namespace ControleOnline\Controller;

use ControleOnline\Service\McpChatService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Chat UI backend for /mcp front: uses Task type=ia + TaskInteration.
 * Does not replace the JSON-RPC MCP endpoint on /mcp.
 */
#[Route('/mcp')]
class McpChatController
{
    public function __construct(
        private readonly McpChatService $chatService,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    #[Route('/chat/providers', name: 'controleonline_mcp_chat_providers', methods: ['GET', 'OPTIONS'])]
    public function providers(Request $request): Response
    {
        if ($request->getMethod() === 'OPTIONS') {
            return new Response('', Response::HTTP_NO_CONTENT, $this->corsHeaders());
        }
        if (!$this->authorizationChecker->isGranted('ROLE_HUMAN')) {
            return new JsonResponse(['error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED, $this->corsHeaders());
        }

        return new JsonResponse(
            ['providers' => $this->chatService->listConfiguredProviders()],
            Response::HTTP_OK,
            $this->corsHeaders()
        );
    }

    #[Route('/chat', name: 'controleonline_mcp_chat', methods: ['POST', 'OPTIONS'])]
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
            $result = $this->chatService->chat($payload);
            return new JsonResponse($result, Response::HTTP_OK, $this->corsHeaders());
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST, $this->corsHeaders());
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'Chat failed'], Response::HTTP_INTERNAL_SERVER_ERROR, $this->corsHeaders());
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
