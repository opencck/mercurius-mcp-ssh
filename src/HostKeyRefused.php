<?php

declare(strict_types=1);

namespace Mercurius\McpSsh;

/**
 * The host showed a key that is not the pinned one: nothing is sent to it. The fingerprint goes to whoever started the
 * server, so its owner can pin that key — the server never trusts a key by itself.
 */
final class HostKeyRefused extends \RuntimeException {
    public function __construct(public readonly string $fingerprint, public readonly bool $changed) {
        parent::__construct(sprintf('UNKNOWN_HOST_KEY %s %s', $fingerprint, $changed ? 'changed' : 'new'));
    }
}
