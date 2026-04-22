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

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS event_sync_state (
            event_id VARCHAR(64) NOT NULL,
            sync_mode ENUM('cloud', 'local_authoritative') NOT NULL DEFAULT 'cloud',
            source_node_id VARCHAR(128) NULL,
            sync_status ENUM('idle', 'pending', 'syncing', 'conflict') NOT NULL DEFAULT 'idle',
            conflicts_count INT UNSIGNED NOT NULL DEFAULT 0,
            last_exported_at DATETIME NULL,
            last_synced_at DATETIME NULL,
            last_report_json LONGTEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (event_id),
            KEY idx_event_sync_state_sync_mode (sync_mode),
            CONSTRAINT fk_event_sync_state_event
                FOREIGN KEY (event_id) REFERENCES events(id)
                ON DELETE CASCADE
                ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS client_mutations (
            id VARCHAR(64) NOT NULL,
            mutation_type VARCHAR(64) NOT NULL DEFAULT 'participant_status',
            participant_id BIGINT UNSIGNED NOT NULL,
            event_id VARCHAR(64) NOT NULL,
            device_id VARCHAR(128) NULL,
            source_node_id VARCHAR(128) NULL,
            user_id VARCHAR(64) NULL,
            base_status ENUM('not_checked_in', 'checked_in', 'checked_in_not_starting') NOT NULL,
            requested_status ENUM('not_checked_in', 'checked_in', 'checked_in_not_starting') NOT NULL,
            applied_status ENUM('not_checked_in', 'checked_in', 'checked_in_not_starting') NOT NULL,
            response_json LONGTEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_client_mutations_event_id (event_id),
            KEY idx_client_mutations_participant_id (participant_id),
            KEY idx_client_mutations_user_id (user_id),
            CONSTRAINT fk_client_mutations_event
                FOREIGN KEY (event_id) REFERENCES events(id)
                ON DELETE CASCADE
                ON UPDATE CASCADE,
            CONSTRAINT fk_client_mutations_participant
                FOREIGN KEY (participant_id) REFERENCES participants(id)
                ON DELETE CASCADE
                ON UPDATE CASCADE,
            CONSTRAINT fk_client_mutations_user
                FOREIGN KEY (user_id) REFERENCES users(id)
                ON DELETE SET NULL
                ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sync_outbox (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            client_mutation_id VARCHAR(64) NULL,
            event_id VARCHAR(64) NOT NULL,
            source_node_id VARCHAR(128) NULL,
            entity_type VARCHAR(64) NOT NULL,
            entity_id VARCHAR(64) NOT NULL,
            action VARCHAR(64) NOT NULL,
            payload_json LONGTEXT NOT NULL,
            status ENUM('pending', 'sent', 'applied', 'conflict') NOT NULL DEFAULT 'pending',
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            last_error VARCHAR(255) NULL,
            sent_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_sync_outbox_event_id (event_id),
            KEY idx_sync_outbox_status (status),
            KEY idx_sync_outbox_client_mutation_id (client_mutation_id),
            CONSTRAINT fk_sync_outbox_event
                FOREIGN KEY (event_id) REFERENCES events(id)
                ON DELETE CASCADE
                ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $currentLocalDateTime = static fn(): string => (new DateTimeImmutable())->format('Y-m-d H:i:s');
    $currentLocalDateTimeMinutePrecision = static function (): string {
        $now = new DateTimeImmutable();
        return $now->setTime(
            (int)$now->format('H'),
            (int)$now->format('i'),
            0
        )->format('Y-m-d H:i:s');
    };
    $validateEventOfficeWindow = static function (string $normalizedOfficeOpenAt, string $normalizedOfficeCloseAt, bool $allowPastOpenAt = false) use ($currentLocalDateTimeMinutePrecision): ?string {
        $openAt = parseLocalDateTimeString($normalizedOfficeOpenAt);
        $closeAt = parseLocalDateTimeString($normalizedOfficeCloseAt);
        $now = parseLocalDateTimeString($currentLocalDateTimeMinutePrecision());

        if ($openAt === null || $closeAt === null || $now === null) {
            return 'office_open_at i office_close_at muszą być prawidłowymi lokalnymi datami i godzinami';
        }

        if (!$allowPastOpenAt && $openAt < $now) {
            return 'Godzina otwarcia biura nie może być w przeszłości';
        }

        if (($closeAt->getTimestamp() - $openAt->getTimestamp()) < 3600) {
            return 'Biuro zawodów musi być otwarte przez minimum 1 godzinę';
        }

        return null;
    };

    $loadAssignedEvents = static function (PDO $pdo, string $userId) use ($currentLocalDateTime): array {
        $stmt = $pdo->prepare('
            SELECT uea.event_id
            FROM user_event_assignments uea
            INNER JOIN events e ON e.id = uea.event_id
            WHERE uea.user_id = :user_id
              AND e.archived_at IS NULL
              AND e.office_close_at > :current_time
            ORDER BY uea.event_id ASC
        ');
        $stmt->execute([
            'user_id' => $userId,
            'current_time' => $currentLocalDateTime(),
        ]);

        return array_map(
            static fn(array $row): string => (string)$row['event_id'],
            $stmt->fetchAll()
        );
    };

    $normalizeStringIdList = static function (array $values): array {
        $normalizedValues = [];

        foreach ($values as $value) {
            if (!is_string($value)) {
                continue;
            }

            $trimmedValue = trim($value);
            if ($trimmedValue === '') {
                continue;
            }

            $normalizedValues[] = $trimmedValue;
        }

        return array_values(array_unique($normalizedValues));
    };

    $loadOrganizationById = static function (PDO $pdo, string $organizationId): array|false {
        $stmt = $pdo->prepare('
            SELECT
                o.id,
                o.name,
                o.logo,
                o.event_limit
            FROM organizations o
            WHERE o.id = :id
            LIMIT 1
        ');
        $stmt->execute(['id' => $organizationId]);
        $organization = $stmt->fetch();
        if ($organization === false) {
            return false;
        }

        return $organization;
    };

    $loadAllOrganizations = static function (PDO $pdo): array {
        return $pdo->query('
            SELECT
                o.id,
                o.name,
                o.logo,
                o.event_limit
            FROM organizations o
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

    $loadUserById = static function (PDO $pdo, string $userId) use ($loadAssignedEvents, $isScannerRole): array|false {
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

        $user['assigned_events'] = $isScannerRole((string)$user['role'])
            ? $loadAssignedEvents($pdo, (string)$user['id'])
            : [];

        return $user;
    };

    $loadAllUsers = static function (PDO $pdo) use ($loadAssignedEvents, $isScannerRole): array {
        $users = $pdo->query('
            SELECT id, name, email, role, organization_id
            FROM users
            WHERE archived_at IS NULL
            ORDER BY name ASC
        ')->fetchAll();

        foreach ($users as &$user) {
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

    $createPasswordResetToken = static function (PDO $pdo, string $userId, int $ttlSeconds = 3600) use ($invalidatePasswordResetTokensForUser): string {
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
            'expires_at' => passwordResetExpiresAt($ttlSeconds),
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
        if (in_array($authUser['role'], ['superadmin', 'admin'], true)) {
            return true;
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

            return true;
        }

        if ($authUser['role'] === 'editor') {
            return $isScannerRole((string)($targetUser['role'] ?? ''))
                && (string)($targetUser['organization_id'] ?? '') === (string)($authUser['organization_id'] ?? '');
        }

        return false;
    };

    $validateScannerAssignments = static function (PDO $pdo, string $organizationId, array $assignedEvents) use ($currentLocalDateTime): ?string {
        if ($assignedEvents === []) {
            return null;
        }

        $eventStmt = $pdo->prepare('
            SELECT id
            FROM events
            WHERE id = :id
              AND organization_id = :organization_id
              AND archived_at IS NULL
              AND office_close_at > :current_time
            LIMIT 1
        ');

        foreach ($assignedEvents as $eventId) {
            $eventStmt->execute([
                'id' => $eventId,
                'organization_id' => $organizationId,
                'current_time' => $currentLocalDateTime(),
            ]);

            if ($eventStmt->fetch() === false) {
                return 'All assigned_events must belong to the same organization as the scanner and refer to current or upcoming events';
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
        if (in_array($authUser['role'], ['superadmin', 'admin'], true)) {
            return array_values($organizations);
        }

        if ($authUser['role'] === 'editor') {
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
        if (in_array($authUser['role'], ['superadmin', 'admin'], true)) {
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
        return array_values(array_filter(
            $users,
            static function (array $user) use ($authUser, $accessibleOrganizationIds): bool {
                if ((string)$user['id'] === (string)$authUser['id']) {
                    return true;
                }

                return in_array((string)($user['organization_id'] ?? ''), $accessibleOrganizationIds, true);
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

    $buildBootstrapSnapshotVersion = static function (array $payload): string {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return 'snapshot-' . hash('sha256', $encoded === false ? serialize($payload) : $encoded);
    };

    $loadEventSyncState = static function (PDO $pdo, string $eventId): array|false {
        $stmt = $pdo->prepare('
            SELECT
                event_id,
                sync_mode,
                source_node_id,
                sync_status,
                conflicts_count,
                DATE_FORMAT(last_exported_at, "%Y-%m-%dT%H:%i:%s") AS last_exported_at,
                DATE_FORMAT(last_synced_at, "%Y-%m-%dT%H:%i:%s") AS last_synced_at,
                last_report_json
            FROM event_sync_state
            WHERE event_id = :event_id
            LIMIT 1
        ');
        $stmt->execute(['event_id' => $eventId]);

        $state = $stmt->fetch();
        if ($state === false) {
            return false;
        }

        $state['last_report'] = $state['last_report_json']
            ? json_decode((string)$state['last_report_json'], true)
            : null;
        unset($state['last_report_json']);

        return $state;
    };

    $saveEventSyncState = static function (
        PDO $pdo,
        string $eventId,
        array $patch
    ) use ($loadEventSyncState): array {
        $currentState = $loadEventSyncState($pdo, $eventId);
        $nextState = [
            'sync_mode' => (string)($patch['sync_mode'] ?? ($currentState['sync_mode'] ?? 'cloud')),
            'source_node_id' => $patch['source_node_id'] ?? ($currentState['source_node_id'] ?? null),
            'sync_status' => (string)($patch['sync_status'] ?? ($currentState['sync_status'] ?? 'idle')),
            'conflicts_count' => (int)($patch['conflicts_count'] ?? ($currentState['conflicts_count'] ?? 0)),
            'last_exported_at' => $patch['last_exported_at'] ?? ($currentState['last_exported_at'] ?? null),
            'last_synced_at' => $patch['last_synced_at'] ?? ($currentState['last_synced_at'] ?? null),
            'last_report_json' => array_key_exists('last_report', $patch)
                ? json_encode($patch['last_report'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : (($currentState['last_report'] ?? null) !== null
                    ? json_encode($currentState['last_report'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : null),
        ];

        $stmt = $pdo->prepare('
            INSERT INTO event_sync_state (
                event_id,
                sync_mode,
                source_node_id,
                sync_status,
                conflicts_count,
                last_exported_at,
                last_synced_at,
                last_report_json
            ) VALUES (
                :event_id,
                :sync_mode,
                :source_node_id,
                :sync_status,
                :conflicts_count,
                :last_exported_at,
                :last_synced_at,
                :last_report_json
            )
            ON DUPLICATE KEY UPDATE
                sync_mode = VALUES(sync_mode),
                source_node_id = VALUES(source_node_id),
                sync_status = VALUES(sync_status),
                conflicts_count = VALUES(conflicts_count),
                last_exported_at = VALUES(last_exported_at),
                last_synced_at = VALUES(last_synced_at),
                last_report_json = VALUES(last_report_json)
        ');
        $stmt->bindValue(':event_id', $eventId, PDO::PARAM_STR);
        $stmt->bindValue(':sync_mode', $nextState['sync_mode'], PDO::PARAM_STR);
        $stmt->bindValue(':source_node_id', $nextState['source_node_id'], $nextState['source_node_id'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':sync_status', $nextState['sync_status'], PDO::PARAM_STR);
        $stmt->bindValue(':conflicts_count', $nextState['conflicts_count'], PDO::PARAM_INT);
        $stmt->bindValue(':last_exported_at', $nextState['last_exported_at'], $nextState['last_exported_at'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':last_synced_at', $nextState['last_synced_at'], $nextState['last_synced_at'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':last_report_json', $nextState['last_report_json'], $nextState['last_report_json'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->execute();

        return $loadEventSyncState($pdo, $eventId) ?: [];
    };

    $loadClientMutationById = static function (PDO $pdo, string $mutationId): array|false {
        $stmt = $pdo->prepare('
            SELECT id, mutation_type, participant_id, event_id, device_id, source_node_id, user_id, base_status, requested_status, applied_status, response_json
            FROM client_mutations
            WHERE id = :id
            LIMIT 1
        ');
        $stmt->execute(['id' => $mutationId]);

        $mutation = $stmt->fetch();
        if ($mutation === false) {
            return false;
        }

        $mutation['response'] = $mutation['response_json']
            ? json_decode((string)$mutation['response_json'], true)
            : null;
        unset($mutation['response_json']);

        return $mutation;
    };

    $recordClientMutation = static function (
        PDO $pdo,
        string $mutationId,
        int $participantId,
        string $eventId,
        ?string $deviceId,
        ?string $sourceNodeId,
        ?string $userId,
        string $baseStatus,
        string $requestedStatus,
        string $appliedStatus,
        array $response
    ): void {
        $stmt = $pdo->prepare('
            INSERT INTO client_mutations (
                id,
                participant_id,
                event_id,
                device_id,
                source_node_id,
                user_id,
                base_status,
                requested_status,
                applied_status,
                response_json
            ) VALUES (
                :id,
                :participant_id,
                :event_id,
                :device_id,
                :source_node_id,
                :user_id,
                :base_status,
                :requested_status,
                :applied_status,
                :response_json
            )
            ON DUPLICATE KEY UPDATE
                device_id = VALUES(device_id),
                source_node_id = VALUES(source_node_id),
                user_id = VALUES(user_id),
                base_status = VALUES(base_status),
                requested_status = VALUES(requested_status),
                applied_status = VALUES(applied_status),
                response_json = VALUES(response_json)
        ');
        $responseJson = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt->bindValue(':id', $mutationId, PDO::PARAM_STR);
        $stmt->bindValue(':participant_id', $participantId, PDO::PARAM_INT);
        $stmt->bindValue(':event_id', $eventId, PDO::PARAM_STR);
        $stmt->bindValue(':device_id', $deviceId, $deviceId === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':source_node_id', $sourceNodeId, $sourceNodeId === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':user_id', $userId, $userId === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':base_status', $baseStatus, PDO::PARAM_STR);
        $stmt->bindValue(':requested_status', $requestedStatus, PDO::PARAM_STR);
        $stmt->bindValue(':applied_status', $appliedStatus, PDO::PARAM_STR);
        $stmt->bindValue(':response_json', $responseJson === false ? null : $responseJson, $responseJson === false ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->execute();
    };

    $enqueueSyncOutbox = static function (
        PDO $pdo,
        string $eventId,
        string $entityType,
        string $entityId,
        string $action,
        array $payload,
        ?string $clientMutationId = null,
        ?string $sourceNodeId = null
    ): void {
        $stmt = $pdo->prepare('
            INSERT INTO sync_outbox (
                client_mutation_id,
                event_id,
                source_node_id,
                entity_type,
                entity_id,
                action,
                payload_json
            ) VALUES (
                :client_mutation_id,
                :event_id,
                :source_node_id,
                :entity_type,
                :entity_id,
                :action,
                :payload_json
            )
        ');
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt->bindValue(':client_mutation_id', $clientMutationId, $clientMutationId === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':event_id', $eventId, PDO::PARAM_STR);
        $stmt->bindValue(':source_node_id', $sourceNodeId, $sourceNodeId === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':entity_type', $entityType, PDO::PARAM_STR);
        $stmt->bindValue(':entity_id', $entityId, PDO::PARAM_STR);
        $stmt->bindValue(':action', $action, PDO::PARAM_STR);
        $stmt->bindValue(':payload_json', $payloadJson === false ? '{}' : $payloadJson, PDO::PARAM_STR);
        $stmt->execute();
    };

    $loadAssignedUsersForEvent = static function (PDO $pdo, string $eventId): array {
        $stmt = $pdo->prepare('
            SELECT u.id, u.name, u.email, u.role, u.organization_id
            FROM users u
            INNER JOIN user_event_assignments uea ON uea.user_id = u.id
            WHERE uea.event_id = :event_id
              AND u.archived_at IS NULL
            ORDER BY u.name ASC
        ');
        $stmt->execute(['event_id' => $eventId]);

        return $stmt->fetchAll();
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

    $resolveParticipantDisplayName = static function (
        string $displayName,
        string $email,
        string $fallbackDisplayName = '',
        string $previousEmail = ''
    ): string {
        $normalizedDisplayName = trim($displayName);
        if ($normalizedDisplayName !== '') {
            return $normalizedDisplayName;
        }

        $normalizedFallback = trim($fallbackDisplayName);
        $normalizedPreviousEmail = normalizeEmailAddress($previousEmail);
        if ($normalizedFallback !== '' && normalizeEmailAddress($normalizedFallback) !== $normalizedPreviousEmail) {
            return $normalizedFallback;
        }

        return $email;
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
            return [null, 'Uczestnik nie jest przypisany do wydarzenia'];
        }

        $event = $loadEventById($pdo, $eventId);
        if ($event === false) {
            return [null, 'Nie znaleziono wydarzenia'];
        }

        if (!$canAccessEvent($authUser, $event) && !$canManageEventParticipants($authUser, $event)) {
            return [null, 'Brak uprawnień'];
        }

        return [$event, null];
    };

    $updateParticipantStatus = static function (
        PDO $pdo,
        array $participant,
        string $status
    ) use ($loadParticipantById, $isValidParticipantStatus, $participantStatusCountsAsCheckedIn): array {
        if (!$isValidParticipantStatus($status)) {
            throw new InvalidArgumentException('Nieobsługiwany status uczestnika');
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

    $normalizeParticipantBibNumber = static function ($value): ?string {
        $normalized = trim((string)$value);

        return $normalized !== '' ? $normalized : null;
    };

    $participantBibNumberExistsForEvent = static function (
        PDO $pdo,
        string $eventId,
        string $bibNumber,
        int $exceptParticipantId
    ): bool {
        $stmt = $pdo->prepare('
            SELECT id
            FROM participants
            WHERE event_id = :event_id
              AND bib_number = :bib_number
              AND id <> :id
            LIMIT 1
        ');
        $stmt->execute([
            'event_id' => $eventId,
            'bib_number' => $bibNumber,
            'id' => $exceptParticipantId,
        ]);

        return $stmt->fetch() !== false;
    };

    $loadParticipantsByEventAndBibNumber = static function (
        PDO $pdo,
        string $eventId,
        string $bibNumber,
        int $exceptParticipantId = 0
    ) use (&$ensureParticipantQrCode): array {
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
              AND bib_number = :bib_number
              AND id <> :except_id
            ORDER BY display_name ASC, id ASC
        ');
        $stmt->execute([
            'event_id' => $eventId,
            'bib_number' => $bibNumber,
            'except_id' => $exceptParticipantId,
        ]);
        $participants = $stmt->fetchAll();

        foreach ($participants as &$participant) {
            $participant['custom_fields'] = decodeJsonObject($participant['custom_fields_json'] ?? null);
            unset($participant['custom_fields_json']);
            $participant = $ensureParticipantQrCode($pdo, $participant);
        }
        unset($participant);

        return $participants;
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

    $insertParticipantRecord = static function (
        PDO $pdo,
        string $eventId,
        string $displayName,
        string $email,
        ?string $bibNumber,
        array $customFields,
        string $organization = '',
        ?string $qrCode = null
    ) use ($loadParticipantById, $generateUniqueParticipantQrCode): array {
        $nameParts = splitParticipantDisplayName($displayName);
        $resolvedBibNumber = $bibNumber !== null && trim($bibNumber) !== ''
            ? trim($bibNumber)
            : null;
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
            echo 'Nie znaleziono obrazu kodu QR';
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
            jsonResponse(422, ['error' => 'Adres e-mail i hasło są wymagane']);
            exit;
        }

        $user = $loadUserWithPasswordByEmail($pdo, $email);

        if ($user === false || !passwordMatches($password, (string)$user['password'])) {
            jsonResponse(401, ['error' => 'Nieprawidłowy adres e-mail lub hasło']);
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
            jsonResponse(422, ['error' => 'Token, hasło i potwierdzenie hasła są wymagane']);
            exit;
        }

        if ($password !== $passwordConfirmation) {
            jsonResponse(422, ['error' => 'Potwierdzenie hasła nie jest zgodne']);
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
            jsonResponse(422, ['error' => 'Aktualne hasło, nowe hasło i potwierdzenie nowego hasła są wymagane']);
            exit;
        }

        if ($newPassword !== $newPasswordConfirmation) {
            jsonResponse(422, ['error' => 'Potwierdzenie hasła nie jest zgodne']);
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
        $bootstrapData = [
            'organizations' => $organizations,
            'events' => $events,
            'archivedEvents' => $archivedEvents,
            'users' => $users,
            'participants' => $participants,
            'activityLog' => $activityLogs,
        ];
        $generatedAt = gmdate(DATE_ATOM);

        jsonResponse(200, [
            'generated_at' => $generatedAt,
            'snapshot_version' => $buildBootstrapSnapshotVersion($bootstrapData),
            'data' => $bootstrapData,
        ]);
        exit;
    }

    if (preg_match('#^/organizations/([^/]+)$#', $path, $matches) === 1 && $method === 'GET') {
        $authUser = requireAuth($resolveAuthenticatedUser);
        $organizationId = (string)$matches[1];

        if (!$canAccessOrganization($authUser, $organizationId)) {
            jsonResponse(403, ['error' => 'Brak uprawnień']);
            exit;
        }

        $organization = $loadOrganizationById($pdo, $organizationId);
        if ($organization === false) {
            jsonResponse(404, ['error' => 'Nie znaleziono organizacji']);
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
        if ($name === '') {
            jsonResponse(422, ['error' => 'Nazwa jest wymagana']);
            exit;
        }

        if (!is_int($eventLimit) && !(is_string($eventLimit) && ctype_digit($eventLimit))) {
            jsonResponse(422, ['error' => 'Limit wydarzeń musi być liczbą całkowitą nie mniejszą niż 0']);
            exit;
        }

        $existingOrganizationStmt = $pdo->prepare('SELECT id FROM organizations WHERE name = :name LIMIT 1');
        $existingOrganizationStmt->execute(['name' => $name]);
        if ($existingOrganizationStmt->fetch() !== false) {
            jsonResponse(409, ['error' => 'Organizacja o tej nazwie już istnieje']);
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
            jsonResponse(403, ['error' => 'Brak uprawnień']);
            exit;
        }

        $organization = $loadOrganizationById($pdo, $organizationId);
        if ($organization === false) {
            jsonResponse(404, ['error' => 'Nie znaleziono organizacji']);
            exit;
        }

        $input = readJsonBody();
        $hasName = array_key_exists('name', $input);
        $hasEventLimit = array_key_exists('event_limit', $input);
        if (!$hasName && !$hasEventLimit) {
            jsonResponse(422, ['error' => 'Podaj co najmniej jedno pole organizacji']);
            exit;
        }

        $name = $hasName ? trim((string)$input['name']) : (string)$organization['name'];
        if ($name === '') {
            jsonResponse(422, ['error' => 'Nazwa jest wymagana']);
            exit;
        }

        $eventLimitValue = (int)$organization['event_limit'];
        if ($hasEventLimit) {
            $eventLimit = $input['event_limit'];
            if (!is_int($eventLimit) && !(is_string($eventLimit) && ctype_digit($eventLimit))) {
                jsonResponse(422, ['error' => 'Limit wydarzeń musi być liczbą całkowitą nie mniejszą niż 0']);
                exit;
            }

            $eventLimitValue = (int)$eventLimit;
        }

        $eventCountStmt = $pdo->prepare('SELECT COUNT(*) AS total FROM events WHERE organization_id = :organization_id');
        $eventCountStmt->execute(['organization_id' => $organizationId]);
        $eventCount = (int)($eventCountStmt->fetch()['total'] ?? 0);
        if ($eventLimitValue < $eventCount) {
            jsonResponse(422, ['error' => 'Limit wydarzeń nie może być mniejszy niż bieżąca liczba wydarzeń']);
            exit;
        }

        $existingOrganizationStmt = $pdo->prepare('SELECT id FROM organizations WHERE name = :name AND id <> :id LIMIT 1');
        $existingOrganizationStmt->execute([
            'name' => $name,
            'id' => $organizationId,
        ]);
        if ($existingOrganizationStmt->fetch() !== false) {
            jsonResponse(409, ['error' => 'Organizacja o tej nazwie już istnieje']);
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
            jsonResponse(403, ['error' => 'Brak uprawnień']);
            exit;
        }

        $organization = $loadOrganizationById($pdo, $organizationId);
        if ($organization === false) {
            jsonResponse(404, ['error' => 'Nie znaleziono organizacji']);
            exit;
        }

        $eventCountStmt = $pdo->prepare('SELECT COUNT(*) AS total FROM events WHERE organization_id = :organization_id');
        $eventCountStmt->execute(['organization_id' => $organizationId]);
        $eventCount = (int)($eventCountStmt->fetch()['total'] ?? 0);
        if ($eventCount > 0) {
            jsonResponse(422, ['error' => 'Nie można usunąć organizacji, która ma przypisane wydarzenia']);
            exit;
        }

        $memberCountStmt = $pdo->prepare('SELECT COUNT(*) AS total FROM users WHERE organization_id = :organization_id AND archived_at IS NULL');
        $memberCountStmt->execute(['organization_id' => $organizationId]);
        $memberCount = (int)($memberCountStmt->fetch()['total'] ?? 0);
        if ($memberCount > 0) {
            jsonResponse(422, ['error' => 'Nie można usunąć organizacji z przypisanymi użytkownikami']);
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
            jsonResponse(422, ['error' => 'Nazwa, lokalizacja, organization_id, office_open_at i office_close_at są wymagane']);
            exit;
        }

        if (!isValidLocalDateTimeString($officeOpenAt) || !isValidLocalDateTimeString($officeCloseAt)) {
            jsonResponse(422, ['error' => 'office_open_at i office_close_at muszą być prawidłowymi lokalnymi datami i godzinami']);
            exit;
        }

        $normalizedOfficeOpenAt = normalizeLocalDateTimeString($officeOpenAt);
        $normalizedOfficeCloseAt = normalizeLocalDateTimeString($officeCloseAt);
        if ($normalizedOfficeOpenAt === null || $normalizedOfficeCloseAt === null) {
            jsonResponse(422, ['error' => 'office_open_at i office_close_at muszą być prawidłowymi lokalnymi datami i godzinami']);
            exit;
        }

        if ($normalizedOfficeOpenAt >= $normalizedOfficeCloseAt) {
            jsonResponse(422, ['error' => 'Godzina otwarcia biura musi być wcześniejsza niż godzina zamknięcia']);
            exit;
        }

        $officeWindowValidationError = $validateEventOfficeWindow($normalizedOfficeOpenAt, $normalizedOfficeCloseAt);
        if ($officeWindowValidationError !== null) {
            jsonResponse(422, ['error' => $officeWindowValidationError]);
            exit;
        }

        $eventDate = substr($normalizedOfficeOpenAt, 0, 10);

        if (!$canAccessOrganization($authUser, $organizationId)) {
            jsonResponse(403, ['error' => 'Brak uprawnień']);
            exit;
        }

        $organization = $loadOrganizationById($pdo, $organizationId);
        if ($organization === false) {
            jsonResponse(422, ['error' => 'Nie znaleziono organizacji']);
            exit;
        }

        $eventCountStmt = $pdo->prepare('SELECT COUNT(*) AS total FROM events WHERE organization_id = :organization_id');
        $eventCountStmt->execute(['organization_id' => $organizationId]);
        $eventCount = (int)($eventCountStmt->fetch()['total'] ?? 0);

        if ($eventCount >= (int)$organization['event_limit']) {
            jsonResponse(422, ['error' => 'Organizacja osiągnęła limit wydarzeń']);
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
            jsonResponse(404, ['error' => 'Nie znaleziono wydarzenia']);
            exit;
        }

        if (!$canAccessEvent($authUser, $event) && !$canViewArchivedEvent($authUser, $event)) {
            jsonResponse(403, ['error' => 'Brak uprawnień']);
            exit;
        }

        jsonResponse(200, ['data' => $event]);
        exit;
    }

    if (preg_match('#^/events/([^/]+)/export-package$#', $path, $matches) === 1 && $method === 'GET') {
        $authUser = requireAnyRole(['superadmin', 'admin', 'editor'], $resolveAuthenticatedUser);
        $eventId = (string)$matches[1];
        $event = $loadEventById($pdo, $eventId);

        if ($event === false) {
            jsonResponse(404, ['error' => 'Nie znaleziono wydarzenia']);
            exit;
        }

        if (!$canManageEventParticipants($authUser, $event)) {
            jsonResponse(403, ['error' => 'Brak uprawnień']);
            exit;
        }

        $participants = $loadParticipantsByEventIds($pdo, [$eventId]);
        $mappings = $loadParticipantFieldMappings($pdo, $eventId);
        $assignedUsers = $loadAssignedUsersForEvent($pdo, $eventId);
        $exportedAt = gmdate(DATE_ATOM);
        $syncState = $saveEventSyncState($pdo, $eventId, [
            'sync_status' => 'pending',
            'last_exported_at' => gmdate('Y-m-d H:i:s'),
        ]);

        jsonResponse(200, [
            'exported_at' => $exportedAt,
            'data' => [
                'event' => $event,
                'participants' => $participants,
                'mappings' => $mappings,
                'assigned_users' => $assignedUsers,
                'sync_state' => $syncState,
            ],
        ]);
        exit;
    }

    if (preg_match('#^/events/([^/]+)$#', $path, $matches) === 1 && $method === 'PATCH') {
        $authUser = requireAnyRole(['superadmin', 'admin', 'editor'], $resolveAuthenticatedUser);
        $eventId = (string)$matches[1];
        $event = $loadEventById($pdo, $eventId);

        if ($event === false) {
            jsonResponse(404, ['error' => 'Nie znaleziono wydarzenia']);
            exit;
        }

        if (!$canManageEventParticipants($authUser, $event)) {
            jsonResponse(403, ['error' => 'Brak uprawnień']);
            exit;
        }

        $input = readJsonBody();

        $name = trim((string)($input['name'] ?? ''));
        $location = trim((string)($input['location'] ?? ''));
        $officeOpenAt = trim((string)($input['office_open_at'] ?? ''));
        $officeCloseAt = trim((string)($input['office_close_at'] ?? ''));

        if ($name === '' || $location === '' || $officeOpenAt === '' || $officeCloseAt === '') {
            jsonResponse(422, ['error' => 'Nazwa, lokalizacja, office_open_at i office_close_at są wymagane']);
            exit;
        }

        if (!isValidLocalDateTimeString($officeOpenAt) || !isValidLocalDateTimeString($officeCloseAt)) {
            jsonResponse(422, ['error' => 'office_open_at i office_close_at muszą być prawidłowymi lokalnymi datami i godzinami']);
            exit;
        }

        $normalizedOfficeOpenAt = normalizeLocalDateTimeString($officeOpenAt);
        $normalizedOfficeCloseAt = normalizeLocalDateTimeString($officeCloseAt);
        if ($normalizedOfficeOpenAt === null || $normalizedOfficeCloseAt === null) {
            jsonResponse(422, ['error' => 'office_open_at i office_close_at muszą być prawidłowymi lokalnymi datami i godzinami']);
            exit;
        }

        if ($normalizedOfficeOpenAt >= $normalizedOfficeCloseAt) {
            jsonResponse(422, ['error' => 'Godzina otwarcia biura musi być wcześniejsza niż godzina zamknięcia']);
            exit;
        }

        $isCurrentlyOpen = $isEventOfficeOpenNow($event);
        $officeWindowValidationError = $validateEventOfficeWindow(
            $normalizedOfficeOpenAt,
            $normalizedOfficeCloseAt,
            $isCurrentlyOpen
        );
        if ($officeWindowValidationError !== null) {
            jsonResponse(422, ['error' => $officeWindowValidationError]);
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
            jsonResponse(404, ['error' => 'Nie znaleziono wydarzenia']);
            exit;
        }

        if (!$canManageEventParticipants($authUser, $event)) {
            jsonResponse(403, ['error' => 'Brak uprawnień']);
            exit;
        }

        if (trim((string)($event['archived_at'] ?? '')) !== '') {
            jsonResponse(422, ['error' => 'Wydarzenie jest już zarchiwizowane']);
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
            jsonResponse(404, ['error' => 'Nie znaleziono wydarzenia']);
            exit;
        }

        if (!$canManageEventParticipants($authUser, $event)) {
            jsonResponse(403, ['error' => 'Brak uprawnień']);
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
            jsonResponse(500, ['error' => 'Nie udało się rozpocząć eksportu CSV']);
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
            jsonResponse(500, ['error' => 'Nie udało się przygotować eksportu CSV']);
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
            jsonResponse(404, ['error' => 'Nie znaleziono wydarzenia']);
            exit;
        }

        if (!$canManageEventParticipants($authUser, $event)) {
            jsonResponse(403, ['error' => 'Brak uprawnień']);
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
            jsonResponse(500, ['error' => 'Nie udało się rozpocząć eksportu CSV']);
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
            jsonResponse(500, ['error' => 'Nie udało się przygotować eksportu CSV']);
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
            jsonResponse(404, ['error' => 'Nie znaleziono wydarzenia']);
            exit;
        }

        if (!$canManageEventParticipants($authUser, $event)) {
            jsonResponse(403, ['error' => 'Brak uprawnień']);
            exit;
        }

        $input = readJsonBody(10485760);
        $csvContent = (string)($input['csv_content'] ?? '');
        if (trim($csvContent) === '') {
            jsonResponse(422, ['error' => 'Pole csv_content jest wymagane']);
            exit;
        }

        $parsed = $parseCsvContent($csvContent);
        $cleaned = $cleanupCsvDataset($parsed['headers'], $parsed['rows']);
        if ($cleaned['headers'] === []) {
            jsonResponse(422, ['error' => 'Plik CSV nie zawiera żadnych niepustych kolumn danych']);
            exit;
        }

        $emailCandidates = $findEmailCandidateColumns($cleaned['headers'], $cleaned['rows']);
        if ($emailCandidates === []) {
            jsonResponse(422, ['error' => 'Plik CSV jest nieprawidłowy: nie wykryto kolumny z adresem e-mail. Każdy uczestnik musi mieć adres e-mail.']);
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
            jsonResponse(404, ['error' => 'Nie znaleziono wydarzenia']);
            exit;
        }

        if (!$canManageEventParticipants($authUser, $event)) {
            jsonResponse(403, ['error' => 'Brak uprawnień']);
            exit;
        }

        if ($loadParticipantFieldMappings($pdo, $eventId) !== []) {
            jsonResponse(409, ['error' => 'Mapowanie pól uczestnika dla tego wydarzenia już istnieje']);
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
            jsonResponse(422, ['error' => 'Pola csv_columns i email_column są wymagane']);
            exit;
        }

        if (!in_array($emailColumn, $csvColumns, true)) {
            jsonResponse(422, ['error' => 'Pole email_column musi wskazywać istniejącą kolumnę CSV']);
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
                jsonResponse(422, ['error' => sprintf('Alias jest wymagany dla aktywnej kolumny "%s"', $sourceColumnName)]);
                exit;
            }

            if (!in_array($fieldRole, ['display_name_part', 'bib_number', 'custom'], true)) {
                jsonResponse(422, ['error' => sprintf('Nieprawidłowa rola pola dla kolumny "%s"', $sourceColumnName)]);
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
            jsonResponse(422, ['error' => 'Wymagane jest co najmniej jedno aktywne mapowanie display_name_part']);
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
            jsonResponse(404, ['error' => 'Nie znaleziono wydarzenia']);
            exit;
        }

        if (!$canManageEventParticipants($authUser, $event)) {
            jsonResponse(403, ['error' => 'Brak uprawnień']);
            exit;
        }

        $mappings = $loadParticipantFieldMappings($pdo, $eventId);
        if ($mappings === []) {
            jsonResponse(422, ['error' => 'Przed importem trzeba skonfigurować mapowanie pól uczestnika']);
            exit;
        }

        $input = readJsonBody(10485760);
        $csvContent = (string)($input['csv_content'] ?? '');
        if (trim($csvContent) === '') {
            jsonResponse(422, ['error' => 'Pole csv_content jest wymagane']);
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
                'error' => 'W pliku CSV brakuje wymaganych kolumn z zapisanego mapowania',
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
            jsonResponse(500, ['error' => 'Zapisane mapowanie jest nieprawidłowe: brak kolumny e-mail']);
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
        $authUser = requireAnyRole(['superadmin', 'admin', 'editor', 'scanner', 'scanner_plus'], $resolveAuthenticatedUser);
        $eventId = (string)$matches[1];
        $event = $loadEventById($pdo, $eventId);

        if ($event === false) {
            jsonResponse(404, ['error' => 'Nie znaleziono wydarzenia']);
            exit;
        }

        if (!$canManageEventParticipants($authUser, $event)) {
            jsonResponse(403, ['error' => 'Brak uprawnień']);
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
            jsonResponse(404, ['error' => 'Nie znaleziono wydarzenia']);
            exit;
        }

        if (!$canManageEventParticipants($authUser, $event)) {
            jsonResponse(403, ['error' => 'Brak uprawnień']);
            exit;
        }

        $mappings = $loadParticipantFieldMappings($pdo, $eventId);
        if ($mappings === []) {
            jsonResponse(422, ['error' => 'Ręczne dodawanie uczestników jest dostępne dopiero po zapisaniu pierwszego mapowania CSV']);
            exit;
        }

        $input = readJsonBody();
        $email = normalizeEmailAddress((string)($input['email'] ?? ''));
        $fieldValues = is_array($input['field_values'] ?? null) ? $input['field_values'] : [];

        if ($email === '' || !isValidEmailAddress($email)) {
            jsonResponse(422, ['error' => 'Wymagany jest prawidłowy adres e-mail']);
            exit;
        }

        $resolvedData = $buildParticipantDataFromMappings($mappings, $fieldValues);
        $resolvedDisplayName = $resolveParticipantDisplayName((string)$resolvedData['display_name'], $email);

        $participant = $insertParticipantRecord(
            $pdo,
            $eventId,
            $resolvedDisplayName,
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
            jsonResponse(403, ['error' => 'Brak uprawnień']);
            exit;
        }

        if (!is_int($eventLimit) && !(is_string($eventLimit) && ctype_digit($eventLimit))) {
            jsonResponse(422, ['error' => 'Limit wydarzeń musi być liczbą całkowitą nie mniejszą niż 0']);
            exit;
        }

        $organization = $loadOrganizationById($pdo, $organizationId);
        if ($organization === false) {
            jsonResponse(404, ['error' => 'Nie znaleziono organizacji']);
            exit;
        }

        $eventCountStmt = $pdo->prepare('SELECT COUNT(*) AS total FROM events WHERE organization_id = :organization_id');
        $eventCountStmt->execute(['organization_id' => $organizationId]);
        $eventCount = (int)($eventCountStmt->fetch()['total'] ?? 0);
        $eventLimitValue = (int)$eventLimit;
        $previousEventLimitValue = (int)$organization['event_limit'];

        if ($eventLimitValue < $eventCount) {
            jsonResponse(422, ['error' => 'Limit wydarzeń nie może być mniejszy niż bieżąca liczba wydarzeń']);
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
            jsonResponse(422, ['error' => 'Nazwa, adres e-mail i rola są wymagane']);
            exit;
        }

        if (!isValidEmailAddress($email)) {
            jsonResponse(422, ['error' => 'Adres e-mail musi być prawidłowy']);
            exit;
        }

        $allowedRolesByCreator = [
            'superadmin' => ['admin', 'editor', 'scanner', 'scanner_plus'],
            'admin' => ['editor', 'scanner', 'scanner_plus'],
            'editor' => ['editor', 'scanner', 'scanner_plus'],
        ];

        if (!in_array($role, $allowedRolesByCreator[(string)$authUser['role']] ?? [], true)) {
            jsonResponse(403, ['error' => 'Brak uprawnień']);
            exit;
        }

        if ($role === 'admin') {
            if ($authUser['role'] !== 'superadmin') {
                jsonResponse(403, ['error' => 'Brak uprawnień']);
                exit;
            }

            $assignedEvents = [];
            $organizationId = '';
        } else {
            if ($authUser['role'] === 'superadmin') {
                if ($organizationId === '') {
                    jsonResponse(422, ['error' => 'Dla tej roli pole organization_id jest wymagane']);
                    exit;
                }
            } elseif ($authUser['role'] === 'admin') {
                if ($organizationId === '') {
                    jsonResponse(403, ['error' => 'Brak uprawnień']);
                    exit;
                }
            } else {
                $organizationId = (string)($authUser['organization_id'] ?? '');
            }

            if ($organizationId === '') {
                jsonResponse(422, ['error' => 'Dla tej roli pole organization_id jest wymagane']);
                exit;
            }

            if ($loadOrganizationById($pdo, $organizationId) === false) {
                jsonResponse(422, ['error' => 'Nie znaleziono organizacji']);
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

        $existingUserStmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $existingUserStmt->execute(['email' => $email]);
        if ($existingUserStmt->fetch() !== false) {
            jsonResponse(409, ['error' => 'Użytkownik z tym adresem e-mail już istnieje']);
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
                        sprintf('Przypisano operatora %s do wydarzenia', $name),
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

            $setupToken = $createPasswordResetToken($pdo, $userId, 7 * 24 * 60 * 60);
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
            jsonResponse(404, ['error' => 'Nie znaleziono użytkownika']);
            exit;
        }

        if (!$isScannerRole((string)($targetUser['role'] ?? ''))) {
            jsonResponse(422, ['error' => 'Przypisania do wydarzeń mogą mieć tylko operatorzy']);
            exit;
        }

        if (!$canManageTargetUser($authUser, $targetUser)) {
            jsonResponse(403, ['error' => 'Brak uprawnień']);
            exit;
        }

        $organizationId = (string)($targetUser['organization_id'] ?? '');
        if ($organizationId === '') {
            jsonResponse(422, ['error' => 'Operator musi należeć do organizacji']);
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
                    sprintf('Przypisano operatora %s do wydarzenia', (string)$targetUser['name']),
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
                    sprintf('Usunieto operatora %s z wydarzenia', (string)$targetUser['name']),
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
            jsonResponse(422, ['error' => 'Rola jest wymagana']);
            exit;
        }

        $targetUser = $loadUserById($pdo, $userId);
        if ($targetUser === false) {
            jsonResponse(404, ['error' => 'Nie znaleziono użytkownika']);
            exit;
        }

        $allowedRolesByCreator = [
            'superadmin' => ['admin', 'editor', 'scanner', 'scanner_plus'],
            'admin' => ['editor', 'scanner', 'scanner_plus'],
            'editor' => ['scanner', 'scanner_plus'],
        ];

        if (!in_array($role, $allowedRolesByCreator[(string)$authUser['role']] ?? [], true)) {
            jsonResponse(403, ['error' => 'Brak uprawnień']);
            exit;
        }

        if (!$canManageTargetUser($authUser, $targetUser)) {
            jsonResponse(403, ['error' => 'Brak uprawnień']);
            exit;
        }

        if (($targetUser['role'] ?? '') === 'admin' || $role === 'admin') {
            jsonResponse(422, ['error' => 'Zmiana ról administratorów nie jest obsługiwana przez ten endpoint']);
            exit;
        }

        if (($targetUser['organization_id'] ?? null) === null) {
            jsonResponse(422, ['error' => 'Docelowy użytkownik musi należeć do organizacji']);
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

    if (preg_match('#^/users/([^/]+)$#', $path, $matches) === 1 && $method === 'PATCH') {
        $authUser = requireAnyRole(['superadmin', 'admin', 'editor'], $resolveAuthenticatedUser);
        $userId = (string)$matches[1];
        $input = readJsonBody();
        $name = trim((string)($input['name'] ?? ''));
        $email = normalizeEmailAddress((string)($input['email'] ?? ''));

        if ($name === '' || $email === '') {
            jsonResponse(422, ['error' => 'Nazwa i adres e-mail są wymagane']);
            exit;
        }

        if (!isValidEmailAddress($email)) {
            jsonResponse(422, ['error' => 'Adres e-mail musi być prawidłowy']);
            exit;
        }

        $targetUser = $loadUserById($pdo, $userId);
        if ($targetUser === false) {
            jsonResponse(404, ['error' => 'Nie znaleziono użytkownika']);
            exit;
        }

        if (!$canManageTargetUser($authUser, $targetUser)) {
            jsonResponse(403, ['error' => 'Brak uprawnień']);
            exit;
        }

        if (in_array((string)($targetUser['role'] ?? ''), ['admin', 'superadmin'], true)) {
            jsonResponse(422, ['error' => 'Aktualizować można tylko konta organizatorów i operatorów']);
            exit;
        }

        $emailOwnerStmt = $pdo->prepare('
            SELECT id
            FROM users
            WHERE email = :email
              AND archived_at IS NULL
              AND id <> :id
            LIMIT 1
        ');
        $emailOwnerStmt->execute([
            'email' => $email,
            'id' => $userId,
        ]);

        if ($emailOwnerStmt->fetch() !== false) {
            jsonResponse(409, ['error' => 'Użytkownik z tym adresem e-mail już istnieje']);
            exit;
        }

        $pdo->beginTransaction();

        try {
            $updateStmt = $pdo->prepare('
                UPDATE users
                SET name = :name,
                    email = :email
                WHERE id = :id
            ');
            $updateStmt->execute([
                'name' => $name,
                'email' => $email,
                'id' => $userId,
            ]);

            if ($name !== (string)$targetUser['name'] || $email !== (string)$targetUser['email']) {
                $addActivityLog(
                    $pdo,
                    sprintf('Zaktualizowano dane uzytkownika: %s', $name),
                    null,
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

    if (preg_match('#^/users/([^/]+)/password-reset$#', $path, $matches) === 1 && $method === 'POST') {
        $authUser = requireAnyRole(['superadmin', 'admin'], $resolveAuthenticatedUser);
        $userId = (string)$matches[1];

        $targetUser = $loadUserById($pdo, $userId);
        if ($targetUser === false) {
            jsonResponse(404, ['error' => 'Nie znaleziono użytkownika']);
            exit;
        }

        if (!$canManageTargetUser($authUser, $targetUser)) {
            jsonResponse(403, ['error' => 'Brak uprawnień']);
            exit;
        }

        if (!in_array((string)($targetUser['role'] ?? ''), ['editor', 'scanner', 'scanner_plus'], true)) {
            jsonResponse(422, ['error' => 'Reset hasła wywołany przez administratora można wysłać tylko do kont organizatorów i operatorów']);
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

            jsonResponse(500, ['error' => 'Nie udało się wysłać wiadomości do resetu hasła']);
            exit;
        }

        jsonResponse(200, ['success' => true]);
        exit;
    }

    if (preg_match('#^/users/([^/]+)$#', $path, $matches) === 1 && $method === 'DELETE') {
        $authUser = requireAnyRole(['superadmin', 'admin', 'editor'], $resolveAuthenticatedUser);
        $userId = (string)$matches[1];

        $targetUser = $loadUserById($pdo, $userId);
        if ($targetUser === false) {
            jsonResponse(404, ['error' => 'Nie znaleziono użytkownika']);
            exit;
        }

        if (!$canManageTargetUser($authUser, $targetUser)) {
            jsonResponse(403, ['error' => 'Brak uprawnień']);
            exit;
        }

        if (!in_array((string)($targetUser['role'] ?? ''), ['editor', 'scanner', 'scanner_plus'], true)) {
            jsonResponse(422, ['error' => 'Archiwizować można tylko konta organizatorów i operatorów']);
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
            jsonResponse(404, ['error' => 'Nie znaleziono uczestnika']);
            exit;
        }

        [$event, $accessError] = $assertParticipantEventAccess($pdo, $authUser, $participant);
        if ($accessError !== null || $event === null || !$canManageEventParticipants($authUser, $event)) {
            jsonResponse($accessError === 'Brak uprawnień' ? 403 : 422, ['error' => $accessError ?? 'Brak uprawnień']);
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
            jsonResponse(404, ['error' => 'Nie znaleziono uczestnika']);
            exit;
        }

        [$event, $accessError] = $assertParticipantEventAccess($pdo, $authUser, $participant);
        if ($accessError !== null || $event === null || !$canManageEventParticipants($authUser, $event)) {
            jsonResponse($accessError === 'Brak uprawnień' ? 403 : 422, ['error' => $accessError ?? 'Brak uprawnień']);
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
            jsonResponse(404, ['error' => 'Nie znaleziono wydarzenia']);
            exit;
        }

        if (!$canManageEventParticipants($authUser, $event)) {
            jsonResponse(403, ['error' => 'Brak uprawnień']);
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

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $updateSentEmailStatusStmt = $pdo->prepare('UPDATE participants SET email_status = :email_status WHERE id = :id');

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

                $updateSentEmailStatusStmt->execute([
                    'email_status' => 'sent',
                    'id' => (int)$participantRow['id'],
                ]);
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

        if ($sentCount > 0) {
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

    if ($path === '/sync/ingest' && $method === 'POST') {
        $authUser = requireAnyRole(['superadmin', 'admin', 'editor'], $resolveAuthenticatedUser);
        $input = readJsonBody();
        $eventId = trim((string)($input['event_id'] ?? ''));
        $sourceNodeId = trim((string)($input['source_node_id'] ?? ''));
        $mutations = is_array($input['mutations'] ?? null) ? $input['mutations'] : [];

        if ($eventId === '') {
            jsonResponse(422, ['error' => 'Pole event_id jest wymagane']);
            exit;
        }

        if ($mutations === []) {
            jsonResponse(422, ['error' => 'Pole mutations jest wymagane']);
            exit;
        }

        $event = $loadEventById($pdo, $eventId);
        if ($event === false) {
            jsonResponse(404, ['error' => 'Nie znaleziono wydarzenia']);
            exit;
        }

        if (!$canManageEventParticipants($authUser, $event)) {
            jsonResponse(403, ['error' => 'Brak uprawnień']);
            exit;
        }

        $report = [
            'sent' => count($mutations),
            'applied' => 0,
            'conflicts' => 0,
            'duplicates' => 0,
            'errors' => [],
        ];

        foreach ($mutations as $index => $mutation) {
            if (!is_array($mutation)) {
                $report['errors'][] = ['index' => $index, 'error' => 'Invalid mutation payload'];
                continue;
            }

            $clientMutationId = trim((string)($mutation['client_mutation_id'] ?? ''));
            $participantId = (int)($mutation['participant_id'] ?? 0);
            $requestedStatus = trim((string)($mutation['requested_status'] ?? $mutation['next_status'] ?? ''));
            $baseStatus = trim((string)($mutation['base_status'] ?? ''));
            $deviceId = trim((string)($mutation['device_id'] ?? ''));

            if ($clientMutationId === '' || !$isValidParticipantStatus($requestedStatus) || !$isValidParticipantStatus($baseStatus)) {
                $report['errors'][] = ['index' => $index, 'error' => 'Mutacja musi zawierać pola client_mutation_id, requested_status i base_status'];
                continue;
            }

            $participant = $loadParticipantById($pdo, $participantId);
            if ($participant === false || (string)($participant['event_id'] ?? '') !== $eventId) {
                $report['errors'][] = ['index' => $index, 'error' => 'Nie znaleziono uczestnika in the target event'];
                continue;
            }

            $existingMutation = $loadClientMutationById($pdo, $clientMutationId);
            if ($existingMutation !== false) {
                $report['duplicates']++;
                continue;
            }

            if ((string)$participant['status'] !== $baseStatus) {
                $report['conflicts']++;
                $report['errors'][] = [
                    'index' => $index,
                    'error' => 'state_conflict',
                    'participant_id' => (int)$participant['id'],
                    'current_status' => (string)$participant['status'],
                ];
                continue;
            }

            $pdo->beginTransaction();

            try {
                if ($requestedStatus !== (string)$participant['status']) {
                    $participant = $updateParticipantStatus($pdo, $participant, $requestedStatus);
                    $addActivityLog(
                        $pdo,
                        sprintf('Zsynchronizowano status uczestnika z wezla lokalnego na %s', $requestedStatus),
                        (string)$participant['event_id'],
                        (int)$participant['id'],
                        (string)$participant['display_name'],
                        (string)$authUser['id'],
                        (string)$authUser['name']
                    );
                } else {
                    $participant = $loadParticipantById($pdo, (int)$participant['id']) ?: $participant;
                }

                $recordClientMutation(
                    $pdo,
                    $clientMutationId,
                    (int)$participant['id'],
                    (string)$participant['event_id'],
                    $deviceId !== '' ? $deviceId : null,
                    $sourceNodeId !== '' ? $sourceNodeId : null,
                    (string)$authUser['id'],
                    $baseStatus,
                    $requestedStatus,
                    (string)$participant['status'],
                    ['data' => $participant]
                );

                $pdo->commit();
                $report['applied']++;
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $report['errors'][] = [
                    'index' => $index,
                    'error' => $exception->getMessage(),
                ];
            }
        }

        $syncState = $saveEventSyncState($pdo, $eventId, [
            'sync_mode' => $sourceNodeId !== '' ? 'local_authoritative' : 'cloud',
            'source_node_id' => $sourceNodeId !== '' ? $sourceNodeId : null,
            'sync_status' => $report['conflicts'] > 0 ? 'conflict' : 'idle',
            'conflicts_count' => $report['conflicts'],
            'last_synced_at' => gmdate('Y-m-d H:i:s'),
            'last_report' => $report,
        ]);

        jsonResponse(200, [
            'data' => $report,
            'event_sync_state' => $syncState,
        ]);
        exit;
    }

    if ($path === '/participants/scan' && $method === 'POST') {
        $authUser = requireAuth($resolveAuthenticatedUser);
        $input = readJsonBody();
        $qrCode = trim((string)($input['qr_code'] ?? ''));

        if ($qrCode === '') {
            jsonResponse(422, ['error' => 'Pole qr_code jest wymagane']);
            exit;
        }

        $participant = $loadParticipantByQrCode($pdo, $qrCode);
        if ($participant === false) {
            jsonResponse(404, ['error' => 'Nie znaleziono uczestnika']);
            exit;
        }

        $eventId = trim((string)($participant['event_id'] ?? ''));
        if ($eventId === '') {
            jsonResponse(422, ['error' => 'Uczestnik nie jest przypisany do wydarzenia']);
            exit;
        }

        $event = $loadEventById($pdo, $eventId);
        if ($event === false) {
            jsonResponse(404, ['error' => 'Nie znaleziono wydarzenia']);
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
        $authUser = requireAnyRole(['superadmin', 'admin', 'editor', 'scanner', 'scanner_plus'], $resolveAuthenticatedUser);
        $participant = $loadParticipantById($pdo, (int)$matches[1]);

        if ($participant === false) {
            jsonResponse(404, ['error' => 'Nie znaleziono uczestnika']);
            exit;
        }

        [$event, $accessError] = $assertParticipantEventAccess($pdo, $authUser, $participant);
        if ($accessError !== null || $event === null) {
            jsonResponse($accessError === 'Brak uprawnień' ? 403 : 422, ['error' => $accessError ?? 'Brak uprawnień']);
            exit;
        }

        $input = readJsonBody();
        $nextStatus = trim((string)($input['status'] ?? ''));
        $email = array_key_exists('email', $input) ? normalizeEmailAddress((string)$input['email']) : null;
        $fieldValues = is_array($input['field_values'] ?? null) ? $input['field_values'] : null;
        $hasBibNumberUpdate = array_key_exists('bib_number', $input);
        $requestedBibNumber = $hasBibNumberUpdate ? $normalizeParticipantBibNumber($input['bib_number']) : null;
        $bibNumberConflictResolution = trim((string)($input['bib_number_conflict_resolution'] ?? ''));
        $clientMutationId = trim((string)($input['client_mutation_id'] ?? ''));
        $deviceId = trim((string)($input['device_id'] ?? ''));
        $sourceNodeId = trim((string)($input['source_node_id'] ?? ''));
        $eventIdFromClient = trim((string)($input['event_id'] ?? ''));
        $baseStatus = trim((string)($input['base_status'] ?? ''));
        $canUpdateStatus = $canAccessEvent($authUser, $event) || $canManageEventParticipants($authUser, $event);
        $canManageParticipantRecord = $canManageEventParticipants($authUser, $event);

        if ($nextStatus === '' && $email === null && $fieldValues === null && !$hasBibNumberUpdate) {
            jsonResponse(422, ['error' => 'Podaj co najmniej jedno pole uczestnika']);
            exit;
        }

        if ($nextStatus !== '' && !$canUpdateStatus) {
            jsonResponse(403, ['error' => 'Brak uprawnien']);
            exit;
        }

        if (($email !== null || $fieldValues !== null || $hasBibNumberUpdate) && !$canManageParticipantRecord) {
            jsonResponse(403, ['error' => 'Brak uprawnien']);
            exit;
        }

        if ($clientMutationId !== '' && strlen($clientMutationId) > 64) {
            jsonResponse(422, ['error' => 'Pole client_mutation_id jest zbyt długie']);
            exit;
        }

        if ($eventIdFromClient !== '' && $eventIdFromClient !== (string)$participant['event_id']) {
            jsonResponse(409, ['error' => 'Wydarzenie uczestnika nie zgadza się z żądanym wydarzeniem', 'code' => 'event_mismatch', 'data' => $participant]);
            exit;
        }

        if ($baseStatus !== '' && !$isValidParticipantStatus($baseStatus)) {
            jsonResponse(422, ['error' => 'Nieobsługiwany bazowy status uczestnika']);
            exit;
        }

        if ($nextStatus !== '') {
            if (!$isValidParticipantStatus($nextStatus)) {
                jsonResponse(422, ['error' => 'Nieobsługiwany status uczestnika']);
                exit;
            }

            if ($clientMutationId !== '') {
                $existingMutation = $loadClientMutationById($pdo, $clientMutationId);
                if ($existingMutation !== false) {
                    if ((int)$existingMutation['participant_id'] !== (int)$participant['id']) {
                        jsonResponse(409, ['error' => 'client_mutation_id jest już powiązany z innym uczestnikiem', 'code' => 'mutation_id_conflict']);
                        exit;
                    }

                    $storedParticipant = $loadParticipantById($pdo, (int)$participant['id']);
                    jsonResponse(200, [
                        'data' => $storedParticipant ?: $participant,
                        'client_mutation_id' => $clientMutationId,
                        'idempotent' => true,
                    ]);
                    exit;
                }
            }

            if ($baseStatus !== '' && $baseStatus !== (string)$participant['status']) {
                jsonResponse(409, [
                    'error' => 'Status uczestnika zmienił się na serwerze. Wymagana jest weryfikacja.',
                    'code' => 'state_conflict',
                    'data' => $participant,
                ]);
                exit;
            }

            if ($nextStatus !== (string)$participant['status'] || $clientMutationId !== '') {
                $statusBeforeUpdate = (string)$participant['status'];
                $pdo->beginTransaction();

                try {
                    if ($nextStatus !== $statusBeforeUpdate) {
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
                    } else {
                        $participant = $loadParticipantById($pdo, (int)$participant['id']) ?: $participant;
                    }

                    if ($clientMutationId !== '') {
                        $responsePayload = ['data' => $participant];
                        $resolvedBaseStatus = $baseStatus !== '' ? $baseStatus : $statusBeforeUpdate;
                        $resolvedSourceNodeId = $sourceNodeId !== '' ? $sourceNodeId : null;
                        $resolvedDeviceId = $deviceId !== '' ? $deviceId : null;

                        $recordClientMutation(
                            $pdo,
                            $clientMutationId,
                            (int)$participant['id'],
                            (string)$participant['event_id'],
                            $resolvedDeviceId,
                            $resolvedSourceNodeId,
                            (string)$authUser['id'],
                            $resolvedBaseStatus,
                            $nextStatus,
                            (string)$participant['status'],
                            $responsePayload
                        );

                        $enqueueSyncOutbox(
                            $pdo,
                            (string)$participant['event_id'],
                            'participant',
                            (string)$participant['id'],
                            'participant_status_updated',
                            [
                                'client_mutation_id' => $clientMutationId,
                                'participant' => $participant,
                                'base_status' => $resolvedBaseStatus,
                                'requested_status' => $nextStatus,
                                'applied_status' => (string)$participant['status'],
                                'device_id' => $resolvedDeviceId,
                                'source_node_id' => $resolvedSourceNodeId,
                                'user_id' => (string)$authUser['id'],
                                'user_name' => (string)$authUser['name'],
                                'event_id' => (string)$participant['event_id'],
                            ],
                            $clientMutationId,
                            $resolvedSourceNodeId
                        );
                    }

                    $pdo->commit();
                } catch (Throwable $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    throw $exception;
                }
            }
        }

        if ($hasBibNumberUpdate) {
            $currentBibNumber = $normalizeParticipantBibNumber($participant['bib_number'] ?? null);

            if ($requestedBibNumber !== null && strlen($requestedBibNumber) > 32) {
                jsonResponse(422, ['error' => 'Numer startowy może mieć maksymalnie 32 znaki']);
                exit;
            }

            if ($requestedBibNumber !== $currentBibNumber) {
                $conflictingParticipants = $requestedBibNumber !== null
                    ? $loadParticipantsByEventAndBibNumber(
                        $pdo,
                        (string)$participant['event_id'],
                        $requestedBibNumber,
                        (int)$participant['id']
                    )
                    : [];

                if (
                    $conflictingParticipants !== []
                    && !in_array($bibNumberConflictResolution, ['keep_duplicates', 'delete_conflicts'], true)
                ) {
                    jsonResponse(409, [
                        'error' => 'Ten numer startowy jest już używany przez innego uczestnika.',
                        'code' => 'bib_number_conflict',
                        'data' => [
                            'bib_number' => $requestedBibNumber,
                            'conflicting_participants' => $conflictingParticipants,
                        ],
                    ]);
                    exit;
                }

                if (
                    $conflictingParticipants !== []
                    && $bibNumberConflictResolution === 'delete_conflicts'
                    && !in_array((string)($authUser['role'] ?? ''), ['superadmin', 'admin', 'editor'], true)
                ) {
                    jsonResponse(403, ['error' => 'Brak uprawnień do usuwania innych uczestników z konfliktem numeru startowego']);
                    exit;
                }

                $pdo->beginTransaction();

                try {
                    if ($conflictingParticipants !== [] && $bibNumberConflictResolution === 'delete_conflicts') {
                        $deleteStmt = $pdo->prepare('DELETE FROM participants WHERE id = :id');

                        foreach ($conflictingParticipants as $conflictingParticipant) {
                            $addActivityLog(
                                $pdo,
                                sprintf('Usunięto uczestnika podczas zwalniania numeru startowego %s', $requestedBibNumber),
                                (string)$participant['event_id'],
                                (int)$conflictingParticipant['id'],
                                (string)$conflictingParticipant['display_name'],
                                (string)$authUser['id'],
                                (string)$authUser['name']
                            );
                            $deleteStmt->execute(['id' => (int)$conflictingParticipant['id']]);
                        }
                    }

                    $updateBibNumberStmt = $pdo->prepare('UPDATE participants SET bib_number = :bib_number WHERE id = :id');
                    $updateBibNumberStmt->bindValue(':bib_number', $requestedBibNumber, $requestedBibNumber === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                    $updateBibNumberStmt->bindValue(':id', (int)$participant['id'], PDO::PARAM_INT);
                    $updateBibNumberStmt->execute();

                    $participant = $loadParticipantById($pdo, (int)$participant['id']) ?: $participant;
                    $addActivityLog(
                        $pdo,
                        $requestedBibNumber === null
                            ? 'Wyczyszczono numer startowy uczestnika'
                            : (
                                $bibNumberConflictResolution === 'keep_duplicates'
                                    ? sprintf('Ustawiono współdzielony numer startowy %s', $requestedBibNumber)
                                    : sprintf('Ustawiono numer startowy uczestnika na %s', $requestedBibNumber)
                            ),
                        (string)$participant['event_id'],
                        (int)$participant['id'],
                        (string)$participant['display_name'],
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
            }
        }

        if ($email !== null || $fieldValues !== null) {
            $mappings = $loadParticipantFieldMappings($pdo, (string)$participant['event_id']);
            if ($mappings === []) {
                jsonResponse(422, ['error' => 'Edycja danych uczestnika wymaga zapisanego mapowania pól dla wydarzenia']);
                exit;
            }

            $resolvedEmail = $email ?? normalizeEmailAddress((string)$participant['email']);
            if ($resolvedEmail === '' || !isValidEmailAddress($resolvedEmail)) {
                jsonResponse(422, ['error' => 'Wymagany jest prawidłowy adres e-mail']);
                exit;
            }

            $participantFieldValues = $fieldValues ?? ($participant['custom_fields'] ?? []);
            $resolvedData = $buildParticipantDataFromMappings(
                $mappings,
                is_array($participantFieldValues) ? $participantFieldValues : [],
                false,
                (string)($participant['bib_number'] ?? '')
            );
            $resolvedDisplayName = $resolveParticipantDisplayName(
                (string)$resolvedData['display_name'],
                $resolvedEmail,
                (string)($participant['display_name'] ?? ''),
                (string)($participant['email'] ?? '')
            );

            $nameParts = splitParticipantDisplayName($resolvedDisplayName);
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
                'display_name' => $resolvedDisplayName,
                'email' => $resolvedEmail,
                'custom_fields_json' => $resolvedData['custom_fields'] !== [] ? json_encode($resolvedData['custom_fields'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                'email_status' => $emailChanged ? 'not_sent' : (string)$participant['email_status'],
                'id' => (int)$participant['id'],
            ]);

            $participant = $loadParticipantById($pdo, (int)$participant['id']);
            $addActivityLog(
                $pdo,
                'Zaktualizowano dane uczestnika',
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
            jsonResponse(404, ['error' => 'Nie znaleziono uczestnika']);
            exit;
        }

        [$event, $accessError] = $assertParticipantEventAccess($pdo, $authUser, $participant);
        if ($accessError !== null || $event === null) {
            jsonResponse($accessError === 'Brak uprawnień' ? 403 : 422, ['error' => $accessError ?? 'Brak uprawnień']);
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
            jsonResponse(404, ['error' => 'Nie znaleziono uczestnika']);
            exit;
        }

        [$event, $accessError] = $assertParticipantEventAccess($pdo, $authUser, $participant);
        if ($accessError !== null || $event === null) {
            jsonResponse($accessError === 'Brak uprawnień' ? 403 : 422, ['error' => $accessError ?? 'Brak uprawnień']);
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
            jsonResponse(422, ['error' => 'Pola event_id, display_name i email są wymagane']);
            exit;
        }

        if (!isValidEmailAddress($email)) {
            jsonResponse(422, ['error' => 'Adres e-mail musi być prawidłowy']);
            exit;
        }

        $event = $loadEventById($pdo, $eventId);
        if ($event === false) {
            jsonResponse(422, ['error' => 'Nie znaleziono wydarzenia']);
            exit;
        }

        if (!$canManageEventParticipants($authUser, $event)) {
            jsonResponse(403, ['error' => 'Brak uprawnień']);
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
            jsonResponse(404, ['error' => 'Nie znaleziono uczestnika']);
            exit;
        }

        [$event, $accessError] = $assertParticipantEventAccess($pdo, $authUser, $participant);
        if ($accessError !== null || $event === null) {
            jsonResponse($accessError === 'Brak uprawnień' ? 403 : 422, ['error' => $accessError ?? 'Brak uprawnień']);
            exit;
        }

        jsonResponse(200, ['data' => $participant]);
        exit;
    }

    if (preg_match('#^/participants/(\d+)$#', $path, $matches) === 1 && $method === 'DELETE') {
        $authUser = requireAnyRole(['superadmin', 'admin', 'editor'], $resolveAuthenticatedUser);
        $participant = $loadParticipantById($pdo, (int)$matches[1]);

        if ($participant === false) {
            jsonResponse(404, ['error' => 'Nie znaleziono uczestnika']);
            exit;
        }

        [$event, $accessError] = $assertParticipantEventAccess($pdo, $authUser, $participant);
        if ($accessError !== null || $event === null || !$canManageEventParticipants($authUser, $event)) {
            jsonResponse($accessError === 'Brak uprawnień' ? 403 : 422, ['error' => $accessError ?? 'Brak uprawnień']);
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

    jsonResponse(404, ['error' => 'Nie znaleziono endpointu']);
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
