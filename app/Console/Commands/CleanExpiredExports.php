<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CleanExpiredExports extends Command
{
    protected $signature = 'exports:clean {--hours=24 : Maximum age in hours}';

    protected $description = 'Delete expired album export zip files';

    public function handle(): int
    {
        $maxAge = (int) $this->option('hours');
        $cutoff = time() - ($maxAge * 3600);
        $deleted = 0;

        $disk = Storage::disk('local');
        $directories = $disk->directories('exports/gallery');

        foreach ($directories as $directory) {
            $files = $disk->files($directory);

            foreach ($files as $file) {
                if ($disk->lastModified($file) < $cutoff) {
                    $disk->delete($file);
                    $deleted++;
                }
            }

            // Remove empty directories
            if (empty($disk->files($directory))) {
                $disk->deleteDirectory($directory);
            }
        }

        $this->info("Deleted {$deleted} expired export file(s).");

        return 0;
    }
}
