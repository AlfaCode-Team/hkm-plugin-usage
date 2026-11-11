# Changelog

## 0.1.0

Initial release.

* `usage.metering`: `count()`, `gauge()`, `flush()`, `sum()`, `latest()`,
  `series()`, `growth()`, `rollup()`.
* Per-request accumulator flushed at `after.execute` (priority 90) into one
  queue message; the request path performs no durable write.
* `usage.flush` job: atomic, exactly-once-per-batch application to hourly
  buckets and a latest-gauge table.
* `usage:rollup` (scheduled hourly, `withoutOverlapping`) and `usage:show`.
* Route `metric` labels are counted on the request's accumulator by the kernel's
  route-label stage (no handler code, no new contract).
* Built-in `http.requests`, `http.errors`, `http.bytes_out`.
