<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

class PlanValidator
{
    public function validate(mixed $document): array
    {
        $version = is_array($document) ? ($document['format_version'] ?? null) : null;
        if (! in_array($version, [1, 2], true)) {
            $this->fail('Unsupported format_version. Use 1 for a reset or 2 for a scheduler update.');
        }
        $schema = json_decode(file_get_contents(base_path('../contracts/plan-v'.$version.'.schema.json')));
        $result = (new Validator)->validate(json_decode(json_encode($document)), $schema);
        if (! $result->isValid()) {
            throw ValidationException::withMessages(['document' => array_values((new ErrorFormatter)->formatFlat($result->error()))]);
        }
        $d = json_decode(json_encode($document), true);
        $ids = array_column($d['priorities'], 'id');
        $ranks = array_column($d['priorities'], 'rank');
        if (count(array_unique($ids)) !== count($ids) || count(array_unique($ranks)) !== count($ranks)) {
            $this->fail('Priority IDs and ranks must be unique.');
        }
        if (! in_array($d['timezone'], \DateTimeZone::listIdentifiers(), true)) {
            $this->fail('Unknown IANA timezone.');
        }
        $weeks = [];
        $blockIds = [];
        $this->blocks($d['standing_plan']['blocks'], $ids, 'day');
        foreach ($d['weeks'] as $week) {
            if (! CarbonImmutable::parse($week['week_start'])->isMonday() || isset($weeks[$week['week_start']])) {
                $this->fail('Each week_start must be a unique Monday.');
            }
            $weeks[$week['week_start']] = true;
            foreach ($week['blocks'] as $block) {
                if (CarbonImmutable::parse($block['date'])->startOfWeek()->toDateString() !== $week['week_start']) {
                    $this->fail('A block date falls outside its week.');
                }
                if ($block['date'] < $d['effective_from']) {
                    $this->fail('Blocks cannot precede effective_from. Keep the original plan start to retain overdue work.');
                }
            }
            $this->blocks($week['blocks'], $ids, 'date');
            if ($version === 2) {
                $this->uniqueBlockIds($week['blocks'], $blockIds);
            }
        }
        if ($version === 2) {
            $this->blocks($d['carryover'], $ids, null);
            $this->uniqueBlockIds($d['carryover'], $blockIds);
        }

        return $d;
    }

    private function blocks(array $blocks, array $ids, ?string $dayField): void
    {
        $positions = [];
        foreach ($blocks as $block) {
            if (! in_array($block['priority_id'], $ids, true)) {
                $this->fail('A block references an unknown priority_id.');
            }
            $key = ($dayField ? $block[$dayField] : 'carryover').'/'.$block['slot'].'/'.($block['position'] ?? 1);
            if (isset($positions[$key])) {
                $this->fail('Block positions must be unique within each day and slot (including carryover).');
            }
            $positions[$key] = true;
        }
    }

    private function uniqueBlockIds(array $blocks, array &$ids): void
    {
        foreach ($blocks as $block) {
            if (isset($ids[$block['id']])) {
                $this->fail('Block IDs must be unique across all weeks and carryover.');
            }
            $ids[$block['id']] = true;
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['document' => $message]);
    }
}
