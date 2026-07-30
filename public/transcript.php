<?php
require_once __DIR__ . '/../src/bootstrap.php';

if (!cfg_ready()) {
    http_response_code(500);
    echo 'Configuration missing. Copy config.php.example to config.php.';
    return;
}
if (!guard_configured()) { return; }

$vid = is_string($_GET['vid'] ?? null) ? $_GET['vid'] : '';
if (!preg_match('/^\d{19}$/D', $vid)) {
    http_response_code(400);
    echo '<!doctype html><title>Bad request</title><p>Invalid video id (400).</p>';
    return;
}

$paths = analysis_transcript_paths($vid);
$txt = $paths['txt'] !== null ? file_get_contents($paths['txt']) : null;
$meta = $paths['json'] !== null ? json_decode((string)file_get_contents($paths['json']), true) : null;

/**
 * Segment text + average token confidence, skipping special tokens
 * ([_BEG_], [_TT_nnn], <|en|>, ...). The pipeline's raw_signals carries no
 * segment-level text (ADR-0010 pass-through: consumers reconstruct from
 * tokens); whisper token texts carry their own leading spaces.
 */
$seg_info = function (array $seg): array {
    $ps = []; $text = '';
    foreach ($seg['tokens'] ?? [] as $t) {
        $tok = (string)($t['text'] ?? '');
        if ($tok !== '' && ($tok[0] === '[' || str_starts_with($tok, '<|'))) { continue; }
        $text .= $tok;
        if (isset($t['p'])) { $ps[] = (float)$t['p']; }
    }
    return [$ps ? array_sum($ps) / count($ps) : null, trim($text)];
};
?>
<!doctype html>
<meta charset="utf-8">
<title>transcript <?= h($vid) ?></title>
<link rel="stylesheet" href="<?= h(url('assets/style.css')) ?>">
<main class="wrap">
  <p><a href="<?= h(url('index.php')) ?>">← all participants</a></p>
  <h1>transcript <?= h($vid) ?></h1>
  <?php if ($txt === null && $meta === null): ?>
    <p class="notice">Not transcribed yet.</p>
  <?php else: ?>
    <?php
      $vm = is_array($meta['video_metadata'] ?? null) ? $meta['video_metadata'] : [];
      $srcUrl = (string)($meta['source_url'] ?? '');
      $srcOk = preg_match('~^https?://~', $srcUrl) === 1;
      $lang = lang_name(is_string($meta['language_detected'] ?? null) ? $meta['language_detected'] : null);
      $byline = [];
      if (($vm['uploader'] ?? '') !== '') { $byline[] = '@' . $vm['uploader']; }
      if (($vm['video_created_at'] ?? '') !== '') { $byline[] = 'posted ' . fmt_date_iso((string)$vm['video_created_at']); }
      if ($lang !== null) { $byline[] = $lang; }
      $stats = [];
      foreach ([['view_count', 'views'], ['like_count', 'likes'], ['comment_count', 'comments']] as [$k, $label]) {
        if (is_numeric($vm[$k] ?? null)) {
          $stats[] = '<span title="' . h(number_format((int)$vm[$k])) . '">' . h(fmt_compact((int)$vm[$k])) . ' ' . $label . '</span>';
        }
      }
    ?>
    <?php if ($vm !== [] || $srcOk || $lang !== null): ?>
      <section class="videometa">
        <?php if (($vm['video_description'] ?? '') !== ''): ?>
          <p class="eyebrow">video caption</p>
          <p class="desc"><?= preg_replace('/(#\w+)/u', '<span class="tag">$1</span>', h((string)$vm['video_description'])) ?></p>
        <?php endif; ?>
        <?php if ($byline !== []): ?><p class="byline"><?= h(implode(' · ', $byline)) ?></p><?php endif; ?>
        <?php if ($stats !== []): ?><p class="vstats"><?= implode(' · ', $stats) ?></p><?php endif; ?>
        <p class="meta">
          <?php if ($srcOk): ?><a href="<?= h($srcUrl) ?>" rel="noreferrer noopener" target="_blank">watch on TikTok ↗</a><?php endif; ?>
          <?php if (($vm['metadata_fetched_at'] ?? '') !== ''): ?>
            <?= $srcOk ? '·' : '' ?> metadata fetched <?= h(fmt_date_iso((string)$vm['metadata_fetched_at'])) ?>
          <?php elseif ($vm === []): ?>
            <?= $srcOk ? '·' : '' ?> no video metadata available for this video (yet)
          <?php endif; ?>
        </p>
      </section>
    <?php endif; ?>
    <?php if ($txt !== null): ?>
      <?php $dur = is_numeric($meta['duration_s'] ?? null)
          ? sprintf('%d:%02d', intdiv((int)round((float)$meta['duration_s']), 60), (int)round((float)$meta['duration_s']) % 60)
          : null; ?>
      <p class="eyebrow">spoken transcript<?= $dur !== null ? ' · ' . h($dur) : '' ?></p>
      <blockquote class="transcript-block"><?= h((string)$txt) ?></blockquote>
    <?php else: ?>
      <p class="notice">(transcript text file missing)</p>
    <?php endif; ?>
    <?php $segs = $meta['raw_signals']['segments'] ?? null; if (is_array($segs)): ?>
      <h2>Segment confidence</h2>
      <table class="rows">
        <thead><tr><th>avg p</th><th>segment</th></tr></thead>
        <tbody>
        <?php foreach ($segs as $seg): [$avg, $stext] = $seg_info($seg); ?>
          <tr><td class="num <?= ($avg !== null && $avg < 0.5) ? 'low' : '' ?>">
                <?= $avg === null ? '—' : number_format($avg, 2) ?></td>
              <td><?= h($stext) ?></td></tr>
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
