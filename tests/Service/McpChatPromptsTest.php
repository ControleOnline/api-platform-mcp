<?php

declare(strict_types=1);

namespace ControleOnline\Mcp\Tests\Service;

use ControleOnline\Entity\People;
use ControleOnline\Entity\Task;
use ControleOnline\Entity\TaskInteration;
use ControleOnline\Service\McpChatPrompts;
use ControleOnline\Service\McpChatToolbox;
use PHPUnit\Framework\TestCase;

final class McpChatPromptsTest extends TestCase
{
    private function people(int $id, string $name = ''): People
    {
        $p = $this->createStub(People::class);
        $p->method('getId')->willReturn($id);
        $p->method('getName')->willReturn($name);

        return $p;
    }

    private function row(People $by, string $body, string $visibility): TaskInteration
    {
        $r = $this->createStub(TaskInteration::class);
        $r->method('getRegisteredBy')->willReturn($by);
        $r->method('getBody')->willReturn($body);
        $r->method('getVisibility')->willReturn($visibility);

        return $r;
    }

    public function testCustomerHistoryLabelsEmployeesAndEndsWithUser(): void
    {
        $company = $this->people(1);
        $client = $this->people(2, 'Maria');
        $seller = $this->people(3, 'Ana');
        $task = $this->createStub(Task::class);
        $task->method('getClient')->willReturn($client);

        $history = (new McpChatPrompts())->history([
            $this->row($company, 'Olá! Posso ajudar?', 'public'),      // dropped: must start with user
            $this->row($client, 'Quero um orçamento', 'public'),
            $this->row($company, 'Claro, para quantas lojas?', 'public'),
            $this->row($seller, 'Ofereça o plano anual', 'private'),
            $this->row($seller, 'Oi Maria, sou a Ana', 'public'),
            $this->row($company, 'resumo', 'private'),
        ], $company, $task, McpChatToolbox::CHANNEL_CUSTOMER);

        self::assertSame(['user', 'assistant', 'user', 'user', 'assistant', 'user'], array_column($history, 'role'));
        self::assertSame('Quero um orçamento', $history[0]['content']);
        self::assertSame('[Interno — Ana] Ofereça o plano anual', $history[2]['content']);
        self::assertSame('[Interno — Ana respondeu ao cliente] Oi Maria, sou a Ana', $history[3]['content']);
        self::assertSame('[Interno — nota] resumo', $history[4]['content']);
        self::assertSame('[Interno] Continue o atendimento.', $history[5]['content']);
    }

    public function testInternalChatKeepsPlainRoles(): void
    {
        $company = $this->people(1);
        $employee = $this->people(5, 'Luiz');
        $task = $this->createStub(Task::class);
        $task->method('getClient')->willReturn($employee);

        $history = (new McpChatPrompts())->history([
            $this->row($employee, 'quantos pedidos hoje?', 'private'),
        ], $company, $task, McpChatToolbox::CHANNEL_WEB);

        self::assertSame([['role' => 'user', 'content' => 'quantos pedidos hoje?']], $history);
    }

    public function testEmptyHistoryStartsTheConversation(): void
    {
        $task = $this->createStub(Task::class);
        $history = (new McpChatPrompts())->history([], $this->people(1), $task, McpChatToolbox::CHANNEL_CUSTOMER);

        self::assertSame('[Interno] Inicie o atendimento.', $history[0]['content']);
    }
}
