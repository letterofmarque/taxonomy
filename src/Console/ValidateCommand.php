<?php

declare(strict_types=1);

namespace Marque\Taxonomy\Console;

use Illuminate\Console\Command;
use Marque\Taxonomy\Definitions\ContentType;
use Marque\Taxonomy\Definitions\Loader;
use Marque\Taxonomy\Exceptions\InvalidDefinitionException;
use Marque\Taxonomy\Services\Upgrader;

/**
 * Check every taxonomy definition without loading them into a running tracker.
 *
 * Deliberately delegates to the same Loader the application uses rather than
 * re-implementing the checks. A validator that is laxer than the loader is
 * worse than no validator — it tells an admin their definitions are fine and
 * then the tracker refuses them.
 *
 * Exits non-zero on failure so it works as a deploy gate.
 */
final class ValidateCommand extends Command
{
    protected $signature = 'marque:taxonomy:validate';

    protected $description = 'Validate taxonomy content-type definitions';

    public function __construct(private readonly Upgrader $upgrader)
    {
        parent::__construct();
    }

    public function handle(Loader $loader): int
    {
        try {
            $types = $loader->load();
        } catch (InvalidDefinitionException $e) {
            // Printed line by line rather than through components->error(),
            // which renders only the first line of a multi-line message — and
            // the first line here is the filename, with every actual reason on
            // the lines after it. An admin told "bad.yaml is invalid" and
            // nothing else is no better off than before they ran this.
            $lines = explode("\n", $e->getMessage());

            $this->components->error(array_shift($lines));

            foreach ($lines as $line) {
                $this->line('  <fg=red>'.$line.'</>');
            }

            $this->newLine();
            $this->line('  No definitions were loaded. The tracker keeps running on its previous ones.');

            return self::FAILURE;
        }

        if ($types === []) {
            $this->components->info('No taxonomy definitions found.');

            return self::SUCCESS;
        }

        foreach ($types as $type) {
            $this->line(sprintf(
                '  <fg=green>✓</> %s <fg=gray>(v%d)</> — %s',
                $type->name,
                $type->version,
                $this->summarise($type),
            ));
        }

        foreach ($loader->shadowedUpdates() as $shadowed) {
            $this->newLine();
            $this->components->warn(sprintf(
                '%s has an update available (v%d → v%d). Your override is in force; the package version is not applied.',
                $shadowed['content_type'],
                $shadowed['app_version'],
                $shadowed['package_version'],
            ));
        }

        // Pending version upgrades surface here as well as in the upgrade
        // command itself: an admin running the routine check should learn a
        // definition changed underneath them without having to already know
        // the upgrade command exists.
        foreach ($this->upgrader->pending(array_values($types)) as $pending) {
            $this->newLine();

            $message = sprintf(
                '%s has an update available (v%d → v%d). %d torrent(s) are classified under it. ',
                $pending['content_type'],
                $pending['from'],
                $pending['to'],
                $pending['affected'],
            );

            $message .= $pending['path']
                ? sprintf('Run `marque:taxonomy:upgrade %s` to review and apply.', $pending['content_type'])
                : 'It declares no migration path, so it cannot be applied — that is the package author\'s to fix.';

            $this->components->warn($message);
        }

        $this->newLine();
        $this->components->info(sprintf('%d content type(s) valid.', count($types)));

        return self::SUCCESS;
    }

    private function summarise(ContentType $type): string
    {
        $levels = implode(' → ', $type->levelNames());

        return $type->facets === []
            ? $levels
            : sprintf('%s  <fg=gray>[%s]</>', $levels, implode(', ', $type->facets));
    }
}
