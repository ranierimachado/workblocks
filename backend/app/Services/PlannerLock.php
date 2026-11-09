<?php

namespace App\Services;

class PlannerLock
{
    public function run(callable $action): mixed
    {
        $h = fopen(storage_path('framework/workblocks.lock'), 'c');
        if (! $h || ! flock($h, LOCK_EX)) {
            throw new \RuntimeException('Could not lock the planner.');
        }
        try {
            return $action();
        } finally {
            flock($h, LOCK_UN);
            fclose($h);
        }
    }
}
