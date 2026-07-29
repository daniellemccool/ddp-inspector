<?php
require_once __DIR__ . '/../src/bootstrap.php';

$doc = flows_parse_doc((string)file_get_contents(__DIR__ . '/fixtures/flows/facebook/documentation.txt'));
eq($doc['platform_title'], 'Facebook', 'doc platform title');
eq($doc['commit'], '356f6e10dbb38dbf1eb69eea220c68a5c0f47ff3', 'doc commit parsed');
eq(count($doc['sections']), 3, 'build information excluded from sections');
eq($doc['sections'][2]['title'], 'Your search history', 'section title');
eq($doc['sections'][2]['vars'], ['Date', 'Search term'], 'section variables');
check(str_contains($doc['sections'][0]['description'], 'recently viewed'), 'section description captured');
eq($doc['sections'][2]['var_desc']['Search term'], 'The search query entered by the participant.', 'variable description');
eq(flows_parse_doc('no heading here'), null, 'unparseable doc -> null');

// Matching: two tables with identical column sets resolve by order.
$tables = [
    'facebook_recently_viewed' => [['Category' => 'Videos', 'Date' => 'd', 'Link' => 'l', 'Name' => 'n']],
    'facebook_recently_visited' => [['Category' => 'Profiles', 'Date' => 'd', 'Link' => 'l', 'Name' => 'n']],
    'facebook_search_history' => [['Date' => 'd', 'Search term' => 'q']],
    'facebook_mystery' => [['Zzz' => 1]],
];
$match = flows_match($tables, $doc);
eq($match['facebook_recently_viewed'], 0, 'first ambiguous table claims first doc section');
eq($match['facebook_recently_visited'], 1, 'second ambiguous table claims second doc section');
eq($match['facebook_search_history'], 2, 'unique column set matches');
eq($match['facebook_mystery'], null, 'unmatched table -> null');
eq(flows_table_order($match),
   ['facebook_recently_viewed', 'facebook_recently_visited', 'facebook_search_history', 'facebook_mystery'],
   'order: doc order then alphabetical unmatched');
eq(flows_prettify('tiktok_watch_history'), 'Tiktok watch history', 'prettified key');
eq(flows_match($tables, null), ['facebook_recently_viewed' => null, 'facebook_recently_visited' => null,
   'facebook_search_history' => null, 'facebook_mystery' => null], 'no doc -> all unmatched');

// A doc section whose column set matches no donation table stays unclaimed; other matches unaffected.
$tables_partial = [
    'facebook_recently_viewed' => [['Category' => 'Videos', 'Date' => 'd', 'Link' => 'l', 'Name' => 'n']],
    'facebook_recently_visited' => [['Category' => 'Profiles', 'Date' => 'd', 'Link' => 'l', 'Name' => 'n']],
    'facebook_mystery' => [['Zzz' => 1]],
];
$match_partial = flows_match($tables_partial, $doc);
eq($match_partial['facebook_recently_viewed'], 0, 'unrelated unclaimed section does not disturb first match');
eq($match_partial['facebook_recently_visited'], 1, 'unrelated unclaimed section does not disturb second match');
eq($match_partial['facebook_mystery'], null, 'still no table for mystery column set');
check(!in_array(2, $match_partial, true), 'search-history doc section (index 2) stays unclaimed when no table has that column set');
eq(flows_table_order($match_partial),
   ['facebook_recently_viewed', 'facebook_recently_visited', 'facebook_mystery'],
   'table order unaffected by the presence of an unclaimed doc section');

// Table rows with trailing whitespace after the closing pipe must still parse.
$ws_doc = flows_parse_doc(
    "# Ws Platform\n\nDescription paragraph.\n\n## Ws Section\n\nSection description.\n\n" .
    "| Variable | Description |\n| -------- | ----------- |\n| `X` | desc |  \n"
);
eq($ws_doc['sections'][0]['vars'], ['X'], 'trailing whitespace after closing pipe does not drop the row');
eq($ws_doc['sections'][0]['var_desc']['X'], 'desc', 'trailing whitespace row still captures description');
$ws_match = flows_match(['t' => [['X' => 1]]], $ws_doc);
eq($ws_match['t'], 0, 'table matches section parsed from a trailing-whitespace row');

// flows_load_all(): legacy mode / absent flows dir -> [], storage mode reads & ksorts parseable platform docs.
eq(flows_load_all(), [], 'legacy mode -> flows_load_all empty');

$GLOBALS['__cfg_saved_flows'] = $GLOBALS['__cfg'];

$flows_empty_scratch = sys_get_temp_dir() . '/ddp-inspector-flows-empty-' . getmypid();
exec('rm -rf ' . escapeshellarg($flows_empty_scratch));
$GLOBALS['__cfg'] = ['storage_root' => $flows_empty_scratch, 'default_n' => 15, 'base_path' => ''];
eq(flows_load_all(), [], 'storage mode with no flows dir on disk -> empty array');

$flows_scratch = sys_get_temp_dir() . '/ddp-inspector-flows-test-' . getmypid();
exec('rm -rf ' . escapeshellarg($flows_scratch));
@mkdir("$flows_scratch/config/flows/facebook", 0755, true);
@mkdir("$flows_scratch/config/flows/tiktok", 0755, true);
@mkdir("$flows_scratch/config/flows/broken", 0755, true);
copy(__DIR__ . '/fixtures/flows/facebook/documentation.txt', "$flows_scratch/config/flows/facebook/documentation.txt");
copy(__DIR__ . '/fixtures/flows/tiktok/documentation.txt', "$flows_scratch/config/flows/tiktok/documentation.txt");
file_put_contents("$flows_scratch/config/flows/broken/documentation.txt", ''); // no '# ' heading -> unparseable
$GLOBALS['__cfg'] = ['storage_root' => $flows_scratch, 'default_n' => 15, 'base_path' => ''];

$all = flows_load_all();
eq(array_keys($all), ['facebook', 'tiktok'], 'flows_load_all returns both slugs ksorted, silently skipping the unparseable one');
eq($all['facebook']['platform_title'], 'Facebook', 'facebook doc parsed via flows_load_all');
eq($all['tiktok']['platform_title'], 'TikTok', 'tiktok doc parsed via flows_load_all');

$GLOBALS['__cfg'] = $GLOBALS['__cfg_saved_flows'];
exec('rm -rf ' . escapeshellarg($flows_scratch));
exec('rm -rf ' . escapeshellarg($flows_empty_scratch));
