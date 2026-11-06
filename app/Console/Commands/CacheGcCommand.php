<?php

namespace App\Console\Commands;

use App\Cache\CacheGc;
use Illuminate\Console\Command;
use MongoDB\Client;

class CacheGcCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'jikan:cache-gc
                            {--force : Bypass the min-interval throttle}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run the MongoDB cache garbage collector (retention sweep + size guard) to keep the free-tier cluster under its storage cap';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $client = new Client(env('MONGODB_URI'));
        $collection = $client->selectCollection(
            env('MONGODB_DATABASE', 'jikan'),
            env('MONGODB_CACHE_COLLECTION', 'cache')
        );

        $gc = new CacheGc($collection, (string) env('CACHE_PREFIX', 'jikan'));
        $report = $gc->maybeRun((bool) $this->option('force'));

        if ($report === null) {
            $this->info('GC skipped (min interval not reached; use --force to run anyway)');
            return 0;
        }

        $this->info(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return 0;
    }
}
