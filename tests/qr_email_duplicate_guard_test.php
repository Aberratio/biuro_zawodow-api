<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/QrEmailDeliveryReport.php';

/**
 * Ochrona przed ponownym wyslaniem kodu QR do kogos, kto juz go dostal.
 *
 * Lokalne participants.email_status potrafi klamac: gdy odpowiedz central-mailera nie wroci
 * przed timeoutem, paczka jest juz zakolejkowana, a uczestnicy nadal maja "niewyslane".
 * Zrodlem prawdy jest wiec stan w mailerze, nie nasza baza.
 */
function guardMailerEmail(array $overrides = []): array
{
    return array_merge([
        'id' => 'mail-1',
        'to' => 'jan@x.pl',
        'status' => 'sent',
        'effectiveStatus' => 'sent',
        'batchId' => 'batch-1',
        'sentAt' => '2026-09-16 10:05:00',
        'createdAt' => '2026-09-16 10:00:00',
        'lastError' => null,
        'metadata' => ['participantId' => 1],
    ], $overrides);
}

test('blocks a resend for a participant whose email is already sent', function (): void {
    $blocked = QrEmailDeliveryReport::participantIdsAlreadyInMailer([guardMailerEmail()]);

    assertSame([1 => true], $blocked);
});

test('blocks a resend while the email is still waiting in the queue', function (): void {
    // To jest dokladnie stan po awarii: mail siedzi w kolejce i wyjdzie sam.
    // Ponowna wysylka oznaczalaby dwa maile u tego samego uczestnika.
    foreach (['pending', 'processing', 'retry'] as $status) {
        $blocked = QrEmailDeliveryReport::participantIdsAlreadyInMailer([
            guardMailerEmail(['status' => $status, 'effectiveStatus' => $status, 'sentAt' => null]),
        ]);

        assertSame([1 => true], $blocked, 'Status ' . $status . ' musi blokowac dosylke');
    }
});

test('blocks a resend when delivery is uncertain', function (): void {
    // "unknown" znaczy, ze nie wiadomo, czy mail wyszedl. Dosylka moglaby zdublowac,
    // wiec wymaga swiadomej decyzji operatora, a nie automatu.
    $blocked = QrEmailDeliveryReport::participantIdsAlreadyInMailer([
        guardMailerEmail(['status' => 'unknown', 'effectiveStatus' => 'unknown']),
    ]);

    assertSame([1 => true], $blocked);
});

test('allows a resend after a permanent failure', function (): void {
    foreach (['failed', 'suppressed', 'bounced'] as $status) {
        $blocked = QrEmailDeliveryReport::participantIdsAlreadyInMailer([
            guardMailerEmail(['status' => $status, 'effectiveStatus' => $status]),
        ]);

        assertSame([], $blocked, 'Status ' . $status . ' nie moze blokowac ponownej proby');
    }
});

test('prefers effectiveStatus over the raw status', function (): void {
    $blocked = QrEmailDeliveryReport::participantIdsAlreadyInMailer([
        guardMailerEmail(['status' => 'pending', 'effectiveStatus' => 'bounced']),
    ]);

    assertSame([], $blocked);
});

test('falls back to the raw status when effectiveStatus is absent', function (): void {
    $email = guardMailerEmail(['status' => 'sent']);
    unset($email['effectiveStatus']);

    assertSame([1 => true], QrEmailDeliveryReport::participantIdsAlreadyInMailer([$email]));
});

test('never blocks on a recipient address alone', function (): void {
    // Dopasowanie po adresie dawaloby falszywe trafienia po ponownym imporcie listy
    // (ten sam adres, nowy rekord, nowy kod QR). Falszywe trafienie tutaj oznacza
    // uczestnika, ktory nigdy nie dostanie maila - to gorsze niz duplikat.
    $withoutMetadata = guardMailerEmail(['metadata' => []]);
    $withZeroId = guardMailerEmail(['metadata' => ['participantId' => 0]]);
    $withNullMetadata = guardMailerEmail(['metadata' => null]);

    assertSame([], QrEmailDeliveryReport::participantIdsAlreadyInMailer([$withoutMetadata]));
    assertSame([], QrEmailDeliveryReport::participantIdsAlreadyInMailer([$withZeroId]));
    assertSame([], QrEmailDeliveryReport::participantIdsAlreadyInMailer([$withNullMetadata]));
});

test('blocks a participant when any of their sends is still live', function (): void {
    // Jeden nieudany mail nie odblokowuje uczestnika, jesli drugi czeka w kolejce.
    $blocked = QrEmailDeliveryReport::participantIdsAlreadyInMailer([
        guardMailerEmail(['id' => 'mail-1', 'status' => 'failed', 'effectiveStatus' => 'failed']),
        guardMailerEmail(['id' => 'mail-2', 'status' => 'pending', 'effectiveStatus' => 'pending']),
    ]);

    assertSame([1 => true], $blocked);
});

test('blocks exactly the participants the mailer knows about', function (): void {
    $blocked = QrEmailDeliveryReport::participantIdsAlreadyInMailer([
        guardMailerEmail(['id' => 'm1', 'metadata' => ['participantId' => 11]]),
        guardMailerEmail(['id' => 'm2', 'metadata' => ['participantId' => 22], 'status' => 'pending', 'effectiveStatus' => 'pending']),
        guardMailerEmail(['id' => 'm3', 'metadata' => ['participantId' => 33], 'status' => 'failed', 'effectiveStatus' => 'failed']),
    ]);

    assertTrue(isset($blocked[11]));
    assertTrue(isset($blocked[22]));
    assertFalse(isset($blocked[33]));
    assertSame(2, count($blocked));
});

test('handles participantId arriving as a numeric string from JSON', function (): void {
    $blocked = QrEmailDeliveryReport::participantIdsAlreadyInMailer([
        guardMailerEmail(['metadata' => ['participantId' => '42']]),
    ]);

    assertSame([42 => true], $blocked);
});

test('returns nothing when the mailer has no emails for the event', function (): void {
    assertSame([], QrEmailDeliveryReport::participantIdsAlreadyInMailer([]));
});

test('is unaffected by unrelated metadata keys', function (): void {
    $blocked = QrEmailDeliveryReport::participantIdsAlreadyInMailer([
        guardMailerEmail(['metadata' => ['participantId' => 5, 'eventId' => 'evt-1', 'bibNumber' => '77']]),
    ]);

    assertSame([5 => true], $blocked);
});

test('guard result is directly usable with isset in the send loop', function (): void {
    // Wysylka sprawdza isset($alreadyInMailer[$id]) - klucze musza byc intami.
    $blocked = QrEmailDeliveryReport::participantIdsAlreadyInMailer([
        guardMailerEmail(['metadata' => ['participantId' => 7]]),
    ]);

    assertTrue(isset($blocked[7]));
    assertFalse(isset($blocked[8]));
    assertSame([7], array_keys($blocked));
});
