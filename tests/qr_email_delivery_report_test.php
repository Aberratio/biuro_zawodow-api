<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/QrEmailDeliveryReport.php';

function qrDeliveryParticipant(int $id, string $email, string $emailStatus = 'sent', string $name = 'Jan Kowalski'): array
{
    return [
        'id' => $id,
        'display_name' => $name,
        'email' => $email,
        'bib_number' => (string)$id,
        'email_status' => $emailStatus,
    ];
}

function qrDeliveryMailerEmail(array $overrides = []): array
{
    return array_merge([
        'id' => 'mail-1',
        'to' => 'jan@x.pl',
        'status' => 'sent',
        'effectiveStatus' => 'sent',
        'batchId' => null,
        'sentAt' => '2026-07-01 10:05:00',
        'createdAt' => '2026-07-01 10:00:00',
        'lastError' => null,
        'metadata' => ['participantId' => 1],
    ], $overrides);
}

test('correlates mailer email with participant by participantId metadata', function (): void {
    $report = QrEmailDeliveryReport::build(
        [qrDeliveryParticipant(1, 'inny-adres@x.pl')],
        [qrDeliveryMailerEmail()]
    );

    $delivery = $report['participants'][0]['delivery'];
    assertNotNullValue($delivery);
    assertSame('mail-1', $delivery['email_id']);
    assertSame('sent', $delivery['effective_status']);
    assertFalse($delivery['is_batch']);
    assertSame(1, $delivery['send_count']);
    assertSame(1, $report['summary']['sent']);
});

test('falls back to case-insensitive recipient email correlation', function (): void {
    $report = QrEmailDeliveryReport::build(
        [qrDeliveryParticipant(7, 'Jan.Kowalski@X.pl')],
        [qrDeliveryMailerEmail(['to' => 'jan.kowalski@x.pl', 'metadata' => []])]
    );

    assertNotNullValue($report['participants'][0]['delivery']);
    assertSame('mail-1', $report['participants'][0]['delivery']['email_id']);
});

test('latest send wins and send_count counts all attempts', function (): void {
    $report = QrEmailDeliveryReport::build(
        [qrDeliveryParticipant(1, 'jan@x.pl')],
        [
            qrDeliveryMailerEmail(['id' => 'mail-old', 'createdAt' => '2026-07-01 09:00:00', 'effectiveStatus' => 'failed', 'status' => 'failed']),
            qrDeliveryMailerEmail(['id' => 'mail-new', 'createdAt' => '2026-07-01 11:00:00']),
        ]
    );

    $delivery = $report['participants'][0]['delivery'];
    assertSame('mail-new', $delivery['email_id']);
    assertSame('sent', $delivery['effective_status']);
    assertSame(2, $delivery['send_count']);
});

test('marks batch emails and exposes batch id', function (): void {
    $report = QrEmailDeliveryReport::build(
        [qrDeliveryParticipant(1, 'jan@x.pl')],
        [qrDeliveryMailerEmail(['batchId' => 'batch-9'])]
    );

    $delivery = $report['participants'][0]['delivery'];
    assertTrue($delivery['is_batch']);
    assertSame('batch-9', $delivery['batch_id']);
});

test('participant without mailer rows gets null delivery and no_data when locally sent', function (): void {
    $report = QrEmailDeliveryReport::build(
        [
            qrDeliveryParticipant(1, 'jan@x.pl', 'sent'),
            qrDeliveryParticipant(2, 'ola@x.pl', 'not_sent'),
        ],
        []
    );

    assertNullValue($report['participants'][0]['delivery']);
    assertNullValue($report['participants'][1]['delivery']);
    assertSame(1, $report['summary']['no_data']);
    assertSame(0, $report['summary']['sent']);
});

test('summary buckets map effective statuses correctly', function (): void {
    $report = QrEmailDeliveryReport::build(
        [
            qrDeliveryParticipant(1, 'a@x.pl'),
            qrDeliveryParticipant(2, 'b@x.pl'),
            qrDeliveryParticipant(3, 'c@x.pl'),
            qrDeliveryParticipant(4, 'd@x.pl'),
            qrDeliveryParticipant(5, 'e@x.pl'),
            qrDeliveryParticipant(6, 'f@x.pl'),
        ],
        [
            qrDeliveryMailerEmail(['id' => 'm1', 'metadata' => ['participantId' => 1], 'effectiveStatus' => 'sent']),
            qrDeliveryMailerEmail(['id' => 'm2', 'metadata' => ['participantId' => 2], 'effectiveStatus' => 'retry', 'status' => 'retry']),
            qrDeliveryMailerEmail(['id' => 'm3', 'metadata' => ['participantId' => 3], 'effectiveStatus' => 'failed', 'status' => 'failed']),
            qrDeliveryMailerEmail(['id' => 'm4', 'metadata' => ['participantId' => 4], 'effectiveStatus' => 'suppressed', 'status' => 'failed']),
            qrDeliveryMailerEmail(['id' => 'm5', 'metadata' => ['participantId' => 5], 'effectiveStatus' => 'bounced']),
            qrDeliveryMailerEmail(['id' => 'm6', 'metadata' => ['participantId' => 6], 'effectiveStatus' => 'unknown', 'status' => 'unknown']),
        ]
    );

    assertSame(6, $report['summary']['participants_total']);
    assertSame(1, $report['summary']['sent']);
    assertSame(1, $report['summary']['queued']);
    assertSame(2, $report['summary']['failed']);
    assertSame(1, $report['summary']['bounced']);
    assertSame(1, $report['summary']['unknown']);
    assertSame(0, $report['summary']['no_data']);
});

test('participantId correlation is not confused by shared recipient address', function (): void {
    // Dwoje uczestnikow z tym samym adresem: participantId ma pierwszenstwo.
    $report = QrEmailDeliveryReport::build(
        [
            qrDeliveryParticipant(1, 'wspolny@x.pl'),
            qrDeliveryParticipant(2, 'wspolny@x.pl'),
        ],
        [
            qrDeliveryMailerEmail(['id' => 'm1', 'to' => 'wspolny@x.pl', 'metadata' => ['participantId' => 1], 'effectiveStatus' => 'bounced']),
            qrDeliveryMailerEmail(['id' => 'm2', 'to' => 'wspolny@x.pl', 'metadata' => ['participantId' => 2], 'effectiveStatus' => 'sent']),
        ]
    );

    assertSame('m1', $report['participants'][0]['delivery']['email_id']);
    assertSame('m2', $report['participants'][1]['delivery']['email_id']);
    assertSame(1, $report['participants'][0]['delivery']['send_count']);
});
