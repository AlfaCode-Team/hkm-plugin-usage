<?php

declare(strict_types=1);

namespace Plugins\Usage\Domain;

/**
 * The two bucket widths stored. The backing value is the width in seconds and is
 * also what is written to the `resolution` column, so a bucket's width is part
 * of its key and an hourly row can never be mistaken for a daily one.
 *
 * Everything is UTC. A bucket boundary that moved with a tenant's timezone would
 * make "yesterday" mean a different set of rows per tenant and make the
 * platform-wide total impossible to add up.
 */
enum Resolution: int
{
    case Hour = 3600;
    case Day  = 86400;

    public function floor(int $timestamp): int
    {
        return intdiv($timestamp, $this->value) * $this->value;
    }
}
