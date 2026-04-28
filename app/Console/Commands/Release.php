<?php

namespace App\Console\Commands;

use App\Support\Version;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class Release extends Command
{
    protected $signature = 'app:release
        {--month= : Override the YY.MM prefix (defaults to current UTC month)}
        {--write : Update the VERSION file with the computed release}
        {--tag : Create an annotated git tag (implies --write)}
        {--push : Push the new tag to origin (implies --tag)}
        {--patch-start=1 : Patch number to use when no prior tag exists this month}';

    protected $description = 'Compute and optionally cut the next YY.MM.patch release';

    public function handle(): int
    {
        if ($this->option('push') && ! $this->option('tag')) {
            $this->setOption('tag', true);
        }

        if ($this->option('tag') && ! $this->option('write')) {
            $this->setOption('write', true);
        }

        $month = (string) ($this->option('month') ?: Version::currentMonth());

        try {
            $next = Version::nextPatch($month, $this->existingTags(), (int) $this->option('patch-start'));
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $current = trim((string) @file_get_contents(base_path('VERSION'))) ?: '(none)';

        $this->line("Current VERSION : {$current}");
        $this->line("Next release    : {$next}");

        if (! $this->option('write')) {
            $this->info('Dry run — pass --write to update VERSION (and --tag / --push to release).');

            return self::SUCCESS;
        }

        file_put_contents(base_path('VERSION'), $next."\n");
        $this->info("Updated VERSION → {$next}");

        if ($this->option('tag')) {
            $tag = "v{$next}";

            $commit = $this->git(['commit', '-am', "chore(release): {$tag}"]);

            if (! $commit) {
                $this->warn('No changes to commit (VERSION may already be staged or unchanged).');
            }

            if (! $this->git(['tag', '-a', $tag, '-m', "Release {$tag}"])) {
                $this->error("Failed to create tag {$tag}");

                return self::FAILURE;
            }

            $this->info("Tagged {$tag}");

            if ($this->option('push')) {
                $this->git(['push']);
                $this->git(['push', 'origin', $tag]);
                $this->info("Pushed {$tag} to origin");
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    protected function existingTags(): array
    {
        $process = new Process(['git', 'tag', '--list', 'v*'], base_path());
        $process->run();

        if (! $process->isSuccessful()) {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode("\n", $process->getOutput())),
            static fn (string $line) => $line !== '',
        ));
    }

    /**
     * @param  array<int, string>  $args
     */
    protected function git(array $args): bool
    {
        $process = new Process(['git', ...$args], base_path());
        $process->run();

        $output = trim($process->getOutput().$process->getErrorOutput());

        if ($output !== '') {
            $this->line($output);
        }

        return $process->isSuccessful();
    }
}
