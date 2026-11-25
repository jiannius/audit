# jiannius/audit

A small, generic audit trail for Laravel 13. It has no UI and no dependency beyond the Laravel framework itself.

> **Status: v1 is being built in phases.** This README is the API spec, written first. Sections marked **Spec** describe behaviour that is not in the code yet; the phase that delivers it is named. Sections without the mark match the code.
>
> | Phase | Tag | Delivers |
> |---|---|---|
> | P1 | `v1.0.0-alpha.1` | Table, `Audit` model, `Recorder`, actor, source and request id, queue propagation, scope columns, `Auditor::record`, `actingAs` and `withSource` |
> | P2 | `alpha.2` | The `Auditable` trait |
> | P3 | `alpha.3` | `Auditor::update`, `delete`, `pivot`, `updateQuietlyWithAudit`, `$model->audit()` |
> | P4 | `alpha.4` | Sign-in listeners |
> | P5 | `v1.0.0` | Retention, purge, CSV export, test helpers |

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

- Record who changed what and when, with the old and new raw database values, for any model that uses the `Auditable` trait.
- Record sign-ins, failed sign-ins, lockouts, sign-outs and password resets.
- Record custom events, such as `sent` or `voided`, and give you helpers for writes that skip model events: bulk updates and deletes, quiet updates, `DB::table` writes and pivot changes.
- Snapshot the actor (who), the record label and any host scope columns (for example a tenant) on every row, so a row stays readable after the user or the record is gone.
- Carry the actor and request id into queued jobs.
- Fail closed: the audit insert is synchronous, on the same connection and inside the same transaction as the change it records. A change without its row is not committed.
- Never store secrets: passwords, tokens, encrypted and hidden attributes are excluded or redacted by default.
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

- Values are **raw database values**, read from the model's raw attributes and never through its casts: enums store their backing value, dates the database string, JSON columns the JSON string, integers stay integers.
- A string value over `audit.max_value_bytes` is stored as the marker `"[truncated N bytes]"`, so a large attribute never blocks a save ([section 9](#9-failure-behaviour)).
- `created` stores the attributes the model held when it was inserted. A column the database fills in by default is not in it.
- `restore()` writes **one** `restored` row, not an extra `updated` one, and writes it inside the save, so it is as atomic as any other change.
- `forceDelete()` writes one `force_deleted` row. It is recorded when `auditEvents()` lists `deleted` or `force_deleted`.
- `saveQuietly()`, `updateQuietly()` and `withoutEvents()` fire no model events, so they write no row. Use the helpers in [section 4](#4-writes-that-skip-events).
- `increment()` and `decrement()` fire `updated`, so they are recorded.
- A save with no changes left after excludes writes **no row**: touch-only saves, remember-token cycling and excluded-only changes are skipped. A change from `null` to `null` (an attribute that was never loaded, now set to `null`) is not a change either.
- Every row has the category `record`.

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
- **Exclude and redact** are added to the lists in config (`attributes.exclude`, `attributes.redact`). Both take glob patterns: `'internal_*'`, `'*_token'`.
- **`auditEvents()`** narrows a noisy model, for example `['deleted']` for attachments. `deleted` also covers `force_deleted`.
- **Scope columns** are filled by your resolvers ([section 8](#8-multi-tenant)). They receive the `PendingAudit`, whose `subject` is the model, so a resolver can read it. Read it with `getAttributes()`, like your overrides.

**Secrets, on by default**

- `password` is stored as `"[redacted]"`.
- `remember_token`, `*_token`, `*_secret` and `two_factor_recovery_codes` are excluded entirely: not stored, and a save that changes only those writes no row.
- Attributes in the model's `$hidden` (`attributes.redact_hidden`) and attributes cast `encrypted`, `encrypted:*`, `hashed` or `AsEncrypted*` (`attributes.redact_encrypted`) are redacted automatically.
- A redacted attribute that is `null` stays `null`, so a row still shows that a value was set or cleared, never what it was.
- Excluding wins over redacting.

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

`increment()` and `decrement()` do not call `save()`, so they are atomic only inside your own transaction.

---

## 4. Writes that skip events

> **Spec: lands in P3.**

Bulk updates, `DB::table()` writes, `updateQuietly()` and pivot changes fire no model events, so they would leave no trail. Use these instead; each runs in its own transaction with its audit rows.

```php
use Jiannius\Audit\Facades\Auditor;

// Bulk or raw update: one row per changed record, from before/after snapshots.
Auditor::update($order->lines(), ['status' => 'cancelled'], meta: ['cascade' => 'order_cancelled']);

// Keep an existing raw table write as it is, but audited.
Auditor::update(
    DB::table('invoices')->where('id', $invoice->id),
    ['voided_at' => now(), 'void_reason' => $reason],
    event: 'voided',
    meta: ['reason' => $reason],
    subjectType: 'invoice',
);

// Bulk delete: snapshots every row first.
Auditor::delete(Invoice::query()->where('status', 'draft'));

// Metadata written without firing the model's saving hooks.
$invoice->updateQuietlyWithAudit(['external_id' => $externalId], event: 'synced');

// Pivot writes: snapshots the pivot rows before and after.
Auditor::pivot($user->teams(), fn (BelongsToMany $teams) => $teams->sync($teamIds));
```

`$query` may be an Eloquent builder, a relation or a base query builder. A base builder needs `subjectType`, or the type is inferred from the morph map by table name. `Auditor::update` selects the affected rows with `lockForUpdate`, so large bulk updates hold locks a little longer.

---

## 5. Custom events

`Auditor::record()` ships in P1. `$model->audit()` ships in P3.

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

$invoice->audit('sent', meta: ['to' => $recipients]);   // P3: same, on a model that uses Auditable
```

Name events after the domain action (`sent`, `voided`, `approved`), not the mechanism. A sync that changes fields is one row, the field change with `event: 'synced'`, not a change row plus an event row.

`record()` writes in the caller's transaction, if any. See [section 9](#9-failure-behaviour).

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
2. Inside a queued job: the origin carried from the process that dispatched it (below), even when that origin is nobody. The guard is **not** consulted inside a job, because a long-running worker keeps its guards between jobs, so a job that signed someone in would otherwise leak that user into the next job. A job that really does act as someone else says so with `actingAs`.
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
| `Auditable` `save()`, `delete()`, `restore()` with `transactional_saves` on (default) | yes | the change rolls back and the exception is raised |
| Same, with `transactional_saves` off | only inside your transaction | exception; without your transaction the change stands |
| `Auditable` model that overrides `save()` without the alias | only inside your transaction | exception after commit (a gap; `assertTransactional` catches it) |
| `Auditor::update`, `delete`, `pivot`; `updateQuietlyWithAudit` | yes (own transaction) | rolled back |
| `Auditor::record`, `$model->audit()` | only inside your transaction | exception; earlier work outside a transaction stands |
| Sign-ins | [section 6](#6-sign-ins) | [section 6](#6-sign-ins) |
| `audit:prune`, `audit:purge` | no; chunked deletes, then one summary row | the command fails; deleted rows stay deleted |

**Consequences**

- A missing `audits` table blocks saves on audited models. Deploy the migration before, or with, the code that adds `Auditable`.
- An exception in a `saved` hook now rolls the save back when `transactional_saves` is on. Run your full suite when you adopt it; the config switch is the off ramp.
- `Model::increment()` and `decrement()` do not call `save()`, so they are not wrapped.

**What the exception holds.** A failed insert throws `Jiannius\Audit\Exceptions\AuditWriteFailed`. It carries the table name, the SQLSTATE and the driver's error code, and nothing else: the database exception's own message holds the full SQL with every bound value (labels, emails, IPs), so it is not chained as `previous`. It still propagates, so a surrounding transaction rolls back. An invalid category throws `InvalidArgumentException` before any insert.

**Sanitising.** An awkward value must never block a legitimate save:

- non-UTF-8 strings are stored as `"[binary N bytes]"`;
- a string in `old_values`, `new_values` or `meta` over `audit.max_value_bytes` (default 65,536 bytes) is stored as the marker `"[truncated N bytes]"`, with its size and none of its content;
- values that cannot be JSON-encoded (for example nesting beyond 512 levels) are stored as the marker `{"_audit": "[values could not be encoded]"}`, never as null;
- NaN and infinite floats, resources and unknown objects are stored as short placeholders; enums store their backing value and dates their database string;
- labels, source and identifiers are truncated to their column sizes, by character;
- the user agent is stored as text, truncated to MySQL's 65,535 bytes without splitting a character.

**The package never logs audit values**, and it never puts them in an exception message, so your error tracker never receives them from the package.

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
| `attributes.exclude` | `created_at`, `updated_at`, `remember_token`, `*_token`, `*_secret`, `two_factor_recovery_codes` | Never stored, never count as a change. Glob patterns |
| `attributes.redact` | `['password']` | Stored as `"[redacted]"` |
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
