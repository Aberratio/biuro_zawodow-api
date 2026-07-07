<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

test('documents and migrates test event sandbox support', function (): void {
    $initSql = file_get_contents(__DIR__ . '/../database/init/001_init.sql');
    $migration = file_get_contents(__DIR__ . '/../database/migrations/023_add_event_test_mode.php');
    $document = openApiDocument();

    assertTrue(is_string($initSql));
    assertStringContains('is_test BOOLEAN NOT NULL DEFAULT FALSE', $initSql);
    assertTrue(is_string($migration));
    assertStringContains('ADD COLUMN is_test BOOLEAN NOT NULL DEFAULT FALSE', $migration);

    $paths = array_keys($document['paths'] ?? []);
    assertTrue(in_array('/events/test', $paths, true), 'OpenAPI path missing: /events/test');
    assertTrue(in_array('/events/{id}/test-reset', $paths, true), 'OpenAPI path missing: /events/{id}/test-reset');

    $eventSchema = $document['components']['schemas']['Event'] ?? [];
    assertTrue(in_array('is_test', $eventSchema['required'] ?? [], true));
    assertSame('boolean', $eventSchema['properties']['is_test']['type'] ?? null);
});

test('keeps test event QR sending on the simulated path', function (): void {
    $source = file_get_contents(__DIR__ . '/../public/index.php');
    assertTrue(is_string($source));

    assertStringContains("'test_mode' => true", $source);
    assertStringContains('Zasymulowano wysylke kodu QR', $source);
    assertStringContains('Zasymulowano wysylke kodow QR dla wydarzenia', $source);

    $singleSimulation = strpos($source, "jsonResponse(200, ['data' => \$participant, 'test_mode' => true]);");
    $singleMailSend = strpos($source, 'MailService::sendParticipantQrEmail(');
    assertTrue($singleSimulation !== false, 'Single QR simulated response is missing');
    assertTrue($singleMailSend !== false, 'Single QR MailService call is missing');
    assertTrue($singleSimulation < $singleMailSend, 'Single QR simulation must happen before MailService call');

    $batchSimulation = strpos($source, "'test_mode' => true", $singleMailSend);
    $batchMailSend = strpos($source, 'MailService::sendParticipantQrEmailBatch(');
    assertTrue($batchSimulation !== false, 'Batch QR simulated response is missing');
    assertTrue($batchMailSend !== false, 'Batch QR MailService call is missing');
    assertTrue($batchSimulation < $batchMailSend, 'Batch QR simulation must happen before MailService batch call');
});
