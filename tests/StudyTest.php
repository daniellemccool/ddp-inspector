<?php
// Data layer core: declaration, file location, status/shape check, read-only open.
require_once __DIR__ . '/../src/bootstrap.php';

function study_test_build_db(string $file): void {
    @unlink($file);
    $pdo = new PDO('sqlite:' . $file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec((string)file_get_contents(__DIR__ . '/fixtures/analysis.sql'));
}

$sc = sys_get_temp_dir() . '/ddp-inspector-study-' . getmypid();
exec('rm -rf ' . escapeshellarg($sc));
$saved_study_cfg = $GLOBALS['__cfg'];
$GLOBALS['__cfg'] = ['storage_root' => $sc, 'default_n' => 15, 'base_path' => ''];
@mkdir("$sc/data/analyses", 0755, true);
$dbfile = "$sc/data/analyses/analysis.sqlite";
$base = ['study_name' => 'Pilot', 'source_mode' => 'local', 'local_path' => '/tmp/x', 'cadence' => 'off', 'default_n' => 15];

inst_save($base);
eq(study_db_decl(), null, 'no declaration -> null');
eq(study_db_status(), 'off', 'status off without declaration');
eq(study_mode(), 'raw', 'mode raw when off');
eq(study_db_notice(), null, 'no notice when off');
eq(study_db(), null, 'no connection when off');

inst_save($base + ['analyses' => ['database' => ['enabled' => false, 'path' => 'x.sqlite']]]);
eq(study_db_status(), 'off', 'disabled declaration is off');

inst_save($base + ['analyses' => ['database' => ['enabled' => true, 'path' => 'x.sqlite']]]);
eq(study_db_decl(), ['path' => 'x.sqlite'], 'enabled declaration returns path');
eq(study_db_file(), $dbfile, 'storage-mode file location');
eq(study_db_status(), 'missing', 'declared, no file -> missing');
eq(study_mode(), 'raw', 'mode raw when missing');
eq(study_db_notice(), "The analysis database hasn't been fetched yet — run Check & fetch in Settings.", 'missing notice');
eq(study_db(), null, 'no connection when missing');

touch($dbfile);
eq(study_db_status(), 'invalid', 'empty file -> invalid');
$e = new PDO('sqlite:' . $dbfile); $e->exec('CREATE TABLE unrelated (a)');
eq(study_db_status(), 'invalid', 'wrong shape -> invalid');
eq(study_mode(), 'raw', 'mode raw when invalid');
eq(study_db_notice(), "The analysis database doesn't look like a pipeline analysis database.", 'invalid notice');
$e = null;

study_test_build_db($dbfile);
eq(study_db_status(), 'ok', 'fixture -> ok');
eq(study_mode(), 'database', 'mode database when ok');
eq(study_db_notice(), null, 'no notice when ok');

$db = study_db();
check($db instanceof PDO, 'study_db returns a PDO when ok');
eq(study_db(), $db, 'one connection per request');
eq((int)$db->query('SELECT COUNT(*) FROM participant')->fetchColumn(), 5, 'fixture readable through study_db');
$threw = false;
try { $db->exec("INSERT INTO meta VALUES ('x', 'y')"); } catch (Throwable $t) { $threw = true; }
eq($threw, true, 'INSERT through the read-only connection throws');
eq((int)(new PDO('sqlite:' . $dbfile))->query("SELECT COUNT(*) FROM meta WHERE key = 'x'")->fetchColumn(), 0, 'nothing was written');

// Developer mode: no storage root, location comes from config.
// A missing column makes the shape invalid.
$bad = "$sc/bad.sqlite";
study_test_build_db($bad);
$b = new PDO('sqlite:' . $bad); $b->exec('ALTER TABLE video DROP COLUMN uploader_id'); $b = null;
$GLOBALS['__cfg'] = ['ddp_dir' => __DIR__ . '/fixtures/ddp', 'analysis_db' => $bad];
eq(study_db_file(), $bad, 'developer-mode file location from config');
eq(study_db_status(), 'invalid', 'developer mode reads shape (dropped column -> invalid)');
$GLOBALS['__cfg'] = ['ddp_dir' => __DIR__ . '/fixtures/ddp'];
eq(study_db_file(), null, 'no file location without storage root or config');
eq(study_db_status(), 'off', 'developer mode without analysis_db is off');

$GLOBALS['__cfg'] = $saved_study_cfg;
exec('rm -rf ' . escapeshellarg($sc));
