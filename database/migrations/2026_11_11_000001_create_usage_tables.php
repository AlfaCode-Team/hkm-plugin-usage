<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;

/**
 * Metering storage: time buckets, the latest gauge values, and the batch ids
 * already applied.
 */
return new class implements MigrationInterface {
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->create('usage_buckets', static function ($t) {
            // '' = a measurement with no tenant, NOT null: a nullable column in a
            // primary key is not unique in every engine, and the upsert that
            // guards each bucket relies on this key being a real conflict target.
            $t->string('tenant_id', 64)->default('');
            $t->string('metric', 128);

            // Bucket width in seconds (3600 | 86400). Part of the key, so an
            // hourly and a daily row for the same instant are different rows.
            $t->unsignedInteger('resolution');

            // Unix timestamp of the bucket START, UTC. bigint: a signed int
            // overflows in 2038.
            $t->unsignedBigInteger('bucket');

            // Sum of every counter increment in the bucket.
            $t->bigInteger('counter_sum')->default(0);
            $t->unsignedInteger('samples')->default(0);

            // The last level reported inside the bucket, and WHEN, so a batch
            // that is applied late cannot overwrite a newer value with an older.
            $t->double('gauge_value')->nullable();
            $t->unsignedBigInteger('gauge_at')->nullable();

            $t->primary(['tenant_id', 'metric', 'resolution', 'bucket'], 'pk_usage_bucket');

            // Platform-wide reads (no tenant) and the prune scan both lead with
            // the metric/width and range over the bucket; the primary key leads
            // with the tenant and cannot serve them.
            $t->index(['metric', 'resolution', 'bucket'], 'idx_usage_metric_range');
            $t->index(['resolution', 'bucket'], 'idx_usage_prune');

            $t->engine('InnoDB');
            $t->charset('utf8mb4');
        });

        $schema->create('usage_gauges', static function ($t) {
            $t->string('tenant_id', 64)->default('');
            $t->string('metric', 128);
            $t->double('value');
            $t->unsignedBigInteger('at');

            $t->primary(['tenant_id', 'metric'], 'pk_usage_gauge');
            $t->index(['metric'], 'idx_usage_gauge_metric');

            $t->engine('InnoDB');
            $t->charset('utf8mb4');
        });

        $schema->create('usage_batches', static function ($t) {
            // The idempotency key stamped by the flusher. A job delivered twice
            // is recognised by this row, written in the same transaction as the
            // counters it protects.
            $t->char('batch_id', 32);
            $t->unsignedBigInteger('applied_at');

            $t->primary(['batch_id'], 'pk_usage_batch');
            $t->index(['applied_at'], 'idx_usage_batch_age');

            $t->engine('InnoDB');
            $t->charset('utf8mb4');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropIfExists('usage_batches');
        $schema->dropIfExists('usage_gauges');
        $schema->dropIfExists('usage_buckets');
    }
};
