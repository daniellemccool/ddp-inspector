<?php
require_once __DIR__ . '/../src/bootstrap.php';

$flash = [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && inst_root() !== null) {
    // An upload over PHP's limit arrives empty: say so, instead of letting
    // the handler report an expired form.
    $tooLarge = inst_post_too_large($_SERVER, $_POST, $_FILES, (string)ini_get('post_max_size'));
    $flash = $tooLarge !== null ? [['kind' => 'error', 'text' => $tooLarge]]
                                : inst_handle_setup_post($_POST, $_FILES)['flash'];
}
// Step 01 is three lines that open. A message about one of them is shown
// inside that line, which comes back open; any other message stays at the top.
$partOf = ['save_name' => 'part-name', 'upload_setup' => 'part-setup', 'remove_setup' => 'part-setup',
           'upload_flow' => 'part-flows', 'remove_flow' => 'part-flows'];
$posted = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
$openPart = $flash !== [] ? ($partOf[$posted] ?? null) : null;
$showFlash = function (array $flash): void {
    foreach ($flash as $f) { echo '<p class="' . ($f['kind'] === 'ok' ? 'notice' : 'warn') . '">' . h($f['text']) . "</p>\n"; }
};
$storageMode = inst_root() !== null;
$flows = $storageMode ? flows_load_all() : [];
$studySetup = $storageMode ? setup_load() : null;
$inst = $storageMode ? inst_load() : null;
$status = inst_status();
// The forms below call csrf_field() well after HTML output has started; force the
// CSRF cookie to be issued now, before any output is sent (setcookie() is a no-op
// once headers are sent — see Task 10 carry-forward constraint).
if ($storageMode) { csrf_token(); }
?>
<?php page_top('set up', 'settings', 'Set up this inspector'); ?>
  <?php if ($openPart === null) { $showFlash($flash); } ?>
  <?php if (!$storageMode): ?>
    <p class="notice">This instance is configured by files on disk (developer mode).</p>
  <?php else: ?>

  <?php
  $ext = ' target="_blank" rel="noopener noreferrer"';
  $self = h(url('setup.php'));
  // The opening tag of one line of step 01: a filled node when the thing is
  // there, a ring when it is not; open when it was just acted on.
  $partOpen = fn(string $id, bool $there): string =>
      '<details class="part part--' . ($there ? 'on' : 'off') . '" id="' . $id . '"' . ($openPart === $id ? ' open' : '') . '>';
  // The word at the end of a line: what a click will let you do. CSS shows
  // the one that fits (Close while open, else the first or the second).
  $partWords = fn(string $add): string =>
      '<span class="part__word part__word--add">' . $add . '</span><span class="part__word part__word--change">Change</span>'
      . '<span class="part__word part__word--close">Close</span>';
  $studyName = trim((string)($inst['study_name'] ?? ''));
  $stored = $studySetup !== null ? setup_summary($studySetup) : null;
  ?>
  <div class="steps">
  <section class="step">
  <h2><span class="step__num">01</span> Describe your study <span class="optional">optional</span></h2>
  <p class="meta">None of this is needed to browse donations. It names the study and fills the Study page.</p>

  <div class="parts">
  <?= $partOpen('part-name', $studyName !== '') ?>
    <summary><span class="part__name">Study name</span><span class="part__status"><?= $studyName !== '' ? h($studyName) : 'Not set.' ?></span><?= $partWords('Set') ?></summary>
    <div class="part__body">
      <?php if ($openPart === 'part-name') { $showFlash($flash); } ?>
      <form method="post" action="<?= $self ?>#part-name" class="toolbar">
        <?= csrf_field() ?><input type="hidden" name="action" value="save_name">
        <label class="field">Study name
          <input name="study_name" value="<?= h($studyName) ?>"></label>
        <button>Save<?= go_mark() ?></button>
      </form>
    </div>
  </details>

  <?= $partOpen('part-setup', $stored !== null) ?>
    <summary><span class="part__name">Study setup</span><span class="part__status"><?php if ($stored !== null): ?><?= h($stored['name']) ?> <span class="part__when"><?= h($stored['when']) ?></span><?php else: ?>Not added. The Study page has no consent, instructions or tasks.<?php endif; ?></span><?= $partWords('Add') ?></summary>
    <div class="part__body">
      <?php if ($openPart === 'part-setup') { $showFlash($flash); } ?>
      <p>What participants saw: information pages, consent, instructions and tasks. It fills the first half of the Study page.</p>
      <p class="meta">From <a href="https://next.eyra.co/"<?= $ext ?>>Next</a>: open the study, open the ⋮ menu next to Publish and choose Export.</p>
      <?php if ($stored !== null): ?>
        <ul class="flows">
          <li><span><span class="flows__name"><?= h($stored['name']) ?></span> <?= h($stored['when']) ?></span>
            <form method="post" action="<?= $self ?>#part-setup">
              <?= csrf_field() ?><input type="hidden" name="action" value="remove_setup">
              <button class="quiet">Remove</button>
            </form></li>
        </ul>
      <?php endif; ?>
      <form method="post" action="<?= $self ?>#part-setup" enctype="multipart/form-data" class="toolbar">
        <?= csrf_field() ?><input type="hidden" name="action" value="upload_setup">
        <input type="file" name="setup_zip" accept=".zip" required>
        <button>Upload setup<?= go_mark() ?></button>
      </form>
    </div>
  </details>

  <?= $partOpen('part-flows', $flows !== []) ?>
    <summary><span class="part__name">Donation flows</span><span class="part__status"><?php if ($flows): ?><?= count($flows) ?> added <span class="part__when"><?= h(implode(', ', array_column($flows, 'platform_title'))) ?></span><?php else: ?>Not added. Tables keep their raw names, and the Study page has no codebook.<?php endif; ?></span><?= $partWords('Add') ?></summary>
    <div class="part__body">
      <?php if ($openPart === 'part-flows') { $showFlash($flash); } ?>
      <p>What was collected: the tables and what each variable means. They title the tables and fill the codebook on the Study page.</p>
      <p class="meta">From the <a href="https://datadonation.eu/software/script-selector/"<?= $ext ?>>script selector</a>: one zip per platform.</p>
      <?php if ($flows): ?>
        <ul class="flows">
        <?php foreach ($flows as $slug => $doc): ?>
          <li><span><span class="flows__name"><?= h($doc['platform_title']) ?></span> <?= count($doc['sections']) ?> data tables</span>
            <form method="post" action="<?= $self ?>#part-flows">
              <?= csrf_field() ?><input type="hidden" name="action" value="remove_flow">
              <input type="hidden" name="slug" value="<?= h((string)$slug) ?>">
              <button class="quiet">Remove</button>
            </form></li>
        <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <form method="post" action="<?= $self ?>#part-flows" enctype="multipart/form-data" class="toolbar">
        <?= csrf_field() ?><input type="hidden" name="action" value="upload_flow">
        <input type="file" name="flow_zip" accept=".zip" required>
        <button>Upload flow<?= go_mark() ?></button>
      </form>
    </div>
  </details>
  </div>
  </section>

  <section class="step">
  <h2><span class="step__num">02</span> Connect your donations</h2>
  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_source">
    <h3>Where are your donations stored?</h3>
    <div class="choices">
    <div class="choice">
      <label class="choice-head"><input type="radio" name="source_mode" value="rd-link" <?= ($inst['source_mode'] ?? '') === 'rd-link' ? 'checked' : '' ?>>
        SURF Research Drive</label>
      <div class="choice-body">
        <ol>
          <li>In Research Drive, right-click the study's folder and choose “Share link”.</li>
          <li>Set it to <em>read only</em> and add a password.</li>
          <li>Paste the link and the password here.</li>
        </ol>
        <label class="field">Share link <input name="share_link" placeholder="https://researchdrive…/s/…"></label>
        <label class="field">Password <input type="password" name="link_password" <?= inst_source_exists() ? 'placeholder="saved ✓"' : '' ?>></label>
      </div>
    </div>
    <div class="choice">
      <label class="choice-head"><input type="radio" name="source_mode" value="yoda" <?= ($inst['source_mode'] ?? '') === 'yoda' ? 'checked' : '' ?>>
        Yoda</label>
      <div class="choice-body">
<?php $ysrc = inst_source_load() ?? []; $yoda = ($ysrc['mode'] ?? '') === 'yoda'; ?>
        Log in with your own Yoda account. In the Yoda portal's data transfer instructions, download the configuration (JSON or YAML) and upload it below, or copy the block and paste it, and create a data access password there too — it is not your UU password, and it expires.
        <label class="field">Folder path <input name="collection" placeholder="/nluu10p/home/research-…" value="<?= h((string)($ysrc['collection'] ?? '')) ?>"></label>
        <label class="field">Yoda configuration file <input type="file" name="irods_config_file" accept=".json,.yml,.yaml"></label>
        <label class="field">…or paste the configuration <textarea name="irods_config" rows="8" spellcheck="false" placeholder="{ &quot;irods_host&quot;: …, &quot;irods_user_name&quot;: … }"><?= $yoda && is_array($ysrc['env'] ?? null) ? h((string)json_encode($ysrc['env'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) : '' ?></textarea></label>
        <label class="field">Data access password <input type="password" name="yoda_password" autocomplete="off" <?= $yoda && (string)($ysrc['password'] ?? '') !== '' ? 'placeholder="saved ✓"' : '' ?>></label>
      </div>
    </div>
    <div class="choice">
      <label class="choice-head"><input type="radio" name="source_mode" value="local" <?= ($inst['source_mode'] ?? '') === 'local' ? 'checked' : '' ?>>
        A folder on this workspace</label>
      <div class="choice-body">
        <?php $localCandidates = inst_local_folder_candidates(); ?>
        <?php if ($localCandidates !== []): ?>
          Pick the study's folder. Folders with donation files found on this workspace are listed — click the field to choose one.
        <?php endif; ?>
        <label class="field">The study's folder <input name="local_path" list="local-folders" value="<?= h((string)($inst['local_path'] ?? '')) ?>"></label>
        <?php if ($localCandidates !== []): ?>
          <datalist id="local-folders">
            <?php foreach ($localCandidates as $c): ?><option value="<?= h($c) ?>"></option><?php endforeach; ?>
          </datalist>
        <?php endif; ?>
      </div>
    </div>
    </div>
    <?php $adb = $inst['analyses']['database'] ?? []; ?>
    <h3>Analyses</h3>
    <p><label class="check"><input type="checkbox" name="analysis_db_enabled" value="1" <?= !empty($adb['enabled']) ? 'checked' : '' ?>>
      This study has an analysis database</label></p>
    <label class="field">Path in the study's folder
      <input name="analysis_db_path" value="<?= h((string)($adb['path'] ?? 'analysis/out/analysis.sqlite')) ?>"></label>
    <button>Save<?= go_mark() ?></button>
  </form>
  </section>

  <section class="step">
  <h2><span class="step__num">03</span> Fetch</h2>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="check_fetch">
    <button>Check connection and fetch donations<?= go_mark() ?></button>
  </form>
  <form method="post" class="toolbar">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_cadence">
    <label class="check"><input type="checkbox" name="cadence" value="daily" <?= ($inst['cadence'] ?? '') === 'daily' ? 'checked' : '' ?>>
      Check for new donations automatically every day</label>
    <button class="quiet">Save</button>
  </form>
  <?php if ($status['phase'] !== 'idle'): ?>
    <p class="<?= $status['phase'] === 'error' ? 'warn' : 'notice' ?>">
      Status: <?= h((string)$status['phase']) ?><?= $status['message'] !== '' ? ' — ' . h((string)$status['message']) : '' ?>
      <?php if ($status['donations'] !== null): ?> · <?= (int)$status['donations'] ?> donations<?php endif; ?></p>
  <?php endif; ?>
  <details class="raw"><summary>Technical log</summary><pre><?= h(inst_log_tail()) ?></pre></details>
  </section>
  </div>
  <?php endif; ?>
<?php page_bottom(); ?>
