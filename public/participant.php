<?php
require_once __DIR__ . '/../src/bootstrap.php';

$id   = (string)($_GET['id'] ?? '');
$seed = max(1, (int)($_GET['seed'] ?? 1));
$n    = max(1, (int)($_GET['n'] ?? cfg('default_n', 15)));

$loaded = ddp_load_dir((string)cfg('ddp_dir'));
$participant = $loaded['participants'][$id] ?? null;
if ($participant === null) {
    http_response_code(404);
    echo '<!doctype html><title>Not found</title><p>Participant not found (404).</p>';
    return;
}
$scope = stats_participant_scope($participant);
?>
<!doctype html>
<meta charset="utf-8">
<title>DDP Inspector — <?= h($id) ?></title>
<link rel="stylesheet" href="<?= h(url('assets/style.css')) ?>">
<main class="wrap">
  <p><a href="<?= h(url('index.php')) ?>">← all participants</a></p>
  <h1>participant <?= h($id) ?></h1>
  <p class="meta"><?= count($participant['files']) ?> file(s); unique videos:
     <strong><?= number_format($scope['unique_videos']) ?></strong></p>

  <h2>Scope</h2>
  <table class="scope">
    <thead><tr><th>section</th><th>rows</th><th>earliest</th><th>latest</th></tr></thead>
    <tbody>
    <?php foreach ($scope['sections'] as $name => $s): ?>
      <tr><td><?= h($name) ?></td><td class="num"><?= number_format($s['count']) ?></td>
          <td><?= h(fmt_ts($s['earliest'])) ?></td><td><?= h(fmt_ts($s['latest'])) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <?php foreach ($scope['sections'] as $name => $s):
      $rows = $participant['sections'][$name];
      $sample = sample_rows($rows, $n, $seed, $name);
      $reshuffle = url('participant.php?id=' . rawurlencode($id) . '&n=' . $n . '&seed=' . ($seed + 1)); ?>
    <section>
      <h3><?= h($name) ?> <span class="count"><?= number_format($s['count']) ?> rows</span>
        <?php if ($s['count'] > count($sample)): ?>
          <a class="reshuffle" href="<?= h($reshuffle) ?>">reshuffle sample</a>
        <?php endif; ?>
      </h3>
      <table class="rows">
        <?php foreach ($sample as $row):
            $date = h((string)($row['Date'] ?? ''));
            if ($name === 'tiktok_comments'): ?>
              <tr><td class="date"><?= $date ?></td><td class="comment"><?= h((string)($row['Comment'] ?? '')) ?></td></tr>
            <?php else:
              $vid = isset($row['Link']) ? stats_canonical_video_id((string)$row['Link']) : null; ?>
              <tr>
                <td class="date"><?= $date ?></td>
                <td class="vid">
                  <?php if ($vid !== null): ?>
                    <a href="<?= h(url('transcript.php?vid=' . $vid)) ?>"><?= h($vid) ?></a>
                  <?php else: ?>
                    <?= h((string)($row['Link'] ?? '')) ?>
                  <?php endif; ?>
                </td>
                <?php if ($name === 'tiktok_share_history'): ?>
                  <td class="method"><?= h((string)($row['Method'] ?? '')) ?> · <?= h((string)($row['SharedContent'] ?? '')) ?></td>
                <?php endif; ?>
              </tr>
            <?php endif; ?>
        <?php endforeach; ?>
      </table>
    </section>
  <?php endforeach; ?>
</main>
