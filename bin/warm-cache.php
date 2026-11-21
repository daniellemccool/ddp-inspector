<?php
// Warm the participant-list cache and print the donation count (last stdout line).
// Run after each refresh as the service user: php <install>/bin/warm-cache.php
// Config comes from DDP_INSPECTOR_CONFIG or ../config.php (see src/bootstrap.php).
// Exits 1 when the list could not be built (a declared database that is not
// usable, or a failed query), so the refresh logs "cache warm-up failed"
// instead of reporting a count while the index is empty.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../src/bootstrap.php';

try {
    if (study_db_unusable()) {
        fwrite(STDERR, 'warm-cache: the analysis database is ' . study_db_status() . "\n");
        exit(1);
    }
    study_participants();
    if (study_db_list_failed()) {
        fwrite(STDERR, "warm-cache: the participant list query failed (see the PHP error log)\n");
        exit(1);
    }
    echo study_donation_count(), "\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'warm-cache: ' . $e->getMessage() . "\n");
    exit(1);
}
