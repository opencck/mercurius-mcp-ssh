<?php

declare(strict_types=1);

namespace Mercurius\McpSsh;

/**
 * A command of one part that starts with sudo gets the login password — for sudo alone: `sudo -S` reads it on stdin,
 * the command runs in `sh -c` with its own stdin from /dev/null, so it cannot read the password even when sudo does not
 * ask for it (a ticket, NOPASSWD). A command of several parts (`;`, `&&`, `|`, a substitution, a second line) gets no
 * password: the other parts would read it. A ticket of `sudo -v` is no way: without a terminal the next sudo does not
 * see it.
 */
final class Sudo {
    /** @return array{string, string}|null the command and its stdin; null — the command goes as it is */
    public static function wrap(string $command, #[\SensitiveParameter] ?string $password): ?array {
        if ($password === null || preg_match('~^\s*sudo\s+(?!-S\b)~', $command) !== 1 || preg_match('~[;&|\n\r`]|\$\(~', $command) === 1) {
            return null;
        }
        $rest = preg_replace('~^\s*sudo\s+~', '', $command) ?? $command;
        return ["sudo -S -p '' sh -c " . SshRemote::quote($rest . ' < /dev/null'), $password . "\n"];
    }

    /** The output without the password, in the spellings a command could print it in. */
    public static function hide(string $text, #[\SensitiveParameter] ?string $password): string {
        if ($password === null || strlen($password) < 4) {
            return $text;
        }
        $spellings = array_values(array_unique([$password, base64_encode($password), rtrim(base64_encode($password), '='), bin2hex($password), strtoupper(bin2hex($password))]));
        usort($spellings, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        return str_replace($spellings, '***', $text);
    }
}
