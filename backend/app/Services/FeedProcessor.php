<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class FeedProcessor
{
    public function process(): array
    {
        $files = app(FeedFiles::class);
        if (! $files->enabled()) {
            throw new \RuntimeException('Enable WORKBLOCKS_FEED_ENABLED to process feeds.');
        }
        $files->directories();
        $outcomes = [];
        app(PlannerLock::class)->run(function () use ($files) {
            $this->recover();
            $files->export();
        });
        $paths = glob($files->root().'/inbox/*.json') ?: [];
        usort($paths, fn ($a, $b) => [filemtime($a), basename($a)] <=> [filemtime($b), basename($b)]);
        foreach ($paths as $path) {
            $filename = basename($path);
            try {
                if (is_link($path)) {
                    throw new \RuntimeException('Symbolic links are not valid feeds.');
                }
                clearstatcache(true, $path);
                $signature = filesize($path).':'.filemtime($path).':'.hash_file('sha256', $path);
                $observation = DB::table('feed_observations')->where('filename', $filename)->first();
                $time = now()->getTimestampMs();
                if (! $observation || $observation->signature !== $signature) {
                    DB::table('feed_observations')->updateOrInsert(['filename' => $filename], ['signature' => $signature, 'stable_since' => $time]);

                    continue;
                }
                if ($time - $observation->stable_since < 2000) {
                    continue;
                }
                $outcomes[$filename] = app(PlannerLock::class)->run(function () use ($filename, $signature) {
                    $path = app(FeedFiles::class)->path('inbox', $filename);
                    clearstatcache(true, $path);
                    if (filesize($path).':'.filemtime($path).':'.hash_file('sha256', $path) !== $signature) {
                        return 'waiting';
                    }

                    return $this->receive($filename);
                });
            } catch (\Throwable $error) {
                report($error);
                $outcomes[$filename] = 'error: '.$error->getMessage();
            }
        }

        return $outcomes;
    }

    private function receive(string $filename): string
    {
        $files = app(FeedFiles::class);
        // Read raw bytes before parsing so malformed JSON can still be archived and explained.
        $path = $files->path('inbox', $filename);
        if (filesize($path) > 2000000) {
            $rawHash = hash_file('sha256', $path);

            return $this->rejectIncoming($filename, $rawHash, 'Feed files must be smaller than 2 MB.');
        }
        $file = $files->read('inbox', $filename);
        $key = hash('sha256', $filename."\0".$file['hash']);
        $existing = DB::table('feed_receipts')->where('key', $key)->first();
        if ($existing) {
            // A crash/retry must never apply an already committed feed a second time.
            $target = $files->root().'/'.$this->folder($existing->status).'/'.$existing->archive_name;
            if (! is_file($target)) {
                DB::table('feed_receipts')->where('key', $key)->update(['source_folder' => 'inbox', 'source_name' => $filename, 'delivered' => false]);
                $this->finish($key);
            } else {
                if (! rename($path, $target)) {
                    throw new \RuntimeException('Could not archive duplicate feed.');
                }
            }

            return $existing->status;
        }
        try {
            $d = app(PlanValidator::class)->validate(json_decode($file['raw'], true, 512, JSON_THROW_ON_ERROR));
            $summary = $this->preview($d);
        } catch (\Throwable $error) {
            if ($this->invalid($error)) {
                return $this->rejectIncoming($filename, $file['hash'], $this->message($error));
            }
            throw $error;
        }
        $reasons = [];
        if ($d['format_version'] === 1) {
            $reasons[] = 'Version 1 is a full plan reset.';
        }
        foreach ($summary['changes']['removed'] ?? [] as $b) {
            $reasons[] = 'Remove unfinished block '.$b['id'].' ('.$b['priority_id'].', '.$b['duration_minutes'].' minutes).';
        }
        $removedPriorities = array_diff(DB::table('priorities')->pluck('id')->all(), array_column($d['priorities'], 'id'));
        foreach ($removedPriorities as $id) {
            $reasons[] = 'Remove priority '.$id.'.';
        }
        if ($reasons) {
            $this->record($filename, $file['hash'], 'pending', implode("\n", $reasons), $summary);
            $this->finish($key);

            return 'pending';
        }
        $this->apply($filename, $file['hash'], $d, $summary, 'inbox', $filename);

        return 'applied';
    }

    public function preview(array $d): array
    {
        if ($d['format_version'] === 2) {
            return app(SchedulerExchange::class)->preview($d);
        }

        return ['mode' => 'reset', 'plan_name' => $d['plan_name'], 'priorities' => count($d['priorities']), 'weeks' => count($d['weeks']), 'blocks' => collect($d['weeks'])->sum(fn ($w) => count($w['blocks'])), 'unfinished_to_delete' => DB::table('work_blocks')->where('completed', false)->count(), 'completed_to_keep' => DB::table('work_blocks')->where('completed', true)->count()];
    }

    private function apply(string $filename, string $hash, array $d, array $summary, string $sourceFolder, string $sourceName): void
    {
        $previous = Workspace::current();
        $key = hash('sha256', $filename."\0".$hash);
        $archive = $this->archiveName('applied', $filename, $hash, $key);
        app(Planner::class)->import($d, function (string $importId) use ($filename, $hash, $summary, $previous, $key, $sourceFolder, $sourceName, $archive) {
            DB::table('feed_receipts')->updateOrInsert(['key' => $key], ['filename' => $filename, 'hash' => $hash, 'status' => 'applied', 'source_folder' => $sourceFolder, 'source_name' => $sourceName, 'archive_name' => $archive, 'delivered' => false, 'reason' => null, 'summary' => json_encode($summary), 'import_id' => $importId, 'workspace_id' => $previous->workspace_id, 'previous_revision' => $previous->revision, 'new_revision' => Workspace::current()->revision, 'created_at' => now()]);
        });
        // The receipt commits WITH the import; archiving can safely be retried after a crash.
        $this->finish($key);
        app(FeedFiles::class)->export();
    }

    private function rejectIncoming(string $filename, string $hash, string $reason): string
    {
        $key = $this->record($filename, $hash, 'rejected', $reason);
        $this->finish($key);

        return 'rejected';
    }

    private function record(string $filename, string $hash, string $status, string $reason, ?array $summary = null): string
    {
        $key = hash('sha256', $filename."\0".$hash);
        $state = Workspace::current();
        DB::table('feed_receipts')->updateOrInsert(['key' => $key], ['filename' => $filename, 'hash' => $hash, 'status' => $status, 'source_folder' => 'inbox', 'source_name' => $filename, 'archive_name' => $this->archiveName($status, $filename, $hash, $key), 'delivered' => false, 'reason' => $reason, 'summary' => $summary ? json_encode($summary) : null, 'workspace_id' => $state->workspace_id, 'previous_revision' => $state->revision, 'new_revision' => null, 'import_id' => null, 'created_at' => now()]);

        return $key;
    }

    private function archiveName(string $status, string $filename, string $hash, string $key): string
    {
        $path = app(FeedFiles::class)->root().'/'.$this->folder($status).'/'.$filename;
        if (file_exists($path) && (is_link($path) || hash_file('sha256', $path) !== $hash)) {
            return substr($filename, 0, -5).'--'.substr($key, 0, 12).'.json';
        }

        return $filename;
    }

    private function folder(string $status): string
    {
        return match ($status) {
            'pending' => 'inbox/pending', 'applied' => 'inbox/applied', 'rejected' => 'inbox/rejected'
        };
    }

    public function recover(): void
    {
        foreach (DB::table('feed_receipts')->where('delivered', false)->get() as $receipt) {
            try {
                $this->finish($receipt->key);
            } catch (\Throwable $error) {
                report($error);
            }
        }
    }

    public function finish(string $key): void
    {
        $files = app(FeedFiles::class);
        $files->directories();
        $r = DB::table('feed_receipts')->where('key', $key)->first();
        if (! $r) {
            throw new \RuntimeException('Feed receipt missing.');
        }
        $folder = $this->folder($r->status);
        $source = $files->root().'/'.$r->source_folder.'/'.$r->source_name;
        $target = $files->root().'/'.$folder.'/'.$r->archive_name;
        if (is_link($source) || is_link($target)) {
            throw new \RuntimeException('Refusing a symbolic link in feed archive.');
        }
        if (is_file($source) && $source !== $target) {
            if (hash_file('sha256', $source) !== $r->hash) {
                throw new \RuntimeException('Feed contents changed before archiving.');
            }
            if (! rename($source, $target)) {
                throw new \RuntimeException('Could not move feed to '.$folder);
            }
        }
        if (! is_file($target) || hash_file('sha256', $target) !== $r->hash) {
            throw new \RuntimeException('Archived feed is missing or changed.');
        }
        if ($r->status === 'applied') {
            $files->atomic($target.'.result.json', json_encode(['import_id' => $r->import_id, 'previous_revision' => $r->previous_revision, 'new_revision' => $r->new_revision, 'applied_at' => CarbonImmutable::parse($r->created_at)->toIso8601String(), 'summary' => json_decode($r->summary, true)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL);
        } else {
            $files->atomic($target.'.reason.txt', $r->reason."\nCurrent revision: ".Workspace::current()->revision."\n");
        }
        DB::table('feed_receipts')->where('key', $key)->update(['source_folder' => $folder, 'source_name' => $r->archive_name, 'delivered' => true]);
        DB::table('feed_observations')->where('filename', $r->filename)->delete();
    }

    public function pending(): array
    {
        $state = Workspace::current();

        return DB::table('feed_receipts')->where('status', 'pending')->orderBy('created_at')->get()->map(fn ($r) => ['id' => $r->key, 'filename' => $r->filename, 'reason' => $r->reason, 'summary' => json_decode($r->summary, true), 'created_at' => $r->created_at, 'outdated' => $r->workspace_id !== $state->workspace_id || $r->previous_revision !== $state->revision])->all();
    }

    public function pendingPreview(string $key): array
    {
        [$r, $d] = $this->readPending($key);

        return ['document' => $d, 'preview' => $this->preview($d), 'feed_key' => $key];
    }

    public function approve(string $key): void
    {
        try {
            [$r, $d] = $this->readPending($key);
            $summary = $this->preview($d);
        } catch (\Throwable $error) {
            if ($this->invalid($error)) {
                $this->rejectPending($key, $this->message($error));
            }
            throw $error;
        }
        $this->apply($r->filename, $r->hash, $d, $summary, $r->source_folder, $r->source_name);
    }

    private function readPending(string $key): array
    {
        $r = DB::table('feed_receipts')->where('key', $key)->where('status', 'pending')->first();
        abort_unless($r, 404, 'Pending feed not found.');
        $state = Workspace::current();
        abort_unless($state->workspace_id === $r->workspace_id && $state->revision === $r->previous_revision, 409, 'This pending feed is outdated. Ask the scheduler to regenerate it from outbox/current.json.');
        $file = app(FeedFiles::class)->read($r->source_folder, $r->source_name);
        abort_unless(hash_equals($r->hash, $file['hash']), 422, 'Pending feed contents changed; submit a new file.');
        $d = app(PlanValidator::class)->validate(json_decode($file['raw'], true, 512, JSON_THROW_ON_ERROR));

        return [$r, $d];
    }

    public function discard(string $key): void
    {
        $this->rejectPending($key, 'discarded by user');
    }

    private function rejectPending(string $key, string $reason): void
    {
        $r = DB::table('feed_receipts')->where('key', $key)->where('status', 'pending')->first();
        abort_unless($r, 404, 'Pending feed not found.');
        DB::table('feed_receipts')->where('key', $key)->update(['status' => 'rejected', 'reason' => $reason, 'archive_name' => $this->archiveName('rejected', $r->filename, $r->hash, $key), 'delivered' => false]);
        $this->finish($key);
    }

    private function invalid(\Throwable $e): bool
    {
        return $e instanceof ValidationException || $e instanceof \JsonException || ($e instanceof HttpExceptionInterface && in_array($e->getStatusCode(), [409, 422], true));
    }

    private function message(\Throwable $e): string
    {
        return $e instanceof ValidationException ? implode("\n", array_merge(...array_values($e->errors()))) : $e->getMessage();
    }
}
