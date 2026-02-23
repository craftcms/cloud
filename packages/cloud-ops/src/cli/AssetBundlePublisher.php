<?php

namespace craft\cloud\ops\cli;

use Craft;
use craft\helpers\Console;
use Illuminate\Support\Collection;
use Iterator;
use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\Process;
use yii\base\BaseObject;

/**
 * @property-read array $assetBundleClasses
 */
class AssetBundlePublisher extends BaseObject
{
    public int $concurrency = 5;

    /** @var array<string> */
    public array $classNames;

    public ?string $to = null;

    /** @var Iterator<Process> */
    protected Iterator $processes;

    /** @var array<Process> */
    protected array $runningProcesses = [];

    public function __construct($config = [])
    {
        $config += [
            'classNames' => $this->getAssetBundleClasses(),
        ];

        parent::__construct($config);
    }

    public function init(): void
    {
        $this->processes = $this->getProcesses();
    }

    public function wait(): void
    {
        $this->startNext();

        while (count($this->runningProcesses) > 0) {
            foreach ($this->runningProcesses as $key => $process) {
                try {
                    $process->checkTimeout();
                    $isRunning = $process->isRunning();
                } catch (RuntimeException $e) {
                    $isRunning = false;
                }

                if (!$isRunning) {
                    Console::stdout($process->getErrorOutput());
                    Console::stdout($process->getOutput());
                    unset($this->runningProcesses[$key]);
                    $this->startNext();
                }
            }
            usleep(1000);
        }
    }

    protected function startNext(): void
    {
        while (count($this->runningProcesses) < $this->concurrency && $this->processes->valid()) {
            $process = $this->processes->current();
            $process->start();
            $this->runningProcesses[] = $process;
            $this->processes->next();
        }
    }

    protected function getProcesses(): \Generator
    {
        $processes = Collection::make($this->classNames)
            ->map(function(string $className) {
                $args = Collection::make([
                    PHP_BINARY,
                    Craft::$app->getRequest()->getScriptFile(),
                    'cloud-ops/asset-bundles/publish-bundle',
                    $className,
                ])->when($this->to, function(Collection $args) {
                    return $args->push('--to')->push($this->to);
                })->push('--quiet', '2>&1');

                return new Process($args->all());
            }
            );

        foreach ($processes->all() as $process) {
            yield $process;
        }
    }

    protected function getAssetBundleClasses(): array
    {
        $classMap = require(Craft::getAlias('@vendor/composer/autoload_classmap.php'));

        return Collection::make($classMap)
            ->keys()
            ->filter(function($className): bool {
                if (
                    preg_match('/^craft\\\(elements|fieldlayoutelements|gql|records)/', $className) ||
                    preg_match('/^yii\\\web\\\AssetBundle/', $className)
                ) {
                    return false;
                }

                // TODO: event
                return (bool) preg_match('/(asset|bundle)/i', $className);
            })
            ->values()
            ->all();
    }
}
