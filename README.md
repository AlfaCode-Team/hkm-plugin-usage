# hkm-plugin-usage

Counters, gauges and growth-over-a-window, with hourly and daily rollup.

Solves `usage.metering`. Requires nothing at the module level. Needs a
`QueuePort`, a worker running the `usage` queue, and (for reads and the worker)
a `DatabasePort` with the three tables from `database/migrations`.

```php
// In a service that declared "requires": ["usage.metering"]
$this->usage->count('invoices.created');          // counter: only ever goes up
$this->usage->gauge('queue.depth', 12);           // gauge: the last value wins

// Anywhere with the Request (a controller, a stage): no contract needed
$request->attribute('usage')->count('exports.started');

// Reading (eventually consistent — see below)
$this->usage->sum('http.errors', $from, $to);                 // all tenants
$this->usage->sum('http.errors', $from, $to, tenant: 't-1');  // one tenant
$this->usage->latest('queue.depth');                          // gauge, now
$this->usage->growth('http.requests', 3600 * 6)->ratio();     // +0.35 = up 35%
$this->usage->series('http.requests', $from, $to);            // hourly buckets
```

Every request through a route is counted without any of that: `http.requests`,
`http.errors` (status >= 500) and `http.bytes_out` (when the response carries
`Content-Length`). `USAGE_TRACK_REQUESTS=false` turns those three off.

---

## The one thing to understand first

**The request path never performs the durable write.**

Metering fires on *every* request. Appwrite runs its metering queue at 8
coroutines for exactly that reason; a synchronous database write here would tax
every single response. So:

```
request ──► UsageAccumulator (an array, per request)
              │   count() / gauge() add to it. Nothing is written.
              ▼
after.execute ──► UsageFlushStage ──► UsageFlusher ──► QueuePort::push('usage.flush', {…})
                                                          │   ONE message per request
                                                          ▼
                                       worker ──► FlushUsageJob ──► UsageRepository  (one transaction)
```

* `UsageFlusher` and `UsageFlushStage` have **no database dependency** — not in
  their constructors, not resolved inside. The guarantee is structural, and
  `UsageRequestPathTest` pins it three ways: a database that counts its calls
  stays at zero through a whole request; reflection over the constructors finds
  no `DatabasePort`/`UsageStore`; and `RealKernelTest` does the same through the
  real kernel pipeline.
* **No queue bound, or the queue is down?** The batch is dropped (and, for a
  dead queue, logged). There is deliberately **no inline-write fallback**: that
  fallback is the cost this design exists to avoid, and the test
  `test_a_queue_that_is_down_never_fails_the_response` asserts the database call
  count is still zero.
* The accumulator lives in the request's container (and on the request as the
  `usage` attribute), **never in a static and never on the stage**: stages are
  memoized per worker. `test_nothing_request_scoped_is_static` and
  `test_the_memoized_stage_does_not_carry_one_request_into_the_next` pin that.
* A request that throws is still flushed (a 500 is what `http.errors` counts),
  then the exception continues to `ErrorStage`.

### What the worker guarantees

`FlushUsageJob` applies a batch **atomically and exactly once per batch id**.
The flusher stamps a random id; the worker records it in `usage_batches` in the
same transaction as the counters, so a job delivered twice (or retried after a
crash between the writes and the ack) is skipped the second time. A failure
mid-batch rolls the whole batch back and the retry applies it again.

Counters are applied as `UPDATE … SET counter_sum = counter_sum + :n` after an
insert-if-absent of the bucket — `DatabasePort::upsert()` replaces columns, it
cannot add, and hand-writing `ON DUPLICATE KEY`/`ON CONFLICT` is not allowed in
a repository.

## What is not counted

`after.execute` sits **in front of** `ExecuteStage`. A request that is answered
earlier — a 404 from route resolution, a denial from the security gateway, a
route filter such as `auth` or `throttle` refusing it — **never reaches the
hook and is not counted.** `RealKernelTest` pins this
(`test_a_request_that_never_reaches_execution_is_not_metered…`). Count those
with the kernel's `MetricsPort` (`ObservabilityStage` wraps everything), not
with this plugin.

## Counting from a route declaration

A route can name a counter in `module.json` / `proj.json` instead of calling
`count()` from its handler:

```json
{ "method": "POST", "path": "/orders", "handler": "…@create", "metric": "orders.created" }
```

The kernel's route-label stage (innermost `after.execute` position) calls
`count($name)` on the request's `usage` attribute — the accumulator this
plugin's stage already puts there — so the increment joins the request's one
batch. No contract is added: the stage needs only a public
`count(string $metric, int $by = 1)`. The name must satisfy `MetricName`
(the kernel's label grammar is the same or stricter); a route with no plugin
installed simply records nothing.

It counts only when the handler ran and returned below 400 — "orders.created"
does not tick for a refusal, a 422 or a thrown error. Those are measured by
`http.requests` / `http.errors` here and by `ObservabilityStage` in the kernel.

## Reads are eventually consistent

A measurement is readable once the worker has applied its batch. A stopped
worker looks like every metric going flat, **not** like an error. Anything that
acts on these numbers (the Monitors plugin, a quota) must tolerate the lag.

`$tenant` on a read: `null` = every tenant added together, a string = that
tenant, `''` = measurements with no tenant. The tenant on a write comes from
`Identity::tenantId`, which is a hint — it chooses a row and authorises nothing.

* `sum()` snaps `$from` down to the bucket start and reads buckets that start in
  `[from, to)`. Older than `USAGE_HOURLY_RETENTION_DAYS` it reads daily rows.
* `growth()` compares the window ending at the **end of the current hour** with
  the one before it, from hourly rows only, and **refuses** a pair of windows
  that does not fit inside hourly retention rather than computing a ratio over a
  half-pruned previous window. `ratio()` is `null` when the earlier window was 0.
* `latest()` with no tenant adds up each tenant's current level.

## Rollup and retention

`usage:rollup` (scheduled hourly at `:07`, `withoutOverlapping`) rebuilds the
daily rows from the hourly ones and then deletes what is past retention. Daily
rows are **replaced, never added to**, so running it twice changes nothing.
It only rebuilds days whose hourly rows are all still present (the window is
day-aligned), so a partly-pruned day can never overwrite a complete total —
`test_a_day_whose_hours_were_partly_pruned_keeps_its_complete_daily_total`.

## Config

| Env | Default | |
|---|---|---|
| `USAGE_QUEUE` | `usage` | queue the flush job is pushed to. Run a worker on it |
| `USAGE_TRACK_REQUESTS` | `true` | the three built-in `http.*` counters |
| `USAGE_MAX_METRICS` | `64` | distinct names per request; the rest are dropped, not stored |
| `USAGE_HOURLY_RETENTION_DAYS` | `14` | |
| `USAGE_DAILY_RETENTION_DAYS` | `400` | |
| `USAGE_BATCH_RETENTION_HOURS` | `48` | how long a batch id is remembered; must outlive any redelivery |

## Commands

`usage:show [metric] [--hours=N] [--tenant=ID]` — status line, and one metric's
hourly buckets. `usage:rollup [--quiet-when-idle]`.

## Writing a job for this kernel (two things this plugin got wrong first)

Both were invisible to every unit test and were found only by booting the real
kernel (`tests/Integration/RealKernelTest.php`):

1. `module.json` `jobs[]` only compiles `job-manifest.php`. The worker's
   name→class registry is **`$worker->job('name', Class::class)` in `boot()`**.
   Without it the worker reports `Unknown job`, returns *skipped*, and **acks** —
   the job silently does nothing.
2. The worker resolves the handler with `make()` from *outside* the module's
   scope, so the job must be bound with `$container->bind()`, **not**
   `bindInternal()` (which throws `ScopeViolationException`; the job is then
   released and retried forever).

## Verified / NOT verified

**Verified** (61 tests, 191 assertions, run):

* the request path enqueues one message and makes zero database calls — through
  a hand-driven stage and through the real kernel pipeline;
* the real SQL against a migrated **SQLite** database: counters accumulate,
  duplicate batch ids are applied once, a failure mid-batch rolls back and a
  retry then applies it, an older gauge cannot overwrite a newer one, rollup is
  idempotent and independent of row order, partial days are not rebuilt, retention
  deletes exactly the right resolution;
* the plugin booted by the real kernel via Ground: hook registered, both
  commands registered, job in the manifest, a request flushed, the real
  `WorkerLoop` applying the batch to the database;
* revert checks: each guard above was broken with a one-line edit and a test
  failed (see CHANGELOG). Two guards survived their first check and were
  documented or given a test — see the comments in `UsageRepository::rebuildDays`
  and `UsageService::rollup`.

**NOT verified:**

* **MySQL and PostgreSQL.** Every SQL statement was run on SQLite only. The
  insert-if-absent used for dedupe relies on `upsert(…, [])` returning the number
  of rows inserted; on MySQL that is read from the Database plugin's source
  (`ON DUPLICATE KEY UPDATE col = col`, which reports 0 for an untouched row),
  **not run**.
* A real queue broker (the fakes are in-memory), real concurrency (two workers
  applying batches at once — the transaction and the dedupe key are designed for
  it, never run), PHP-FPM, OpenSwoole.
* Throughput. Nothing here was measured under load; "one push per request" is a
  design claim, not a benchmark. At very high request rates one queue message per
  request is itself a cost; batching across requests in a long-lived worker would
  be the next step and is not built.
* `usage:show` / `usage:rollup` were executed through `BufferIO` in tests and
  registered by the real kernel; they were never run from a real terminal.
