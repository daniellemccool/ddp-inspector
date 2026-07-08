<?php
require_once __DIR__ . '/../src/bootstrap.php';

if (!cfg_ready()) {
    http_response_code(500);
    echo 'Configuration missing. Copy config.php.example to config.php.';
    return;
}

$loaded = ddp_load_dir((string)cfg('ddp_dir'));
?>
<!doctype html>
<meta charset="utf-8">
<title>DDP Inspector — participants</title>
<link rel="stylesheet" href="<?= h(url('assets/style.css')) ?>">
<main class="wrap">
  <h1>DDP Inspector</h1>
  <?php if ($loaded['skipped']): ?>
    <p class="skipped">⚠ <?= count($loaded['skipped']) ?> file(s) skipped (non-conforming):
      <?= h(implode(', ', array_column($loaded['skipped'], 'path'))) ?></p>
  <?php endif; ?>
  <table class="scope">
    <thead><tr><th>participant</th><th>total rows</th><th>unique videos</th><th>earliest</th><th>latest</th></tr></thead>
    <tbody>
    <?php foreach ($loaded['participants'] as $p):
        $scope = stats_participant_scope($p); ?>
      <tr>
        <td><a href="<?= h(url('participant.php?id=' . rawurlencode($p['id']))) ?>"><?= h($p['id']) ?></a></td>
        <td class="num"><?= number_format($scope['total_rows']) ?></td>
        <td class="num"><?= number_format($scope['unique_videos']) ?></td>
        <td><?= h(fmt_ts($scope['earliest'])) ?></td>
        <td><?= h(fmt_ts($scope['latest'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</main>
