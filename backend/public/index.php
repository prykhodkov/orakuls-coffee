<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Http\Kernel;
use App\Http\Request;

Kernel::create()->handle(Request::fromGlobals())->send();
