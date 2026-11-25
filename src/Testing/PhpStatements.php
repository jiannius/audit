<?php

namespace Jiannius\Audit\Testing;

/**
 * Splits PHP source into statements, with token_get_all and nothing else.
 *
 * Comments are dropped and the contents of strings never appear in a statement's `text`, so neither
 * can match a pattern. A closure or block body is a statement list of its own, and its place in the
 * outer statement is `{…}`, so `Order::where(function ($q) {…})->update([…])` stays one statement
 * while the statements inside the closure are checked on their own.
 *
 * A body that belongs to a sanctioned audited write (`Auditor::update/delete/pivot(…, function () {…})`)
 * marks the statements inside as sanctioned.
 *
 * Blade files are handled too: PHP blocks tokenise as they are, and the code in `@php` blocks, directive
 * arguments and `{{ }}` / `{!! !!}` echoes is read out of the HTML around them.
 *
 * @internal
 */
final class PhpStatements
{
    /** A statement head with one of these opens a body whose statements are an audited write's own. */
    private const SANCTIONING_HEAD = '/\bAuditor::(?:update|delete|pivot)\(|\$auditor->(?:update|delete|pivot)\(/i';

    /** After a closing brace, these keep the statement going: `function () {…})->update(…)`, `{…}, $x)`. */
    private const CONTINUES = [')', ',', ';', '->', '?->', ']', '.', '?', ':', '??', '&&', '||', '=>', '+', '-', '*', '/'];

    /**
     * `raw` is the statement as written, with comments dropped, whitespace collapsed and bodies shown as {…}.
     * `text` is the same without the contents of strings, which is what patterns are matched against.
     *
     * @return list<array{line: int, raw: string, text: string, sanctioned: bool}>
     */
    public static function of(string $source, bool $blade = false): array
    {
        $statements = self::parse($source, 0, $blade);

        usort($statements, fn (array $a, array $b) => $a['line'] <=> $b['line']);

        return $statements;
    }

    /** @return list<array{line: int, raw: string, text: string, sanctioned: bool}> */
    private static function parse(string $source, int $lineOffset, bool $blade): array
    {
        $items = self::items($source, $lineOffset);

        $statements = [];
        $levels = [self::level(false)];

        $flush = function () use (&$levels, &$statements) {
            $level = &$levels[array_key_last($levels)];

            if ($level['line'] !== null && trim(implode('', $level['text'])) !== '') {
                $statements[] = [
                    'line' => $level['line'],
                    'raw' => trim((string) preg_replace('/\s+/', ' ', implode('', $level['raw']))),
                    'text' => self::join($level['text']),
                    'sanctioned' => $level['sanctioned'],
                ];
            }

            $level = self::level($level['sanctioned']);
        };

        $count = count($items);

        for ($i = 0; $i < $count; $i++) {
            [$kind, $raw, $text, $line] = $items[$i];

            switch ($kind) {
                case 'html':
                    $flush();

                    if ($blade) {
                        foreach (self::bladeCode($raw, $line) as [$codeLine, $code]) {
                            array_push($statements, ...self::parse('<?php '.$code.';', $codeLine - 1, false));
                        }
                    }

                    break;

                case 'end':
                    $flush();

                    break;

                case 'open':
                    $level = &$levels[array_key_last($levels)];
                    $head = self::join($level['text']);
                    $sanctioned = $level['sanctioned'] || preg_match(self::SANCTIONING_HEAD, $head) === 1;

                    $level['raw'][] = '{…}';
                    $level['text'][] = '{…}';
                    $level['line'] ??= $line;
                    unset($level);

                    $levels[] = self::level($sanctioned);

                    break;

                case 'close':
                    if (count($levels) === 1) {
                        $flush();

                        break;
                    }

                    $flush();
                    array_pop($levels);

                    // `}` ends a block statement (`if (...) {…}`) but not a closure that is an argument or a chain link.
                    $next = null;

                    for ($j = $i + 1; $j < $count; $j++) {
                        if (trim($items[$j][1]) !== '') {
                            $next = $items[$j][1];

                            break;
                        }
                    }

                    if ($next === null || ! in_array($next, self::CONTINUES, true)) {
                        $flush();
                    }

                    break;

                default:
                    $level = &$levels[array_key_last($levels)];

                    if ($level['line'] === null) {
                        if (trim($raw) === '') {
                            break;
                        }

                        $level['line'] = $line;
                    }

                    $level['raw'][] = $raw;
                    $level['text'][] = $text;
            }
        }

        while ($levels !== []) {
            $flush();
            array_pop($levels);
        }

        return $statements;
    }

    /** @return array{raw: list<string>, text: list<string>, line: ?int, sanctioned: bool} */
    private static function level(bool $sanctioned): array
    {
        return ['raw' => [], 'text' => [], 'line' => null, 'sanctioned' => $sanctioned];
    }

    /**
     * The tokens as items of [kind, raw, text, line], where kind is code, open, close, end or html.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: int}>
     */
    private static function items(string $source, int $lineOffset): array
    {
        $items = [];
        $line = 1;
        $string = null;
        $quote = '';

        foreach (token_get_all($source) as $token) {
            [$id, $text] = is_array($token) ? [$token[0], $token[1]] : [null, $token];
            $at = (is_array($token) ? $token[2] : $line) + $lineOffset;
            $line = (is_array($token) ? $token[2] : $line) + substr_count($text, "\n");

            if ($string !== null) {
                // Inside an interpolated string or a heredoc: nothing in it is code we match on.
                if (($string === 'quote' && $id === null && $text === $quote) || ($string === 'heredoc' && $id === T_END_HEREDOC)) {
                    $string = null;
                    $items[] = ['code', '"…"', '""', $at];
                }

                continue;
            }

            if ($id === T_START_HEREDOC) {
                $string = 'heredoc';

                continue;
            }

            if ($id === null && ($text === '"' || $text === '`')) {
                $string = 'quote';
                $quote = $text;

                continue;
            }

            $items[] = match (true) {
                $id === T_WHITESPACE, $id === T_COMMENT, $id === T_DOC_COMMENT => ['code', ' ', ' ', $at],
                $id === T_INLINE_HTML => ['html', $text, ' ', $at],
                $id === T_OPEN_TAG, $id === T_OPEN_TAG_WITH_ECHO, $id === T_CLOSE_TAG => ['end', $text, ' ', $at],
                $id === T_CONSTANT_ENCAPSED_STRING => ['code', $text, "''", $at],
                $id === null && $text === '{' => ['open', '{', '{', $at],
                $id === null && $text === '}' => ['close', '}', '}', $at],
                $id === null && $text === ';' => ['end', ';', ';', $at],
                default => ['code', $text, $text, $at],
            };
        }

        return $items;
    }

    /** Join tokens without spaces, except between two words, so `new Foo` stays `new Foo` and `$a->b()` stays `$a->b()`. */
    private static function join(array $parts): string
    {
        $out = '';
        $space = false;

        foreach ($parts as $part) {
            if ($part === ' ') {
                $space = true;

                continue;
            }

            if ($space && $out !== '' && preg_match('/[\w$\\\\]$/', $out) === 1 && preg_match('/^[\w$\\\\]/', $part) === 1) {
                $out .= ' ';
            }

            $out .= $part;
            $space = false;
        }

        return $out;
    }

    /**
     * The PHP code in the HTML of a Blade file: `@php` blocks, directive arguments, and echoes.
     *
     * @return list<array{0: int, 1: string}> [line, code]
     */
    private static function bladeCode(string $html, int $line): array
    {
        $found = [];

        // Blade comments and @verbatim blocks are not code.
        $html = (string) preg_replace_callback('/\{\{--.*?--\}\}|@verbatim.*?@endverbatim/s', fn (array $m) => preg_replace('/[^\n]/', ' ', $m[0]), $html);

        // @php ... @endphp first, and blanked out so what is inside is not read twice.
        $original = $html;
        $html = (string) preg_replace_callback('/@php(?!\s*\()(.*?)@endphp/s', function (array $m) use (&$found, $original, $line) {
            $found[] = [$line + substr_count(substr($original, 0, $m[1][1]), "\n"), $m[1][0]];

            return preg_replace('/[^\n]/', ' ', $m[0][0]);
        }, $html, flags: PREG_OFFSET_CAPTURE);
        $at = fn (int $offset): int => $line + substr_count(substr($html, 0, $offset), "\n");

        $patterns = [
            // @directive(arguments), with up to two levels of nested parentheses
            '/@\w+\s*\(((?:[^()]++|\((?:[^()]++|\([^()]*+\))*+\))*+)\)/s',
            '/\{\{\{?\s*(.+?)\s*\}?\}\}/s',
            '/\{!!\s*(.+?)\s*!!\}/s',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $html, $matches, PREG_OFFSET_CAPTURE) === false) {
                continue;
            }

            foreach ($matches[1] as [$code, $offset]) {
                $found[] = [$at($offset), $code];
            }
        }

        return $found;
    }
}
