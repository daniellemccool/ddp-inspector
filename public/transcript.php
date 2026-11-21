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

// A declared database that is not usable: the page shows only the notice.
$notice = study_db_notice();
$transcript = $notice === null ? study_transcript($vid) : null;
$txt = $transcript['text'] ?? null;
$meta = $transcript['meta'] ?? null;
$reason = ($txt === null && $meta === null && is_string($transcript['availability'] ?? null))
    ? study_availability_label($transcript['availability']) : null;

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
<?php page_top('video ' . $vid, 'participants', '<span class="eyebrow">Video</span> <span class="id">' . h($vid) . '</span>'); ?>
  <?php if ($notice !== null): ?>
    <p class="notice"><?= h($notice) ?></p>
  <?php elseif ($txt === null && $meta === null): ?>
    <?php if ($reason !== null): ?>
      <p class="notice">No transcript: <?= h($reason) ?>.</p>
    <?php else: ?>
      <p class="notice">Not transcribed yet.</p>
    <?php endif; ?>
  <?php else: ?>
    <?php
      $vm = is_array($meta['video_metadata'] ?? null) ? $meta['video_metadata'] : [];
      $srcUrl = (string)($meta['source_url'] ?? '');
      $srcOk = preg_match('~^https?://~', $srcUrl) === 1;
      $lang = lang_name(is_string($meta['language_detected'] ?? null) ? $meta['language_detected'] : null);
      // Label and value rows for the rail; the full count goes in a title attr.
      $facts = [];
      if (($vm['uploader'] ?? '') !== '') { $facts[] = ['Uploader', '<span class="handle">@' . h((string)$vm['uploader']) . '</span>']; }
      if (($vm['video_created_at'] ?? '') !== '') { $facts[] = ['Posted', h(fmt_date_iso((string)$vm['video_created_at']))]; }
      foreach ([['view_count', 'Views'], ['like_count', 'Likes'], ['comment_count', 'Comments']] as [$k, $label]) {
        if (is_numeric($vm[$k] ?? null)) {
          $facts[] = [$label, '<span title="' . h(number_format((int)$vm[$k])) . '">' . h(fmt_compact((int)$vm[$k])) . '</span>'];
        }
      }
      $dur = is_numeric($meta['duration_s'] ?? null)
          ? sprintf('%d:%02d', intdiv((int)round((float)$meta['duration_s']), 60), (int)round((float)$meta['duration_s']) % 60)
          : null;
      // The spoken language and the length belong to the recording, so they
      // sit with the transcript, not with what the uploader wrote.
      $spoken = array_values(array_filter([$lang, $dur], fn ($v) => $v !== null));
    ?>
    <div class="video">
    <?php if ($vm !== [] || $srcOk || $lang !== null): ?>
      <aside class="videometa" aria-labelledby="about-video">
        <h2 id="about-video">About this video</h2>
        <?php if (($vm['video_description'] ?? '') !== ''): ?>
          <div class="videometa__row">
            <p class="eyebrow">Video caption</p>
            <?php // Hashtag muting runs on h()-escaped text: the (?<!&) guard keeps the
                  // pattern off the #039-style innards of entities h() just produced. ?>
            <p class="desc"><?= preg_replace('/(?<!&)(#\w+)/u', '<span class="tag">$1</span>', h((string)$vm['video_description'])) ?></p>
          </div>
        <?php endif; ?>
        <?php if ($facts !== []): ?>
          <dl class="facts">
          <?php foreach ($facts as [$label, $value]): ?>
            <div class="videometa__row"><dt><?= $label ?></dt><dd><?= $value ?></dd></div>
          <?php endforeach; ?>
          </dl>
        <?php endif; ?>
        <div class="videometa__row">
          <?php if ($srcOk): ?><a href="<?= h($srcUrl) ?>" rel="noreferrer noopener" target="_blank">Watch on TikTok<?= go_mark() ?></a><?php endif; ?>
          <?php if (($vm['metadata_fetched_at'] ?? '') !== ''): ?>
            <p class="meta">Metadata fetched <?= h(fmt_date_iso((string)$vm['metadata_fetched_at'])) ?></p>
          <?php elseif ($vm === []): ?>
            <p class="meta">No video metadata available for this video (yet).</p>
          <?php endif; ?>
        </div>
      </aside>
    <?php endif; ?>
    <div class="video__main">
    <?php if ($txt !== null): ?>
      <p class="eyebrow">Spoken transcript<?= $spoken !== [] ? " · " . h(implode(" · ", $spoken)) : "" ?></p>
      <?php if (trim((string)$txt) === ''): ?>
        <p class="meta">No speech detected in this video.</p>
      <?php else: ?>
        <blockquote class="transcript-block"><?= h(trim((string)$txt)) ?></blockquote>
      <?php endif; ?>
    <?php else: ?>
      <p class="notice">The transcript text file is missing.</p>
    <?php endif; ?>
    <?php $segs = $meta['raw_signals']['segments'] ?? null; if (is_array($segs)): ?>
      <h2>Segment confidence</h2>
      <p class="meta">Average Whisper token probability per segment. Values below 0.50 are marked.</p>
      <table class="rows segments">
        <thead><tr><th class="num">Confidence</th><th>Segment</th></tr></thead>
        <tbody>
        <?php foreach ($segs as $seg): [$avg, $stext] = $seg_info($seg); ?>
          <tr><td class="num <?= ($avg !== null && $avg < 0.5) ? 'low' : '' ?>">
                <?= $avg === null ? '—' : number_format($avg, 2) ?></td>
              <td><?= h($stext) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php elseif ($meta !== null): ?>
      <p class="notice">This transcript has no confidence data (it was made before the pipeline recorded it).</p>
    <?php endif; ?>
    <?php if ($meta !== null): ?>
      <details class="raw"><summary>Raw JSON</summary><pre><?= h(json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre></details>
    <?php endif; ?>
    </div>
    </div>
  <?php endif; ?>
<?php page_bottom(); ?>
