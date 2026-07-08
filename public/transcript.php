<?php
require_once __DIR__ . '/../src/bootstrap.php';

$vid = (string)($_GET['vid'] ?? '');
if (!preg_match('/^\d{19}$/', $vid)) {
    http_response_code(400);
    echo '<!doctype html><title>Bad request</title><p>Invalid video id (400).</p>';
    return;
}

$dir = cfg('transcripts_dir');
$txt = null; $meta = null;
if ($dir) {
    $base = rtrim((string)$dir, '/') . '/' . substr($vid, -2) . '/' . $vid;
    if (is_file($base . '.txt'))  { $txt  = file_get_contents($base . '.txt'); }
    if (is_file($base . '.json')) { $meta = json_decode((string)file_get_contents($base . '.json'), true); }
}

/** Average token confidence for a segment, ignoring special [_...] tokens. */
$seg_avg = function (array $seg): ?float {
    $ps = [];
    foreach ($seg['tokens'] ?? [] as $t) {
        $txt = (string)($t['text'] ?? '');
        if ($txt !== '' && $txt[0] === '[') { continue; }
        if (isset($t['p'])) { $ps[] = (float)$t['p']; }
    }
    return $ps ? array_sum($ps) / count($ps) : null;
};
?>
<!doctype html>
<meta charset="utf-8">
<title>transcript <?= h($vid) ?></title>
<link rel="stylesheet" href="<?= h(url('assets/style.css')) ?>">
<main class="wrap">
  <h1>transcript <?= h($vid) ?></h1>
  <?php if ($txt === null && $meta === null): ?>
    <p class="notice">not transcribed yet.</p>
  <?php else: ?>
    <pre class="transcript"><?= h((string)$txt) ?></pre>
    <?php $segs = $meta['raw_signals']['segments'] ?? null; if (is_array($segs)): ?>
      <h2>Segment confidence</h2>
      <table class="rows">
        <thead><tr><th>avg p</th><th>segment</th></tr></thead>
        <tbody>
        <?php foreach ($segs as $seg): $avg = $seg_avg($seg); ?>
          <tr><td class="num <?= ($avg !== null && $avg < 0.5) ? 'low' : '' ?>">
                <?= $avg === null ? '—' : number_format($avg, 2) ?></td>
              <td><?= h((string)($seg['text'] ?? '')) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php elseif ($meta !== null): ?>
      <p class="notice">No raw_signals in this artifact (pre-Epic-1 schema).</p>
    <?php endif; ?>
    <?php if ($meta !== null): ?>
      <details><summary>raw JSON</summary><pre><?= h(json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre></details>
    <?php endif; ?>
  <?php endif; ?>
</main>
