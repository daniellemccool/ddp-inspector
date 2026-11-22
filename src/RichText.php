<?php
// Rich text from a study setup: the About, Consent and Helpdesk pages and the
// pages of an instruction manual are HTML written in Next's editor (Trix,
// whose default block is <div> and which leaves <!--block--> comments). It
// arrives in an upload, so none of it is trusted.
//
// rich_html() does not clean that HTML, it rebuilds it. The input is cut into
// tags and text. Text is escaped. A tag is never copied: if its name is on
// the list below, a fresh bare tag is written in its place (a link gets back
// only a checked address); otherwise nothing is written. So the only markup
// that can reach the page is what this file itself spells out.

/** What each input tag is written as. Anything else is dropped, its text kept. */
const RICH_TAGS = [
    'p' => 'p', 'div' => 'p', 'br' => 'br',
    // Headings sit below the Study page's own (a screen's title is an h2, a
    // manual chapter an h3).
    'h1' => 'h4', 'h2' => 'h5', 'h3' => 'h6', 'h4' => 'h6', 'h5' => 'h6', 'h6' => 'h6',
    'strong' => 'strong', 'b' => 'strong', 'em' => 'em', 'i' => 'em', 'u' => 'u',
    's' => 's', 'del' => 's', 'strike' => 's',
    'ul' => 'ul', 'ol' => 'ol', 'li' => 'li', 'blockquote' => 'blockquote',
    'pre' => 'pre', 'code' => 'code', 'a' => 'a',
];
/** Written tags that are blocks: a paragraph is closed before one opens. */
const RICH_BLOCKS = ['p', 'ul', 'ol', 'blockquote', 'pre', 'h4', 'h5', 'h6'];
/** Written tags that hold blocks: a paragraph outside one of these is not "open" inside it. */
const RICH_CONTAINERS = ['li', 'blockquote', 'ul', 'ol'];

/** The address of a link tag, if it is one a page may link to: http, https
 *  or mailto, with no whitespace or control characters in it. Otherwise null. */
function rich_href(string $tag): ?string {
    if (preg_match('~(?<![\w-])href\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))~i', $tag, $m) !== 1) { return null; }
    $href = trim(html_entity_decode((string)end($m), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if (preg_match('~^(https?://|mailto:)~i', $href) !== 1 || preg_match('/[\x00-\x20\x7f]/', $href) === 1) { return null; }
    return $href;
}

/** $html without its comments. An unterminated "<!--" is left as text. */
function rich_strip_comments(string $html): string {
    $out = ''; $at = 0;
    while (($start = strpos($html, '<!--', $at)) !== false) {
        $end = strpos($html, '-->', $start + 4);
        if ($end === false) { break; }
        $out .= substr($html, $at, $start - $at);
        $at = $end + 3;
    }
    return $out . substr($html, $at);
}

/** A piece of text without the blank space at its end (spaces, no-break
 *  spaces as characters or as &nbsp;). */
function rich_rtrim(string $text): string {
    do {
        $before = strlen($text);
        $text = rtrim($text, " \t\n\r\0\x0B\f");
        if (str_ends_with($text, '&nbsp;')) { $text = substr($text, 0, -6); }
        elseif (str_ends_with($text, "\u{a0}")) { $text = substr($text, 0, -2); }
    } while (strlen($text) !== $before);
    return $text;
}

/** Untrusted HTML in, HTML that is safe to write into a page out. */
function rich_html(string $html): string {
    $html = rich_strip_comments($html);
    // A tag is "<", an optional "/", a letter, then everything up to the next
    // ">". Quotes are not honoured: a ">" inside an attribute ends the tag
    // early and the rest falls out as text, which is escaped. Any other "<"
    // or ">" is text too.
    $parts = preg_split('~(</?[A-Za-z][^>]*>)~', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    if ($parts === false) { return htmlspecialchars($html, ENT_QUOTES, 'UTF-8'); }
    // The output is kept in pieces (one per tag or stretch of text) and joined
    // at the end, so that tidying a paragraph's end only ever touches the
    // last few pieces, however long the text before it.
    $out = [];
    $open = [];        // written tags still open, innermost last
    $skipping = null;  // inside <script> or <style>: drop everything up to its end
    $closeTo = function (int $depth) use (&$out, &$open): void {
        while (count($open) > $depth) {
            $tag = array_pop($open);
            if ($tag === 'p') {
                // Next's editor ends a block with blank lines for spacing. On
                // the page paragraphs space themselves, so line breaks and
                // blank space at a paragraph's end are dropped, and a
                // paragraph left with nothing in it is not written at all.
                while ($out !== []) {
                    $last = end($out);
                    if ($last === '<p>') { array_pop($out); continue 2; }
                    if ($last === '<br>') { array_pop($out); continue; }
                    if ($last[0] === '<') { break; }               // another tag: stop
                    $trimmed = rich_rtrim($last);
                    if ($trimmed === '') { array_pop($out); continue; }
                    $out[array_key_last($out)] = $trimmed;
                    break;
                }
            }
            $out[] = "</$tag>";
        }
    };
    foreach ($parts as $i => $part) {
        if ($i % 2 === 0) {
            if ($skipping === null && $part !== '') { $out[] = str_replace(['<', '>'], ['&lt;', '&gt;'], $part); }
            continue;
        }
        preg_match('~^<(/?)([A-Za-z][A-Za-z0-9]*)~', $part, $m);
        $closing = $m[1] === '/';
        $name = strtolower($m[2]);
        if ($skipping !== null) { if ($closing && $name === $skipping) { $skipping = null; } continue; }
        if ($name === 'script' || $name === 'style') { if (!$closing) { $skipping = $name; } continue; }
        $tag = RICH_TAGS[$name] ?? null;
        if ($tag === null) { continue; }
        if ($tag === 'br') { if (!$closing) { $out[] = '<br>'; } continue; }
        if ($closing) {
            // Close back to the matching open tag; a closer with no match is dropped.
            $at = array_search($tag, array_reverse($open, true), true);
            if ($at !== false) { $closeTo((int)$at); }
            continue;
        }
        if ($tag === 'li') {
            // A list item is written only inside a list, as that list's
            // direct child: a bare one would end whatever list item of the
            // page this text sits in. A new item ends the one still open.
            $list = false;
            for ($d = count($open) - 1; $d >= 0; $d--) {
                if ($open[$d] === 'ul' || $open[$d] === 'ol') { $list = $d; break; }
            }
            if ($list === false) { continue; }
            $closeTo($list + 1);
        } elseif (in_array($tag, RICH_BLOCKS, true)) {
            // A paragraph does not hold a block. If one is open here (not
            // further out, around a list item or quote), it ends first.
            for ($d = count($open) - 1; $d >= 0 && !in_array($open[$d], RICH_CONTAINERS, true); $d--) {
                if ($open[$d] === 'p') { $closeTo($d); break; }
            }
        }
        if ($tag === 'a') {
            $href = rich_href($part);
            if ($href === null) { continue; }
            $out[] = '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener noreferrer">';
        } else {
            $out[] = "<$tag>";
        }
        $open[] = $tag;
    }
    $closeTo(0);
    return implode('', $out);
}
