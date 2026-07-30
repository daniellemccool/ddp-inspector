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
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>DDP Inspector — set up</title>
<link rel="stylesheet" href="<?= h(url('assets/style.css')) ?>">
<main class="wrap">
  <header class="site">
    <a class="wordmark" href="<?= h(url('index.php')) ?>">DDP Inspector</a>
    <nav class="crumbs"><a href="<?= h(url('index.php')) ?>">← all participants</a></nav>
  </header>
  <h1>Set up this inspector</h1>
  <?php foreach ($flash as $f): ?>
    <p class="<?= $f['kind'] === 'ok' ? 'notice' : 'skipped' ?>"><?= h($f['text']) ?></p>
  <?php endforeach; ?>
  <?php if (!$storageMode): ?>
    <p class="notice">This instance is configured by files on disk (developer mode).</p>
  <?php else: ?>

  <section class="step">
  <h2>1 · Add your study's donation flow(s)</h2>
  <p class="meta">Upload the same zip file(s) you downloaded from the flow builder — one per platform.</p>
  <?php foreach ($flows as $slug => $doc): ?>
    <p class="notice"><?= h($doc['platform_title']) ?> — <?= count($doc['sections']) ?> data tables ✓</p>
  <?php endforeach; ?>
  <form method="post" enctype="multipart/form-data" class="toolbar">
    <?= csrf_field() ?><input type="hidden" name="action" value="upload_flow">
    <input type="file" name="flow_zip" accept=".zip" required>
    <button>Upload flow</button>
  </form>
  </section>

  <section class="step">
  <h2>2 · About your study</h2>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_source">
    <label class="field">Study name
      <input name="study_name" value="<?= h((string)($inst['study_name'] ?? '')) ?>"></label>
    <h3>Where are your donations stored?</h3>
    <div class="choice">
      <label class="choice-head"><input type="radio" name="source_mode" value="rd-link" <?= ($inst['source_mode'] ?? '') === 'rd-link' ? 'checked' : '' ?>>
        SURF Research Drive</label>
      <div class="choice-body">
        In Research Drive: ① right-click the folder with donations and choose “Share link”,
        ② set it to <em>read only</em> and add a password, ③ paste the link and password here.
        <label class="field">Share link <input name="share_link" placeholder="https://researchdrive…/s/…"></label>
        <label class="field">Password <input type="password" name="link_password" <?= inst_source_exists() ? 'placeholder="saved ✓"' : '' ?>></label>
      </div>
    </div>
    <div class="choice">
      <label class="choice-head"><input type="radio" name="source_mode" value="yoda" <?= ($inst['source_mode'] ?? '') === 'yoda' ? 'checked' : '' ?>>
        Yoda</label>
      <div class="choice-body">
        Your data manager gives you a folder path and an access code — paste both here.
        <label class="field">Folder path <input name="collection" value="<?= h((string)(inst_source_load()['collection'] ?? '')) ?>"></label>
        <label class="field">Access code <input type="password" name="access_code" <?= inst_source_exists() ? 'placeholder="saved ✓"' : '' ?>></label>
      </div>
    </div>
    <div class="choice">
      <label class="choice-head"><input type="radio" name="source_mode" value="local" <?= ($inst['source_mode'] ?? '') === 'local' ? 'checked' : '' ?>>
        A folder on this workspace</label>
      <div class="choice-body">
        <?php $localCandidates = inst_local_folder_candidates(); ?>
        <?php if ($localCandidates !== []): ?>
          Folders with donation files found on this workspace — click the field to pick one.
        <?php endif; ?>
        <label class="field">Folder <input name="local_path" list="local-folders" value="<?= h((string)($inst['local_path'] ?? '')) ?>"></label>
        <?php if ($localCandidates !== []): ?>
          <datalist id="local-folders">
            <?php foreach ($localCandidates as $c): ?><option value="<?= h($c) ?>"></option><?php endforeach; ?>
          </datalist>
        <?php endif; ?>
      </div>
    </div>
    <p><label class="meta"><input type="checkbox" name="cadence" value="daily" <?= ($inst['cadence'] ?? '') === 'daily' ? 'checked' : '' ?>>
      Check for new donations automatically every day</label></p>
    <button>Save</button>
  </form>
  </section>

  <section class="step">
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
  <details class="raw"><summary>Technical log</summary><pre><?= h(inst_log_tail()) ?></pre></details>
  </section>
  <?php endif; ?>
</main>
