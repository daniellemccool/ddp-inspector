<?php
require_once __DIR__ . '/../src/bootstrap.php';
if (!cfg_ready()) { http_response_code(500); echo 'Configuration missing. Copy config.php.example to config.php.'; return; }
if (!guard_configured()) { return; }

$id   = is_string($_GET['id'] ?? null) ? $_GET['id'] : '';
$seed = max(1, (int)(is_scalar($_GET['seed'] ?? null) ? $_GET['seed'] : 1));
$n    = max(1, (int)(is_scalar($_GET['n'] ?? null) ? $_GET['n'] : cfg('default_n', 15)));

$showAllRows = ($_GET['all'] ?? '') === '1';
$participant = study_participant($id, $n, $seed, $showAllRows);
if ($participant === null) {
    http_response_code(404);
    echo '<!doctype html><title>Not found</title><p>Participant not found (404).</p>';
    return;
}
$id = $participant['id'];
$notice = study_db_notice();
?>
<!doctype html>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>DDP Inspector — <?= h($id) ?></title>
<link rel="stylesheet" href="<?= h(asset_url('assets/style.css')) ?>">
<main class="wrap">
  <header class="site">
    <a class="wordmark" href="<?= h(url('index.php')) ?>">DDP Inspector</a>
    <nav class="crumbs"><a href="<?= h(url('index.php')) ?>">← all participants</a></nav>
  </header>
  <h1>Participant <span class="id"><?= h($id) ?></span></h1>
  <?php if ($notice !== null): ?><p class="notice"><?= h($notice) ?></p><?php endif; ?>
  <p class="samplesize">sample size:
    <?php foreach ([10, 15, 20, 50] as $opt): ?>
      <a href="<?= h(url('participant.php?id=' . rawurlencode($id) . '&n=' . $opt . '&seed=' . $seed . ($showAllRows ? '&all=1' : ''))) ?>"<?= $opt === $n ? ' class="cur"' : '' ?>><?= $opt ?></a>
    <?php endforeach; ?>
  </p>
  <p class="meta"><?php if (!$showAllRows): ?>
    Rows whose video has no transcript yet are hidden —
    <a href="<?= h(url('participant.php?id=' . rawurlencode($id) . '&n=' . $n . '&seed=' . $seed . '&all=1')) ?>">include them</a>
  <?php else: ?>
    Showing all rows, including untranscribed videos —
    <a href="<?= h(url('participant.php?id=' . rawurlencode($id) . '&n=' . $n . '&seed=' . $seed)) ?>">hide untranscribed</a>
  <?php endif; ?></p>

  <?php foreach ($participant['platforms'] as $platform):
      // Tables without rows have no section; they can never match a flow doc,
      // so their scope row keeps the prettified key.
      $titles = array_column($platform['sections'], 'title', 'key'); ?>
    <h2><?= h($platform['title']) ?></h2>
    <?php foreach ($platform['notes'] as $note): ?>
      <p class="skipped"><?= h($note) ?></p>
    <?php endforeach; ?>
    <table class="scope">
      <thead><tr><th>table</th><th>rows</th><th>earliest</th><th>latest</th></tr></thead>
      <tbody>
      <?php foreach ($platform['scope'] as $name => $s): ?>
        <tr><td><?= h($titles[$name] ?? flows_prettify((string)$name)) ?></td><td class="num"><?= number_format($s['count']) ?></td>
            <td class="date"><?= h(fmt_ts($s['earliest'])) ?></td><td class="date"><?= h(fmt_ts($s['latest'])) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <?php foreach ($platform['sections'] as $sec):
        $reshuffle = url('participant.php?id=' . rawurlencode($id) . '&n=' . $n . '&seed=' . ($seed + 1) . ($showAllRows ? '&all=1' : '')); ?>
      <section>
        <h3><?= h($sec['title']) ?> <span class="count"><?= number_format($sec['total']) ?> rows<?php
          if ($sec['hidden'] > 0): ?> · <?= number_format($sec['with_artifacts']) ?> with transcripts<?php endif; ?></span>
          <?php if ($sec['with_artifacts'] > count($sec['sample'])): ?><a class="reshuffle" href="<?= h($reshuffle) ?>">reshuffle sample</a><?php endif; ?>
        </h3>
        <?php if ($sec['description'] !== ''): ?><p class="meta"><?= h($sec['description']) ?></p><?php endif; ?>
        <?php if ($sec['deleted'] > 0): ?><p class="meta">Participant removed <?= $sec['deleted'] ?> row(s) before donating.</p><?php endif; ?>
        <?php if ($sec['with_artifacts'] === 0 && $sec['hidden'] > 0): ?>
          <p class="meta">None of these <?= number_format($sec['hidden']) ?> rows have transcripts yet.</p>
        <?php else: ?>
        <table class="rows">
          <thead><tr><?php foreach ($sec['columns'] as $c): ?><th><?= h($c) ?></th><?php endforeach; ?><th></th></tr></thead>
          <tbody>
          <?php foreach ($sec['sample'] as $item): ?>
            <tr>
              <?php foreach ($sec['columns'] as $c):
                  $cellClass = preg_match('/date|time/i', $c) ? ' class="date"'
                      : (preg_match('/link|url/i', $c) ? ' class="url"' : ''); ?>
                <td<?= $cellClass ?>><?= h($item['row'][$c] ?? '') ?></td>
              <?php endforeach; ?>
              <td class="actions"><?php foreach ($item['links'] as $link): ?>
                    <a href="<?= h($link['url']) ?>"><?= h($link['label']) ?></a>
                  <?php endforeach; ?><?php if ($item['reason'] !== null): ?><span class="reason"><?= h($item['reason']) ?></span><?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </section>
    <?php endforeach; ?>
  <?php endforeach; ?>
</main>
