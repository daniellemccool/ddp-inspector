<?php
require_once __DIR__ . '/../src/bootstrap.php';

$flash = [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && inst_root() !== null) {
    $flash = inst_handle_setup_post($_POST, $_FILES)['flash'];
}
$storageMode = inst_root() !== null;
$flows = $storageMode ? flows_load_all() : [];
$inst = $storageMode ? inst_load() : null;
$status = inst_status();
// The forms below call csrf_field() well after HTML output has started; force the
// CSRF cookie to be issued now, before any output is sent (setcookie() is a no-op
// once headers are sent — see Task 10 carry-forward constraint).
if ($storageMode) { csrf_token(); }
?>
<!doctype html>
<meta charset="utf-8">
<title>DDP Inspector — set up</title>
<link rel="stylesheet" href="<?= h(url('assets/style.css')) ?>">
<main class="wrap">
  <p><a href="<?= h(url('index.php')) ?>">← donations</a></p>
  <h1>Set up this inspector</h1>
  <?php foreach ($flash as $f): ?>
    <p class="<?= $f['kind'] === 'ok' ? 'notice' : 'skipped' ?>"><?= h($f['text']) ?></p>
  <?php endforeach; ?>
  <?php if (!$storageMode): ?>
    <p class="notice">This instance is configured by files on disk (developer mode).</p>
  <?php else: ?>

  <h2>1 · Add your study's donation flow(s)</h2>
  <p>Upload the same zip file(s) you downloaded from the flow builder — one per platform.</p>
  <?php foreach ($flows as $slug => $doc): ?>
    <p class="notice"><?= h($doc['platform_title']) ?> — <?= count($doc['sections']) ?> data tables ✓</p>
  <?php endforeach; ?>
  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?><input type="hidden" name="action" value="upload_flow">
    <input type="file" name="flow_zip" accept=".zip" required>
    <button>Upload flow</button>
  </form>

  <h2>2 · Where are your donations stored?</h2>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_source">
    <p><label>Study name <input name="study_name" value="<?= h((string)($inst['study_name'] ?? '')) ?>"></label></p>
    <p><label><input type="radio" name="source_mode" value="rd-link" <?= ($inst['source_mode'] ?? '') === 'rd-link' ? 'checked' : '' ?>>
      SURF Research Drive</label><br>
      In Research Drive: ① right-click the folder with donations and choose “Share link”,
      ② set it to <em>read only</em> and add a password, ③ paste the link and password here.<br>
      <label>Share link <input name="share_link" placeholder="https://researchdrive…/s/…"></label>
      <label>Password <input type="password" name="link_password" <?= inst_source_exists() ? 'placeholder="saved ✓"' : '' ?>></label></p>
    <p><label><input type="radio" name="source_mode" value="yoda" <?= ($inst['source_mode'] ?? '') === 'yoda' ? 'checked' : '' ?>>
      My data manager gave me an access code</label><br>
      <label>Folder path <input name="collection" value="<?= h((string)(inst_source_load()['collection'] ?? '')) ?>"></label>
      <label>Access code <input type="password" name="access_code" <?= inst_source_exists() ? 'placeholder="saved ✓"' : '' ?>></label></p>
    <p><label><input type="radio" name="source_mode" value="local" <?= ($inst['source_mode'] ?? '') === 'local' ? 'checked' : '' ?>>
      Advanced: a folder on this workspace</label><br>
      <label>Folder <input name="local_path" value="<?= h((string)($inst['local_path'] ?? '')) ?>"></label></p>
    <p><label><input type="checkbox" name="cadence" value="daily" <?= ($inst['cadence'] ?? '') === 'daily' ? 'checked' : '' ?>>
      Check for new donations automatically every day</label></p>
    <button>Save</button>
  </form>

  <h2>3 · Check &amp; fetch</h2>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="check_fetch">
    <button>Check connection and fetch donations</button>
  </form>
  <?php if ($status['phase'] !== 'idle'): ?>
    <p class="<?= $status['phase'] === 'error' ? 'skipped' : 'notice' ?>">
      Status: <?= h((string)$status['phase']) ?><?= $status['message'] !== '' ? ' — ' . h((string)$status['message']) : '' ?>
      <?php if ($status['donations'] !== null): ?> · <?= (int)$status['donations'] ?> donations<?php endif; ?></p>
  <?php endif; ?>
  <details><summary>Technical log</summary><pre><?= h(inst_log_tail()) ?></pre></details>
  <?php endif; ?>
</main>
