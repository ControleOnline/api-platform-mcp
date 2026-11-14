<?php

declare(strict_types=1);

namespace ControleOnline\Service;

use ControleOnline\Entity\People;
use ControleOnline\Entity\Task;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Tickets from /ai to the platform company (the API domain's company, e.g. Controle Online).
 *
 * An employee of a client company whose company has no AI configured does not get an AI chat:
 * the message opens a relationship (sales) or support ticket for the platform. The platform's team
 * follows it in CRM / Support and may put its own AI on it (spending the platform's tokens).
 * The client employee follows and answers the ticket from /ai.
 */
class McpChatTickets
{
    public const TYPES = ['relationship', 'support'];
    /** Ticket opened from /ai: the client follows it there, no WhatsApp/e-mail destination is added. */
    public const VIA_AI = 'via:ai';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StatusService $statusService,
        private readonly ?DomainService $domainService = null,
    ) {
    }

    public function platform(): People
    {
        $platform = $this->domainService?->getPeopleDomain()->getPeople();
        if (!$platform instanceof People) {
            throw new \RuntimeException('Platform company not resolved for this domain');
        }

        return $platform;
    }

    /** A ticket the user opened (they are its client); never an internal chat. */
    public function findOwn(int $taskId, People $user): ?Task
    {
        $task = $this->em->getRepository(Task::class)->find($taskId);
        if (!$task instanceof Task || (int) $task->getClient()?->getId() !== (int) $user->getId()) {
            return null;
        }
        $announce = $task->getAnnounce(true);
        $internal = $task->getType() === 'ia' && !array_is_list($announce) && ($announce['channel'] ?? '') === 'mcp';

        return $internal ? null : $task;
    }

    public function open(People $user, People $company, string $type, string $message): Task
    {
        $type = in_array($type, self::TYPES, true) ? $type : 'support';
        $platform = $this->platform();
        if ((int) $platform->getId() === (int) $company->getId()) {
            throw new \InvalidArgumentException('Configure a IA da empresa em Configurador → IA.');
        }

        $task = new Task();
        $task->setType($type);
        $task->setName(mb_substr(trim((string) ($company->getAlias() ?: $company->getName())) . ': ' . $message, 0, 50));
        $task->setRegisteredBy($user);
        $task->setClient($user);
        $task->setProvider($platform);
        $task->setTaskStatus($this->statusService->discoveryStatus('open', 'open', $type));
        $task->setAnnounce([self::VIA_AI]);
        $this->em->persist($task);
        $this->em->flush();

        return $task;
    }

    /** CRM/support tickets reached by AI need a destination: copy the client's phone/e-mail if missing. */
    public function addCustomerContacts(Task $task): void
    {
        $announce = $task->getAnnounce(true);
        $hasContact = array_is_list($announce) && array_filter($announce, static fn ($v) => is_string($v)
            && (preg_match('/^\d{10,15}$/', $v) || filter_var($v, FILTER_VALIDATE_EMAIL)));
        if ($hasContact || !$task->getClient() || (array_is_list($announce) && in_array(self::VIA_AI, $announce, true))) {
            return;
        }
        foreach ($task->getClient()->getPhone() as $phone) {
            $task->addAnnounce($phone->getDdi() . $phone->getDdd() . $phone->getPhone());
            return;
        }
        foreach ($task->getClient()->getEmail() as $email) {
            $task->addAnnounce(strtolower($email->getEmail()));
            return;
        }
    }
}
