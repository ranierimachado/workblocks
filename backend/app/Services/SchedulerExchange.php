<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SchedulerExchange
{
    public function export(): array
    {
        return DB::transaction(function () {
            $state = Workspace::current();
            $settings = json_decode(DB::table('plan_settings')->value('document') ?? '{}', true);
            abort_unless(isset($settings['plan_name']), 422, 'Import an initial plan before exporting a scheduler update.');
            $document = array_intersect_key($settings, array_flip(['plan_name', 'timezone', 'effective_from', 'default_block_minutes']));
            $document += ['format_version' => 2, 'workspace_id' => $state->workspace_id, 'base_revision' => (int) $state->revision];
            $document['priorities'] = DB::table('priorities')->orderBy('rank')->get()->map(fn ($p) => (array) $p)->all();
            $document['standing_plan'] = ['notes' => $settings['standing_notes'] ?? '', 'blocks' => DB::table('template_blocks')->orderBy('id')->get()->map(fn ($b) => collect((array) $b)->except('id')->all())->all()];
            $document['weeks'] = DB::table('plan_weeks')->orderBy('week_start')->get()->map(fn ($week) => [
                'week_start' => $week->week_start, 'notes' => $week->notes, 'changes' => json_decode($week->changes, true),
                'blocks' => DB::table('work_blocks')->where('completed', false)->whereBetween('date', [$week->week_start, CarbonImmutable::parse($week->week_start)->addDays(6)->toDateString()])->orderBy('date')->orderBy('slot')->orderBy('position')->get()->map(fn ($b) => $this->exportBlock($b))->all(),
            ])->all();
            // Legacy carryover can share position 1. Export a deterministic, valid order.
            $positions = [];
            $document['carryover'] = DB::table('work_blocks')->where('completed', false)->whereNull('date')->orderBy('slot')->orderBy('position')->orderBy('id')->get()->map(function ($b) use (&$positions) {
                $block = $this->exportBlock($b);
                $block['position'] = $positions[$b->slot] = ($positions[$b->slot] ?? 0) + 1;

                return $block;
            })->all();

            return $document;
        });
    }

    private function exportBlock(object $block): array
    {
        $result = ['id' => $block->id, 'priority_id' => $block->priority_id, 'slot' => $block->slot, 'position' => $block->position, 'duration_minutes' => $block->duration_minutes, 'notes' => $block->notes];
        if ($block->date !== null) {
            $result['date'] = $block->date;
        }

        return $result;
    }

    public function incoming(array $d): array
    {
        $blocks = [];
        foreach ($d['weeks'] as $week) {
            foreach ($week['blocks'] as $block) {
                $blocks[] = $block;
            }
        }
        foreach ($d['carryover'] as $block) {
            $blocks[] = $block + ['date' => null];
        }

        return $blocks;
    }

    public function check(array $d): void
    {
        Workspace::assertCurrent($d);
        $old = DB::table('work_blocks')->get()->keyBy('id');
        $incoming = collect($this->incoming($d))->keyBy('id');
        $splitTotals = [];
        foreach ($incoming as $block) {
            $existing = $old->get($block['id']);
            if ($existing && $existing->completed) {
                $this->fail('Completed work cannot be resubmitted as unfinished work.');
            }
            if (isset($block['split_from'])) {
                $parent = $old->get($block['split_from']);
                if ($existing || ! $parent || $parent->completed || ! $incoming->has($parent->id) || isset($incoming[$parent->id]['split_from']) || $incoming[$parent->id]['priority_id'] !== $block['priority_id']) {
                    $this->fail('split_from must reference a retained unfinished block of the same priority; the child must have a new UUID.');
                }
                $splitTotals[$parent->id] = ($splitTotals[$parent->id] ?? 0) + ($block['duration_minutes'] ?? $d['default_block_minutes']);
            }
        }
        foreach ($splitTotals as $parent => $minutes) {
            $first = $incoming[$parent]['duration_minutes'] ?? $d['default_block_minutes'];
            if ($minutes + $first !== $old[$parent]->duration_minutes) {
                $this->fail('A split must preserve the parent block’s total minutes.');
            }
        }
    }

    public function preview(array $d): array
    {
        return DB::transaction(function () use ($d) {
            $this->check($d);
            $old = DB::table('work_blocks')->where('completed', false)->get()->keyBy('id');
            $incoming = collect(app(Planner::class)->offsetPositions($this->incoming($d)))->keyBy('id');
            $changes = ['added' => [], 'removed' => [], 'moved' => [], 'updated' => [], 'split' => []];
            foreach ($incoming as $id => $b) {
                $before = $old->get($id);
                $summary = ['id' => $id, 'priority_id' => $b['priority_id'], 'date' => $b['date'], 'slot' => $b['slot'], 'position' => $b['position'] ?? 1, 'notes' => $b['notes'] ?? '', 'duration_minutes' => $b['duration_minutes'] ?? $d['default_block_minutes']];
                if (! $before) {
                    $changes[isset($b['split_from']) ? 'split' : 'added'][] = $summary + (isset($b['split_from']) ? ['split_from' => $b['split_from']] : []);

                    continue;
                }
                if ($before->date !== $b['date'] || $before->slot !== $b['slot'] || $before->position !== ($b['position'] ?? 1)) {
                    $changes['moved'][] = $summary + ['from_date' => $before->date, 'from_slot' => $before->slot, 'from_position' => $before->position];
                }
                if ($before->duration_minutes !== $summary['duration_minutes'] || $before->notes !== ($b['notes'] ?? '') || $before->priority_id !== $b['priority_id']) {
                    $changes['updated'][] = $summary + ['before_duration_minutes' => $before->duration_minutes, 'before_notes' => $before->notes, 'before_priority_id' => $before->priority_id];
                }
            }
            foreach ($old as $id => $b) {
                if (! $incoming->has($id)) {
                    $changes['removed'][] = ['id' => $id, 'priority_id' => $b->priority_id, 'date' => $b->date, 'slot' => $b->slot, 'duration_minutes' => $b->duration_minutes];
                }
            }

            return ['mode' => 'update', 'plan_name' => $d['plan_name'], 'priorities' => count($d['priorities']), 'weeks' => count($d['weeks']), 'blocks' => $incoming->count(), 'carryover' => count($d['carryover']), 'unfinished_to_delete' => count($changes['removed']), 'completed_to_keep' => DB::table('work_blocks')->where('completed', true)->count(), 'base_revision' => $d['base_revision'], 'changes' => $changes,
                'unfinished_minutes_before' => $old->sum('duration_minutes'), 'unfinished_minutes_after' => $incoming->sum(fn ($b) => $b['duration_minutes'] ?? $d['default_block_minutes']),
                'priorities_changed' => DB::table('priorities')->orderBy('rank')->get()->map(fn ($p) => (array) $p)->all() !== collect($d['priorities'])->sortBy('rank')->values()->all(),
                'standing_plan_changed' => $this->normalizedTemplate($d) !== $this->normalizedTemplate($this->export()),
            ];
        });
    }

    private function normalizedTemplate(array $d): array
    {
        $blocks = array_map(fn ($b) => ['day' => $b['day'], 'slot' => $b['slot'], 'priority_id' => $b['priority_id'], 'position' => $b['position'] ?? 1, 'duration_minutes' => $b['duration_minutes'] ?? $d['default_block_minutes'], 'notes' => $b['notes'] ?? ''], $d['standing_plan']['blocks']);
        usort($blocks, fn ($a, $b) => [$a['day'], $a['slot'], $a['position']] <=> [$b['day'], $b['slot'], $b['position']]);

        return ['notes' => $d['standing_plan']['notes'] ?? '', 'blocks' => $blocks];
    }

    public function apply(array $d, ?callable $after = null): string
    {
        $this->check($d);
        app(Planner::class)->backup();

        return DB::transaction(function () use ($d, $after) {
            $this->check($d);
            $planner = app(Planner::class);
            $old = DB::table('work_blocks')->get()->keyBy('id');
            $id = (string) Str::uuid();
            DB::table('plan_imports')->insert(['id' => $id, 'plan_name' => $d['plan_name'], 'snapshot' => json_encode($planner->snapshot()), 'document' => json_encode($d), 'created_at' => now(), 'updated_at' => now()]);
            $incoming = $planner->offsetPositions($this->incoming($d));
            DB::table('work_blocks')->where('completed', false)->delete();
            foreach (['priorities', 'template_blocks', 'plan_weeks', 'plan_settings'] as $table) {
                DB::table($table)->delete();
            }
            DB::table('plan_settings')->insert(['document' => json_encode(collect($d)->except(['workspace_id', 'base_revision', 'carryover', 'priorities', 'standing_plan', 'weeks'])->all() + ['standing_notes' => $d['standing_plan']['notes'] ?? ''])]);
            foreach ($d['priorities'] as $priority) {
                DB::table('priorities')->insert($priority);
            }
            foreach ($d['standing_plan']['blocks'] as $b) {
                DB::table('template_blocks')->insert(['day' => $b['day'], 'slot' => $b['slot'], 'position' => $b['position'] ?? 1, 'priority_id' => $b['priority_id'], 'duration_minutes' => $b['duration_minutes'] ?? $d['default_block_minutes'], 'notes' => $b['notes'] ?? '']);
            }
            foreach ($d['weeks'] as $week) {
                DB::table('plan_weeks')->insert(['week_start' => $week['week_start'], 'notes' => $week['notes'] ?? '', 'changes' => json_encode($week['changes'] ?? [])]);
            }
            foreach ($incoming as $b) {
                $previous = $old->get($b['id']);
                $parent = isset($b['split_from']) ? $old->get($b['split_from']) : null;
                DB::table('work_blocks')->insert(['id' => $b['id'], 'origin_id' => $previous->origin_id ?? $parent->origin_id ?? $b['id'], 'original_date' => $previous ? $previous->original_date : ($parent ? $parent->original_date : $b['date']), 'date' => $b['date'], 'slot' => $b['slot'], 'position' => $b['position'] ?? 1, 'priority_id' => $b['priority_id'], 'priority_name' => DB::table('priorities')->where('id', $b['priority_id'])->value('name'), 'duration_minutes' => $b['duration_minutes'] ?? $d['default_block_minutes'], 'notes' => $b['notes'] ?? '', 'completed' => false, 'revision' => $previous ? $previous->revision + 1 : 1, 'created_at' => $previous->created_at ?? now(), 'updated_at' => now()]);
            }
            // Completed history can occupy weeks omitted by the scheduler.
            foreach (DB::table('work_blocks')->where('completed', true)->whereNotNull('date')->pluck('date') as $date) {
                DB::table('plan_weeks')->insertOrIgnore(['week_start' => CarbonImmutable::parse($date)->startOfWeek()->toDateString(), 'notes' => 'Completed work history.', 'changes' => '[]']);
            }
            Workspace::advance();
            if ($after) {
                $after($id);
            }

            return $id;
        });
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['document' => $message]);
    }
}
