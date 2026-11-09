<?php

namespace Tests\Feature;

use App\Services\Planner;
use App\Services\PlanValidator;
use App\Services\SchedulerExchange;
use App\Services\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SchedulerExchangeTest extends TestCase
{
    use RefreshDatabase;

    private function start(): array
    {
        $this->withSession(['planner_authenticated' => true]);
        app(Planner::class)->import(json_decode(file_get_contents(base_path('../examples/rani-plan.json')), true));

        return app(SchedulerExchange::class)->export();
    }

    private function apply(array $d)
    {
        return $this->postJson('/api/imports', ['document' => $d, 'confirm_replace' => true]);
    }

    public function test_export_is_valid_and_roundtrip_keeps_ids_origins_and_overdue_work(): void
    {
        $d = $this->start();
        $before = DB::table('work_blocks')->get()->keyBy('id');
        $this->assertEquals($d, app(PlanValidator::class)->validate($d));
        $this->apply($d)->assertCreated();
        foreach (DB::table('work_blocks')->get() as $b) {
            $this->assertEquals($before[$b->id]->origin_id, $b->origin_id);
            $this->assertEquals($before[$b->id]->original_date, $b->original_date);
            $this->assertEquals($before[$b->id]->date, $b->date);
        }
        $this->assertDatabaseCount('work_blocks', 23);
    }

    public function test_carryover_survives_an_unchanged_exchange(): void
    {
        $this->start();
        $b = DB::table('work_blocks')->first();
        app(Planner::class)->move($b->id, ['revision' => $b->revision, 'date' => null, 'slot' => 'extra']);
        $d = app(SchedulerExchange::class)->export();
        $this->assertCount(1, $d['carryover']);
        $this->apply($d)->assertCreated();
        $this->assertDatabaseHas('work_blocks', ['id' => $b->id, 'date' => null, 'duration_minutes' => 180, 'original_date' => $b->original_date]);
    }

    public function test_stale_preview_and_apply_are_rejected_after_a_move_without_writes(): void
    {
        $d = $this->start();
        $b = DB::table('work_blocks')->first();
        app(Planner::class)->move($b->id, ['revision' => 1, 'date' => null, 'slot' => 'extra']);
        $this->postJson('/api/imports/preview', ['document' => $d])->assertConflict();
        $this->apply($d)->assertConflict();
        $this->assertDatabaseCount('plan_imports', 1);
        $this->assertDatabaseHas('work_blocks', ['id' => $b->id, 'date' => null]);
    }

    public function test_each_workspace_write_invalidates_a_feed(): void
    {
        $this->start();
        $p = app(Planner::class);
        $b = DB::table('work_blocks')->first();
        $d = app(SchedulerExchange::class)->export();
        $p->complete($b->id, ['revision' => 1, 'completed' => true]);
        $this->apply($d)->assertConflict();
        $d = app(SchedulerExchange::class)->export();
        $p->complete($b->id, ['revision' => 2, 'completed' => false]);
        $this->apply($d)->assertConflict();
        $d = app(SchedulerExchange::class)->export();
        $p->split($b->id, ['revision' => 3, 'first_minutes' => 90, 'date' => null, 'slot' => 'extra']);
        $this->apply($d)->assertConflict();
        $d = app(SchedulerExchange::class)->export();
        $p->generate('2026-11-16');
        $this->apply($d)->assertConflict();
        $d = app(SchedulerExchange::class)->export();
        $importId = DB::table('plan_imports')->value('id');
        $p->restore($importId);
        $this->apply($d)->assertConflict();
    }

    public function test_an_import_rejects_changes_made_after_preview_and_replay(): void
    {
        $d = $this->start();
        $this->postJson('/api/imports/preview', ['document' => $d])->assertOk();
        $b = DB::table('work_blocks')->first();
        app(Planner::class)->complete($b->id, ['revision' => 1, 'completed' => true]);
        $this->apply($d)->assertConflict();
        $fresh = app(SchedulerExchange::class)->export();
        $this->apply($fresh)->assertCreated();
        $this->apply($fresh)->assertConflict();
    }

    public function test_wrong_workspace_is_rejected(): void
    {
        $d = $this->start();
        $d['workspace_id'] = (string) Str::uuid();
        $this->apply($d)->assertConflict();
    }

    public function test_completed_priority_identity_and_name_snapshot_survive_rename(): void
    {
        $this->start();
        $b = DB::table('work_blocks')->first();
        app(Planner::class)->complete($b->id, ['revision' => 1, 'completed' => true]);
        $d = app(SchedulerExchange::class)->export();
        foreach ($d['priorities'] as &$priority) {
            if ($priority['id'] === $b->priority_id) {
                $priority['name'] = 'Renamed priority';
            }
        }
        unset($priority);
        $this->apply($d)->assertCreated();
        $this->assertDatabaseHas('work_blocks', ['id' => $b->id, 'priority_id' => $b->priority_id, 'priority_name' => $b->priority_name, 'completed' => true]);
    }

    public function test_removed_completed_priority_keeps_historical_identity(): void
    {
        $this->start();
        $b = DB::table('work_blocks')->first();
        app(Planner::class)->complete($b->id, ['revision' => 1, 'completed' => true]);
        $d = app(SchedulerExchange::class)->export();
        $d['priorities'] = array_values(array_filter($d['priorities'], fn ($p) => $p['id'] !== $b->priority_id));
        $d['standing_plan']['blocks'] = array_values(array_filter($d['standing_plan']['blocks'], fn ($p) => $p['priority_id'] !== $b->priority_id));
        foreach ($d['weeks'] as &$week) {
            $week['blocks'] = array_values(array_filter($week['blocks'], fn ($p) => $p['priority_id'] !== $b->priority_id));
        }
        unset($week);
        $this->apply($d)->assertCreated();
        $this->assertDatabaseHas('work_blocks', ['id' => $b->id, 'priority_id' => $b->priority_id]);
    }

    public function test_completed_id_cannot_be_resubmitted_and_duplicate_ids_fail(): void
    {
        $d = $this->start();
        $first = $d['weeks'][0]['blocks'][0];
        $d['weeks'][0]['blocks'][1]['id'] = $first['id'];
        $this->apply($d)->assertUnprocessable();
        app(Planner::class)->complete($first['id'], ['revision' => 1, 'completed' => true]);
        $d = app(SchedulerExchange::class)->export();
        $d['weeks'][0]['blocks'][] = $first;
        $this->apply($d)->assertUnprocessable();
    }

    public function test_split_is_previewed_and_keeps_lineage_and_minutes(): void
    {
        $d = $this->start();
        $parent = $d['weeks'][0]['blocks'][0];
        $d['weeks'][0]['blocks'][0]['duration_minutes'] = 90;
        $child = $parent;
        $child['id'] = (string) Str::uuid();
        $child['split_from'] = $parent['id'];
        $child['duration_minutes'] = 90;
        unset($child['date']);
        $d['carryover'][] = $child;
        $this->postJson('/api/imports/preview', ['document' => $d])->assertOk()->assertJsonCount(1, 'changes.split');
        $this->apply($d)->assertCreated();
        $this->assertEquals(4140, DB::table('work_blocks')->sum('duration_minutes'));
        $this->assertDatabaseHas('work_blocks', ['id' => $child['id'], 'origin_id' => $parent['id'], 'original_date' => $parent['date'], 'date' => null]);
        $this->apply(app(SchedulerExchange::class)->export())->assertCreated();
    }

    public function test_split_that_duplicates_minutes_is_rejected(): void
    {
        $d = $this->start();
        $child = $d['weeks'][0]['blocks'][0];
        $child['split_from'] = $child['id'];
        $child['id'] = (string) Str::uuid();
        unset($child['date']);
        $d['carryover'][] = $child;
        $this->apply($d)->assertUnprocessable();
        $this->assertDatabaseCount('work_blocks', 23);
    }

    public function test_diff_reports_moves_additions_removals_and_updates(): void
    {
        $d = $this->start();
        $moved = array_shift($d['weeks'][0]['blocks']);
        unset($moved['date']);
        $d['carryover'][] = $moved;
        array_pop($d['weeks'][0]['blocks']);
        $added = $d['weeks'][0]['blocks'][0];
        $added['id'] = (string) Str::uuid();
        $added['position'] = 2;
        $d['weeks'][0]['blocks'][] = $added;
        $d['weeks'][0]['blocks'][0]['notes'] = 'Changed notes';
        $this->postJson('/api/imports/preview', ['document' => $d])->assertOk()->assertJsonCount(1, 'changes.moved')->assertJsonCount(1, 'changes.added')->assertJsonCount(1, 'changes.removed')->assertJsonCount(1, 'changes.updated');
        $this->assertDatabaseCount('work_blocks', 23);
    }

    public function test_position_collision_with_completed_work_is_resolved_without_drift(): void
    {
        $this->start();
        $b = DB::table('work_blocks')->first();
        app(Planner::class)->complete($b->id, ['revision' => 1, 'completed' => true]);
        $d = app(SchedulerExchange::class)->export();
        $d['weeks'][0]['blocks'][] = ['id' => (string) Str::uuid(), 'date' => $b->date, 'slot' => $b->slot, 'priority_id' => $b->priority_id];
        $id = end($d['weeks'][0]['blocks'])['id'];
        $this->apply($d)->assertCreated();
        $this->assertDatabaseHas('work_blocks', ['id' => $id, 'position' => 2]);
        $this->apply(app(SchedulerExchange::class)->export())->assertCreated();
        $this->assertDatabaseHas('work_blocks', ['id' => $id, 'position' => 2]);
    }

    public function test_version_one_still_performs_destructive_reset_and_invalidates_exports(): void
    {
        $d = $this->start();
        $b = DB::table('work_blocks')->first();
        app(Planner::class)->move($b->id, ['revision' => 1, 'date' => null, 'slot' => 'extra']);
        $reset = json_decode(file_get_contents(base_path('../examples/rani-plan.json')), true);
        $reset['weeks'] = [];
        $this->apply($reset)->assertCreated();
        $this->assertDatabaseCount('work_blocks', 0);
        $this->apply($d)->assertConflict();
    }

    public function test_version_two_failure_rolls_back_state_and_revision(): void
    {
        $d = $this->start();
        DB::statement("CREATE TRIGGER reject_update BEFORE INSERT ON priorities BEGIN SELECT RAISE(ABORT, 'simulated failure'); END");
        $this->apply($d)->assertStatus(500);
        $this->assertDatabaseCount('work_blocks', 23);
        $this->assertDatabaseCount('plan_imports', 1);
        $this->assertEquals($d['base_revision'], Workspace::current()->revision);
    }

    public function test_empty_carryover_field_is_required_and_export_is_authenticated(): void
    {
        $d = $this->start();
        unset($d['carryover']);
        $this->apply($d)->assertUnprocessable();
        $this->getJson('/api/scheduler/export')->assertOk()->assertJsonPath('format_version', 2);
        $this->flushSession();
        $this->getJson('/api/scheduler/export')->assertUnauthorized();
    }

    public function test_legacy_restore_repairs_collisions_and_invalidates_feeds(): void
    {
        $d = $this->start();
        $snapshot = app(Planner::class)->snapshot();
        $duplicate = $snapshot['work_blocks'][0];
        $duplicate['id'] = (string) Str::uuid();
        $duplicate['completed'] = true;
        $snapshot['work_blocks'][] = $duplicate;
        $id = (string) Str::uuid();
        DB::table('plan_imports')->insert(['id' => $id, 'plan_name' => 'Legacy backup', 'snapshot' => json_encode($snapshot), 'document' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $this->postJson('/api/imports/'.$id.'/restore', ['confirm_restore' => true])->assertNoContent();
        $this->assertDatabaseCount('work_blocks', 24);
        $this->assertDatabaseHas('work_blocks', ['id' => $duplicate['id'], 'position' => $duplicate['position']]);
        $this->apply($d)->assertConflict();
    }
}
