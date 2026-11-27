<?php

declare(strict_types=1);

namespace Polytypo\Tests;

use PHPUnit\Framework\TestCase;
use Polytypo\Engine\Codepoints;
use Polytypo\Engine\Locale;
use Polytypo\Engine\Rules\HyphenRule;
use Polytypo\Engine\Rules\NbspRule;
use ReflectionMethod;

/**
 * The first-code-point indexes behind nbsp's list-driven sub-rules and hyphen's pattern search must
 * select exactly what a straight scan of the whole list selects, at every position, for every
 * locale's lists, including the case-lenient and space-lenient matchers.
 */
final class PatternIndexTest extends TestCase
{
    private static function call(string $class, string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod($class, $method))->invoke(null, ...$args);
    }

    /** @return string[] */
    private static function localeIds(): array
    {
        $ids = [];
        foreach (glob(dirname(__DIR__) . '/resources/spec/locales/*.json') ?: [] as $file) {
            $id = basename($file, '.json');
            if ($id !== 'registry') {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @param int[][] $words
     * @return int[][]
     */
    private static function probes(array $words): array
    {
        $out = [];
        foreach ($words as $w) {
            $text = Codepoints::fromCodepoints($w);
            foreach ([$text, mb_strtoupper($text), mb_strtolower($text), mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1)] as $variant) {
                $cp = Codepoints::toCodepoints(' 5' . $variant . ' x');
                $out[] = $cp;
                $out[] = array_map(static fn (int $c): int => $c === 0x20 ? 0xA0 : $c, $cp);
                $out[] = array_map(static fn (int $c): int => $c === 0x20 ? 0x202F : ($c === 0x2D ? 0x2011 : $c), $cp);
            }
        }

        return $out;
    }

    public function testNbspIndexesMatchLinearScans(): void
    {
        $checked = 0;
        foreach (self::localeIds() as $locale) {
            $data = Locale::localeDataRaw($locale);
            $prep = self::call(NbspRule::class, 'prepare', $data);
            $lists = [
                ['shortWords', 'matchFirstCharLenient'],
                ['abbreviations', 'matchSpaceLenient'],
                ['symbols', 'matchExact'],
                ['beforeNumber', 'matchExact'],
                ['beforeWord', 'matchExact'],
            ];
            foreach ($lists as [$key, $matcher]) {
                $fn = (new ReflectionMethod(NbspRule::class, $matcher))->getClosure();
                foreach (self::probes($prep[$key]) as $cp) {
                    foreach (array_keys($cp) as $a) {
                        $want = null;
                        foreach ($prep[$key] as $w) {
                            if ($fn($cp, $a, $w)) {
                                $want = $w;
                                break;
                            }
                        }
                        $got = self::call(NbspRule::class, 'longestMatch', $prep[$key . 'Index'], $cp, $a, $fn);
                        $this->assertSame($want, $got, "{$locale} {$key} at {$a} in " . Codepoints::fromCodepoints($cp));
                        $checked++;
                    }
                }
            }

            $units = $prep['units'];
            $unitWords = array_map(static fn (array $u): array => $u['w'], $units);
            foreach (self::probes($unitWords) as $cp) {
                foreach (array_keys($cp) as $a) {
                    $want = null;
                    foreach ($units as $unit) {
                        if (self::call(NbspRule::class, 'matchExact', $cp, $a, $unit['w'])) {
                            $want = $unit;
                            break;
                        }
                    }
                    $got = null;
                    foreach ($prep['unitsIndex'][$cp[$a]] ?? [] as $unit) {
                        if (self::call(NbspRule::class, 'matchExact', $cp, $a, $unit['w'])) {
                            $got = $unit;
                            break;
                        }
                    }
                    $this->assertSame($want, $got, "{$locale} units at {$a} in " . Codepoints::fromCodepoints($cp));
                    $checked++;
                }
            }
        }
        $this->assertGreaterThan(0, $checked);
    }

    public function testHyphenIndexMatchesLinearSelect(): void
    {
        $checked = 0;
        foreach (self::localeIds() as $locale) {
            $hyphen = Locale::localeDataRaw($locale)['hyphen'];
            if ($hyphen['compounds'] === [] && $hyphen['prefixes'] === [] && $hyphen['suffixes'] === []) {
                continue;
            }
            $patterns = [];
            foreach ([['compounds', 0], ['prefixes', 1], ['suffixes', 2]] as [$field, $kind]) {
                foreach ($hyphen[$field] as $entry) {
                    $patterns[] = ['cps' => Codepoints::toCodepoints($entry), 'kind' => $kind];
                }
            }
            $index = self::call(HyphenRule::class, 'indexPatterns', $patterns);
            foreach (self::probes(array_column($patterns, 'cps')) as $cp) {
                foreach (array_keys($cp) as $a) {
                    $want = self::call(HyphenRule::class, 'select', $cp, $a, self::linearIndex($patterns, $cp[$a]));
                    $got = self::call(HyphenRule::class, 'select', $cp, $a, $index);
                    $this->assertSame($want, $got, "{$locale} hyphen at {$a} in " . Codepoints::fromCodepoints($cp));
                    $checked++;
                }
            }
        }
        $this->assertGreaterThan(0, $checked);
    }

    /**
     * The whole list under every key: select then reads it exactly as the unindexed scan did.
     *
     * @param array{cps: int[], kind: int}[] $patterns
     * @return array<int, array{cps: int[], kind: int}[]>
     */
    private static function linearIndex(array $patterns, int $key): array
    {
        return [$key => $patterns];
    }
}
