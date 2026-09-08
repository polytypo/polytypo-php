<?php

declare(strict_types=1);

namespace Polytypo\Modes;

use Polytypo\Engine\Codepoints;

/**
 * HTML span extraction via a minimal, hand-rolled, position-tracking tokenizer -- not
 * `DOMDocument` (ARCHITECTURE.md section 2 names "DOM" for PHP, but verified this session that
 * `DOMNode::getLineNo()` gives only a line number and `DOMNode::getColumnNo()` does not exist at
 * all -- strictly worse than Ruby's rejected `nokogiri`). Mirrors what Ruby/Python/Go already
 * hand-roll or use a low-level tokenizer for, for the same reason: neither is a full HTML5
 * tree-construction implementation either, and this does not need to be one.
 *
 * Operates on an explicit code-point array (ARCHITECTURE.md section 4.2) -- PHP strings are byte
 * arrays, not code-point-indexed, unlike Ruby's UTF-8 String.
 */
final class Html
{
    /**
     * modes.md 3.6, exhaustive and CLOSED: extending it is a spec change, not an implementation
     * decision. svg/math are here because in MathML a quotation mark, a hyphen and a prime are
     * operators and identifiers -- substituting a curly glyph changes what the expression means.
     */
    private const SKIPPED_ELEMENTS = ['code', 'pre', 'kbd', 'samp', 'var', 'script', 'style', 'textarea', 'svg', 'math'];

    /**
     * HTML5 void elements: never pushed onto the element stack, since an author is not required
     * to close them and this tokenizer never synthesizes a matching end tag for one.
     */
    private const VOID_ELEMENTS = [
        'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr',
    ];

    private const TAG_NAME_STOP = ['>', '/', ' ', "\t", "\n", "\r", "\f"];

    private function __construct()
    {
    }

    private static function isAsciiAlpha(string $ch): bool
    {
        return ($ch >= 'a' && $ch <= 'z') || ($ch >= 'A' && $ch <= 'Z');
    }

    private static function isAsciiAlnum(string $ch): bool
    {
        return self::isAsciiAlpha($ch) || ($ch >= '0' && $ch <= '9');
    }

    private static function isHexDigit(string $ch): bool
    {
        return ($ch >= '0' && $ch <= '9') || ($ch >= 'a' && $ch <= 'f') || ($ch >= 'A' && $ch <= 'F');
    }

    /**
     * Ranges [start, end) of every well-formed character reference in $chars (a code-point-array
     * slice, one string per code point) -- &name;, &#1234;, &#x2014; -- as spec/rules/modes.md 3.6
     * requires them treated: opaque units, split out of the surrounding text span so their exact
     * source spelling survives untouched. "Well-formed" is syntactic (shape only, matching the
     * html5lib/Python html.parser precedent this project's other ports follow), not validated
     * against the registered named-character-reference table: a bare & that begins no well-formed
     * reference is deliberately left inside its span (modes.md 3.6: "Tom & Jerry's \"book\"" must
     * not lose the pairing of its quotation marks to a spurious boundary).
     *
     * @param string[] $chars
     * @return array<int, array{0: int, 1: int}>
     */
    public static function findEntityRefs(array $chars): array
    {
        $refs = [];
        $n = count($chars);
        $i = 0;
        while ($i < $n) {
            if ($chars[$i] !== '&') {
                $i++;
                continue;
            }
            $start = $i;
            $j = $i + 1;
            if ($j < $n && $chars[$j] === '#' && $j + 1 < $n && ($chars[$j + 1] === 'x' || $chars[$j + 1] === 'X')) {
                $k = $j + 2;
                while ($k < $n && self::isHexDigit($chars[$k])) {
                    $k++;
                }
                if ($k > $j + 2 && $k < $n && $chars[$k] === ';') {
                    $refs[] = [$start, $k + 1];
                    $i = $k + 1;
                    continue;
                }
            } elseif ($j < $n && $chars[$j] === '#') {
                $k = $j + 1;
                while ($k < $n && $chars[$k] >= '0' && $chars[$k] <= '9') {
                    $k++;
                }
                if ($k > $j + 1 && $k < $n && $chars[$k] === ';') {
                    $refs[] = [$start, $k + 1];
                    $i = $k + 1;
                    continue;
                }
            } elseif ($j < $n && self::isAsciiAlpha($chars[$j])) {
                $k = $j + 1;
                while ($k < $n && self::isAsciiAlnum($chars[$k])) {
                    $k++;
                }
                if ($k < $n && $chars[$k] === ';') {
                    $refs[] = [$start, $k + 1];
                    $i = $k + 1;
                    continue;
                }
            }
            $i++;
        }

        return $refs;
    }

    /**
     * Parses "<name ...>", "</name>" or "<name .../>" (as a code-point-array slice, one string
     * per code point) into [name, closing, selfClosing]. Comments, declarations and processing
     * instructions ("<!--", "<!", "<?") have no element name and return null.
     *
     * Public: also used by a future Markdown mode for inline-raw-HTML tag-stack handling.
     *
     * @param string[] $chars
     * @return array{0: string, 1: bool, 2: bool}|null
     */
    public static function readTag(array $chars): ?array
    {
        if ($chars === [] || $chars[0] !== '<') {
            return null;
        }

        $n = count($chars);
        $i = 1;
        $closing = $i < $n && $chars[$i] === '/';
        if ($closing) {
            $i++;
        }
        if ($i >= $n || $chars[$i] === '!' || $chars[$i] === '?') {
            return null;
        }
        if (!self::isAsciiAlpha($chars[$i])) {
            return null;
        }

        $nameStart = $i;
        while ($i < $n && !in_array($chars[$i], self::TAG_NAME_STOP, true)) {
            $i++;
        }
        $name = strtolower(implode('', array_slice($chars, $nameStart, $i - $nameStart)));
        $joined = rtrim(implode('', $chars));
        $selfClosing = str_ends_with($joined, '/>');

        return [$name, $closing, $selfClosing];
    }

    /** @param string[] $stack */
    private static function lastStackIndex(array $stack, string $tag): ?int
    {
        for ($i = count($stack) - 1; $i >= 0; $i--) {
            if ($stack[$i] === $tag) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Locates the processable spans of an HTML document. The tokenizer is used only to locate
     * spans and is then discarded (modes.md 4: "the document is never serialised") --
     * attributes, tag syntax and entity spelling are never touched.
     *
     * @return Span[]
     */
    public static function htmlSpans(string $source): array
    {
        return ParseError::wrap(static function () use ($source): array {
            $cp = Codepoints::toCodepoints($source);
            $chars = array_map(static fn (int $c): string => mb_chr($c, 'UTF-8'), $cp);
            $n = count($chars);

            $spans = [];
            $stack = [];
            $skipDepth = 0;
            $i = 0;

            while ($i < $n) {
                if ($chars[$i] === '<') {
                    $tagEnd = self::findTagEnd($chars, $i);
                    if ($tagEnd === null) {
                        // A bare "<" with no closing ">" before EOF: the rest of the document is text.
                        self::emitText($spans, $chars, $i, $n, $skipDepth);
                        $i = $n;
                        continue;
                    }

                    $tagChars = array_slice($chars, $i, $tagEnd - $i);
                    $parsed = self::readTag($tagChars);
                    if ($parsed !== null) {
                        [$name, $closing, $selfClosing] = $parsed;
                        if ($closing) {
                            $idx = self::lastStackIndex($stack, $name);
                            if ($idx !== null) {
                                $popped = array_slice($stack, $idx);
                                $stack = array_slice($stack, 0, $idx);
                                foreach ($popped as $n2) {
                                    if (in_array($n2, self::SKIPPED_ELEMENTS, true)) {
                                        $skipDepth--;
                                    }
                                }
                            }
                        } elseif ($selfClosing || in_array($name, self::VOID_ELEMENTS, true)) {
                            // Opens and closes atomically; no persistent stack effect regardless
                            // of the tag's identity, since there is no subsequent content inside
                            // it to skip.
                        } else {
                            $stack[] = $name;
                            if (in_array($name, self::SKIPPED_ELEMENTS, true)) {
                                $skipDepth++;
                            }
                        }
                    }
                    // Comments/declarations/processing instructions ($parsed === null) have no
                    // stack effect.
                    $i = $tagEnd;
                } else {
                    $textEnd = $i;
                    while ($textEnd < $n && $chars[$textEnd] !== '<') {
                        $textEnd++;
                    }
                    self::emitText($spans, $chars, $i, $textEnd, $skipDepth);
                    $i = $textEnd;
                }
            }

            return $spans;
        });
    }

    /**
     * Finds the index just past the ">" that closes the tag starting at $chars[$start] === "<",
     * honoring comments ("<!--" ... "-->") and quoted attribute values so a ">" inside either
     * does not end the tag early. Returns null if unterminated.
     *
     * @param string[] $chars
     */
    private static function findTagEnd(array $chars, int $start): ?int
    {
        $n = count($chars);
        if (array_slice($chars, $start, 4) === ['<', '!', '-', '-']) {
            $close = self::indexOfSequence($chars, ['-', '-', '>'], $start + 4);

            return $close === null ? null : $close + 3;
        }

        $i = $start + 1;
        $quote = null;
        while ($i < $n) {
            $ch = $chars[$i];
            if ($quote !== null) {
                if ($ch === $quote) {
                    $quote = null;
                }
            } elseif ($ch === '"' || $ch === "'") {
                $quote = $ch;
            } elseif ($ch === '>') {
                return $i + 1;
            }
            $i++;
        }

        return null;
    }

    /**
     * @param string[] $chars
     * @param string[] $seq
     */
    private static function indexOfSequence(array $chars, array $seq, int $from): ?int
    {
        $n = count($chars);
        $m = count($seq);
        $i = $from;
        while ($i + $m <= $n) {
            if (array_slice($chars, $i, $m) === $seq) {
                return $i;
            }
            $i++;
        }

        return null;
    }

    /**
     * @param Span[] $spans
     * @param string[] $chars
     */
    private static function emitText(array &$spans, array $chars, int $from, int $to, int $skipDepth): void
    {
        if ($skipDepth !== 0 || $to <= $from) {
            return;
        }

        $slice = array_slice($chars, $from, $to - $from);
        $refs = self::findEntityRefs($slice);
        $cursor = 0;
        foreach ($refs as [$refStart, $refEnd]) {
            if ($refStart > $cursor) {
                $spans[] = new Span($from + $cursor, $from + $refStart);
            }
            $cursor = $refEnd;
        }
        if ($cursor < count($slice)) {
            $spans[] = new Span($from + $cursor, $to);
        }
    }
}
