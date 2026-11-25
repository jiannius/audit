<?php

use Illuminate\Database\Eloquent\Relations\Relation;
use Jiannius\Audit\Testing\AuditCoverage;
use Jiannius\Audit\Tests\Fixtures\Coverage\Bad;
use Jiannius\Audit\Tests\Fixtures\Coverage\Good;
use Jiannius\Audit\Tests\Fixtures\Coverage\Mixed\Kept;
use Jiannius\Audit\Tests\Fixtures\User;
use PHPUnit\Framework\AssertionFailedError;

function coverage(string $set): AuditCoverage
{
    return AuditCoverage::models(__DIR__.'/../Fixtures/Coverage/'.ucfirst($set), 'Jiannius\\Audit\\Tests\\Fixtures\\Coverage\\'.ucfirst($set));
}

/** The message a failing assertion gives. */
function coverageFailure(Closure $assertion): string
{
    try {
        $assertion();
    } catch (AssertionFailedError $e) {
        return $e->getMessage();
    }

    test()->fail('The assertion should have failed.');
}

beforeEach(function () {
    Relation::morphMap([
        'coverage-invoice' => Good\Invoice::class,
        'coverage-aliased' => Good\Aliased::class,
        'coverage-receipt' => Good\Receipt::class,
        'coverage-calls-parent' => Good\CallsParent::class,
        'coverage-overrides-save' => Bad\OverridesSave::class,
        'coverage-overrides-delete' => Bad\OverridesDelete::class,
        'coverage-child' => Bad\Child::class,
    ], merge: true);
});

describe('a set of models that is covered', function () {
    it('passes all three assertions, and chains', function () {
        coverage('good')
            ->except([Good\Scratch::class => 'Throwaway rows, never worth a trail.'])
            ->assertAllAuditable()
            ->assertMorphMapped()
            ->assertTransactional();

        expect(true)->toBeTrue();
    });

    it('does not count abstract classes, traits and classes that are not models', function () {
        $message = coverageFailure(fn () => coverage('good')->assertAllAuditable());

        // Only the one concrete model that is neither audited nor excepted.
        expect($message)->toContain(Good\Scratch::class)
            ->and(substr_count($message, 'does not use Auditable'))->toBe(1);
    });

    it('accepts an override that aliases the trait, and one that calls parent::save() of an audited parent', function () {
        coverage('good')->assertTransactional();

        expect(true)->toBeTrue();
    });
});

describe('assertAllAuditable', function () {
    it('lists every concrete model that is neither audited nor excepted', function () {
        $message = coverageFailure(fn () => coverage('bad')->assertAllAuditable());

        expect($message)->toContain('(1)')
            ->toContain(Bad\Plain::class.' does not use Auditable')
            ->toContain('except()')
            ->not->toContain(Bad\Unmapped::class);
    });

    it('is satisfied by an exception with a reason', function () {
        coverage('bad')->except([Bad\Plain::class => 'Not business data.'])->assertAllAuditable();

        expect(true)->toBeTrue();
    });

    it('refuses an exception without a reason', function () {
        expect(fn () => coverage('bad')->except([Bad\Plain::class => '']))->toThrow(InvalidArgumentException::class)
            ->and(fn () => coverage('bad')->except([Bad\Plain::class]))->toThrow(InvalidArgumentException::class);
    });

    it('flags an exception that is stale: audited after all, or not a model here', function () {
        $message = coverageFailure(fn () => coverage('bad')->except([
            Bad\Plain::class => 'Not business data.',
            Bad\Unmapped::class => 'Was not audited once.',
            'App\\Models\\Gone' => 'Deleted.',
        ])->assertAllAuditable());

        expect($message)->toContain('(2)')
            ->toContain(Bad\Unmapped::class.' is excepted (Was not audited once.) but uses Auditable')
            ->toContain('App\\Models\\Gone is excepted but is not a concrete model');
    });
});

describe('assertMorphMapped', function () {
    it('lists every audited model that has no alias, all at once', function () {
        $message = coverageFailure(fn () => coverage('bad')->assertMorphMapped());

        expect($message)->toContain('(1)')
            ->toContain(Bad\Unmapped::class.' has no morph-map alias')
            ->not->toContain(Bad\OverridesSave::class);
    });

    it('does not ask a model that is not audited for an alias', function () {
        $message = coverageFailure(fn () => coverage('bad')->assertMorphMapped());

        expect($message)->not->toContain(Bad\Plain::class);
    });

    it('lists several at once', function () {
        Relation::morphMap([], merge: false);
        Relation::morphMap(['user' => User::class]);

        $message = coverageFailure(fn () => coverage('bad')->assertMorphMapped());

        expect($message)->toContain('(4)')
            ->toContain(Bad\Unmapped::class)
            ->toContain(Bad\OverridesSave::class)
            ->toContain(Bad\OverridesDelete::class)
            ->toContain(Bad\Child::class);
    });
});

describe('assertTransactional', function () {
    it('flags a model that overrides save() or delete() without the alias, with the fix', function () {
        $message = coverageFailure(fn () => coverage('bad')->assertTransactional());

        expect($message)->toContain('(2)')
            ->toContain(Bad\OverridesSave::class.' overrides save() (OverridesSave.php:12)')
            ->toContain('use Auditable { save as auditableSave; }')
            ->toContain(Bad\OverridesDelete::class.' overrides delete() (OverridesDelete.php:12)')
            ->toContain('use Auditable { delete as auditableDelete; }');
    });

    it('reports the class that holds the override once, not each model that inherits it', function () {
        $message = coverageFailure(fn () => coverage('bad')->assertTransactional());

        expect(substr_count($message, Bad\OverridesSave::class.' overrides'))->toBe(1)
            ->and($message)->not->toContain(Bad\Child::class);
    });

    it('flags only the method that lacks its alias', function () {
        // OverridesDelete aliases save() but overrides delete() without aliasing it.
        $message = coverageFailure(fn () => coverage('bad')->assertTransactional());

        expect($message)->not->toContain(Bad\OverridesDelete::class.' overrides save()');
    });
});

it('refuses a directory that does not exist', function () {
    expect(fn () => AuditCoverage::models('/nowhere/at/all', 'App\\Models')->assertAllAuditable())->toThrow(InvalidArgumentException::class);
});

describe('a path or namespace that finds nothing', function () {
    it('throws instead of passing with no models, in every assertion', function (string $assertion) {
        $coverage = AuditCoverage::models(__DIR__.'/../Fixtures/Coverage/Good', 'App\\Models');

        expect(fn () => $coverage->{$assertion}())->toThrow(InvalidArgumentException::class, 'found no Eloquent model');
    })->with(['assertAllAuditable', 'assertMorphMapped', 'assertTransactional']);

    it('names the files that did not declare the class their path implies', function () {
        try {
            AuditCoverage::models(__DIR__.'/../Fixtures/Coverage/Good', 'Wrong\\Namespace')->assertAllAuditable();
            test()->fail('It should have thrown.');
        } catch (InvalidArgumentException $e) {
            expect($e->getMessage())->toContain('Invoice.php')->toContain('Aliased.php');
        }
    });
});

describe('a set with a file that does not resolve, and custom pivots', function () {
    beforeEach(function () {
        Relation::morphMap(['coverage-kept' => Kept::class], merge: true);
    });

    it('reports the file whose class the path and namespace do not give', function () {
        $message = coverageFailure(fn () => coverage('mixed')->assertAllAuditable());

        expect($message)->toContain('(1)')
            ->toContain('Mismatched.php does not declare Jiannius\Audit\Tests\Fixtures\Coverage\Mixed\Mismatched');
    });

    it('does not ask a custom pivot, or a morph pivot, for the trait', function () {
        $message = coverageFailure(fn () => coverage('mixed')->assertAllAuditable());

        expect($message)->not->toContain('Membership')->not->toContain('Tagging');
    });

    it('checks the models that do resolve', function () {
        coverage('mixed')->assertMorphMapped()->assertTransactional();

        expect(true)->toBeTrue();
    });
});
