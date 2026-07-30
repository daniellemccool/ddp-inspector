<?php
require_once __DIR__ . '/../src/bootstrap.php';
if (!cfg_ready()) { http_response_code(500); echo 'Configuration missing. Copy config.php.example to config.php.'; return; }
if (!guard_configured()) { return; }

$id   = is_string($_GET['id'] ?? null) ? $_GET['id'] : '';
$seed = max(1, (int)(is_scalar($_GET['seed'] ?? null) ? $_GET['seed'] : 1));
$n    = max(1, (int)(is_scalar($_GET['n'] ?? null) ? $_GET['n'] : cfg('default_n', 15)));

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
<title>DDP Inspector — <?= h($id) ?></title>
<link rel="stylesheet" href="<?= h(url('assets/style.css')) ?>">
<main class="wrap">
  <p><a href="<?= h(url('index.php')) ?>">← all participants</a></p>
  <h1>participant <?= h($id) ?></h1>
  <p class="samplesize">sample size:
    <?php foreach ([10, 15, 20, 50] as $opt): ?>
      <a href="<?= h(url('participant.php?id=' . rawurlencode($id) . '&n=' . $opt . '&seed=' . $seed)) ?>"<?= $opt === $n ? ' class="cur"' : '' ?>><?= $opt ?></a>
    <?php endforeach; ?>
  </p>

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
            <td><?= h(fmt_ts($s['earliest'])) ?></td><td><?= h(fmt_ts($s['latest'])) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <?php foreach ($order as $name):
        $rows = $entry['tables'][$name] ?? [];
        if (!$rows) { continue; }
        $secIdx = $match[$name] ?? null;
        $title = $secIdx !== null ? $doc['sections'][$secIdx]['title'] : flows_prettify($name);
        $desc = $secIdx !== null ? $doc['sections'][$secIdx]['description'] : '';
        $cols = $secIdx !== null ? $doc['sections'][$secIdx]['vars'] : array_keys($rows[0]);
        $sample = sample_rows($rows, $n, $seed, $name);
        $del = (int)($entry['deleted'][$name] ?? 0);
        $reshuffle = url('participant.php?id=' . rawurlencode($id) . '&n=' . $n . '&seed=' . ($seed + 1)); ?>
      <section>
        <h3><?= h($title) ?> <span class="count"><?= number_format(count($rows)) ?> rows</span>
          <?php if (count($rows) > count($sample)): ?><a class="reshuffle" href="<?= h($reshuffle) ?>">reshuffle sample</a><?php endif; ?>
        </h3>
        <?php if ($desc !== ''): ?><p class="meta"><?= h($desc) ?></p><?php endif; ?>
        <?php if ($del > 0): ?><p class="meta">Participant removed <?= $del ?> row(s) before donating.</p><?php endif; ?>
        <table class="rows">
          <thead><tr><?php foreach ($cols as $c): ?><th><?= h($c) ?></th><?php endforeach; ?><th></th></tr></thead>
          <tbody>
          <?php foreach ($sample as $row): ?>
            <tr>
              <?php foreach ($cols as $c): ?><td><?= h(is_scalar($row[$c] ?? null) ? (string)$row[$c] : '') ?></td><?php endforeach; ?>
              <td><?php foreach (analysis_row_links($slug, $row) as $link): ?>
                    <a href="<?= h($link['url']) ?>"><?= h($link['label']) ?></a>
                  <?php endforeach; ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </section>
    <?php endforeach; ?>
  <?php endforeach; ?>
</main>
