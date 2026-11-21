<?php
require_once __DIR__ . '/../src/bootstrap.php';
if (!cfg_ready()) { http_response_code(500); echo 'Configuration missing. Copy config.php.example to config.php.'; return; }
if (!guard_configured()) { return; }

$id   = is_string($_GET['id'] ?? null) ? $_GET['id'] : '';
$seed = max(1, (int)(is_scalar($_GET['seed'] ?? null) ? $_GET['seed'] : 1));
$n    = min(500, max(1, (int)(is_scalar($_GET['n'] ?? null) ? $_GET['n'] : cfg('default_n', 15))));

$showAllRows = ($_GET['all'] ?? '') === '1';
// A declared database that is not usable: the page shows only the notice.
$notice = study_db_notice();
$participant = $notice === null ? study_participant($id, $n, $seed, $showAllRows) : null;
if ($notice === null && $participant === null) {
    http_response_code(404);
    echo '<!doctype html><title>Not found</title><p>Participant not found (404).</p>';
    return;
}
$id = $participant['id'] ?? $id;
?>
<?php page_top($id, 'participants', '<span class="eyebrow">Participant</span> <span class="id">' . h($id) . '</span>'); ?>
  <?php if ($notice !== null): ?><p class="notice"><?= h($notice) ?></p><?php else: ?>
  <div class="controls niche">
  <p class="samplesize"><span class="samplesize__label">Rows per table</span>
    <?php foreach ([10, 15, 20, 50] as $opt): ?>
      <a href="<?= h(url('participant.php?id=' . rawurlencode($id) . '&n=' . $opt . '&seed=' . $seed . ($showAllRows ? '&all=1' : ''))) ?>"<?= $opt === $n ? ' class="cur" aria-current="true"' : '' ?>><?= $opt ?></a>
    <?php endforeach; ?>
  </p>
  <p class="meta"><?php if (!$showAllRows): ?>
    Rows without a transcript are hidden —
    <a href="<?= h(url('participant.php?id=' . rawurlencode($id) . '&n=' . $n . '&seed=' . $seed . '&all=1')) ?>">show them</a>
  <?php else: ?>
    All rows are shown —
    <a href="<?= h(url('participant.php?id=' . rawurlencode($id) . '&n=' . $n . '&seed=' . $seed)) ?>">hide rows without a transcript</a>
  <?php endif; ?></p>
  </div>

  <?php foreach ($participant['platforms'] as $platform):
      // Tables without rows have no section; they can never match a flow doc,
      // so their scope row keeps the prettified key.
      $titles = array_column($platform['sections'], 'title', 'key'); ?>
    <h2><?= h($platform['title']) ?></h2>
    <?php foreach ($platform['notes'] as $note): ?>
      <p class="notice"><?= h($note) ?></p>
    <?php endforeach; ?>
    <table class="scope">
      <thead><tr><th>Table</th><th class="num">Rows</th><th>Earliest</th><th>Latest</th></tr></thead>
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
  <?php endif; ?>
<?php page_bottom(); ?>
