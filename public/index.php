<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

setCorsHeaders();
validateServerSecurityConfiguration();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($path === '/openapi.json' && $method === 'GET') {
    jsonResponse(200, openApiDocument());
    exit;
}

if (($path === '/docs' || $path === '/docs/') && $method === 'GET') {
    htmlResponse(200, swaggerUiPage());
    exit;
}

try {
    $pdo = Database::connect();

    $requestRateLimitsTableExistsStmt = $pdo->query("
        SELECT COUNT(*) AS total
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'request_rate_limits'
    ");
    $requestRateLimitsTableExists = (int)($requestRateLimitsTableExistsStmt->fetch()['total'] ?? 0) > 0;
    if (!$requestRateLimitsTableExists) {
        $pdo->exec("
            CREATE TABLE request_rate_limits (
                bucket_key VARCHAR(128) NOT NULL,
                bucket_name VARCHAR(64) NOT NULL,
                attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
                window_started_at DATETIME NOT NULL,
                blocked_until DATETIME NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (bucket_key),
                KEY idx_request_rate_limits_bucket_name (bucket_name),
                KEY idx_request_rate_limits_blocked_until (blocked_until)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    $activityLogEventIdColumnExistsStmt = $pdo->query("
        SELECT COUNT(*) AS total
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'activity_logs'
          AND COLUMN_NAME = 'event_id'
    ");
    $activityLogEventIdColumnExists = (int)($activityLogEventIdColumnExistsStmt->fetch()['total'] ?? 0) > 0;
    if (!$activityLogEventIdColumnExists) {
        $pdo->exec("
            ALTER TABLE activity_logs
            ADD COLUMN event_id VARCHAR(64) NULL AFTER action,
            ADD KEY idx_activity_logs_event_id (event_id),
            ADD CONSTRAINT fk_activity_logs_event
                FOREIGN KEY (event_id) REFERENCES events(id)
                ON DELETE SET NULL
                ON UPDATE CASCADE
        ");
    }

    $usersArchivedAtColumnExistsStmt = $pdo->query("
        SELECT COUNT(*) AS total
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'users'
          AND COLUMN_NAME = 'archived_at'
    ");
    $usersArchivedAtColumnExists = (int)($usersArchivedAtColumnExistsStmt->fetch()['total'] ?? 0) > 0;
    if (!$usersArchivedAtColumnExists) {
        $pdo->exec("
            ALTER TABLE users
            ADD COLUMN archived_at DATETIME NULL AFTER organization_id,
            ADD KEY idx_users_archived_at (archived_at)
        ");
    }

    $usersArchivedEmailColumnExistsStmt = $pdo->query("
        SELECT COUNT(*) AS total
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'users'
          AND COLUMN_NAME = 'archived_email'
    ");
    $usersArchivedEmailColumnExists = (int)($usersArchivedEmailColumnExistsStmt->fetch()['total'] ?? 0) > 0;
    if (!$usersArchivedEmailColumnExists) {
        $pdo->exec("
            ALTER TABLE users
            ADD COLUMN archived_email VARCHAR(190) NULL AFTER archived_at
        ");
    }

    $eventsArchivedAtColumnExistsStmt = $pdo->query("
        SELECT COUNT(*) AS total
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'events'
          AND COLUMN_NAME = 'archived_at'
    ");
    $eventsArchivedAtColumnExists = (int)($eventsArchivedAtColumnExistsStmt->fetch()['total'] ?? 0) > 0;
    if (!$eventsArchivedAtColumnExists) {
        $pdo->exec("
            ALTER TABLE events
            ADD COLUMN archived_at DATETIME NULL AFTER office_close_at,
            ADD KEY idx_events_archived_at (archived_at)
        ");
    }

    $loadAdminOrganizationIds = static function (PDO $pdo, string $userId): array {
        $stmt = $pdo->prepare('
            SELECT organization_id
            FROM admin_organization_assignments
            WHERE user_id = :user_id
            ORDER BY organization_id ASC
        ');
        $stmt->execute(['user_id' => $userId]);

        return array_map(
            static fn(array $row): string => (string)$row['organization_id'],
            $stmt->fetchAll()
        );
    };

    $loadAssignedEvents = static function (PDO $pdo, string $userId): array {
        $stmt = $pdo->prepare('
            SELECT uea.event_id
            FROM user_event_assignments uea
            INNER JOIN events e ON e.id = uea.event_id
            WHERE uea.user_id = :user_id
              AND e.archived_at IS NULL
            ORDER BY uea.event_id ASC
        ');
        $stmt->execute(['user_id' => $userId]);

        return array_map(
            static fn(array $row): string => (string)$row['event_id'],
            $stmt->fetchAll()
        );
    };

    $loadOrganizationById = static function (PDO $pdo, string $organizationId): array|false {
        $stmt = $pdo->prepare('
            SELECT
                o.id,
                o.name,
                o.logo,
                o.event_limit,
                aoa.user_id AS admin_user_id,
                admin_user.name AS admin_user_name
            FROM organizations o
            LEFT JOIN admin_organization_assignments aoa ON aoa.organization_id = o.id
            LEFT JOIN users admin_user ON admin_user.id = aoa.user_id
            WHERE o.id = :id
            LIMIT 1
        ');
        $stmt->execute(['id' => $organizationId]);

        return $stmt->fetch();
    };

    $loadAllOrganizations = static function (PDO $pdo): array {
        return $pdo->query('
            SELECT
                o.id,
                o.name,
                o.logo,
                o.event_limit,
                aoa.user_id AS admin_user_id,
                admin_user.name AS admin_user_name
            FROM organizations o
            LEFT JOIN admin_organization_assignments aoa ON aoa.organization_id = o.id
            LEFT JOIN users admin_user ON admin_user.id = aoa.user_id
            ORDER BY o.name ASC
        ')->fetchAll();
    };

    $loadEventById = static function (PDO $pdo, string $eventId): array|false {
        $stmt = $pdo->prepare('
            SELECT
                id,
                name,
                location,
                organization_id,
                DATE_FORMAT(office_open_at, "%Y-%m-%dT%H:%i:%s") AS office_open_at,
                DATE_FORMAT(office_close_at, "%Y-%m-%dT%H:%i:%s") AS office_close_at,
                DATE_FORMAT(archived_at, "%Y-%m-%dT%H:%i:%s") AS archived_at
            FROM events
            WHERE id = :id
            LIMIT 1
        ');
        $stmt->execute(['id' => $eventId]);

        return $stmt->fetch();
    };

    $loadAllEvents = static function (PDO $pdo): array {
        return $pdo->query('
            SELECT
                id,
                name,
                location,
                organization_id,
                DATE_FORMAT(office_open_at, "%Y-%m-%dT%H:%i:%s") AS office_open_at,
                DATE_FORMAT(office_close_at, "%Y-%m-%dT%H:%i:%s") AS office_close_at,
                DATE_FORMAT(archived_at, "%Y-%m-%dT%H:%i:%s") AS archived_at
            FROM events
            WHERE archived_at IS NULL
            ORDER BY event_date ASC
        ')->fetchAll();
    };

    $loadAllArchivedEvents = static function (PDO $pdo): array {
        return $pdo->query('
            SELECT
                id,
                name,
                location,
                organization_id,
                DATE_FORMAT(office_open_at, "%Y-%m-%dT%H:%i:%s") AS office_open_at,
                DATE_FORMAT(office_close_at, "%Y-%m-%dT%H:%i:%s") AS office_close_at,
                DATE_FORMAT(archived_at, "%Y-%m-%dT%H:%i:%s") AS archived_at
            FROM events
            WHERE archived_at IS NOT NULL
            ORDER BY archived_at DESC, event_date DESC
        ')->fetchAll();
    };

    $participantStatuses = [
        'not_checked_in' => [
            'label' => 'Nieodprawiony',
            'counts_as_checked_in' => false,
        ],
        'checked_in' => [
            'label' => 'Odprawiony',
            'counts_as_checked_in' => true,
        ],
        'checked_in_not_starting' => [
            'label' => 'Odprawiony bez startu',
            'counts_as_checked_in' => true,
        ],
    ];

    $isValidParticipantStatus = static fn(string $status): bool => isset($participantStatuses[$status]);
    $participantStatusCountsAsCheckedIn = static fn(string $status): bool => (bool)($participantStatuses[$status]['counts_as_checked_in'] ?? false);
    $scannerRoles = ['scanner', 'scanner_plus'];
    $isScannerRole = static fn(string $role): bool => in_array($role, $scannerRoles, true);

    $loadUserById = static function (PDO $pdo, string $userId) use ($loadAdminOrganizationIds, $loadAssignedEvents, $isScannerRole): array|false {
        $stmt = $pdo->prepare('
            SELECT id, name, email, role, organization_id
            FROM users
            WHERE id = :id
              AND archived_at IS NULL
            LIMIT 1
        ');
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();

        if ($user === false) {
            return false;
        }

        $user['organization_ids'] = $user['role'] === 'admin'
            ? $loadAdminOrganizationIds($pdo, (string)$user['id'])
            : [];
        $user['assigned_events'] = $isScannerRole((string)$user['role'])
            ? $loadAssignedEvents($pdo, (string)$user['id'])
            : [];

        return $user;
    };

    $loadAllUsers = static function (PDO $pdo) use ($loadAdminOrganizationIds, $loadAssignedEvents, $isScannerRole): array {
        $users = $pdo->query('
            SELECT id, name, email, role, organization_id
            FROM users
            WHERE archived_at IS NULL
            ORDER BY name ASC
        ')->fetchAll();

        foreach ($users as &$user) {
            $user['organization_ids'] = $user['role'] === 'admin'
                ? $loadAdminOrganizationIds($pdo, (string)$user['id'])
                : [];
            $user['assigned_events'] = $isScannerRole((string)$user['role'])
                ? $loadAssignedEvents($pdo, (string)$user['id'])
                : [];
        }
        unset($user);

        return $users;
    };

    $loadUserWithPasswordByEmail = static function (PDO $pdo, string $email): array|false {
        $stmt = $pdo->prepare('
            SELECT id, name, email, password, role, organization_id
            FROM users
            WHERE email = :email
              AND archived_at IS NULL
            LIMIT 1
        ');
        $stmt->execute(['email' => $email]);

        return $stmt->fetch();
    };

    $invalidatePasswordResetTokensForUser = static function (PDO $pdo, string $userId): void {
        $stmt = $pdo->prepare('
            UPDATE password_resets
            SET used_at = COALESCE(used_at, UTC_TIMESTAMP())
            WHERE user_id = :user_id
              AND used_at IS NULL
        ');
        $stmt->execute(['user_id' => $userId]);
    };

    $createPasswordResetToken = static function (PDO $pdo, string $userId) use ($invalidatePasswordResetTokensForUser): string {
        $token = bin2hex(random_bytes(32));
        $invalidatePasswordResetTokensForUser($pdo, $userId);

        $stmt = $pdo->prepare('
            INSERT INTO password_resets (id, user_id, token_hash, expires_at)
            VALUES (:id, :user_id, :token_hash, :expires_at)
        ');
        $stmt->execute([
            'id' => 'pr-' . bin2hex(random_bytes(8)),
            'user_id' => $userId,
            'token_hash' => passwordResetTokenHash($token),
            'expires_at' => passwordResetExpiresAt(),
        ]);

        return $token;
    };

    $loadActivePasswordReset = static function (PDO $pdo, string $token): array|false {
        $stmt = $pdo->prepare('
            SELECT pr.id, pr.user_id, pr.expires_at, u.email, u.name
            FROM password_resets pr
            INNER JOIN users u ON u.id = pr.user_id
            WHERE pr.token_hash = :token_hash
              AND pr.used_at IS NULL
              AND pr.expires_at >= UTC_TIMESTAMP()
            LIMIT 1
        ');
        $stmt->execute(['token_hash' => passwordResetTokenHash($token)]);

        return $stmt->fetch();
    };

    $markPasswordResetTokensUsed = static function (PDO $pdo, string $userId): void {
        $stmt = $pdo->prepare('
            UPDATE password_resets
            SET used_at = COALESCE(used_at, UTC_TIMESTAMP())
            WHERE user_id = :user_id
              AND used_at IS NULL
        ');
        $stmt->execute(['user_id' => $userId]);
    };

    $resolveAuthenticatedUser = static function (array $tokenUser) use ($pdo, $loadUserById): ?array {
        $userId = trim((string)($tokenUser['id'] ?? ''));
        if ($userId === '') {
            return null;
        }

        $user = $loadUserById($pdo, $userId);
        return is_array($user) ? $user : null;
    };

    $canAccessOrganization = static function (array $authUser, string $organizationId): bool {
        if ($authUser['role'] === 'superadmin') {
            return true;
        }

        if ($authUser['role'] === 'admin') {
            return in_array($organizationId, $authUser['organization_ids'] ?? [], true);
        }

        return (string)($authUser['organization_id'] ?? '') === $organizationId;
    };

    $canManageTargetUser = static function (array $authUser, array $targetUser) use ($isScannerRole): bool {
        if ((string)$authUser['id'] === (string)$targetUser['id']) {
            return false;
        }

        if ($authUser['role'] === 'superadmin') {
            return true;
        }

        if ($authUser['role'] === 'admin') {
            if (($targetUser['role'] ?? '') === 'admin' || ($targetUser['role'] ?? '') === 'superadmin') {
                return false;
            }

            return in_array((string)($targetUser['organization_id'] ?? ''), $authUser['organization_ids'] ?? [], true);
        }

        if ($authUser['role'] === 'editor') {
            return $isScannerRole((string)($targetUser['role'] ?? ''))
                && (string)($targetUser['organization_id'] ?? '') === (string)($authUser['organization_id'] ?? '');
        }

        return false;
    };

    $validateScannerAssignments = static function (PDO $pdo, string $organizationId, array $assignedEvents): ?string {
        if ($assignedEvents === []) {
            return null;
        }

        $eventStmt = $pdo->prepare('
            SELECT id
            FROM events
            WHERE id = :id
              AND organization_id = :organization_id
              AND archived_at IS NULL
            LIMIT 1
        ');

        foreach ($assignedEvents as $eventId) {
            $eventStmt->execute([
                'id' => $eventId,
                'organization_id' => $organizationId,
            ]);

            if ($eventStmt->fetch() === false) {
                return 'All assigned_events must belong to the same organization as the scanner';
            }
        }

        return null;
    };

    $isArchivedEvent = static fn(array $event): bool => trim((string)($event['archived_at'] ?? '')) !== '';

    $isEventOfficeOpenNow = static function (array $event): bool {
        $openAt = parseLocalDateTimeString((string)($event['office_open_at'] ?? ''));
        $closeAt = parseLocalDateTimeString((string)($event['office_close_at'] ?? ''));
        if ($openAt === null || $closeAt === null) {
            return false;
        }

        $now = new DateTimeImmutable();
        return $now >= $openAt && $now <= $closeAt;
    };

    $canManageEventParticipants = static function (array $authUser, array $event) use ($canAccessOrganization, $isArchivedEvent, $isEventOfficeOpenNow): bool {
        if (in_array($authUser['role'], ['superadmin', 'admin', 'editor', 'scanner_plus'], true) === false) {
            return false;
        }

        if ($isArchivedEvent($event) && $authUser['role'] !== 'superadmin') {
            return false;
        }

        if ($authUser['role'] === 'scanner_plus') {
            return in_array((string)$event['id'], $authUser['assigned_events'] ?? [], true)
                && $isEventOfficeOpenNow($event);
        }

        return $canAccessOrganization($authUser, (string)$event['organization_id']);
    };

    $formatEventOfficeWindow = static function (array $event): string {
        $openAt = parseLocalDateTimeString((string)($event['office_open_at'] ?? ''));
        $closeAt = parseLocalDateTimeString((string)($event['office_close_at'] ?? ''));
        if ($openAt === null || $closeAt === null) {
            return 'Godziny biura zawodów niedostępne';
        }

        return sprintf(
            '%s, %s - %s',
            $openAt->format('d.m.Y'),
            $openAt->format('H:i'),
            $closeAt->format('H:i')
        );
    };

    $canAccessEvent = static function (array $authUser, array $event) use ($canAccessOrganization, $isEventOfficeOpenNow, $isArchivedEvent, $isScannerRole): bool {
        if ($isArchivedEvent($event)) {
            return $authUser['role'] === 'superadmin'
                && $canAccessOrganization($authUser, (string)$event['organization_id']);
        }

        if ($isScannerRole((string)$authUser['role'])) {
            return in_array((string)$event['id'], $authUser['assigned_events'] ?? [], true)
                && $isEventOfficeOpenNow($event);
        }

        if ($canAccessOrganization($authUser, (string)$event['organization_id'])) {
            return true;
        }

        return false;
    };

    $canViewArchivedEvent = static function (array $authUser, array $event) use ($canAccessOrganization, $isArchivedEvent): bool {
        if (!$isArchivedEvent($event)) {
            return false;
        }

        if (in_array($authUser['role'], ['superadmin', 'admin', 'editor'], true) === false) {
            return false;
        }

        return $canAccessOrganization($authUser, (string)$event['organization_id']);
    };

    $filterAccessibleEvents = static function (array $authUser, array $events) use ($canAccessEvent, $canManageEventParticipants): array {
        return array_values(array_filter(
            $events,
            static function (array $event) use ($authUser, $canAccessEvent, $canManageEventParticipants): bool {
                return $canAccessEvent($authUser, $event) || $canManageEventParticipants($authUser, $event);
            }
        ));
    };

    $loadAccessibleEvents = static function (PDO $pdo, array $authUser) use ($loadAllEvents, $filterAccessibleEvents): array {
        return $filterAccessibleEvents($authUser, $loadAllEvents($pdo));
    };

    $filterAccessibleArchivedEvents = static function (array $authUser, array $events) use ($canViewArchivedEvent): array {
        return array_values(array_filter(
            $events,
            static fn(array $event): bool => $canViewArchivedEvent($authUser, $event)
        ));
    };

    $loadAccessibleArchivedEvents = static function (PDO $pdo, array $authUser) use ($loadAllArchivedEvents, $filterAccessibleArchivedEvents): array {
        return $filterAccessibleArchivedEvents($authUser, $loadAllArchivedEvents($pdo));
    };

    $filterAccessibleOrganizations = static function (array $authUser, array $organizations, array $accessibleEvents): array {
        if ($authUser['role'] === 'superadmin') {
            return array_values($organizations);
        }

        if ($authUser['role'] === 'admin') {
            $allowedOrganizationIds = $authUser['organization_ids'] ?? [];
        } elseif ($authUser['role'] === 'editor') {
            $allowedOrganizationIds = [(string)($authUser['organization_id'] ?? '')];
        } else {
            $allowedOrganizationIds = array_map(
                static fn(array $event): string => (string)$event['organization_id'],
                $accessibleEvents
            );
        }

        $allowedOrganizationIds = array_values(array_unique(array_filter(
            $allowedOrganizationIds,
            static fn(string $organizationId): bool => $organizationId !== ''
        )));

        return array_values(array_filter(
            $organizations,
            static fn(array $organization): bool => in_array((string)$organization['id'], $allowedOrganizationIds, true)
        ));
    };

    $filterAccessibleUsers = static function (array $authUser, array $users, array $accessibleOrganizations) use ($isScannerRole): array {
        if ($authUser['role'] === 'superadmin') {
            return array_values($users);
        }

        if ($isScannerRole((string)$authUser['role'])) {
            return array_values(array_filter(
                $users,
                static fn(array $user): bool => (string)$user['id'] === (string)$authUser['id']
            ));
        }

        $accessibleOrganizationIds = array_map(
            static fn(array $organization): string => (string)$organization['id'],
            $accessibleOrganizations
        );
        $accessibleAdminIds = array_values(array_filter(
            array_map(
                static fn(array $organization): string => trim((string)($organization['admin_user_id'] ?? '')),
                $accessibleOrganizations
            ),
            static fn(string $userId): bool => $userId !== ''
        ));

        return array_values(array_filter(
            $users,
            static function (array $user) use ($authUser, $accessibleOrganizationIds, $accessibleAdminIds): bool {
                if ((string)$user['id'] === (string)$authUser['id']) {
                    return true;
                }

                if (in_array((string)($user['organization_id'] ?? ''), $accessibleOrganizationIds, true)) {
                    return true;
                }

                return in_array((string)$user['id'], $accessibleAdminIds, true);
            }
        ));
    };

    $filterAccessibleActivityLogs = static function (array $activityLogs, array $accessibleEventIds, array $accessibleParticipantIds): array {
        $accessibleEventLookup = array_fill_keys($accessibleEventIds, true);
        $accessibleParticipantLookup = array_fill_keys($accessibleParticipantIds, true);

        return array_values(array_filter(
            $activityLogs,
            static function (array $log) use ($accessibleEventLookup, $accessibleParticipantLookup): bool {
                $eventId = trim((string)($log['event_id'] ?? ''));
                if ($eventId !== '' && isset($accessibleEventLookup[$eventId])) {
                    return true;
                }

                $participantId = trim((string)($log['participant_id'] ?? ''));
                return $participantId !== '' && isset($accessibleParticipantLookup[$participantId]);
            }
        ));
    };

    $rateLimitBucketKey = static function (string $bucketName, ?string $subject = null): string {
        $parts = [$bucketName, clientIpAddress()];
        $normalizedSubject = strtolower(trim((string)$subject));
        if ($normalizedSubject !== '') {
            $parts[] = $normalizedSubject;
        }

        return hash('sha256', implode('|', $parts));
    };

    $consumeRateLimitAttempt = static function (
        PDO $pdo,
        string $bucketName,
        int $maxAttempts,
        int $windowSeconds,
        ?string $subject = null
    ) use ($rateLimitBucketKey): array {
        $bucketKey = $rateLimitBucketKey($bucketName, $subject);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $nowString = $now->format('Y-m-d H:i:s');

        $pdo->beginTransaction();

        try {
            $selectStmt = $pdo->prepare('
                SELECT bucket_key, attempt_count, window_started_at, blocked_until
                FROM request_rate_limits
                WHERE bucket_key = :bucket_key
                LIMIT 1
                FOR UPDATE
            ');
            $selectStmt->execute(['bucket_key' => $bucketKey]);
            $row = $selectStmt->fetch();

            if ($row === false) {
                $insertStmt = $pdo->prepare('
                    INSERT INTO request_rate_limits (bucket_key, bucket_name, attempt_count, window_started_at, blocked_until)
                    VALUES (:bucket_key, :bucket_name, 1, :window_started_at, NULL)
                ');
                $insertStmt->execute([
                    'bucket_key' => $bucketKey,
                    'bucket_name' => $bucketName,
                    'window_started_at' => $nowString,
                ]);

                $pdo->commit();
                return ['allowed' => true, 'retry_after' => 0];
            }

            $blockedUntilRaw = trim((string)($row['blocked_until'] ?? ''));
            $blockedUntil = $blockedUntilRaw !== ''
                ? new DateTimeImmutable($blockedUntilRaw, new DateTimeZone('UTC'))
                : null;

            if ($blockedUntil !== null && $blockedUntil > $now) {
                $pdo->commit();
                return [
                    'allowed' => false,
                    'retry_after' => max($blockedUntil->getTimestamp() - $now->getTimestamp(), 1),
                ];
            }

            $windowStartedAt = new DateTimeImmutable((string)$row['window_started_at'], new DateTimeZone('UTC'));
            $windowExpiresAt = $windowStartedAt->modify(sprintf('+%d seconds', $windowSeconds));

            if ($windowExpiresAt <= $now) {
                $resetStmt = $pdo->prepare('
                    UPDATE request_rate_limits
                    SET attempt_count = 1, window_started_at = :window_started_at, blocked_until = NULL
                    WHERE bucket_key = :bucket_key
                ');
                $resetStmt->execute([
                    'window_started_at' => $nowString,
                    'bucket_key' => $bucketKey,
                ]);

                $pdo->commit();
                return ['allowed' => true, 'retry_after' => 0];
            }

            $attemptCount = (int)($row['attempt_count'] ?? 0) + 1;
            $isBlocked = $attemptCount > $maxAttempts;
            $retryAfter = $isBlocked
                ? max($windowExpiresAt->getTimestamp() - $now->getTimestamp(), 1)
                : 0;
            $nextBlockedUntil = $isBlocked
                ? $now->modify(sprintf('+%d seconds', $retryAfter))->format('Y-m-d H:i:s')
                : null;

            $updateStmt = $pdo->prepare('
                UPDATE request_rate_limits
                SET attempt_count = :attempt_count, blocked_until = :blocked_until
                WHERE bucket_key = :bucket_key
            ');
            $updateStmt->bindValue(':attempt_count', $attemptCount, PDO::PARAM_INT);
            $updateStmt->bindValue(':blocked_until', $nextBlockedUntil, $nextBlockedUntil === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $updateStmt->bindValue(':bucket_key', $bucketKey, PDO::PARAM_STR);
            $updateStmt->execute();

            $pdo->commit();

            return ['allowed' => !$isBlocked, 'retry_after' => $retryAfter];
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    };

    $clearRateLimitBucket = static function (PDO $pdo, string $bucketName, ?string $subject = null) use ($rateLimitBucketKey): void {
        $deleteStmt = $pdo->prepare('DELETE FROM request_rate_limits WHERE bucket_key = :bucket_key');
        $deleteStmt->execute(['bucket_key' => $rateLimitBucketKey($bucketName, $subject)]);
    };

    $generateUniqueParticipantQrCode = static function (PDO $pdo): string {
        $checkStmt = $pdo->prepare('SELECT id FROM participants WHERE qr_code = :qr_code LIMIT 1');

        do {
            $token = QrCodeService::generateToken();
            $checkStmt->execute(['qr_code' => $token]);
            $collision = $checkStmt->fetch();
        } while ($collision !== false);

        return $token;
    };

    $addActivityLog = static function (
        PDO $pdo,
        string $action,
        ?string $eventId = null,
        ?int $participantId = null,
        ?string $participantName = null,
        ?string $userId = null,
        ?string $userName = null
    ): void {
        $stmt = $pdo->prepare('
            INSERT INTO activity_logs (
                id,
                action,
                event_id,
                participant_id,
                participant_name_snapshot,
                user_id,
                user_name_snapshot
            ) VALUES (
                :id,
                :action,
                :event_id,
                :participant_id,
                :participant_name_snapshot,
                :user_id,
                :user_name_snapshot
            )
        ');
        $stmt->execute([
            'id' => 'log-' . bin2hex(random_bytes(8)),
            'action' => $action,
            'event_id' => $eventId,
            'participant_id' => $participantId,
            'participant_name_snapshot' => $participantName,
            'user_id' => $userId,
            'user_name_snapshot' => $userName,
        ]);
    };

    $archiveExpiredEvents = static function (PDO $pdo) use ($addActivityLog): void {
        $eventsToArchiveStmt = $pdo->query('
            SELECT id, name
            FROM events
            WHERE archived_at IS NULL
              AND office_close_at <= DATE_SUB(NOW(), INTERVAL 1 MONTH)
        ');
        $eventsToArchive = $eventsToArchiveStmt->fetchAll();

        if ($eventsToArchive === []) {
            return;
        }

        $pdo->beginTransaction();

        try {
            $archiveEventStmt = $pdo->prepare('
                UPDATE events
                SET archived_at = UTC_TIMESTAMP()
                WHERE id = :id
                  AND archived_at IS NULL
            ');

            foreach ($eventsToArchive as $event) {
                $archiveEventStmt->execute(['id' => (string)$event['id']]);
                if ($archiveEventStmt->rowCount() === 0) {
                    continue;
                }

                $addActivityLog(
                    $pdo,
                    sprintf('Automatycznie zarchiwizowano wydarzenie: %s', (string)$event['name']),
                    (string)$event['id']
                );
            }

            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    };

    $archiveExpiredEvents($pdo);

    $loadParticipantFieldMappings = static function (PDO $pdo, string $eventId): array {
        $stmt = $pdo->prepare('
            SELECT
                source_column_name,
                alias,
                field_role,
                display_order,
                is_required,
                is_active
            FROM event_participant_field_mappings
            WHERE event_id = :event_id
            ORDER BY display_order ASC, id ASC
        ');
        $stmt->execute(['event_id' => $eventId]);

        return array_map(static function (array $row): array {
            return [
                'source_column_name' => (string)$row['source_column_name'],
                'alias' => (string)$row['alias'],
                'field_role' => (string)$row['field_role'],
                'display_order' => (int)$row['display_order'],
                'is_required' => (bool)$row['is_required'],
                'is_active' => (bool)$row['is_active'],
            ];
        }, $stmt->fetchAll());
    };

    $buildParticipantDataFromMappings = static function (
        array $mappings,
        array $fieldValues,
        bool $requireAllActiveFields = false,
        ?string $preservedBibNumber = null
    ): array {
        $displayNameParts = [];
        $customFields = [];
        $bibNumber = $preservedBibNumber;
        $missingFields = [];

        foreach ($mappings as $mapping) {
            if (!($mapping['is_active'] ?? true) || $mapping['field_role'] === 'email') {
                continue;
            }

            $alias = trim((string)($mapping['alias'] ?? ''));
            if ($alias === '') {
                continue;
            }

            $isRequired = $requireAllActiveFields || (bool)($mapping['is_required'] ?? false);
            $value = trim((string)($fieldValues[$alias] ?? ''));

            if ($mapping['field_role'] === 'bib_number' && $preservedBibNumber !== null && trim($preservedBibNumber) !== '') {
                $value = trim($preservedBibNumber);
            }

            if ($value === '') {
                if ($isRequired) {
                    $missingFields[] = $alias;
                }
                continue;
            }

            if ($mapping['field_role'] === 'display_name_part') {
                $displayNameParts[] = $value;
            }

            if ($mapping['field_role'] === 'bib_number') {
                $bibNumber = $value;
            }

            $customFields[$alias] = $value;
        }

        return [
            'display_name' => trim(implode(' ', $displayNameParts)),
            'custom_fields' => $customFields,
            'bib_number' => $bibNumber,
            'missing_fields' => $missingFields,
        ];
    };

    $ensureParticipantQrCode = static function (PDO $pdo, array $participant) use ($generateUniqueParticipantQrCode): array {
        $qrCode = trim((string)($participant['qr_code'] ?? ''));
        if (QrCodeService::isSecureToken($qrCode)) {
            return $participant;
        }

        $newQrCode = $generateUniqueParticipantQrCode($pdo);
        $updateStmt = $pdo->prepare('UPDATE participants SET qr_code = :qr_code WHERE id = :id');
        $updateStmt->execute([
            'qr_code' => $newQrCode,
            'id' => (int)$participant['id'],
        ]);

        $participant['qr_code'] = $newQrCode;
        return $participant;
    };

    $loadParticipantById = static function (PDO $pdo, int $participantId) use (&$ensureParticipantQrCode): array|false {
        $stmt = $pdo->prepare('
            SELECT
                id,
                event_id,
                first_name,
                last_name,
                display_name,
                email,
                organization,
                bib_number,
                qr_code,
                custom_fields_json,
                status,
                email_status,
                checked_in_at,
                created_at,
                updated_at
            FROM participants
            WHERE id = :id
        ');
        $stmt->execute(['id' => $participantId]);
        $participant = $stmt->fetch();
        if ($participant === false) {
            return false;
        }

        $participant['custom_fields'] = decodeJsonObject($participant['custom_fields_json'] ?? null);
        unset($participant['custom_fields_json']);

        return $ensureParticipantQrCode($pdo, $participant);
    };

    $loadParticipantByQrCode = static function (PDO $pdo, string $qrCode) use (&$ensureParticipantQrCode): array|false {
        $stmt = $pdo->prepare('
            SELECT
                id,
                event_id,
                first_name,
                last_name,
                display_name,
                email,
                organization,
                bib_number,
                qr_code,
                custom_fields_json,
                status,
                email_status,
                checked_in_at,
                created_at,
                updated_at
            FROM participants
            WHERE qr_code = :qr_code
            LIMIT 1
        ');
        $stmt->execute(['qr_code' => $qrCode]);
        $participant = $stmt->fetch();

        if ($participant === false) {
            return false;
        }

        $participant['custom_fields'] = decodeJsonObject($participant['custom_fields_json'] ?? null);
        unset($participant['custom_fields_json']);

        return $ensureParticipantQrCode($pdo, $participant);
    };

    $loadParticipantsByEventId = static function (PDO $pdo, string $eventId) use (&$ensureParticipantQrCode): array {
        $stmt = $pdo->prepare('
            SELECT
                id,
                event_id,
                first_name,
                last_name,
                display_name,
                email,
                organization,
                bib_number,
                qr_code,
                custom_fields_json,
                status,
                email_status,
                checked_in_at,
                created_at,
                updated_at
            FROM participants
            WHERE event_id = :event_id
            ORDER BY id ASC
        ');
        $stmt->execute(['event_id' => $eventId]);
        $participants = $stmt->fetchAll();

        foreach ($participants as &$participant) {
            $participant['custom_fields'] = decodeJsonObject($participant['custom_fields_json'] ?? null);
            unset($participant['custom_fields_json']);
            $participant = $ensureParticipantQrCode($pdo, $participant);
        }
        unset($participant);

        return $participants;
    };

    $loadParticipantsByEventIds = static function (PDO $pdo, array $eventIds) use (&$ensureParticipantQrCode): array {
        $normalizedEventIds = array_values(array_filter(
            array_map(static fn(mixed $eventId): string => trim((string)$eventId), $eventIds),
            static fn(string $eventId): bool => $eventId !== ''
        ));
        if ($normalizedEventIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($normalizedEventIds), '?'));
        $stmt = $pdo->prepare("
            SELECT
                id,
                event_id,
                first_name,
                last_name,
                display_name,
                email,
                organization,
                bib_number,
                qr_code,
                custom_fields_json,
                status,
                email_status,
                checked_in_at,
                created_at,
                updated_at
            FROM participants
            WHERE event_id IN ($placeholders)
            ORDER BY id DESC
        ");
        $stmt->execute($normalizedEventIds);
        $participants = $stmt->fetchAll();

        foreach ($participants as &$participant) {
            $participant['custom_fields'] = decodeJsonObject($participant['custom_fields_json'] ?? null);
            unset($participant['custom_fields_json']);
            $participant = $ensureParticipantQrCode($pdo, $participant);
        }
        unset($participant);

        return $participants;
    };

    $loadAllActivityLogs = static function (PDO $pdo): array {
        return $pdo->query('
            SELECT
                id,
                event_id,
                participant_id,
                created_at AS timestamp,
                action,
                participant_name_snapshot AS participant_name,
                user_name_snapshot AS user_name
            FROM activity_logs
            ORDER BY created_at DESC
        ')->fetchAll();
    };

    $serializeParticipantScanResponse = static function (array $participant, array $event, bool $canAccess): array {
        return [
            'participant' => [
                'id' => (int)$participant['id'],
                'event_id' => (string)$participant['event_id'],
                'display_name' => (string)$participant['display_name'],
                'email' => (string)$participant['email'],
                'bib_number' => (string)($participant['bib_number'] ?? ''),
                'status' => (string)$participant['status'],
                'email_status' => (string)$participant['email_status'],
                'checked_in_at' => $participant['checked_in_at'],
                'qr_code' => (string)$participant['qr_code'],
            ],
            'event' => [
                'id' => (string)$event['id'],
                'name' => (string)$event['name'],
                'location' => (string)$event['location'],
                'organization_id' => (string)$event['organization_id'],
                'office_open_at' => (string)$event['office_open_at'],
                'office_close_at' => (string)$event['office_close_at'],
                'archived_at' => $event['archived_at'] ?? null,
            ],
            'access' => [
                'allowed' => $canAccess,
            ],
        ];
    };

    $assertParticipantEventAccess = static function (PDO $pdo, array $authUser, array $participant) use ($loadEventById, $canAccessEvent, $canManageEventParticipants): array {
        $eventId = trim((string)($participant['event_id'] ?? ''));
        if ($eventId === '') {
            return [null, 'Participant is not assigned to an event'];
        }

        $event = $loadEventById($pdo, $eventId);
        if ($event === false) {
            return [null, 'Event not found'];
        }

        if (!$canAccessEvent($authUser, $event) && !$canManageEventParticipants($authUser, $event)) {
            return [null, 'Forbidden'];
        }

        return [$event, null];
    };

    $updateParticipantStatus = static function (
        PDO $pdo,
        array $participant,
        string $status
    ) use ($loadParticipantById, $isValidParticipantStatus, $participantStatusCountsAsCheckedIn): array {
        if (!$isValidParticipantStatus($status)) {
            throw new InvalidArgumentException('Unsupported participant status');
        }

        $checkedInAt = $participantStatusCountsAsCheckedIn($status)
            ? ($participant['checked_in_at'] ?? gmdate('Y-m-d H:i:s'))
            : null;

        $stmt = $pdo->prepare('UPDATE participants SET status = :status, checked_in_at = :checked_in_at WHERE id = :id');
        $stmt->bindValue(':status', $status);
        $stmt->bindValue(':checked_in_at', $checkedInAt, $checkedInAt === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':id', (int)$participant['id'], PDO::PARAM_INT);
        $stmt->execute();

        return $loadParticipantById($pdo, (int)$participant['id']) ?: $participant;
    };

    $normalizeImportHeader = static function (string $header): string {
        $normalized = trim($header);
        $normalized = preg_replace('/^\xEF\xBB\xBF/u', '', $normalized) ?? $normalized;

        return $normalized;
    };

    $detectCsvDelimiter = static function (string $line): string {
        $candidates = [',', ';', "\t", '|'];
        $bestDelimiter = ',';
        $bestCount = 0;

        foreach ($candidates as $candidate) {
            $count = count(str_getcsv($line, $candidate));
            if ($count > $bestCount) {
                $bestCount = $count;
                $bestDelimiter = $candidate;
            }
        }

        return $bestDelimiter;
    };

    $parseCsvContent = static function (string $csvContent) use ($normalizeImportHeader, $detectCsvDelimiter): array {
        $normalized = preg_replace('/^\xEF\xBB\xBF/u', '', $csvContent) ?? $csvContent;
        $lines = preg_split('/\r\n|\n|\r/', $normalized) ?: [];
        $lines = array_values(array_filter($lines, static fn(string $line): bool => trim($line) !== ''));

        if ($lines === []) {
            return ['headers' => [], 'rows' => []];
        }

        $delimiter = $detectCsvDelimiter($lines[0]);
        $headers = array_map($normalizeImportHeader, str_getcsv(array_shift($lines), $delimiter));
        $headerCount = count($headers);
        $rows = [];

        foreach ($lines as $line) {
            $values = str_getcsv($line, $delimiter);
            if ($values === [null] || $values === false) {
                continue;
            }

            if (count($values) < $headerCount) {
                $values = array_pad($values, $headerCount, '');
            } elseif (count($values) > $headerCount) {
                $values = array_slice($values, 0, $headerCount);
            }

            $row = [];
            foreach ($headers as $index => $header) {
                $row[$header] = trim((string)($values[$index] ?? ''));
            }
            $rows[] = $row;
        }

        return ['headers' => $headers, 'rows' => $rows];
    };

    $cleanupCsvDataset = static function (array $headers, array $rows): array {
        $activeHeaders = [];

        foreach ($headers as $header) {
            if ($header === '') {
                continue;
            }

            foreach ($rows as $row) {
                if (trim((string)($row[$header] ?? '')) !== '') {
                    $activeHeaders[] = $header;
                    continue 2;
                }
            }
        }

        $cleanRows = array_map(static function (array $row) use ($activeHeaders): array {
            $cleanRow = [];
            foreach ($activeHeaders as $header) {
                $cleanRow[$header] = trim((string)($row[$header] ?? ''));
            }

            return $cleanRow;
        }, $rows);

        return ['headers' => $activeHeaders, 'rows' => $cleanRows];
    };

    $findEmailCandidateColumns = static function (array $headers, array $rows): array {
        $matches = [];

        foreach ($headers as $header) {
            $matchedCount = 0;
            foreach ($rows as $row) {
                $value = trim((string)($row[$header] ?? ''));
                if ($value !== '' && isValidEmailAddress($value)) {
                    $matchedCount++;
                }
            }

            if ($matchedCount > 0) {
                $matches[] = [
                    'column' => $header,
                    'matched_count' => $matchedCount,
                ];
            }
        }

        return $matches;
    };

    $buildDisplayNameFromMapping = static function (array $row, array $mappings): string {
        $parts = [];

        foreach ($mappings as $mapping) {
            if (($mapping['field_role'] ?? '') !== 'display_name_part' || !($mapping['is_active'] ?? true)) {
                continue;
            }

            $value = trim((string)($row[(string)$mapping['source_column_name']] ?? ''));
            if ($value !== '') {
                $parts[] = $value;
            }
        }

        return trim(implode(' ', $parts));
    };

    $normalizeCustomFieldsFromMapping = static function (array $row, array $mappings): array {
        $customFields = [];

        foreach ($mappings as $mapping) {
            if (!($mapping['is_active'] ?? true)) {
                continue;
            }

            $role = (string)$mapping['field_role'];
            if (!in_array($role, ['display_name_part', 'bib_number', 'custom'], true)) {
                continue;
            }

            $alias = trim((string)$mapping['alias']);
            if ($alias === '') {
                continue;
            }

            $value = trim((string)($row[(string)$mapping['source_column_name']] ?? ''));
            if ($value === '') {
                continue;
            }

            $customFields[$alias] = $value;
        }

        return $customFields;
    };

    $nextBibNumberForEvent = static function (PDO $pdo, string $eventId): string {
        $stmt = $pdo->prepare('
            SELECT bib_number
            FROM participants
            WHERE event_id = :event_id
              AND bib_number IS NOT NULL
              AND bib_number <> \'\'
        ');
        $stmt->execute(['event_id' => $eventId]);
        $rows = $stmt->fetchAll();
        $maxBib = 0;

        foreach ($rows as $row) {
            $bibNumber = (string)($row['bib_number'] ?? '');
            if (ctype_digit($bibNumber)) {
                $maxBib = max($maxBib, (int)$bibNumber);
            }
        }

        return (string)($maxBib + 1);
    };

    $insertParticipantRecord = static function (
        PDO $pdo,
        string $eventId,
        string $displayName,
        string $email,
        ?string $bibNumber,
        array $customFields,
        string $organization = '',
        ?string $qrCode = null
    ) use ($loadParticipantById, $nextBibNumberForEvent, $generateUniqueParticipantQrCode): array {
        $nameParts = splitParticipantDisplayName($displayName);
        $resolvedBibNumber = $bibNumber !== null && trim($bibNumber) !== ''
            ? trim($bibNumber)
            : $nextBibNumberForEvent($pdo, $eventId);
        $resolvedQrCode = $qrCode !== null && QrCodeService::isSecureToken(trim($qrCode))
            ? trim($qrCode)
            : $generateUniqueParticipantQrCode($pdo);

        $stmt = $pdo->prepare('
            INSERT INTO participants (
                event_id,
                first_name,
                last_name,
                display_name,
                email,
                organization,
                bib_number,
                qr_code,
                custom_fields_json,
                status,
                email_status
            ) VALUES (
                :event_id,
                :first_name,
                :last_name,
                :display_name,
                :email,
                :organization,
                :bib_number,
                :qr_code,
                :custom_fields_json,
                :status,
                :email_status
            )
        ');
        $stmt->execute([
            'event_id' => $eventId,
            'first_name' => $nameParts['first_name'],
            'last_name' => $nameParts['last_name'],
            'display_name' => $displayName,
            'email' => $email,
            'organization' => $organization !== '' ? $organization : null,
            'bib_number' => $resolvedBibNumber,
            'qr_code' => $resolvedQrCode,
            'custom_fields_json' => $customFields !== [] ? json_encode($customFields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'status' => 'not_checked_in',
            'email_status' => 'not_sent',
        ]);

        $participantId = (int)$pdo->lastInsertId();
        return $loadParticipantById($pdo, $participantId) ?: [];
    };

    if (preg_match('#^/qr-images/([A-Za-z0-9_%-]+)\.svg$#', $path, $matches) === 1 && $method === 'GET') {
        $qrCode = rawurldecode((string)$matches[1]);
        $participant = $loadParticipantByQrCode($pdo, $qrCode);

        if ($participant === false) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'QR image not found';
            exit;
        }

        http_response_code(200);
        header('Content-Type: image/svg+xml; charset=utf-8');
        header('Cache-Control: public, max-age=300');
        echo QrCodeService::renderSvg((string)$participant['qr_code'], 320, 10);
        exit;
    }

    if ($path === '/auth/login' && $method === 'POST') {
        $input = readJsonBody();
        $email = normalizeEmailAddress((string)($input['email'] ?? ''));
        $password = (string)($input['password'] ?? '');
        $loginRateLimit = $consumeRateLimitAttempt($pdo, 'auth_login', 5, 900, $email);
        if (!$loginRateLimit['allowed']) {
            jsonResponse(429, [
                'error' => 'Too many login attempts. Please try again later.',
                'retry_after' => (int)$loginRateLimit['retry_after'],
            ]);
            exit;
        }

        if ($email === '' || $password === '') {
            jsonResponse(422, ['error' => 'email and password are required']);
            exit;
        }

        $user = $loadUserWithPasswordByEmail($pdo, $email);

        if ($user === false || !passwordMatches($password, (string)$user['password'])) {
            jsonResponse(401, ['error' => 'Invalid credentials']);
            exit;
        }

        $storedPassword = (string)$user['password'];
        if (!isPasswordHash($storedPassword) || password_needs_rehash($storedPassword, PASSWORD_DEFAULT)) {
            $updatePasswordStmt = $pdo->prepare('UPDATE users SET password = :password WHERE id = :id');
            $updatePasswordStmt->execute([
                'password' => password_hash($password, PASSWORD_DEFAULT),
                'id' => $user['id'],
            ]);
        }

        $user['organization_ids'] = $user['role'] === 'admin'
            ? $loadAdminOrganizationIds($pdo, (string)$user['id'])
            : [];
        $user['assigned_events'] = $isScannerRole((string)$user['role'])
            ? $loadAssignedEvents($pdo, (string)$user['id'])
            : [];
        unset($user['password']);
        $clearRateLimitBucket($pdo, 'auth_login', $email);

        jsonResponse(200, [
            'token_type' => 'Bearer',
            'access_token' => issueAuthToken($user),
            'expires_in' => 28800,
            'user' => $user,
        ]);
        exit;
    }

    if ($path === '/auth/forgot-password' && $method === 'POST') {
        $input = readJsonBody();
        $email = normalizeEmailAddress((string)($input['email'] ?? ''));
        $forgotPasswordRateLimit = $consumeRateLimitAttempt($pdo, 'auth_forgot_password', 5, 900, $email);
        if (!$forgotPasswordRateLimit['allowed']) {
            jsonResponse(429, [
                'error' => forgotPasswordSuccessMessage(),
                'retry_after' => (int)$forgotPasswordRateLimit['retry_after'],
            ]);
            exit;
        }

        if ($email === '' || !isValidEmailAddress($email)) {
            jsonResponse(200, ['message' => forgotPasswordSuccessMessage()]);
            exit;
        }

        $user = $loadUserWithPasswordByEmail($pdo, $email);
        if ($user !== false) {
            try {
                $token = $createPasswordResetToken($pdo, (string)$user['id']);
                $resetUrl = appFrontendUrl() . '/reset-password?token=' . urlencode($token);
                MailService::sendPasswordResetEmail((string)$user['email'], (string)$user['name'], $resetUrl);
            } catch (Throwable $exception) {
                if ((getenv('APP_DEBUG') ?: 'false') === 'true') {
                    jsonResponse(500, ['error' => $exception->getMessage()]);
                    exit;
                }
            }
        }

        jsonResponse(200, ['message' => forgotPasswordSuccessMessage()]);
        exit;
    }

    if ($path === '/auth/reset-password' && $method === 'POST') {
        $input = readJsonBody();
        $token = trim((string)($input['token'] ?? ''));
        $password = (string)($input['password'] ?? '');
        $passwordConfirmation = (string)($input['password_confirmation'] ?? '');
        $resetRateLimit = $consumeRateLimitAttempt($pdo, 'auth_reset_password', 8, 900, $token);
        if (!$resetRateLimit['allowed']) {
            jsonResponse(429, [
                'error' => 'Too many password reset attempts. Please try again later.',
                'retry_after' => (int)$resetRateLimit['retry_after'],
            ]);
            exit;
        }

        if ($token === '' || $password === '' || $passwordConfirmation === '') {
            jsonResponse(422, ['error' => 'token, password and password_confirmation are required']);
            exit;
        }

        if ($password !== $passwordConfirmation) {
            jsonResponse(422, ['error' => 'Password confirmation does not match']);
            exit;
        }

        $passwordValidationError = validatePasswordRules($password);
        if ($passwordValidationError !== null) {
            jsonResponse(422, ['error' => $passwordValidationError]);
            exit;
        }

        $passwordReset = $loadActivePasswordReset($pdo, $token);
        if ($passwordReset === false) {
            jsonResponse(422, ['error' => 'Link resetu hasła jest nieważny lub wygasł']);
            exit;
        }

        $pdo->beginTransaction();

        try {
            $updatePasswordStmt = $pdo->prepare('UPDATE users SET password = :password WHERE id = :id');
            $updatePasswordStmt->execute([
                'password' => password_hash($password, PASSWORD_DEFAULT),
                'id' => $passwordReset['user_id'],
            ]);

            $markPasswordResetTokensUsed($pdo, (string)$passwordReset['user_id']);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }

        $clearRateLimitBucket($pdo, 'auth_reset_password', $token);
        jsonResponse(200, ['message' => 'Hasło zostało zmienione.']);
        exit;
    }

    if ($path === '/auth/change-password' && $method === 'POST') {
        $authUser = requireAuth($resolveAuthenticatedUser);
        $input = readJsonBody();
        $currentPassword = (string)($input['current_password'] ?? '');
        $newPassword = (string)($input['new_password'] ?? '');
        $newPasswordConfirmation = (string)($input['new_password_confirmation'] ?? '');

        if ($currentPassword === '' || $newPassword === '' || $newPasswordConfirmation === '') {
            jsonResponse(422, ['error' => 'current_password, new_password and new_password_confirmation are required']);
            exit;
        }

        if ($newPassword !== $newPasswordConfirmation) {
            jsonResponse(422, ['error' => 'Password confirmation does not match']);
            exit;
        }

        $passwordValidationError = validatePasswordRules($newPassword);
        if ($passwordValidationError !== null) {
            jsonResponse(422, ['error' => $passwordValidationError]);
            exit;
        }

        $stmt = $pdo->prepare('SELECT password FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $authUser['id']]);
        $userPasswordRow = $stmt->fetch();

        if ($userPasswordRow === false || !passwordMatches($currentPassword, (string)$userPasswordRow['password'])) {
            jsonResponse(422, ['error' => 'Aktualne hasło jest nieprawidłowe']);
            exit;
        }

        $pdo->beginTransaction();

        try {
            $updatePasswordStmt = $pdo->prepare('UPDATE users SET password = :password WHERE id = :id');
            $updatePasswordStmt->execute([
                'password' => password_hash($newPassword, PASSWORD_DEFAULT),
                'id' => $authUser['id'],
            ]);

            $markPasswordResetTokensUsed($pdo, (string)$authUser['id']);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }

        jsonResponse(200, ['message' => 'Hasło zostało zmienione.']);
        exit;
    }

    if ($path === '/auth/me' && $method === 'GET') {
        jsonResponse(200, ['data' => requireAuth($resolveAuthenticatedUser)]);
        exit;
    }

    if ($path === '/health' && $method === 'GET') {
        $pdo->query('SELECT 1');
        jsonResponse(200, [
            'status' => 'ok',
            'service' => 'biuro-zawodow-api',
            'timestamp' => gmdate(DATE_ATOM),
        ]);
        exit;
    }

    if ($path === '/bootstrap' && $method === 'GET') {
        $authUser = requireAuth($resolveAuthenticatedUser);
        $events = $loadAccessibleEvents($pdo, $authUser);
        $archivedEvents = $loadAccessibleArchivedEvents($pdo, $authUser);
        $organizations = $filterAccessibleOrganizations($authUser, $loadAllOrganizations($pdo), $events);
        $users = $filterAccessibleUsers($authUser, $loadAllUsers($pdo), $organizations);
        $accessibleEventIds = array_map(
            static fn(array $event): string => (string)$event['id'],
            $events
        );
        $accessibleArchivedEventIds = array_map(
            static fn(array $event): string => (string)$event['id'],
            $archivedEvents
        );
        $participants = $loadParticipantsByEventIds($pdo, $accessibleEventIds);
        $archivedParticipants = $loadParticipantsByEventIds($pdo, $accessibleArchivedEventIds);
        $participants = array_merge($participants, $archivedParticipants);
        $accessibleParticipantIds = array_map(
            static fn(array $participant): string => (string)$participant['id'],
            $participants
        );
        $activityLogs = $filterAccessibleActivityLogs(
            $loadAllActivityLogs($pdo),
            array_merge($accessibleEventIds, $accessibleArchivedEventIds),
            $accessibleParticipantIds
        );

        jsonResponse(200, [
            'data' => [
                'organizations' => $organizations,
                'events' => $events,
                'archivedEvents' => $archivedEvents,
                'users' => $users,
                'participants' => $participants,
                'activityLog' => $activityLogs,
            ],
        ]);
        exit;
    }

    if (preg_match('#^/organizations/([^/]+)$#', $path, $matches) === 1 && $method === 'GET') {
        $authUser = requireAuth($resolveAuthenticatedUser);
        $organizationId = (string)$matches[1];

        if (!$canAccessOrganization($authUser, $organizationId)) {
            jsonResponse(403, ['error' => 'Forbidden']);
            exit;
        }

        $organization = $loadOrganizationById($pdo, $organizationId);
        if ($organization === false) {
            jsonResponse(404, ['error' => 'Organization not found']);
            exit;
        }

        jsonResponse(200, ['data' => $organization]);
        exit;
    }

    if ($path === '/organizations' && $method === 'POST') {
        $authUser = requireAnyRole(['superadmin', 'admin'], $resolveAuthenticatedUser);
        $input = readJsonBody();

        $name = trim((string)($input['name'] ?? ''));
        $eventLimit = $input['event_limit'] ?? null;
        $adminUserId = $authUser['role'] === 'admin'
            ? (string)$authUser['id']
            : trim((string)($input['admin_user_id'] ?? ''));

        if ($name === '') {
            jsonResponse(422, ['error' => 'name is required']);
            exit;
        }

        if (!is_int($eventLimit) && !(is_string($eventLimit) && ctype_digit($eventLimit))) {
            jsonResponse(422, ['error' => 'event_limit must be a non-negative integer']);
            exit;
        }

        if ($adminUserId === '') {
            jsonResponse(422, ['error' => 'admin_user_id is required']);
            exit;
        }

        $adminStmt = $pdo->prepare('SELECT id, role FROM users WHERE id = :id LIMIT 1');
        $adminStmt->execute(['id' => $adminUserId]);
        $adminUser = $adminStmt->fetch();

        if ($adminUser === false || (string)$adminUser['role'] !== 'admin') {
            jsonResponse(422, ['error' => 'Assigned admin not found']);
            exit;
        }

        $existingOrganizationStmt = $pdo->prepare('SELECT id FROM organizations WHERE name = :name LIMIT 1');
        $existingOrganizationStmt->execute(['name' => $name]);
        if ($existingOrganizationStmt->fetch() !== false) {
            jsonResponse(409, ['error' => 'Organization with this name already exists']);
            exit;
        }

        $organizationId = 'org-' . bin2hex(random_bytes(8));
        $eventLimitValue = (int)$eventLimit;

        $pdo->beginTransaction();

        try {
            $insertOrganizationStmt = $pdo->prepare('
                INSERT INTO organizations (id, name, event_limit)
                VALUES (:id, :name, :event_limit)
            ');
            $insertOrganizationStmt->execute([
                'id' => $organizationId,
                'name' => $name,
                'event_limit' => $eventLimitValue,
            ]);

            $assignmentStmt = $pdo->prepare('
                INSERT INTO admin_organization_assignments (user_id, organization_id)
                VALUES (:user_id, :organization_id)
            ');
            $assignmentStmt->execute([
                'user_id' => $adminUserId,
                'organization_id' => $organizationId,
            ]);

            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }

        jsonResponse(201, ['data' => $loadOrganizationById($pdo, $organizationId)]);
        exit;
    }

    if (preg_match('#^/organizations/([^/]+)$#', $path, $matches) === 1 && $method === 'PATCH') {
        $authUser = requireAnyRole(['superadmin', 'admin'], $resolveAuthenticatedUser);
        $organizationId = (string)$matches[1];

        if (!$canAccessOrganization($authUser, $organizationId)) {
            jsonResponse(403, ['error' => 'Forbidden']);
            exit;
        }

        $organization = $loadOrganizationById($pdo, $organizationId);
        if ($organization === false) {
            jsonResponse(404, ['error' => 'Organization not found']);
            exit;
        }

        $input = readJsonBody();
        $hasName = array_key_exists('name', $input);
        $hasEventLimit = array_key_exists('event_limit', $input);
        if (!$hasName && !$hasEventLimit) {
            jsonResponse(422, ['error' => 'At least one organization field must be provided']);
            exit;
        }

        $name = $hasName ? trim((string)$input['name']) : (string)$organization['name'];
        if ($name === '') {
            jsonResponse(422, ['error' => 'name is required']);
            exit;
        }

        $eventLimitValue = (int)$organization['event_limit'];
        if ($hasEventLimit) {
            $eventLimit = $input['event_limit'];
            if (!is_int($eventLimit) && !(is_string($eventLimit) && ctype_digit($eventLimit))) {
                jsonResponse(422, ['error' => 'event_limit must be a non-negative integer']);
                exit;
            }

            $eventLimitValue = (int)$eventLimit;
        }

        $eventCountStmt = $pdo->prepare('SELECT COUNT(*) AS total FROM events WHERE organization_id = :organization_id AND archived_at IS NULL');
        $eventCountStmt->execute(['organization_id' => $organizationId]);
        $eventCount = (int)($eventCountStmt->fetch()['total'] ?? 0);
        if ($eventLimitValue < $eventCount) {
            jsonResponse(422, ['error' => 'event_limit cannot be lower than the current number of events']);
            exit;
        }

        $existingOrganizationStmt = $pdo->prepare('SELECT id FROM organizations WHERE name = :name AND id <> :id LIMIT 1');
        $existingOrganizationStmt->execute([
            'name' => $name,
            'id' => $organizationId,
        ]);
        if ($existingOrganizationStmt->fetch() !== false) {
            jsonResponse(409, ['error' => 'Organization with this name already exists']);
            exit;
        }

        $updateStmt = $pdo->prepare('
            UPDATE organizations
            SET name = :name, event_limit = :event_limit
            WHERE id = :id
        ');
        $updateStmt->execute([
            'name' => $name,
            'event_limit' => $eventLimitValue,
            'id' => $organizationId,
        ]);

        if ($name !== (string)$organization['name']) {
            $addActivityLog(
                $pdo,
                sprintf('Zmieniono nazwę organizacji na %s', $name),
                null,
                null,
                null,
                (string)$authUser['id'],
                (string)$authUser['name']
            );
        }

        if ($eventLimitValue !== (int)$organization['event_limit']) {
            $addActivityLog(
                $pdo,
                sprintf('Zmieniono limit wydarzeń organizacji %s na %d', $name, $eventLimitValue),
                null,
                null,
                null,
                (string)$authUser['id'],
                (string)$authUser['name']
            );
        }

        jsonResponse(200, ['data' => $loadOrganizationById($pdo, $organizationId)]);
        exit;
    }

    if (preg_match('#^/organizations/([^/]+)$#', $path, $matches) === 1 && $method === 'DELETE') {
        $authUser = requireAnyRole(['superadmin', 'admin'], $resolveAuthenticatedUser);
        $organizationId = (string)$matches[1];

        if (!$canAccessOrganization($authUser, $organizationId)) {
            jsonResponse(403, ['error' => 'Forbidden']);
            exit;
        }

        $organization = $loadOrganizationById($pdo, $organizationId);
        if ($organization === false) {
            jsonResponse(404, ['error' => 'Organization not found']);
            exit;
        }

        $eventCountStmt = $pdo->prepare('SELECT COUNT(*) AS total FROM events WHERE organization_id = :organization_id');
        $eventCountStmt->execute(['organization_id' => $organizationId]);
        $eventCount = (int)($eventCountStmt->fetch()['total'] ?? 0);
        if ($eventCount > 0) {
            jsonResponse(422, ['error' => 'Organization with events cannot be deleted']);
            exit;
        }

        $memberCountStmt = $pdo->prepare('SELECT COUNT(*) AS total FROM users WHERE organization_id = :organization_id AND archived_at IS NULL');
        $memberCountStmt->execute(['organization_id' => $organizationId]);
        $memberCount = (int)($memberCountStmt->fetch()['total'] ?? 0);
        if ($memberCount > 0) {
            jsonResponse(422, ['error' => 'Organization with assigned users cannot be deleted']);
            exit;
        }

        $deleteStmt = $pdo->prepare('DELETE FROM organizations WHERE id = :id');
        $deleteStmt->execute(['id' => $organizationId]);

        $addActivityLog(
            $pdo,
            sprintf('Usunięto organizację: %s', (string)$organization['name']),
            null,
            null,
            null,
            (string)$authUser['id'],
            (string)$authUser['name']
        );

        jsonResponse(200, ['success' => true]);
        exit;
    }

    if ($path === '/events' && $method === 'POST') {
        $authUser = requireAnyRole(['superadmin', 'admin', 'editor'], $resolveAuthenticatedUser);
        $input = readJsonBody();

        $name = trim((string)($input['name'] ?? ''));
        $location = trim((string)($input['location'] ?? ''));
        $organizationId = trim((string)($input['organization_id'] ?? ''));
        $officeOpenAt = trim((string)($input['office_open_at'] ?? ''));
        $officeCloseAt = trim((string)($input['office_close_at'] ?? ''));

        if ($name === '' || $location === '' || $organizationId === '' || $officeOpenAt === '' || $officeCloseAt === '') {
            jsonResponse(422, ['error' => 'name, location, organization_id, office_open_at and office_close_at are required']);
            exit;
        }

        if (!isValidLocalDateTimeString($officeOpenAt) || !isValidLocalDateTimeString($officeCloseAt)) {
            jsonResponse(422, ['error' => 'office_open_at and office_close_at must be valid local date-time values']);
            exit;
        }

        $normalizedOfficeOpenAt = normalizeLocalDateTimeString($officeOpenAt);
        $normalizedOfficeCloseAt = normalizeLocalDateTimeString($officeCloseAt);
        if ($normalizedOfficeOpenAt === null || $normalizedOfficeCloseAt === null) {
            jsonResponse(422, ['error' => 'office_open_at and office_close_at must be valid local date-time values']);
            exit;
        }

        if ($normalizedOfficeOpenAt >= $normalizedOfficeCloseAt) {
            jsonResponse(422, ['error' => 'office_open_at must be earlier than office_close_at']);
            exit;
        }

        $eventDate = substr($normalizedOfficeOpenAt, 0, 10);

        if (!$canAccessOrganization($authUser, $organizationId)) {
            jsonResponse(403, ['error' => 'Forbidden']);
            exit;
        }

        $organization = $loadOrganizationById($pdo, $organizationId);
        if ($organization === false) {
            jsonResponse(422, ['error' => 'Organization not found']);
            exit;
        }

        $eventCountStmt = $pdo->prepare('SELECT COUNT(*) AS total FROM events WHERE organization_id = :organization_id AND archived_at IS NULL');
        $eventCountStmt->execute(['organization_id' => $organizationId]);
        $eventCount = (int)($eventCountStmt->fetch()['total'] ?? 0);

        if ($eventCount >= (int)$organization['event_limit']) {
            jsonResponse(422, ['error' => 'Organization event limit has been reached']);
            exit;
        }

        $eventId = 'evt-' . bin2hex(random_bytes(8));

        $pdo->beginTransaction();

        try {
            $insertEventStmt = $pdo->prepare('
                INSERT INTO events (id, name, event_date, location, organization_id, office_open_at, office_close_at)
                VALUES (:id, :name, :event_date, :location, :organization_id, :office_open_at, :office_close_at)
            ');
            $insertEventStmt->execute([
                'id' => $eventId,
                'name' => $name,
                'event_date' => $eventDate,
                'location' => $location,
                'organization_id' => $organizationId,
                'office_open_at' => $normalizedOfficeOpenAt,
                'office_close_at' => $normalizedOfficeCloseAt,
            ]);

            $activityStmt = $pdo->prepare('
                INSERT INTO activity_logs (
                    id,
                    action,
                    event_id,
                    participant_id,
                    participant_name_snapshot,
                    user_id,
                    user_name_snapshot
                ) VALUES (
                    :id,
                    :action,
                    :event_id,
                    NULL,
                    NULL,
                    :user_id,
                    :user_name_snapshot
                )
            ');
            $activityStmt->execute([
                'id' => 'log-' . bin2hex(random_bytes(8)),
                'action' => sprintf('Utworzono wydarzenie: %s', $name),
                'event_id' => $eventId,
                'user_id' => $authUser['id'],
                'user_name_snapshot' => $authUser['name'],
            ]);

            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }

        jsonResponse(201, [
            'data' => [
                'id' => $eventId,
                'name' => $name,
                'location' => $location,
                'organization_id' => $organizationId,
                'office_open_at' => str_replace(' ', 'T', $normalizedOfficeOpenAt),
                'office_close_at' => str_replace(' ', 'T', $normalizedOfficeCloseAt),
            ],
        ]);
        exit;
    }

    if (preg_match('#^/events/([^/]+)$#', $path, $matches) === 1 && $method === 'GET') {
        $authUser = requireAuth($resolveAuthenticatedUser);
        $event = $loadEventById($pdo, (string)$matches[1]);

        if ($event === false) {
            jsonResponse(404, ['error' => 'Event not found']);
            exit;
        }

        if (!$canAccessEvent($authUser, $event) && !$canViewArchivedEvent($authUser, $event)) {
            jsonResponse(403, ['error' => 'Forbidden']);
            exit;
        }

        jsonResponse(200, ['data' => $event]);
        exit;
    }

    if (preg_match('#^/events/([^/]+)$#', $path, $matches) === 1 && $method === 'PATCH') {
        $authUser = requireAnyRole(['superadmin', 'admin', 'editor'], $resolveAuthenticatedUser);
        $eventId = (string)$matches[1];
        $event = $loadEventById($pdo, $eventId);

        if ($event === false) {
            jsonResponse(404, ['error' => 'Event not found']);
            exit;
        }

        if (!$canManageEventParticipants($authUser, $event)) {
            jsonResponse(403, ['error' => 'Forbidden']);
            exit;
        }

        $input = readJsonBody();

        $name = trim((string)($input['name'] ?? ''));
        $location = trim((string)($input['location'] ?? ''));
        $officeOpenAt = trim((string)($input['office_open_at'] ?? ''));
        $officeCloseAt = trim((string)($input['office_close_at'] ?? ''));

        if ($name === '' || $location === '' || $officeOpenAt === '' || $officeCloseAt === '') {
            jsonResponse(422, ['error' => 'name, location, office_open_at and office_close_at are required']);
            exit;
        }

        if (!isValidLocalDateTimeString($officeOpenAt) || !isValidLocalDateTimeString($officeCloseAt)) {
            jsonResponse(422, ['error' => 'office_open_at and office_close_at must be valid local date-time values']);
            exit;
        }

        $normalizedOfficeOpenAt = normalizeLocalDateTimeString($officeOpenAt);
        $normalizedOfficeCloseAt = normalizeLocalDateTimeString($officeCloseAt);
        if ($normalizedOfficeOpenAt === null || $normalizedOfficeCloseAt === null) {
            jsonResponse(422, ['error' => 'office_open_at and office_close_at must be valid local date-time values']);
            exit;
        }

        if ($normalizedOfficeOpenAt >= $normalizedOfficeCloseAt) {
            jsonResponse(422, ['error' => 'office_open_at must be earlier than office_close_at']);
            exit;
        }

        $eventDate = substr($normalizedOfficeOpenAt, 0, 10);

        $stmt = $pdo->prepare('
            UPDATE events
            SET
                name = :name,
                event_date = :event_date,
                location = :location,
                office_open_at = :office_open_at,
                office_close_at = :office_close_at
            WHERE id = :id
            LIMIT 1
        ');
        $stmt->execute([
            'id' => $eventId,
            'name' => $name,
            'event_date' => $eventDate,
            'location' => $location,
            'office_open_at' => $normalizedOfficeOpenAt,
            'office_close_at' => $normalizedOfficeCloseAt,
        ]);

        $addActivityLog(
            $pdo,
            sprintf('Zaktualizowano wydarzenie: %s', $name),
            $eventId,
            null,
            null,
            (string)$authUser['id'],
            (string)$authUser['name']
        );

        jsonResponse(200, ['data' => $loadEventById($pdo, $eventId)]);
        exit;
    }

    if (preg_match('#^/events/([^/]+)$#', $path, $matches) === 1 && $method === 'DELETE') {
        $authUser = requireAnyRole(['superadmin', 'admin', 'editor'], $resolveAuthenticatedUser);
        $eventId = (string)$matches[1];
        $event = $loadEventById($pdo, $eventId);

        if ($event === false) {
            jsonResponse(404, ['error' => 'Event not found']);
            exit;
        }

        if (!$canManageEventParticipants($authUser, $event)) {
            jsonResponse(403, ['error' => 'Forbidden']);
            exit;
        }

        if (trim((string)($event['archived_at'] ?? '')) !== '') {
            jsonResponse(422, ['error' => 'Event is already archived']);
            exit;
        }

        $officeCloseAt = parseLocalDateTimeString((string)($event['office_close_at'] ?? ''));
        if ($officeCloseAt === null || new DateTimeImmutable() <= $officeCloseAt) {
            jsonResponse(422, ['error' => 'Only finished events can be archived']);
            exit;
        }

        $participantCountStmt = $pdo->prepare('SELECT COUNT(*) AS total FROM participants WHERE event_id = :event_id');
        $participantCountStmt->execute(['event_id' => $eventId]);
        $participantCount = (int)($participantCountStmt->fetch()['total'] ?? 0);

        $pdo->beginTransaction();

        try {
            $addActivityLog(
                $pdo,
                sprintf('Zarchiwizowano wydarzenie: %s (%d uczestnikow)', (string)$event['name'], $participantCount),
                $eventId,
                null,
                null,
                (string)$authUser['id'],
                (string)$authUser['name']
            );

            $archiveEventStmt = $pdo->prepare('UPDATE events SET archived_at = UTC_TIMESTAMP() WHERE id = :id');
            $archiveEventStmt->execute(['id' => $eventId]);

            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }

        jsonResponse(200, ['success' => true]);
        exit;
    }

    if (preg_match('#^/events/([^/]+)/export\.csv$#', $path, $matches) === 1 && $method === 'GET') {
        $authUser = requireAnyRole(['superadmin', 'admin', 'editor'], $resolveAuthenticatedUser);
        $eventId = (string)$matches[1];
        $event = $loadEventById($pdo, $eventId);

        if ($event === false) {
            jsonResponse(404, ['error' => 'Event not found']);
            exit;
        }

        if (!$canManageEventParticipants($authUser, $event)) {
            jsonResponse(403, ['error' => 'Forbidden']);
            exit;
        }

        $participants = $loadParticipantsByEventId($pdo, $eventId);
        $mappings = $loadParticipantFieldMappings($pdo, $eventId);

        $customFieldColumns = [];
        foreach ($mappings as $mapping) {
            $alias = trim((string)($mapping['alias'] ?? ''));
            if ($alias === '' || in_array($alias, ['Email'], true)) {
                continue;
            }

            $customFieldColumns[] = $alias;
        }

        foreach ($participants as $participant) {
            foreach (($participant['custom_fields'] ?? []) as $fieldName => $value) {
                $fieldName = trim((string)$fieldName);
                if ($fieldName === '' || in_array($fieldName, $customFieldColumns, true)) {
                    continue;
                }

                $customFieldColumns[] = $fieldName;
            }
        }

        $columns = array_merge([
            'participant_id',
            'event_id',
            'event_name',
            'event_location',
            'event_office_open_at',
            'event_office_close_at',
            'organization_id',
            'display_name',
            'first_name',
            'last_name',
            'email',
            'organization',
            'bib_number',
            'status',
            'email_status',
            'checked_in_at',
            'qr_code',
            'created_at',
            'updated_at',
        ], $customFieldColumns);

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            jsonResponse(500, ['error' => 'Failed to initialize CSV export']);
            exit;
        }

        fputcsv($stream, $columns);

        foreach ($participants as $participant) {
            $row = [
                'participant_id' => (string)$participant['id'],
                'event_id' => (string)$event['id'],
                'event_name' => (string)$event['name'],
                'event_location' => (string)$event['location'],
                'event_office_open_at' => (string)$event['office_open_at'],
                'event_office_close_at' => (string)$event['office_close_at'],
                'organization_id' => (string)$event['organization_id'],
                'display_name' => (string)($participant['display_name'] ?? ''),
                'first_name' => (string)($participant['first_name'] ?? ''),
                'last_name' => (string)($participant['last_name'] ?? ''),
                'email' => (string)($participant['email'] ?? ''),
                'organization' => (string)($participant['organization'] ?? ''),
                'bib_number' => (string)($participant['bib_number'] ?? ''),
                'status' => (string)($participant['status'] ?? ''),
                'email_status' => (string)($participant['email_status'] ?? ''),
                'checked_in_at' => (string)($participant['checked_in_at'] ?? ''),
                'qr_code' => (string)($participant['qr_code'] ?? ''),
                'created_at' => (string)($participant['created_at'] ?? ''),
                'updated_at' => (string)($participant['updated_at'] ?? ''),
            ];

            foreach ($customFieldColumns as $columnName) {
                $row[$columnName] = (string)(($participant['custom_fields'] ?? [])[$columnName] ?? '');
            }

            fputcsv($stream, array_map(
                static fn(string $columnName): string => (string)($row[$columnName] ?? ''),
                $columns
            ));
        }

        rewind($stream);
        $csvContent = stream_get_contents($stream);
        fclose($stream);

        $addActivityLog(
            $pdo,
            'Wyeksportowano dane uczestnikow do CSV',
            $eventId,
            null,
            null,
            (string)$authUser['id'],
            (string)$authUser['name']
        );

        if ($csvContent === false) {
            jsonResponse(500, ['error' => 'Failed to build CSV export']);
            exit;
        }

        $safeEventName = preg_replace('/[^a-zA-Z0-9_-]+/', '-', (string)$event['name']) ?: 'event';
        header('Content-Type: text/csv; charset=UTF-8');
        header(sprintf('Content-Disposition: attachment; filename="%s-participants.csv"', trim($safeEventName, '-')));
        echo "\xEF\xBB\xBF";
        echo $csvContent;
        exit;
    }

    if (preg_match('#^/events/([^/]+)/logs/export\.csv$#', $path, $matches) === 1 && $method === 'GET') {
        $authUser = requireAnyRole(['superadmin', 'admin', 'editor'], $resolveAuthenticatedUser);
        $eventId = (string)$matches[1];
        $event = $loadEventById($pdo, $eventId);

        if ($event === false) {
            jsonResponse(404, ['error' => 'Event not found']);
            exit;
        }

        if (!$canManageEventParticipants($authUser, $event)) {
            jsonResponse(403, ['error' => 'Forbidden']);
            exit;
        }

        $stmt = $pdo->prepare('
            SELECT
                al.id,
                COALESCE(al.event_id, p.event_id) AS event_id,
                al.action,
                al.participant_id,
                al.participant_name_snapshot AS participant_name,
                al.user_id,
                al.user_name_snapshot AS user_name,
                al.created_at AS timestamp
            FROM activity_logs al
            LEFT JOIN participants p ON p.id = al.participant_id
            WHERE COALESCE(al.event_id, p.event_id) = :event_id
            ORDER BY al.created_at DESC, al.id DESC
        ');
        $stmt->execute(['event_id' => $eventId]);
        $logs = $stmt->fetchAll();

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            jsonResponse(500, ['error' => 'Failed to initialize CSV export']);
            exit;
        }

        fputcsv($stream, ['log_id', 'event_id', 'event_name', 'user_id', 'user_name', 'participant_id', 'participant_name', 'action', 'timestamp']);
        foreach ($logs as $log) {
            fputcsv($stream, [
                (string)($log['id'] ?? ''),
                (string)($log['event_id'] ?? ''),
                (string)$event['name'],
                (string)($log['user_id'] ?? ''),
                (string)($log['user_name'] ?? ''),
                (string)($log['participant_id'] ?? ''),
                (string)($log['participant_name'] ?? ''),
                (string)($log['action'] ?? ''),
                (string)($log['timestamp'] ?? ''),
            ]);
        }

        rewind($stream);
        $csvContent = stream_get_contents($stream);
        fclose($stream);

        $addActivityLog(
            $pdo,
            'Wyeksportowano logi wydarzenia do CSV',
            $eventId,
            null,
            null,
            (string)$authUser['id'],
            (string)$authUser['name']
        );

        if ($csvContent === false) {
            jsonResponse(500, ['error' => 'Failed to build CSV export']);
            exit;
        }

        $safeEventName = preg_replace('/[^a-zA-Z0-9_-]+/', '-', (string)$event['name']) ?: 'event';
        header('Content-Type: text/csv; charset=UTF-8');
        header(sprintf('Content-Disposition: attachment; filename="%s-logs.csv"', trim($safeEventName, '-')));
        echo "\xEF\xBB\xBF";
        echo $csvContent;
        exit;
    }

    if (preg_match('#^/events/([^/]+)/participant-imports/analyze$#', $path, $matches) === 1 && $method === 'POST') {
        $authUser = requireAnyRole(['superadmin', 'admin', 'editor'], $resolveAuthenticatedUser);
        $eventId = (string)$matches[1];
        $event = $loadEventById($pdo, $eventId);

        if ($event === false) {
            jsonResponse(404, ['error' => 'Event not found']);
            exit;
        }

        if (!$canManageEventParticipants($authUser, $event)) {
            jsonResponse(403, ['error' => 'Forbidden']);
            exit;
        }

        $input = readJsonBody(10485760);
        $csvContent = (string)($input['csv_content'] ?? '');
        if (trim($csvContent) === '') {
            jsonResponse(422, ['error' => 'csv_content is required']);
            exit;
        }

        $parsed = $parseCsvContent($csvContent);
        $cleaned = $cleanupCsvDataset($parsed['headers'], $parsed['rows']);
        if ($cleaned['headers'] === []) {
            jsonResponse(422, ['error' => 'CSV does not contain any non-empty data columns']);
            exit;
        }

        $emailCandidates = $findEmailCandidateColumns($cleaned['headers'], $cleaned['rows']);
        if ($emailCandidates === []) {
            jsonResponse(422, ['error' => 'CSV is invalid: no email address column detected. Each participant must have an email address.']);
            exit;
        }

        $existingMappings = $loadParticipantFieldMappings($pdo, $eventId);
        $requiredColumns = array_values(array_map(
            static fn(array $mapping): string => (string)$mapping['source_column_name'],
            array_filter($existingMappings, static fn(array $mapping): bool => (bool)$mapping['is_required'])
        ));
        $missingColumns = array_values(array_diff($requiredColumns, $cleaned['headers']));

        jsonResponse(200, [
            'data' => [
                'headers' => $cleaned['headers'],
                'sample_rows' => array_slice($cleaned['rows'], 0, 5),
                'email_candidates' => $emailCandidates,
                'has_mapping' => $existingMappings !== [],
                'mappings' => $existingMappings,
                'missing_required_columns' => $missingColumns,
                'row_count' => count($cleaned['rows']),
            ],
        ]);
        exit;
    }

    if (preg_match('#^/events/([^/]+)/participant-imports/confirm$#', $path, $matches) === 1 && $method === 'POST') {
        $authUser = requireAnyRole(['superadmin', 'admin', 'editor'], $resolveAuthenticatedUser);
        $eventId = (string)$matches[1];
        $event = $loadEventById($pdo, $eventId);

        if ($event === false) {
            jsonResponse(404, ['error' => 'Event not found']);
            exit;
        }

        if (!$canManageEventParticipants($authUser, $event)) {
            jsonResponse(403, ['error' => 'Forbidden']);
            exit;
        }

        if ($loadParticipantFieldMappings($pdo, $eventId) !== []) {
            jsonResponse(409, ['error' => 'Participant field mapping already exists for this event']);
            exit;
        }

        $input = readJsonBody();
        $csvColumns = array_values(array_filter(
            is_array($input['csv_columns'] ?? null) ? $input['csv_columns'] : [],
            static fn(mixed $column): bool => is_string($column) && trim($column) !== ''
        ));
        $emailColumn = trim((string)($input['email_column'] ?? ''));
        $fields = is_array($input['fields'] ?? null) ? $input['fields'] : [];

        if ($csvColumns === [] || $emailColumn === '') {
            jsonResponse(422, ['error' => 'csv_columns and email_column are required']);
            exit;
        }

        if (!in_array($emailColumn, $csvColumns, true)) {
            jsonResponse(422, ['error' => 'email_column must exist in CSV columns']);
            exit;
        }

        $mappingsToSave = [[
            'source_column_name' => $emailColumn,
            'alias' => 'Email',
            'field_role' => 'email',
            'display_order' => 0,
            'is_required' => true,
            'is_active' => true,
        ]];

        $displayNamePartCount = 0;
        $seenColumns = [$emailColumn => true];
        $displayOrder = 1;

        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }

            $sourceColumnName = trim((string)($field['source_column_name'] ?? ''));
            $alias = trim((string)($field['alias'] ?? ''));
            $fieldRole = trim((string)($field['field_role'] ?? ''));
            $isActive = (bool)($field['is_active'] ?? false);

            if ($sourceColumnName === '' || !in_array($sourceColumnName, $csvColumns, true) || isset($seenColumns[$sourceColumnName])) {
                continue;
            }

            $seenColumns[$sourceColumnName] = true;

            if (!$isActive) {
                continue;
            }

            if ($alias === '') {
                jsonResponse(422, ['error' => sprintf('Alias is required for active column "%s"', $sourceColumnName)]);
                exit;
            }

            if (!in_array($fieldRole, ['display_name_part', 'bib_number', 'custom'], true)) {
                jsonResponse(422, ['error' => sprintf('Invalid field role for column "%s"', $sourceColumnName)]);
                exit;
            }

            if ($fieldRole === 'display_name_part') {
                $displayNamePartCount++;
            }

            $mappingsToSave[] = [
                'source_column_name' => $sourceColumnName,
                'alias' => $alias,
                'field_role' => $fieldRole,
                'display_order' => $displayOrder++,
                'is_required' => $fieldRole === 'display_name_part',
                'is_active' => true,
            ];
        }

        if ($displayNamePartCount === 0) {
            jsonResponse(422, ['error' => 'At least one active display_name_part mapping is required']);
            exit;
        }

        $pdo->beginTransaction();

        try {
            $insertStmt = $pdo->prepare('
                INSERT INTO event_participant_field_mappings (
                    event_id,
                    source_column_name,
                    alias,
                    field_role,
                    display_order,
                    is_required,
                    is_active
                ) VALUES (
                    :event_id,
                    :source_column_name,
                    :alias,
                    :field_role,
                    :display_order,
                    :is_required,
                    :is_active
                )
            ');

            foreach ($mappingsToSave as $mapping) {
                $insertStmt->execute([
                    'event_id' => $eventId,
                    'source_column_name' => $mapping['source_column_name'],
                    'alias' => $mapping['alias'],
                    'field_role' => $mapping['field_role'],
                    'display_order' => $mapping['display_order'],
                    'is_required' => $mapping['is_required'] ? 1 : 0,
                    'is_active' => $mapping['is_active'] ? 1 : 0,
                ]);
            }

            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }

        $addActivityLog(
            $pdo,
            'Zapisano mapowanie pol CSV dla wydarzenia',
            $eventId,
            null,
            null,
            (string)$authUser['id'],
            (string)$authUser['name']
        );

        jsonResponse(201, ['data' => $loadParticipantFieldMappings($pdo, $eventId)]);
        exit;
    }

    if (preg_match('#^/events/([^/]+)/participant-imports/run$#', $path, $matches) === 1 && $method === 'POST') {
        $authUser = requireAnyRole(['superadmin', 'admin', 'editor'], $resolveAuthenticatedUser);
        $eventId = (string)$matches[1];
        $event = $loadEventById($pdo, $eventId);

        if ($event === false) {
            jsonResponse(404, ['error' => 'Event not found']);
            exit;
        }

        if (!$canManageEventParticipants($authUser, $event)) {
            jsonResponse(403, ['error' => 'Forbidden']);
            exit;
        }

        $mappings = $loadParticipantFieldMappings($pdo, $eventId);
        if ($mappings === []) {
            jsonResponse(422, ['error' => 'Participant field mapping must be configured before import']);
            exit;
        }

        $input = readJsonBody(10485760);
        $csvContent = (string)($input['csv_content'] ?? '');
        if (trim($csvContent) === '') {
            jsonResponse(422, ['error' => 'csv_content is required']);
            exit;
        }

        $parsed = $parseCsvContent($csvContent);
        $cleaned = $cleanupCsvDataset($parsed['headers'], $parsed['rows']);
        $requiredColumns = array_values(array_map(
            static fn(array $mapping): string => (string)$mapping['source_column_name'],
            array_filter($mappings, static fn(array $mapping): bool => (bool)$mapping['is_required'])
        ));
        $missingColumns = array_values(array_diff($requiredColumns, $cleaned['headers']));
        if ($missingColumns !== []) {
            jsonResponse(422, [
                'error' => 'CSV is missing required columns from the saved mapping',
                'missing_columns' => $missingColumns,
            ]);
            exit;
        }

        $emailMapping = null;
        foreach ($mappings as $mapping) {
            if ($mapping['field_role'] === 'email') {
                $emailMapping = $mapping;
                break;
            }
        }

        if ($emailMapping === null) {
            jsonResponse(500, ['error' => 'Saved mapping is invalid: missing email column']);
            exit;
        }

        $existingParticipantsStmt = $pdo->prepare('
            SELECT email, display_name, bib_number
            FROM participants
            WHERE event_id = :event_id
        ');
        $existingParticipantsStmt->execute(['event_id' => $eventId]);
        $existingBibKeys = [];
        $existingFallbackKeys = [];
        foreach ($existingParticipantsStmt->fetchAll() as $row) {
            $emailKey = strtolower(trim((string)($row['email'] ?? '')));
            $displayNameKey = strtolower(trim((string)($row['display_name'] ?? '')));
            $bibKey = trim((string)($row['bib_number'] ?? ''));

            if ($bibKey !== '') {
                $existingBibKeys[$emailKey . '|' . $bibKey] = true;
            }

            if ($emailKey !== '' && $displayNameKey !== '') {
                $existingFallbackKeys[$emailKey . '|' . $displayNameKey] = true;
            }
        }

        $createdParticipants = [];
        $duplicateCount = 0;
        $invalidCount = 0;
        $invalidRows = [];

        foreach ($cleaned['rows'] as $index => $row) {
            $email = trim((string)($row[(string)$emailMapping['source_column_name']] ?? ''));
            $displayName = $buildDisplayNameFromMapping($row, $mappings);

            if ($email === '' || !isValidEmailAddress($email) || $displayName === '') {
                $invalidCount++;
                $invalidRows[] = $index + 2;
                continue;
            }

            $normalizedEmail = strtolower($email);

            $bibNumber = null;
            foreach ($mappings as $mapping) {
                if ($mapping['field_role'] === 'bib_number') {
                    $candidateBib = trim((string)($row[(string)$mapping['source_column_name']] ?? ''));
                    if ($candidateBib !== '') {
                        $bibNumber = $candidateBib;
                    }
                    break;
                }
            }

            $fallbackKey = $normalizedEmail . '|' . strtolower($displayName);
            $bibDuplicateKey = $bibNumber !== null ? $normalizedEmail . '|' . $bibNumber : null;

            if (($bibDuplicateKey !== null && isset($existingBibKeys[$bibDuplicateKey])) || ($bibDuplicateKey === null && isset($existingFallbackKeys[$fallbackKey]))) {
                $duplicateCount++;
                continue;
            }

            $customFields = $normalizeCustomFieldsFromMapping($row, $mappings);
            $participant = $insertParticipantRecord($pdo, $eventId, $displayName, $email, $bibNumber, $customFields);
            if ($participant !== []) {
                $createdParticipants[] = $participant;
                $createdBibNumber = trim((string)($participant['bib_number'] ?? ''));
                if ($createdBibNumber !== '') {
                    $existingBibKeys[$normalizedEmail . '|' . $createdBibNumber] = true;
                }
                $existingFallbackKeys[$fallbackKey] = true;
            }
        }

        $addActivityLog(
            $pdo,
            sprintf(
                'Zaimportowano CSV uczestnikow: dodano %d, duplikaty %d, niepoprawne %d',
                count($createdParticipants),
                $duplicateCount,
                $invalidCount
            ),
            $eventId,
            null,
            null,
            (string)$authUser['id'],
            (string)$authUser['name']
        );
        jsonResponse(200, [
            'data' => [
                'created_count' => count($createdParticipants),
                'duplicate_count' => $duplicateCount,
                'invalid_count' => $invalidCount,
                'invalid_rows' => $invalidRows,
                'participants' => $createdParticipants,
            ],
        ]);
        exit;
    }

    if (preg_match('#^/events/([^/]+)/participant-field-mappings$#', $path, $matches) === 1 && $method === 'GET') {
        $authUser = requireAnyRole(['superadmin', 'admin', 'editor', 'scanner_plus'], $resolveAuthenticatedUser);
        $eventId = (string)$matches[1];
        $event = $loadEventById($pdo, $eventId);

        if ($event === false) {
            jsonResponse(404, ['error' => 'Event not found']);
            exit;
        }

        if (!$canManageEventParticipants($authUser, $event)) {
            jsonResponse(403, ['error' => 'Forbidden']);
            exit;
        }

        $mappings = $loadParticipantFieldMappings($pdo, $eventId);
        jsonResponse(200, [
            'data' => [
                'has_mapping' => $mappings !== [],
                'mappings' => $mappings,
            ],
        ]);
        exit;
    }

    if (preg_match('#^/events/([^/]+)/participants/manual$#', $path, $matches) === 1 && $method === 'POST') {
        $authUser = requireAnyRole(['superadmin', 'admin', 'editor'], $resolveAuthenticatedUser);
        $eventId = (string)$matches[1];
        $event = $loadEventById($pdo, $eventId);

        if ($event === false) {
            jsonResponse(404, ['error' => 'Event not found']);
            exit;
        }

        if (!$canManageEventParticipants($authUser, $event)) {
            jsonResponse(403, ['error' => 'Forbidden']);
            exit;
        }

        $mappings = $loadParticipantFieldMappings($pdo, $eventId);
        if ($mappings === []) {
            jsonResponse(422, ['error' => 'Manual participant creation is available only after the first CSV mapping is saved']);
            exit;
        }

        $input = readJsonBody();
        $email = normalizeEmailAddress((string)($input['email'] ?? ''));
        $fieldValues = is_array($input['field_values'] ?? null) ? $input['field_values'] : [];

        if ($email === '' || !isValidEmailAddress($email)) {
            jsonResponse(422, ['error' => 'A valid email is required']);
            exit;
        }

        $resolvedData = $buildParticipantDataFromMappings($mappings, $fieldValues);
        if ($resolvedData['display_name'] === '') {
            jsonResponse(422, ['error' => 'At least one display name field is required']);
            exit;
        }

        if ($resolvedData['missing_fields'] !== []) {
            jsonResponse(422, ['error' => 'Missing required participant fields', 'missing_fields' => $resolvedData['missing_fields']]);
            exit;
        }

        $participant = $insertParticipantRecord(
            $pdo,
            $eventId,
            $resolvedData['display_name'],
            $email,
            is_string($resolvedData['bib_number']) ? $resolvedData['bib_number'] : null,
            is_array($resolvedData['custom_fields']) ? $resolvedData['custom_fields'] : []
        );
        $addActivityLog(
            $pdo,
            'Dodano uczestnika recznie',
            $eventId,
            (int)$participant['id'],
            (string)$participant['display_name'],
            (string)$authUser['id'],
            (string)$authUser['name']
        );
        jsonResponse(201, ['data' => $participant]);
        exit;
    }

    if (preg_match('#^/organizations/([^/]+)/event-limit$#', $path, $matches) === 1 && $method === 'POST') {
        $authUser = requireAnyRole(['superadmin', 'admin'], $resolveAuthenticatedUser);
        $organizationId = (string)$matches[1];
        $input = readJsonBody();
        $eventLimit = $input['event_limit'] ?? null;

        if (!$canAccessOrganization($authUser, $organizationId)) {
            jsonResponse(403, ['error' => 'Forbidden']);
            exit;
        }

        if (!is_int($eventLimit) && !(is_string($eventLimit) && ctype_digit($eventLimit))) {
            jsonResponse(422, ['error' => 'event_limit must be a non-negative integer']);
            exit;
        }

        $organization = $loadOrganizationById($pdo, $organizationId);
        if ($organization === false) {
            jsonResponse(404, ['error' => 'Organization not found']);
            exit;
        }

        $eventCountStmt = $pdo->prepare('SELECT COUNT(*) AS total FROM events WHERE organization_id = :organization_id AND archived_at IS NULL');
        $eventCountStmt->execute(['organization_id' => $organizationId]);
        $eventCount = (int)($eventCountStmt->fetch()['total'] ?? 0);
        $eventLimitValue = (int)$eventLimit;
        $previousEventLimitValue = (int)$organization['event_limit'];

        if ($eventLimitValue < $eventCount) {
            jsonResponse(422, ['error' => 'event_limit cannot be lower than the current number of events']);
            exit;
        }

        $updateStmt = $pdo->prepare('UPDATE organizations SET event_limit = :event_limit WHERE id = :id');
        $updateStmt->execute([
            'event_limit' => $eventLimitValue,
            'id' => $organizationId,
        ]);

        $addActivityLog(
            $pdo,
            $eventLimitValue > $previousEventLimitValue
                ? sprintf('Zwiększono limit wydarzeń organizacji %s z %d do %d', (string)$organization['name'], $previousEventLimitValue, $eventLimitValue)
                : sprintf('Zmieniono limit wydarzeń organizacji %s z %d do %d', (string)$organization['name'], $previousEventLimitValue, $eventLimitValue),
            null,
            null,
            null,
            (string)$authUser['id'],
            (string)$authUser['name']
        );

        jsonResponse(200, ['data' => $loadOrganizationById($pdo, $organizationId)]);
        exit;
    }

    if ($path === '/users' && $method === 'POST') {
        $authUser = requireAnyRole(['superadmin', 'admin', 'editor'], $resolveAuthenticatedUser);
        $input = readJsonBody();

        $name = trim((string)($input['name'] ?? ''));
        $email = normalizeEmailAddress((string)($input['email'] ?? ''));
        $role = trim((string)($input['role'] ?? ''));
        $organizationId = trim((string)($input['organization_id'] ?? ''));
        $assignedEvents = array_values(array_filter(
            is_array($input['assigned_events'] ?? null) ? $input['assigned_events'] : [],
            static fn(mixed $eventId): bool => is_string($eventId) && trim($eventId) !== ''
        ));

        if ($name === '' || $email === '' || $role === '') {
            jsonResponse(422, ['error' => 'name, email and role are required']);
            exit;
        }

        if (!isValidEmailAddress($email)) {
            jsonResponse(422, ['error' => 'email must be a valid email address']);
            exit;
        }

        $allowedRolesByCreator = [
            'superadmin' => ['admin', 'editor', 'scanner', 'scanner_plus'],
            'admin' => ['editor', 'scanner', 'scanner_plus'],
            'editor' => ['scanner', 'scanner_plus'],
        ];

        if (!in_array($role, $allowedRolesByCreator[(string)$authUser['role']] ?? [], true)) {
            jsonResponse(403, ['error' => 'Forbidden']);
            exit;
        }

        if ($role === 'admin') {
            if ($authUser['role'] !== 'superadmin') {
                jsonResponse(403, ['error' => 'Forbidden']);
                exit;
            }

            $assignedEvents = [];
        } else {
            if ($authUser['role'] === 'superadmin') {
                if ($organizationId === '') {
                    jsonResponse(422, ['error' => 'organization_id is required for this role']);
                    exit;
                }
            } elseif ($authUser['role'] === 'admin') {
                if ($organizationId === '' || !in_array($organizationId, $authUser['organization_ids'] ?? [], true)) {
                    jsonResponse(403, ['error' => 'Forbidden']);
                    exit;
                }
            } else {
                $organizationId = (string)($authUser['organization_id'] ?? '');
            }

            if ($organizationId === '') {
                jsonResponse(422, ['error' => 'organization_id is required for this role']);
                exit;
            }

            if ($loadOrganizationById($pdo, $organizationId) === false) {
                jsonResponse(422, ['error' => 'Organization not found']);
                exit;
            }

            if ($role === 'editor') {
                $assignedEvents = [];
            } else {
                $assignmentValidationError = $validateScannerAssignments($pdo, $organizationId, $assignedEvents);
                if ($assignmentValidationError !== null) {
                    jsonResponse(422, ['error' => $assignmentValidationError]);
                    exit;
                }
            }
        }

        if ($role === 'admin' && $organizationId !== '' && $loadOrganizationById($pdo, $organizationId) === false) {
            jsonResponse(422, ['error' => 'Organization not found']);
            exit;
        }

        $existingUserStmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $existingUserStmt->execute(['email' => $email]);
        if ($existingUserStmt->fetch() !== false) {
            jsonResponse(409, ['error' => 'User with this email already exists']);
            exit;
        }

        $userId = 'u-' . bin2hex(random_bytes(8));
        $passwordHash = password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT);

        $pdo->beginTransaction();

        try {
            $insertUserStmt = $pdo->prepare('
                INSERT INTO users (id, name, email, password, role, organization_id)
                VALUES (:id, :name, :email, :password, :role, :organization_id)
            ');
            $insertUserStmt->execute([
                'id' => $userId,
                'name' => $name,
                'email' => $email,
                'password' => $passwordHash,
                'role' => $role,
                'organization_id' => $role === 'admin' ? null : $organizationId,
            ]);

            if ($role === 'admin' && $organizationId !== '') {
                $assignmentStmt = $pdo->prepare('
                    INSERT INTO admin_organization_assignments (user_id, organization_id)
                    VALUES (:user_id, :organization_id)
                ');
                $assignmentStmt->execute([
                    'user_id' => $userId,
                    'organization_id' => $organizationId,
                ]);
            }

            if ($isScannerRole($role) && $assignedEvents !== []) {
                $assignmentStmt = $pdo->prepare('
                    INSERT INTO user_event_assignments (user_id, event_id)
                    VALUES (:user_id, :event_id)
                ');

                foreach ($assignedEvents as $eventId) {
                    $assignmentStmt->execute([
                        'user_id' => $userId,
                        'event_id' => $eventId,
                    ]);
                    $addActivityLog(
                        $pdo,
                        sprintf('Przypisano skanera %s do wydarzenia', $name),
                        $eventId,
                        null,
                        null,
                        (string)$authUser['id'],
                        (string)$authUser['name']
                    );
                }
            }

            $activityStmt = $pdo->prepare('
                INSERT INTO activity_logs (
                    id,
                    action,
                    event_id,
                    participant_id,
                    participant_name_snapshot,
                    user_id,
                    user_name_snapshot
                ) VALUES (
                    :id,
                    :action,
                    NULL,
                    NULL,
                    NULL,
                    :user_id,
                    :user_name_snapshot
                )
            ');
            $activityStmt->execute([
                'id' => 'log-' . bin2hex(random_bytes(8)),
                'action' => sprintf('Dodano uzytkownika: %s', $name),
                'user_id' => $authUser['id'],
                'user_name_snapshot' => $authUser['name'],
            ]);

            $setupToken = $createPasswordResetToken($pdo, $userId);
            $setupUrl = appFrontendUrl() . '/reset-password?token=' . urlencode($setupToken);
            MailService::sendAccountSetupEmail($email, $name, $setupUrl);

            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }

        jsonResponse(201, ['data' => $loadUserById($pdo, $userId)]);
        exit;
    }

    if (preg_match('#^/users/([^/]+)/event-assignments$#', $path, $matches) === 1 && $method === 'PATCH') {
        $authUser = requireAnyRole(['superadmin', 'admin', 'editor'], $resolveAuthenticatedUser);
        $userId = (string)$matches[1];
        $input = readJsonBody();
        $assignedEvents = array_values(array_filter(
            is_array($input['assigned_events'] ?? null) ? $input['assigned_events'] : [],
            static fn(mixed $eventId): bool => is_string($eventId) && trim($eventId) !== ''
        ));

        $targetUser = $loadUserById($pdo, $userId);
        if ($targetUser === false) {
            jsonResponse(404, ['error' => 'User not found']);
            exit;
        }

        if (!$isScannerRole((string)($targetUser['role'] ?? ''))) {
            jsonResponse(422, ['error' => 'Only scanners can have event assignments']);
            exit;
        }

        if (!$canManageTargetUser($authUser, $targetUser)) {
            jsonResponse(403, ['error' => 'Forbidden']);
            exit;
        }

        $organizationId = (string)($targetUser['organization_id'] ?? '');
        if ($organizationId === '') {
            jsonResponse(422, ['error' => 'Scanner must belong to an organization']);
            exit;
        }

        $assignmentValidationError = $validateScannerAssignments($pdo, $organizationId, $assignedEvents);
        if ($assignmentValidationError !== null) {
            jsonResponse(422, ['error' => $assignmentValidationError]);
            exit;
        }

        $pdo->beginTransaction();

        try {
            $previousAssignedEvents = array_values(array_filter(
                is_array($targetUser['assigned_events'] ?? null) ? $targetUser['assigned_events'] : [],
                static fn(mixed $eventId): bool => is_string($eventId) && trim($eventId) !== ''
            ));
            $deleteStmt = $pdo->prepare('DELETE FROM user_event_assignments WHERE user_id = :user_id');
            $deleteStmt->execute(['user_id' => $userId]);

            if ($assignedEvents !== []) {
                $insertStmt = $pdo->prepare('
                    INSERT INTO user_event_assignments (user_id, event_id)
                    VALUES (:user_id, :event_id)
                ');

                foreach ($assignedEvents as $eventId) {
                    $insertStmt->execute([
                        'user_id' => $userId,
                        'event_id' => $eventId,
                    ]);
                }
            }

            $addedEventIds = array_values(array_diff($assignedEvents, $previousAssignedEvents));
            $removedEventIds = array_values(array_diff($previousAssignedEvents, $assignedEvents));

            foreach ($addedEventIds as $eventId) {
                $addActivityLog(
                    $pdo,
                    sprintf('Przypisano skanera %s do wydarzenia', (string)$targetUser['name']),
                    $eventId,
                    null,
                    null,
                    (string)$authUser['id'],
                    (string)$authUser['name']
                );
            }

            foreach ($removedEventIds as $eventId) {
                $addActivityLog(
                    $pdo,
                    sprintf('Usunieto skanera %s z wydarzenia', (string)$targetUser['name']),
                    $eventId,
                    null,
                    null,
                    (string)$authUser['id'],
                    (string)$authUser['name']
                );
            }

            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }

        jsonResponse(200, ['data' => $loadUserById($pdo, $userId)]);
        exit;
    }

    if (preg_match('#^/users/([^/]+)/role$#', $path, $matches) === 1 && $method === 'PATCH') {
        $authUser = requireAnyRole(['superadmin', 'admin', 'editor'], $resolveAuthenticatedUser);
        $userId = (string)$matches[1];
        $input = readJsonBody();
        $role = trim((string)($input['role'] ?? ''));

        if ($role === '') {
            jsonResponse(422, ['error' => 'role is required']);
            exit;
        }

        $targetUser = $loadUserById($pdo, $userId);
        if ($targetUser === false) {
            jsonResponse(404, ['error' => 'User not found']);
            exit;
        }

        $allowedRolesByCreator = [
            'superadmin' => ['admin', 'editor', 'scanner', 'scanner_plus'],
            'admin' => ['editor', 'scanner', 'scanner_plus'],
            'editor' => ['scanner', 'scanner_plus'],
        ];

        if (!in_array($role, $allowedRolesByCreator[(string)$authUser['role']] ?? [], true)) {
            jsonResponse(403, ['error' => 'Forbidden']);
            exit;
        }

        if (!$canManageTargetUser($authUser, $targetUser)) {
            jsonResponse(403, ['error' => 'Forbidden']);
            exit;
        }

        if (($targetUser['role'] ?? '') === 'admin' || $role === 'admin') {
            jsonResponse(422, ['error' => 'Changing admin roles is not supported by this endpoint']);
            exit;
        }

        if (($targetUser['organization_id'] ?? null) === null) {
            jsonResponse(422, ['error' => 'Target user must belong to an organization']);
            exit;
        }

        $pdo->beginTransaction();

        try {
            $updateStmt = $pdo->prepare('UPDATE users SET role = :role WHERE id = :id');
            $updateStmt->execute([
                'role' => $role,
                'id' => $userId,
            ]);

            if ($role === 'editor') {
                $deleteAssignmentsStmt = $pdo->prepare('DELETE FROM user_event_assignments WHERE user_id = :user_id');
                $deleteAssignmentsStmt->execute(['user_id' => $userId]);
            }

            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }

        jsonResponse(200, ['data' => $loadUserById($pdo, $userId)]);
        exit;
    }

    if (preg_match('#^/users/([^/]+)/password-reset$#', $path, $matches) === 1 && $method === 'POST') {
        $authUser = requireAnyRole(['superadmin', 'admin'], $resolveAuthenticatedUser);
        $userId = (string)$matches[1];

        $targetUser = $loadUserById($pdo, $userId);
        if ($targetUser === false) {
            jsonResponse(404, ['error' => 'User not found']);
            exit;
        }

        if (!$canManageTargetUser($authUser, $targetUser)) {
            jsonResponse(403, ['error' => 'Forbidden']);
            exit;
        }

        if (!in_array((string)($targetUser['role'] ?? ''), ['editor', 'scanner', 'scanner_plus'], true)) {
            jsonResponse(422, ['error' => 'Only organizer and scanner accounts can receive an admin-triggered password reset']);
            exit;
        }

        try {
            $token = $createPasswordResetToken($pdo, (string)$targetUser['id']);
            $resetUrl = appFrontendUrl() . '/reset-password?token=' . urlencode($token);
            MailService::sendPasswordResetEmail((string)$targetUser['email'], (string)$targetUser['name'], $resetUrl);

            $addActivityLog(
                $pdo,
                sprintf('Wyslano reset hasla dla uzytkownika: %s', (string)$targetUser['name']),
                null,
                null,
                null,
                (string)$authUser['id'],
                (string)$authUser['name']
            );
        } catch (Throwable $exception) {
            if ((getenv('APP_DEBUG') ?: 'false') === 'true') {
                jsonResponse(500, ['error' => $exception->getMessage()]);
                exit;
            }

            jsonResponse(500, ['error' => 'Could not send password reset email']);
            exit;
        }

        jsonResponse(200, ['success' => true]);
        exit;
    }

    if (preg_match('#^/users/([^/]+)$#', $path, $matches) === 1 && $method === 'DELETE') {
        $authUser = requireAnyRole(['superadmin', 'admin'], $resolveAuthenticatedUser);
        $userId = (string)$matches[1];

        $targetUser = $loadUserById($pdo, $userId);
        if ($targetUser === false) {
            jsonResponse(404, ['error' => 'User not found']);
            exit;
        }

        if (!$canManageTargetUser($authUser, $targetUser)) {
            jsonResponse(403, ['error' => 'Forbidden']);
            exit;
        }

        if (!in_array((string)($targetUser['role'] ?? ''), ['editor', 'scanner', 'scanner_plus'], true)) {
            jsonResponse(422, ['error' => 'Only organizer and scanner accounts can be archived']);
            exit;
        }

        $archivedEmail = (string)$targetUser['email'];
        $archivedAlias = sprintf(
            'archived+%s+%s@biurozawodow.local',
            preg_replace('/[^a-zA-Z0-9_-]+/', '-', $userId) ?: 'user',
            bin2hex(random_bytes(6))
        );

        $pdo->beginTransaction();

        try {
            $archiveStmt = $pdo->prepare('
                UPDATE users
                SET email = :email,
                    archived_email = :archived_email,
                    archived_at = UTC_TIMESTAMP()
                WHERE id = :id
            ');
            $archiveStmt->execute([
                'email' => $archivedAlias,
                'archived_email' => $archivedEmail,
                'id' => $userId,
            ]);

            $deleteAssignmentsStmt = $pdo->prepare('DELETE FROM user_event_assignments WHERE user_id = :user_id');
            $deleteAssignmentsStmt->execute(['user_id' => $userId]);

            $deleteAdminAssignmentsStmt = $pdo->prepare('DELETE FROM admin_organization_assignments WHERE user_id = :user_id');
            $deleteAdminAssignmentsStmt->execute(['user_id' => $userId]);

            $invalidatePasswordResetTokensForUser($pdo, $userId);

            $addActivityLog(
                $pdo,
                sprintf('Zarchiwizowano konto uzytkownika: %s', (string)$targetUser['name']),
                null,
                null,
                null,
                (string)$authUser['id'],
                (string)$authUser['name']
            );

            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }

        jsonResponse(200, ['success' => true]);
        exit;
    }

    if (preg_match('#^/participants/(\d+)/qr-preview$#', $path, $matches) === 1 && $method === 'GET') {
        $authUser = requireAnyRole(['superadmin', 'admin', 'editor'], $resolveAuthenticatedUser);
        $participant = $loadParticipantById($pdo, (int)$matches[1]);

        if ($participant === false) {
            jsonResponse(404, ['error' => 'Participant not found']);
            exit;
        }

        [$event, $accessError] = $assertParticipantEventAccess($pdo, $authUser, $participant);
        if ($accessError !== null || $event === null || !$canManageEventParticipants($authUser, $event)) {
            jsonResponse($accessError === 'Forbidden' ? 403 : 422, ['error' => $accessError ?? 'Forbidden']);
            exit;
        }

        jsonResponse(200, [
            'data' => [
                'participant' => $participant,
                'event' => $event,
                'qr_code_svg_data_uri' => QrCodeService::renderSvgDataUri((string)$participant['qr_code'], 320, 10),
                'qr_code_image_url' => qrCodeImageUrl((string)$participant['qr_code']),
            ],
        ]);
        exit;
    }

    if (preg_match('#^/participants/(\d+)/send-qr-email$#', $path, $matches) === 1 && $method === 'POST') {
        $authUser = requireAnyRole(['superadmin', 'admin', 'editor'], $resolveAuthenticatedUser);
        $participant = $loadParticipantById($pdo, (int)$matches[1]);

        if ($participant === false) {
            jsonResponse(404, ['error' => 'Participant not found']);
            exit;
        }

        [$event, $accessError] = $assertParticipantEventAccess($pdo, $authUser, $participant);
        if ($accessError !== null || $event === null || !$canManageEventParticipants($authUser, $event)) {
            jsonResponse($accessError === 'Forbidden' ? 403 : 422, ['error' => $accessError ?? 'Forbidden']);
            exit;
        }

        try {
            MailService::sendParticipantQrEmail(
                recipientEmail: (string)$participant['email'],
                recipientName: (string)$participant['display_name'],
                eventName: (string)$event['name'],
                eventOfficeWindow: $formatEventOfficeWindow($event),
                eventLocation: (string)$event['location'],
                bibNumber: (string)($participant['bib_number'] ?? ''),
                qrToken: (string)$participant['qr_code']
            );

            $updateStmt = $pdo->prepare('UPDATE participants SET email_status = :email_status WHERE id = :id');
            $updateStmt->execute([
                'email_status' => 'sent',
                'id' => (int)$participant['id'],
            ]);
            $participant['email_status'] = 'sent';

            $addActivityLog(
                $pdo,
                'Ponownie wyslano kod QR',
                (string)$event['id'],
                (int)$participant['id'],
                (string)$participant['display_name'],
                (string)$authUser['id'],
                (string)$authUser['name']
            );

            jsonResponse(200, ['data' => $participant]);
        } catch (Throwable $exception) {
            jsonResponse(422, ['error' => $exception->getMessage()]);
        }
        exit;
    }

    if (preg_match('#^/events/([^/]+)/send-qr-emails$#', $path, $matches) === 1 && $method === 'POST') {
        $authUser = requireAnyRole(['superadmin', 'admin', 'editor'], $resolveAuthenticatedUser);
        $eventId = (string)$matches[1];
        $event = $loadEventById($pdo, $eventId);

        if ($event === false) {
            jsonResponse(404, ['error' => 'Event not found']);
            exit;
        }

        if (!$canManageEventParticipants($authUser, $event)) {
            jsonResponse(403, ['error' => 'Forbidden']);
            exit;
        }

        $input = readJsonBody();
        $resendAll = (bool)($input['resend_all'] ?? false);

        $stmt = $pdo->prepare('
            SELECT
                id,
                event_id,
                first_name,
                last_name,
                display_name,
                email,
                organization,
                bib_number,
                qr_code,
                custom_fields_json,
                status,
                email_status,
                checked_in_at,
                created_at,
                updated_at
            FROM participants
            WHERE event_id = :event_id
            ORDER BY id ASC
        ');
        $stmt->execute(['event_id' => $eventId]);
        $participants = $stmt->fetchAll();

        $sentCount = 0;
        $errorCount = 0;
        $errors = [];
        $updatedParticipantIds = [];

        foreach ($participants as $participantRow) {
            $participantRow['custom_fields'] = decodeJsonObject($participantRow['custom_fields_json'] ?? null);
            unset($participantRow['custom_fields_json']);
            $participantRow = $ensureParticipantQrCode($pdo, $participantRow);

            if (!$resendAll && (string)$participantRow['email_status'] === 'sent') {
                continue;
            }

            try {
                MailService::sendParticipantQrEmail(
                    recipientEmail: (string)$participantRow['email'],
                    recipientName: (string)$participantRow['display_name'],
                    eventName: (string)$event['name'],
                    eventOfficeWindow: $formatEventOfficeWindow($event),
                    eventLocation: (string)$event['location'],
                    bibNumber: (string)($participantRow['bib_number'] ?? ''),
                    qrToken: (string)$participantRow['qr_code']
                );

                $updatedParticipantIds[] = (int)$participantRow['id'];
                $sentCount++;
            } catch (Throwable $exception) {
                $errorCount++;
                $errors[] = [
                    'participant_id' => (int)$participantRow['id'],
                    'participant_name' => (string)$participantRow['display_name'],
                    'error' => $exception->getMessage(),
                ];
            }
        }

        if ($updatedParticipantIds !== []) {
            $placeholders = implode(',', array_fill(0, count($updatedParticipantIds), '?'));
            $updateStmt = $pdo->prepare("UPDATE participants SET email_status = 'sent' WHERE id IN ({$placeholders})");
            $updateStmt->execute($updatedParticipantIds);
            $addActivityLog(
                $pdo,
                $resendAll ? 'Wyslano ponownie kody QR dla wydarzenia' : 'Wyslano kody QR dla wydarzenia',
                $eventId,
                null,
                null,
                (string)$authUser['id'],
                (string)$authUser['name']
            );
        }

        jsonResponse(200, [
            'data' => [
                'sent_count' => $sentCount,
                'error_count' => $errorCount,
                'errors' => $errors,
            ],
        ]);
        exit;
    }

    if ($path === '/participants/scan' && $method === 'POST') {
        $authUser = requireAuth($resolveAuthenticatedUser);
        $input = readJsonBody();
        $qrCode = trim((string)($input['qr_code'] ?? ''));

        if ($qrCode === '') {
            jsonResponse(422, ['error' => 'qr_code is required']);
            exit;
        }

        $participant = $loadParticipantByQrCode($pdo, $qrCode);
        if ($participant === false) {
            jsonResponse(404, ['error' => 'Participant not found']);
            exit;
        }

        $eventId = trim((string)($participant['event_id'] ?? ''));
        if ($eventId === '') {
            jsonResponse(422, ['error' => 'Participant is not assigned to an event']);
            exit;
        }

        $event = $loadEventById($pdo, $eventId);
        if ($event === false) {
            jsonResponse(404, ['error' => 'Event not found']);
            exit;
        }

        if (!$canAccessEvent($authUser, $event)) {
            jsonResponse(403, [
                'error' => 'QR belongs to an event outside your permissions',
                'data' => $serializeParticipantScanResponse($participant, $event, false),
            ]);
            exit;
        }

        $addActivityLog(
            $pdo,
            'Skan QR',
            (string)$event['id'],
            (int)$participant['id'],
            (string)$participant['display_name'],
            (string)$authUser['id'],
            (string)$authUser['name']
        );

        jsonResponse(200, ['data' => $serializeParticipantScanResponse($participant, $event, true)]);
        exit;
    }

    if (preg_match('#^/participants/(\d+)$#', $path, $matches) === 1 && $method === 'PATCH') {
        $authUser = requireAnyRole(['superadmin', 'admin', 'editor', 'scanner_plus'], $resolveAuthenticatedUser);
        $participant = $loadParticipantById($pdo, (int)$matches[1]);

        if ($participant === false) {
            jsonResponse(404, ['error' => 'Participant not found']);
            exit;
        }

        [$event, $accessError] = $assertParticipantEventAccess($pdo, $authUser, $participant);
        if ($accessError !== null || $event === null || !$canManageEventParticipants($authUser, $event)) {
            jsonResponse($accessError === 'Forbidden' ? 403 : 422, ['error' => $accessError ?? 'Forbidden']);
            exit;
        }

        $input = readJsonBody();
        $nextStatus = trim((string)($input['status'] ?? ''));
        $email = array_key_exists('email', $input) ? normalizeEmailAddress((string)$input['email']) : null;
        $fieldValues = is_array($input['field_values'] ?? null) ? $input['field_values'] : null;

        if ($nextStatus === '' && $email === null && $fieldValues === null) {
            jsonResponse(422, ['error' => 'At least one participant field must be provided']);
            exit;
        }

        if ($nextStatus !== '') {
            if (!$isValidParticipantStatus($nextStatus)) {
                jsonResponse(422, ['error' => 'Unsupported participant status']);
                exit;
            }

            if ($nextStatus !== (string)$participant['status']) {
                $participant = $updateParticipantStatus($pdo, $participant, $nextStatus);
                $addActivityLog(
                    $pdo,
                    sprintf('Zmieniono status uczestnika na %s', $nextStatus),
                    (string)$participant['event_id'],
                    (int)$participant['id'],
                    (string)$participant['display_name'],
                    (string)$authUser['id'],
                    (string)$authUser['name']
                );
            }
        }

        if ($email !== null || $fieldValues !== null) {
            $mappings = $loadParticipantFieldMappings($pdo, (string)$participant['event_id']);
            if ($mappings === []) {
                jsonResponse(422, ['error' => 'Participant reassignment requires saved field mapping for the event']);
                exit;
            }

            $resolvedEmail = $email ?? normalizeEmailAddress((string)$participant['email']);
            if ($resolvedEmail === '' || !isValidEmailAddress($resolvedEmail)) {
                jsonResponse(422, ['error' => 'A valid email is required']);
                exit;
            }

            $participantFieldValues = $fieldValues ?? ($participant['custom_fields'] ?? []);
            $resolvedData = $buildParticipantDataFromMappings(
                $mappings,
                is_array($participantFieldValues) ? $participantFieldValues : [],
                true,
                (string)($participant['bib_number'] ?? '')
            );

            if ($resolvedData['missing_fields'] !== []) {
                jsonResponse(422, ['error' => 'All participant columns are required for package reassignment', 'missing_fields' => $resolvedData['missing_fields']]);
                exit;
            }

            if ($resolvedData['display_name'] === '') {
                jsonResponse(422, ['error' => 'At least one display name field is required']);
                exit;
            }

            $nameParts = splitParticipantDisplayName($resolvedData['display_name']);
            $emailChanged = $resolvedEmail !== normalizeEmailAddress((string)$participant['email']);
            $updateStmt = $pdo->prepare('
                UPDATE participants
                SET
                    first_name = :first_name,
                    last_name = :last_name,
                    display_name = :display_name,
                    email = :email,
                    custom_fields_json = :custom_fields_json,
                    email_status = :email_status
                WHERE id = :id
            ');
            $updateStmt->execute([
                'first_name' => $nameParts['first_name'],
                'last_name' => $nameParts['last_name'],
                'display_name' => $resolvedData['display_name'],
                'email' => $resolvedEmail,
                'custom_fields_json' => $resolvedData['custom_fields'] !== [] ? json_encode($resolvedData['custom_fields'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                'email_status' => $emailChanged ? 'not_sent' : (string)$participant['email_status'],
                'id' => (int)$participant['id'],
            ]);

            $participant = $loadParticipantById($pdo, (int)$participant['id']);
            $addActivityLog(
                $pdo,
                'Przepisano pakiet na inna osobe',
                (string)$participant['event_id'],
                (int)$participant['id'],
                (string)$participant['display_name'],
                (string)$authUser['id'],
                (string)$authUser['name']
            );
        }

        jsonResponse(200, ['data' => $participant]);
        exit;
    }

    if (preg_match('#^/participants/(\d+)/check-in$#', $path, $matches) === 1 && $method === 'POST') {
        $authUser = requireAuth($resolveAuthenticatedUser);
        $participant = $loadParticipantById($pdo, (int)$matches[1]);

        if ($participant === false) {
            jsonResponse(404, ['error' => 'Participant not found']);
            exit;
        }

        [$event, $accessError] = $assertParticipantEventAccess($pdo, $authUser, $participant);
        if ($accessError !== null || $event === null) {
            jsonResponse($accessError === 'Forbidden' ? 403 : 422, ['error' => $accessError ?? 'Forbidden']);
            exit;
        }

        $participant = $updateParticipantStatus($pdo, $participant, 'checked_in');
        $addActivityLog(
            $pdo,
            'Check-in',
            (string)$event['id'],
            (int)$participant['id'],
            (string)$participant['display_name'],
            (string)$authUser['id'],
            (string)$authUser['name']
        );

        jsonResponse(200, ['data' => $participant]);
        exit;
    }

    if (preg_match('#^/participants/(\d+)/undo-check-in$#', $path, $matches) === 1 && $method === 'POST') {
        $authUser = requireAuth($resolveAuthenticatedUser);
        $participant = $loadParticipantById($pdo, (int)$matches[1]);

        if ($participant === false) {
            jsonResponse(404, ['error' => 'Participant not found']);
            exit;
        }

        [$event, $accessError] = $assertParticipantEventAccess($pdo, $authUser, $participant);
        if ($accessError !== null || $event === null) {
            jsonResponse($accessError === 'Forbidden' ? 403 : 422, ['error' => $accessError ?? 'Forbidden']);
            exit;
        }

        $participant = $updateParticipantStatus($pdo, $participant, 'not_checked_in');
        $addActivityLog(
            $pdo,
            'Cofnieto odprawe',
            (string)$event['id'],
            (int)$participant['id'],
            (string)$participant['display_name'],
            (string)$authUser['id'],
            (string)$authUser['name']
        );

        jsonResponse(200, ['data' => $participant]);
        exit;
    }

    if ($path === '/participants' && $method === 'GET') {
        $authUser = requireAuth($resolveAuthenticatedUser);
        $accessibleEventIds = array_map(
            static fn(array $event): string => (string)$event['id'],
            $loadAccessibleEvents($pdo, $authUser)
        );
        $participants = $loadParticipantsByEventIds($pdo, $accessibleEventIds);

        jsonResponse(200, ['data' => $participants]);
        exit;
    }

    if ($path === '/participants' && $method === 'POST') {
        $authUser = requireAnyRole(['superadmin', 'admin', 'editor'], $resolveAuthenticatedUser);

        $input = readJsonBody();
        $firstName = trim((string)($input['first_name'] ?? ''));
        $lastName = trim((string)($input['last_name'] ?? ''));
        $email = normalizeEmailAddress((string)($input['email'] ?? ''));
        $organization = trim((string)($input['organization'] ?? ''));
        $eventId = trim((string)($input['event_id'] ?? ''));
        $bibNumber = trim((string)($input['bib_number'] ?? ''));
        $qrCode = trim((string)($input['qr_code'] ?? ''));
        $displayName = trim((string)($input['display_name'] ?? trim($firstName . ' ' . $lastName)));

        if ($eventId === '' || $displayName === '' || $email === '') {
            jsonResponse(422, ['error' => 'event_id, display_name and email are required']);
            exit;
        }

        if (!isValidEmailAddress($email)) {
            jsonResponse(422, ['error' => 'email must be a valid email address']);
            exit;
        }

        $event = $loadEventById($pdo, $eventId);
        if ($event === false) {
            jsonResponse(422, ['error' => 'Event not found']);
            exit;
        }

        if (!$canManageEventParticipants($authUser, $event)) {
            jsonResponse(403, ['error' => 'Forbidden']);
            exit;
        }

        $participant = $insertParticipantRecord(
            $pdo,
            $eventId,
            $displayName,
            $email,
            $bibNumber === '' ? null : $bibNumber,
            [],
            $organization,
            $qrCode === '' ? null : $qrCode
        );

        $addActivityLog(
            $pdo,
            'Dodano uczestnika',
            $eventId,
            (int)($participant['id'] ?? 0),
            (string)$displayName,
            (string)$authUser['id'],
            (string)$authUser['name']
        );

        jsonResponse(201, ['data' => $participant]);
        exit;
    }

    if (preg_match('#^/participants/(\d+)$#', $path, $matches) === 1 && $method === 'GET') {
        $authUser = requireAuth($resolveAuthenticatedUser);

        $participant = $loadParticipantById($pdo, (int)$matches[1]);

        if ($participant === false) {
            jsonResponse(404, ['error' => 'Participant not found']);
            exit;
        }

        [$event, $accessError] = $assertParticipantEventAccess($pdo, $authUser, $participant);
        if ($accessError !== null || $event === null) {
            jsonResponse($accessError === 'Forbidden' ? 403 : 422, ['error' => $accessError ?? 'Forbidden']);
            exit;
        }

        jsonResponse(200, ['data' => $participant]);
        exit;
    }

    if (preg_match('#^/participants/(\d+)$#', $path, $matches) === 1 && $method === 'DELETE') {
        $authUser = requireAnyRole(['superadmin', 'admin', 'editor'], $resolveAuthenticatedUser);
        $participant = $loadParticipantById($pdo, (int)$matches[1]);

        if ($participant === false) {
            jsonResponse(404, ['error' => 'Participant not found']);
            exit;
        }

        [$event, $accessError] = $assertParticipantEventAccess($pdo, $authUser, $participant);
        if ($accessError !== null || $event === null || !$canManageEventParticipants($authUser, $event)) {
            jsonResponse($accessError === 'Forbidden' ? 403 : 422, ['error' => $accessError ?? 'Forbidden']);
            exit;
        }

        $pdo->beginTransaction();

        try {
            $addActivityLog(
                $pdo,
                'Usunięto uczestnika',
                (string)$event['id'],
                (int)$participant['id'],
                (string)$participant['display_name'],
                (string)$authUser['id'],
                (string)$authUser['name']
            );

            $deleteStmt = $pdo->prepare('DELETE FROM participants WHERE id = :id');
            $deleteStmt->execute(['id' => (int)$participant['id']]);

            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }

        jsonResponse(200, ['success' => true]);
        exit;
    }

    jsonResponse(404, ['error' => 'Route not found']);
} catch (PDOException $exception) {
    if ($exception->getCode() === '23000') {
        jsonResponse(409, [
            'error' => 'Conflict',
            'details' => getenv('APP_DEBUG') === 'true' ? $exception->getMessage() : null,
        ]);
        exit;
    }

    jsonResponse(500, [
        'error' => 'Database error',
        'details' => getenv('APP_DEBUG') === 'true' ? $exception->getMessage() : null,
    ]);
} catch (Throwable $exception) {
    jsonResponse(500, [
        'error' => 'Server error',
        'details' => getenv('APP_DEBUG') === 'true' ? $exception->getMessage() : null,
    ]);
}
