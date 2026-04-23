<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

$pdo = Database::connect();

$pdo->exec("
    ALTER TABLE event_participant_field_mappings
    MODIFY field_role ENUM('email', 'display_name_part', 'bib_number', 'custom', 'important_custom') NOT NULL
");

echo "Added important_custom participant field role.\n";
