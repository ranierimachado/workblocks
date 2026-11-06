<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

\Illuminate\Support\Facades\Artisan::command('plan:import {file} {--replace : Confirm destructive plan replacement}', function () {
 if(!$this->option('replace')){$this->error('Use --replace to confirm deleting unfinished work.');return 1;}
 $document=json_decode(file_get_contents($this->argument('file')),true,512,JSON_THROW_ON_ERROR);
 $validated=app(\App\Services\PlanValidator::class)->validate($document);
 $handle=fopen(storage_path('framework/workblocks.lock'),'c');flock($handle,LOCK_EX);
 try{$id=app(\App\Services\Planner::class)->import($validated);}finally{flock($handle,LOCK_UN);fclose($handle);}
 $this->info('Imported '.$validated['plan_name'].' ('.$id.').');
})->purpose('Validate and replace the active plan, preserving completed history');
