<?php
require_once __DIR__ . '/../src/bootstrap.php';
if (!cfg_ready()) { http_response_code(500); echo 'Configuration missing. Copy config.php.example to config.php.'; return; }
if (!guard_configured()) { return; }

$loaded = ddp_load_dir_summaries((string)inst_effective_ddp_dir());
$status = inst_status();
$storageMode = inst_root() !== null;
// The form below calls csrf_field() well after HTML output has started; force the
// CSRF cookie to be issued now, before any output is sent (setcookie() is a no-op
// once headers are sent — see Task 10 carry-forward constraint).
if ($storageMode) { csrf_token(); }
?>
<!doctype html>
<meta charset="utf-8">
<title>DDP Inspector — participants</title>
<link rel="stylesheet" href="<?= h(url('assets/style.css')) ?>">
<main class="wrap">
  <h1>DDP Inspector</h1>
  <?php if ($storageMode): ?>
    <p class="meta">
      <?php if (!empty($status['finished_at'])): ?>Last updated <?= h((string)$status['finished_at']) ?> ·<?php endif; ?>
      <?= inst_donation_count() ?> donation file(s)
      <?php if ($status['phase'] === 'error'): ?><span class="skipped"><?= h((string)$status['message']) ?></span><?php endif; ?>
    </p>
    <form method="post" action="<?= h(url('setup.php')) ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="refresh_now">
      <button>Check for new donations</button>
    </form>
    <p class="meta"><a href="<?= h(url('setup.php')) ?>">Settings</a></p>
  <?php endif; ?>
  <?php if ($loaded['skipped']): ?>
    <p class="skipped">⚠ <?= count($loaded['skipped']) ?> file(s) skipped (non-conforming):
      <?= h(implode(', ', array_column($loaded['skipped'], 'path'))) ?></p>
  <?php endif; ?>
  <?php if (!$loaded['participants']): ?>
    <p class="notice">No donations yet. Once participants donate (and a fetch has run), they appear here.</p>
  <?php else: ?>
  <table class="scope">
    <thead><tr><th>participant</th><th>platforms</th><th>total rows</th><th>earliest</th><th>latest</th></tr></thead>
    <tbody>
    <?php foreach ($loaded['participants'] as $p):
        $total = 0; $earliest = null; $latest = null; $plats = [];
        foreach ($p['platforms'] as $slug => $entry) {
            $scope = stats_scope_from_summaries($entry['tables']);
            $plats[] = $slug;
            $total += $scope['total_rows'];
            if ($scope['earliest'] !== null && ($earliest === null || $scope['earliest'] < $earliest)) { $earliest = $scope['earliest']; }
            if ($scope['latest'] !== null && ($latest === null || $scope['latest'] > $latest)) { $latest = $scope['latest']; }
        } ?>
      <tr>
        <td><a href="<?= h(url('participant.php?id=' . rawurlencode($p['id']))) ?>"><?= h($p['id']) ?></a></td>
        <td><?= h(implode(', ', $plats)) ?></td>
        <td class="num"><?= number_format($total) ?></td>
        <td><?= h(fmt_ts($earliest)) ?></td>
        <td><?= h(fmt_ts($latest)) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</main>
