<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

test('platform integration access defaults to superadmin only', function (): void {
    foreach ([null, '', 'unknown'] as $value) {
        $access = PlatformIntegrationAccess::fromValue($value);
        assertSame('superadmin', $access->mode);
        assertTrue($access->canUse('superadmin'));
        assertFalse($access->canUse('admin'));
    }
});

test('platform integration access follows the mode', function (): void {
    $off = PlatformIntegrationAccess::fromValue('off');
    assertFalse($off->canUse('superadmin'));
    assertFalse($off->canUse('admin'));

    $on = PlatformIntegrationAccess::fromValue('on');
    foreach (['superadmin', 'admin', 'editor'] as $role) {
        assertTrue($on->canUse($role));
    }

    assertSame(['mode' => 'superadmin', 'can_use' => false], PlatformIntegrationAccess::fromValue('superadmin')->presentFor('editor'));
});

test('platform integration access accepts only known modes', function (): void {
    assertTrue(PlatformIntegrationAccess::isValidMode('on'));
    assertFalse(PlatformIntegrationAccess::isValidMode('ON'));
    assertFalse(PlatformIntegrationAccess::isValidMode(1));
});

test('platform-connection writes check the integration access, reads report it', function (): void {
    $source = platformHandlersSource();
    $handlers = preg_split("/(?=    if \\(preg_match\\('#\\^\\/events\\/)/", $source, -1, PREG_SPLIT_NO_EMPTY);
    $checked = 0;

    foreach ($handlers as $handler) {
        $isRead = str_contains($handler, "\$method === 'GET'");
        assertSame(!$isRead, str_contains($handler, '$denyPlatformUseUnlessAllowed($pdo, $authUser)'));
        if ($isRead) {
            assertTrue(str_contains($handler, "'access' => PlatformIntegrationAccess::load(\$pdo)->presentFor("));
        }
        $checked++;
    }

    assertSame(5, $checked);
});
