<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Workspace
{
    public static function current(): object
    {
        return DB::table('workspace_state')->where('id', 1)->first();
    }

    public static function advance(bool $newIdentity = false): void
    {
        $updates = ['revision' => DB::raw('revision + 1')];
        if ($newIdentity) {
            $updates['workspace_id'] = (string) Str::uuid();
        }
        DB::table('workspace_state')->where('id', 1)->update($updates);
        DB::afterCommit(function () {
            try {
                app(FeedFiles::class)->export();
            } catch (\Throwable $error) {
                report($error);
            }
        });
    }

    public static function assertCurrent(array $document): void
    {
        $state = self::current();
        abort_unless($document['workspace_id'] === $state->workspace_id && $document['base_revision'] === (int) $state->revision,
            409, 'This feed is outdated or belongs to another workspace. Export the current schedule and regenerate the update.');
    }
}
