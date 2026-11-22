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
    // Headings sit below the Study page's own section headings (h2, h3).
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

/** Untrusted HTML in, HTML that is safe to write into a page out. */
function rich_html(string $html): string {
    $html = (string)preg_replace('/<!--.*?-->/s', '', $html);
    // A tag is "<", an optional "/", a letter, then everything up to the next
    // ">". Quotes are not honoured: a ">" inside an attribute ends the tag
    // early and the rest falls out as text, which is escaped. Any other "<"
    // or ">" is text too.
    $parts = preg_split('~(</?[A-Za-z][^>]*>)~', $html, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
    $out = '';
    $open = [];        // written tags still open, innermost last
    $skipping = null;  // inside <script> or <style>: drop everything up to its end
    $closeTo = function (int $depth) use (&$out, &$open): void {
        while (count($open) > $depth) { $out .= '</' . array_pop($open) . '>'; }
    };
    foreach ($parts as $i => $part) {
        if ($i % 2 === 0) {
            if ($skipping === null) { $out .= str_replace(['<', '>'], ['&lt;', '&gt;'], $part); }
            continue;
        }
        preg_match('~^<(/?)([A-Za-z][A-Za-z0-9]*)~', $part, $m);
        $closing = $m[1] === '/';
        $name = strtolower($m[2]);
        if ($skipping !== null) { if ($closing && $name === $skipping) { $skipping = null; } continue; }
        if ($name === 'script' || $name === 'style') { if (!$closing) { $skipping = $name; } continue; }
        $tag = RICH_TAGS[$name] ?? null;
        if ($tag === null) { continue; }
        if ($tag === 'br') { if (!$closing) { $out .= '<br>'; } continue; }
        if ($closing) {
            // Close back to the matching open tag; a closer with no match is dropped.
            $at = array_search($tag, array_reverse($open, true), true);
            if ($at !== false) { $closeTo((int)$at); }
            continue;
        }
        if (in_array($tag, RICH_BLOCKS, true)) {
            // A paragraph does not hold a block. If one is open here (not
            // further out, around a list item or quote), it ends first.
            for ($d = count($open) - 1; $d >= 0 && !in_array($open[$d], RICH_CONTAINERS, true); $d--) {
                if ($open[$d] === 'p') { $closeTo($d); break; }
            }
        }
        if ($tag === 'a') {
            $href = rich_href($part);
            if ($href === null) { continue; }
            $out .= '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener noreferrer">';
        } else {
            $out .= "<$tag>";
        }
        $open[] = $tag;
    }
    $closeTo(0);
    return $out;
}
