<?php
// Rich text from a study setup (consent, About, Helpdesk, manual pages) is HTML
// written in Next's editor. rich_html() rebuilds it from an allowlist: these
// pairs pin exactly what comes out.
$cases = [
  '<div>One.</div><div>Two.</div>' => '<p>One.</p><p>Two.</p>',
  // Next's editor ends blocks with blank lines for spacing; here paragraphs
  // space themselves, so line breaks at a paragraph's end go, and so does a
  // paragraph left empty. Breaks inside the text stay.
  '<!--block--><div>A<br><br></div>' => '<p>A</p>',
  '<div>a<br>b<br /></div>' => '<p>a<br>b</p>',
  '<div><br></div><div>x</div><div> &nbsp;<br> </div>' => '<p>x</p>',
  '<div><strong>a</strong><br><br></div><ul><li>b<br></li></ul>' => '<p><strong>a</strong></p><ul><li>b<br></li></ul>',
  '<h1><strong>Title</strong></h1>' => '<h4><strong>Title</strong></h4>',
  '<p onclick="x()" class="c">hi</p>' => '<p>hi</p>',
  '<script>alert(1)</script><p>ok</p><style>p{}</style>' => '<p>ok</p>',
  '<a href="mailto:a@example.org" target="_top">mail</a>' => '<a href="mailto:a@example.org" target="_blank" rel="noopener noreferrer">mail</a>',
  '<a href="javascript:alert(1)">x</a>' => 'x',
  '<a href=" JAVASCRIPT:alert(1)">x</a>' => 'x',
  '<a href="https://example.org/?a=1&amp;b=2">l</a>' => '<a href="https://example.org/?a=1&amp;b=2" target="_blank" rel="noopener noreferrer">l</a>',
  '<a title="><img src=x onerror=alert(1)>">t</a>' => '"&gt;t',   // tags end at the first >, quotes or not
  '<img src="x" onerror="alert(1)"><figure>cap</figure>' => 'cap',
  '<ul><li>a<li>b' => '<ul><li>a</li><li>b</li></ul>',            // Review Focus 2: closed at the end; a new item ends the open one
  // A list item is only written inside a list: a bare one would end the
  // page's own list item around this text.
  '<li>x</li>' => 'x',
  '<p>a</p><li>b</li><p>c</p>' => '<p>a</p>b<p>c</p>',
  '<ol><li>a<ul><li>b</li><li>c</li></ul></li><li>d</li></ol>' => '<ol><li>a<ul><li>b</li><li>c</li></ul></li><li>d</li></ol>',
  '<ul><li><p>a</p><li>b</ul>' => '<ul><li><p>a</p></li><li>b</li></ul>',
  '<ul><li>a</li></ul></li></ul>b' => '<ul><li>a</li></ul>b',
  '<strong>bold</p></div>after' => '<strong>boldafter</strong>',   // Review Focus 2: stray closers dropped
  '1 < 2 > 0 &amp; &lt;b&gt;' => '1 &lt; 2 &gt; 0 &amp; &lt;b&gt;',
  '<pre><em>note</em> <a href="https://example.org">x</a></pre>' => '<pre><em>note</em> <a href="https://example.org" target="_blank" rel="noopener noreferrer">x</a></pre>',
  '<svg><script>x</script></svg><iframe src="//e"></iframe>' => '',
  '<p>a</p><script>never closed <p>b</p>' => '<p>a</p>',
  // A paragraph never holds a block: it is closed first, as a browser would.
  '<div>a<ul><li>b</li></ul>c</div>' => '<p>a</p><ul><li>b</li></ul>c',
  '<ul><li><div>x</div><div>y</div></li></ul>' => '<ul><li><p>x</p><p>y</p></li></ul>',
  '<div><strong>a<div>b</div></strong></div>' => '<p><strong>a</strong></p><p>b</p>',
  // Links: the address is the only attribute kept, and only a safe one.
  '<a data-href="https://ok.example" href="javascript:x">l</a>' => 'l',
  "<a href='https://example.org/a b'>l</a>" => 'l',
  '<A HREF=https://example.org/x>l</A>' => '<a href="https://example.org/x" target="_blank" rel="noopener noreferrer">l</a>',
  '<a href="https://example.org/&quot;onmouseover=&quot;x">l</a>' => '<a href="https://example.org/&quot;onmouseover=&quot;x" target="_blank" rel="noopener noreferrer">l</a>',
  '<!-- a > b --><p>c</p><!-- unterminated' => '<p>c</p>&lt;!-- unterminated',
  '<B>b</B><I>i</I><del>d</del><h2>t</h2><h6>u</h6>' => '<strong>b</strong><em>i</em><s>d</s><h5>t</h5><h6>u</h6>',
  '' => '',
];
foreach ($cases as $in => $want) { eq(rich_html($in), $want, 'rich_html: ' . $in); }
foreach (array_keys($cases) as $in) {
    $out = rich_html($in);
    check(preg_match('~<(?!/?(?:p|br|h[456]|strong|em|u|s|ul|ol|li|blockquote|pre|code)>|a href="[^"<>]*" target="_blank" rel="noopener noreferrer">|/a>)~', $out) === 0, 'only rebuilt tags in output: ' . $in);
    foreach (['p', 'h4', 'h5', 'h6', 'strong', 'em', 'u', 's', 'ul', 'ol', 'li', 'blockquote', 'pre', 'code', 'a'] as $t) {
        eq(preg_match_all('~<' . $t . '[ >]~', $out), substr_count($out, '</' . $t . '>'), 'balanced <' . $t . '>: ' . $in);
    }
}

// Long runs of whitespace and large inputs: nothing already written is ever
// lost, and the work grows with the input, not with its square.
$spaces = str_repeat(' ', 9000);
$out = rich_html('<h1>Consent</h1><p>You agree to A, B and C.</p><pre>' . $spaces . '</pre><p>Last line.</p>');
check(str_starts_with($out, '<h4>Consent</h4><p>You agree to A, B and C.</p><pre>') && str_ends_with($out, '</pre><p>Last line.</p>'),
      'a long run of spaces loses nothing written before it');
$out = rich_html('<ol><li><p>' . $spaces . 'x</p></li></ol>');
check(str_starts_with($out, '<ol><li><p>') && str_ends_with($out, 'x</p></li></ol>'), 'a long run of spaces inside a list item keeps the list');
eq(rich_html('<div>a' . str_repeat('<br>', 9000) . str_repeat('&nbsp; ', 3000) . '</div><div>b</div>'), '<p>a</p><p>b</p>', 'a long tail of blank lines is trimmed');
eq(rich_html("<div>a\u{a0}\u{a0} </div>"), '<p>a</p>', 'no-break spaces at a paragraph\'s end are trimmed');
eq(rich_html('<!--' . str_repeat('x', 200000) . '--><p>kept</p>'), '<p>kept</p>', 'a very long comment is removed, the rest kept');
$t0 = microtime(true);
$out = rich_html(str_repeat('<div>A paragraph of text.<br></div>', 20000));
$took = microtime(true) - $t0;
eq(substr_count($out, '<p>A paragraph of text.</p>'), 20000, 'twenty thousand paragraphs all come out');
check($took < 2.0, 'twenty thousand paragraphs take well under two seconds (took ' . round($took, 2) . ' s)');
