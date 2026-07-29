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
