<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Rouxtaccess\Sync\Anonymizers\AnonymizeUserEmails;
use Rouxtaccess\Sync\Anonymizers\AnonymizeUserPhoneNumbers;

beforeEach(function () {
    $this->connection = DB::getDefaultConnection();

    Schema::connection($this->connection)->create('users', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('email');
        $table->string('phone_number')->nullable();
        $table->string('msisdn')->nullable();
    });

    DB::connection($this->connection)->table('users')->insert([
        ['name' => 'Ada', 'email' => 'ada@real.com', 'phone_number' => '+27821234567', 'msisdn' => '27821234567'],
        ['name' => 'Alan', 'email' => 'alan@real.com', 'phone_number' => '+27829876543', 'msisdn' => '27829876543'],
    ]);
});

afterEach(function () {
    Schema::connection($this->connection)->dropIfExists('users');
});

it('obfuscates every user email uniquely', function () {
    (new AnonymizeUserEmails)($this->connection);

    $emails = DB::connection($this->connection)->table('users')->pluck('email', 'id');

    expect($emails[1])->toBe('user1@example.test')
        ->and($emails[2])->toBe('user2@example.test')
        ->and($emails->unique()->count())->toBe(2)
        ->and($emails->filter(fn ($e) => str_contains($e, 'real.com')))->toBeEmpty();
});

it('obfuscates the phone number columns that exist', function () {
    (new AnonymizeUserPhoneNumbers)($this->connection);

    $rows = DB::connection($this->connection)->table('users')->get();

    expect($rows->pluck('phone_number')->every(fn ($p) => str_starts_with($p, '+1555')))->toBeTrue()
        ->and($rows->pluck('msisdn')->every(fn ($p) => str_starts_with($p, '+1555')))->toBeTrue()
        ->and($rows->pluck('phone_number')->unique()->count())->toBe(2);
});

it('is a no-op when the table is absent', function () {
    Schema::connection($this->connection)->dropIfExists('users');

    (new AnonymizeUserEmails)($this->connection);
    (new AnonymizeUserPhoneNumbers)($this->connection);
})->throwsNoExceptions();

it('ignores phone columns that do not exist', function () {
    Schema::connection($this->connection)->table('users', function ($table) {
        $table->dropColumn('msisdn');
    });

    (new AnonymizeUserPhoneNumbers)($this->connection);

    expect(DB::connection($this->connection)->table('users')->value('phone_number'))->toStartWith('+1555');
});
