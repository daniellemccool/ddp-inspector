<?php
// Warm the participant-list cache and print the donation count (last stdout line).
// Run after each refresh as the service user: php <install>/bin/warm-cache.php
// Config comes from DDP_INSPECTOR_CONFIG or ../config.php (see src/bootstrap.php).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../src/bootstrap.php';

try {
    study_participants();
    echo study_donation_count(), "\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'warm-cache: ' . $e->getMessage() . "\n");
    exit(1);
}
