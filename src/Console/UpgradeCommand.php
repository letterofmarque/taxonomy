<?php

declare(strict_types=1);

namespace Marque\Taxonomy\Console;

use Illuminate\Console\Command;
use Marque\Taxonomy\Definitions\ContentType;
use Marque\Taxonomy\Definitions\Loader;
use Marque\Taxonomy\Definitions\Migration;
use Marque\Taxonomy\Exceptions\UpgradeRefusedException;
use Marque\Taxonomy\Models\Classification;
use Marque\Taxonomy\Models\InstalledVersion;
use Marque\Taxonomy\Services\Upgrader;

/**
 * The explicit step standing between `composer update` and a reshaped
 * catalogue.
 *
 * Dan's framing: *"this isn't an easy on them hard on us thing, this is
 * protecting some people from themselves, we need to make it harder for them
 * to fuck things up like that."*
 *
 * The confirmation is only half the protection — the other half is the report
 * above it. "Are you sure?" with no numbers is the dialog everyone clicks
 * through; "this will orphan 4,000 torrents" is a decision.
 */
final class UpgradeCommand extends Command
{
    protected $signature = 'marque:taxonomy:upgrade {content_type : The content type to upgrade}';

    protected $description = 'Review and apply a pending taxonomy content-type upgrade';

    public function handle(Loader $loader, Upgrader $upgrader): int
    {
        $name = (string) $this->argument('content_type');

        $types = $loader->load();

        if (! isset($types[$name])) {
            $this->components->error(sprintf('No content type "%s" is defined.', $name));

            return self::FAILURE;
        }

        $type = $types[$name];
        $installed = InstalledVersion::for($name);

        if ($installed === null || $installed->version >= $type->version) {
            $this->components->info(sprintf('%s is already at v%d. Nothing to do.', $name, $type->version));

            return self::SUCCESS;
        }

        $path = $type->migrationPathFrom($installed->version);

        if ($path === null) {
            // A refusal, not a warning. The author never said what their
            // change does to existing data, and the admin is the wrong person
            // to guess.
            $this->components->error(UpgradeRefusedException::noPath(
                $name,
                $installed->version,
                $type->version,
            )->getMessage());

            return self::FAILURE;
        }

        $this->report($type, $installed->version, $path);

        if (! $this->confirm('Apply this upgrade?', false)) {
            $this->components->info('Nothing was changed.');

            return self::FAILURE;
        }

        $upgrader->apply($type);

        $this->components->info(sprintf('%s upgraded to v%d.', $name, $type->version));

        return self::SUCCESS;
    }

    /**
     * @param  list<Migration>  $path
     */
    private function report(ContentType $type, int $from, array $path): void
    {
        $affected = Classification::where('content_type', $type->name)->count();

        $this->newLine();
        $this->line(sprintf(
            '  <options=bold>%s</> has an update available (v%d → v%d).',
            $type->name,
            $from,
            $type->version,
        ));
        $this->line(sprintf('  <fg=yellow>%d torrent(s)</> are classified under it.', $affected));
        $this->newLine();

        $destructive = false;

        foreach ($path as $step) {
            $this->line(sprintf('  <fg=gray>v%d → v%d</>', $step->from, $step->to()));

            foreach ($step->renamedLevels as $rename) {
                // Worth stating plainly: this is the case the whole mechanism
                // exists for, and an admin should see that nothing is lost.
                $this->line(sprintf(
                    '    <fg=green>rename</> level %s → %s <fg=gray>(classifications preserved)</>',
                    $rename['from'],
                    $rename['to'],
                ));
            }

            foreach (array_keys($step->addedLevels) as $level) {
                $this->line(sprintf('    <fg=green>add</> level %s', $level));
            }

            foreach ($step->addedFacets as $facet) {
                $this->line(sprintf('    <fg=green>add</> facet %s', $facet));
            }

            foreach ($step->removedLevels as $level) {
                $destructive = true;
                $this->line(sprintf('    <fg=red>remove</> level %s', $level));
            }

            foreach ($step->removedFacets as $facet) {
                $destructive = true;
                $this->line(sprintf('    <fg=red>remove</> facet %s', $facet));
            }
        }

        $this->newLine();

        if ($destructive) {
            $this->components->warn(sprintf(
                'This will orphan classifications for up to %d torrent(s). '
                .'Nothing is deleted — orphaned rows remain and are recoverable — but those torrents '
                .'will no longer appear under the removed level.',
                $affected,
            ));
        }
    }
}
