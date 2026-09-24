<?php

namespace App\Console\Commands;

use App\Domain\Identity\BreachedPasswords;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/**
 * Downloads Have I Been Pwned's SHA-1 list into the offline range files
 * BreachedPasswords reads (05.13 §5.4). Scheduled quarterly
 * (routes/console.php).
 *
 * This is the only moment the platform contacts HIBP, and it sends
 * nothing derived from a password: it walks all 16^5 public ranges
 * (00000–FFFFF), which is how HIBP distributes the full list. That is
 * ~1,048,576 requests and tens of gigabytes on disk; it runs for hours.
 *
 * The new list is built beside the live one and swapped in only when
 * every range has been fetched — a failed refresh leaves the previous
 * list in place, never a partial one. `--prefix` fetches only the given
 * ranges into the live directory, for development and tests.
 */
class RefreshBreachedPasswords extends Command
{
    protected $signature = 'auth:refresh-breached-passwords
        {--prefix=* : Fetch only these 5-hex-digit ranges, into the live directory}
        {--concurrency=64 : Requests in flight at once}';

    protected $description = 'Refresh the offline Have I Been Pwned breached-password list (05.13 §5.4)';

    private const RANGES = 1 << 20;

    private const ATTEMPTS = 3;

    public function handle(): int
    {
        $live = (new BreachedPasswords)->directory();
        $source = (string) config('auth.breached_passwords.source');
        $concurrency = max(1, (int) $this->option('concurrency'));

        $only = [];
        foreach ((array) $this->option('prefix') as $prefix) {
            if (is_string($prefix) && $prefix !== '') {
                $only[] = strtoupper($prefix);
            }
        }
        foreach ($only as $prefix) {
            if (preg_match('/^[0-9A-F]{5}$/', $prefix) !== 1) {
                $this->error("Not a 5-hex-digit prefix: {$prefix}");

                return self::INVALID;
            }
        }

        $target = $only === [] ? $live.'.incoming' : $live;
        File::ensureDirectoryExists($target);

        $prefixes = $only !== [] ? $only : null;
        $total = $prefixes === null ? self::RANGES : count($prefixes);
        $bar = $this->output->createProgressBar($total);

        for ($offset = 0; $offset < $total; $offset += $concurrency) {
            $batch = [];
            for ($i = $offset; $i < min($offset + $concurrency, $total); $i++) {
                $batch[] = $prefixes === null ? sprintf('%05X', $i) : $prefixes[$i];
            }

            if (! $this->fetchBatch($source, $target, $batch)) {
                $this->newLine();
                $this->error('Refresh abandoned; the previous list is still in use.');

                return self::FAILURE;
            }

            $bar->advance(count($batch));
        }

        $bar->finish();
        $this->newLine();

        if ($only === []) {
            $this->swapIn($live, $target);
        }

        $this->info("Breached-password list refreshed: {$total} ranges in {$live}.");

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $prefixes
     */
    private function fetchBatch(string $source, string $target, array $prefixes): bool
    {
        $pending = $prefixes;

        for ($attempt = 1; $attempt <= self::ATTEMPTS && $pending !== []; $attempt++) {
            /** @var array<string, Response|\Throwable> $responses */
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn (string $prefix) => $pool->as($prefix)->timeout(30)->get($source.$prefix),
                $pending,
            ));

            $failed = [];
            foreach ($pending as $prefix) {
                $response = $responses[$prefix] ?? null;
                if ($response instanceof Response && $response->successful()) {
                    File::put("{$target}/{$prefix}.txt", $response->body());
                } else {
                    $failed[] = $prefix;
                }
            }
            $pending = $failed;

            if ($pending !== []) {
                sleep($attempt);
            }
        }

        return $pending === [];
    }

    private function swapIn(string $live, string $incoming): void
    {
        $previous = $live.'.previous';
        File::deleteDirectory($previous);

        if (is_dir($live)) {
            rename($live, $previous);
        }
        rename($incoming, $live);
        File::deleteDirectory($previous);
    }
}
