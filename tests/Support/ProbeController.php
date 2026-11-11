<?php

declare(strict_types=1);

namespace Tests\Plugins\Usage\Support;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;

/** A project route for the real-kernel test. Not part of the plugin. */
final class ProbeController
{
    public function ok(Request $request): Response
    {
        $request->attribute('usage')?->count('probe.hits');

        return Response::json(['ok' => true]);
    }

    public function boom(Request $request): Response
    {
        return Response::json(['error' => 'x'], 503);
    }
}
