<?php

declare(strict_types=1);

namespace Mercurius\McpSsh;

/** What a remote command left: its exit code (null — it did not end), its output, whether the output was cut. */
final class Result {
    public function __construct(
        public readonly ?int $exit,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly bool $cut = false,
    ) {}
}
