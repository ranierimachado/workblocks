<?php

use App\Services\FeedFiles;
use App\Services\FeedProcessor;
use App\Services\Planner;
use App\Services\PlanValidator;
use App\Services\SchedulerExchange;
use App\Services\Workspace;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('plan:import {file} {--replace : Confirm destructive plan replacement}', function () {
    if (! $this->option('replace')) {
        $this->error('Use --replace to confirm deleting unfinished work.');

        return 1;
    }
    $document = json_decode(file_get_contents($this->argument('file')), true, 512, JSON_THROW_ON_ERROR);
    $validated = app(PlanValidator::class)->validate($document);
    $handle = fopen(storage_path('framework/workblocks.lock'), 'c');
    flock($handle, LOCK_EX);
    try {
        $id = app(Planner::class)->import($validated);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
    $this->info('Imported '.$validated['plan_name'].' ('.$id.').');
})->purpose('Validate and replace the active plan, preserving completed history');

Artisan::command('plan:export {file}', function () {
    $document = app(SchedulerExchange::class)->export();
    $file = $this->argument('file');
    if (file_put_contents($file, json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL) === false) {
        $this->error('Could not write export.');

        return 1;
    }
    $this->info('Exported scheduler revision '.$document['base_revision'].' to '.$file.'.');
})->purpose('Export unfinished work, carryover and the standing plan for an external scheduler');

Artisan::command('feed:process', function () {
    $h = fopen(storage_path('framework/feed-worker.lock'), 'c');
    if (! $h || ! flock($h, LOCK_EX | LOCK_NB)) {
        $this->warn('A feed worker is already running.');

        return 0;
    }
    try {
        $this->line(json_encode(app(FeedProcessor::class)->process(), JSON_UNESCAPED_UNICODE));
    } finally {
        flock($h, LOCK_UN);
        fclose($h);
    }
})->purpose('Export the workspace and process stable inbox files once');

Artisan::command('feed:watch {--interval=15}', function () {
    $interval = filter_var($this->option('interval'), FILTER_VALIDATE_INT);
    if ($interval === false || $interval < 1 || $interval > 300) {
        $this->error('Interval must be 1–300 seconds.');

        return 1;
    }
    if (! app(FeedFiles::class)->enabled()) {
        $this->error('Enable WORKBLOCKS_FEED_ENABLED first.');

        return 1;
    }
    $h = fopen(storage_path('framework/feed-worker.lock'), 'c');
    if (! $h || ! flock($h, LOCK_EX | LOCK_NB)) {
        $this->info('A feed worker is already running.');

        return 0;
    }
    $running = true;
    if (function_exists('pcntl_async_signals')) {
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, function () use (&$running) {
            $running = false;
        });
        pcntl_signal(SIGINT, function () use (&$running) {
            $running = false;
        });
    }
    $this->info('Watching the Workblocks inbox every '.$interval.' seconds.');
    try {
        while ($running) {
            try {
                $result = app(FeedProcessor::class)->process();
                if ($result) {
                    $this->line(now()->toIso8601String().' '.json_encode($result));
                }
            } catch (Throwable $e) {
                report($e);
                $this->error($e->getMessage());
            }for ($i = 0; $i < $interval && $running; $i++) {
                sleep(1);
            }
        }
    } finally {
        flock($h, LOCK_UN);
        fclose($h);
    }
})->purpose('Keep outbox/current.json current and watch incoming scheduler feeds');

Artisan::command('feed:status', function () {
    $h = fopen(storage_path('framework/feed-worker.lock'), 'c');
    $free = $h && flock($h, LOCK_EX | LOCK_NB);
    if ($free) {
        flock($h, LOCK_UN);
    }
    if ($h) {
        fclose($h);
    }
    $this->line(json_encode(['running' => ! $free, 'enabled' => app(FeedFiles::class)->enabled(), 'revision' => Workspace::current()->revision]));
})->purpose('Report whether the local feed worker holds its singleton lock');
