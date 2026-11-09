<?php

namespace Tests\Feature;

use App\Services\FeedFiles;
use App\Services\FeedProcessor;
use App\Services\Planner;
use App\Services\SchedulerExchange;
use App\Services\Workspace;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AutomatedFeedTest extends TestCase
{
    use DatabaseMigrations;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/wb-feed-'.bin2hex(random_bytes(8));
        config(['workblocks.feed_directory' => $this->directory]);
        app(Planner::class)->import(json_decode(file_get_contents(base_path('../examples/rani-plan.json')), true));
        config(['workblocks.feed_enabled' => true]);
        app(FeedFiles::class)->directories();
        $this->withSession(['planner_authenticated' => true]);
    }

    protected function tearDown(): void
    {
        config(['workblocks.feed_enabled' => false]);
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function queue(array $d, string $name = '01-feed.json'): void
    {
        file_put_contents($this->directory.'/inbox/'.$name, json_encode($d));
    }

    private function pass(): array
    {
        return app(FeedProcessor::class)->process();
    }

    private function settled(): array
    {
        $this->pass();
        $this->travel(3)->seconds();

        return $this->pass();
    }

    private function export(): array
    {
        return app(SchedulerExchange::class)->export();
    }

    private function edited(): array
    {
        $d = $this->export();
        $d['weeks'][0]['blocks'][0]['notes'] = 'Requested by scheduler';

        return $d;
    }

    private function outbox(): array
    {
        return json_decode(file_get_contents($this->directory.'/outbox/current.json'), true);
    }

    public function test_non_destructive_feed_applies_and_exports_receipt(): void
    {
        $d = $this->edited();
        $this->queue($d);
        $this->assertSame(['01-feed.json' => 'applied'], $this->settled());
        $this->assertDatabaseHas('work_blocks', ['id' => $d['weeks'][0]['blocks'][0]['id'], 'notes' => 'Requested by scheduler']);
        $this->assertFileExists($this->directory.'/inbox/applied/01-feed.json');
        $r = json_decode(file_get_contents($this->directory.'/inbox/applied/01-feed.json.result.json'), true);
        $this->assertEquals($d['base_revision'], $r['previous_revision']);
        $this->assertEquals($d['base_revision'] + 1, $r['new_revision']);
        $this->assertArrayHasKey('changes', $r['summary']);
        $this->assertNotEmpty($r['import_id']);
        $this->assertEquals($this->export(), $this->outbox());
    }

    public function test_outdated_feed_is_rejected_without_changes(): void
    {
        $d = $this->edited();
        $b = DB::table('work_blocks')->first();
        app(Planner::class)->move($b->id, ['revision' => $b->revision, 'date' => null, 'slot' => 'extra']);
        $before = app(Planner::class)->snapshot();
        $revision = Workspace::current()->revision;
        $this->queue($d);
        $this->assertSame(['01-feed.json' => 'rejected'], $this->settled());
        $this->assertEquals($before, app(Planner::class)->snapshot());
        $this->assertEquals($revision, Workspace::current()->revision);
        $this->assertStringContainsString('outdated', file_get_contents($this->directory.'/inbox/rejected/01-feed.json.reason.txt'));
    }

    public function test_dropped_unfinished_work_is_pending_without_changing_state(): void
    {
        $d = $this->export();
        array_pop($d['weeks'][0]['blocks']);
        $before = app(Planner::class)->snapshot();
        $this->queue($d);
        $this->assertSame(['01-feed.json' => 'pending'], $this->settled());
        $this->assertEquals($before, app(Planner::class)->snapshot());
        $this->assertStringContainsString('Remove unfinished block', file_get_contents($this->directory.'/inbox/pending/01-feed.json.reason.txt'));
        $this->getJson('/api/feeds/pending')->assertOk()->assertJsonCount(1)->assertJsonPath('0.summary.unfinished_to_delete', 1);
    }

    public function test_two_feeds_against_same_revision_apply_only_once(): void
    {
        $d = $this->edited();
        $this->queue($d, '01-first.json');
        $this->queue($d, '02-second.json');
        $this->assertSame(['01-first.json' => 'applied', '02-second.json' => 'rejected'], $this->settled());
        $this->assertDatabaseCount('plan_imports', 2);
        $this->assertEquals($d['base_revision'] + 1, Workspace::current()->revision);
    }

    public function test_changing_or_partial_file_is_not_read_until_stable(): void
    {
        $path = $this->directory.'/inbox/01-feed.json';
        file_put_contents($path, '{"format_version":');
        $this->pass();
        $this->travel(1)->seconds();
        file_put_contents($path, '{"format_version":2,');
        $this->pass();
        $this->travel(1)->seconds();
        $this->queue($this->edited());
        $this->pass();
        $this->travel(1)->seconds();
        $this->assertSame([], $this->pass());
        $this->assertDatabaseCount('feed_receipts', 0);
        $this->travel(2)->seconds();
        $this->assertSame(['01-feed.json' => 'applied'], $this->pass());
    }

    public function test_pending_approval_revalidates_and_rejects_stale_revision(): void
    {
        $d = $this->export();
        array_pop($d['weeks'][0]['blocks']);
        $this->queue($d);
        $this->settled();
        $key = DB::table('feed_receipts')->value('key');
        $b = DB::table('work_blocks')->first();
        app(Planner::class)->complete($b->id, ['revision' => $b->revision, 'completed' => true]);
        $this->postJson('/api/feeds/'.$key.'/approve', ['confirm_replace' => true])->assertConflict();
        $this->assertDatabaseCount('work_blocks', 23);
        $this->assertFileExists($this->directory.'/inbox/rejected/01-feed.json');
    }

    public function test_pending_can_be_previewed_and_approved(): void
    {
        $d = $this->export();
        array_pop($d['weeks'][0]['blocks']);
        $this->queue($d);
        $this->settled();
        $key = DB::table('feed_receipts')->value('key');
        $this->postJson('/api/feeds/'.$key.'/preview')->assertOk()->assertJsonPath('preview.unfinished_to_delete', 1);
        $this->postJson('/api/feeds/'.$key.'/approve', ['confirm_replace' => true])->assertNoContent();
        $this->assertDatabaseCount('work_blocks', 22);
        $this->assertFileExists($this->directory.'/inbox/applied/01-feed.json.result.json');
        $this->assertSame([], $this->pass());
    }

    public function test_pending_can_be_discarded_without_changes(): void
    {
        $d = $this->export();
        array_pop($d['weeks'][0]['blocks']);
        $this->queue($d);
        $this->settled();
        $key = DB::table('feed_receipts')->value('key');
        $revision = Workspace::current()->revision;
        $this->postJson('/api/feeds/'.$key.'/discard')->assertNoContent();
        $this->assertEquals($revision, Workspace::current()->revision);
        $this->assertStringContainsString('discarded by user', file_get_contents($this->directory.'/inbox/rejected/01-feed.json.reason.txt'));
    }

    public function test_priority_removal_is_held_even_without_block_deletion(): void
    {
        $d = $this->export();
        array_pop($d['priorities']);
        $this->queue($d);
        $this->assertSame(['01-feed.json' => 'pending'], $this->settled());
        $this->assertStringContainsString('Remove priority portfolio', file_get_contents($this->directory.'/inbox/pending/01-feed.json.reason.txt'));
    }

    public function test_version_one_reset_always_requires_approval(): void
    {
        $d = json_decode(file_get_contents(base_path('../examples/rani-plan.json')), true);
        $this->queue($d);
        $this->assertSame(['01-feed.json' => 'pending'], $this->settled());
        $this->assertDatabaseCount('plan_imports', 1);
        $key = DB::table('feed_receipts')->value('key');
        $this->postJson('/api/feeds/'.$key.'/approve', ['confirm_replace' => true])->assertNoContent();
        $this->assertDatabaseCount('plan_imports', 2);
        $this->assertFileExists($this->directory.'/inbox/applied/01-feed.json');
    }

    public function test_priority_and_duration_changes_are_non_destructive(): void
    {
        $d = $this->export();
        $b = &$d['weeks'][0]['blocks'][0];
        $id = $b['id'];
        $b['priority_id'] = 'learn-ai';
        $b['duration_minutes'] = 120;
        unset($b);
        $this->queue($d);
        $this->assertSame(['01-feed.json' => 'applied'], $this->settled());
        $this->assertDatabaseHas('work_blocks', ['id' => $id, 'priority_id' => 'learn-ai', 'duration_minutes' => 120]);
    }

    public function test_direct_planner_changes_and_restore_export_after_commit(): void
    {
        $b = DB::table('work_blocks')->first();
        app(Planner::class)->move($b->id, ['revision' => $b->revision, 'date' => null, 'slot' => 'extra']);
        $this->assertEquals($this->export(), $this->outbox());
        app(Planner::class)->generate('2026-11-16');
        $this->assertEquals($this->export(), $this->outbox());
        app(Planner::class)->restore(DB::table('plan_imports')->value('id'));
        $this->assertFileDoesNotExist($this->directory.'/outbox/current.json');
        $this->assertSame(0, DB::table('work_blocks')->count());
    }

    public function test_rollback_does_not_publish_an_uncommitted_export(): void
    {
        $this->pass();
        $before = file_get_contents($this->directory.'/outbox/current.json');
        $b = DB::table('work_blocks')->first();
        try {
            DB::transaction(function () use ($b) {
                app(Planner::class)->move($b->id, ['revision' => $b->revision, 'date' => null, 'slot' => 'extra']);
                throw new \RuntimeException('Rollback');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame($before, file_get_contents($this->directory.'/outbox/current.json'));
    }

    public function test_committed_receipt_recovers_archive_without_double_apply(): void
    {
        $this->queue($this->edited());
        $this->pass();
        $this->travel(3)->seconds();
        $broken = new class extends FeedProcessor
        {
            public function finish(string $key): void
            {
                throw new \RuntimeException('Simulated interruption after commit');
            }
        };
        $broken->process();
        $this->assertDatabaseCount('plan_imports', 2);
        $revision = Workspace::current()->revision;
        $this->pass();
        $this->assertDatabaseCount('plan_imports', 2);
        $this->assertEquals($revision, Workspace::current()->revision);
        $this->assertFileExists($this->directory.'/inbox/applied/01-feed.json.result.json');
    }

    public function test_invalid_json_and_completed_history_edits_are_rejected(): void
    {
        file_put_contents($this->directory.'/inbox/01-invalid.json', '{invalid');
        $d = $this->edited();
        $d['weeks'][0]['blocks'][0]['completed'] = true;
        $this->queue($d, '02-history.json');
        $this->assertSame(['01-invalid.json' => 'rejected', '02-history.json' => 'rejected'], $this->settled());
        $this->assertDatabaseCount('plan_imports', 1);
    }

    public function test_non_json_files_are_ignored_and_watcher_status_is_available(): void
    {
        file_put_contents($this->directory.'/inbox/note.md', 'not a feed');
        $this->assertSame([], $this->settled());
        $this->assertFileExists($this->directory.'/inbox/note.md');
        $this->artisan('feed:status')->assertExitCode(0);
    }

    public function test_pending_endpoints_require_authentication(): void
    {
        $this->flushSession();
        $this->getJson('/api/feeds/pending')->assertUnauthorized();
    }

    public function test_backup_failure_leaves_valid_feed_for_retry(): void
    {
        $this->queue($this->edited());
        $this->pass();
        $this->travel(3)->seconds();
        app()->instance(Planner::class, new class extends Planner
        {
            public function backup(): ?string
            {
                throw new \RuntimeException('Backup storage unavailable');
            }
        });
        $result = $this->pass();
        $this->assertStringContainsString('error:', $result['01-feed.json']);
        $this->assertFileExists($this->directory.'/inbox/01-feed.json');
        $this->assertDatabaseCount('plan_imports', 1);
        $this->assertDatabaseCount('feed_receipts', 0);
        app()->forgetInstance(Planner::class);
        $this->assertSame(['01-feed.json' => 'applied'], $this->pass());
    }

    public function test_dropped_carryover_is_pending(): void
    {
        $b = DB::table('work_blocks')->first();
        app(Planner::class)->move($b->id, ['revision' => $b->revision, 'date' => null, 'slot' => 'extra']);
        $d = $this->export();
        $d['carryover'] = [];
        $this->queue($d);
        $this->assertSame(['01-feed.json' => 'pending'], $this->settled());
        $this->assertDatabaseHas('work_blocks', ['id' => $b->id, 'date' => null]);
    }

    public function test_new_blocks_require_ids(): void
    {
        $d = $this->edited();
        unset($d['weeks'][0]['blocks'][0]['id']);
        $this->queue($d);
        $this->assertSame(['01-feed.json' => 'rejected'], $this->settled());
        $this->assertDatabaseCount('plan_imports', 1);
    }
}
