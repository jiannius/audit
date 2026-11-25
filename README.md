# jiannius/audit

A small, generic audit trail for Laravel 13. It has no UI and no dependency beyond the Laravel framework itself.

> **Status: v1 is being built in phases.** This README is the API spec, written first. Sections marked **Spec** describe behaviour that is not in the code yet; the phase that delivers it is named. Sections without the mark match the code.
>
> | Phase | Tag | Delivers | State |
> |---|---|---|---|
> | P1 | `v1.0.0-alpha.1` | Table, `Audit` model, `Recorder`, actor, source and request id, queue propagation, scope columns, `Auditor::record`, `actingAs` and `withSource` | built |
> | P2 | `alpha.2` | The `Auditable` trait | built |
> | P3 | `alpha.3` | `Auditor::update`, `delete`, `pivot`, `updateQuietlyWithAudit`, `$model->audit()` | built |
> | P4 | `alpha.4` | Sign-in listeners | spec |
> | P5 | `v1.0.0` | Retention, purge, CSV export, test helpers | spec |

- [1. What it does and doesn't](#1-what-it-does-and-doesnt)
- [2. Install](#2-install)
- [3. Auditing a model](#3-auditing-a-model)
- [4. Writes that skip events](#4-writes-that-skip-events)
- [5. Custom events](#5-custom-events)
- [6. Sign-ins](#6-sign-ins)
- [7. Actor, source and queues](#7-actor-source-and-queues)
- [8. Multi-tenant](#8-multi-tenant)
- [9. Failure behaviour](#9-failure-behaviour)
- [10. Retention and purge](#10-retention-and-purge)
- [11. CSV export](#11-csv-export)
- [12. Testing your app](#12-testing-your-app)
- [13. Config reference](#13-config-reference)
- [14. Production checklist](#14-production-checklist)
- [15. Upgrading from the pre-1.0 package](#15-upgrading-from-the-pre-10-package)

---

## 1. What it does and doesn't

**It does**

- Record who changed what and when, with the old and new raw values, for any model that uses the `Auditable` trait.
- Record sign-ins, failed sign-ins, lockouts, sign-outs and password resets.
- Record custom events, such as `sent` or `voided`, and give you helpers for writes that skip model events: bulk updates and deletes, quiet updates, `DB::table` writes and pivot changes.
- Snapshot the actor (who), the record label and any host scope columns (for example a tenant) on every row, so a row stays readable after the user or the record is gone.
- Carry the actor and request id into queued jobs.
- Fail closed: the audit insert is synchronous, on the same connection and inside the same transaction as the change it records. A change without its row is not committed.
- Keep the usual secrets out of rows by default: passwords, tokens, API keys, and encrypted, hashed and hidden attributes are excluded or redacted. This is a safety net with sensible defaults, not a guarantee: see [section 3](#3-auditing-a-model) for what it does not cover.
- Work under `Model::shouldBeStrict()` and `Relation::enforceMorphMap()`, with ULID and bigint keys.
- Keep or prune rows by category, with overrides per record type and event.

**It doesn't**

- **Show anything.** There is no UI. Hosts build their own pages from the `Audit` model.
- **Decide who may see rows.** There is no policy. Anyone your code lets query the table sees it. The optional tenant read scope ([section 8](#8-multi-tenant)) is the only built-in limit.
- **Capture writes the application never sees:** raw SQL from a database client, vendor code that writes past model events, SQL typed into a REPL. Use the helpers in [section 4](#4-writes-that-skip-events), the raw-write test guard in [section 12](#12-testing-your-app), and optionally database-level controls ([section 14](#14-production-checklist)). Word any compliance claim to match what is captured.
- **Record views.** You can record them yourself as custom events.
- **Write rows asynchronously.** Audit writes are never queued.
- **Migrate an existing audit table,** or support Laravel before 13.

---

## 2. Install

```bash
composer require jiannius/audit
php artisan vendor:publish --tag=audit-config --tag=audit-migrations
```

While v1 is in its alpha tags, require a tag explicitly, for example `composer require jiannius/audit:^1.0@alpha`.

Publish the migration **once**. While a `create_audits_table` migration exists in `database/migrations`, publishing again leaves it alone instead of adding a second copy.

The package registers no global alias. Import the facade where you use it: `use Jiannius\Audit\Facades\Auditor;`.

Then:

1. **Add your scope columns** to the published migration, if you want any ([section 8](#8-multi-tenant)). The migration is yours: the package never auto-loads it, so it can never ALTER a large table without you.
2. **Run it:** `php artisan migrate`.
3. **Enforce a morph map** and add an alias for every model you audit and for your user model. Rows store the alias, not the class name, and an unmapped class fails the first save.

```php
use Illuminate\Database\Eloquent\Relations\Relation;

Relation::enforceMorphMap([
    'user' => App\Models\User::class,
    'invoice' => App\Models\Invoice::class,
]);
```

4. **Set the actor attributes** if your user model does not use `name` and `email` ([section 13](#13-config-reference)).

The table is append-only and has no foreign keys:

| Column | Notes |
|---|---|
| `id` | bigint, monotonic |
| `category` | `record`, `auth` or `action`; the retention group |
| `event` | `created`, `updated`, `deleted`, `restored`, `login`, `sent`, `voided` ... |
| `subject_type`, `subject_id`, `subject_label` | The record. The type is the morph alias, the id is always a string, the label is a snapshot |
| `parent_type`, `parent_id` | A roll-up, for example an invoice line's invoice |
| `actor_type`, `actor_id`, `actor_name`, `actor_identifier` | Who. Name and identifier are snapshots |
| your scope columns | One per key in `audit.scopes`, for example `team_id` |
| `source` | `web`, `console:<command>`, `queue:<job>`, `schedule:<task>`, `backfill:<name>` |
| `request_id`, `ip_address`, `user_agent` | HTTP only for the last two |
| `old_values`, `new_values`, `meta` | JSON. Raw database values |
| `created_at` | `datetime`, no `updated_at` |

**Database notes.** MySQL's `json` type does not keep key order, so `old_values` and `new_values` may read back with their keys sorted. Do not rely on key order. Ids are strings so one table holds ULID and bigint subjects, and the package always binds them as strings, because comparing a varchar column to an integer defeats the index on MySQL.

---

## 3. Auditing a model

```php
use Jiannius\Audit\Auditable;

class Invoice extends Model
{
    use Auditable;
}
```

Every model that uses `Auditable` needs a morph-map alias ([section 2](#2-install)). The trait calls `getMorphClass()` and lets it throw, so a missing alias fails the first save, and the save is rolled back.

**What each event stores**

| Event | `old_values` | `new_values` | `meta` |
|---|---|---|---|
| `created` | none | the raw attributes inserted | none |
| `updated` | the changed keys only | the changed keys only | none |
| `deleted` | the full raw snapshot | none | `soft`: `true` for a soft delete, `false` for a hard delete |
| `force_deleted` | the full raw snapshot | none | none |
| `restored` | the changed keys (`deleted_at` before) | the changed keys (`deleted_at` after) | none |

Any row whose old or new value was replaced by the truncation marker also has `meta._truncated`, the list of those fields ([section 9](#9-failure-behaviour)). The leading underscore keeps it clear of your own meta keys; the package owns `_truncated`.

- Values are **raw values**, read from the model's raw attributes and never through its casts: enums store their backing value, dates the database string, JSON columns the JSON string, integers stay integers, and PHP booleans are stored as `1` and `0`, as the database holds them. `old_values` are what the model instance held as loaded (its original attributes, `getPrevious()` after the save), not a fresh read of the database: see "Limits" below. `new_values` are what the model holds after the save, as it was assigned, so a few casts differ in shape from what you would read back: a `decimal` column assigned a float stays a float (and one read from the database is a string), and an integer column assigned `'5'` stays `'5'`. Compare values by meaning, not by type.
- A string value over `audit.max_value_bytes` is stored as the marker `"[truncated N bytes]"`, so a large attribute never blocks a save ([section 9](#9-failure-behaviour)).
- `created` stores the attributes the model held when it was inserted. A column the database fills in by default is not in it.
- `restore()` writes **one** `restored` row, not an extra `updated` one, and writes it inside the save, so it is as atomic as any other change. A model that was never trashed (built or created in memory, then restored) writes none. When the model was loaded without `deleted_at`, the row has no `old_values`, because what it was is unknown.
- `forceDelete()` writes one `force_deleted` row. It is recorded when `auditEvents()` lists `deleted` or `force_deleted`.
- `saveQuietly()`, `updateQuietly()` and `withoutEvents()` fire no model events, so they write no row. Use the helpers in [section 4](#4-writes-that-skip-events).
- `increment()`, `decrement()` and `incrementEach()` fire `updated`, so they are recorded.
- A save with no changes left after excludes writes **no row**: touch-only saves, remember-token cycling and excluded-only changes are skipped. A change from `null` to `null` (an attribute that was never loaded, now set to `null`) is not a change either.
- Every row has the category `record`.

**Limits: old values are the instance's, not the database's.** For a model save, `old_values` come from the model instance in memory. If the record was changed through another copy of the model or a raw query after this instance was loaded, the instance is stale and the row records a stale old value (the new value is still what was written). When accuracy against concurrent writers matters, re-fetch with `fresh()` or `refresh()` just before saving. The bulk helpers in [section 4](#4-writes-that-skip-events) do not have this limit: they read old values from the database under a lock.

**Overridable methods.** Each has a default, so you only write the ones you need:

```php
public function auditLabel(): ?string;            // the first filled key of config('audit.attributes.label')
public function auditParent(): Model|array|null;  // null; or [Invoice::class, $id], or a model
public function auditExclude(): array;            // []: never stored, never counts as a change (glob patterns)
public function auditRedact(): array;             // []: stored as "[redacted]" (glob patterns)
public function auditEvents(): array;             // ['created', 'updated', 'deleted', 'restored']
```

- **Label.** `subject_label` is a snapshot, so a row stays readable after the record is gone. It is read from the raw attributes.
- **Parent.** A child can roll up to its parent, so a timeline shows both:

  ```php
  class InvoiceLine extends Model
  {
      use Auditable;

      public function auditParent(): array
      {
          return [Invoice::class, $this->getAttributes()['invoice_id'] ?? null];
      }
  }
  ```

  The class must have a morph alias. With no id (`null` or empty) the row has no parent. You can also return a model you already hold.
- **Exclude and redact** are added to the lists in config (`attributes.exclude`, `attributes.redact`). Both take glob patterns: `'internal_*'`, `'*_token'`. The model's own `created_at` and `updated_at` columns are excluded under whatever names it gives them.
- **`auditEvents()`** narrows a noisy model, for example `['deleted']` for attachments. `deleted` also covers `force_deleted`.
- **Scope columns** are filled by your resolvers ([section 8](#8-multi-tenant)). They receive the `PendingAudit`, whose `subject` is the model, so a resolver can read it. Read it with `getAttributes()`, like your overrides.

**Secrets, on by default**

- `password`, `api_key`, `*_api_key`, `secret_key`, `private_key` and `token` are stored as `"[redacted]"`, so the change is still visible. There is deliberately no broad `*_key` pattern: it would also hide `foreign_key` and `sort_key`.
- `remember_token`, `*_token`, `*_secret` and `two_factor_recovery_codes` are excluded entirely: not stored, and a save that changes only those writes no row.
- Attributes in the model's `$hidden` (`attributes.redact_hidden`) stay redacted whatever the instance does: what a fresh instance of the class hides always counts (the `$hidden` property, `#[Hidden]` and a `getHidden()` override), so `makeVisible()` and `setHidden([])` do not un-redact them. `$visible` is a display whitelist, not a redaction source. Attributes cast `encrypted`, `encrypted:*`, `hashed` or `AsEncrypted*` (`attributes.redact_encrypted`) are redacted automatically too.
- A redacted attribute that is `null` stays `null`, so a row still shows that a value was set or cleared, never what it was.
- Excluding wins over redacting.
- **The record label follows the same rules.** `subject_label` skips any label attribute that is excluded, redacted, hidden or cast encrypted or hashed, so a label cannot leak what the values do not. If you override `auditLabel()`, keeping secrets out of it is yours to do.

**What this does not cover.** The rules work on attribute names and casts. A secret inside a JSON column (for example a `settings` array holding a token) is stored as it is, unless you redact the whole column with `auditRedact()`. A secret in an attribute with an unremarkable name, and one in an overridden `auditLabel()`, are not caught either. Review what your models store.

**Reading the trail**

```php
$invoice->audits;          // MorphMany: the rows where the invoice is the subject
$invoice->auditTrail();    // Builder: the invoice's rows plus the rows whose parent is the invoice
$invoice->auditTrail()->latest('id')->paginate();
```

`auditTrail()` is unordered, so add your own order. Both go through the `Audit` model, so a [read scope](#8-multi-tenant) applies. `audits()` always binds the id as a string, as the package's own scopes do.

**Strict mode.** The trait never reads a relation or a magic attribute. Your overrides must not either: read from `$this->getAttributes()` with `array_key_exists` or `??`, as in the `auditParent()` above. A model loaded with a partial `select()` then works under `shouldBeStrict()`. Do not write `$this->name`, `$this->invoice->number` or any other accessor in an override: under strict mode they throw when the attribute was not selected or the relation was not loaded.

**Saves are transactional.** With `audit.transactional_saves` on (the default), `save()` and `delete()` run inside `$this->getConnection()->transaction(...)`, so the change and its row commit or roll back together ([section 9](#9-failure-behaviour)). `restore()` and `forceDelete()` go through `save()` and `delete()`, so they are covered too. The transaction is the model's own connection, which is the connection the row is written on while `audit.connection` is `null`. If you set `audit.connection` to another connection, the row cannot join the change's transaction. Nothing is wrapped while `audit.enabled` is `false`.

**Models that override `save()`.** A method on the class beats the trait's, so the wrapper is silently skipped. Alias it instead, and call the alias where you would have called `parent::save()`:

```php
use Auditable { save as auditableSave; }

public function save(array $options = [])
{
    // your logic
    return $this->auditableSave($options);
}
```

The same goes for an overridden `delete()`: alias it as `auditableDelete`. The `AuditCoverage::assertTransactional()` test helper flags models that override either without the alias ([section 12](#12-testing-your-app)).

`increment()`, `decrement()` and `incrementEach()` are wrapped in the same transaction, so their row commits or rolls back with them. `incrementQuietly()` fires no events, so it writes no row.

**After a failed audit insert** the database has rolled back, and the package puts the model back as it was before the call: `exists`, `wasRecentlyCreated`, the attributes and originals, the trashed state and the force-deleting flag. A retry of `save()` then does the right thing instead of updating a row that does not exist.

**The wrapper is skipped when it cannot matter.** A model whose `auditEvents()` does not include any event the operation can fire (a `save()` on a model that records only `deleted`) is saved without a transaction.

**Host traits.** A trait of your own that defines `save()` or `delete()` collides with the one in `Auditable` (a fatal error at class load). Alias the package's methods in the `use` block, as above, and call them from yours.

**Tests.** Under `Event::fake()` (or `Model::withoutEvents()`) no model events fire, so no rows are written. Fake only the events you mean to, or use `Event::fakeExcept([...])`, in tests that assert on audit rows.

---

## 4. Writes that skip events

Bulk updates, `DB::table()` writes, `updateQuietly()` and pivot changes fire no model events, so they would leave no trail. Use these instead. Each runs the change and its audit rows in **one transaction**, so a failed audit insert rolls the change back ([section 9](#9-failure-behaviour)).

| You would write | Write this instead | Rows |
|---|---|---|
| `Order::where(...)->update([...])` | `Auditor::update(Order::where(...), [...])` | one per record that changed |
| `DB::table('invoices')->where(...)->update([...])` | `Auditor::update(DB::table('invoices')->where(...), [...], subjectType: 'invoice')` | one per record that changed |
| `Order::where(...)->delete()` | `Auditor::delete(Order::where(...))` | one per record deleted |
| `$invoice->updateQuietly([...])` | `$invoice->updateQuietlyWithAudit([...])` | one, or none if nothing changed |
| `$user->teams()->sync($ids)` | `Auditor::pivot($user->teams(), fn ($teams) => $teams->sync($ids))` | one on the parent, or none if the links are unchanged |

Call them as `Auditor::update(...)`, `Auditor::delete(...)` and `Auditor::pivot(...)` (the facade), so the raw-write test guard ([section 12](#12-testing-your-app)) can tell an audited write from a raw one.

### Bulk update

```php
use Illuminate\Support\Facades\DB;
use Jiannius\Audit\Facades\Auditor;

Auditor::update(
    EloquentBuilder|Relation|QueryBuilder $query,
    array $values,
    string $event = 'updated',
    array $meta = [],
    ?string $subjectType = null,
): int;   // the number of rows the update affected, as Laravel's update() returns
```

Before and after:

```php
// Before: no row at all.
Order::where('status', 'open')->update(['status' => 'cancelled']);

// After: one row per order that changed, each with its own old and new status.
Auditor::update(Order::where('status', 'open'), ['status' => 'cancelled'], event: 'cancelled', meta: ['reason' => 'season_end']);

// A relation: cascade a status to an invoice's lines in one statement.
Auditor::update($invoice->lines(), ['status' => 'cancelled'], meta: ['cascade' => 'invoice_cancelled']);

// An expression works too: each row records its own result.
Auditor::update(Invoice::where('team_id', $team->id), ['total' => DB::raw('total + 100')]);
```

Over two invoices, the last call writes these rows:

| subject | `old_values` | `new_values` |
|---|---|---|
| Invoice INV-1 | `{"total": 500}` | `{"total": 600}` |
| Invoice INV-2 | `{"total": 120}` | `{"total": 220}` |

**How it works.** In one transaction on the query's connection it selects the matching rows (`select *`, with `lockForUpdate`), runs your update, reads the same rows again by key, and compares the columns you wrote. So:

- **Old values are read from the database inside the transaction,** under the lock, so they are exact even if another writer changed the rows since you loaded them. This differs from a model save ([section 3](#3-auditing-a-model)), whose old values are the instance's in-memory originals. `Auditor::delete()` and `Auditor::pivot()` read their snapshots the same way.
- **Expressions work** (`DB::raw`, arithmetic on a column): the new value is read back, not computed.
- **Only changed records get a row.** A record the update left as it was, such as `status` set to what it already was, gets none. A call that changes nothing writes nothing.
- **Only the columns you wrote** are in `old_values` and `new_values`. A JSON path (`'settings->theme'`) is recorded as the whole `settings` column, and a table-qualified name (`'invoices.total'`) as `total`.
- **The model's rules apply.** Excluded attributes never appear (a call that changes only excluded columns writes no row) and redacted ones are stored as `"[redacted]"`. The label and parent come from the model's `auditLabel()` and `auditParent()`, which receive a model hydrated with every column, so they are safe under strict mode. The `updated_at` an Eloquent update adds is excluded as usual.
- **The model's `auditEvents()` is respected for standard events.** `Auditor::update(...)` with the default `updated` writes no row for a model that records only `deleted`. A custom event name (`voided`, `cancelled`) is always recorded: you asked for it.
- **Scopes apply.** An Eloquent builder or relation brings its global scopes, including soft deletes, so a trashed record is not updated or recorded.
- **The primary key cannot be updated** through it: the rows could not be matched before and after. It throws `InvalidArgumentException`.
- **A column qualified with the table or its alias** (`'invoices.total'`, `'i.total'`) is recorded as that column. A column qualified with anything else throws `InvalidArgumentException`: it could not be placed on the audited record, and skipping it would write a change with no row.
- **Bulk writes bypass casts and mutators.** The table gets exactly what you pass: a `hashed` cast or a setter does not run. The audit still applies the model's redaction to what it stores.
- **Locks and memory.** The select locks the matching rows, in primary key order, until the transaction commits: a little longer than a plain update. **The before-snapshot of every matched row is held in memory**, roughly 2 to 3 times the raw row size (about 1.5 KB for a 600-byte row), while the re-read, the comparison and the audit rows go in chunks of 1,000 keys. For a very large update, batch it yourself by key range (`whereBetween('id', [$from, $to])`, a few thousand rows at a time).
- **The audited set is the written set.**
  - **With a `limit()` or `offset()`** the database and the locked select could pick different rows (a different index, ties in an `orderBy`). So the keys come from the locked select, which honours your `orderBy`, `limit` and `offset`, with the primary key as a tie-break after your order. The write then runs as the same query without its order, limit and offset, narrowed to exactly those keys (`whereIn`, in chunks of 1,000), with the model's scopes and `updated_at` handling as usual. A limit with no `orderBy` is allowed, but which rows it takes is up to the database, as it is without this package; the rows audited are always the rows written.
  - **Without a limit or offset** the original query is written as it is, and the locked select is ordered by primary key so two audited writes lock rows in the same order.
  - If the write still affects more rows than were locked and snapshotted (a row that appeared in between under `READ COMMITTED`), it throws `RuntimeException` and rolls back: retry.
  - A `limit()` or `offset()` on a query with joins (a many-to-many or has-many-through relation) is refused up front with `InvalidArgumentException`, because the database ignores the limit on a joined write. Select the keys first and use `whereKey()`.

**A base query builder** (`DB::table()`) has no model, so it needs one:

```php
// Keep an existing raw table write exactly as it is, but audited.
Auditor::update(
    DB::table('invoices')->where('id', $invoice->id),
    ['voided_at' => now(), 'void_reason' => $reason],
    event: 'voided',
    meta: ['reason' => $reason],
    subjectType: 'invoice',
);
```

`subjectType` is a morph-map alias (`'invoice'`) or a model class. Leave it out and the model is inferred from the morph map by table name, when exactly one model uses the table. If none does, or several do, it throws `InvalidArgumentException` and asks for `subjectType`. That model supplies the rules, the label and the parent. For an Eloquent builder or relation, `subjectType` is ignored.

### Bulk delete

```php
Auditor::delete(
    EloquentBuilder|Relation|QueryBuilder $query,
    string $event = 'deleted',
    array $meta = [],
    ?string $subjectType = null,
): int;
```

```php
// Before: rows vanish with no trail.
Order::where('status', 'draft')->delete();

// After: every row is snapshotted first, then deleted.
Auditor::delete(Order::where('status', 'draft'));
```

Each row has `old_values` (the full raw row, filtered and redacted by the model's rules), no `new_values`, and `meta.soft`, in the shape the model's own `delete()` records. For a soft delete that is the row as the soft delete leaves it, so `deleted_at` holds the new timestamp, exactly as the observer's `deleted` row does: hosts see one shape whichever way a record was deleted. A hard delete records the row as it was.

- On an **Eloquent builder or relation of a soft-deleting model** it **soft deletes**, as Laravel's builder does, and `meta.soft` is `true`. Trashed rows are not matched unless the builder says `withTrashed()`; with it, already-trashed rows are soft deleted again (their `deleted_at` is rewritten, as Laravel's builder does) and a `deleted` row is recorded for them.
- Otherwise (a model that does not soft delete, or a base query builder, which knows nothing about soft deletes) it hard deletes and `meta.soft` is `false`.
- `event: 'force_deleted'` on a soft-deleting model deletes for good, trashed rows included, and records `force_deleted` with no `soft` key. It drops the soft-delete scope **and only that one**: the model's other global scopes (a tenant scope, say) still apply. Laravel's own `forceDelete()` on a builder drops every global scope; this is deliberately safer.

### `updateQuietlyWithAudit()`

```php
// Metadata written without firing the model's saving hooks.
$invoice->updateQuietlyWithAudit(['external_id' => $externalId], event: 'synced', meta: ['system' => 'ledger']);
// returns bool, like updateQuietly()
```

It fills the model, saves it without events, and records one row from `getPrevious()` and `getChanges()`, in one transaction (whatever `transactional_saves` says: no `saved` hook is involved). The row has the same old and new values as an `updated` row from the observer, with your `event` (default `updated`) and `meta`, category `record`. Like a model save, its old values are the instance's in-memory originals, so `refresh()` first if the record may have changed elsewhere ([section 3](#3-auditing-a-model), "Limits"). A call that changes nothing, or only excluded attributes, writes no row. If the insert fails the update rolls back and the model is left as a failed `save()` leaves it: your values assigned and dirty, the originals untouched, so a retry of `save()` works.

### Pivot changes

```php
Auditor::pivot(BelongsToMany $relation, Closure $write, string $event = 'updated', array $meta = [], ?string $field = null): mixed;
```

```php
// Before: a membership change leaves no trail.
$user->teams()->sync($teamIds);

// After: one row on the user, if the links changed.
Auditor::pivot($user->teams(), fn (BelongsToMany $teams) => $teams->sync($teamIds));
```

It reads the pivot rows before and after your closure, in one transaction, and returns what the closure returned. `attach`, `detach`, `sync`, `toggle`, `updateExistingPivot` or your own pivot queries all work. If the links are the same afterwards, **nothing is written**.

The row is recorded **on the relation's parent** (the user above), and the field is named after the relation (`teams`). That is the relation's method name when the package can tell it, else the related table's name; pass `field: 'teams'` to set it yourself. Its old and new value is the **whole set of links**, in one of two shapes:

| The relation | The field's old and new value | Example |
|---|---|---|
| has no pivot columns | a list of the related ids, sorted; an id linked twice appears twice | `{"teams": [1, 3]}` becomes `{"teams": [1, 2]}` |
| declares pivot columns with `withPivot()` | a map of related id to its columns, sorted by id; an id linked twice maps to a list of its column sets | `{"teams": {"1": {"role": "member"}}}` becomes `{"teams": {"1": {"role": "admin"}}}` |

- **Timestamps** (`withTimestamps()`) are left out, so touching `updated_at` alone is not a change.
- **Excluded** pivot columns (`*_token` and the like) are left out, and **redacted** ones read `"[redacted]"`. A change to a redacted column is still seen as a change.
- The parent's `auditEvents()` is checked for the default `updated` event, and the row uses the parent's `auditLabel()` and `auditParent()`.
- **Duplicate links** (a pivot table with no unique key, linked twice to the same record) are counted: `[1, 1, 2]`, and in the map form `{"1": [{"role": "admin"}, {"role": "member"}]}`, the column sets in JSON order. Adding a duplicate is a change and writes a row.
- The existing pivot rows are read with `lockForUpdate`, so a concurrent change to a link that exists waits for yours. A link that does not exist yet has nothing to lock (the database may or may not take a gap lock), so two concurrent attaches of new links are not serialised.
- The whole set is stored on both sides, so a relation with thousands of links gives large rows. For those, record the change as a custom event instead.

### Rules for all of them

- **Atomic.** If an audit insert fails, the change is rolled back and `AuditWriteFailed` is thrown. Inside your own transaction they run as a savepoint, so the change and its rows commit or roll back with yours.
- **Turned off.** While `audit.enabled` is `false` they run the change and write no rows.
- **The audit connection.** The transaction is on the query's connection. If you point `audit.connection` at another connection, a row cannot join it ([section 9](#9-failure-behaviour)).
- **Actor and source** come from the context, like every other row ([section 7](#7-actor-source-and-queues)).

---

## 5. Custom events

```php
use Jiannius\Audit\Category;
use Jiannius\Audit\Facades\Auditor;

Auditor::record(
    string $event,
    ?Model $subject = null,
    array $meta = [],
    array $old = [],
    array $new = [],
    string $category = Category::ACTION,
    ?Model $parent = null,
): void;

Auditor::record('exported', meta: ['rows' => 1200]);
Auditor::record('sent', $invoice, meta: ['to' => $recipients]);
```

On a model that uses `Auditable`, `audit()` does the same with the model as the subject and its own `auditParent()` as the parent:

```php
$invoice->audit(string $event, array $meta = [], array $old = [], array $new = []): void;

$invoice->audit('sent', meta: ['to' => $recipients]);
$user->audit('plan_changed', old: ['plan' => 'free'], new: ['plan' => 'pro']);
```

- The category is `action`. Old and new values are optional, for an event that has a before and after but is not a column change.
- **`$model->audit()` applies the model's rules to `old` and `new`:** excluded attributes are dropped and redacted ones stored as `"[redacted]"`. `Auditor::record()` stores what you pass, so keep secrets out of it yourself.
- `$model->audit('updated')` and the other standard event names follow the model's `auditEvents()`, like the helpers in [section 4](#4-writes-that-skip-events). Any other name is always recorded.

Name events after the domain action (`sent`, `voided`, `approved`), not the mechanism. A sync that changes fields is one row, the field change with `event: 'synced'`, not a change row plus an event row.

`record()` and `audit()` write in the caller's transaction, if any. They throw if the insert fails; work you did earlier outside a transaction stands. See [section 9](#9-failure-behaviour).

---

## 6. Sign-ins

> **Spec: lands in P4.**

Listeners are registered for the events in `audit.auth.events`. All rows are category `auth`.

| Laravel event | `event` | Subject | Actor | Meta | If the insert fails |
|---|---|---|---|---|---|
| `Login` | `login` | user | user | `guard`, `via_remember`, `remember` | the login is removed from the session and the recaller cookie is unqueued, then the error is rethrown |
| `Failed` | `login_failed` | matched user or null | none | `guard`, `identifier_key`, `identifier` | throws (the sign-in fails) |
| `Lockout` | `lockout` | none | none | `identifier_key`, `identifier` | throws |
| `Logout` | `logout` | user | user | `guard` | reported, never thrown |
| `CurrentDeviceLogout` | `logout_current_device` | user | user | `guard` | reported |
| `OtherDeviceLogout` | `logout_other_devices` | user | user | `guard` | reported |
| `PasswordReset` | `password_reset` | user | user | none | throws |

**Identifier keys.** `audit.auth.identifier` is an ordered list, default `['email', 'username']`.

- `Failed` records the first listed key present in the credentials, and its value. Nothing else is ever read from the credentials, because they carry the plaintext password.
- `Lockout` carries only the request, so the first listed key present in the request input is recorded. A form that keeps its identifier elsewhere, such as a Livewire form, must put it into the input before firing `Lockout`:

```php
request()->merge(['email' => $this->email]);

event(new Lockout(request()));
```

- The value is trimmed and truncated, and otherwise stored as typed.

**Rules**

- `via_remember` comes from the guard's `viaRemember()`, so a programmatic `Auth::login()` is not recorded as a password sign-in.
- `Login` fires after the session is written, so if its row cannot be written the login is undone, not just reported.
- Sign-out fails open: a broken audit never stops someone signing out.

---

## 7. Actor, source and queues

Every row records who acted, where from, and in which request.

**Actor.** The first match wins:

1. An `Auditor::actingAs($user, fn () => ...)` override. `actingAs(null, ...)` means nobody.
2. Inside a queued job picked up by a worker: the origin carried from the process that dispatched it (below), even when that origin is nobody. The guard is **not** consulted inside a job, with or without a carried origin, because a long-running worker keeps its guards between jobs, so a user an earlier job signed in would otherwise be credited with this job's work. **Calling `Auth::login()` or `Auth::setUser()` inside a job does not change who the rows are attributed to; use `Auditor::actingAs()`.** A job pushed from outside Laravel, or queued before you installed the package, has no origin and is recorded as nobody (or the Context-key fallback below). Work that runs without queue events, such as `dispatchSync()` of a job that does not implement `ShouldQueue`, is not a queue frame and uses the guard as usual.
3. The user signed in on the default guard. If the guard has not resolved its user yet, but the session already holds its login id, that user is looked up from the id. The package never calls `Auth::user()` and never reads the remember-me cookie, so recording a row cannot trigger a remember-cookie login, a `Login` event or any other side effect. A user known only from a remember cookie is recorded as nobody until your own code resolves them.
4. A fallback: the user whose id is in the Context key named by `audit.actor.context_key`.
5. Nobody, shown as "System".

**Actor snapshot.** `actor_name` and `actor_identifier` are read from the attributes named by `audit.actor.name` (default `name`) and `audit.actor.identifier` (default `email`; for example `username` or `phone`). They are read with `array_key_exists` on the model's loaded attributes, so the snapshot runs no query and is safe under strict mode and partial selects. An attribute that was not loaded gives `null`. The snapshot survives the user being deleted.

```php
// config/audit.php
'actor' => ['name' => 'name', 'identifier' => 'username'],
```

**Source.**

| Source | When |
|---|---|
| `web` | an HTTP request |
| `console:<command>` | an Artisan command, for example `console:app:import` |
| `queue:<job>` | inside a queued job, whatever the driver |
| `schedule:<task>` | work that runs inside the scheduler process: a closure (name it with `->name('...')`) or a job that is not queued, named after its class. Never a command line |
| `console:<command>` | a scheduled artisan command, which runs as its own process, so it shows as `console:<command>` |
| `queue:<job>` | a scheduled job that is queued runs on a worker, so it shows as `queue:<job>` |
| `backfill:<name>` | whatever you set with `withSource` |

```php
Auditor::withSource('backfill:fix-totals', function () {
    // every row written here has source "backfill:fix-totals"
});

Auditor::actingAs($admin, fn () => $this->import($file));
```

Both return the callback's result and restore the previous value even when it throws. An explicit `withSource` beats the automatic source.

**Request id.** Taken from the Context key in `audit.request_id.context_key` when your app already sets one; otherwise a ULID per request, per console command or scheduler run, and per job that carries no origin. It travels into jobs: the jobs a process dispatches share that process's request id, so a job's rows correlate with the request, command or scheduler run that dispatched it.

**IP and user agent.** Recorded for HTTP only. The IP is `request()->ip()`, so it is correct only if you configure trusted proxies ([section 14](#14-production-checklist)).

**Queued jobs.** On dispatch the package adds a hidden `audit.origin` entry to Laravel's Context: the actor's type, id, name and identifier, and the request id. Hidden Context travels in the job payload and never reaches log entries. The worker restores it, so a job's rows show:

- the user who triggered the job, although nobody is signed in on the worker, even if that user has since been deleted;
- source `queue:<job display name>`;
- the same request id as the request that dispatched it.

A job dispatched from a job, and each job in a chain, passes the same origin on. A job dispatched from a console command or the scheduler carries that process's request id and the signed-in actor if there is one, which is usually nobody, shown as "System". It works on every queue driver, because it relies only on Context serialisation; `sync` included. A job pushed from outside Laravel carries no origin, so it gets its own request id and no actor.

Dispatching never fails because of the audit. If the signed-in user cannot be snapshotted (for example their class is missing from an enforced morph map), the job is dispatched with no actor, while an audit write by that user still fails loudly.

`actingAs` wraps the dispatch itself. `Job::dispatch()` returns a pending dispatch that only pushes when it is destroyed, which can be after your callback has returned. Inside `actingAs`, dispatch immediately: `Bus::dispatch(new Job)` or `dispatch_sync`.

The origin holds the actor's name and identifier, so those appear in the jobs table payload for as long as the job sits there.

**Writes inside a job behave as anywhere else.** Rows are inserted synchronously. If an insert fails, the change rolls back, the job fails and retries per its own settings. A retry that changes nothing writes no row.

The package does not carry the tenant into a worker. If your scope resolvers need one, restore it in the worker yourself ([section 8](#8-multi-tenant)).

---

## 8. Multi-tenant

The package knows no tenant or organisation column. You add any number of columns of your own to the published migration and give each a resolver. Every row is stamped on write.

**Declare a scope column**

```php
// config/audit.php
'scopes' => [
    // Short form: a resolver only. The column is stamped on write; reads are not restricted.
    'region_id' => App\Audit\RegionScope::class,

    // Long form: also isolate reads (opt-in).
    'team_id' => [
        'resolver' => App\Audit\TeamScope::class,    // stamps the column on write
        'read' => App\Audit\CurrentTeam::class,      // limits reads to the current value
    ],
],
```

```php
use Jiannius\Audit\PendingAudit;

class TeamScope
{
    // Receives the pending row: its event, subject, parent, actor and meta.
    public function __invoke(PendingAudit $audit): string|int|null
    {
        return $audit->subject?->getAttributes()['team_id'] ?? auth()->user()?->team_id;
    }
}

class CurrentTeam
{
    public function __invoke(): string|int|null
    {
        return auth()->user()?->team_id;
    }
}
```

Resolvers are class names (so config stays cacheable) and are resolved from the container. A resolver that returns `null` stores `null`.

**One shared database with a tenant column**

- **Writes:** the column is stamped on every row.
- **Reads, by default:** nothing is restricted. `Audit::query()` returns every tenant's rows, which is right when the column is a filter rather than a boundary.
- **Opt-in isolation:** give the scope a `read` resolver. The `Audit` model then adds a global scope named `audit.scope.<column>` that applies `where <column> = <current value>`. It applies to `Audit::query()` and to everything built on it: `audits()`, `auditTrail()` and the CSV export.
- **Fail closed:** when the read resolver returns `null`, the scope returns **no rows**.
- **Cross-tenant screens** opt out by name: `Audit::withoutGlobalScope('audit.scope.team_id')`.
- **A row keeps the tenant it was written with.** If a record later moves to another tenant, its old rows stay where they were. Who may see moved history is your rule; the package never rewrites rows, because rows are immutable.
- **Indexes** on scope columns are yours to add in the published migration, for example `index(['team_id', 'id'])`.

**What "immutable" covers.** An `Audit` instance cannot be updated or deleted: `save()`, `update()` and `delete()` throw. These still reach the table, because they skip the model events or never go through an instance: `saveQuietly()`, `Model::withoutEvents()`, `Audit::create()` (rows are meant to be inserted by the package only), and mass `Audit::query()->update()` or `->delete()`. Keep them out of host code. The raw-write test guard ([section 12](#12-testing-your-app), P5) flags them, and database-level grants stop them for certain ([section 14](#14-production-checklist)).

**Connections.** With `audit.connection = null` (the default), rows are written on the **subject's** connection, but the `Audit` model **reads** on the default connection. That fits database-per-tenant hosts, where the default connection is switched to the tenant's. A host that uses several connections in other ways should set `audit.connection` explicitly, so writes and reads use the same one.

**One database per tenant**

- **Writes:** with `audit.connection = null`, rows are written on the subject's connection. A tenant's changes land in that tenant's `audits` table and roll back with its transaction.
- **Migration:** publish it into your tenant migrations, not the central ones.
- **Central users:** with no foreign keys and actor snapshots, a user stored centrally but acting in a tenant database is recorded correctly.
- **Gaps:**
  - Sign-in rows with no subject (a failed sign-in with no matched user, and lockouts) use the default connection. They reach the tenant database only if tenancy is initialised before the auth routes; otherwise they land centrally.
  - `audit:prune`, `audit:purge` and the CSV export act on one connection, so run them once per tenant, for example through your tenancy package's "run for every tenant" command.
  - The package does not carry the tenant into queued jobs. Your tenancy package must restore it in the worker.
- **Supported by design, not covered by the package's test suite**, because the package has no tenancy dependency.

---

## 9. Failure behaviour

The audit write is **fail closed**: it is synchronous, never queued, and on the same connection as the change, so it joins the same transaction.

| Write path | Change and row atomic? | When the audit insert fails |
|---|---|---|
| `Auditable` `save()`, `delete()`, `restore()`, `increment()`, `decrement()` with `transactional_saves` on (default) | yes | the change rolls back and the exception is raised |
| Same, with `transactional_saves` off | only inside your transaction | exception; without your transaction the change stands |
| `Auditable` model that overrides `save()` without the alias | only inside your transaction | exception after commit (a gap; `assertTransactional` catches it) |
| `Auditor::update`, `delete`, `pivot`; `updateQuietlyWithAudit` | yes (own transaction) | rolled back |
| `Auditor::record`, `$model->audit()` | only inside your transaction | exception; earlier work outside a transaction stands |
| Sign-ins | [section 6](#6-sign-ins) | [section 6](#6-sign-ins) |
| `audit:prune`, `audit:purge` | no; chunked deletes, then one summary row | the command fails; deleted rows stay deleted |

**Consequences**

- A missing `audits` table blocks saves on audited models. Deploy the migration before, or with, the code that adds `Auditable`.
- An exception in a `saved` hook now rolls the save back when `transactional_saves` is on. Run your full suite when you adopt it; the config switch is the off ramp.

**What the exception holds.** A failed insert throws `Jiannius\Audit\Exceptions\AuditWriteFailed`. It carries the table name, the SQLSTATE and the driver's error code, and nothing else: the database exception's own message holds the full SQL with every bound value (labels, emails, IPs), so it is not chained as `previous`. It still propagates, so a surrounding transaction rolls back, and an error Laravel treats as retryable (a deadlock, a MySQL lock wait timeout, a busy SQLite file) stays retryable for `DB::transaction($callback, $attempts)`: the exception then also carries a canned phrase Laravel's concurrency detector recognises, never a value. An invalid category throws `InvalidArgumentException` before any insert.

**Sanitising.** An awkward value must never block a legitimate save:

- non-UTF-8 strings are stored as `"[binary N bytes]"`;
- a string in `old_values`, `new_values` or `meta` over `audit.max_value_bytes` (default 65,536 bytes) is stored as the marker `"[truncated N bytes]"`, with its size and none of its content. A row whose old or new values hold such a marker also gets `meta._truncated`, the list of those fields, so an update whose old and new values read the same is explained. No hash of the content is stored;
- values that cannot be JSON-encoded (for example nesting beyond 512 levels) are stored as the marker `{"_audit": "[values could not be encoded]"}`, never as null;
- NaN and infinite floats, resources and unknown objects are stored as short placeholders; enums store their backing value and dates their database string;
- labels, source and identifiers are truncated to their column sizes, by character;
- the user agent is stored as text, truncated to MySQL's 65,535 bytes without splitting a character.

**The package never logs audit values**, and the `AuditWriteFailed` message and its `previous` chain hold none. A stack trace is a separate matter: with `zend.exception_ignore_args=Off`, which is the development default, a trace records the arguments of every frame, including the values you passed to `Auditor::record()` and the `PendingAudit` the recorder handles, and an error tracker that serialises traces will receive them. Set `zend.exception_ignore_args=On` in production (it is the default in `php.ini-production`). The package avoids adding the database exception to the trace, but cannot remove your own call's arguments from it.

**Id gaps.** An auto-increment `id` can skip numbers when a transaction rolls back after the row was inserted. A gap is not a deleted row.

**Turning auditing off.** `audit.enabled` (`AUDIT_ENABLED`) skips all writes. Use it only while seeding or importing, never in production.

---

## 10. Retention and purge

> **Spec: lands in P5.**

Retention is your config, and the shipped defaults keep everything, so installing the package never deletes data.

```php
'retention' => [
    'record' => '7 years',    // null keeps forever
    'action' => null,
    'auth' => '2 years',
    'overrides' => [
        // The first match wins. Each needs at least one of subject_type, event, category.
        ['subject_type' => 'attachment', 'event' => 'created', 'keep' => '90 days'],
        ['category' => 'auth', 'event' => 'login_failed', 'keep' => '1 year'],
    ],
],
```

- An override applies to the rows it matches; the first override in list order wins.
- A category default applies only to rows in that category that no override matches. So an override can keep rows **longer** than their category as well as shorter.
- `keep: null` keeps forever.

**`audit:prune`** is the only prune path and is scheduled for you. The package registers it with the scheduler, so you only need `php artisan schedule:run` in cron. The `Audit` model does not use `Prunable` or `MassPrunable`, and `model:prune` never touches audit rows.

```php
'prune' => [
    'schedule' => 'daily',   // a frequency method with no arguments, or a cron expression; null disables
    'chunk' => 1000,
],
```

- It deletes in chunks with query-builder deletes, so the model's immutability guard is never involved.
- **Every run writes one `audits_pruned` row**, even when nothing was deleted: category `action`, event `audits_pruned`, meta `command: 'prune'` and each rule's `{category | override, cutoff, deleted}`. The daily row is your scheduler heartbeat: if it stops appearing, `schedule:run` is not running.
- `php artisan audit:prune --pretend` prints the counts and writes nothing.

**`audit:purge`** is the targeted, manual clean-up:

```bash
php artisan audit:purge --before=2026-01-01 --type=attachment --event=created [--category=record] [--pretend] [--force] [--below-retention]
```

- `--before` is required, and so is at least one of `--type`, `--event` or `--category`.
- **`--force`** only confirms a run outside the `local` environment. Without it, a non-local run refuses.
- **`--below-retention`** is separate and always needed to cut below your configured retention. `--force` alone never allows it. The configured retention is the longest retention of any rule whose rows the filter could match (`null` means forever); if `--before` is later than that cutoff, the command refuses without `--below-retention`.
- `--pretend` deletes nothing and writes no row.
- Otherwise it deletes in chunks and writes one `audits_pruned` row: meta `command: 'purge'`, the filters, `before`, `deleted`, `below_retention` and `forced`.

**Indexes.** Category rules use `(category, created_at)`. Override rules use `(event, id)` or the subject index. A very large table with type-and-event overrides may want an extra `(subject_type, event, created_at)` index in your published migration.

---

## 11. CSV export

> **Spec: lands in P5.**

A streamed, CSV-injection-safe export builder. You supply the query, so your own filters and visibility rules apply, and a formatter that turns raw values into display values.

```php
use Jiannius\Audit\Audit;
use Jiannius\Audit\Export\CsvExport;

CsvExport::make(Audit::query()->between($from, $to))
    ->formatter(app(FormatsAuditValues::class))
    ->timezone($request->user()->timezone)
    ->scopeColumns(['team_id' => 'Team'])
    ->headings(['actor_identifier' => 'User email'])
    ->download('audit-trail-2026-11.csv');         // StreamedResponse; ->write($handle): int for commands and tests
```

```php
interface FormatsAuditValues
{
    public function recordType(string $subjectType): string;                    // 'invoice-line' => 'Invoice line'
    public function field(string $subjectType, string $field): string;          // 'total' => 'Total'
    public function value(string $subjectType, string $field, mixed $raw): string|int|float|bool|null;  // 15050 => 150.5
}
```

Set your implementation in `audit.formatter`. The default `RawFormatter` uses headline field names, scalars as they are, arrays as JSON and `null` as blank.

- **One row per field.** An update gives one row per changed field; a create or delete one row per stored field; an event with no values one row with blank field columns. The Audit ID column regroups rows into entries.
- **Columns:** Audit ID, Time (in the given timezone), Category, Event, Record type, Record ID, Record, Parent type, Parent ID, Field, Old value, New value, User ID, User name, User identifier, Source, IP address, User agent, Request ID, your scope columns, Details (meta as JSON).
- Streams through `lazyById(1000)`, so it never loads the whole result.
- Starts with a UTF-8 BOM, so Excel reads the encoding correctly.
- **CSV-injection safety:** a string cell that starts with `=`, `+`, `-`, `@`, a tab or a carriage return gets a leading `'`. Have your formatter return numbers as numbers, so negative amounts are not prefixed.
- An export is a sensitive file. You control who may run it.

---

## 12. Testing your app

> **Spec: lands in P5.** All three helpers are optional.

```php
use Jiannius\Audit\Testing\AuditCoverage;
use Jiannius\Audit\Testing\InteractsWithAudits;
use Jiannius\Audit\Testing\RawWriteGuard;

// In a feature test (InteractsWithAudits)
$this->assertAudited($invoice, 'updated', old: ['status' => 'draft'], new: ['status' => 'sent']);
$this->assertNotAudited($invoice, 'updated');

// Fails on every event-bypassing write that is not in the allowlist, and on allowlist entries that no longer match.
RawWriteGuard::for(base_path())
    ->paths(['app', 'resources/views', 'routes'])
    ->allow(require base_path('tests/Fixtures/audit/raw-writes.php'))   // [['file', 'contains', 'reason'], ...]
    ->assertClean();

// Optional coverage check
AuditCoverage::models(app_path('Models'), 'App\\Models')
    ->except(require base_path('tests/Fixtures/audit/unaudited-models.php'))   // [Model::class => 'reason']
    ->assertAllAuditable()      // every concrete model uses Auditable or is excepted with a reason
    ->assertMorphMapped()       // every Auditable model has a morph alias, all missing ones listed at once
    ->assertTransactional();    // flags Auditable models that override save() or delete()
```

**How the guard works.** It tokenises your source with `token_get_all` (no extra dependency; Blade single-file components tokenise fine), splits it into statements and flags a statement that matches any of:

- `*Quietly(` and `withoutEvents(`;
- `DB::(insert|update|delete|statement|unprepared|affectingStatement)(`;
- `DB::table(...)` followed by a write (`insert*`, `update*`, `upsert`, `delete`, `truncate`, `increment*`, `decrement*`);
- `->toBase()->(update|delete|insert)`;
- pivot writes: `sync`, `syncWithoutDetaching`, `syncWithPivotValues`, `attach`, `detach`, `toggle`, `updateExistingPivot`;
- a method chain ending in `update`, `delete`, `forceDelete`, `insert`, `insertOrIgnore`, `upsert`, `increment` or `decrement`;
- any write through the `Audit` model.

It skips statements that go through `Auditor::`. It is **biased towards false positives**: one allowlist line with a reason costs little. It cannot see dynamic calls such as `$query->{$method}()`, or a helper that hides a write.

**In your own tests,** the package's behaviour is easy to assert on directly:

```php
expect(Jiannius\Audit\Audit::forSubject($invoice)->pluck('event')->all())->toBe(['created', 'sent']);
```

Run your suite under `Model::shouldBeStrict()` with an enforced morph map: that is how the package's own tests run.

---

## 13. Config reference

Publish it with `php artisan vendor:publish --tag=audit-config`. Config is usually cached in production, so changes take effect only after `php artisan config:cache`.

**Partial configs are fine.** The package merges its defaults under your published file recursively, so a published `actor`, `attributes`, `auth` or `prune` array that lists only some keys keeps the defaults for the rest. **Lists are replaced, not merged**: if you set `attributes.exclude` or `attributes.label`, you get exactly your list, not yours plus the defaults. An empty list disables the default.

| Key | Default | Meaning |
|---|---|---|
| `enabled` | `env('AUDIT_ENABLED', true)` | Off skips every write. Only while seeding or importing, never in production |
| `table` | `'audits'` | The table name. Read by the migration, the model and the recorder |
| `connection` | `null` | `null` writes on the subject's connection (and shares its transaction) while the `Audit` model reads on the default connection, which fits database-per-tenant. A name forces one connection for both. Set it explicitly if you use several connections otherwise |
| `transactional_saves` | `true` | Wrap `Auditable` `save()` and `delete()` in a transaction with their row. Off for hosts that cannot accept saved-hook rollbacks |
| `model` | `Jiannius\Audit\Audit::class` | Your own subclass, for example to add scopes. Rows are always inserted by the `Recorder` |
| `max_value_bytes` | `65536` | A string in old/new/meta over this many bytes is stored as `"[truncated N bytes]"`. `0` turns the cap off |
| `attributes.exclude` | `created_at`, `updated_at`, `remember_token`, `*_token`, `*_secret`, `two_factor_recovery_codes` | Never stored, never count as a change. Glob patterns. The model's own timestamp columns are added automatically |
| `attributes.redact` | `password`, `api_key`, `*_api_key`, `secret_key`, `private_key`, `token` | Stored as `"[redacted]"`. Glob patterns |
| `attributes.redact_hidden` | `true` | Redact attributes in the model's `$hidden` |
| `attributes.redact_encrypted` | `true` | Redact `encrypted*`, `hashed` and `AsEncrypted*` casts |
| `attributes.label` | `name`, `title`, `number`, `code`, `email` | The first filled attribute is the `subject_label` snapshot |
| `actor.name` | `'name'` | Attribute snapshotted as `actor_name` |
| `actor.identifier` | `'email'` | Attribute snapshotted as `actor_identifier`, for example `username` or `phone` |
| `actor.context_key` | `null` | Fallback: a Context key holding the actor's id |
| `request_id.context_key` | `null` | Reuse a request id your app already puts in Context |
| `scopes` | `[]` | Scope columns: `column => Resolver::class` or `column => ['resolver' => ..., 'read' => ...]` ([section 8](#8-multi-tenant)) |
| `auth.events` | all seven sign-in events | Which sign-in events are recorded (P4) |
| `auth.identifier` | `['email', 'username']` | The first key present is recorded; nothing else is read (P4) |
| `retention.record`, `.action`, `.auth` | `null` | Keep period such as `'7 years'`; `null` keeps forever (P5) |
| `retention.overrides` | `[]` | First match wins; each has `keep` and at least one of `subject_type`, `event`, `category` (P5) |
| `prune.schedule` | `'daily'` | A frequency method or cron expression; `null` disables the schedule (P5) |
| `prune.chunk` | `1000` | Rows per delete (P5) |
| `formatter` | `Jiannius\Audit\Export\RawFormatter::class` | The `FormatsAuditValues` used by the CSV export (P5) |

---

## 14. Production checklist

- [ ] **`schedule:run` is in cron.** Otherwise retention never runs. The daily `audits_pruned` row is the heartbeat.
- [ ] **Trusted proxies are configured.** Otherwise `ip_address` records your proxy.
- [ ] **Queue workers are running** for any queued work that writes audited changes.
- [ ] **`php artisan config:cache`** after any audit config or env change.
- [ ] **The migration is deployed before, or with,** the code that adds `Auditable`. A missing table blocks saves.
- [ ] **Database-per-tenant:** the migration is in the tenant migrations ([section 8](#8-multi-tenant)).
- [ ] **A morph map is enforced** and every audited model and user model has an alias.
- [ ] **Optional database-level immutability.** For the strongest guarantee, give the application's database user no `UPDATE` or `DELETE` on the `audits` table, and run `audit:prune` and `audit:purge` on a privileged connection. The model blocks updates and deletes through an instance; `saveQuietly()`, `withoutEvents()`, `Audit::create()` and mass `Audit::query()->update()` or `->delete()` get past it ([section 8](#8-multi-tenant)). Only the database can stop those, and a database client.

---

## 15. Upgrading from the pre-1.0 package

v1 is a breaking rewrite: a new table, a new API and no upgrade path. The pre-1.0 package was a single trait that wrote into your own `App\Models\Audit`; v1 shares none of its code or schema.

A project that needs the old code can pin the last pre-rewrite commit:

```json
"require": { "jiannius/audit": "dev-main#2cc7a1a" }
```

---

## License

MIT. See [LICENSE.md](LICENSE.md).
