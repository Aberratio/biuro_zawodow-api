<?php

declare(strict_types=1);

require_once __DIR__ . '/PlatformWebhookStore.php';
require_once __DIR__ . '/PlatformParticipantSync.php';

/**
 * Applies an authenticated critical-change webhook: records it once (idempotency receipt) and marks,
 * never deletes, the participant. Serialised with the participant pull through the same per-event lock.
 */
final class PlatformCriticalChangeService
{
    /** A pull that started this much after the cancellation proves the platform still listed the person. */
    private const SUPERSEDE_MARGIN_SECONDS = 120;
    private const LOCK_WAIT_SECONDS = 5;
    private const RETRY_AFTER_SECONDS = 60;
    public const ACTOR_NAME = 'Platforma Zmierzymy Czas';

    /** @var callable(string, string, string, array<string, mixed>): void */
    private $logger;

    /** @param null|callable(string $level, string $code, string $message, array $context): void $logger */
    public function __construct(private readonly PlatformWebhookStore $store, ?callable $logger = null)
    {
        $this->logger = $logger ?? static function (string $level, string $code, string $message, array $context): void {
            ApplicationLogger::log($level, $code, $message, $context);
        };
    }

    /**
     * @param array<string, mixed> $request parsed by PlatformWebhookRequest
     * @return array{http: int, status: string, retry_after: ?int}
     */
    public function handle(string $eventId, array $request, string $nowUtc): array
    {
        $existing = $this->store->findReceipt($eventId, (string)$request['dedupe_key']);
        if ($existing !== null) {
            return $this->duplicate($eventId, $request, $existing);
        }

        if (!$this->store->lockEvent($eventId, self::LOCK_WAIT_SECONDS)) {
            return ['http' => 503, 'status' => 'busy', 'retry_after' => self::RETRY_AFTER_SECONDS];
        }

        try {
            $this->store->begin();
            $receiptId = $this->store->insertReceipt([
                'event_id' => $eventId,
                'dedupe_key' => (string)$request['dedupe_key'],
                'platform_registration_id' => (string)$request['registration_id'],
                'change_type' => (string)$request['change_type'],
                'occurred_at' => $request['occurred_at'],
                'fingerprint' => (string)$request['fingerprint'],
                'outcome' => 'received',
                'received_at' => $nowUtc,
            ]);
            if ($receiptId === null) {
                $this->store->rollBack();

                return ['http' => 200, 'status' => 'duplicate', 'retry_after' => null];
            }

            [$outcome, $participantId] = $this->apply($eventId, $request, $receiptId, $nowUtc);
            $this->store->touchWebhook($eventId, $nowUtc);
            $this->store->setReceiptOutcome($receiptId, $outcome, $participantId);
            $this->store->commit();

            return ['http' => 200, 'status' => $outcome, 'retry_after' => null];
        } catch (Throwable $exception) {
            $this->store->rollBack();
            ($this->logger)('error', 'platform_webhook.failed', 'Platform webhook could not be applied', [
                'event_id' => $eventId,
                'change_type' => (string)$request['change_type'],
                'exception_class' => $exception::class,
            ]);

            return ['http' => 500, 'status' => 'error', 'retry_after' => null];
        } finally {
            $this->store->unlockEvent($eventId);
        }
    }

    /** @return array{http: int, status: string, retry_after: ?int} */
    private function duplicate(string $eventId, array $request, array $existing): array
    {
        if (!hash_equals((string)($existing['fingerprint'] ?? ''), (string)$request['fingerprint'])) {
            ($this->logger)('warning', 'platform_webhook.dedupe_key_reused', 'Platform webhook reused an idempotency key for different content', [
                'event_id' => $eventId,
                'change_type' => (string)$request['change_type'],
            ]);
        }

        return ['http' => 200, 'status' => 'duplicate', 'retry_after' => null];
    }

    /** @return array{0: string, 1: ?int} outcome, participant id */
    private function apply(string $eventId, array $request, int $receiptId, string $nowUtc): array
    {
        $type = (string)$request['change_type'];
        if (!in_array($type, ['cancellation', 'refund_approved', 'refund_refunded'], true)) {
            ($this->logger)('warning', 'platform_webhook.unknown_type', 'Platform webhook with an unknown change type ignored', [
                'event_id' => $eventId,
                'change_type' => $type,
            ]);

            return ['ignored_unknown_type', null];
        }

        $participant = $this->store->findParticipant($eventId, (string)$request['registration_id']);
        if ($participant === null) {
            if ($type !== 'cancellation') {
                $this->store->requestPull($eventId, $nowUtc);
            }

            return ['participant_not_found', null];
        }

        $participantId = (int)$participant['id'];
        $name = (string)($participant['display_name'] ?? '');
        $metadata = [
            'change_type' => $type,
            'receipt_id' => $receiptId,
            'platform_registration_id' => (string)$request['registration_id'],
        ];
        $withdrawnText = 'Platforma Zmierzymy Czas: rezygnacja lub anulowanie zapisu — uczestnik oznaczony jako wycofany';

        if ($type === 'refund_approved') {
            $this->store->addActivityLog($eventId, 'Platforma Zmierzymy Czas: zatwierdzono zwrot opłaty', $metadata + ['outcome' => 'recorded'], $participantId, $name);

            return ['recorded', $participantId];
        }

        if ($type === 'refund_refunded') {
            $this->store->addActivityLog($eventId, 'Platforma Zmierzymy Czas: zwrot wykonany — lista uczestników zostanie odświeżona', $metadata + ['outcome' => 'recorded'], $participantId, $name);
            $this->store->requestPull($eventId, $nowUtc);

            return ['recorded', $participantId];
        }

        if (($participant['platform_removed_at'] ?? null) !== null) {
            $this->store->addActivityLog($eventId, $withdrawnText, $metadata + ['outcome' => 'already_removed'], $participantId, $name);

            return ['already_removed', $participantId];
        }

        $occurredAt = $request['occurred_at'] !== null ? (string)$request['occurred_at'] : $nowUtc;
        if ($this->supersededByPull($eventId, $occurredAt)) {
            $this->store->addActivityLog($eventId, 'Platforma Zmierzymy Czas: zgłoszenie rezygnacji starsze niż ostatnie pobranie listy — bez zmian, lista zostanie odświeżona', $metadata + ['outcome' => 'superseded_by_pull'], $participantId, $name);
            $this->store->requestPull($eventId, $nowUtc);

            return ['superseded_by_pull', $participantId];
        }

        if (!$this->store->markParticipantRemoved($participantId, min($occurredAt, $nowUtc))) {
            return ['already_removed', $participantId];
        }

        $state = PlatformParticipantSync::changeState($participant);
        $this->store->addChangeLog($eventId, [
            'change_type' => 'updated',
            'change_source' => 'platform_webhook',
            'participant_id' => $participantId,
            'participant_audit_key' => (string)$participant['participant_audit_key'],
            'before_state' => $state,
            'after_state' => $state,
            'changed_fields' => ['platform_removed_at'],
            'user_id' => null,
            'user_name' => self::ACTOR_NAME,
        ]);
        $this->store->addActivityLog($eventId, $withdrawnText, $metadata + ['outcome' => 'applied'], $participantId, $name);

        return ['applied', $participantId];
    }

    private function supersededByPull(string $eventId, string $occurredAtUtc): bool
    {
        $state = $this->store->connectionPullState($eventId);
        $started = (string)($state['last_pull_started_at'] ?? '');
        if ($started === '' || !in_array((string)($state['last_pull_status'] ?? ''), ['ok', 'partial'], true)) {
            return false;
        }

        $startedAt = strtotime($started . ' UTC');
        $occurred = strtotime($occurredAtUtc . ' UTC');
        if ($startedAt === false || $occurred === false) {
            return false;
        }

        return $startedAt > $occurred + self::SUPERSEDE_MARGIN_SECONDS;
    }
}
