<?php

declare(strict_types=1);

namespace App\Database;

use RuntimeException;

/**
 * Розбиває SQL-файл на окремі оператори.
 *
 * Навіщо: PDO::exec() з кількома операторами не кидає виняток, якщо помилка не в першому
 * операторі, а наступні запити на цьому з'єднанні падають з 2014 (pending result sets) — D-15.
 * Тому міграції виконуються по одному оператору, а розбір робимо самі.
 *
 * Це машина станів по символах, а не регулярка: «;» усередині рядків, ідентифікаторів
 * і коментарів оператора не завершує.
 */
final class SqlSplitter
{
    /** @return list<string> непорожні оператори без кінцевого «;» та без коментарів */
    public static function split(string $sql): array
    {
        $sql = str_replace("\r\n", "\n", $sql);
        $length = strlen($sql);
        $statements = [];
        $current = '';
        $i = 0;

        while ($i < $length) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            if ($char === ';') {
                self::flush($statements, $current);
                $current = '';
                $i++;
            } elseif ($char === "'" || $char === '"') {
                $end = self::skipQuoted($sql, $i, $char, true);
                $current .= substr($sql, $i, $end - $i);
                $i = $end;
            } elseif ($char === '`') {
                // В ідентифікаторах «\» не екранує; подвоєний `` дає два послідовні закриття-відкриття
                $end = self::skipQuoted($sql, $i, '`', false);
                $current .= substr($sql, $i, $end - $i);
                $i = $end;
            } elseif ($char === '#' || ($char === '-' && $next === '-' && self::isCommentSpace($sql, $i + 2))) {
                // Коментар до кінця рядка; сам перенос лишається, щоб не склеїти токени
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $length : $end;
            } elseif ($char === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                if ($end === false) {
                    throw new RuntimeException('Unterminated block comment ' . self::where($sql, $i));
                }
                if (($sql[$i + 2] ?? '') === '!') {
                    // Умовний коментар /*! … */ MySQL виконує, тож лишаємо його в операторі як є
                    $current .= substr($sql, $i, $end + 2 - $i);
                } else {
                    $current .= ' ';
                }
                $i = $end + 2;
            } else {
                $current .= $char;
                $i++;
            }
        }
        self::flush($statements, $current);

        return $statements;
    }

    /**
     * Повертає позицію одразу за закриваючою лапкою.
     * Подвоєна лапка ('') обробляється природно: закриття і одразу нове відкриття.
     */
    private static function skipQuoted(string $sql, int $start, string $quote, bool $backslashEscapes): int
    {
        $length = strlen($sql);
        for ($i = $start + 1; $i < $length; $i++) {
            if ($backslashEscapes && $sql[$i] === '\\') {
                $i++; // наступний символ екрановано
                continue;
            }
            if ($sql[$i] === $quote) {
                return $i + 1;
            }
        }

        throw new RuntimeException("Unterminated quoted string {$quote} " . self::where($sql, $start));
    }

    /** За правилом MySQL «--» є коментарем, лише якщо після нього пробіл, табуляція, переніс або кінець тексту (5--3 — це вираз). */
    private static function isCommentSpace(string $sql, int $pos): bool
    {
        return $pos >= strlen($sql) || in_array($sql[$pos], [' ', "\t", "\n", "\r"], true);
    }

    /** @param list<string> $statements */
    private static function flush(array &$statements, string $current): void
    {
        $statement = trim($current);
        if ($statement === '') {
            return;
        }
        if (preg_match('/^DELIMITER\b/i', $statement) === 1) {
            throw new RuntimeException('DELIMITER is not supported in migrations (no procedures or triggers).');
        }
        $statements[] = $statement;
    }

    private static function where(string $sql, int $offset): string
    {
        $line = substr_count($sql, "\n", 0, $offset) + 1;

        return "at offset {$offset} (line {$line})";
    }
}
