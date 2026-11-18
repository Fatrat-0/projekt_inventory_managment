<?php

namespace App\Services;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ShipmentDispatchBlocked extends ConflictHttpException
{
    public function __construct(public readonly array $blockers)
    {
        parent::__construct($blockers[0]['message'] ?? 'A fuvar feladásának feltételei nem teljesülnek.');
    }
}
