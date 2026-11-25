<?php

use Illuminate\Support\Facades\Event;
use Jiannius\Audit\Facades\Auditor;
use Jiannius\Audit\Testing\InteractsWithAudits;
use PHPUnit\Framework\AssertionFailedError;

uses(InteractsWithAudits::class);

describe('assertAudited', function () {
    it('passes for an event with the old and new values given', function () {
        $document = makeDocument(['title' => 'Draft', 'total' => 100]);
        $document->update(['title' => 'Final', 'total' => 250]);

        $this->assertAudited($document, 'created');
        $this->assertAudited($document, 'updated');
        $this->assertAudited($document, 'updated', old: ['title' => 'Draft'], new: ['title' => 'Final']);
        $this->assertAudited($document, 'updated', new: ['total' => 250]);
    });

    it('compares only the keys given, and by meaning', function () {
        $document = makeDocument(['title' => 'Draft', 'total' => 100]);
        $document->update(['title' => 'Final', 'total' => 250]);

        // An integer and its numeric string are one value; the other changed keys need not be listed.
        $this->assertAudited($document, 'updated', old: ['total' => '100']);
        $this->assertAudited($document, 'updated', new: ['total' => 250.0]);
    });

    it('takes a boolean as the 1 or 0 the database holds', function () {
        $user = makeUser();
        Auditor::record('flagged', $user, old: ['active' => 0], new: ['active' => 1]);

        $this->assertAudited($user, 'flagged', old: ['active' => false], new: ['active' => true]);
    });

    it('compares arrays by key, so a pivot map or list is checked whole', function () {
        $user = makeUser();
        Auditor::record('moved', $user, old: ['teams' => [1, 2]], new: ['teams' => ['1' => ['role' => 'admin']]]);

        $this->assertAudited($user, 'moved', old: ['teams' => [1, 2]], new: ['teams' => [1 => ['role' => 'admin']]]);

        expect(fn () => $this->assertAudited($user, 'moved', old: ['teams' => [1]]))->toThrow(AssertionFailedError::class);
    });

    it('does not treat two different strings that look like numbers as equal', function () {
        $user = makeUser();
        Auditor::record('coded', $user, new: ['code' => '007']);

        expect(fn () => $this->assertAudited($user, 'coded', new: ['code' => '7']))->toThrow(AssertionFailedError::class);
    });

    it('fails with the events the model does have, when the event is missing', function () {
        $document = makeDocument();

        try {
            $this->assertAudited($document, 'updated');
            $this->fail('It should have failed.');
        } catch (AssertionFailedError $e) {
            expect($e->getMessage())->toContain('document ['.$document->getKey().'] has no [updated] audit row')
                ->toContain('Its rows are: created');
        }
    });

    it('fails with what was found, when the values differ', function () {
        $document = makeDocument(['title' => 'Draft']);
        $document->update(['title' => 'Final']);

        try {
            $this->assertAudited($document, 'updated', old: ['title' => 'Draft'], new: ['title' => 'Wrong']);
            $this->fail('It should have failed.');
        } catch (AssertionFailedError $e) {
            expect($e->getMessage())->toContain('none with the old and new values you gave')
                ->toContain('expected old: {"title":"Draft"}  new: {"title":"Wrong"}')
                ->toContain('found    old: {"title":"Draft"}  new: {"title":"Final"}');
        }
    });

    it('says so when there are no rows at all, and hints at Event::fake()', function () {
        Event::fake();

        $document = makeDocument();

        try {
            $this->assertAudited($document, 'created');
            $this->fail('It should have failed.');
        } catch (AssertionFailedError $e) {
            expect($e->getMessage())->toContain('no audit rows at all')->toContain('Event::fake()');
        }
    });

    it('finds the rows of the model and no other', function () {
        $one = makeDocument(['title' => 'One']);
        $two = makeDocument(['title' => 'Two']);
        $two->update(['title' => 'Two!']);

        $this->assertAudited($two, 'updated');
        $this->assertNotAudited($one, 'updated');
    });

    it('sees the rows of every tenant, whatever the read scope', function () {
        $this->useScopes(['team_id' => ['resolver' => fn () => 2, 'read' => fn () => 1]]);

        $document = makeDocument();

        $this->assertAudited($document, 'created');
    });
});

describe('assertNotAudited', function () {
    it('passes when there is no row for the event', function () {
        $document = makeDocument();

        $this->assertNotAudited($document, 'updated');
        $this->assertNotAudited($document, 'deleted');
    });

    it('fails when there is one, and counts them', function () {
        $document = makeDocument();
        $document->update(['title' => 'a']);
        $document->update(['title' => 'b']);

        try {
            $this->assertNotAudited($document, 'updated');
            $this->fail('It should have failed.');
        } catch (AssertionFailedError $e) {
            expect($e->getMessage())->toContain('has 2 [updated] audit rows, and should have none');
        }
    });
});
