<?php

declare(strict_types=1);

test('keeps scanner plus authorized for manual participant creation', function (): void {
    $source = file_get_contents(__DIR__ . '/../public/index.php');
    assertTrue(is_string($source));

    assertStringContains(
        "if (preg_match('#^/events/([^/]+)/participants/manual$#', \$path, \$matches) === 1 && \$method === 'POST')",
        $source,
        'Manual participant endpoint is missing'
    );
    assertStringContains(
        "\$authUser = requireAnyRole(['superadmin', 'admin', 'editor', 'scanner_plus'], \$resolveAuthenticatedUser);",
        $source,
        'Manual participant creation must allow scanner_plus'
    );
    assertStringContains(
        "\$resolvedData = \$buildParticipantDataFromMappings(\$mappings, \$fieldValues);",
        $source,
        'Manual participant creation must validate mapped field values'
    );
});

test('keeps paid only QR bulk email scope enforced by the API', function (): void {
    $source = file_get_contents(__DIR__ . '/../public/index.php');
    assertTrue(is_string($source));

    assertStringContains(
        "if (preg_match('#^/events/([^/]+)/send-qr-emails$#', \$path, \$matches) === 1 && \$method === 'POST')",
        $source,
        'Bulk QR email endpoint is missing'
    );
    assertStringContains(
        "\$paymentScope = (string)(\$input['payment_scope'] ?? 'all');",
        $source,
        'Bulk QR email endpoint must read payment_scope'
    );
    assertStringContains(
        "if (!in_array(\$paymentScope, ['all', 'paid_only'], true))",
        $source,
        'Bulk QR email endpoint must reject unsupported payment scopes'
    );
    assertStringContains(
        "if (\$paymentScope === 'paid_only' && \$participantPaymentStatus !== 'paid')",
        $source,
        'paid_only scope must skip participants that are not paid'
    );
    assertStringContains(
        "'skipped_unpaid_count' => \$skippedUnpaidCount",
        $source,
        'paid_only response must expose skipped participant count'
    );
});
