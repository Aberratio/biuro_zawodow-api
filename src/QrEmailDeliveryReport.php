<?php

declare(strict_types=1);

/**
 * Koreluje statusy maili zwrocone przez central-mailer z uczestnikami wydarzenia.
 * Czysta logika bez IO — latwa do testowania.
 */
final class QrEmailDeliveryReport
{
    /**
     * @param array<int, array<string, mixed>> $participants wiersze participants (id, display_name, email, bib_number, email_status)
     * @param array<int, array<string, mixed>> $mailerEmails payloady maili z GET /emails/context/{id} (posortowane od najnowszych)
     * @return array{summary: array<string, int>, participants: list<array<string, mixed>>}
     */
    public static function build(array $participants, array $mailerEmails): array
    {
        $byParticipantId = [];
        $byEmail = [];
        foreach ($mailerEmails as $email) {
            $metadata = is_array($email['metadata'] ?? null) ? $email['metadata'] : [];
            $participantId = (int)($metadata['participantId'] ?? 0);
            if ($participantId > 0) {
                $byParticipantId[$participantId][] = $email;
            }
            $recipient = strtolower(trim((string)($email['to'] ?? '')));
            if ($recipient !== '') {
                $byEmail[$recipient][] = $email;
            }
        }

        $summary = [
            'participants_total' => count($participants),
            'sent' => 0,
            'queued' => 0,
            'failed' => 0,
            'bounced' => 0,
            'unknown' => 0,
            'no_data' => 0,
        ];
        $rows = [];
        foreach ($participants as $participant) {
            $participantId = (int)$participant['id'];
            $participantEmail = strtolower(trim((string)($participant['email'] ?? '')));

            $localEmailStatus = (string)($participant['email_status'] ?? 'not_sent');

            $candidates = $byParticipantId[$participantId] ?? [];
            if ($candidates === [] && $participantEmail !== '' && $localEmailStatus === 'sent') {
                // Fallback dla maili sprzed przekazywania participantId w metadata.
                // Tylko dla uczestnikow z lokalnie odnotowana wysylka: po skasowaniu i ponownym
                // wczytaniu listy w kontekscie wydarzenia zostaja maile z nieaktualnymi kodami QR,
                // wyslane do tego samego adresu, ale do juz nieistniejacych rekordow uczestnikow.
                $candidates = $byEmail[$participantEmail] ?? [];
            }
            $delivery = self::delivery($candidates);

            if ($delivery !== null) {
                $bucket = self::summaryBucket((string)$delivery['effective_status']);
                $summary[$bucket]++;
            } elseif ($localEmailStatus === 'sent') {
                // Wyslano przed wdrozeniem sledzenia — brak danych o dostarczeniu.
                $summary['no_data']++;
            }

            $rows[] = [
                'participant_id' => $participantId,
                'name' => (string)($participant['display_name'] ?? ''),
                'email' => (string)($participant['email'] ?? ''),
                'bib_number' => (string)($participant['bib_number'] ?? ''),
                'local_email_status' => $localEmailStatus,
                'delivery' => $delivery,
            ];
        }

        return ['summary' => $summary, 'participants' => $rows];
    }

    /** @param list<array<string, mixed>> $candidates */
    private static function delivery(array $candidates): ?array
    {
        if ($candidates === []) {
            return null;
        }

        usort(
            $candidates,
            static fn (array $a, array $b): int => strcmp((string)($b['createdAt'] ?? ''), (string)($a['createdAt'] ?? ''))
        );
        $latest = $candidates[0];

        return [
            'email_id' => (string)($latest['id'] ?? ''),
            'status' => (string)($latest['status'] ?? ''),
            'effective_status' => (string)($latest['effectiveStatus'] ?? $latest['status'] ?? ''),
            'is_batch' => ($latest['batchId'] ?? null) !== null,
            'batch_id' => $latest['batchId'] ?? null,
            'sent_at' => $latest['sentAt'] ?? null,
            'created_at' => $latest['createdAt'] ?? null,
            'last_error' => $latest['lastError'] ?? null,
            'send_count' => count($candidates),
        ];
    }

    private static function summaryBucket(string $effectiveStatus): string
    {
        return match ($effectiveStatus) {
            'sent' => 'sent',
            'pending', 'processing', 'retry' => 'queued',
            'failed', 'suppressed' => 'failed',
            'bounced' => 'bounced',
            default => 'unknown',
        };
    }
}
