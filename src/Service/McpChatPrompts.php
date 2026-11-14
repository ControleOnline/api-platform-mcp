<?php

declare(strict_types=1);

namespace ControleOnline\Service;

use ControleOnline\Entity\People;
use ControleOnline\Entity\Task;
use ControleOnline\Entity\TaskInteration;

/**
 * System prompts and conversation history for the chat channels.
 */
class McpChatPrompts
{
    private const GOALS = [
        'ia' => 'Understand what the customer needs. Solve simple questions yourself. When it is a sales matter, '
            . 'hand off with type relationship; when it is help with the product, access or billing, hand off with type support.',
        'relationship' => 'This is a sales opportunity (CRM). Qualify it: understand the need, size, timing and decision maker, '
            . 'answer what you can, keep the stage updated with classify_task, and hand off to the salesperson when the '
            . 'customer is ready for a proposal or asks for a person.',
        'support' => 'This is a support ticket. Collect everything the analyst needs to solve it: what happens, since when, '
            . 'steps to reproduce, account/company, screenshots or examples. Keep the stage updated with classify_task and '
            . 'hand off to the analyst with a complete summary.',
    ];

    public function system(string $channel, string $trigger, People $company, Task $task, string $custom): string
    {
        $companyLabel = trim((string) ($company->getAlias() ?: $company->getName())) . ' (company_id ' . $company->getId() . ')';

        if ($channel === McpChatToolbox::CHANNEL_WEB) {
            $base = "You are the ControleOnline assistant for {$companyLabel}. You talk with an employee of the company.\n"
                . 'Answer with real data: use the tools (start with list_query_datasets / query_business_data, scoped to this '
                . 'company) before stating any number, and say plainly when the data is not available. Never invent figures. '
                . "Reply in the user's language, concisely.
"
                . 'Try to solve the request yourself. If you cannot (missing data or permissions, a bug, something only a person '
                . 'can do) or the user asks for a person, call handoff_to_human with support or relationship.';
        } else {
            $client = trim((string) $task->getClient()?->getName());
            $base = "You are the virtual attendant of {$companyLabel}, talking with a customer"
                . ($client !== '' ? " named {$client}" : '') . ". Ticket: \"{$task->getName()}\" ({$task->getType()}).\n"
                . (self::GOALS[$task->getType()] ?? self::GOALS['ia']) . "\n"
                . 'Everything you write is sent to the customer. You have no access to internal data: never invent prices, '
                . 'deadlines, stock or policies. Messages marked [Interno] are from employees and are never seen by the '
                . "customer; follow their instructions. Write short plain-text messages (no markdown) in the customer's language.";
            if ($trigger === 'assign') {
                $base .= "\nYou were just assigned to this ticket: write the first message to the customer now, introducing "
                    . 'yourself on behalf of the company and following the employee instructions.';
            }
        }

        return trim($custom) !== '' ? $base . "\n\nCompany instructions:\n" . trim($custom) : $base;
    }

    /**
     * The company (AI) is "assistant"; everyone else is "user". In customer conversations, employee
     * messages and private notes are labelled [Interno] so the model never mistakes them for the customer.
     *
     * @param list<TaskInteration> $rows oldest first
     * @return list<array{role: string, content: string}>
     */
    public function history(array $rows, People $company, Task $task, string $channel): array
    {
        $clientId = (int) $task->getClient()?->getId();
        $history = [];
        foreach ($rows as $row) {
            $body = trim((string) $row->getBody());
            if ($body === '') {
                continue;
            }
            $authorId = (int) $row->getRegisteredBy()?->getId();
            $private = $row->getVisibility() !== 'public';

            if ($authorId === (int) $company->getId()) {
                $role = 'assistant';
                $content = $channel === McpChatToolbox::CHANNEL_CUSTOMER && $private ? "[Interno — nota] {$body}" : $body;
            } elseif ($channel === McpChatToolbox::CHANNEL_WEB || $authorId === $clientId) {
                $role = 'user';
                $content = $body;
            } else {
                $role = 'user';
                $who = trim((string) $row->getRegisteredBy()?->getName());
                $content = $private
                    ? "[Interno — {$who}] {$body}"
                    : "[Interno — {$who} respondeu ao cliente] {$body}";
            }
            $history[] = ['role' => $role, 'content' => $content];
        }
        // Providers expect the conversation to start with the user.
        while ($history !== [] && $history[0]['role'] === 'assistant') {
            array_shift($history);
        }
        // ...and to end with the user (current models reject an assistant prefill).
        if ($history === [] || $history[array_key_last($history)]['role'] === 'assistant') {
            $history[] = ['role' => 'user', 'content' => $history === [] ? '[Interno] Inicie o atendimento.' : '[Interno] Continue o atendimento.'];
        }

        return $history;
    }
}
