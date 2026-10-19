<?php

declare(strict_types=1);

namespace Polytypo\Modes;

use Polytypo\Engine\Codepoints;

/**
 * spec/rules/modes.md 3.8. `yaml` differs from the other two document modes twice over.
 *
 * It uses NO PARSER (3.8.1): two of the five ecosystems' YAML libraries cannot report the source
 * offsets the round-trip guarantee needs -- this one, symfony/yaml, reports no positions at all --
 * so the scan below is specified rather than delegated and is written the same way in every
 * runtime.
 *
 * And the caller names the keys (3.8.2). YAML is a data format with islands of prose in it -- the
 * inverse of HTML and Markdown -- and nothing in its syntax separates `description:` from `run:`.
 * A keyless draft of this file rewrote `if !` as `if!` inside a workflow's shell script; there is
 * no content test that would not, because shell and template expressions are written in words.
 *
 * It also SKIPS BY DEFAULT -- the inverse of 3.6's closed skip list. A construct this scan does
 * not recognise with certainty yields no spans, so the worst outcome of a gap in it is prose left
 * untypeset, never a changed byte.
 */
final class Yaml
{
    private const SPACE = ' ';
    private const TAB = "\t";
    private const LF = "\n";
    private const CR = "\r";
    private const DECLINING_KEY_CHARS = ['"', "'", '{', '[', '&', '*', '!', '#'];
    private const NON_SCALAR_VALUE_CHARS = ['#', '&', '*', '!', '{', '['];

    private function __construct()
    {
    }

    /**
     * @param array<string, true> $keys
     * @return list<Span>
     */
    public static function yamlSpans(string $source, array $keys): array
    {
        $cp = Codepoints::toCodepoints($source);
        /** @var list<string> $chars */
        $chars = array_map(static fn (int $c): string => mb_chr($c, 'UTF-8'), $cp);
        $lines = self::splitLines($chars);

        $spans = [];
        $li = 0;
        while ($li < count($lines)) {
            $li = self::scanLine($chars, $lines, $li, $keys, $spans);
        }

        return self::dropCarriageReturnRegions($chars, $lines, $spans);
    }

    /**
     * 3.8.4's carriage-return region: a line containing a U+000D yields no spans, and neither does
     * any later line up to the first one that is non-empty, does not begin with U+0020 and
     * contains none. It removes spans and never adds one.
     *
     * @param list<string> $chars
     * @param list<array{int, int}> $lines
     * @param list<Span> $spans
     * @return list<Span>
     */
    private static function dropCarriageReturnRegions(array $chars, array $lines, array $spans): array
    {
        $declined = [];
        $inRegion = false;
        foreach ($lines as [$ns, $ne]) {
            $hasCr = false;
            for ($i = $ns; $i < $ne; $i++) {
                if ($chars[$i] === self::CR) {
                    $hasCr = true;
                    break;
                }
            }
            if ($hasCr) {
                $inRegion = true;
            } elseif ($ne > $ns && $chars[$ns] !== self::SPACE) {
                $inRegion = false;
            }
            $declined[] = $inRegion;
        }
        if (!in_array(true, $declined, true)) {
            return $spans;
        }

        $kept = [];
        $li = 0;
        foreach ($spans as $span) {
            while ($li + 1 < count($lines) && $span->start >= $lines[$li + 1][0]) {
                $li++;
            }
            if (!$declined[$li]) {
                $kept[] = $span;
            }
        }

        return $kept;
    }

    /**
     * 3.8.4: a line ends at U+000A, and A U+000D IMMEDIATELY BEFORE IT IS NOT PART OF THE LINE --
     * it is a terminator like the U+000A itself, so it lies outside every span and comes back
     * untouched. Without that clause a CRLF file behaves differently from the same bytes with LF:
     * the block header reads as `|` + U+000D and is unrecognised, and a plain scalar carries the
     * carriage return inside its span. Five runtimes split lines with five different standard
     * library calls, so the treatment has to be stated rather than inherited.
     *
     * @param list<string> $chars
     * @return list<array{int, int}> each line as [start, end]
     */
    private static function splitLines(array $chars): array
    {
        $lines = [];
        $start = 0;
        $n = count($chars);
        for ($i = 0; $i < $n; $i++) {
            if ($chars[$i] !== self::LF) {
                continue;
            }
            $lines[] = [$start, ($i > $start && $chars[$i - 1] === self::CR) ? $i - 1 : $i];
            $start = $i + 1;
        }
        if ($start < $n) {
            $lines[] = [$start, ($n > $start && $chars[$n - 1] === self::CR) ? $n - 1 : $n];
        }

        return $lines;
    }

    /** @param list<string> $chars */
    private static function firstNonSpace(array $chars, int $start, int $end): int
    {
        $i = $start;
        while ($i < $end && $chars[$i] === self::SPACE) {
            $i++;
        }

        return $i;
    }

    /** @param list<string> $chars */
    private static function hasTab(array $chars, int $start, int $end): bool
    {
        for ($i = $start; $i < $end; $i++) {
            if ($chars[$i] === self::TAB) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $chars */
    private static function hasLoneCarriageReturn(array $chars, int $start, int $end): bool
    {
        for ($i = $start; $i < $end; $i++) {
            if ($chars[$i] === self::CR) {
                return true;
            }
        }

        return false;
    }

    /**
     * 3.8.4 step 3. `---` and `...` at the head of a line, bare or introducing a node: the
     * trailing-content form is declined too, so `--- key: value` never yields a key of
     * `--- key`.
     *
     * @param list<string> $chars
     */
    private static function isDocumentMarker(array $chars, int $from, int $to): bool
    {
        if ($to - $from < 3) {
            return false;
        }
        $c = $chars[$from];
        if ($c !== '-' && $c !== '.') {
            return false;
        }
        if ($chars[$from + 1] !== $c || $chars[$from + 2] !== $c) {
            return false;
        }

        return $from + 3 === $to || $chars[$from + 3] === self::SPACE;
    }

    /**
     * A colon that ends the line or is followed by U+0020 -- the only colon YAML reads as an
     * indicator.
     *
     * @param list<string> $chars
     */
    private static function isIndicatorColon(array $chars, int $j, int $to): bool
    {
        if ($chars[$j] !== ':') {
            return false;
        }

        return $j + 1 === $to || $chars[$j + 1] === self::SPACE;
    }

    /** @param list<string> $chars */
    private static function isSequenceDash(array $chars, int $j, int $to): bool
    {
        if ($chars[$j] !== '-') {
            return false;
        }

        return $j + 1 === $to || $chars[$j + 1] === self::SPACE;
    }

    /**
     * The value run of 3.8.4 step 7: every following line that is blank or indented more than the
     * key line. THOSE LINES ARE NEVER SCANNED AGAIN -- without that, a multi-line quoted scalar, a
     * multi-line flow collection and a folded plain scalar all leak their continuation lines back
     * into the scan as if they were mappings, and a span can end up holding a scalar's own closing
     * delimiter.
     *
     * @param list<string> $chars
     * @param list<array{int, int}> $lines
     */
    private static function valueRunEnd(array $chars, array $lines, int $li, int $indent): int
    {
        $k = $li + 1;
        while ($k < count($lines)) {
            [$ns, $ne] = $lines[$k];
            $first = self::firstNonSpace($chars, $ns, $ne);
            if ($first !== $ne && $first - $ns <= $indent) {
                break;
            }
            $k++;
        }

        return $k;
    }

    /**
     * 3.8.4 step 7: a line of the value run that is not blank. Blank lines alone are not one.
     *
     * @param list<string> $chars
     * @param list<array{int, int}> $lines
     */
    private static function hasContinuation(array $chars, array $lines, int $li, int $runEnd): bool
    {
        for ($k = $li + 1; $k < $runEnd; $k++) {
            [$ns, $ne] = $lines[$k];
            if (self::firstNonSpace($chars, $ns, $ne) !== $ne) {
                return true;
            }
        }

        return false;
    }

    /**
     * 3.8.4 step 5: the index of the quote that closes the one at `$q`, or -1. In a double-quoted
     * scalar a U+005C and the code point after it never close the quote, which decodes nothing:
     * no YAML escape ends in anything that would be a closing quote. A quote not closed on the
     * line returns -1.
     *
     * @param list<string> $chars
     */
    private static function closingQuote(array $chars, int $lineEnd, int $q): int
    {
        $quote = $chars[$q];
        for ($j = $q + 1; $j < $lineEnd; $j++) {
            $c = $chars[$j];
            if ($quote === '"' && $c === '\\') {
                $j++;
                continue;
            }
            if ($c !== $quote) {
                continue;
            }
            if ($quote === "'" && $j + 1 < $lineEnd && $chars[$j + 1] === "'") {
                $j++;
                continue;
            }

            return $j;
        }

        return -1;
    }

    /**
     * 3.8.4 step 3: the next line that begins in column 0 with a document marker.
     *
     * @param list<string> $chars
     * @param list<array{int, int}> $lines
     */
    private static function documentRunEnd(array $chars, array $lines, int $li): int
    {
        $k = $li + 1;
        while ($k < count($lines) && !self::isDocumentMarker($chars, $lines[$k][0], $lines[$k][1])) {
            $k++;
        }

        return $k;
    }

    /**
     * 3.8.4 step 5's own-line node: everything up to the first later line that is non-empty and
     * does not begin with U+0020, because the scan does not track the key above.
     *
     * @param list<string> $chars
     * @param list<array{int, int}> $lines
     */
    private static function ownLineRunEnd(array $chars, array $lines, int $li, int $indent): int
    {
        if ($indent === 0) {
            return self::documentRunEnd($chars, $lines, $li);
        }
        $k = $li + 1;
        while ($k < count($lines)) {
            [$nextStart, $nextEnd] = $lines[$k];
            if ($nextEnd > $nextStart && $chars[$nextStart] !== self::SPACE) {
                break;
            }
            $k++;
        }

        return $k;
    }

    /**
     * 3.8.4 step 4: `-`, `?` or `:` followed by U+0020 or the line end.
     *
     * @param list<string> $chars
     */
    private static function isNodeMarker(array $chars, int $j, int $to): bool
    {
        if ($chars[$j] !== '-' && $chars[$j] !== '?' && $chars[$j] !== ':') {
            return false;
        }

        return $j + 1 === $to || $chars[$j + 1] === self::SPACE;
    }

    /**
     * 3.8.4 step 5. Returns [colon, keyEnd, declined, openQuote]; colon is -1 for a line with no
     * key, which is a node line.
     *
     * @param list<string> $chars
     * @return array{int, int, bool, bool}
     */
    private static function locateKey(array $chars, int $lineEnd, int $i): array
    {
        $first = $chars[$i];
        if ($first === '"' || $first === "'") {
            $close = self::closingQuote($chars, $lineEnd, $i);
            if ($close < 0) {
                return [-1, -1, false, true];
            }
            $p = $close + 1;
            while ($p < $lineEnd && $chars[$p] === self::SPACE) {
                $p++;
            }
            if ($p < $lineEnd && self::isIndicatorColon($chars, $p, $lineEnd)) {
                return [$p, $close + 1, true, false];
            }

            return [-1, -1, false, false];
        }
        if (in_array($first, ['{', '[', '&', '*', '!'], true)) {
            return [-1, -1, false, false];
        }

        $declined = false;
        for ($j = $i; $j < $lineEnd; $j++) {
            $c = $chars[$j];
            if ($c === '#' && $chars[$j - 1] === self::SPACE) {
                break;
            }
            if (self::isIndicatorColon($chars, $j, $lineEnd)) {
                $keyEnd = $j;
                while ($keyEnd > $i && $chars[$keyEnd - 1] === self::SPACE) {
                    $keyEnd--;
                }

                return [$j, $keyEnd, $declined, false];
            }
            if (in_array($c, self::DECLINING_KEY_CHARS, true)) {
                $declined = true;
            }
        }

        return [-1, -1, false, false];
    }

    /**
     * 3.8.4 step 7: whether a declined key's value can continue on the next line.
     *
     * @param list<string> $chars
     */
    private static function declinedValueOpen(array $chars, int $lineEnd, int $v): bool
    {
        $value = $chars[$v];
        if (in_array($value, ['|', '>', '{', '[', '&', '!'], true)) {
            return true;
        }
        if ($value !== '"' && $value !== "'") {
            return false;
        }
        $close = self::closingQuote($chars, $lineEnd, $v);
        if ($close < 0) {
            return true;
        }
        $p = $close + 1;
        while ($p < $lineEnd && $chars[$p] === self::SPACE) {
            $p++;
        }

        return $p < $lineEnd && $chars[$p] !== '#';
    }

    /**
     * One step of 3.8.4. Returns the index of the next line to scan.
     *
     * @param list<string> $chars
     * @param list<array{int, int}> $lines
     * @param array<string, true> $keys
     * @param list<Span> $spans
     */
    private static function scanLine(array $chars, array $lines, int $li, array $keys, array &$spans): int
    {
        [$lineStart, $lineEnd] = $lines[$li];
        $skipLine = $li + 1;

        // step 1 -- blank. A tab makes indentation undecidable, so the line and whatever it
        // absorbs yield no spans, but steps 2 to 7 still decide what it absorbs.
        $start = self::firstNonSpace($chars, $lineStart, $lineEnd);
        if ($start === $lineEnd) {
            return $skipLine;
        }
        $isQuiet = self::hasTab($chars, $lineStart, $lineEnd);
        $indent = $start - $lineStart;

        // steps 2 and 3 -- comment, directive, document marker. A marker with a node after it
        // absorbs the document.
        if ($chars[$start] === '#' || $chars[$start] === '%') {
            return $skipLine;
        }
        if (self::isDocumentMarker($chars, $start, $lineEnd)) {
            $p = $start + 3;
            while ($p < $lineEnd && $chars[$p] === self::SPACE) {
                $p++;
            }
            if ($p >= $lineEnd || $chars[$p] === '#') {
                return $skipLine;
            }

            return $indent === 0 ? self::documentRunEnd($chars, $lines, $li) : $skipLine;
        }

        // step 4 -- node markers are consumed, not skipped; `- - key: v` nests.
        $i = $start;
        while ($i < $lineEnd && self::isNodeMarker($chars, $i, $lineEnd)) {
            $i += 2;
            while ($i < $lineEnd && $chars[$i] === self::SPACE) {
                $i++;
            }
        }
        if ($i >= $lineEnd || $chars[$i] === '#') {
            return $skipLine;
        }

        // step 5 -- find the key. A colon NOT followed by U+0020 or the line end is an ordinary
        // key character, so `a:b: v` has the key `a:b`; stating that keeps five scanners agreeing.
        [$colon, $keyEnd, $declined, $openQuote] = self::locateKey($chars, $lineEnd, $i);

        // No key: the line is a node of its own, and it absorbs its value run. One that opens a
        // flow collection, a block scalar or a quote and sits on a line of its own absorbs the
        // region.
        if ($colon < 0) {
            $isOwnLine = $i === $start
                && ($openQuote || in_array($chars[$i], ['{', '[', '|', '>'], true));
            if ($isOwnLine) {
                return self::ownLineRunEnd($chars, $lines, $li, $indent);
            }

            return self::valueRunEnd($chars, $lines, $li, $indent);
        }

        // step 6 -- an empty value means a nested node, whose lines ARE scanned on their own.
        $v = $colon + 1;
        while ($v < $lineEnd && $chars[$v] === self::SPACE) {
            $v++;
        }
        if ($v >= $lineEnd) {
            return $skipLine;
        }

        // step 7 -- the line carries an inline value, so its continuation lines belong to it. A
        // declined key's value absorbs them only if it can continue on the next line.
        if ($declined) {
            return self::declinedValueOpen($chars, $lineEnd, $v)
                ? self::valueRunEnd($chars, $lines, $li, $indent)
                : $skipLine;
        }
        $next = self::valueRunEnd($chars, $lines, $li, $indent);

        // step 8 -- the key must be listed. Checked before the value's form, so an unlisted key
        // costs nothing to decline: this is what makes `run:`, `if:` and `image:` unreachable.
        $key = implode('', array_slice($chars, $i, $keyEnd - $i));
        if (!isset($keys[$key])) {
            return $next;
        }
        if ($isQuiet) {
            return $next;
        }

        // step 9 -- the scalar form.
        $value = $chars[$v];
        if (in_array($value, self::NON_SCALAR_VALUE_CHARS, true)) {
            return $next;
        }
        if ($value === '|' || $value === '>') {
            self::blockScalar($chars, $lines, $li, $next, $indent, $v, $spans);

            return $next;
        }
        if ($value === '"' || $value === "'") {
            if (!self::hasContinuation($chars, $lines, $li, $next)) {
                self::quotedScalar($chars, $lineEnd, $v, $spans);
            }

            return $next;
        }
        if (!self::hasContinuation($chars, $lines, $li, $next)) {
            self::plainScalar($chars, $lineEnd, $v, $spans);
        }

        return $next;
    }

    /**
     * 3.8.5. One span per non-blank content line, starting after the block's own indentation. The
     * header, the indentation and every line terminator lie outside every span -- including the
     * run of line terminators at the end that the chomping indicator governs, which is why `|`,
     * `|-`, `|+`, `>`, `>-` and `>+` are handled identically here.
     *
     * @param list<string> $chars
     * @param list<array{int, int}> $lines
     * @param list<Span> $spans
     */
    private static function blockScalar(
        array $chars,
        array $lines,
        int $li,
        int $runEnd,
        int $indent,
        int $v,
        array &$spans,
    ): void {
        [, $lineEnd] = $lines[$li];

        // The header: at most one chomping indicator and at most one indentation indicator, in
        // either order, then optional spaces and an optional comment. Anything else is
        // unrecognised.
        $h = $v + 1;
        $explicitIndent = 0;
        $chomping = false;
        while ($h < $lineEnd) {
            $c = $chars[$h];
            if (($c === '-' || $c === '+') && !$chomping) {
                $chomping = true;
                $h++;
                continue;
            }
            if ($c >= '1' && $c <= '9' && $explicitIndent === 0) {
                $explicitIndent = (int) $c;
                $h++;
                continue;
            }
            break;
        }
        while ($h < $lineEnd && $chars[$h] === self::SPACE) {
            $h++;
        }
        if ($h < $lineEnd && $chars[$h] !== '#') {
            return;
        }

        // One definition of the run, and four conditions that make the whole block yield no
        // spans.
        $content = [];
        $contentIndent = -1;
        for ($k = $li + 1; $k < $runEnd; $k++) {
            [$ns, $ne] = $lines[$k];
            $first = self::firstNonSpace($chars, $ns, $ne);
            if ($first === $ne) {
                continue; // blank lines belong to the block and yield no span
            }
            if (self::hasTab($chars, $ns, $ne) || self::hasLoneCarriageReturn($chars, $ns, $ne)) {
                return;
            }
            $nextIndent = $first - $ns;
            if ($contentIndent < 0) {
                $contentIndent = $explicitIndent > 0 ? $indent + $explicitIndent : $nextIndent;
            }
            // An explicit indicator that disagrees with the block as written, or a later line
            // dedented inside it, is ambiguous rather than guessable -- bail rather than choose.
            if ($nextIndent < $contentIndent) {
                return;
            }
            $content[] = [$ns, $ne];
        }
        if ($contentIndent <= 0) {
            return;
        }

        foreach ($content as [$ns, $ne]) {
            $from = $ns + $contentIndent;
            if ($ne > $from) {
                $spans[] = new Span($from, $ne);
            }
        }
    }

    /**
     * 3.8.6. The span is the content between the quotes. The double-quoted backslash bail exists so
     * that source characters and content characters are the same thing, which the offset model of
     * 3.1 requires; a single-quoted `''` pair is an opaque two-code-point unit outside every span,
     * like an HTML character reference in 3.6. No colon test applies here: quoting neutralises the
     * colon, and applying the plain-scalar test would decline `title: "Chapter 1: the beginning"`.
     *
     * @param list<string> $chars
     * @param list<Span> $spans
     */
    private static function quotedScalar(array $chars, int $lineEnd, int $v, array &$spans): void
    {
        $quote = $chars[$v];
        $close = -1;
        $segment = $v + 1;
        $ranges = [];
        for ($j = $v + 1; $j < $lineEnd; $j++) {
            $c = $chars[$j];
            if ($quote === '"' && $c === '\\') {
                return;
            }
            if ($c !== $quote) {
                continue;
            }
            if ($quote === "'" && $j + 1 < $lineEnd && $chars[$j + 1] === "'") {
                if ($j > $segment) {
                    $ranges[] = [$segment, $j];
                }
                $segment = $j + 2;
                $j++;
                continue;
            }
            $close = $j;
            break;
        }
        if ($close < 0) {
            return;
        }

        $after = $close + 1;
        while ($after < $lineEnd && $chars[$after] === self::SPACE) {
            $after++;
        }
        if ($after < $lineEnd && $chars[$after] !== '#') {
            return;
        }

        if ($close > $segment) {
            $ranges[] = [$segment, $close];
        }
        foreach ($ranges as [$start, $end]) {
            $spans[] = new Span($start, $end);
        }
    }

    /**
     * 3.8.6. In a plain scalar `:` and `#` are still live: U+0020 beside either of them is what
     * turns a scalar into a mapping indicator or a comment, and `dashes` emits U+0020 in every
     * `-spaced` locale. Lifting both out as opaque units puts the dash token at a span extremity,
     * where the edge-growth rule of 3.4 discards the replacement that emits one.
     *
     * @param list<string> $chars
     * @param list<Span> $spans
     */
    private static function plainScalar(array $chars, int $lineEnd, int $v, array &$spans): void
    {
        // The scalar ends before a trailing comment, so a colon inside that comment is not the
        // scalar's and must not decline it.
        $end = $lineEnd;
        for ($j = $v; $j < $lineEnd; $j++) {
            if ($chars[$j] === '#' && $j > $v && $chars[$j - 1] === self::SPACE) {
                $end = $j - 1;
                break;
            }
        }
        while ($end > $v && $chars[$end - 1] === self::SPACE) {
            $end--;
        }
        if ($end <= $v) {
            return;
        }

        // Compact nesting is not a value: `key: - item` opens a sequence, and `key: a .:` is a
        // mapping whose key is `a .` -- a plain scalar can never contain a colon in that position.
        if (self::isSequenceDash($chars, $v, $lineEnd)) {
            return;
        }
        for ($j = $v; $j < $end; $j++) {
            if (self::isIndicatorColon($chars, $j, $lineEnd)) {
                return;
            }
        }

        $segment = $v;
        for ($j = $v; $j <= $end; $j++) {
            if ($j !== $end && $chars[$j] !== ':' && $chars[$j] !== '#') {
                continue;
            }
            if ($j > $segment) {
                $spans[] = new Span($segment, $j);
            }
            $segment = $j + 1;
        }
    }
}
