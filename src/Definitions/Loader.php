<?php

declare(strict_types=1);

namespace Marque\Taxonomy\Definitions;

use Marque\Taxonomy\Exceptions\InvalidDefinitionException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads YAML content-type definitions from package sources and the app, and
 * hands back definition objects.
 *
 * Two rules govern everything here.
 *
 * **The app wins.** Packages ship versioned defaults; the app overrides them by
 * content-type name. A `composer update` therefore never silently reshapes a
 * definition an admin has taken ownership of — but the admin is told a newer
 * version exists (see shadowedUpdates()), because silently sitting on a stale
 * definition is its own trap.
 *
 * **All or nothing.** One bad file fails the entire load. That looks harsh
 * until you consider the alternative: a tracker running a partial taxonomy,
 * with torrents classified under a level that quietly stopped existing. A
 * refusal leaves the previous definitions in force, which an admin can
 * actually reason about.
 *
 * A pure PHP seam — no framework coupling, no view layer, nothing that stops
 * this composing (Spec #83's finding).
 */
final class Loader
{
    /** @var list<array{content_type: string, app_version: int, package_version: int}> */
    private array $shadowed = [];

    /**
     * @param  list<string>  $packagePaths  Searched first; later paths win over earlier ones.
     * @param  string|null  $appPath  Searched last, and beats every package.
     */
    public function __construct(
        private readonly array $packagePaths = [],
        private readonly ?string $appPath = null,
        private readonly ?Validator $validator = null,
    ) {}

    /**
     * @return array<string, ContentType> Keyed by content type name.
     */
    public function load(): array
    {
        $this->shadowed = [];

        $packages = $this->loadFrom($this->packagePaths);
        $app = $this->loadFrom($this->appPath === null ? [] : [$this->appPath]);

        foreach ($app as $name => $definition) {
            if (! isset($packages[$name])) {
                continue;
            }

            $packageVersion = $this->versionOf($packages[$name]['data']);
            $appVersion = $this->versionOf($definition['data']);

            // Told, not applied. The upgrade itself is CP5's explicit command,
            // which reports what changes and how many torrents are affected
            // before touching anything.
            if ($packageVersion > $appVersion) {
                $this->shadowed[] = [
                    'content_type' => $name,
                    'app_version' => $appVersion,
                    'package_version' => $packageVersion,
                ];
            }
        }

        $merged = [...$packages, ...$app];

        return array_map(
            fn (array $entry): ContentType => ContentType::fromArray($entry['data']),
            $merged,
        );
    }

    /**
     * Content types where the admin's override is behind the package's shipped
     * version. Reported so a stale override is visible rather than silent.
     *
     * @return list<array{content_type: string, app_version: int, package_version: int}>
     */
    public function shadowedUpdates(): array
    {
        return $this->shadowed;
    }

    /**
     * @param  list<string>  $paths
     * @return array<string, array{data: array<string, mixed>, file: string}>
     */
    private function loadFrom(array $paths): array
    {
        $found = [];

        foreach ($paths as $path) {
            // A tracker with no app-level overrides has no directory at all.
            // That is the normal case, not an error.
            if (! is_dir($path)) {
                continue;
            }

            foreach ($this->filesIn($path) as $file) {
                $data = $this->parse($file);
                $name = (string) ($data['content_type'] ?? '');

                // Across sources a repeat is an override and legitimate.
                // Within one source it is ambiguous — nothing says which file
                // should win — so refuse rather than pick arbitrarily.
                if ($name !== '' && isset($found[$name])) {
                    throw InvalidDefinitionException::duplicate($name, $found[$name]['file'], $file);
                }

                $errors = ($this->validator ?? new Validator)->validate($data);

                if ($errors !== []) {
                    throw InvalidDefinitionException::invalid($file, $errors);
                }

                $found[$name] = ['data' => $data, 'file' => $file];
            }
        }

        return $found;
    }

    /**
     * @return list<string>
     */
    private function filesIn(string $path): array
    {
        // Both extensions are in common use; an admin should not have to
        // discover which one we happened to pick.
        $files = [
            ...(glob($path.'/*.yaml') ?: []),
            ...(glob($path.'/*.yml') ?: []),
        ];

        // Deterministic order, so a duplicate-declaration error names the same
        // two files every run rather than depending on the filesystem.
        sort($files);

        return $files;
    }

    /**
     * @return array<string, mixed>
     */
    private function parse(string $file): array
    {
        try {
            $parsed = Yaml::parseFile($file);
        } catch (ParseException $e) {
            throw InvalidDefinitionException::unparsable($file, $e->getMessage());
        }

        if (! is_array($parsed) || array_is_list($parsed)) {
            throw InvalidDefinitionException::notAMap($file);
        }

        return $parsed;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function versionOf(array $data): int
    {
        return is_int($data['version'] ?? null) ? $data['version'] : 1;
    }
}
