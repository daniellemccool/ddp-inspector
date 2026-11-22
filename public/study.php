<?php
// The study's documentation, as two pages under "Study":
//   study.php                  What participants saw (the study setup exported from Next)
//   study.php?page=collected   What was collected (the codebook of the donation flows)
// Both uploads are optional and are made in Settings. Neither page needs the
// donations to be connected.
//
// Everything shown here came from an upload. Rich text (About, Consent,
// Helpdesk, manual pages) goes through rich_html(); every other value
// through h(), read with setup_text() / setup_list() so that a field of the
// wrong type is simply absent.
require_once __DIR__ . '/../src/bootstrap.php';
if (!cfg_ready()) { http_response_code(500); echo 'Configuration missing. Copy config.php.example to config.php.'; return; }

$storageMode = inst_root() !== null;
$view = ($_GET['page'] ?? '') === 'collected' ? 'collected' : 'seen';
$setup = $storageMode ? setup_load() : null;
$flows = $storageMode ? flows_load_all() : [];
$ov = $setup !== null ? setup_overview($setup) : null;
$studyName = $storageMode ? trim((string)(inst_load()['study_name'] ?? '')) : '';
if ($studyName === '' && $ov !== null) { $studyName = $ov['name']; }

$ext = ' target="_blank" rel="noopener noreferrer"';
$settings = '<a href="' . h(url('setup.php')) . '">Settings</a>';
// A packaged file's address carries a stamp of the upload: Next reuses file
// names (logo.png, document-1.pdf) and the browser may keep a file for an
// hour, so a setup uploaded again must not show the previous one's files.
$assetStamp = substr(md5(setup_text($setup['uploaded']['uploaded_at'] ?? null)), 0, 8);
$asset = fn(string $name): string => h(url('asset.php?f=' . rawurlencode($name) . '&v=' . $assetStamp));
$missingFile = '<span class="meta">not included in the export</span>';

// What a participant met, in order: each is one "screen" on the first page.
// A screen is [id, what it was, its title, what to draw inside its frame].
$meta = $setup['meta'] ?? [];
$assets = $setup['assets'] ?? [];
$screens = [];
if (($about = setup_text($meta['about'] ?? null)) !== '') { $screens[] = ['about', 'Information page', 'About', ['rich' => $about]]; }
if (($consent = setup_text($meta['consent'] ?? null)) !== '') { $screens[] = ['consent', 'Consent', 'Consent', ['rich' => $consent]]; }
if (($privacyRef = setup_text($meta['privacy_statement_pdf'] ?? null)) !== '') { $screens[] = ['privacy', 'Privacy statement', 'Privacy statement', ['privacy' => $privacyRef]]; }
$typeLabels = ['document' => 'document', 'data_donation' => 'data donation', 'questionnaire' => 'questionnaire',
               'instruction_manual' => 'instruction manual'];
$tasks = setup_list($meta['workflow']['tasks'] ?? null);
foreach ($tasks as $i => $task) {
    $type = setup_text($task['type'] ?? null);
    $label = $typeLabels[$type] ?? ($type !== '' ? str_replace('_', ' ', $type) : 'unknown');
    $title = setup_text($task['title'] ?? null);
    // Only the tasks are numbered: the export gives their order. About and
    // Helpdesk are pages a participant can open at any time, not steps.
    $screens[] = ['task-' . ($i + 1), 'Task ' . ($i + 1) . ' of ' . count($tasks) . ' · ' . $label,
                  $title !== '' ? $title : 'Task ' . ($i + 1), ['task' => $task, 'type' => $type, 'title' => $title]];
}
if (($helpdesk = setup_text($meta['helpdesk'] ?? null)) !== '') { $screens[] = ['helpdesk', 'Helpdesk page', 'Helpdesk', ['rich' => $helpdesk]]; }

$subnav = $storageMode ? ['seen' => ['study.php', 'What participants saw'], 'collected' => ['study.php?page=collected', 'What was collected']] : [];
page_top('study', 'study', $studyName !== '' ? '<span class="eyebrow">Study</span> ' . h($studyName) : 'Study', $subnav, $view);
?>
<?php if (!$storageMode): ?>
  <p class="notice">This instance is configured by files on disk (developer mode).</p>

<?php elseif ($view === 'seen'): ?>
  <?php if ($setup === null && !$flows): ?>
    <p class="notice">Nothing describes this study yet. Add a study setup or a donation flow in <?= $settings ?>.</p>
  <?php elseif ($setup === null): ?>
    <p class="notice">No study setup yet, so there is no consent, instructions or tasks to show. Add one in <?= $settings ?>.</p>
  <?php else: ?>
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

        // Where this page's content comes from, said once.
        $line = ['From the study setup'];
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
        echo '<p class="meta">' . implode(' · ', $line) . '</p>';
      ?>
      </div>
    </div>

    <?php if ($ov['format_version'] > 1): ?>
      <p class="notice">This study setup was exported in a newer format (version <?= (int)$ov['format_version'] ?>); some parts may not be shown.</p>
    <?php endif; ?>
    <?php if ($setup['warnings']): $n = count($setup['warnings']); ?>
      <p class="notice">Next could not include <?= $n ?> file<?= $n === 1 ? '' : 's' ?> in the export: <?= h(implode(', ', array_map('basename', $setup['warnings']))) ?>.</p>
    <?php endif; ?>

    <?php if (!$screens): ?>
      <p class="meta">This study setup holds no information pages, consent or tasks.</p>
    <?php else: ?>
      <nav class="contents" aria-label="On this page">
        <p class="eyebrow">What a participant saw</p>
        <ul>
        <?php foreach ($screens as [$id, , $title]): ?><li><a href="#<?= h($id) ?>"><?= h($title) ?></a></li><?php endforeach; ?>
        </ul>
      </nav>
    <?php endif; ?>

    <?php // Each screen: what it was and its title are ours; everything inside
          // the frame is what the participant saw. ?>
    <?php foreach ($screens as [$id, $kind, $title, $body]): ?>
    <section class="screen" id="<?= h($id) ?>">
      <?php // The label says what kind of thing it was; where that is just the
            // title again (Consent, Privacy statement) it is left out. ?>
      <?php if ($kind !== $title): ?><p class="screen__kind"><?= h($kind) ?></p><?php endif; ?>
      <h2><?= h($title) ?></h2>
      <?php // What goes in the frame is drawn first, so that a task the setup says
            // nothing more about gets one line in our voice, not an empty box. ?>
      <?php ob_start(); ?>
      <?php if (isset($body['rich'])): ?>
        <div class="rich"><?= rich_html($body['rich']) ?></div>
      <?php elseif (isset($body['privacy'])): $file = setup_ref($body['privacy'], $assets); ?>
        <p><?php if ($file !== null): ?><a href="<?= $asset($file) ?>"<?= $ext ?>>Open the privacy statement</a><?php else: ?>Privacy statement <?= $missingFile ?><?php endif; ?></p>
      <?php else:
          $task = $body['task']; $type = $body['type'];
          $description = setup_text($task['description'] ?? null); ?>
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
          <?php if ($manualTitle !== '' && $manualTitle !== $body['title']): ?><p class="manual__title"><?= h($manualTitle) ?></p><?php endif; ?>
          <?php if ($manualDescription !== ''): ?><p><?= h($manualDescription) ?></p><?php endif; ?>
          <?php foreach (setup_list($task['chapters'] ?? null) as $chapter):
              $chapterTitle = setup_text($chapter['title'] ?? null); ?>
            <div class="chapter">
              <?php if ($chapterTitle !== ''): ?><h3><?= h($chapterTitle) ?></h3><?php endif; ?>
              <ol class="manual">
              <?php foreach (setup_list($chapter['pages'] ?? null) as $page):
                  $pageTitle = setup_text($page['title'] ?? null);
                  $pageText = setup_text($page['description'] ?? null);
                  $imageRef = setup_text($page['image'] ?? null);
                  $image = setup_ref($imageRef, $assets); ?>
                <li>
                  <div class="manual__text">
                    <?php if ($pageTitle !== ''): ?><p class="manual__page"><?= h($pageTitle) ?></p><?php endif; ?>
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
      <?php endif; ?>
      <?php $inside = trim((string)ob_get_clean()); ?>
      <?php if ($inside !== ''): ?>
      <div class="screen__frame">
      <?= $inside ?>

      </div>
      <?php else: ?>
      <p class="meta">The study setup records nothing more about this task.</p>
      <?php endif; ?>
    </section>
    <?php endforeach; ?>
  <?php endif; ?>

<?php else: /* What was collected */ ?>
  <?php if (!$flows): ?>
    <p class="notice">No donation flow yet, so there is no codebook to show. Add one in <?= $settings ?>.</p>
  <?php else: ?>
    <div class="overview"><div class="overview__facts">
      <p>The tables each donation holds, and what every variable means.</p>
      <p class="meta">From the donation flows · <?= count($flows) ?> platform<?= count($flows) === 1 ? '' : 's' ?></p>
    </div></div>

    <?php foreach ($flows as $slug => $doc): if (!$doc['sections']) { continue; } ?>
      <nav class="contents" aria-label="<?= h($doc['platform_title']) ?> tables">
        <p class="eyebrow"><?= h($doc['platform_title']) ?> tables</p>
        <ul>
        <?php foreach ($doc['sections'] as $n => $sec): ?><li><a href="#t-<?= h((string)$slug) ?>-<?= $n + 1 ?>"><?= h($sec['title']) ?></a></li><?php endforeach; ?>
        </ul>
      </nav>
    <?php endforeach; ?>

    <?php foreach ($flows as $slug => $doc):
        $bits = [];
        $commit = setup_text($doc['commit'] ?? null);
        if ($commit !== '') {
            $short = h(substr($commit, 0, 7));
            $bits[] = 'built from commit ' . (($doc['source_url'] ?? null) !== null
                ? '<a href="' . h((string)$doc['source_url']) . '"' . $ext . '>' . $short . '</a>' : $short);
        }
        $uploaded = setup_day($doc['meta']['uploaded_at'] ?? null);
        if ($uploaded !== '') { $bits[] = 'uploaded ' . h($uploaded); } ?>
      <h2><?= h($doc['platform_title']) ?></h2>
      <?php if ($bits): ?><p class="meta"><?= implode(' · ', $bits) ?></p><?php endif; ?>
      <?php foreach ($doc['sections'] as $n => $sec): ?>
        <h3 id="t-<?= h((string)$slug) ?>-<?= $n + 1 ?>"><?= h($sec['title']) ?></h3>
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
