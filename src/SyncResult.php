<?php

namespace Rouxtaccess\Sync;

class SyncResult
{
    /**
     * @param  array<string, mixed>  $data  outcome details for after-hooks (e.g. the imported database name)
     */
    protected function __construct(
        public bool $ok,
        public string $message,
        public array $data = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function success(string $message, array $data = []): self
    {
        return new self(true, $message, $data);
    }

    public static function failure(string $message): self
    {
        return new self(false, $message);
    }
}
