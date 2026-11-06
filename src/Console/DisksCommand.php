<?php

declare(strict_types=1);

namespace craft\cloud\Console;

use craft\cloud\Disks;
use CraftCms\Cms\ProjectConfig\ProjectConfig;
use Illuminate\Console\Command;

/**
 * Prints `config/filesystems.php` disks for the project's 3.x Craft Cloud filesystems. Craft's upgrade needs a disk
 * for each filesystem before it can move volumes onto disks with the same handles.
 */
class DisksCommand extends Command
{
    private const array UNSUPPORTED_SETTINGS = ['baseUrl', 'localFsPath', 'localFsUrl'];

    #[\Override]
    protected $signature = 'cloud:disks';

    #[\Override]
    protected $description = 'Prints Laravel disks for the project’s Craft Cloud filesystems, to add to config/filesystems.php before upgrading.';

    public function handle(ProjectConfig $projectConfig): int
    {
        $filesystems = (array) $projectConfig->get('fs');
        $disks = self::fromFilesystems($filesystems);

        if ($disks === []) {
            $this->components->info('There are no Craft Cloud filesystems in the project config.');

            return self::SUCCESS;
        }

        $this->components->info('Add these to the `disks` array in config/filesystems.php:');
        $this->line(self::render($disks));

        foreach (array_keys($disks) as $handle) {
            $settings = array_filter($filesystems[$handle]['settings'] ?? []);

            foreach (array_intersect(self::UNSUPPORTED_SETTINGS, array_keys($settings)) as $setting) {
                $this->components->warn(
                    "`{$handle}` has a `{$setting}` setting, which Craft Cloud disks don’t support, so it won’t carry over.",
                );
            }
        }

        return self::SUCCESS;
    }

    /**
     * Returns `craft-cloud` disk configs for 3.x's `AssetsFs` filesystems, by handle. `expires` isn't applied yet, but
     * is kept for when object metadata is.
     *
     * @param array<string,mixed> $filesystems The `fs` project config
     * @return array<string,array<string,string>>
     */
    public static function fromFilesystems(array $filesystems): array
    {
        $disks = [];

        foreach ($filesystems as $handle => $filesystem) {
            if (!is_array($filesystem) || ($filesystem['type'] ?? null) !== 'craft\cloud\fs\AssetsFs') {
                continue;
            }

            $settings = (array) ($filesystem['settings'] ?? []);

            $disks[(string) $handle] = array_filter(
                [
                    'driver' => Disks::DRIVER,
                    'subpath' => $settings['subpath'] ?? null,
                    'expires' => $settings['expires'] ?? null,
                ],
                static fn(mixed $value): bool => is_string($value) && $value !== '',
            );
        }

        return $disks;
    }

    /**
     * @param array<string,array<string,string>> $disks
     */
    private static function render(array $disks): string
    {
        $lines = [];

        foreach ($disks as $handle => $disk) {
            $lines[] = var_export($handle, return: true) . ' => [';

            foreach ($disk as $key => $value) {
                $lines[] = '    ' . var_export($key, return: true) . ' => ' . var_export($value, return: true) . ',';
            }

            $lines[] = '],';
        }

        return implode("\n", $lines);
    }
}
