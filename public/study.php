<?php
// The study's documentation, in two halves: what participants saw (the study
// setup exported from Next) and what was collected (the codebook of the
// donation flows from the script selector). Both are uploaded in Settings and
// both are optional. The page does not need the donations to be connected.
//
// Everything shown here came from an upload. Rich text (About, Consent,
// Helpdesk, manual pages) goes through rich_html(); every other value
// through h(), read with setup_text() / setup_list() so that a field of the
// wrong type is simply absent.
require_once __DIR__ . '/../src/bootstrap.php';
if (!cfg_ready()) { http_response_code(500); echo 'Configuration missing. Copy config.php.example to config.php.'; return; }

$storageMode = inst_root() !== null;
$setup = $storageMode ? setup_load() : null;
$flows = $storageMode ? flows_load_all() : [];
$ov = $setup !== null ? setup_overview($setup) : null;
$studyName = $storageMode ? trim((string)(inst_load()['study_name'] ?? '')) : '';
if ($studyName === '' && $ov !== null) { $studyName = $ov['name']; }

$ext = ' target="_blank" rel="noopener noreferrer"';
$settings = '<a href="' . h(url('setup.php')) . '">Settings</a>';
$asset = fn(string $name): string => h(url('asset.php?f=' . rawurlencode($name)));
$missingFile = '<span class="meta">not included in the export</span>';

// The parts of the study setup that are there, in the order a participant met them.
$meta = $setup['meta'] ?? [];
$assets = $setup['assets'] ?? [];
$about = setup_text($meta['about'] ?? null);
$consent = setup_text($meta['consent'] ?? null);
$helpdesk = setup_text($meta['helpdesk'] ?? null);
$privacyRef = setup_text($meta['privacy_statement_pdf'] ?? null);
$tasks = setup_list($meta['workflow']['tasks'] ?? null);
$sections = [];
if ($about !== '') { $sections['about'] = 'About'; }
if ($consent !== '') { $sections['consent'] = 'Consent'; }
if ($privacyRef !== '') { $sections['privacy'] = 'Privacy statement'; }
if ($tasks !== []) { $sections['tasks'] = 'Tasks'; }
if ($helpdesk !== '') { $sections['helpdesk'] = 'Helpdesk'; }
$onThisPage = $sections + ($flows ? ['collected' => 'What was collected'] : []);
$typeLabels = ['document' => 'document', 'data_donation' => 'data donation', 'questionnaire' => 'questionnaire',
               'instruction_manual' => 'instruction manual'];

page_top('study', 'study', $studyName !== '' ? '<span class="eyebrow">Study</span> ' . h($studyName) : 'Study');
?>
<?php if (!$storageMode): ?>
  <p class="notice">This instance is configured by files on disk (developer mode).</p>
<?php elseif ($setup === null && !$flows): ?>
  <p class="notice">Nothing describes this study yet. Add a study setup or a donation flow in <?= $settings ?>.</p>
<?php else: ?>
  <?php if ($onThisPage): ?>
  <nav class="onpage niche" aria-label="On this page">
    <span class="onpage__label">On this page</span>
    <?php foreach ($onThisPage as $id => $label): ?><a href="#<?= h($id) ?>"><?= h($label) ?></a><?php endforeach; ?>
  </nav>
  <?php endif; ?>

  <?php if ($ov !== null): ?>
  <div class="overview">
    <?php if ($ov['logo'] !== null): ?><img class="overview__logo" src="<?= $asset($ov['logo']) ?>" alt=""><?php endif; ?>
    <div class="overview__facts">
    <?php
      $line = [];
      if ($ov['public_title'] !== '' && $ov['public_title'] !== $studyName) { $line[] = 'Shown to participants as “' . h($ov['public_title']) . '”'; }
      if ($ov['subtitle'] !== '') { $line[] = h($ov['subtitle']); }
      if ($ov['language'] !== '') { $line[] = h((string)lang_name($ov['language'])); }
      if ($line) { echo '<p>' . implode(' · ', $line) . '</p>'; }

      $people = [];
      foreach ($ov['authors'] as $a) {
          $people[] = $a['email'] !== '' ? '<a href="mailto:' . h($a['email']) . '">' . h($a['name']) . '</a>' : h($a['name']);
      }
      if ($people) { echo '<p>' . implode(', ', $people) . '</p>'; }

      $line = [];
      if ($ov['study_number'] !== null) {
          $pub = $ov['publisher'];
          $line[] = ($pub === null ? 'Next' : ($pub['url'] !== '' ? '<a href="' . h($pub['url']) . '"' . $ext . '>' . h($pub['name']) . '</a>' : h($pub['name'])))
                  . ' study ' . $ov['study_number'];
      }
      if (setup_day($ov['exported_at']) !== '') { $line[] = 'setup exported ' . h(setup_day($ov['exported_at'])); }
      if ($ov['license'] !== null) {
          $lic = $ov['license'];
          $line[] = $lic['url'] !== '' ? '<a href="' . h($lic['url']) . '"' . $ext . '>' . h($lic['name']) . '</a>' : h($lic['name']);
      }
      if ($line) { echo '<p class="meta">' . implode(' · ', $line) . '</p>'; }
    ?>
    </div>
  </div>
  <?php endif; ?>

  <h2>What participants saw</h2>
  <?php if ($setup === null): ?>
    <p class="notice">No study setup yet, so there is no consent, instructions or tasks to show. Add one in <?= $settings ?>.</p>
  <?php else: ?>
    <?php if ($ov['format_version'] > 1): ?>
      <p class="notice">This study setup was exported in a newer format (version <?= (int)$ov['format_version'] ?>); some parts may not be shown.</p>
    <?php endif; ?>
    <?php if ($setup['warnings']): $n = count($setup['warnings']); ?>
      <p class="notice">Next could not include <?= $n ?> file<?= $n === 1 ? '' : 's' ?> in the export: <?= h(implode(', ', array_map('basename', $setup['warnings']))) ?>.</p>
    <?php endif; ?>
    <?php if (!$sections): ?>
      <p class="meta">This study setup holds no information pages, consent or tasks.</p>
    <?php endif; ?>

    <?php if ($about !== ''): ?>
      <h3 id="about">About</h3>
      <div class="rich"><?= rich_html($about) ?></div>
    <?php endif; ?>

    <?php if ($consent !== ''): ?>
      <h3 id="consent">Consent</h3>
      <div class="rich"><?= rich_html($consent) ?></div>
    <?php endif; ?>

    <?php if ($privacyRef !== ''): $file = setup_ref($privacyRef, $assets); ?>
      <h3 id="privacy">Privacy statement</h3>
      <p><?php if ($file !== null): ?><a href="<?= $asset($file) ?>"<?= $ext ?>>Open the privacy statement</a><?php else: ?><?= $missingFile ?><?php endif; ?></p>
    <?php endif; ?>

    <?php if ($tasks !== []): ?>
      <h3 id="tasks">Tasks</h3>
      <ol class="tasks">
      <?php foreach ($tasks as $task):
          $type = setup_text($task['type'] ?? null);
          $label = $typeLabels[$type] ?? ($type !== '' ? str_replace('_', ' ', $type) : 'unknown');
          $title = setup_text($task['title'] ?? null);
          $description = setup_text($task['description'] ?? null); ?>
        <li class="task">
          <h4><?= h($title !== '' ? $title : 'Untitled task') ?> <span class="task__type"><?= h($label) ?></span></h4>
          <?php if ($description !== ''): ?><p><?= h($description) ?></p><?php endif; ?>
          <?php if ($type === 'document'):
              $filename = setup_text($task['filename'] ?? null);
              $file = setup_ref($task['file'] ?? null, $assets); ?>
            <p><?php if ($file !== null): ?><a href="<?= $asset($file) ?>"<?= $ext ?>><?= h($filename !== '' ? $filename : $file) ?></a>
               <?php else: ?><?= h($filename) ?> <?= $missingFile ?><?php endif; ?></p>
          <?php elseif ($type === 'data_donation'):
              $filename = setup_text($task['filename'] ?? null); ?>
            <?php if ($filename !== ''): ?><p class="meta">Script the study ran: <span class="id"><?= h($filename) ?></span></p><?php endif; ?>
          <?php elseif ($type === 'questionnaire'):
              $link = setup_text($task['link'] ?? null); $url = setup_web_url($link); ?>
            <?php if ($url !== null): ?><p><a class="url" href="<?= h($url) ?>"<?= $ext ?>><?= h($url) ?></a></p>
            <?php elseif ($link !== ''): ?><p class="url"><?= h($link) ?></p><?php endif; ?>
          <?php elseif ($type === 'instruction_manual'):
              $manualTitle = setup_text($task['manual_title'] ?? null);
              $manualDescription = setup_text($task['manual_description'] ?? null); ?>
            <?php if ($manualTitle !== '' && $manualTitle !== $title): ?><p class="manual__title"><?= h($manualTitle) ?></p><?php endif; ?>
            <?php if ($manualDescription !== ''): ?><p><?= h($manualDescription) ?></p><?php endif; ?>
            <?php foreach (setup_list($task['chapters'] ?? null) as $chapter): ?>
              <div class="chapter">
                <h5><?= h(setup_text($chapter['title'] ?? null)) ?></h5>
                <ol class="manual">
                <?php foreach (setup_list($chapter['pages'] ?? null) as $page):
                    $pageText = setup_text($page['description'] ?? null);
                    $imageRef = setup_text($page['image'] ?? null);
                    $image = setup_ref($imageRef, $assets); ?>
                  <li>
                    <div class="manual__text">
                      <p class="manual__page"><?= h(setup_text($page['title'] ?? null)) ?></p>
                      <?php if ($pageText !== ''): ?><div class="rich"><?= rich_html($pageText) ?></div><?php endif; ?>
                    </div>
                    <?php if ($image !== null): ?><a class="manual__shot" href="<?= $asset($image) ?>"<?= $ext ?>><img src="<?= $asset($image) ?>" alt="Screenshot for this step" loading="lazy"></a>
                    <?php elseif ($imageRef !== ''): ?><p>Screenshot <?= $missingFile ?></p><?php endif; ?>
                  </li>
                <?php endforeach; ?>
                </ol>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
      </ol>
    <?php endif; ?>

    <?php if ($helpdesk !== ''): ?>
      <h3 id="helpdesk">Helpdesk</h3>
      <div class="rich"><?= rich_html($helpdesk) ?></div>
    <?php endif; ?>
  <?php endif; ?>

  <h2 id="collected">What was collected</h2>
  <?php if (!$flows): ?>
    <p class="notice">No donation flow yet, so there is no codebook to show. Add one in <?= $settings ?>.</p>
  <?php else: ?>
    <?php foreach ($flows as $doc):
        $bits = [];
        $commit = setup_text($doc['commit'] ?? null);
        if ($commit !== '') {
            $short = h(substr($commit, 0, 7));
            $bits[] = 'built from commit ' . (($doc['source_url'] ?? null) !== null
                ? '<a href="' . h((string)$doc['source_url']) . '"' . $ext . '>' . $short . '</a>' : $short);
        }
        $uploaded = setup_day($doc['meta']['uploaded_at'] ?? null);
        if ($uploaded !== '') { $bits[] = 'uploaded ' . h($uploaded); } ?>
      <h3><?= h($doc['platform_title']) ?></h3>
      <?php if ($bits): ?><p class="meta"><?= implode(' · ', $bits) ?></p><?php endif; ?>
      <?php foreach ($doc['sections'] as $sec): ?>
        <h4><?= h($sec['title']) ?></h4>
        <?php if ($sec['description'] !== ''): ?><p class="meta"><?= h($sec['description']) ?></p><?php endif; ?>
        <?php if ($sec['vars']): ?>
        <table class="codebook">
          <thead><tr><th>Variable</th><th>Description</th></tr></thead>
          <tbody>
          <?php foreach ($sec['vars'] as $var): ?>
            <tr><td class="id"><?= h((string)$var) ?></td><td><?= h((string)($sec['var_desc'][$var] ?? '')) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      <?php endforeach; ?>
    <?php endforeach; ?>
  <?php endif; ?>
<?php endif; ?>
<?php page_bottom(); ?>
