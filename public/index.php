<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

registerApplicationLogging();
setCorsHeaders();
validateServerSecurityConfiguration();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($path === '/openapi.json' && $method === 'GET') {
    if (!isApiDocumentationEnabled()) {
        jsonResponse(404, ['error' => 'Nie znaleziono endpointu']);
        exit;
    }

    jsonResponse(200, openApiDocument());
    exit;
}

if (($path === '/docs' || $path === '/docs/') && $method === 'GET') {
    if (!isApiDocumentationEnabled()) {
        jsonResponse(404, ['error' => 'Nie znaleziono endpointu']);
        exit;
    }

    htmlResponse(200, swaggerUiPage());
    exit;
}

try {
    $pdo = Database::connect();

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
              AND e.deleted_at IS NULL
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
                DATE_FORMAT(archived_at, "%Y-%m-%dT%H:%i:%s") AS archived_at,
                DATE_FORMAT(deleted_at, "%Y-%m-%dT%H:%i:%s") AS deleted_at
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
                DATE_FORMAT(archived_at, "%Y-%m-%dT%H:%i:%s") AS archived_at,
                DATE_FORMAT(deleted_at, "%Y-%m-%dT%H:%i:%s") AS deleted_at
            FROM events
            WHERE archived_at IS NULL
              AND deleted_at IS NULL
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
                DATE_FORMAT(archived_at, "%Y-%m-%dT%H:%i:%s") AS archived_at,
                DATE_FORMAT(deleted_at, "%Y-%m-%dT%H:%i:%s") AS deleted_at
            FROM events
            WHERE archived_at IS NOT NULL
              AND deleted_at IS NULL
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

    $csvDelimiter = ';';
    $writeCsvRow = static function ($stream, array $row) use ($csvDelimiter): void {
        fputcsv($stream, $row, $csvDelimiter);
    };

    $buildExportFilename = static function (array $event, string $exportType): string {
        $eventName = trim((string)($event['name'] ?? 'wydarzenie'));
        $eventName = strtr($eventName, ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z', 'Ą' => 'A', 'Ć' => 'C', 'Ę' => 'E', 'Ł' => 'L', 'Ń' => 'N', 'Ó' => 'O', 'Ś' => 'S', 'Ź' => 'Z', 'Ż' => 'Z']);
        $asciiName = function_exists('iconv') ? iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $eventName) : false;
        $normalizedName = $asciiName !== false ? (string)$asciiName : $eventName;
        $slug = strtolower((string)preg_replace('/[^a-zA-Z0-9]+/', '-', $normalizedName));
        $slug = trim((string)preg_replace('/-+/', '-', $slug), '-');
        if ($slug === '') {
            $slug = 'wydarzenie';
        }

        $slug = trim(substr($slug, 0, 48), '-');
        $date = gmdate('Ymd');

        return sprintf('bz-%s-%s-%s.csv', $exportType, $slug === '' ? 'wydarzenie' : $slug, $date);
    };

    $setCsvDownloadHeaders = static function (string $filename): void {
        header('Content-Type: text/csv; charset=UTF-8');
        header(sprintf(
            'Content-Disposition: attachment; filename="%s"; filename*=UTF-8\'\'%s',
            addcslashes($filename, '\\"'),
            rawurlencode($filename)
        ));
    };

    $translateExportValue = static function (string $value, string $type) use ($participantStatuses): string {
        $maps = [
            'participant_status' => array_map(
                static fn(array $status): string => (string)$status['label'],
                $participantStatuses
            ),
            'email_status' => [
                'not_sent' => 'Niewysłany',
                'sent' => 'Wysłany',
            ],
            'change_type' => [
                'added' => 'Dodano',
                'updated' => 'Zmieniono',
                'deleted' => 'Usunięto',
                'added_then_deleted' => 'Dodano, a następnie usunięto',
            ],
            'change_source' => [
                'csv_import' => 'Import CSV',
                'manual' => 'Dodanie ręczne',
                'participant_edit' => 'Edycja uczestnika',
                'participant_delete' => 'Usunięcie uczestnika',
                'bib_conflict_resolution' => 'Rozwiązanie konfliktu numeru startowego',
            ],
            'current_state' => [
                'active' => 'Aktywny',
                'deleted' => 'Usunięty',
            ],
            'changed_field' => [
                'participant_record' => 'rekord uczestnika',
                'display_name' => 'imię i nazwisko',
                'first_name' => 'imię',
                'last_name' => 'nazwisko',
                'email' => 'e-mail',
                'organization' => 'organizacja',
                'bib_number' => 'numer startowy',
                'status' => 'status',
                'email_status' => 'status e-maila',
                'checked_in_at' => 'czas odprawy',
            ],
        ];

        return $maps[$type][$value] ?? $value;
    };

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

    $createPasswordResetToken = static function (PDO $pdo, string $userId, int $ttlSeconds = PASSWORD_RESET_TTL_SECONDS) use ($invalidatePasswordResetTokensForUser): string {
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
              AND deleted_at IS NULL
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
    $isDeletedEvent = static fn(array $event): bool => trim((string)($event['deleted_at'] ?? '')) !== '';

    $isEventOfficeOpenNow = static function (array $event): bool {
        $openAt = parseLocalDateTimeString((string)($event['office_open_at'] ?? ''));
        $closeAt = parseLocalDateTimeString((string)($event['office_close_at'] ?? ''));
        if ($openAt === null || $closeAt === null) {
            return false;
        }

        $now = new DateTimeImmutable();
        return $now >= $openAt && $now <= $closeAt;
    };

    $isEventFinished = static function (array $event): bool {
        $closeAt = parseLocalDateTimeString((string)($event['office_close_at'] ?? ''));
        if ($closeAt === null) {
            return false;
        }

        return new DateTimeImmutable() > $closeAt;
    };

    $canManageEventParticipants = static function (array $authUser, array $event) use ($canAccessOrganization, $isArchivedEvent, $isDeletedEvent, $isEventOfficeOpenNow): bool {
        if (in_array($authUser['role'], ['superadmin', 'admin', 'editor', 'scanner_plus'], true) === false) {
            return false;
        }

        if ($isDeletedEvent($event)) {
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
            'Otwarcie: %s %s, zamknięcie: %s %s',
            $openAt->format('d.m.Y'),
            $openAt->format('H:i'),
            $closeAt->format('d.m.Y'),
            $closeAt->format('H:i')
        );
    };

    $canAccessEvent = static function (array $authUser, array $event) use ($canAccessOrganization, $isDeletedEvent, $isEventOfficeOpenNow, $isArchivedEvent, $isScannerRole): bool {
        if ($isDeletedEvent($event)) {
            return false;
        }

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

    $canViewArchivedEvent = static function (array $authUser, array $event) use ($canAccessOrganization, $isArchivedEvent, $isDeletedEvent): bool {
        if (!$isArchivedEvent($event)) {
            return false;
        }

        if ($isDeletedEvent($event)) {
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

        addAuditLog($pdo, [
            'action' => $action,
            'actor_user_id' => $userId,
            'actor_name' => $userName,
            'event_id' => $eventId,
            'participant_id' => $participantId,
            'target_type' => $participantId !== null ? 'participant' : ($eventId !== null ? 'event' : null),
            'target_id' => $participantId !== null ? (string)$participantId : $eventId,
            'metadata' => [
                'participant_name' => $participantName,
                'legacy_activity_log' => true,
            ],
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
        $payloadJson = encodeJsonValue($payload);
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
              AND deleted_at IS NULL
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
                  AND deleted_at IS NULL
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

    $participantImportantFieldsMetaKey = '__important_field_aliases';

    $normalizeParticipantImportantFieldAliases = static function (array $aliases): array {
        $normalizedAliases = [];

        foreach ($aliases as $alias) {
            if (!is_string($alias)) {
                continue;
            }

            $trimmedAlias = trim($alias);
            if ($trimmedAlias === '') {
                continue;
            }

            $normalizedAliases[] = $trimmedAlias;
        }

        return array_values(array_unique($normalizedAliases));
    };

    $extractParticipantFieldMetadata = static function (array $decodedCustomFields) use ($participantImportantFieldsMetaKey, $normalizeParticipantImportantFieldAliases): array {
        $importantFieldAliases = $normalizeParticipantImportantFieldAliases(
            is_array($decodedCustomFields[$participantImportantFieldsMetaKey] ?? null)
                ? $decodedCustomFields[$participantImportantFieldsMetaKey]
                : []
        );

        unset($decodedCustomFields[$participantImportantFieldsMetaKey]);

        $customFields = [];
        foreach ($decodedCustomFields as $fieldName => $value) {
            $normalizedFieldName = trim((string)$fieldName);
            if ($normalizedFieldName === '') {
                continue;
            }

            $customFields[$normalizedFieldName] = trim((string)$value);
        }

        return [
            'custom_fields' => $customFields,
            'important_field_aliases' => $importantFieldAliases,
        ];
    };

    $encodeParticipantCustomFields = static function (array $customFields, array $importantFieldAliases) use ($participantImportantFieldsMetaKey, $normalizeParticipantImportantFieldAliases): array {
        $normalizedCustomFields = [];
        foreach ($customFields as $fieldName => $value) {
            $normalizedFieldName = trim((string)$fieldName);
            if ($normalizedFieldName === '') {
                continue;
            }

            $normalizedCustomFields[$normalizedFieldName] = trim((string)$value);
        }

        $normalizedImportantFieldAliases = $normalizeParticipantImportantFieldAliases($importantFieldAliases);
        if ($normalizedImportantFieldAliases !== []) {
            $normalizedCustomFields[$participantImportantFieldsMetaKey] = $normalizedImportantFieldAliases;
        }

        return $normalizedCustomFields;
    };

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
        $importantFieldAliases = [];

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
            if ($mapping['field_role'] === 'important_custom') {
                $importantFieldAliases[] = $alias;
            }
        }

        return [
            'display_name' => trim(implode(' ', $displayNameParts)),
            'custom_fields' => $customFields,
            'bib_number' => $bibNumber,
            'missing_fields' => $missingFields,
            'important_field_aliases' => $importantFieldAliases,
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

    $loadParticipantById = static function (PDO $pdo, int $participantId) use (&$ensureParticipantQrCode, $extractParticipantFieldMetadata): array|false {
        $stmt = $pdo->prepare('
            SELECT
                id,
                event_id,
                participant_audit_key,
                baseline_import_record_id,
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

        $decodedCustomFields = decodeJsonObject($participant['custom_fields_json'] ?? null);
        $participantFieldMetadata = $extractParticipantFieldMetadata($decodedCustomFields);
        $participant['custom_fields'] = $participantFieldMetadata['custom_fields'];
        $participant['important_field_aliases'] = $participantFieldMetadata['important_field_aliases'];
        unset($participant['custom_fields_json']);

        return $ensureParticipantQrCode($pdo, $participant);
    };

    $loadParticipantByQrCode = static function (PDO $pdo, string $qrCode) use (&$ensureParticipantQrCode, $extractParticipantFieldMetadata): array|false {
        $stmt = $pdo->prepare('
            SELECT
                id,
                event_id,
                participant_audit_key,
                baseline_import_record_id,
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

        $decodedCustomFields = decodeJsonObject($participant['custom_fields_json'] ?? null);
        $participantFieldMetadata = $extractParticipantFieldMetadata($decodedCustomFields);
        $participant['custom_fields'] = $participantFieldMetadata['custom_fields'];
        $participant['important_field_aliases'] = $participantFieldMetadata['important_field_aliases'];
        unset($participant['custom_fields_json']);

        return $ensureParticipantQrCode($pdo, $participant);
    };

    $loadParticipantsByEventId = static function (PDO $pdo, string $eventId) use (&$ensureParticipantQrCode, $extractParticipantFieldMetadata): array {
        $stmt = $pdo->prepare('
            SELECT
                id,
                event_id,
                participant_audit_key,
                baseline_import_record_id,
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
            $decodedCustomFields = decodeJsonObject($participant['custom_fields_json'] ?? null);
            $participantFieldMetadata = $extractParticipantFieldMetadata($decodedCustomFields);
            $participant['custom_fields'] = $participantFieldMetadata['custom_fields'];
            $participant['important_field_aliases'] = $participantFieldMetadata['important_field_aliases'];
            unset($participant['custom_fields_json']);
            $participant = $ensureParticipantQrCode($pdo, $participant);
        }
        unset($participant);

        return $participants;
    };

    $loadParticipantsByEventIds = static function (PDO $pdo, array $eventIds) use (&$ensureParticipantQrCode, $extractParticipantFieldMetadata): array {
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
                participant_audit_key,
                baseline_import_record_id,
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
            $decodedCustomFields = decodeJsonObject($participant['custom_fields_json'] ?? null);
            $participantFieldMetadata = $extractParticipantFieldMetadata($decodedCustomFields);
            $participant['custom_fields'] = $participantFieldMetadata['custom_fields'];
            $participant['important_field_aliases'] = $participantFieldMetadata['important_field_aliases'];
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
                'custom_fields' => $participant['custom_fields'] ?? [],
                'important_field_aliases' => $participant['important_field_aliases'] ?? [],
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

    $normalizeParticipantChangeState = static function (array $participant) use (
        $extractParticipantFieldMetadata,
        $normalizeParticipantImportantFieldAliases,
        $normalizeParticipantBibNumber
    ): array {
        $customFields = [];
        $importantFieldAliases = [];

        if (isset($participant['custom_fields']) && is_array($participant['custom_fields'])) {
            foreach ($participant['custom_fields'] as $fieldName => $value) {
                $normalizedFieldName = trim((string)$fieldName);
                if ($normalizedFieldName === '') {
                    continue;
                }

                $customFields[$normalizedFieldName] = trim((string)$value);
            }
        } else {
            $participantFieldMetadata = $extractParticipantFieldMetadata(
                decodeJsonObject(isset($participant['custom_fields_json']) ? (string)$participant['custom_fields_json'] : null)
            );
            $customFields = $participantFieldMetadata['custom_fields'];
            $importantFieldAliases = $participantFieldMetadata['important_field_aliases'];
        }

        if (isset($participant['important_field_aliases']) && is_array($participant['important_field_aliases'])) {
            $importantFieldAliases = $normalizeParticipantImportantFieldAliases($participant['important_field_aliases']);
        }

        ksort($customFields);

        return [
            'display_name' => trim((string)($participant['display_name'] ?? '')),
            'email' => normalizeEmailAddress((string)($participant['email'] ?? '')),
            'bib_number' => $normalizeParticipantBibNumber($participant['bib_number'] ?? null),
            'custom_fields' => $customFields,
            'important_field_aliases' => $importantFieldAliases,
        ];
    };

    $diffParticipantChangeStates = static function (array $beforeState, array $afterState): array {
        $changedFields = [];

        foreach (['display_name', 'email', 'bib_number'] as $fieldName) {
            if (($beforeState[$fieldName] ?? null) !== ($afterState[$fieldName] ?? null)) {
                $changedFields[] = $fieldName;
            }
        }

        $beforeCustomFields = is_array($beforeState['custom_fields'] ?? null) ? $beforeState['custom_fields'] : [];
        $afterCustomFields = is_array($afterState['custom_fields'] ?? null) ? $afterState['custom_fields'] : [];
        $customFieldAliases = array_values(array_unique(array_merge(array_keys($beforeCustomFields), array_keys($afterCustomFields))));
        sort($customFieldAliases);

        foreach ($customFieldAliases as $alias) {
            $beforeValue = trim((string)($beforeCustomFields[$alias] ?? ''));
            $afterValue = trim((string)($afterCustomFields[$alias] ?? ''));
            if ($beforeValue !== $afterValue) {
                $changedFields[] = $alias;
            }
        }

        return $changedFields;
    };

    $eventHasParticipantImportBaseline = static function (PDO $pdo, string $eventId): bool {
        $stmt = $pdo->prepare('
            SELECT COUNT(*) AS total
            FROM event_participant_import_baseline_records
            WHERE event_id = :event_id
        ');
        $stmt->execute(['event_id' => $eventId]);

        return (int)($stmt->fetch()['total'] ?? 0) > 0;
    };

    $createParticipantImportBaselineRecord = static function (
        PDO $pdo,
        string $eventId,
        string $participantAuditKey,
        array $participantState,
        ?int $sourceRowNumber = null
    ) use ($encodeParticipantCustomFields): int {
        $encodedCustomFields = $encodeParticipantCustomFields(
            is_array($participantState['custom_fields'] ?? null) ? $participantState['custom_fields'] : [],
            is_array($participantState['important_field_aliases'] ?? null) ? $participantState['important_field_aliases'] : []
        );
        $stmt = $pdo->prepare('
            INSERT INTO event_participant_import_baseline_records (
                event_id,
                participant_audit_key,
                source_row_number,
                display_name,
                email,
                bib_number,
                custom_fields_json
            ) VALUES (
                :event_id,
                :participant_audit_key,
                :source_row_number,
                :display_name,
                :email,
                :bib_number,
                :custom_fields_json
            )
        ');
        $stmt->bindValue(':event_id', $eventId);
        $stmt->bindValue(':participant_audit_key', $participantAuditKey);
        $stmt->bindValue(':source_row_number', $sourceRowNumber, $sourceRowNumber === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':display_name', trim((string)($participantState['display_name'] ?? '')));
        $stmt->bindValue(':email', normalizeEmailAddress((string)($participantState['email'] ?? '')));
        $stmt->bindValue(':bib_number', $participantState['bib_number'] ?? null, ($participantState['bib_number'] ?? null) === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':custom_fields_json', $encodedCustomFields !== [] ? encodeJsonValue($encodedCustomFields) : null, $encodedCustomFields !== [] ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->execute();

        return (int)$pdo->lastInsertId();
    };

    $attachParticipantBaselineRecord = static function (PDO $pdo, int $participantId, int $baselineRecordId): void {
        $stmt = $pdo->prepare('
            UPDATE participants
            SET baseline_import_record_id = :baseline_import_record_id
            WHERE id = :id
        ');
        $stmt->execute([
            'baseline_import_record_id' => $baselineRecordId,
            'id' => $participantId,
        ]);
    };

    $addParticipantChangeLog = static function (
        PDO $pdo,
        string $eventId,
        string $participantAuditKey,
        ?int $baselineRecordId,
        ?int $participantId,
        string $changeType,
        string $changeSource,
        array $beforeState,
        array $afterState,
        array $changedFields,
        ?string $userId = null,
        ?string $userName = null
    ): void {
        $auditLogId = addAuditLog($pdo, [
            'category' => 'participants',
            'action_code' => 'participant.change.' . $changeType,
            'action' => 'Zmiana uczestnika: ' . $changeType,
            'actor_user_id' => $userId,
            'actor_name' => $userName,
            'target_type' => 'participant',
            'target_id' => $participantId !== null ? (string)$participantId : $participantAuditKey,
            'event_id' => $eventId,
            'participant_id' => $participantId,
            'metadata' => [
                'change_source' => $changeSource,
                'changed_fields' => array_values($changedFields),
                'participant_audit_key' => $participantAuditKey,
            ],
        ]);
        $stmt = $pdo->prepare('
            INSERT INTO event_participant_change_logs (
                id,
                audit_log_id,
                event_id,
                participant_audit_key,
                baseline_record_id,
                participant_id,
                change_type,
                change_source,
                changed_fields_json,
                before_state_json,
                after_state_json,
                user_id,
                user_name_snapshot
            ) VALUES (
                :id,
                :audit_log_id,
                :event_id,
                :participant_audit_key,
                :baseline_record_id,
                :participant_id,
                :change_type,
                :change_source,
                :changed_fields_json,
                :before_state_json,
                :after_state_json,
                :user_id,
                :user_name_snapshot
            )
        ');
        $stmt->bindValue(':id', 'pcl-' . bin2hex(random_bytes(8)));
        $stmt->bindValue(':audit_log_id', $auditLogId);
        $stmt->bindValue(':event_id', $eventId);
        $stmt->bindValue(':participant_audit_key', $participantAuditKey);
        $stmt->bindValue(':baseline_record_id', $baselineRecordId, $baselineRecordId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':participant_id', $participantId, $participantId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':change_type', $changeType);
        $stmt->bindValue(':change_source', $changeSource);
        $stmt->bindValue(':changed_fields_json', $changedFields !== [] ? encodeJsonValue(array_values($changedFields)) : null, $changedFields !== [] ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':before_state_json', $beforeState !== [] ? encodeJsonValue($beforeState) : null, $beforeState !== [] ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':after_state_json', $afterState !== [] ? encodeJsonValue($afterState) : null, $afterState !== [] ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':user_id', $userId, $userId === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':user_name_snapshot', $userName, $userName === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->execute();
    };

    $loadParticipantImportBaselineRecords = static function (PDO $pdo, string $eventId) use ($normalizeParticipantChangeState): array {
        $stmt = $pdo->prepare('
            SELECT
                id,
                event_id,
                participant_audit_key,
                source_row_number,
                display_name,
                email,
                bib_number,
                custom_fields_json,
                created_at
            FROM event_participant_import_baseline_records
            WHERE event_id = :event_id
            ORDER BY id ASC
        ');
        $stmt->execute(['event_id' => $eventId]);

        return array_map(static function (array $row) use ($normalizeParticipantChangeState): array {
            $row['state'] = $normalizeParticipantChangeState($row);
            return $row;
        }, $stmt->fetchAll());
    };

    $loadParticipantChangeLogsByEvent = static function (PDO $pdo, string $eventId): array {
        $stmt = $pdo->prepare('
            SELECT
                id,
                event_id,
                participant_audit_key,
                baseline_record_id,
                participant_id,
                change_type,
                change_source,
                changed_fields_json,
                before_state_json,
                after_state_json,
                user_id,
                user_name_snapshot,
                created_at
            FROM event_participant_change_logs
            WHERE event_id = :event_id
            ORDER BY created_at ASC, id ASC
        ');
        $stmt->execute(['event_id' => $eventId]);

        return array_map(static function (array $row): array {
            return [
                ...$row,
                'baseline_record_id' => isset($row['baseline_record_id']) ? (int)$row['baseline_record_id'] : null,
                'participant_id' => isset($row['participant_id']) ? (int)$row['participant_id'] : null,
                'changed_fields' => array_values(array_filter(
                    array_map(static fn(mixed $fieldName): string => trim((string)$fieldName), decodeJsonObject($row['changed_fields_json'] ?? null)),
                    static fn(string $fieldName): bool => $fieldName !== ''
                )),
                'before_state' => decodeJsonObject($row['before_state_json'] ?? null),
                'after_state' => decodeJsonObject($row['after_state_json'] ?? null),
            ];
        }, $stmt->fetchAll());
    };

    $loadParticipantFieldMappingState = static function (PDO $pdo, string $eventId) use ($loadParticipantFieldMappings, $eventHasParticipantImportBaseline): array {
        return [
            'has_mapping' => $loadParticipantFieldMappings($pdo, $eventId) !== [],
            'has_baseline_import' => $eventHasParticipantImportBaseline($pdo, $eventId),
        ];
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
    ) use (&$ensureParticipantQrCode, $extractParticipantFieldMetadata): array {
        $stmt = $pdo->prepare('
            SELECT
                id,
                event_id,
                participant_audit_key,
                baseline_import_record_id,
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
            $decodedCustomFields = decodeJsonObject($participant['custom_fields_json'] ?? null);
            $participantFieldMetadata = $extractParticipantFieldMetadata($decodedCustomFields);
            $participant['custom_fields'] = $participantFieldMetadata['custom_fields'];
            $participant['important_field_aliases'] = $participantFieldMetadata['important_field_aliases'];
            unset($participant['custom_fields_json']);
            $participant = $ensureParticipantQrCode($pdo, $participant);
        }
        unset($participant);

        return $participants;
    };

    $normalizeImportHeader = static function (string $header): string {
        $normalized = trim(normalizeUtf8String($header));
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
        $normalizedContent = normalizeUtf8String($csvContent);
        $normalized = preg_replace('/^\xEF\xBB\xBF/u', '', $normalizedContent) ?? $normalizedContent;
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
                $row[$header] = trim(normalizeUtf8String((string)($values[$index] ?? '')));
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
        $importantFieldAliases = [];

        foreach ($mappings as $mapping) {
            if (!($mapping['is_active'] ?? true)) {
                continue;
            }

            $role = (string)$mapping['field_role'];
            if (!in_array($role, ['display_name_part', 'bib_number', 'custom', 'important_custom'], true)) {
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
            if ($role === 'important_custom') {
                $importantFieldAliases[] = $alias;
            }
        }

        return [
            'custom_fields' => $customFields,
            'important_field_aliases' => $importantFieldAliases,
        ];
    };

    $insertParticipantRecord = static function (
        PDO $pdo,
        string $eventId,
        string $displayName,
        string $email,
        ?string $bibNumber,
        array $customFields,
        string $organization = '',
        ?string $qrCode = null,
        ?string $participantAuditKey = null,
        ?int $baselineImportRecordId = null
    ) use ($loadParticipantById, $generateUniqueParticipantQrCode, $encodeParticipantCustomFields): array {
        $nameParts = splitParticipantDisplayName($displayName);
        $resolvedBibNumber = $bibNumber !== null && trim($bibNumber) !== ''
            ? trim($bibNumber)
            : null;
        $resolvedQrCode = $qrCode !== null && QrCodeService::isSecureToken(trim($qrCode))
            ? trim($qrCode)
            : $generateUniqueParticipantQrCode($pdo);
        $resolvedImportantFieldAliases = [];
        if (isset($customFields['important_field_aliases']) && is_array($customFields['important_field_aliases'])) {
            $resolvedImportantFieldAliases = $customFields['important_field_aliases'];
            unset($customFields['important_field_aliases']);
        }
        $encodedCustomFields = $encodeParticipantCustomFields($customFields, $resolvedImportantFieldAliases);
        $resolvedParticipantAuditKey = is_string($participantAuditKey) && trim($participantAuditKey) !== ''
            ? trim($participantAuditKey)
            : 'pa-' . bin2hex(random_bytes(12));

        $stmt = $pdo->prepare('
            INSERT INTO participants (
                event_id,
                participant_audit_key,
                baseline_import_record_id,
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
                :participant_audit_key,
                :baseline_import_record_id,
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
            'participant_audit_key' => $resolvedParticipantAuditKey,
            'baseline_import_record_id' => $baselineImportRecordId,
            'first_name' => $nameParts['first_name'],
            'last_name' => $nameParts['last_name'],
            'display_name' => $displayName,
            'email' => $email,
            'organization' => $organization !== '' ? $organization : null,
            'bib_number' => $resolvedBibNumber,
            'qr_code' => $resolvedQrCode,
            'custom_fields_json' => $encodedCustomFields !== [] ? encodeJsonValue($encodedCustomFields) : null,
            'status' => 'not_checked_in',
            'email_status' => 'not_sent',
        ]);

        $participantId = (int)$pdo->lastInsertId();
        return $loadParticipantById($pdo, $participantId) ?: [];
    };

    $buildParticipantImportMappingsFromInput = static function (array $input): array {
        $csvColumns = array_values(array_filter(
            is_array($input['csv_columns'] ?? null) ? $input['csv_columns'] : [],
            static fn(mixed $column): bool => is_string($column) && trim($column) !== ''
        ));
        $emailColumn = trim((string)($input['email_column'] ?? ''));
        $fields = is_array($input['fields'] ?? null) ? $input['fields'] : [];

        if ($csvColumns === [] || $emailColumn === '') {
            return [null, 'Pola csv_columns i email_column są wymagane'];
        }

        if (!in_array($emailColumn, $csvColumns, true)) {
            return [null, 'Pole email_column musi wskazywać istniejącą kolumnę CSV'];
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
                return [null, sprintf('Alias jest wymagany dla aktywnej kolumny "%s"', $sourceColumnName)];
            }

            if (!in_array($fieldRole, ['display_name_part', 'bib_number', 'custom', 'important_custom'], true)) {
                return [null, sprintf('Nieprawidłowa rola pola dla kolumny "%s"', $sourceColumnName)];
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
            return [null, 'Wymagane jest co najmniej jedno aktywne mapowanie display_name_part'];
        }

        return [$mappingsToSave, null];
    };

    $insertParticipantImportMappings = static function (PDO $pdo, string $eventId, array $mappingsToSave): void {
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
    };

    $normalizeMappingDisplayOrder = static function (mixed $value, int $fallback): int {
        if (is_int($value)) {
            return max(0, $value);
        }

        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            return max(0, (int)$value);
        }

        return $fallback;
    };

    $encodeRenamedCustomFields = static function (?string $customFieldsJson, string $oldAlias, string $newAlias) use ($participantImportantFieldsMetaKey, $normalizeParticipantImportantFieldAliases): array {
        $decodedCustomFields = decodeJsonObject($customFieldsJson);
        if ($decodedCustomFields === [] || $oldAlias === $newAlias) {
            return [$customFieldsJson, false, null];
        }

        $changed = false;
        if (array_key_exists($oldAlias, $decodedCustomFields)) {
            if (array_key_exists($newAlias, $decodedCustomFields)) {
                return [null, false, sprintf('Nie można zmienić aliasu "%s" na "%s", bo uczestnik ma już dane pod nową nazwą.', $oldAlias, $newAlias)];
            }

            $decodedCustomFields[$newAlias] = $decodedCustomFields[$oldAlias];
            unset($decodedCustomFields[$oldAlias]);
            $changed = true;
        }

        $importantAliases = $normalizeParticipantImportantFieldAliases(
            is_array($decodedCustomFields[$participantImportantFieldsMetaKey] ?? null)
                ? $decodedCustomFields[$participantImportantFieldsMetaKey]
                : []
        );
        if ($importantAliases !== []) {
            $renamedAliases = array_values(array_unique(array_map(
                static fn(string $alias): string => $alias === $oldAlias ? $newAlias : $alias,
                $importantAliases
            )));
            if ($renamedAliases !== $importantAliases) {
                $changed = true;
            }

            if ($renamedAliases !== []) {
                $decodedCustomFields[$participantImportantFieldsMetaKey] = $renamedAliases;
            } else {
                unset($decodedCustomFields[$participantImportantFieldsMetaKey]);
            }
        }

        if (!$changed) {
            return [$customFieldsJson, false, null];
        }

        return [$decodedCustomFields !== [] ? encodeJsonValue($decodedCustomFields) : null, true, null];
    };

    $renameParticipantMappingAliasReferences = static function (PDO $pdo, string $eventId, string $oldAlias, string $newAlias) use ($encodeRenamedCustomFields): ?string {
        if ($oldAlias === $newAlias) {
            return null;
        }

        $participantStmt = $pdo->prepare('
            SELECT id, custom_fields_json
            FROM participants
            WHERE event_id = :event_id
        ');
        $participantStmt->execute(['event_id' => $eventId]);
        $participantUpdates = [];
        foreach ($participantStmt->fetchAll() as $participantRow) {
            [$encodedCustomFields, $changed, $error] = $encodeRenamedCustomFields(
                isset($participantRow['custom_fields_json']) ? (string)$participantRow['custom_fields_json'] : null,
                $oldAlias,
                $newAlias
            );
            if ($error !== null) {
                return $error;
            }

            if ($changed) {
                $participantUpdates[] = [
                    'id' => (int)$participantRow['id'],
                    'custom_fields_json' => $encodedCustomFields,
                ];
            }
        }

        $baselineStmt = $pdo->prepare('
            SELECT id, custom_fields_json
            FROM event_participant_import_baseline_records
            WHERE event_id = :event_id
        ');
        $baselineStmt->execute(['event_id' => $eventId]);
        $baselineUpdates = [];
        foreach ($baselineStmt->fetchAll() as $baselineRow) {
            [$encodedCustomFields, $changed, $error] = $encodeRenamedCustomFields(
                isset($baselineRow['custom_fields_json']) ? (string)$baselineRow['custom_fields_json'] : null,
                $oldAlias,
                $newAlias
            );
            if ($error !== null) {
                return $error;
            }

            if ($changed) {
                $baselineUpdates[] = [
                    'id' => (int)$baselineRow['id'],
                    'custom_fields_json' => $encodedCustomFields,
                ];
            }
        }

        $updateParticipantStmt = $pdo->prepare('
            UPDATE participants
            SET custom_fields_json = :custom_fields_json
            WHERE id = :id
        ');
        foreach ($participantUpdates as $update) {
            $updateParticipantStmt->bindValue(':id', $update['id'], PDO::PARAM_INT);
            $updateParticipantStmt->bindValue(':custom_fields_json', $update['custom_fields_json'], $update['custom_fields_json'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $updateParticipantStmt->execute();
        }

        $updateBaselineStmt = $pdo->prepare('
            UPDATE event_participant_import_baseline_records
            SET custom_fields_json = :custom_fields_json
            WHERE id = :id
        ');
        foreach ($baselineUpdates as $update) {
            $updateBaselineStmt->bindValue(':id', $update['id'], PDO::PARAM_INT);
            $updateBaselineStmt->bindValue(':custom_fields_json', $update['custom_fields_json'], $update['custom_fields_json'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $updateBaselineStmt->execute();
        }

        return null;
    };

    $buildParticipantFieldMappingUpdatesFromInput = static function (array $existingMappings, array $input) use ($normalizeMappingDisplayOrder): array {
        $inputMappings = is_array($input['mappings'] ?? null) ? $input['mappings'] : [];
        if ($inputMappings === []) {
            return [null, 'Pole mappings jest wymagane'];
        }

        $existingBySourceColumn = [];
        foreach ($existingMappings as $mapping) {
            $existingBySourceColumn[(string)$mapping['source_column_name']] = $mapping;
        }

        $updatesBySourceColumn = [];
        $seenInputSourceColumns = [];
        foreach ($inputMappings as $index => $inputMapping) {
            if (!is_array($inputMapping)) {
                continue;
            }

            $sourceColumnName = trim((string)($inputMapping['source_column_name'] ?? ''));
            if ($sourceColumnName === '' || !isset($existingBySourceColumn[$sourceColumnName])) {
                return [null, sprintf('Nie można dodać ani zmienić kolumny źródłowej "%s"', $sourceColumnName)];
            }

            if (isset($seenInputSourceColumns[$sourceColumnName])) {
                return [null, sprintf('Kolumna "%s" występuje w żądaniu więcej niż raz', $sourceColumnName)];
            }
            $seenInputSourceColumns[$sourceColumnName] = true;

            $existingMapping = $existingBySourceColumn[$sourceColumnName];
            $currentRole = (string)$existingMapping['field_role'];
            $nextRole = trim((string)($inputMapping['field_role'] ?? $currentRole));
            $nextAlias = trim((string)($inputMapping['alias'] ?? $existingMapping['alias']));
            $nextIsActive = array_key_exists('is_active', $inputMapping)
                ? (bool)$inputMapping['is_active']
                : (bool)$existingMapping['is_active'];
            $nextIsRequired = array_key_exists('is_required', $inputMapping)
                ? (bool)$inputMapping['is_required']
                : (bool)$existingMapping['is_required'];
            $nextDisplayOrder = $normalizeMappingDisplayOrder($inputMapping['display_order'] ?? null, $index + 1);

            if ($currentRole === 'email') {
                if ($nextRole !== 'email' || $nextAlias !== (string)$existingMapping['alias'] || !$nextIsActive || !$nextIsRequired) {
                    return [null, 'Mapowanie e-mail jest systemowe i nie może być zmieniane'];
                }

                $updatesBySourceColumn[$sourceColumnName] = [
                    ...$existingMapping,
                    'display_order' => 0,
                    'is_required' => true,
                    'is_active' => true,
                ];
                continue;
            }

            if ($nextAlias === '') {
                return [null, sprintf('Alias jest wymagany dla kolumny "%s"', $sourceColumnName)];
            }

            if (strlen($nextAlias) > 190) {
                return [null, sprintf('Alias dla kolumny "%s" jest zbyt długi', $sourceColumnName)];
            }

            if (in_array($currentRole, ['custom', 'important_custom'], true)) {
                if (!in_array($nextRole, ['custom', 'important_custom'], true)) {
                    return [null, sprintf('Kolumnę "%s" można przełączać tylko między rolą pola dodatkowego i wyróżnionego pola dodatkowego', $sourceColumnName)];
                }
            } elseif ($nextRole !== $currentRole) {
                return [null, sprintf('Rola systemowa kolumny "%s" nie może być zmieniana', $sourceColumnName)];
            }

            if (!$nextIsActive) {
                $nextIsRequired = false;
            } elseif ($nextRole === 'display_name_part') {
                $nextIsRequired = true;
            }

            $updatesBySourceColumn[$sourceColumnName] = [
                'source_column_name' => $sourceColumnName,
                'alias' => $nextAlias,
                'field_role' => $nextRole,
                'display_order' => $nextDisplayOrder,
                'is_required' => $nextIsRequired,
                'is_active' => $nextIsActive,
            ];
        }

        foreach ($existingMappings as $existingMapping) {
            $sourceColumnName = (string)$existingMapping['source_column_name'];
            if (!isset($updatesBySourceColumn[$sourceColumnName])) {
                $updatesBySourceColumn[$sourceColumnName] = $existingMapping;
            }
        }

        $activeDisplayNamePartCount = 0;
        $activeAliases = [];
        foreach ($updatesBySourceColumn as $mapping) {
            if (!($mapping['is_active'] ?? false) || ($mapping['field_role'] ?? '') === 'email') {
                continue;
            }

            if (($mapping['field_role'] ?? '') === 'display_name_part') {
                $activeDisplayNamePartCount++;
            }

            $normalizedAlias = strtolower(trim((string)$mapping['alias']));
            if ($normalizedAlias !== '') {
                if (isset($activeAliases[$normalizedAlias])) {
                    return [null, sprintf('Alias "%s" jest użyty więcej niż raz w aktywnym mapowaniu', (string)$mapping['alias'])];
                }
                $activeAliases[$normalizedAlias] = true;
            }
        }

        if ($activeDisplayNamePartCount === 0) {
            return [null, 'Mapowanie musi zawierać co najmniej jedną aktywną kolumnę budującą nazwę uczestnika'];
        }

        return [array_values($updatesBySourceColumn), null];
    };

    $findParticipantEmailMapping = static function (array $mappings): ?array {
        foreach ($mappings as $mapping) {
            if (($mapping['field_role'] ?? '') === 'email') {
                return $mapping;
            }
        }

        return null;
    };

    $buildParticipantListComparisonKey = static function (string $email, string $displayName, ?string $bibNumber): string {
        $emailKey = strtolower(trim($email));
        $bibKey = trim((string)$bibNumber);
        if ($emailKey === '') {
            return '';
        }

        if ($bibKey !== '') {
            return $emailKey . '|bib|' . strtolower($bibKey);
        }

        $displayNameKey = strtolower(trim($displayName));
        return $displayNameKey === '' ? '' : $emailKey . '|name|' . $displayNameKey;
    };

    $buildCsvParticipantComparisonKeys = static function (array $rows, array $mappings) use ($findParticipantEmailMapping, $buildDisplayNameFromMapping, $buildParticipantListComparisonKey): array {
        $emailMapping = $findParticipantEmailMapping($mappings);
        if ($emailMapping === null) {
            return [];
        }

        $keys = [];
        foreach ($rows as $row) {
            $email = normalizeEmailAddress((string)($row[(string)$emailMapping['source_column_name']] ?? ''));
            $displayName = $buildDisplayNameFromMapping($row, $mappings);
            $bibNumber = null;
            foreach ($mappings as $mapping) {
                if (($mapping['field_role'] ?? '') === 'bib_number') {
                    $candidateBib = trim((string)($row[(string)$mapping['source_column_name']] ?? ''));
                    if ($candidateBib !== '') {
                        $bibNumber = $candidateBib;
                    }
                    break;
                }
            }

            $key = $buildParticipantListComparisonKey($email, $displayName, $bibNumber);
            if ($key !== '') {
                $keys[$key] = true;
            }
        }

        return array_keys($keys);
    };

    $loadExistingParticipantComparisonKeys = static function (PDO $pdo, string $eventId) use ($buildParticipantListComparisonKey): array {
        $stmt = $pdo->prepare('
            SELECT email, display_name, bib_number
            FROM participants
            WHERE event_id = :event_id
        ');
        $stmt->execute(['event_id' => $eventId]);

        $keys = [];
        foreach ($stmt->fetchAll() as $row) {
            $key = $buildParticipantListComparisonKey(
                normalizeEmailAddress((string)($row['email'] ?? '')),
                (string)($row['display_name'] ?? ''),
                isset($row['bib_number']) ? (string)$row['bib_number'] : null
            );
            if ($key !== '') {
                $keys[$key] = true;
            }
        }

        return array_keys($keys);
    };

    $countEventParticipantListStats = static function (PDO $pdo, string $eventId): array {
        $stmt = $pdo->prepare("
            SELECT
                COUNT(*) AS participant_count,
                SUM(CASE WHEN email_status = 'sent' THEN 1 ELSE 0 END) AS sent_qr_email_count
            FROM participants
            WHERE event_id = :event_id
        ");
        $stmt->execute(['event_id' => $eventId]);
        $row = $stmt->fetch() ?: [];

        return [
            'participant_count' => (int)($row['participant_count'] ?? 0),
            'sent_qr_email_count' => (int)($row['sent_qr_email_count'] ?? 0),
        ];
    };

    $buildParticipantListDifference = static function (PDO $pdo, string $eventId, array $headers, array $rows, array $mappings) use ($buildCsvParticipantComparisonKeys, $loadExistingParticipantComparisonKeys): array {
        $mappedColumns = array_values(array_unique(array_map(
            static fn(array $mapping): string => (string)$mapping['source_column_name'],
            $mappings
        )));
        sort($mappedColumns);

        $csvColumns = array_values(array_unique(array_map(static fn(mixed $header): string => (string)$header, $headers)));
        sort($csvColumns);

        $missingColumns = array_values(array_diff($mappedColumns, $csvColumns));
        $extraColumns = array_values(array_diff($csvColumns, $mappedColumns));
        $columnsDiffer = $missingColumns !== [] || $extraColumns !== [];

        $existingKeys = $loadExistingParticipantComparisonKeys($pdo, $eventId);
        $csvKeys = $buildCsvParticipantComparisonKeys($rows, $mappings);
        $existingKeySet = array_fill_keys($existingKeys, true);
        $csvKeySet = array_fill_keys($csvKeys, true);
        $removedCount = count(array_diff_key($existingKeySet, $csvKeySet));
        $addedCount = count(array_diff_key($csvKeySet, $existingKeySet));
        $largerListCount = max(count($existingKeySet), count($csvKeySet));
        $participantDifferenceRatio = $largerListCount === 0 ? 0.0 : ($addedCount + $removedCount) / $largerListCount;

        return [
            'columns_differ' => $columnsDiffer,
            'missing_columns' => $missingColumns,
            'extra_columns' => $extraColumns,
            'participant_difference_ratio' => round($participantDifferenceRatio, 4),
            'should_offer_replacement' => $largerListCount > 0 && ($columnsDiffer || $participantDifferenceRatio > 0.5),
        ];
    };

    $deleteEventParticipantList = static function (PDO $pdo, string $eventId, array $authUser) use ($addActivityLog): array {
        $deleteChangeLogsStmt = $pdo->prepare('DELETE FROM event_participant_change_logs WHERE event_id = :event_id');
        $deleteChangeLogsStmt->execute(['event_id' => $eventId]);
        $deletedChangeLogs = $deleteChangeLogsStmt->rowCount();

        $deleteParticipantsStmt = $pdo->prepare('DELETE FROM participants WHERE event_id = :event_id');
        $deleteParticipantsStmt->execute(['event_id' => $eventId]);
        $deletedParticipants = $deleteParticipantsStmt->rowCount();

        $deleteMappingsStmt = $pdo->prepare('DELETE FROM event_participant_field_mappings WHERE event_id = :event_id');
        $deleteMappingsStmt->execute(['event_id' => $eventId]);
        $deletedMappings = $deleteMappingsStmt->rowCount();

        $deleteBaselineStmt = $pdo->prepare('DELETE FROM event_participant_import_baseline_records WHERE event_id = :event_id');
        $deleteBaselineStmt->execute(['event_id' => $eventId]);
        $deletedBaselineRecords = $deleteBaselineStmt->rowCount();

        $addActivityLog(
            $pdo,
            sprintf('Usunięto listę uczestników wydarzenia: uczestnicy %d, mapowania %d', $deletedParticipants, $deletedMappings),
            $eventId,
            null,
            null,
            (string)$authUser['id'],
            (string)$authUser['name']
        );

        return [
            'deleted_participant_count' => $deletedParticipants,
            'deleted_mapping_count' => $deletedMappings,
            'deleted_baseline_record_count' => $deletedBaselineRecords,
            'deleted_change_log_count' => $deletedChangeLogs,
        ];
    };

    $importParticipantsFromRows = static function (
        PDO $pdo,
        string $eventId,
        array $rows,
        array $mappings,
        array $authUser,
        bool $hasBaselineImport
    ) use (
        $findParticipantEmailMapping,
        $buildDisplayNameFromMapping,
        $normalizeCustomFieldsFromMapping,
        $insertParticipantRecord,
        $normalizeParticipantChangeState,
        $createParticipantImportBaselineRecord,
        $attachParticipantBaselineRecord,
        $addParticipantChangeLog
    ): array {
        $emailMapping = $findParticipantEmailMapping($mappings);
        if ($emailMapping === null) {
            return [null, 'Zapisane mapowanie jest nieprawidłowe: brak kolumny e-mail'];
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
        $invalidRowDetails = [];
        $duplicateRowDetails = [];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2;
            $emailColumnName = (string)$emailMapping['source_column_name'];
            $rawEmail = trim((string)($row[$emailColumnName] ?? ''));
            $email = normalizeEmailAddress($rawEmail);
            $displayName = $buildDisplayNameFromMapping($row, $mappings);
            $rowErrors = [];

            if ($rawEmail === '') {
                $rowErrors[] = sprintf('Brak adresu e-mail w kolumnie "%s".', $emailColumnName);
            } elseif (!isValidEmailAddress($email)) {
                $rowErrors[] = sprintf('Nieprawidłowy adres e-mail w kolumnie "%s".', $emailColumnName);
            }

            if ($displayName === '') {
                $displayNameColumns = array_values(array_map(
                    static fn(array $mapping): string => (string)$mapping['source_column_name'],
                    array_filter($mappings, static fn(array $mapping): bool => ($mapping['field_role'] ?? '') === 'display_name_part' && (bool)($mapping['is_active'] ?? true))
                ));
                $rowErrors[] = $displayNameColumns === []
                    ? 'Brak aktywnej kolumny budującej nazwę uczestnika.'
                    : sprintf('Brak danych budujących nazwę uczestnika w kolumnach: %s.', implode(', ', $displayNameColumns));
            }

            if ($rowErrors !== []) {
                $invalidCount++;
                $invalidRows[] = $rowNumber;
                $invalidRowDetails[] = [
                    'row_number' => $rowNumber,
                    'reasons' => $rowErrors,
                    'row' => $row,
                ];
                continue;
            }

            $normalizedEmail = strtolower($email);

            $bibNumber = null;
            foreach ($mappings as $mapping) {
                if (($mapping['field_role'] ?? '') === 'bib_number') {
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
                $duplicateRowDetails[] = [
                    'row_number' => $rowNumber,
                    'reasons' => [
                        $bibDuplicateKey !== null
                            ? 'Uczestnik z tym samym adresem e-mail i numerem startowym już istnieje w wydarzeniu.'
                            : 'Uczestnik z tym samym adresem e-mail i nazwą już istnieje w wydarzeniu.',
                    ],
                    'row' => $row,
                    'matched_by' => $bibDuplicateKey !== null ? 'email_bib_number' : 'email_display_name',
                ];
                continue;
            }

            $customFieldData = $normalizeCustomFieldsFromMapping($row, $mappings);
            $participant = $insertParticipantRecord(
                $pdo,
                $eventId,
                $displayName,
                $email,
                $bibNumber,
                [
                    'important_field_aliases' => $customFieldData['important_field_aliases'] ?? [],
                    ...($customFieldData['custom_fields'] ?? []),
                ]
            );
            if ($participant === []) {
                $invalidCount++;
                $invalidRows[] = $rowNumber;
                $invalidRowDetails[] = [
                    'row_number' => $rowNumber,
                    'reasons' => ['Nie udało się zapisać uczestnika w bazie danych.'],
                    'row' => $row,
                ];
                continue;
            }

            $participantState = $normalizeParticipantChangeState($participant);
            if (!$hasBaselineImport) {
                $baselineRecordId = $createParticipantImportBaselineRecord(
                    $pdo,
                    $eventId,
                    (string)$participant['participant_audit_key'],
                    $participantState,
                    $index + 2
                );
                $attachParticipantBaselineRecord($pdo, (int)$participant['id'], $baselineRecordId);
                $participant['baseline_import_record_id'] = $baselineRecordId;
            } else {
                $addParticipantChangeLog(
                    $pdo,
                    $eventId,
                    (string)$participant['participant_audit_key'],
                    null,
                    (int)$participant['id'],
                    'added',
                    'csv_import',
                    [],
                    $participantState,
                    ['participant_record'],
                    (string)$authUser['id'],
                    (string)$authUser['name']
                );
            }

            $createdParticipants[] = $participant;
            $createdBibNumber = trim((string)($participant['bib_number'] ?? ''));
            if ($createdBibNumber !== '') {
                $existingBibKeys[$normalizedEmail . '|' . $createdBibNumber] = true;
            }
            $existingFallbackKeys[$fallbackKey] = true;
        }

        return [[
            'created_count' => count($createdParticipants),
            'duplicate_count' => $duplicateCount,
            'invalid_count' => $invalidCount,
            'invalid_rows' => $invalidRows,
            'invalid_row_details' => $invalidRowDetails,
            'duplicate_row_details' => $duplicateRowDetails,
            'participants' => $createdParticipants,
        ], null];
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
            addAuditLog($pdo, [
                'category' => 'authentication',
                'action_code' => 'auth.login.rate_limited',
                'action' => 'Zablokowano probe logowania przez limit',
                'outcome' => 'blocked',
                'severity' => 'warning',
                'target_type' => 'user_email',
                'target_id' => $email !== '' ? hash_hmac('sha256', $email, authSecret()) : null,
                'metadata' => ['retry_after' => (int)$loginRateLimit['retry_after']],
            ]);
            jsonResponse(429, [
                'error' => 'Zbyt wiele prób logowania. Spróbuj ponownie później.',
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
            addAuditLog($pdo, [
                'category' => 'authentication',
                'action_code' => 'auth.login.failed',
                'action' => 'Nieudana proba logowania',
                'outcome' => 'failure',
                'severity' => 'warning',
                'target_type' => 'user_email',
                'target_id' => $email !== '' ? hash_hmac('sha256', $email, authSecret()) : null,
            ]);
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
        RequestContext::setUser($user);
        $addActivityLog(
            $pdo,
            sprintf('Zalogowano użytkownika: %s (%s)', (string)$user['name'], (string)$user['email']),
            null,
            null,
            null,
            (string)$user['id'],
            (string)$user['name']
        );
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
                'error' => 'Zbyt wiele prób resetu hasła. Spróbuj ponownie później.',
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
            addAuditLog($pdo, [
                'category' => 'authentication',
                'action_code' => 'auth.password_reset.completed',
                'action' => 'Zmieniono haslo przez link resetu',
                'actor_user_id' => $passwordReset['user_id'],
                'actor_name' => $passwordReset['name'] ?? null,
                'target_type' => 'user',
                'target_id' => $passwordReset['user_id'],
            ]);
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
            addAuditLog($pdo, [
                'category' => 'authentication',
                'action_code' => 'auth.password_change.completed',
                'action' => 'Uzytkownik zmienil swoje haslo',
                'actor_user_id' => $authUser['id'],
                'actor_name' => $authUser['name'],
                'actor_role' => $authUser['role'],
                'target_type' => 'user',
                'target_id' => $authUser['id'],
            ]);
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

    if ($path === '/superadmin/operations' && $method === 'GET') {
        requireAnyRole(['superadmin'], $resolveAuthenticatedUser);

        $alerts = [];
        $qualityIssues = [];
        $addIssue = static function (
            array &$target,
            string $id,
            string $severity,
            string $category,
            string $title,
            string $description,
            int $count,
            array $context = []
        ): void {
            $target[] = array_merge([
                'id' => $id,
                'severity' => $severity,
                'category' => $category,
                'title' => $title,
                'description' => $description,
                'count' => $count,
                'organization_id' => null,
                'organization_name' => null,
                'event_id' => null,
                'event_name' => null,
            ], $context);
        };

        $upcomingEventsStmt = $pdo->query("
            SELECT
                e.id AS event_id,
                e.name AS event_name,
                e.organization_id,
                o.name AS organization_name,
                DATE_FORMAT(e.office_open_at, '%Y-%m-%dT%H:%i:%s') AS office_open_at,
                COALESCE(pc.participant_count, 0) AS participant_count,
                COALESCE(pc.unsent_email_count, 0) AS unsent_email_count,
                COALESCE(oc.operator_count, 0) AS operator_count
            FROM events e
            INNER JOIN organizations o ON o.id = e.organization_id
            LEFT JOIN (
                SELECT
                    event_id,
                    COUNT(*) AS participant_count,
                    SUM(CASE WHEN email_status = 'not_sent' THEN 1 ELSE 0 END) AS unsent_email_count
                FROM participants
                WHERE event_id IS NOT NULL
                GROUP BY event_id
            ) pc ON pc.event_id = e.id
            LEFT JOIN (
                SELECT
                    uea.event_id,
                    COUNT(DISTINCT uea.user_id) AS operator_count
                FROM user_event_assignments uea
                INNER JOIN users u ON u.id = uea.user_id
                WHERE u.archived_at IS NULL
                  AND u.role IN ('scanner', 'scanner_plus')
                GROUP BY uea.event_id
            ) oc ON oc.event_id = e.id
            WHERE e.archived_at IS NULL
              AND e.deleted_at IS NULL
              AND e.office_close_at >= UTC_TIMESTAMP()
              AND e.office_open_at <= DATE_ADD(UTC_TIMESTAMP(), INTERVAL 7 DAY)
            ORDER BY e.office_open_at ASC
        ");

        foreach ($upcomingEventsStmt->fetchAll() as $eventRow) {
            $eventId = (string)$eventRow['event_id'];
            $eventName = (string)$eventRow['event_name'];
            $context = [
                'organization_id' => (string)$eventRow['organization_id'],
                'organization_name' => (string)$eventRow['organization_name'],
                'event_id' => $eventId,
                'event_name' => $eventName,
            ];
            $participantCount = (int)$eventRow['participant_count'];
            $operatorCount = (int)$eventRow['operator_count'];
            $unsentEmailCount = (int)$eventRow['unsent_email_count'];

            if ($participantCount === 0) {
                $addIssue(
                    $alerts,
                    'event-no-participants-' . $eventId,
                    'critical',
                    'event_no_participants',
                    'Wydarzenie bez uczestników',
                    sprintf('%s rozpoczyna pracę biura w ciągu 7 dni i nie ma uczestników.', $eventName),
                    1,
                    $context
                );
            }

            if ($operatorCount === 0) {
                $addIssue(
                    $alerts,
                    'event-no-operators-' . $eventId,
                    'critical',
                    'event_no_operators',
                    'Wydarzenie bez operatorów',
                    sprintf('%s rozpoczyna pracę biura w ciągu 7 dni i nie ma przypisanych operatorów.', $eventName),
                    1,
                    $context
                );
            }

            if ($participantCount > 0 && $unsentEmailCount > 0) {
                $addIssue(
                    $alerts,
                    'event-unsent-emails-' . $eventId,
                    'warning',
                    'event_unsent_emails',
                    'Niewysłane kody QR',
                    sprintf('%s ma %d uczestników bez wysłanego kodu QR.', $eventName, $unsentEmailCount),
                    $unsentEmailCount,
                    $context
                );
            }
        }

        $organizationLimitsStmt = $pdo->query("
            SELECT
                o.id AS organization_id,
                o.name AS organization_name,
                o.event_limit,
                COUNT(e.id) AS event_count
            FROM organizations o
            LEFT JOIN events e
                ON e.organization_id = o.id
               AND e.deleted_at IS NULL
            GROUP BY o.id, o.name, o.event_limit
            HAVING o.event_limit > 0
               AND COUNT(e.id) >= o.event_limit
            ORDER BY o.name ASC
        ");

        foreach ($organizationLimitsStmt->fetchAll() as $organizationRow) {
            $organizationId = (string)$organizationRow['organization_id'];
            $eventCount = (int)$organizationRow['event_count'];
            $eventLimit = (int)$organizationRow['event_limit'];
            $addIssue(
                $alerts,
                'organization-limit-' . $organizationId,
                'warning',
                'organization_event_limit',
                'Osiągnięty limit wydarzeń',
                sprintf(
                    '%s wykorzystuje %d z %d dostępnych wydarzeń.',
                    (string)$organizationRow['organization_name'],
                    $eventCount,
                    $eventLimit
                ),
                $eventCount,
                [
                    'organization_id' => $organizationId,
                    'organization_name' => (string)$organizationRow['organization_name'],
                ]
            );
        }

        $blockedRateLimitRow = $pdo->query("
            SELECT COUNT(*) AS total
            FROM request_rate_limits
            WHERE blocked_until IS NOT NULL
              AND blocked_until > UTC_TIMESTAMP()
        ")->fetch();
        $blockedRateLimitCount = (int)($blockedRateLimitRow['total'] ?? 0);
        if ($blockedRateLimitCount > 0) {
            $addIssue(
                $alerts,
                'active-rate-limit-blocks',
                'warning',
                'active_rate_limit_blocks',
                'Aktywne blokady logowania',
                sprintf('System ma %d aktywnych blokad po przekroczeniu limitu prób.', $blockedRateLimitCount),
                $blockedRateLimitCount
            );
        }

        $syncEventsStmt = $pdo->query("
            SELECT
                e.id AS event_id,
                e.name AS event_name,
                e.organization_id,
                o.name AS organization_name,
                ess.sync_mode,
                ess.sync_status,
                ess.conflicts_count,
                DATE_FORMAT(ess.last_exported_at, '%Y-%m-%dT%H:%i:%s') AS last_exported_at,
                DATE_FORMAT(ess.last_synced_at, '%Y-%m-%dT%H:%i:%s') AS last_synced_at,
                COALESCE(ob.pending_count, 0) AS pending_count,
                COALESCE(ob.conflict_count, 0) AS outbox_conflict_count,
                COALESCE(ob.error_count, 0) AS error_count,
                ob.last_error
            FROM event_sync_state ess
            INNER JOIN events e ON e.id = ess.event_id
            INNER JOIN organizations o ON o.id = e.organization_id
            LEFT JOIN (
                SELECT
                    event_id,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
                    SUM(CASE WHEN status = 'conflict' THEN 1 ELSE 0 END) AS conflict_count,
                    SUM(CASE WHEN last_error IS NOT NULL AND last_error <> '' THEN 1 ELSE 0 END) AS error_count,
                    MAX(last_error) AS last_error
                FROM sync_outbox
                GROUP BY event_id
            ) ob ON ob.event_id = ess.event_id
            WHERE e.deleted_at IS NULL
            ORDER BY
                CASE ess.sync_status WHEN 'conflict' THEN 0 WHEN 'pending' THEN 1 WHEN 'syncing' THEN 2 ELSE 3 END,
                e.name ASC
        ");

        $syncEvents = array_map(static function (array $row): array {
            return [
                'event_id' => (string)$row['event_id'],
                'event_name' => (string)$row['event_name'],
                'organization_id' => (string)$row['organization_id'],
                'organization_name' => (string)$row['organization_name'],
                'sync_mode' => (string)$row['sync_mode'],
                'sync_status' => (string)$row['sync_status'],
                'conflicts_count' => (int)$row['conflicts_count'],
                'last_exported_at' => $row['last_exported_at'] ?? null,
                'last_synced_at' => $row['last_synced_at'] ?? null,
                'pending_count' => (int)$row['pending_count'],
                'outbox_conflict_count' => (int)$row['outbox_conflict_count'],
                'error_count' => (int)$row['error_count'],
                'last_error' => $row['last_error'] ?? null,
            ];
        }, $syncEventsStmt->fetchAll());

        foreach ($syncEvents as $syncEvent) {
            $conflictCount = (int)$syncEvent['conflicts_count'] + (int)$syncEvent['outbox_conflict_count'];
            $errorCount = (int)$syncEvent['error_count'];
            if ($syncEvent['sync_status'] !== 'conflict' && $conflictCount === 0 && $errorCount === 0) {
                continue;
            }

            $eventId = (string)$syncEvent['event_id'];
            $addIssue(
                $alerts,
                'sync-problem-' . $eventId,
                'critical',
                'sync_problem',
                'Problem synchronizacji',
                sprintf(
                    '%s ma %d konfliktów i %d operacji z błędem.',
                    (string)$syncEvent['event_name'],
                    $conflictCount,
                    $errorCount
                ),
                $conflictCount + $errorCount,
                [
                    'organization_id' => (string)$syncEvent['organization_id'],
                    'organization_name' => (string)$syncEvent['organization_name'],
                    'event_id' => $eventId,
                    'event_name' => (string)$syncEvent['event_name'],
                ]
            );
        }

        $duplicateBibStmt = $pdo->query("
            SELECT
                e.id AS event_id,
                e.name AS event_name,
                e.organization_id,
                o.name AS organization_name,
                COUNT(*) AS duplicate_group_count,
                SUM(duplicates.group_count) AS affected_count
            FROM (
                SELECT event_id, bib_number, COUNT(*) AS group_count
                FROM participants
                WHERE event_id IS NOT NULL
                  AND bib_number IS NOT NULL
                  AND TRIM(bib_number) <> ''
                GROUP BY event_id, bib_number
                HAVING COUNT(*) > 1
            ) duplicates
            INNER JOIN events e ON e.id = duplicates.event_id
            INNER JOIN organizations o ON o.id = e.organization_id
            WHERE e.deleted_at IS NULL
            GROUP BY e.id, e.name, e.organization_id, o.name
            ORDER BY affected_count DESC
        ");
        foreach ($duplicateBibStmt->fetchAll() as $row) {
            $eventId = (string)$row['event_id'];
            $affectedCount = (int)$row['affected_count'];
            $addIssue(
                $qualityIssues,
                'duplicate-bib-' . $eventId,
                'warning',
                'duplicate_bib_numbers',
                'Powtarzające się numery startowe',
                sprintf('%s ma %d uczestników w grupach z powtarzającym się numerem startowym.', (string)$row['event_name'], $affectedCount),
                $affectedCount,
                [
                    'organization_id' => (string)$row['organization_id'],
                    'organization_name' => (string)$row['organization_name'],
                    'event_id' => $eventId,
                    'event_name' => (string)$row['event_name'],
                ]
            );
        }

        $duplicateEmailStmt = $pdo->query("
            SELECT
                e.id AS event_id,
                e.name AS event_name,
                e.organization_id,
                o.name AS organization_name,
                SUM(duplicates.group_count) AS affected_count
            FROM (
                SELECT event_id, LOWER(TRIM(email)) AS normalized_email, COUNT(*) AS group_count
                FROM participants
                WHERE event_id IS NOT NULL
                  AND TRIM(email) <> ''
                GROUP BY event_id, LOWER(TRIM(email))
                HAVING COUNT(*) > 1
            ) duplicates
            INNER JOIN events e ON e.id = duplicates.event_id
            INNER JOIN organizations o ON o.id = e.organization_id
            WHERE e.deleted_at IS NULL
            GROUP BY e.id, e.name, e.organization_id, o.name
            ORDER BY affected_count DESC
        ");
        foreach ($duplicateEmailStmt->fetchAll() as $row) {
            $eventId = (string)$row['event_id'];
            $affectedCount = (int)$row['affected_count'];
            $addIssue(
                $qualityIssues,
                'duplicate-email-' . $eventId,
                'warning',
                'duplicate_emails',
                'Powtarzające się adresy e-mail',
                sprintf('%s ma %d uczestników korzystających z powtarzających się adresów e-mail.', (string)$row['event_name'], $affectedCount),
                $affectedCount,
                [
                    'organization_id' => (string)$row['organization_id'],
                    'organization_name' => (string)$row['organization_name'],
                    'event_id' => $eventId,
                    'event_name' => (string)$row['event_name'],
                ]
            );
        }

        $missingQrStmt = $pdo->query("
            SELECT
                e.id AS event_id,
                e.name AS event_name,
                e.organization_id,
                o.name AS organization_name,
                COUNT(*) AS affected_count
            FROM participants p
            INNER JOIN events e ON e.id = p.event_id
            INNER JOIN organizations o ON o.id = e.organization_id
            WHERE e.archived_at IS NULL
              AND e.deleted_at IS NULL
              AND (p.qr_code IS NULL OR TRIM(p.qr_code) = '')
            GROUP BY e.id, e.name, e.organization_id, o.name
            ORDER BY affected_count DESC
        ");
        foreach ($missingQrStmt->fetchAll() as $row) {
            $eventId = (string)$row['event_id'];
            $affectedCount = (int)$row['affected_count'];
            $addIssue(
                $qualityIssues,
                'missing-qr-' . $eventId,
                'critical',
                'participants_without_qr',
                'Uczestnicy bez kodu QR',
                sprintf('%s ma %d uczestników bez kodu QR.', (string)$row['event_name'], $affectedCount),
                $affectedCount,
                [
                    'organization_id' => (string)$row['organization_id'],
                    'organization_name' => (string)$row['organization_name'],
                    'event_id' => $eventId,
                    'event_name' => (string)$row['event_name'],
                ]
            );
        }

        $orphanParticipantsRow = $pdo->query('SELECT COUNT(*) AS total FROM participants WHERE event_id IS NULL')->fetch();
        $orphanParticipantsCount = (int)($orphanParticipantsRow['total'] ?? 0);
        if ($orphanParticipantsCount > 0) {
            $addIssue(
                $qualityIssues,
                'participants-without-event',
                'critical',
                'participants_without_event',
                'Uczestnicy bez wydarzenia',
                sprintf('%d uczestników nie jest powiązanych z żadnym wydarzeniem.', $orphanParticipantsCount),
                $orphanParticipantsCount
            );
        }

        $unassignedOperatorsRow = $pdo->query("
            SELECT COUNT(*) AS total
            FROM users u
            WHERE u.archived_at IS NULL
              AND u.role IN ('scanner', 'scanner_plus')
              AND NOT EXISTS (
                  SELECT 1
                  FROM user_event_assignments uea
                  WHERE uea.user_id = u.id
              )
        ")->fetch();
        $unassignedOperatorsCount = (int)($unassignedOperatorsRow['total'] ?? 0);
        if ($unassignedOperatorsCount > 0) {
            $addIssue(
                $qualityIssues,
                'operators-without-events',
                'warning',
                'operators_without_events',
                'Operatorzy bez przypisań',
                sprintf('%d aktywnych operatorów nie ma przypisanego żadnego wydarzenia.', $unassignedOperatorsCount),
                $unassignedOperatorsCount
            );
        }

        $eventsWithoutMappingsStmt = $pdo->query("
            SELECT
                e.id AS event_id,
                e.name AS event_name,
                e.organization_id,
                o.name AS organization_name
            FROM events e
            INNER JOIN organizations o ON o.id = e.organization_id
            WHERE e.archived_at IS NULL
              AND e.deleted_at IS NULL
              AND NOT EXISTS (
                  SELECT 1
                  FROM event_participant_field_mappings epfm
                  WHERE epfm.event_id = e.id
              )
            ORDER BY e.office_open_at ASC
        ");
        foreach ($eventsWithoutMappingsStmt->fetchAll() as $row) {
            $eventId = (string)$row['event_id'];
            $addIssue(
                $qualityIssues,
                'event-without-mappings-' . $eventId,
                'info',
                'event_without_field_mappings',
                'Brak mapowania pól uczestników',
                sprintf('%s nie ma zapisanego mapowania pól importu.', (string)$row['event_name']),
                1,
                [
                    'organization_id' => (string)$row['organization_id'],
                    'organization_name' => (string)$row['organization_name'],
                    'event_id' => $eventId,
                    'event_name' => (string)$row['event_name'],
                ]
            );
        }

        $severityOrder = ['critical' => 0, 'warning' => 1, 'info' => 2];
        $sortIssues = static function (array &$issues) use ($severityOrder): void {
            usort($issues, static function (array $left, array $right) use ($severityOrder): int {
                $severityComparison = ($severityOrder[(string)$left['severity']] ?? 99) <=> ($severityOrder[(string)$right['severity']] ?? 99);
                return $severityComparison !== 0
                    ? $severityComparison
                    : strcmp((string)$left['title'], (string)$right['title']);
            });
        };
        $sortIssues($alerts);
        $sortIssues($qualityIssues);

        $criticalAlertCount = count(array_filter($alerts, static fn(array $alert): bool => $alert['severity'] === 'critical'));
        $warningAlertCount = count(array_filter($alerts, static fn(array $alert): bool => $alert['severity'] === 'warning'));
        $syncConflictCount = array_sum(array_map(
            static fn(array $event): int => (int)$event['conflicts_count'] + (int)$event['outbox_conflict_count'],
            $syncEvents
        ));

        jsonResponse(200, [
            'generated_at' => gmdate(DATE_ATOM),
            'summary' => [
                'critical_alerts' => $criticalAlertCount,
                'warning_alerts' => $warningAlertCount,
                'quality_issues' => count($qualityIssues),
                'sync_conflicts' => $syncConflictCount,
                'active_rate_limit_blocks' => $blockedRateLimitCount,
            ],
            'alerts' => $alerts,
            'sync_events' => $syncEvents,
            'quality_issues' => $qualityIssues,
        ]);
        exit;
    }

    $buildAuditQuery = static function (): array {
        $conditions = [];
        $params = [];
        foreach (['category', 'action_code', 'outcome', 'severity', 'request_id'] as $field) {
            $value = trim((string)($_GET[$field] ?? ''));
            if ($value !== '') {
                $conditions[] = "al.{$field} = :{$field}";
                $params[$field] = $value;
            }
        }
        $actorId = trim((string)($_GET['actor_id'] ?? ''));
        if ($actorId !== '') {
            $conditions[] = 'al.actor_user_id = :actor_id';
            $params['actor_id'] = $actorId;
        }
        $scope = trim((string)($_GET['scope'] ?? 'all'));
        $entityId = trim((string)($_GET['id'] ?? ''));
        $scopeColumns = ['user' => 'actor_user_id', 'organization' => 'organization_id', 'event' => 'event_id', 'participant' => 'participant_id'];
        if ($entityId !== '' && isset($scopeColumns[$scope])) {
            $conditions[] = 'al.' . $scopeColumns[$scope] . ' = :entity_id';
            $params['entity_id'] = $entityId;
        }
        foreach (['from' => '>=', 'to' => '<='] as $field => $operator) {
            $value = trim((string)($_GET[$field] ?? ''));
            if ($value === '') {
                continue;
            }
            try {
                $date = new DateTimeImmutable($value);
            } catch (Throwable) {
                jsonResponse(422, ['error' => 'Nieprawidlowy zakres dat']);
                exit;
            }
            $conditions[] = "al.created_at {$operator} :{$field}";
            $params[$field] = $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }
        $query = trim((string)($_GET['q'] ?? ''));
        if ($query !== '') {
            $conditions[] = '(al.action LIKE :query OR al.action_code LIKE :query OR al.actor_name_snapshot LIKE :query OR al.target_id LIKE :query OR al.metadata_json LIKE :query)';
            $params['query'] = '%' . $query . '%';
        }
        return [$conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions), $params];
    };

    $loadAuditRows = static function (PDO $pdo, string $where, array $params, int $limit, int $offset = 0): array {
        $stmt = $pdo->prepare("
            SELECT
                'audit' AS source, al.id, al.action, DATE_FORMAT(al.created_at, '%Y-%m-%dT%H:%i:%sZ') AS timestamp,
                al.category, al.action_code, al.outcome, al.severity, al.request_id,
                al.organization_id, o.name AS organization_name, al.event_id, e.name AS event_name,
                al.participant_id, COALESCE(p.display_name, JSON_UNQUOTE(JSON_EXTRACT(al.metadata_json, '$.participant_name'))) AS participant_name,
                al.actor_user_id AS user_id, COALESCE(u.name, al.actor_name_snapshot) AS user_name,
                al.actor_role_snapshot AS user_role, al.target_type, al.target_id, al.metadata_json
            FROM audit_logs al
            LEFT JOIN organizations o ON o.id = al.organization_id
            LEFT JOIN events e ON e.id = al.event_id
            LEFT JOIN participants p ON p.id = al.participant_id
            LEFT JOIN users u ON u.id = al.actor_user_id
            {$where}
            ORDER BY al.created_at DESC, al.id DESC
            LIMIT {$limit} OFFSET {$offset}
        ");
        $stmt->execute($params);
        $entries = $stmt->fetchAll();
        foreach ($entries as &$entry) {
            $entry['metadata'] = decodeJsonObject($entry['metadata_json'] ?? null);
            unset($entry['metadata_json']);
        }
        unset($entry);
        return $entries;
    };

    if ($path === '/superadmin/database' && $method === 'GET') {
        requireAnyRole(['superadmin'], $resolveAuthenticatedUser);

        $databaseNameRow = $pdo->query('SELECT DATABASE() AS database_name')->fetch();
        $databaseName = trim((string)($databaseNameRow['database_name'] ?? ''));
        if ($databaseName === '') {
            jsonResponse(500, ['error' => 'Nie udało się ustalić aktywnej bazy danych']);
            exit;
        }

        $tablesStmt = $pdo->prepare('
            SELECT
                TABLE_NAME AS name,
                TABLE_ROWS AS row_estimate
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = :schema
              AND TABLE_TYPE = :table_type
            ORDER BY TABLE_NAME ASC
        ');
        $tablesStmt->execute([
            'schema' => $databaseName,
            'table_type' => 'BASE TABLE',
        ]);
        $tables = array_map(static function (array $row): array {
            return [
                'name' => (string)$row['name'],
                'row_estimate' => isset($row['row_estimate']) ? (int)$row['row_estimate'] : null,
            ];
        }, $tablesStmt->fetchAll());

        $tableNames = array_map(static fn(array $table): string => (string)$table['name'], $tables);
        $selectedTable = trim((string)($_GET['table'] ?? ''));
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = max(1, min(500, (int)($_GET['limit'] ?? 100)));

        if ($selectedTable === '') {
            jsonResponse(200, [
                'database' => $databaseName,
                'tables' => $tables,
                'columns' => [],
                'rows' => [],
                'meta' => [
                    'selected_table' => null,
                    'page' => 1,
                    'per_page' => $limit,
                    'total' => 0,
                    'total_pages' => 1,
                ],
            ]);
            exit;
        }

        if (!in_array($selectedTable, $tableNames, true)) {
            jsonResponse(404, ['error' => 'Nie znaleziono tabeli']);
            exit;
        }

        $columnsStmt = $pdo->prepare('
            SELECT
                COLUMN_NAME AS name,
                COLUMN_TYPE AS type,
                IS_NULLABLE AS is_nullable,
                COLUMN_KEY AS column_key,
                COLUMN_DEFAULT AS column_default,
                EXTRA AS extra
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = :schema
              AND TABLE_NAME = :table_name
            ORDER BY ORDINAL_POSITION ASC
        ');
        $columnsStmt->execute([
            'schema' => $databaseName,
            'table_name' => $selectedTable,
        ]);
        $columns = array_map(static function (array $row): array {
            $columnName = (string)$row['name'];
            return [
                'name' => $columnName,
                'type' => (string)$row['type'],
                'nullable' => (string)$row['is_nullable'] === 'YES',
                'key' => (string)($row['column_key'] ?? ''),
                'default' => isset($row['column_default']) ? (string)$row['column_default'] : null,
                'extra' => (string)($row['extra'] ?? ''),
                'masked' => isSensitiveDatabaseColumn($columnName),
            ];
        }, $columnsStmt->fetchAll());

        $quoteIdentifier = static fn(string $identifier): string => '`' . str_replace('`', '``', $identifier) . '`';
        $quotedTable = $quoteIdentifier($selectedTable);
        $totalRows = (int)(($pdo->query("SELECT COUNT(*) AS total FROM {$quotedTable}")->fetch()['total'] ?? 0));
        $totalPages = max(1, (int)ceil($totalRows / $limit));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $limit;

        $primaryKeyColumns = array_values(array_filter(
            $columns,
            static fn(array $column): bool => (string)($column['key'] ?? '') === 'PRI'
        ));
        $orderSql = '';
        if ($primaryKeyColumns !== []) {
            $orderColumns = array_map(
                static fn(array $column): string => $quoteIdentifier((string)$column['name']) . ' ASC',
                $primaryKeyColumns
            );
            $orderSql = ' ORDER BY ' . implode(', ', $orderColumns);
        }

        $rowsStmt = $pdo->query("SELECT * FROM {$quotedTable}{$orderSql} LIMIT {$limit} OFFSET {$offset}");
        $rows = maskSensitiveDatabaseRows($rowsStmt->fetchAll(), $columns);

        jsonResponse(200, [
            'database' => $databaseName,
            'tables' => $tables,
            'columns' => $columns,
            'rows' => $rows,
            'meta' => [
                'selected_table' => $selectedTable,
                'page' => $page,
                'per_page' => $limit,
                'total' => $totalRows,
                'total_pages' => $totalPages,
            ],
        ]);
        exit;
    }

    if ($path === '/superadmin/logging-status' && $method === 'GET') {
        requireAnyRole(['superadmin'], $resolveAuthenticatedUser);
        jsonResponse(200, ['data' => ApplicationLogger::status()]);
        exit;
    }

    if ($path === '/superadmin/server-logs' && $method === 'GET') {
        requireAnyRole(['superadmin'], $resolveAuthenticatedUser);
        $source = trim((string)($_GET['source'] ?? 'application'));
        if (!in_array($source, ['application', 'php'], true)) {
            jsonResponse(422, ['error' => 'Nieobslugiwane zrodlo logow']);
            exit;
        }
        $utc = new DateTimeZone('UTC');
        $now = new DateTimeImmutable('now', $utc);
        try {
            $from = new DateTimeImmutable((string)($_GET['from'] ?? '-24 hours'), $utc);
            $to = new DateTimeImmutable((string)($_GET['to'] ?? 'now'), $utc);
        } catch (Throwable) {
            jsonResponse(422, ['error' => 'Nieprawidlowy zakres dat']);
            exit;
        }
        if ($from > $to || $from < $now->modify('-30 days') || $to > $now->modify('+5 minutes')) {
            jsonResponse(422, ['error' => 'Zakres logow musi miescic sie w ostatnich 30 dniach']);
            exit;
        }
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = max(1, min(100, (int)($_GET['limit'] ?? 50)));
        $entries = ApplicationLogger::readEntries($source, $from, $to, [
            'level' => $_GET['level'] ?? '',
            'request_id' => $_GET['request_id'] ?? '',
            'event_code' => $_GET['event_code'] ?? '',
            'q' => $_GET['q'] ?? '',
        ], 5000);
        $total = count($entries);
        $totalPages = max(1, (int)ceil($total / $limit));
        $page = min($page, $totalPages);
        jsonResponse(200, [
            'data' => array_slice($entries, ($page - 1) * $limit, $limit),
            'meta' => ['page' => $page, 'per_page' => $limit, 'total' => $total, 'total_pages' => $totalPages],
        ]);
        exit;
    }

    if ($path === '/superadmin/server-logs/export.csv' && $method === 'GET') {
        $authUser = requireAnyRole(['superadmin'], $resolveAuthenticatedUser);
        $source = trim((string)($_GET['source'] ?? 'application'));
        if (!in_array($source, ['application', 'php'], true)) {
            jsonResponse(422, ['error' => 'Nieobslugiwane zrodlo logow']);
            exit;
        }
        $utc = new DateTimeZone('UTC');
        $now = new DateTimeImmutable('now', $utc);
        try {
            $from = new DateTimeImmutable((string)($_GET['from'] ?? '-24 hours'), $utc);
            $to = new DateTimeImmutable((string)($_GET['to'] ?? 'now'), $utc);
        } catch (Throwable) {
            jsonResponse(422, ['error' => 'Nieprawidlowy zakres dat']);
            exit;
        }
        if ($from > $to || $from < $now->modify('-30 days')) {
            jsonResponse(422, ['error' => 'Zakres logow musi miescic sie w ostatnich 30 dniach']);
            exit;
        }
        $entries = ApplicationLogger::readEntries($source, $from, $to, [
            'level' => $_GET['level'] ?? '',
            'request_id' => $_GET['request_id'] ?? '',
            'event_code' => $_GET['event_code'] ?? '',
            'q' => $_GET['q'] ?? '',
        ], 10000);
        addAuditLog($pdo, [
            'category' => 'system', 'action_code' => 'server_logs.exported', 'action' => 'Wyeksportowano logi serwera',
            'actor_user_id' => $authUser['id'], 'actor_name' => $authUser['name'], 'actor_role' => $authUser['role'],
            'target_type' => 'server_logs', 'target_id' => $source, 'metadata' => ['entries_count' => count($entries)],
        ]);
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            jsonResponse(500, ['error' => 'Nie udalo sie rozpoczac eksportu CSV']);
            exit;
        }
        $writeCsvRow($stream, ['ID', 'Czas UTC', 'Poziom', 'Kod zdarzenia', 'Komunikat', 'Request ID', 'Metoda', 'Sciezka', 'Uzytkownik', 'Rola']);
        foreach ($entries as $entry) {
            $writeCsvRow($stream, [
                $entry['id'] ?? '', $entry['timestamp'] ?? '', $entry['level'] ?? '', $entry['event_code'] ?? '',
                $entry['message'] ?? '', $entry['request_id'] ?? '', $entry['method'] ?? '', $entry['path'] ?? '',
                $entry['user_id'] ?? '', $entry['user_role'] ?? '',
            ]);
        }
        rewind($stream);
        $csvContent = stream_get_contents($stream);
        fclose($stream);
        $setCsvDownloadHeaders('logi-serwera-' . $source . '-' . gmdate('Y-m-d') . '.csv');
        echo "\xEF\xBB\xBF";
        echo $csvContent;
        exit;
    }

    if (preg_match('#^/superadmin/server-logs/(application|php)/(\d{4}-\d{2}-\d{2})/([^/]+)$#', $path, $matches) === 1 && $method === 'GET') {
        $authUser = requireAnyRole(['superadmin'], $resolveAuthenticatedUser);
        $entry = ApplicationLogger::findEntry((string)$matches[1], (string)$matches[2], rawurldecode((string)$matches[3]));
        if ($entry === null) {
            jsonResponse(404, ['error' => 'Nie znaleziono logu serwera']);
            exit;
        }
        addAuditLog($pdo, [
            'category' => 'system', 'action_code' => 'server_logs.viewed', 'action' => 'Wyswietlono szczegoly logu serwera',
            'actor_user_id' => $authUser['id'], 'actor_name' => $authUser['name'], 'actor_role' => $authUser['role'],
            'target_type' => 'server_log', 'target_id' => $entry['id'] ?? null, 'metadata' => ['source' => $matches[1]],
        ]);
        jsonResponse(200, ['data' => $entry]);
        exit;
    }

    if ($path === '/superadmin/audit/export.csv' && $method === 'GET') {
        $authUser = requireAnyRole(['superadmin'], $resolveAuthenticatedUser);
        [$where, $params] = $buildAuditQuery();
        $entries = $loadAuditRows($pdo, $where, $params, 10000);
        addAuditLog($pdo, [
            'category' => 'system', 'action_code' => 'audit.exported', 'action' => 'Wyeksportowano audyt systemowy',
            'actor_user_id' => $authUser['id'], 'actor_name' => $authUser['name'], 'actor_role' => $authUser['role'],
            'target_type' => 'audit_logs', 'metadata' => ['entries_count' => count($entries)],
        ]);
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            jsonResponse(500, ['error' => 'Nie udalo sie rozpoczac eksportu CSV']);
            exit;
        }
        $writeCsvRow($stream, ['ID', 'Czas UTC', 'Kategoria', 'Kod akcji', 'Akcja', 'Wynik', 'Poziom', 'Aktor', 'Rola', 'Cel', 'Request ID']);
        foreach ($entries as $entry) {
            $writeCsvRow($stream, [
                $entry['id'], $entry['timestamp'], $entry['category'], $entry['action_code'], $entry['action'],
                $entry['outcome'], $entry['severity'], $entry['user_name'], $entry['user_role'],
                trim((string)$entry['target_type'] . ':' . (string)$entry['target_id'], ':'), $entry['request_id'],
            ]);
        }
        rewind($stream);
        $csvContent = stream_get_contents($stream);
        fclose($stream);
        $setCsvDownloadHeaders('audyt-systemowy-' . gmdate('Y-m-d') . '.csv');
        echo "\xEF\xBB\xBF";
        echo $csvContent;
        exit;
    }

    if (preg_match('#^/superadmin/audit/([^/]+)$#', $path, $matches) === 1 && $method === 'GET') {
        $authUser = requireAnyRole(['superadmin'], $resolveAuthenticatedUser);
        $stmt = $pdo->prepare('SELECT * FROM audit_logs WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => rawurldecode((string)$matches[1])]);
        $entry = $stmt->fetch();
        if ($entry === false) {
            jsonResponse(404, ['error' => 'Nie znaleziono wpisu audytu']);
            exit;
        }
        $entry['metadata'] = decodeJsonObject($entry['metadata_json'] ?? null);
        unset($entry['metadata_json'], $entry['ip_hash']);
        addAuditLog($pdo, [
            'category' => 'system', 'action_code' => 'audit.viewed', 'action' => 'Wyswietlono szczegoly audytu',
            'actor_user_id' => $authUser['id'], 'actor_name' => $authUser['name'], 'actor_role' => $authUser['role'],
            'target_type' => 'audit_log', 'target_id' => $entry['id'],
        ]);
        jsonResponse(200, ['data' => $entry]);
        exit;
    }

    if ($path === '/superadmin/audit' && $method === 'GET') {
        requireAnyRole(['superadmin'], $resolveAuthenticatedUser);
        [$where, $params] = $buildAuditQuery();
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = max(1, min(100, (int)($_GET['limit'] ?? 50)));
        $countStmt = $pdo->prepare("SELECT COUNT(*) AS total FROM audit_logs al {$where}");
        $countStmt->execute($params);
        $total = (int)($countStmt->fetch()['total'] ?? 0);
        $totalPages = max(1, (int)ceil($total / $limit));
        $page = min($page, $totalPages);
        $entries = $loadAuditRows($pdo, $where, $params, $limit, ($page - 1) * $limit);
        jsonResponse(200, [
            'data' => $entries,
            'meta' => ['page' => $page, 'per_page' => $limit, 'total' => $total, 'total_pages' => $totalPages],
        ]);
        exit;
    }

    if ($path === '/superadmin/audit' && $method === 'GET') {
        requireAnyRole(['superadmin'], $resolveAuthenticatedUser);

        $scope = trim((string)($_GET['scope'] ?? 'all'));
        $entityId = trim((string)($_GET['id'] ?? ''));
        $query = trim((string)($_GET['q'] ?? ''));
        $page = (int)($_GET['page'] ?? 1);
        $page = max(1, $page);
        $limit = (int)($_GET['limit'] ?? 50);
        $limit = max(1, min(50, $limit));
        $offset = ($page - 1) * $limit;
        $sourceLimit = $offset + $limit;

        $allowedScopes = ['all', 'user', 'organization', 'event', 'participant'];
        if (!in_array($scope, $allowedScopes, true)) {
            jsonResponse(422, ['error' => 'Nieobslugiwany zakres audytu']);
            exit;
        }

        $scopeSearchTerms = [];
        if ($scope === 'user' && $entityId !== '') {
            $targetUser = $loadUserById($pdo, $entityId);
            if (is_array($targetUser)) {
                $scopeSearchTerms = array_values(array_filter([
                    trim((string)($targetUser['name'] ?? '')),
                    trim((string)($targetUser['email'] ?? '')),
                ], static fn(string $value): bool => $value !== ''));
            }
        }

        $buildActivityWhere = static function (string $scope, string $entityId, string $query, array $scopeSearchTerms, array &$params): string {
            $conditions = [];

            if ($entityId !== '') {
                if ($scope === 'user') {
                    $userConditions = ['al.user_id = :activity_entity_id'];
                    $params['activity_entity_id'] = $entityId;
                    foreach ($scopeSearchTerms as $index => $term) {
                        $placeholder = 'activity_scope_term_' . $index;
                        $userConditions[] = 'al.action LIKE :' . $placeholder;
                        $params[$placeholder] = '%' . $term . '%';
                    }
                    $conditions[] = '(' . implode(' OR ', $userConditions) . ')';
                } elseif ($scope === 'organization') {
                    $conditions[] = 'e.organization_id = :activity_entity_id';
                    $params['activity_entity_id'] = $entityId;
                } elseif ($scope === 'event') {
                    $conditions[] = 'COALESCE(al.event_id, p.event_id) = :activity_entity_id';
                    $params['activity_entity_id'] = $entityId;
                } elseif ($scope === 'participant') {
                    $conditions[] = 'al.participant_id = :activity_participant_id';
                    $params['activity_participant_id'] = (int)$entityId;
                }
            }

            if ($query !== '') {
                $queryPlaceholders = [];
                foreach (['action', 'event', 'organization', 'participant', 'snapshot', 'user', 'user_snapshot'] as $field) {
                    $placeholder = 'activity_query_' . $field;
                    $queryPlaceholders[$field] = ':' . $placeholder;
                    $params[$placeholder] = '%' . $query . '%';
                }
                $conditions[] = '(
                    al.action LIKE ' . $queryPlaceholders['action'] . '
                    OR e.name LIKE ' . $queryPlaceholders['event'] . '
                    OR o.name LIKE ' . $queryPlaceholders['organization'] . '
                    OR p.display_name LIKE ' . $queryPlaceholders['participant'] . '
                    OR al.participant_name_snapshot LIKE ' . $queryPlaceholders['snapshot'] . '
                    OR u.name LIKE ' . $queryPlaceholders['user'] . '
                    OR al.user_name_snapshot LIKE ' . $queryPlaceholders['user_snapshot'] . '
                )';
            }

            return $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);
        };

        $buildParticipantChangeWhere = static function (string $scope, string $entityId, string $query, array $scopeSearchTerms, array &$params): string {
            $conditions = [];

            if ($entityId !== '') {
                if ($scope === 'user') {
                    $userConditions = ['pcl.user_id = :change_entity_id'];
                    $params['change_entity_id'] = $entityId;
                    foreach ($scopeSearchTerms as $index => $term) {
                        $placeholder = 'change_scope_term_' . $index;
                        $userConditions[] = 'pcl.user_name_snapshot LIKE :' . $placeholder;
                        $params[$placeholder] = '%' . $term . '%';
                    }
                    $conditions[] = '(' . implode(' OR ', $userConditions) . ')';
                } elseif ($scope === 'organization') {
                    $conditions[] = 'e.organization_id = :change_entity_id';
                    $params['change_entity_id'] = $entityId;
                } elseif ($scope === 'event') {
                    $conditions[] = 'pcl.event_id = :change_entity_id';
                    $params['change_entity_id'] = $entityId;
                } elseif ($scope === 'participant') {
                    $conditions[] = 'pcl.participant_id = :change_participant_id';
                    $params['change_participant_id'] = (int)$entityId;
                }
            }

            if ($query !== '') {
                $queryPlaceholders = [];
                foreach (['type', 'source', 'audit_key', 'event', 'organization', 'participant', 'user', 'user_snapshot', 'fields'] as $field) {
                    $placeholder = 'change_query_' . $field;
                    $queryPlaceholders[$field] = ':' . $placeholder;
                    $params[$placeholder] = '%' . $query . '%';
                }
                $conditions[] = '(
                    pcl.change_type LIKE ' . $queryPlaceholders['type'] . '
                    OR pcl.change_source LIKE ' . $queryPlaceholders['source'] . '
                    OR pcl.participant_audit_key LIKE ' . $queryPlaceholders['audit_key'] . '
                    OR e.name LIKE ' . $queryPlaceholders['event'] . '
                    OR o.name LIKE ' . $queryPlaceholders['organization'] . '
                    OR p.display_name LIKE ' . $queryPlaceholders['participant'] . '
                    OR u.name LIKE ' . $queryPlaceholders['user'] . '
                    OR pcl.user_name_snapshot LIKE ' . $queryPlaceholders['user_snapshot'] . '
                    OR pcl.changed_fields_json LIKE ' . $queryPlaceholders['fields'] . '
                )';
            }

            return $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);
        };

        $activityParams = [];
        $activityWhere = $buildActivityWhere($scope, $entityId, $query, $scopeSearchTerms, $activityParams);
        $activityCountStmt = $pdo->prepare("
            SELECT COUNT(*) AS total
            FROM activity_logs al
            LEFT JOIN participants p ON p.id = al.participant_id
            LEFT JOIN events e ON e.id = COALESCE(al.event_id, p.event_id)
            LEFT JOIN organizations o ON o.id = e.organization_id
            LEFT JOIN users u ON u.id = al.user_id
            $activityWhere
        ");
        $activityCountStmt->execute($activityParams);
        $activityTotal = (int)($activityCountStmt->fetch()['total'] ?? 0);

        $activityStmt = $pdo->prepare("
            SELECT
                'activity' AS source,
                al.id,
                al.action,
                DATE_FORMAT(al.created_at, '%Y-%m-%dT%H:%i:%s') AS timestamp,
                COALESCE(al.event_id, p.event_id) AS event_id,
                e.name AS event_name,
                e.organization_id,
                o.name AS organization_name,
                al.participant_id,
                COALESCE(p.display_name, al.participant_name_snapshot) AS participant_name,
                al.user_id,
                COALESCE(u.name, al.user_name_snapshot) AS user_name,
                NULL AS change_type,
                NULL AS change_source,
                NULL AS changed_fields_json
            FROM activity_logs al
            LEFT JOIN participants p ON p.id = al.participant_id
            LEFT JOIN events e ON e.id = COALESCE(al.event_id, p.event_id)
            LEFT JOIN organizations o ON o.id = e.organization_id
            LEFT JOIN users u ON u.id = al.user_id
            $activityWhere
            ORDER BY al.created_at DESC
            LIMIT $sourceLimit
        ");
        $activityStmt->execute($activityParams);

        $changeParams = [];
        $changeWhere = $buildParticipantChangeWhere($scope, $entityId, $query, $scopeSearchTerms, $changeParams);
        $changeCountStmt = $pdo->prepare("
            SELECT COUNT(*) AS total
            FROM event_participant_change_logs pcl
            LEFT JOIN participants p ON p.id = pcl.participant_id
            LEFT JOIN events e ON e.id = pcl.event_id
            LEFT JOIN organizations o ON o.id = e.organization_id
            LEFT JOIN users u ON u.id = pcl.user_id
            $changeWhere
        ");
        $changeCountStmt->execute($changeParams);
        $changeTotal = (int)($changeCountStmt->fetch()['total'] ?? 0);

        $changeStmt = $pdo->prepare("
            SELECT
                'participant_change' AS source,
                pcl.id,
                CONCAT('Zmiana uczestnika: ', pcl.change_type) AS action,
                DATE_FORMAT(pcl.created_at, '%Y-%m-%dT%H:%i:%s') AS timestamp,
                pcl.event_id,
                e.name AS event_name,
                e.organization_id,
                o.name AS organization_name,
                pcl.participant_id,
                COALESCE(
                    p.display_name,
                    JSON_UNQUOTE(JSON_EXTRACT(pcl.after_state_json, '$.display_name')),
                    JSON_UNQUOTE(JSON_EXTRACT(pcl.before_state_json, '$.display_name'))
                ) AS participant_name,
                pcl.user_id,
                COALESCE(u.name, pcl.user_name_snapshot) AS user_name,
                pcl.change_type,
                pcl.change_source,
                pcl.changed_fields_json
            FROM event_participant_change_logs pcl
            LEFT JOIN participants p ON p.id = pcl.participant_id
            LEFT JOIN events e ON e.id = pcl.event_id
            LEFT JOIN organizations o ON o.id = e.organization_id
            LEFT JOIN users u ON u.id = pcl.user_id
            $changeWhere
            ORDER BY pcl.created_at DESC
            LIMIT $sourceLimit
        ");
        $changeStmt->execute($changeParams);

        $entries = array_merge($activityStmt->fetchAll(), $changeStmt->fetchAll());
        usort($entries, static function (array $left, array $right): int {
            return strcmp((string)($right['timestamp'] ?? ''), (string)($left['timestamp'] ?? ''));
        });
        $entries = array_slice($entries, $offset, $limit);

        foreach ($entries as &$entry) {
            $changedFields = decodeJsonObject($entry['changed_fields_json'] ?? null);
            $entry['changed_fields'] = array_values(array_filter(
                array_map(static fn(mixed $field): string => trim((string)$field), $changedFields),
                static fn(string $field): bool => $field !== ''
            ));
            unset($entry['changed_fields_json']);
        }
        unset($entry);

        $total = $activityTotal + $changeTotal;
        $totalPages = max(1, (int)ceil($total / $limit));

        jsonResponse(200, [
            'data' => $entries,
            'meta' => [
                'page' => $page,
                'per_page' => $limit,
                'total' => $total,
                'total_pages' => $totalPages,
            ],
        ]);
        exit;
    }

    if (preg_match('#^/superadmin/admins/([^/]+)$#', $path, $matches) === 1 && $method === 'PATCH') {
        $authUser = requireAnyRole(['superadmin'], $resolveAuthenticatedUser);
        $userId = (string)$matches[1];
        $input = readJsonBody();
        $name = trim((string)($input['name'] ?? ''));
        $email = normalizeEmailAddress((string)($input['email'] ?? ''));

        if ($name === '' || $email === '') {
            jsonResponse(422, ['error' => 'Nazwa i adres e-mail sa wymagane']);
            exit;
        }

        if (!isValidEmailAddress($email)) {
            jsonResponse(422, ['error' => 'Adres e-mail musi byc prawidlowy']);
            exit;
        }

        $targetUser = $loadUserById($pdo, $userId);
        if ($targetUser === false) {
            jsonResponse(404, ['error' => 'Nie znaleziono uzytkownika']);
            exit;
        }

        if ((string)($targetUser['role'] ?? '') !== 'admin') {
            jsonResponse(422, ['error' => 'Ten endpoint obsluguje tylko konta adminow']);
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
            jsonResponse(409, ['error' => 'Uzytkownik z tym adresem e-mail juz istnieje']);
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

            $addActivityLog(
                $pdo,
                sprintf('Zaktualizowano konto admina: %s', $name),
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

        jsonResponse(200, ['data' => $loadUserById($pdo, $userId)]);
        exit;
    }

    if (preg_match('#^/superadmin/admins/([^/]+)/password-reset$#', $path, $matches) === 1 && $method === 'POST') {
        $authUser = requireAnyRole(['superadmin'], $resolveAuthenticatedUser);
        $userId = (string)$matches[1];

        $targetUser = $loadUserById($pdo, $userId);
        if ($targetUser === false) {
            jsonResponse(404, ['error' => 'Nie znaleziono uzytkownika']);
            exit;
        }

        if ((string)($targetUser['role'] ?? '') !== 'admin') {
            jsonResponse(422, ['error' => 'Reset hasla z tego miejsca dotyczy tylko adminow']);
            exit;
        }

        try {
            $token = $createPasswordResetToken($pdo, (string)$targetUser['id'], ACCOUNT_SETUP_TTL_SECONDS);
            $resetUrl = appFrontendUrl() . '/reset-password?token=' . urlencode($token);
            MailService::sendPasswordSetupEmail((string)$targetUser['email'], (string)$targetUser['name'], $resetUrl);

            $addActivityLog(
                $pdo,
                sprintf('Wyslano reset hasla dla admina: %s', (string)$targetUser['name']),
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

    if (preg_match('#^/superadmin/users/([^/]+)/role$#', $path, $matches) === 1 && $method === 'PATCH') {
        $authUser = requireAnyRole(['superadmin'], $resolveAuthenticatedUser);
        $userId = (string)$matches[1];
        $input = readJsonBody();
        $role = trim((string)($input['role'] ?? ''));
        $organizationId = trim((string)($input['organization_id'] ?? ''));

        if ($role === '') {
            jsonResponse(422, ['error' => 'Rola jest wymagana']);
            exit;
        }

        if (!in_array($role, ['admin', 'editor', 'scanner', 'scanner_plus'], true)) {
            jsonResponse(422, ['error' => 'Nieprawidlowa rola']);
            exit;
        }

        $targetUser = $loadUserById($pdo, $userId);
        if ($targetUser === false) {
            jsonResponse(404, ['error' => 'Nie znaleziono uzytkownika']);
            exit;
        }

        if ((string)($targetUser['role'] ?? '') === 'superadmin') {
            jsonResponse(422, ['error' => 'Nie mozna zmienic roli superadmina']);
            exit;
        }

        if (!$canManageTargetUser($authUser, $targetUser)) {
            jsonResponse(403, ['error' => 'Brak uprawnien']);
            exit;
        }

        if ($role !== 'admin') {
            if ($organizationId === '') {
                jsonResponse(422, ['error' => 'Dla tej roli pole organization_id jest wymagane']);
                exit;
            }

            if ($loadOrganizationById($pdo, $organizationId) === false) {
                jsonResponse(422, ['error' => 'Nie znaleziono organizacji']);
                exit;
            }
        } else {
            $organizationId = '';
        }

        $currentRole = (string)($targetUser['role'] ?? '');
        $currentOrganizationId = trim((string)($targetUser['organization_id'] ?? ''));
        $preserveScannerAssignments =
            $isScannerRole($currentRole)
            && $isScannerRole($role)
            && $currentOrganizationId !== ''
            && $currentOrganizationId === $organizationId;

        $pdo->beginTransaction();

        try {
            $updateStmt = $pdo->prepare('
                UPDATE users
                SET role = :role,
                    organization_id = :organization_id
                WHERE id = :id
            ');
            $updateStmt->execute([
                'role' => $role,
                'organization_id' => $role === 'admin' ? null : $organizationId,
                'id' => $userId,
            ]);

            if (!$preserveScannerAssignments || !$isScannerRole($role)) {
                $deleteAssignmentsStmt = $pdo->prepare('DELETE FROM user_event_assignments WHERE user_id = :user_id');
                $deleteAssignmentsStmt->execute(['user_id' => $userId]);
            }

            if ($currentRole !== $role || $currentOrganizationId !== $organizationId) {
                $addActivityLog(
                    $pdo,
                    sprintf(
                        'Zmieniono role uzytkownika %s z %s na %s',
                        (string)$targetUser['name'],
                        $currentRole,
                        $role
                    ),
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

    if (preg_match('#^/superadmin/admins/([^/]+)$#', $path, $matches) === 1 && $method === 'DELETE') {
        $authUser = requireAnyRole(['superadmin'], $resolveAuthenticatedUser);
        $userId = (string)$matches[1];

        $targetUser = $loadUserById($pdo, $userId);
        if ($targetUser === false) {
            jsonResponse(404, ['error' => 'Nie znaleziono uzytkownika']);
            exit;
        }

        if ((string)($targetUser['role'] ?? '') !== 'admin') {
            jsonResponse(422, ['error' => 'Archiwizowac w tym miejscu mozna tylko konta adminow']);
            exit;
        }

        $archivedEmail = (string)$targetUser['email'];
        $archivedAlias = sprintf(
            'archived+%s+%s@biurozawodow.local',
            preg_replace('/[^a-zA-Z0-9_-]+/', '-', $userId) ?: 'admin',
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

            $invalidatePasswordResetTokensForUser($pdo, $userId);

            $addActivityLog(
                $pdo,
                sprintf('Zarchiwizowano konto admina: %s', (string)$targetUser['name']),
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

        $eventCountStmt = $pdo->prepare('SELECT COUNT(*) AS total FROM events WHERE organization_id = :organization_id AND deleted_at IS NULL');
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

        $eventCountStmt = $pdo->prepare('SELECT COUNT(*) AS total FROM events WHERE organization_id = :organization_id AND archived_at IS NULL AND deleted_at IS NULL');
        $eventCountStmt->execute(['organization_id' => $organizationId]);
        $eventCount = (int)($eventCountStmt->fetch()['total'] ?? 0);
        if ($eventCount > 0) {
            jsonResponse(422, ['error' => 'Nie można usunąć organizacji, która ma aktywne lub nadchodzące wydarzenia']);
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

        $eventCountStmt = $pdo->prepare('SELECT COUNT(*) AS total FROM events WHERE organization_id = :organization_id AND deleted_at IS NULL');
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
        $reopenOffice = (bool)($input['reopen_office'] ?? false);

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

        $existingOfficeOpenAt = normalizeLocalDateTimeString((string)($event['office_open_at'] ?? ''));
        $isCurrentlyOpen = $isEventOfficeOpenNow($event);
        $isKeepingExistingOfficeOpenAt = $existingOfficeOpenAt !== null
            && $normalizedOfficeOpenAt === $existingOfficeOpenAt;
        $isReopeningFinishedEvent = $reopenOffice && $isEventFinished($event);
        $officeWindowValidationError = $validateEventOfficeWindow(
            $normalizedOfficeOpenAt,
            $normalizedOfficeCloseAt,
            $isCurrentlyOpen || $isKeepingExistingOfficeOpenAt || $isReopeningFinishedEvent
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

        if (!$isEventFinished($event)) {
            jsonResponse(422, ['error' => 'Do archiwum można przenieść tylko zakończone wydarzenia']);
            exit;
        }

        $participantCountStmt = $pdo->prepare('SELECT COUNT(*) AS total FROM participants WHERE event_id = :event_id');
        $participantCountStmt->execute(['event_id' => $eventId]);
        $participantCount = (int)($participantCountStmt->fetch()['total'] ?? 0);

        $pdo->beginTransaction();

        try {
            if ($eventHasParticipantImportBaseline($pdo, (string)$event['id'])) {
                $addParticipantChangeLog(
                    $pdo,
                    (string)$event['id'],
                    (string)$participant['participant_audit_key'],
                    isset($participant['baseline_import_record_id']) ? (int)$participant['baseline_import_record_id'] : null,
                    (int)$participant['id'],
                    'deleted',
                    'participant_delete',
                    $normalizeParticipantChangeState($participant),
                    [],
                    ['participant_record'],
                    (string)$authUser['id'],
                    (string)$authUser['name']
                );
            }
            $addActivityLog(
                $pdo,
                sprintf('Zarchiwizowano wydarzenie: %s (%d uczestników)', (string)$event['name'], $participantCount),
                $eventId,
                null,
                null,
                (string)$authUser['id'],
                (string)$authUser['name']
            );

            $archiveEventStmt = $pdo->prepare('UPDATE events SET archived_at = UTC_TIMESTAMP() WHERE id = :id AND deleted_at IS NULL');
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

    if (preg_match('#^/events/([^/]+)/archive$#', $path, $matches) === 1 && $method === 'POST') {
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

        if (trim((string)($event['deleted_at'] ?? '')) !== '') {
            jsonResponse(422, ['error' => 'Usuniętego wydarzenia nie można przenieść do archiwum']);
            exit;
        }

        if (trim((string)($event['archived_at'] ?? '')) !== '') {
            jsonResponse(422, ['error' => 'Wydarzenie jest już zarchiwizowane']);
            exit;
        }

        if (!$isEventFinished($event)) {
            jsonResponse(422, ['error' => 'Do archiwum można przenieść tylko zakończone wydarzenia']);
            exit;
        }

        $participantCountStmt = $pdo->prepare('SELECT COUNT(*) AS total FROM participants WHERE event_id = :event_id');
        $participantCountStmt->execute(['event_id' => $eventId]);
        $participantCount = (int)($participantCountStmt->fetch()['total'] ?? 0);

        $pdo->beginTransaction();

        try {
            $addActivityLog(
                $pdo,
                sprintf('Zarchiwizowano wydarzenie: %s (%d uczestników)', (string)$event['name'], $participantCount),
                $eventId,
                null,
                null,
                (string)$authUser['id'],
                (string)$authUser['name']
            );

            $archiveEventStmt = $pdo->prepare('
                UPDATE events
                SET archived_at = UTC_TIMESTAMP()
                WHERE id = :id
                  AND archived_at IS NULL
                  AND deleted_at IS NULL
            ');
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

    if (preg_match('#^/events/([^/]+)/delete-ui$#', $path, $matches) === 1 && $method === 'POST') {
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

        if (trim((string)($event['deleted_at'] ?? '')) !== '') {
            jsonResponse(422, ['error' => 'Wydarzenie jest już usunięte']);
            exit;
        }

        if (trim((string)($event['archived_at'] ?? '')) !== '') {
            jsonResponse(422, ['error' => 'Wydarzenie jest zarchiwizowane i nie można go usuwać']);
            exit;
        }

        $officeCloseAt = parseLocalDateTimeString((string)($event['office_close_at'] ?? ''));
        $now = new DateTimeImmutable();
        if ($officeCloseAt !== null && $now > $officeCloseAt) {
            jsonResponse(422, ['error' => 'Zakończone wydarzenia trzeba przenieść do archiwum']);
            exit;
        }

        $pdo->beginTransaction();

        try {
            $addActivityLog(
                $pdo,
                sprintf('Usunięto wydarzenie: %s', (string)$event['name']),
                $eventId,
                null,
                null,
                (string)$authUser['id'],
                (string)$authUser['name']
            );

            $deleteEventStmt = $pdo->prepare('
                UPDATE events
                SET deleted_at = UTC_TIMESTAMP()
                WHERE id = :id
                  AND archived_at IS NULL
                  AND deleted_at IS NULL
            ');
            $deleteEventStmt->execute(['id' => $eventId]);

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
            'participant_id' => 'ID uczestnika',
            'event_id' => 'ID wydarzenia',
            'event_name' => 'Nazwa wydarzenia',
            'event_location' => 'Lokalizacja wydarzenia',
            'event_office_open_at' => 'Otwarcie biura zawodów',
            'event_office_close_at' => 'Zamknięcie biura zawodów',
            'organization_id' => 'ID organizacji',
            'display_name' => 'Imię i nazwisko',
            'first_name' => 'Imię',
            'last_name' => 'Nazwisko',
            'email' => 'E-mail',
            'organization' => 'Organizacja',
            'bib_number' => 'Numer startowy',
            'status' => 'Status',
            'email_status' => 'Status e-maila',
            'checked_in_at' => 'Czas odprawy',
            'qr_code' => 'Kod QR',
            'created_at' => 'Utworzono',
            'updated_at' => 'Zaktualizowano',
        ], array_fill_keys($customFieldColumns, null));
        foreach ($customFieldColumns as $customFieldColumn) {
            $columns[$customFieldColumn] = $customFieldColumn;
        }
        $columnKeys = array_keys($columns);

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            jsonResponse(500, ['error' => 'Nie udało się rozpocząć eksportu CSV']);
            exit;
        }

        $writeCsvRow($stream, array_values($columns));

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
                'status' => $translateExportValue((string)($participant['status'] ?? ''), 'participant_status'),
                'email_status' => $translateExportValue((string)($participant['email_status'] ?? ''), 'email_status'),
                'checked_in_at' => (string)($participant['checked_in_at'] ?? ''),
                'qr_code' => (string)($participant['qr_code'] ?? ''),
                'created_at' => (string)($participant['created_at'] ?? ''),
                'updated_at' => (string)($participant['updated_at'] ?? ''),
            ];

            foreach ($customFieldColumns as $columnName) {
                $row[$columnName] = (string)(($participant['custom_fields'] ?? [])[$columnName] ?? '');
            }

            $writeCsvRow($stream, array_map(
                static fn(string $columnName): string => (string)($row[$columnName] ?? ''),
                $columnKeys
            ));
        }

        rewind($stream);
        $csvContent = stream_get_contents($stream);
        fclose($stream);

        $addActivityLog(
            $pdo,
            'Wyeksportowano dane uczestników do CSV',
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

        $setCsvDownloadHeaders($buildExportFilename($event, 'uczestnicy'));
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

        $writeCsvRow($stream, ['ID logu', 'ID wydarzenia', 'Nazwa wydarzenia', 'ID użytkownika', 'Użytkownik', 'ID uczestnika', 'Uczestnik', 'Akcja', 'Data i godzina']);
        foreach ($logs as $log) {
            $writeCsvRow($stream, [
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

        $setCsvDownloadHeaders($buildExportFilename($event, 'logi'));
        echo "\xEF\xBB\xBF";
        echo $csvContent;
        exit;
    }

    if (preg_match('#^/events/([^/]+)/participant-changes/export\.csv$#', $path, $matches) === 1 && $method === 'GET') {
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

        if (!$eventHasParticipantImportBaseline($pdo, $eventId)) {
            jsonResponse(422, ['error' => 'Eksport zmian uczestników jest dostępny dopiero po pierwszym udanym imporcie CSV dla wydarzenia']);
            exit;
        }

        $baselineRecords = $loadParticipantImportBaselineRecords($pdo, $eventId);
        $currentParticipants = $loadParticipantsByEventId($pdo, $eventId);
        $changeLogs = $loadParticipantChangeLogsByEvent($pdo, $eventId);

        $entities = [];
        $customFieldAliases = [];
        $collectCustomFieldAliases = static function (array $participantState) use (&$customFieldAliases): void {
            foreach (array_keys(is_array($participantState['custom_fields'] ?? null) ? $participantState['custom_fields'] : []) as $alias) {
                $normalizedAlias = trim((string)$alias);
                if ($normalizedAlias === '') {
                    continue;
                }

                $customFieldAliases[$normalizedAlias] = true;
            }
        };

        foreach ($baselineRecords as $baselineRecord) {
            $auditKey = trim((string)($baselineRecord['participant_audit_key'] ?? ''));
            if ($auditKey === '') {
                continue;
            }

            $entities[$auditKey] ??= [
                'baseline_record' => null,
                'current_participant' => null,
                'logs' => [],
            ];
            $entities[$auditKey]['baseline_record'] = $baselineRecord;
            $collectCustomFieldAliases(is_array($baselineRecord['state'] ?? null) ? $baselineRecord['state'] : []);
        }

        foreach ($currentParticipants as $currentParticipant) {
            $auditKey = trim((string)($currentParticipant['participant_audit_key'] ?? ''));
            if ($auditKey === '') {
                continue;
            }

            $entities[$auditKey] ??= [
                'baseline_record' => null,
                'current_participant' => null,
                'logs' => [],
            ];
            $entities[$auditKey]['current_participant'] = $currentParticipant;
            $collectCustomFieldAliases($normalizeParticipantChangeState($currentParticipant));
        }

        foreach ($changeLogs as $changeLog) {
            $auditKey = trim((string)($changeLog['participant_audit_key'] ?? ''));
            if ($auditKey === '') {
                continue;
            }

            $entities[$auditKey] ??= [
                'baseline_record' => null,
                'current_participant' => null,
                'logs' => [],
            ];
            $entities[$auditKey]['logs'][] = $changeLog;
        }

        $customFieldColumns = array_keys($customFieldAliases);
        sort($customFieldColumns);

        $rows = [];
        foreach ($entities as $auditKey => $entity) {
            $baselineRecord = is_array($entity['baseline_record'] ?? null) ? $entity['baseline_record'] : null;
            $currentParticipant = is_array($entity['current_participant'] ?? null) ? $entity['current_participant'] : null;
            $logs = is_array($entity['logs'] ?? null) ? $entity['logs'] : [];
            $baselineState = $baselineRecord !== null && is_array($baselineRecord['state'] ?? null) ? $baselineRecord['state'] : [];
            $currentState = $currentParticipant !== null ? $normalizeParticipantChangeState($currentParticipant) : [];

            $lastLog = $logs !== [] ? $logs[count($logs) - 1] : null;
            $firstLog = $logs !== [] ? $logs[0] : null;
            $addedSeen = false;
            $deletedSeen = false;
            $latestAddSource = '';

            foreach ($logs as $logEntry) {
                if (($logEntry['change_type'] ?? '') === 'added' && !$addedSeen) {
                    $addedSeen = true;
                    $latestAddSource = (string)($logEntry['change_source'] ?? '');
                }

                if (($logEntry['change_type'] ?? '') === 'deleted') {
                    $deletedSeen = true;
                }
            }

            $rowChangeType = '';
            $changeSource = '';
            $currentStateLabel = '';
            $originalState = [];
            $currentStateForExport = [];
            $changedFields = [];

            if ($baselineRecord !== null && $currentParticipant !== null) {
                $changedFields = $diffParticipantChangeStates($baselineState, $currentState);
                if ($changedFields === []) {
                    continue;
                }

                $rowChangeType = 'updated';
                $changeSource = (string)($lastLog['change_source'] ?? 'participant_edit');
                $currentStateLabel = 'active';
                $originalState = $baselineState;
                $currentStateForExport = $currentState;
            } elseif ($baselineRecord !== null) {
                $rowChangeType = 'deleted';
                $changeSource = (string)($lastLog['change_source'] ?? 'participant_delete');
                $currentStateLabel = 'deleted';
                $originalState = $baselineState;
                $currentStateForExport = [];
                $changedFields = ['participant_record'];
            } elseif ($currentParticipant !== null) {
                $rowChangeType = 'added';
                $changeSource = $latestAddSource !== '' ? $latestAddSource : (string)($lastLog['change_source'] ?? '');
                $currentStateLabel = 'active';
                $originalState = [];
                $currentStateForExport = $currentState;
                $changedFields = ['participant_record'];
            } elseif ($addedSeen || $deletedSeen) {
                $rowChangeType = $addedSeen && $deletedSeen ? 'added_then_deleted' : ($deletedSeen ? 'deleted' : 'added');
                $changeSource = (string)($lastLog['change_source'] ?? '');
                $currentStateLabel = 'deleted';
                $originalState = [];
                $currentStateForExport = [];
                $changedFields = ['participant_record'];
            } else {
                continue;
            }

            $row = [
                'change_type' => $translateExportValue($rowChangeType, 'change_type'),
                'change_source' => $translateExportValue($changeSource, 'change_source'),
                'event_id' => $eventId,
                'event_name' => (string)$event['name'],
                'baseline_record_id' => $baselineRecord !== null ? (string)$baselineRecord['id'] : '',
                'current_participant_id' => $currentParticipant !== null ? (string)$currentParticipant['id'] : '',
                'current_state' => $translateExportValue($currentStateLabel, 'current_state'),
                'changed_fields' => implode('|', array_map(
                    static fn(string $field): string => $translateExportValue($field, 'changed_field'),
                    $changedFields
                )),
                'change_count' => (string)count($logs),
                'first_changed_at' => (string)($firstLog['created_at'] ?? ''),
                'last_changed_at' => (string)($lastLog['created_at'] ?? ''),
                'last_changed_by' => (string)($lastLog['user_name_snapshot'] ?? ''),
                'original_display_name' => (string)($originalState['display_name'] ?? ''),
                'current_display_name' => (string)($currentStateForExport['display_name'] ?? ''),
                'original_email' => (string)($originalState['email'] ?? ''),
                'current_email' => (string)($currentStateForExport['email'] ?? ''),
                'original_bib_number' => (string)($originalState['bib_number'] ?? ''),
                'current_bib_number' => (string)($currentStateForExport['bib_number'] ?? ''),
            ];

            foreach ($customFieldColumns as $columnName) {
                $row['original__' . $columnName] = (string)((is_array($originalState['custom_fields'] ?? null) ? $originalState['custom_fields'] : [])[$columnName] ?? '');
                $row['current__' . $columnName] = (string)((is_array($currentStateForExport['custom_fields'] ?? null) ? $currentStateForExport['custom_fields'] : [])[$columnName] ?? '');
            }

            $rows[] = $row;
        }

        usort($rows, static function (array $left, array $right): int {
            $leftTimestamp = trim((string)($left['last_changed_at'] ?? ''));
            $rightTimestamp = trim((string)($right['last_changed_at'] ?? ''));

            if ($leftTimestamp === $rightTimestamp) {
                return strcmp((string)($left['current_participant_id'] ?? $left['baseline_record_id'] ?? ''), (string)($right['current_participant_id'] ?? $right['baseline_record_id'] ?? ''));
            }

            return strcmp($rightTimestamp, $leftTimestamp);
        });

        $columns = [
            'change_type' => 'Typ zmiany',
            'change_source' => 'Źródło zmiany',
            'event_id' => 'ID wydarzenia',
            'event_name' => 'Nazwa wydarzenia',
            'baseline_record_id' => 'ID rekordu bazowego',
            'current_participant_id' => 'Aktualne ID uczestnika',
            'current_state' => 'Aktualny stan',
            'changed_fields' => 'Zmienione pola',
            'change_count' => 'Liczba zmian',
            'first_changed_at' => 'Pierwsza zmiana',
            'last_changed_at' => 'Ostatnia zmiana',
            'last_changed_by' => 'Ostatnio zmienił',
            'original_display_name' => 'Poprzednie imię i nazwisko',
            'current_display_name' => 'Aktualne imię i nazwisko',
            'original_email' => 'Poprzedni e-mail',
            'current_email' => 'Aktualny e-mail',
            'original_bib_number' => 'Poprzedni numer startowy',
            'current_bib_number' => 'Aktualny numer startowy',
        ];
        foreach ($customFieldColumns as $columnName) {
            $columns['original__' . $columnName] = 'Poprzednio: ' . $columnName;
            $columns['current__' . $columnName] = 'Aktualnie: ' . $columnName;
        }
        $columnKeys = array_keys($columns);

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            jsonResponse(500, ['error' => 'Nie udało się rozpocząć eksportu CSV']);
            exit;
        }

        $writeCsvRow($stream, array_values($columns));
        foreach ($rows as $row) {
            $writeCsvRow($stream, array_map(
                static fn(string $columnName): string => (string)($row[$columnName] ?? ''),
                $columnKeys
            ));
        }

        rewind($stream);
        $csvContent = stream_get_contents($stream);
        fclose($stream);

        $addActivityLog(
            $pdo,
            'Wyeksportowano zmiany uczestników do CSV',
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

        $setCsvDownloadHeaders($buildExportFilename($event, 'zmiany'));
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
        $participantListStats = $countEventParticipantListStats($pdo, $eventId);
        $listDifference = $existingMappings !== []
            ? $buildParticipantListDifference($pdo, $eventId, $cleaned['headers'], $cleaned['rows'], $existingMappings)
            : [
                'columns_differ' => false,
                'missing_columns' => [],
                'extra_columns' => [],
                'participant_difference_ratio' => 0.0,
                'should_offer_replacement' => false,
            ];

        jsonResponse(200, [
            'data' => [
                'headers' => $cleaned['headers'],
                'sample_rows' => array_slice($cleaned['rows'], 0, 5),
                'email_candidates' => $emailCandidates,
                'has_mapping' => $existingMappings !== [],
                'has_baseline_import' => $eventHasParticipantImportBaseline($pdo, $eventId),
                'mappings' => $existingMappings,
                'missing_required_columns' => $missingColumns,
                'row_count' => count($cleaned['rows']),
                'existing_participant_count' => $participantListStats['participant_count'],
                'sent_qr_email_count' => $participantListStats['sent_qr_email_count'],
                'list_difference' => $listDifference,
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
        [$mappingsToSave, $mappingError] = $buildParticipantImportMappingsFromInput($input);
        if ($mappingError !== null || $mappingsToSave === null) {
            jsonResponse(422, ['error' => $mappingError ?? 'Nieprawidłowe mapowanie CSV']);
            exit;
        }

        $pdo->beginTransaction();

        try {
            $insertParticipantImportMappings($pdo, $eventId, $mappingsToSave);

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

        $hasBaselineImport = $eventHasParticipantImportBaseline($pdo, $eventId);
        $importSummary = null;

        $pdo->beginTransaction();

        try {
            [$importSummary, $importError] = $importParticipantsFromRows($pdo, $eventId, $cleaned['rows'], $mappings, $authUser, $hasBaselineImport);
            if ($importError !== null || $importSummary === null) {
                throw new RuntimeException($importError ?? 'Nie udało się zaimportować uczestników');
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
            sprintf(
                'Zaimportowano CSV uczestników: dodano %d, duplikaty %d, niepoprawne %d',
                (int)$importSummary['created_count'],
                (int)$importSummary['duplicate_count'],
                (int)$importSummary['invalid_count']
            ),
            $eventId,
            null,
            null,
            (string)$authUser['id'],
            (string)$authUser['name']
        );
        jsonResponse(200, [
            'data' => $importSummary,
        ]);
        exit;
    }

    if (preg_match('#^/events/([^/]+)/participant-list$#', $path, $matches) === 1 && $method === 'DELETE') {
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
        $confirmQrSent = (bool)($input['confirm_qr_sent'] ?? false);
        $participantListStats = $countEventParticipantListStats($pdo, $eventId);
        if ($participantListStats['sent_qr_email_count'] > 0 && !$confirmQrSent) {
            jsonResponse(409, [
                'error' => 'Dla tego wydarzenia wysłano już wiadomości z kodami QR. Potwierdź, że mimo tego chcesz usunąć listę uczestników.',
                'code' => 'qr_emails_sent',
                'data' => $participantListStats,
            ]);
            exit;
        }

        $pdo->beginTransaction();

        try {
            $summary = $deleteEventParticipantList($pdo, $eventId, $authUser);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }

        jsonResponse(200, ['data' => $summary]);
        exit;
    }

    if (preg_match('#^/events/([^/]+)/participant-imports/replace$#', $path, $matches) === 1 && $method === 'POST') {
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
        $mappingInput = is_array($input['mapping'] ?? null) ? $input['mapping'] : [];
        $confirmQrSent = (bool)($input['confirm_qr_sent'] ?? false);

        if (trim($csvContent) === '') {
            jsonResponse(422, ['error' => 'Pole csv_content jest wymagane']);
            exit;
        }

        $participantListStats = $countEventParticipantListStats($pdo, $eventId);
        if ($participantListStats['sent_qr_email_count'] > 0 && !$confirmQrSent) {
            jsonResponse(409, [
                'error' => 'Dla tego wydarzenia wysłano już wiadomości z kodami QR. Potwierdź, że mimo tego chcesz usunąć starą listę i wgrać nową.',
                'code' => 'qr_emails_sent',
                'data' => $participantListStats,
            ]);
            exit;
        }

        $parsed = $parseCsvContent($csvContent);
        $cleaned = $cleanupCsvDataset($parsed['headers'], $parsed['rows']);
        if ($cleaned['headers'] === []) {
            jsonResponse(422, ['error' => 'Plik CSV nie zawiera żadnych niepustych kolumn danych']);
            exit;
        }

        [$mappingsToSave, $mappingError] = $buildParticipantImportMappingsFromInput($mappingInput);
        if ($mappingError !== null || $mappingsToSave === null) {
            jsonResponse(422, ['error' => $mappingError ?? 'Nieprawidłowe mapowanie CSV']);
            exit;
        }

        $requiredColumns = array_values(array_map(
            static fn(array $mapping): string => (string)$mapping['source_column_name'],
            array_filter($mappingsToSave, static fn(array $mapping): bool => (bool)$mapping['is_required'])
        ));
        $missingColumns = array_values(array_diff($requiredColumns, $cleaned['headers']));
        if ($missingColumns !== []) {
            jsonResponse(422, [
                'error' => 'W pliku CSV brakuje wymaganych kolumn z nowego mapowania',
                'missing_columns' => $missingColumns,
            ]);
            exit;
        }

        $pdo->beginTransaction();

        try {
            $resetSummary = $deleteEventParticipantList($pdo, $eventId, $authUser);
            $insertParticipantImportMappings($pdo, $eventId, $mappingsToSave);
            [$importSummary, $importError] = $importParticipantsFromRows($pdo, $eventId, $cleaned['rows'], $mappingsToSave, $authUser, false);
            if ($importError !== null || $importSummary === null) {
                jsonResponse(500, ['error' => $importError ?? 'Nie udało się zaimportować uczestników']);
                exit;
            }

            $addActivityLog(
                $pdo,
                sprintf(
                    'Podmieniono listę uczestników z CSV: dodano %d, duplikaty %d, niepoprawne %d',
                    (int)$importSummary['created_count'],
                    (int)$importSummary['duplicate_count'],
                    (int)$importSummary['invalid_count']
                ),
                $eventId,
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

        jsonResponse(200, [
            'data' => [
                'reset' => $resetSummary,
                ...$importSummary,
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
        $mappingState = $loadParticipantFieldMappingState($pdo, $eventId);
        jsonResponse(200, [
            'data' => [
                'has_mapping' => $mappingState['has_mapping'],
                'has_baseline_import' => $mappingState['has_baseline_import'],
                'mappings' => $mappings,
            ],
        ]);
        exit;
    }

    if (preg_match('#^/events/([^/]+)/participant-field-mappings$#', $path, $matches) === 1 && $method === 'PATCH') {
        $authUser = requireAnyRole(['superadmin'], $resolveAuthenticatedUser);
        $eventId = (string)$matches[1];
        $event = $loadEventById($pdo, $eventId);

        if ($event === false) {
            jsonResponse(404, ['error' => 'Nie znaleziono wydarzenia']);
            exit;
        }

        if ($isDeletedEvent($event)) {
            jsonResponse(403, ['error' => 'Nie można zmieniać mapowania usuniętego wydarzenia']);
            exit;
        }

        $existingMappings = $loadParticipantFieldMappings($pdo, $eventId);
        if ($existingMappings === []) {
            jsonResponse(404, ['error' => 'To wydarzenie nie ma jeszcze zapisanego mapowania pól uczestnika']);
            exit;
        }

        $input = readJsonBody();
        [$mappingUpdates, $mappingError] = $buildParticipantFieldMappingUpdatesFromInput($existingMappings, $input);
        if ($mappingError !== null || $mappingUpdates === null) {
            jsonResponse(422, ['error' => $mappingError ?? 'Nieprawidłowe mapowanie pól uczestnika']);
            exit;
        }

        $existingMappingsBySourceColumn = [];
        foreach ($existingMappings as $mapping) {
            $existingMappingsBySourceColumn[(string)$mapping['source_column_name']] = $mapping;
        }

        $pdo->beginTransaction();

        try {
            foreach ($mappingUpdates as $mappingUpdate) {
                $sourceColumnName = (string)$mappingUpdate['source_column_name'];
                $existingMapping = $existingMappingsBySourceColumn[$sourceColumnName] ?? null;
                if (is_array($existingMapping)) {
                    $aliasError = $renameParticipantMappingAliasReferences(
                        $pdo,
                        $eventId,
                        (string)$existingMapping['alias'],
                        (string)$mappingUpdate['alias']
                    );
                    if ($aliasError !== null) {
                        $pdo->rollBack();
                        jsonResponse(409, ['error' => $aliasError]);
                        exit;
                    }
                }
            }

            $updateMappingStmt = $pdo->prepare('
                UPDATE event_participant_field_mappings
                SET
                    alias = :alias,
                    field_role = :field_role,
                    display_order = :display_order,
                    is_required = :is_required,
                    is_active = :is_active
                WHERE event_id = :event_id
                  AND source_column_name = :source_column_name
            ');

            foreach ($mappingUpdates as $mappingUpdate) {
                $updateMappingStmt->execute([
                    'event_id' => $eventId,
                    'source_column_name' => $mappingUpdate['source_column_name'],
                    'alias' => $mappingUpdate['alias'],
                    'field_role' => $mappingUpdate['field_role'],
                    'display_order' => (int)$mappingUpdate['display_order'],
                    'is_required' => $mappingUpdate['is_required'] ? 1 : 0,
                    'is_active' => $mappingUpdate['is_active'] ? 1 : 0,
                ]);
            }

            $addActivityLog(
                $pdo,
                'Zaktualizowano mapowanie pól uczestników dla wydarzenia',
                $eventId,
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

        $mappings = $loadParticipantFieldMappings($pdo, $eventId);
        $mappingState = $loadParticipantFieldMappingState($pdo, $eventId);
        jsonResponse(200, [
            'data' => [
                'has_mapping' => $mappingState['has_mapping'],
                'has_baseline_import' => $mappingState['has_baseline_import'],
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
            [
                'important_field_aliases' => is_array($resolvedData['important_field_aliases'] ?? null) ? $resolvedData['important_field_aliases'] : [],
                ...(is_array($resolvedData['custom_fields']) ? $resolvedData['custom_fields'] : []),
            ]
        );
        if ($eventHasParticipantImportBaseline($pdo, $eventId)) {
            $addParticipantChangeLog(
                $pdo,
                $eventId,
                (string)$participant['participant_audit_key'],
                isset($participant['baseline_import_record_id']) ? (int)$participant['baseline_import_record_id'] : null,
                (int)$participant['id'],
                'added',
                'manual',
                [],
                $normalizeParticipantChangeState($participant),
                ['participant_record'],
                (string)$authUser['id'],
                (string)$authUser['name']
            );
        }
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

        $eventCountStmt = $pdo->prepare('SELECT COUNT(*) AS total FROM events WHERE organization_id = :organization_id AND deleted_at IS NULL');
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
        $manualPassword = $input['password'] ?? null;

        if ($manualPassword !== null && !is_string($manualPassword)) {
            jsonResponse(422, ['error' => 'Haslo musi byc tekstem']);
            exit;
        }

        $manualPassword = is_string($manualPassword) ? $manualPassword : '';

        if ($name === '' || $email === '' || $role === '') {
            jsonResponse(422, ['error' => 'Nazwa, adres e-mail i rola są wymagane']);
            exit;
        }

        if (!isValidEmailAddress($email)) {
            jsonResponse(422, ['error' => 'Adres e-mail musi być prawidłowy']);
            exit;
        }

        if ($manualPassword !== '') {
            $passwordValidationError = validatePasswordRules($manualPassword);
            if ($passwordValidationError !== null) {
                jsonResponse(422, ['error' => $passwordValidationError]);
                exit;
            }
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
        $passwordHash = password_hash(
            $manualPassword !== '' ? $manualPassword : bin2hex(random_bytes(24)),
            PASSWORD_DEFAULT
        );

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

            if ($manualPassword === '') {
                $setupToken = $createPasswordResetToken($pdo, $userId, ACCOUNT_SETUP_TTL_SECONDS);
                $setupUrl = appFrontendUrl() . '/reset-password?token=' . urlencode($setupToken);
                MailService::sendAccountSetupEmail($email, $name, $setupUrl);
            }

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

    if (preg_match('#^/users/([^/]+)/password$#', $path, $matches) === 1 && $method === 'PATCH') {
        $authUser = requireAnyRole(['superadmin', 'admin'], $resolveAuthenticatedUser);
        $userId = (string)$matches[1];
        $input = readJsonBody();
        $password = $input['password'] ?? null;

        if (!is_string($password) || $password === '') {
            jsonResponse(422, ['error' => 'Hasło jest wymagane']);
            exit;
        }

        $passwordValidationError = validatePasswordRules($password);
        if ($passwordValidationError !== null) {
            jsonResponse(422, ['error' => $passwordValidationError]);
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

        if (!in_array((string)($targetUser['role'] ?? ''), ['editor', 'scanner', 'scanner_plus'], true)) {
            jsonResponse(422, ['error' => 'Hasło można ustawić ręcznie tylko dla kont organizatorów i operatorów']);
            exit;
        }

        $pdo->beginTransaction();

        try {
            $updatePasswordStmt = $pdo->prepare('UPDATE users SET password = :password WHERE id = :id');
            $updatePasswordStmt->execute([
                'password' => password_hash($password, PASSWORD_DEFAULT),
                'id' => $userId,
            ]);

            $invalidatePasswordResetTokensForUser($pdo, $userId);
            $addActivityLog(
                $pdo,
                sprintf('Ustawiono haslo recznie dla uzytkownika: %s', (string)$targetUser['name']),
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
            $token = $createPasswordResetToken($pdo, (string)$targetUser['id'], ACCOUNT_SETUP_TTL_SECONDS);
            $resetUrl = appFrontendUrl() . '/reset-password?token=' . urlencode($token);
            MailService::sendPasswordSetupEmail((string)$targetUser['email'], (string)$targetUser['name'], $resetUrl);

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
            $wasAlreadySent = (string)($participant['email_status'] ?? '') === 'sent';

            MailService::sendParticipantQrEmail(
                recipientEmail: (string)$participant['email'],
                recipientName: (string)$participant['display_name'],
                eventName: (string)$event['name'],
                eventOfficeWindow: $formatEventOfficeWindow($event),
                eventLocation: (string)$event['location'],
                bibNumber: (string)($participant['bib_number'] ?? ''),
                qrToken: (string)$participant['qr_code'],
                participantCustomFields: is_array($participant['custom_fields'] ?? null) ? $participant['custom_fields'] : [],
                participantImportantFieldAliases: is_array($participant['important_field_aliases'] ?? null) ? $participant['important_field_aliases'] : []
            );

            $updateStmt = $pdo->prepare('UPDATE participants SET email_status = :email_status WHERE id = :id');
            $updateStmt->execute([
                'email_status' => 'sent',
                'id' => (int)$participant['id'],
            ]);
            $participant['email_status'] = 'sent';

            $addActivityLog(
                $pdo,
                $wasAlreadySent ? 'Ponownie wyslano kod QR' : 'Wyslano kod QR',
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

        if ($resendAll && $isEventOfficeOpenNow($event)) {
            jsonResponse(422, ['error' => 'W godzinach działania biura nie można ponownie wysłać kodów QR do wszystkich uczestników.']);
            exit;
        }

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
            $decodedCustomFields = decodeJsonObject($participantRow['custom_fields_json'] ?? null);
            $participantFieldMetadata = $extractParticipantFieldMetadata($decodedCustomFields);
            $participantRow['custom_fields'] = $participantFieldMetadata['custom_fields'];
            $participantRow['important_field_aliases'] = $participantFieldMetadata['important_field_aliases'];
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
                    qrToken: (string)$participantRow['qr_code'],
                    participantCustomFields: is_array($participantRow['custom_fields'] ?? null) ? $participantRow['custom_fields'] : [],
                    participantImportantFieldAliases: is_array($participantRow['important_field_aliases'] ?? null) ? $participantRow['important_field_aliases'] : []
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
                'error' => 'Ten kod QR należy do uczestnika z innego wydarzenia niż aktualnie wybrane.',
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
        $requestedImportantFieldAliases = isset($input['important_field_aliases']) && is_array($input['important_field_aliases'])
            ? $normalizeParticipantImportantFieldAliases($input['important_field_aliases'])
            : null;
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
        $hasParticipantChangeBaseline = $eventHasParticipantImportBaseline($pdo, (string)$participant['event_id']);

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
                    $participantStateBeforeBibUpdate = $normalizeParticipantChangeState($participant);
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
            $participantStateBeforeBibUpdate = $hasParticipantChangeBaseline
                ? $normalizeParticipantChangeState($participant)
                : null;

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
                        'error' => 'Inny uczestnik ma już przypisany ten numer startowy.',
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
                            if ($hasParticipantChangeBaseline) {
                                $addParticipantChangeLog(
                                    $pdo,
                                    (string)$participant['event_id'],
                                    (string)$conflictingParticipant['participant_audit_key'],
                                    isset($conflictingParticipant['baseline_import_record_id']) ? (int)$conflictingParticipant['baseline_import_record_id'] : null,
                                    (int)$conflictingParticipant['id'],
                                    'deleted',
                                    'bib_conflict_resolution',
                                    $normalizeParticipantChangeState($conflictingParticipant),
                                    [],
                                    ['participant_record'],
                                    (string)$authUser['id'],
                                    (string)$authUser['name']
                                );
                            }
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
                    if ($hasParticipantChangeBaseline && is_array($participantStateBeforeBibUpdate)) {
                        $participantStateAfterBibUpdate = $normalizeParticipantChangeState($participant);
                        $changedFields = $diffParticipantChangeStates($participantStateBeforeBibUpdate, $participantStateAfterBibUpdate);
                        if ($changedFields !== []) {
                            $addParticipantChangeLog(
                                $pdo,
                                (string)$participant['event_id'],
                                (string)$participant['participant_audit_key'],
                                isset($participant['baseline_import_record_id']) ? (int)$participant['baseline_import_record_id'] : null,
                                (int)$participant['id'],
                                'updated',
                                'participant_edit',
                                $participantStateBeforeBibUpdate,
                                $participantStateAfterBibUpdate,
                                $changedFields,
                                (string)$authUser['id'],
                                (string)$authUser['name']
                            );
                        }
                    }
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

            $existingCustomFields = [];
            if (is_array($participant['custom_fields'] ?? null)) {
                foreach ($participant['custom_fields'] as $fieldName => $value) {
                    $normalizedFieldName = trim((string)$fieldName);
                    if ($normalizedFieldName === '') {
                        continue;
                    }

                    $existingCustomFields[$normalizedFieldName] = trim((string)$value);
                }
            }

            $participantFieldValues = $fieldValues ?? $existingCustomFields;
            $resolvedData = $buildParticipantDataFromMappings(
                $mappings,
                is_array($participantFieldValues) ? $participantFieldValues : [],
                false,
                (string)($participant['bib_number'] ?? '')
            );
            $mappedAliases = [];
            foreach ($mappings as $mapping) {
                if (!($mapping['is_active'] ?? true) || ($mapping['field_role'] ?? '') === 'email') {
                    continue;
                }

                $alias = trim((string)($mapping['alias'] ?? ''));
                if ($alias === '') {
                    continue;
                }

                $mappedAliases[$alias] = true;
            }

            $resolvedCustomFields = $existingCustomFields;
            foreach (array_keys($mappedAliases) as $alias) {
                unset($resolvedCustomFields[$alias]);
            }

            foreach ((is_array($resolvedData['custom_fields'] ?? null) ? $resolvedData['custom_fields'] : []) as $alias => $value) {
                $normalizedAlias = trim((string)$alias);
                if ($normalizedAlias === '') {
                    continue;
                }

                $resolvedCustomFields[$normalizedAlias] = trim((string)$value);
            }
            ksort($resolvedCustomFields);

            $resolvedImportantFieldAliases = $requestedImportantFieldAliases
                ?? $normalizeParticipantImportantFieldAliases(
                    is_array($participant['important_field_aliases'] ?? null) ? $participant['important_field_aliases'] : []
                );

            if (is_array($resolvedData['important_field_aliases'] ?? null)) {
                $resolvedImportantFieldAliases = $normalizeParticipantImportantFieldAliases(array_merge(
                    $resolvedImportantFieldAliases,
                    $resolvedData['important_field_aliases']
                ));
            }

            $resolvedImportantFieldAliases = array_values(array_filter(
                $resolvedImportantFieldAliases,
                static function (string $alias) use ($resolvedCustomFields): bool {
                    return isset($resolvedCustomFields[$alias]) && trim((string)$resolvedCustomFields[$alias]) !== '';
                }
            ));

            $resolvedDisplayName = $resolveParticipantDisplayName(
                (string)$resolvedData['display_name'],
                $resolvedEmail,
                (string)($participant['display_name'] ?? ''),
                (string)($participant['email'] ?? '')
            );
            $encodedCustomFields = $encodeParticipantCustomFields(
                $resolvedCustomFields,
                $resolvedImportantFieldAliases
            );

            $nameParts = splitParticipantDisplayName($resolvedDisplayName);
            $emailChanged = $resolvedEmail !== normalizeEmailAddress((string)$participant['email']);
            $participantStateBeforeDataUpdate = $normalizeParticipantChangeState($participant);
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
                'custom_fields_json' => $encodedCustomFields !== [] ? encodeJsonValue($encodedCustomFields) : null,
                'email_status' => $emailChanged ? 'not_sent' : (string)$participant['email_status'],
                'id' => (int)$participant['id'],
            ]);

            $participant = $loadParticipantById($pdo, (int)$participant['id']) ?: $participant;
            if ($hasParticipantChangeBaseline) {
                $participantStateAfterDataUpdate = $normalizeParticipantChangeState($participant);
                $changedFields = $diffParticipantChangeStates($participantStateBeforeDataUpdate, $participantStateAfterDataUpdate);
                if ($changedFields !== []) {
                    $addParticipantChangeLog(
                        $pdo,
                        (string)$participant['event_id'],
                        (string)$participant['participant_audit_key'],
                        isset($participant['baseline_import_record_id']) ? (int)$participant['baseline_import_record_id'] : null,
                        (int)$participant['id'],
                        'updated',
                        'participant_edit',
                        $participantStateBeforeDataUpdate,
                        $participantStateAfterDataUpdate,
                        $changedFields,
                        (string)$authUser['id'],
                        (string)$authUser['name']
                    );
                }
            }
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

        if ($eventHasParticipantImportBaseline($pdo, $eventId)) {
            $addParticipantChangeLog(
                $pdo,
                $eventId,
                (string)$participant['participant_audit_key'],
                isset($participant['baseline_import_record_id']) ? (int)$participant['baseline_import_record_id'] : null,
                (int)($participant['id'] ?? 0),
                'added',
                'manual',
                [],
                $normalizeParticipantChangeState($participant),
                ['participant_record'],
                (string)$authUser['id'],
                (string)$authUser['name']
            );
        }

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
    ApplicationLogger::logException($exception, 'database.exception');
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
    ApplicationLogger::logException($exception, 'application.unhandled_exception');
    jsonResponse(500, [
        'error' => 'Server error',
        'details' => getenv('APP_DEBUG') === 'true' ? $exception->getMessage() : null,
    ]);
}
