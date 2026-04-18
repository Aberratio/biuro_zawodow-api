<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

$pdo = Database::connect();

$uniqueBibIndexStmt = $pdo->query("
    SHOW INDEX FROM participants
    WHERE Key_name = 'uq_participants_event_bib'
");
$uniqueBibIndexExists = $uniqueBibIndexStmt !== false && $uniqueBibIndexStmt->fetch() !== false;

if ($uniqueBibIndexExists) {
    $pdo->exec("
        ALTER TABLE participants
        DROP INDEX uq_participants_event_bib
    ");
}

$eventBibIndexStmt = $pdo->query("
    SHOW INDEX FROM participants
    WHERE Key_name = 'idx_participants_event_bib'
");
$eventBibIndexExists = $eventBibIndexStmt !== false && $eventBibIndexStmt->fetch() !== false;

if (!$eventBibIndexExists) {
    $pdo->exec("
        ALTER TABLE participants
        ADD KEY idx_participants_event_bib (event_id, bib_number)
    ");
}

echo "Participant bib numbers can now be duplicated within the same event.\n";
