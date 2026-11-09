<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class FeedFiles
{
    public function enabled(): bool
    {
        return (bool) config('workblocks.feed_enabled');
    }

    public function root(): string
    {
        return rtrim(config('workblocks.feed_directory'), '/');
    }

    public function directories(): void
    {
        if (is_link($this->root())) {
            throw new \RuntimeException('Feed directory cannot be a symbolic link.');
        }
        foreach (['outbox', 'inbox', 'inbox/applied', 'inbox/rejected', 'inbox/pending'] as $folder) {
            $path = $this->root().'/'.$folder;
            if (is_link($path)) {
                throw new \RuntimeException('Feed folders cannot be symbolic links.');
            }if (! is_dir($path) && ! mkdir($path, 0770, true) && ! is_dir($path)) {
                throw new \RuntimeException('Could not create '.$folder);
            }
        }
    }

    public function atomic(string $path, string $contents): void
    {
        $tmp = $path.'.tmp';
        if (is_link($tmp) || is_link($path)) {
            throw new \RuntimeException('Refusing a symbolic link in feed output.');
        }
        if (file_put_contents($tmp, $contents, LOCK_EX) === false || ! rename($tmp, $path)) {
            throw new \RuntimeException('Could not write feed output.');
        }
    }

    public function export(): void
    {
        if (! $this->enabled()) {
            return;
        }
        $this->directories();
        $h = fopen(storage_path('framework/feed-export.lock'), 'c');
        if (! $h || ! flock($h, LOCK_EX)) {
            throw new \RuntimeException('Could not lock feed export.');
        }
        try {
            if (! DB::table('plan_settings')->exists()) {
                $current = $this->root().'/outbox/current.json';
                if (is_link($current)) {
                    throw new \RuntimeException('Refusing symbolic link in outbox.');
                } if (is_file($current) && ! unlink($current)) {
                    throw new \RuntimeException('Could not remove obsolete export.');
                }

return;
            }
            $d = app(SchedulerExchange::class)->export();
            $this->atomic($this->root().'/outbox/current.json', json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL);
        } finally {
            flock($h, LOCK_UN);
            fclose($h);
        }
    }

    public function path(string $folder, string $filename): string
    {
        if (! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{0,220}\.json\z/D', $filename)) {
            throw new \RuntimeException('Invalid feed filename.');
        }
        $this->directories();
        $path = $this->root().'/'.$folder.'/'.$filename;
        if (is_link($path) || ! is_file($path)) {
            throw new \RuntimeException('Feed file is missing or is a symbolic link.');
        }

        return $path;
    }

    public function read(string $folder, string $filename): array
    {
        $path = $this->path($folder, $filename);
        clearstatcache(true, $path);
        if (filesize($path) > 2000000) {
            throw new \RuntimeException('Feed files must be smaller than 2 MB.');
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException('Could not read feed.');
        }

        return ['raw' => $raw, 'hash' => hash('sha256',$raw), 'path' => $path];
    }
}
