<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitKanboard\Exception;

/**
 * Thrown when Kanboard authentication fails (HTTP 401/403).
 */
final class KanboardAuthException extends \RuntimeException
{
    public static function unauthorized(): self
    {
        return new self('Kanboard authentication failed — check KANBOARD_USERNAME and KANBOARD_API_TOKEN.');
    }

    public static function forbidden(): self
    {
        return new self('Kanboard access denied — the user may lack permissions for this operation.');
    }
}
