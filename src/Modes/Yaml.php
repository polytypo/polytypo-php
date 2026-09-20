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

        return $spans;
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

        // step 1 -- blank, or a tab anywhere, which makes indentation undecidable.
        $start = self::firstNonSpace($chars, $lineStart, $lineEnd);
        if ($start === $lineEnd || self::hasTab($chars, $lineStart, $lineEnd)) {
            return $skipLine;
        }
        $indent = $start - $lineStart;

        // steps 2 and 3 -- comment, directive, document marker.
        if ($chars[$start] === '#' || $chars[$start] === '%') {
            return $skipLine;
        }
        if (self::isDocumentMarker($chars, $start, $lineEnd)) {
            return $skipLine;
        }

        // step 4 -- block sequence entries are consumed, not skipped; `- - key: v` nests.
        $i = $start;
        while ($i < $lineEnd && self::isSequenceDash($chars, $i, $lineEnd)) {
            $i += 2;
            while ($i < $lineEnd && $chars[$i] === self::SPACE) {
                $i++;
            }
        }
        if ($i >= $lineEnd) {
            return $skipLine;
        }

        // step 5 -- find the key. A colon NOT followed by U+0020 or the line end is an ordinary
        // key character, so `a:b: v` has the key `a:b`; stating that keeps five scanners agreeing.
        $keyStart = $i;
        $colon = -1;
        for ($j = $i; $j < $lineEnd; $j++) {
            if (in_array($chars[$j], self::DECLINING_KEY_CHARS, true)) {
                return $skipLine;
            }
            if (self::isIndicatorColon($chars, $j, $lineEnd)) {
                $colon = $j;
                break;
            }
        }
        if ($colon < 0) {
            return $skipLine;
        }
        $keyEnd = $colon;
        while ($keyEnd > $keyStart && $chars[$keyEnd - 1] === self::SPACE) {
            $keyEnd--;
        }
        if ($keyEnd <= $keyStart) {
            return $skipLine;
        }

        // step 6 -- an empty value means a nested node, whose lines ARE scanned on their own.
        $v = $colon + 1;
        while ($v < $lineEnd && $chars[$v] === self::SPACE) {
            $v++;
        }
        if ($v >= $lineEnd) {
            return $skipLine;
        }

        // step 7 -- the line carries an inline value, so its continuation lines belong to it.
        $next = self::valueRunEnd($chars, $lines, $li, $indent);

        // step 8 -- the key must be listed. Checked before the value's form, so an unlisted key
        // costs nothing to decline: this is what makes `run:`, `if:` and `image:` unreachable.
        $key = implode('', array_slice($chars, $keyStart, $keyEnd - $keyStart));
        if (!isset($keys[$key])) {
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
            if ($next === $li + 1) {
                self::quotedScalar($chars, $lineEnd, $v, $spans);
            }

            return $next;
        }
        if ($next === $li + 1) {
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

        // One definition of the run, and three conditions that make the whole block yield no
        // spans.
        $content = [];
        $contentIndent = -1;
        for ($k = $li + 1; $k < $runEnd; $k++) {
            [$ns, $ne] = $lines[$k];
            $first = self::firstNonSpace($chars, $ns, $ne);
            if ($first === $ne) {
                continue; // blank lines belong to the block and yield no span
            }
            if (self::hasTab($chars, $ns, $ne)) {
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
     * 3.8.6. The span is the content between the quotes. Both bails exist so that source
     * characters and content characters are the same thing, which the offset model of 3.1 requires
     * -- the same constraint that makes an HTML character reference an opaque unit in 3.6. No
     * colon test applies here: quoting neutralises the colon, and applying the plain-scalar test
     * would decline `title: "Chapter 1: the beginning"`.
     *
     * @param list<string> $chars
     * @param list<Span> $spans
     */
    private static function quotedScalar(array $chars, int $lineEnd, int $v, array &$spans): void
    {
        $quote = $chars[$v];
        $close = -1;
        for ($j = $v + 1; $j < $lineEnd; $j++) {
            $c = $chars[$j];
            if ($quote === '"' && $c === '\\') {
                return;
            }
            if ($quote === "'" && $c === "'" && $j + 1 < $lineEnd && $chars[$j + 1] === "'") {
                return;
            }
            if ($c === $quote) {
                $close = $j;
                break;
            }
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

        if ($close > $v + 1) {
            $spans[] = new Span($v + 1, $close);
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
