<?php

declare(strict_types=1);

namespace Mercurius\McpSsh;

/** What was kept of one output stream and whether more came than was kept. */
final class Drained {
    public function __construct(public readonly string $kept, public readonly bool $cut) {}
}
