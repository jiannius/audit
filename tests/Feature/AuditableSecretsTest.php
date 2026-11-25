<?php

use Illuminate\Database\Eloquent\Casts\AsEncryptedArrayObject;
use Illuminate\Database\Eloquent\Casts\AsEncryptedCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Jiannius\Audit\AuditObserver;
use Jiannius\Audit\Tests\Fixtures\AuditedUser;
use Jiannius\Audit\Tests\Fixtures\Document;

function makeAuditedUser(array $attributes = []): AuditedUser
{
    return AuditedUser::create($attributes + [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.test',
        'username' => 'ada',
        'password' => 'plain-password-1',
    ]);
}

describe('passwords', function () {
    it('redacts a hashed password on create and update', function () {
        $user = makeAuditedUser();
        $hash = $user->getAttributes()['password'];

        expect(Hash::isHashed($hash))->toBeTrue()
            ->and($this->audits()->sole()->new_values)->toMatchArray(['password' => '[redacted]', 'email' => 'ada@example.test']);

        $user->update(['password' => 'plain-password-2']);

        $audit = $this->audits()->last();

        expect($audit->old_values)->toBe(['password' => '[redacted]'])
            ->and($audit->new_values)->toBe(['password' => '[redacted]'])
            ->and(everythingAudited())->not->toContain('plain-password')
            ->and(everythingAudited())->not->toContain($hash)
            ->and(everythingAudited())->not->toContain($user->getAttributes()['password']);
    });

    it('redacts the snapshot of a deleted record', function () {
        $user = makeAuditedUser();

        $user->delete();

        expect($this->audits()->last()->old_values)->toMatchArray(['password' => '[redacted]'])
            ->and(everythingAudited())->not->toContain('plain-password');
    });
});

describe('tokens and secrets', function () {
    it('never records the remember token', function () {
        $user = makeAuditedUser();

        $user->setRememberToken('remember-token-1');
        $user->timestamps = false;
        $user->save();

        $user->timestamps = true;
        $user->setRememberToken('remember-token-2');
        $user->save();

        expect($this->audits())->toHaveCount(1)
            ->and(DB::table('users')->value('remember_token'))->toBe('remember-token-2')
            ->and(everythingAudited())->not->toContain('remember-token');
    });

    it('excludes *_token and *_secret columns and two_factor_recovery_codes', function () {
        $document = Document::create(['title' => 'Keys', 'api_token' => 'tok-secret-1', 'webhook_secret' => 'whsec-1']);
        $document->update(['api_token' => 'tok-secret-2', 'webhook_secret' => 'whsec-2', 'total' => 1]);

        expect($this->audits()->first()->new_values)->not->toHaveKeys(['api_token', 'webhook_secret'])
            ->and($this->audits()->last()->new_values)->toBe(['total' => 1])
            ->and(everythingAudited())->not->toContain('tok-secret')
            ->and(everythingAudited())->not->toContain('whsec');

        $document->forceDelete();

        expect(everythingAudited())->not->toContain('tok-secret')
            ->and(everythingAudited())->not->toContain('whsec');
    });

    it('excludes two_factor_recovery_codes by default', function () {
        $values = app(AuditObserver::class)->values(new Document, [
            'title' => 'x',
            'two_factor_recovery_codes' => 'codes',
            'remember_token' => 'abc',
            'created_at' => '2026-01-01 00:00:00',
        ]);

        expect($values)->toBe(['title' => 'x']);
    });
});

describe('redaction rules', function () {
    it('redacts an encrypted cast without being asked', function () {
        $document = Document::create(['title' => 'Memo', 'notes' => 'the launch code is 1234']);

        expect($document->notes)->toBe('the launch code is 1234')
            ->and($this->audits()->sole()->new_values)->toMatchArray(['notes' => '[redacted]']);

        $document->update(['notes' => 'the launch code is 5678']);

        expect($this->audits()->last()->old_values)->toBe(['notes' => '[redacted]'])
            ->and(everythingAudited())->not->toContain('launch code')
            ->and(everythingAudited())->not->toContain(DB::table('documents')->value('notes'));
    });

    it('redacts what auditRedact() names', function () {
        $document = Document::create(['title' => 'Safe', 'pin' => '4321']);

        $document->update(['pin' => '8765']);

        expect($this->audits()->last()->new_values)->toBe(['pin' => '[redacted]'])
            ->and(everythingAudited())->not->toContain('4321')
            ->and(everythingAudited())->not->toContain('8765');
    });

    it('redacts hidden attributes, and only while redact_hidden is on', function () {
        $hidden = new class extends Document
        {
            protected $hidden = ['title'];
        };

        $values = app(AuditObserver::class)->values($hidden, ['title' => 'Secret title', 'total' => 3]);

        expect($values)->toBe(['title' => '[redacted]', 'total' => 3]);

        config(['audit.attributes.redact_hidden' => false]);

        expect(app(AuditObserver::class)->values($hidden, ['title' => 'Open title']))->toBe(['title' => 'Open title']);
    });

    it('stops redacting encrypted casts when redact_encrypted is off', function () {
        config(['audit.attributes.redact_encrypted' => false]);

        $values = app(AuditObserver::class)->values(new Document, ['notes' => 'cipher']);

        expect($values)->toBe(['notes' => 'cipher']);
    });

    it('keeps a null redacted attribute null', function () {
        $document = Document::create(['title' => 'Empty', 'notes' => null]);

        expect($this->audits()->sole()->new_values)->toMatchArray(['notes' => null]);
    });

    it('recognises the encrypted and hashed cast spellings', function () {
        $model = new class extends Document
        {
            protected function casts(): array
            {
                return [
                    'a' => 'encrypted:array',
                    'b' => 'hashed',
                    'c' => AsEncryptedArrayObject::class,
                    'd' => AsEncryptedCollection::class.':'.Collection::class,
                    'e' => 'encrypted',
                    'f' => 'array',
                ];
            }
        };

        $values = app(AuditObserver::class)->values($model, array_fill_keys(['a', 'b', 'c', 'd', 'e', 'f'], 'v'));

        expect($values)->toBe(['a' => '[redacted]', 'b' => '[redacted]', 'c' => '[redacted]', 'd' => '[redacted]', 'e' => '[redacted]', 'f' => 'v']);
    });
});
