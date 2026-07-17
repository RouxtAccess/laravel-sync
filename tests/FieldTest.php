<?php

use Rouxtaccess\Sync\Field;

it('resolves a closure default against prior answers', function () {
    $field = new Field('db_port', 'Port', default: fn (array $answers): string => (string) ($answers['base'] ?? 1));

    expect($field->textDefault(['base' => 9]))->toBe('9')
        ->and($field->textDefault([]))->toBe('1');
});

it('casts a value through the cast closure', function () {
    $field = new Field('db_port', 'Port', cast: fn ($value): int => (int) $value);

    expect($field->cast('7'))->toBe(7)
        ->and($field->cast('0'))->toBe(0);
});

it('returns the value unchanged without a cast', function () {
    $field = new Field('name', 'Name');

    expect($field->cast('forge'))->toBe('forge');
});

it('reads a boolean default', function () {
    expect((new Field('delete', 'Delete', boolean: true, default: true))->booleanDefault())->toBeTrue()
        ->and((new Field('delete', 'Delete', boolean: true))->booleanDefault())->toBeFalse();
});
