<?php
require_once __DIR__ . '/../src/bootstrap.php';
if (!cfg_ready()) { http_response_code(500); echo 'Configuration missing. Copy config.php.example to config.php.'; return; }
if (!guard_configured()) { return; }

$id   = is_string($_GET['id'] ?? null) ? $_GET['id'] : '';
$seed = max(1, (int)(is_scalar($_GET['seed'] ?? null) ? $_GET['seed'] : 1));
$n    = max(1, (int)(is_scalar($_GET['n'] ?? null) ? $_GET['n'] : cfg('default_n', 15)));

$showAllRows = ($_GET['all'] ?? '') === '1';
$txIds = analysis_available_ids('transcripts');
$participant = ddp_load_participant((string)inst_effective_ddp_dir(), $id);
if ($participant === null) {
    http_response_code(404);
    echo '<!doctype html><title>Not found</title><p>Participant not found (404).</p>';
    return;
}
$docs = flows_load_all();
?>
<!doctype html>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>DDP Inspector — <?= h($id) ?></title>
<link rel="stylesheet" href="<?= h(url('assets/style.css')) ?>">
<main class="wrap">
  <header class="site">
    <a class="wordmark" href="<?= h(url('index.php')) ?>">DDP Inspector</a>
    <nav class="crumbs"><a href="<?= h(url('index.php')) ?>">← all participants</a></nav>
  </header>
  <h1>Participant <span class="id"><?= h($id) ?></span></h1>
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

  <?php foreach ($participant['platforms'] as $slug => $entry):
      $doc = $docs[$slug] ?? null;
      $match = flows_match($entry['tables'], $doc);
      $order = flows_table_order($match);
      $scope = stats_platform_scope($entry['tables'], $order); ?>
    <h2><?= h($doc['platform_title'] ?? ucfirst($slug)) ?></h2>
    <?php if ($entry['superseded']): ?>
      <p class="skipped">Note: this participant donated more than once for this platform;
        showing the most recent donation (<?= count($entry['superseded']) ?> older file(s) ignored).</p>
    <?php endif; ?>
    <table class="scope">
      <thead><tr><th>table</th><th>rows</th><th>earliest</th><th>latest</th></tr></thead>
      <tbody>
      <?php foreach ($scope['tables'] as $name => $s):
          $title = ($match[$name] ?? null) !== null ? $doc['sections'][$match[$name]]['title'] : flows_prettify($name); ?>
        <tr><td><?= h($title) ?></td><td class="num"><?= number_format($s['count']) ?></td>
            <td class="date"><?= h(fmt_ts($s['earliest'])) ?></td><td class="date"><?= h(fmt_ts($s['latest'])) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <?php foreach ($order as $name):
        $rows = $entry['tables'][$name] ?? [];
        if (!$rows) { continue; }
        [$rowsShown, $hiddenRows] = $showAllRows ? [$rows, 0]
            : analysis_filter_rows_with_artifacts($slug, $rows, $txIds);
        $secIdx = $match[$name] ?? null;
        $title = $secIdx !== null ? $doc['sections'][$secIdx]['title'] : flows_prettify($name);
        $desc = $secIdx !== null ? $doc['sections'][$secIdx]['description'] : '';
        $cols = $secIdx !== null ? $doc['sections'][$secIdx]['vars'] : array_keys($rows[0]);
        $sample = sample_rows($rowsShown, $n, $seed, $name);
        $del = (int)($entry['deleted'][$name] ?? 0);
        $reshuffle = url('participant.php?id=' . rawurlencode($id) . '&n=' . $n . '&seed=' . ($seed + 1) . ($showAllRows ? '&all=1' : '')); ?>
      <section>
        <h3><?= h($title) ?> <span class="count"><?= number_format(count($rows)) ?> rows<?php
          if ($hiddenRows > 0): ?> · <?= number_format(count($rowsShown)) ?> with transcripts<?php endif; ?></span>
          <?php if (count($rowsShown) > count($sample)): ?><a class="reshuffle" href="<?= h($reshuffle) ?>">reshuffle sample</a><?php endif; ?>
        </h3>
        <?php if ($desc !== ''): ?><p class="meta"><?= h($desc) ?></p><?php endif; ?>
        <?php if ($del > 0): ?><p class="meta">Participant removed <?= $del ?> row(s) before donating.</p><?php endif; ?>
        <?php if ($rowsShown === [] && $hiddenRows > 0): ?>
          <p class="meta">None of these <?= number_format($hiddenRows) ?> rows have transcripts yet.</p>
        <?php else: ?>
        <table class="rows">
          <thead><tr><?php foreach ($cols as $c): ?><th><?= h($c) ?></th><?php endforeach; ?><th></th></tr></thead>
          <tbody>
          <?php foreach ($sample as $row): ?>
            <tr>
              <?php foreach ($cols as $c):
                  $cellClass = preg_match('/date|time/i', $c) ? ' class="date"'
                      : (preg_match('/link|url/i', $c) ? ' class="url"' : ''); ?>
                <td<?= $cellClass ?>><?= h(is_scalar($row[$c] ?? null) ? (string)$row[$c] : '') ?></td>
              <?php endforeach; ?>
              <td class="actions"><?php foreach (analysis_row_links($slug, $row) as $link): ?>
                    <a href="<?= h($link['url']) ?>"><?= h($link['label']) ?></a>
                  <?php endforeach; ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </section>
    <?php endforeach; ?>
  <?php endforeach; ?>
</main>
