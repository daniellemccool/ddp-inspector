<?php
require_once __DIR__ . '/../src/bootstrap.php';
if (!cfg_ready()) { http_response_code(500); echo 'Configuration missing. Copy config.php.example to config.php.'; return; }
if (!guard_configured()) { return; }

$txIds = analysis_available_ids('transcripts');
$ctx = ['ids' => $txIds, 'fp' => analysis_ids_fingerprint($txIds)];
$loaded = ddp_load_dir_summaries((string)inst_effective_ddp_dir(), $ctx);
$showAll = ($_GET['all'] ?? '') === '1';
$status = inst_status();
$storageMode = inst_root() !== null;

// Per-participant coverage aggregate + the ≥1-transcript display filter.
$rows = []; $hidden = 0;
foreach ($loaded['participants'] as $p) {
    $videos = 0; $transcribed = 0;
    foreach ($p['platforms'] as $entry) {
        $videos += (int)($entry['videos_total'] ?? 0);
        $transcribed += (int)($entry['videos_transcribed'] ?? 0);
    }
    $p['videos_total'] = $videos;
    $p['videos_transcribed'] = $transcribed;
    if ($transcribed === 0 && !$showAll) { $hidden++; continue; }
    $rows[] = $p;
}

// Skip classification: declined donations and empty uploads are normal
// campaign artifacts — summarize them quietly, expandable for detail.
$skipKinds = ['declined' => 0, 'empty' => 0, 'invalid' => 0];
foreach ($loaded['skipped'] as $s) { $skipKinds[$s['kind'] ?? 'invalid']++; }
$skipBits = [];
if ($skipKinds['declined']) { $skipBits[] = $skipKinds['declined'] . ' declined donation(s)'; }
if ($skipKinds['empty'])    { $skipBits[] = $skipKinds['empty'] . ' empty file(s)'; }
if ($skipKinds['invalid'])  { $skipBits[] = $skipKinds['invalid'] . ' unreadable file(s)'; }
// The form below calls csrf_field() well after HTML output has started; force the
// CSRF cookie to be issued now, before any output is sent (setcookie() is a no-op
// once headers are sent — see Task 10 carry-forward constraint).
if ($storageMode) { csrf_token(); }
?>
<!doctype html>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>DDP Inspector — participants</title>
<link rel="stylesheet" href="<?= h(asset_url('assets/style.css')) ?>">
<main class="wrap">
  <header class="site">
    <a class="wordmark" href="<?= h(url('index.php')) ?>">DDP Inspector</a>
    <?php if ($storageMode): ?>
      <nav class="crumbs"><a href="<?= h(url('setup.php')) ?>">Settings</a></nav>
    <?php endif; ?>
  </header>
  <h1>Participants</h1>
  <?php if ($storageMode): ?>
    <div class="toolbar">
      <p class="meta">
        <?php if (!empty($status['finished_at'])): ?>Last updated <?= h((string)$status['finished_at']) ?> ·<?php endif; ?>
        <?= inst_donation_count() ?> donation file(s)
      </p>
      <form method="post" action="<?= h(url('setup.php')) ?>">
        <?= csrf_field() ?><input type="hidden" name="action" value="refresh_now">
        <button>Check for new donations</button>
      </form>
    </div>
    <?php if ($status['phase'] === 'error'): ?>
      <p class="skipped"><?= h((string)$status['message']) ?></p>
    <?php endif; ?>
  <?php endif; ?>
  <?php if ($loaded['skipped']): ?>
    <details class="skipped">
      <summary>⚠ <?= count($loaded['skipped']) ?> file(s) set aside — <?= h(implode(', ', $skipBits)) ?></summary>
      <ul>
      <?php foreach ($loaded['skipped'] as $s): ?>
        <li><?= h($s['participant']) ?> — <?= h($s['kind'] === 'declined' ? 'declined to donate' : ($s['kind'] === 'empty' ? 'empty upload' : 'unreadable file')) ?>
          <span class="meta">(<?= h($s['path']) ?>)</span></li>
      <?php endforeach; ?>
      </ul>
    </details>
  <?php endif; ?>
  <?php if ($hidden > 0 && !$showAll): ?>
    <p class="meta"><?= $hidden ?> participant(s) without transcripts yet are hidden —
      <a href="<?= h(url('index.php?all=1')) ?>">show all</a></p>
  <?php elseif ($showAll): ?>
    <p class="meta">Showing all participants, including those without transcripts —
      <a href="<?= h(url('index.php')) ?>">show only participants with transcripts</a></p>
  <?php endif; ?>
  <?php if (!$rows): ?>
    <p class="notice"><?= $loaded['participants']
        ? 'No participants with transcripts yet — transcription may still be running.'
        : 'No donations yet. Once participants donate (and a fetch has run), they appear here.' ?></p>
  <?php else: ?>
  <table class="scope">
    <thead><tr><th>participant</th><th>platforms</th><th>total rows</th><th>transcribed videos</th><th>earliest</th><th>latest</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $p):
        $total = 0; $earliest = null; $latest = null; $plats = [];
        foreach ($p['platforms'] as $slug => $entry) {
            $scope = stats_scope_from_summaries($entry['tables']);
            $plats[] = $slug;
            $total += $scope['total_rows'];
            if ($scope['earliest'] !== null && ($earliest === null || $scope['earliest'] < $earliest)) { $earliest = $scope['earliest']; }
            if ($scope['latest'] !== null && ($latest === null || $scope['latest'] > $latest)) { $latest = $scope['latest']; }
        } ?>
      <tr>
        <td><a class="id" href="<?= h(url('participant.php?id=' . rawurlencode($p['id']))) ?>"><?= h($p['id']) ?></a></td>
        <td><?= h(implode(', ', $plats)) ?></td>
        <td class="num"><?= number_format($total) ?></td>
        <td class="num"><?= $p['videos_total'] > 0
            ? number_format($p['videos_transcribed']) . ' <span class="of">of ' . number_format($p['videos_total']) . '</span>'
            : '—' ?></td>
        <td class="date"><?= h(fmt_ts($earliest)) ?></td>
        <td class="date"><?= h(fmt_ts($latest)) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</main>
