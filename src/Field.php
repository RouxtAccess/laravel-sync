<?php

namespace Rouxtaccess\Sync;

use Closure;

class Field
{
    /**
     * @param  array<string, string>|null  $options
     */
    public function __construct(
        public string $key,
        public string $label,
        public bool $required = true,
        public bool $secret = false,
        public bool $boolean = false,
        public ?array $options = null,
        public string|bool|Closure|null $default = null,
        public ?string $placeholder = null,
        public ?string $hint = null,
        public ?Closure $cast = null,
    ) {}

    /**
     * @param  array<string, mixed>  $answers
     */
    public function textDefault(array $answers): string
    {
        $default = $this->default instanceof Closure ? ($this->default)($answers) : $this->default;

        return (string) ($default ?? '');
    }

    public function booleanDefault(): bool
    {
        return (bool) ($this->default ?? false);
    }

    public function cast(mixed $value): mixed
    {
        return $this->cast instanceof Closure ? ($this->cast)($value) : $value;
    }
}
