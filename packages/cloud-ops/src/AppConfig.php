<?php

namespace craft\cloud\ops;

use Craft;
use craft\helpers\App;

class AppConfig
{
    public function __construct(
        private readonly array $config,
        private readonly string $appType,
    ) {
    }

    public function getConfig(): array
    {
        if (!Module::isCraftCloud()) {
            return $this->config;
        }

        $config = $this->config;
        $config['id'] = $this->getId();

        if ($this->appType === 'web') {
            $config['components']['session'] = $this->getSessionConfig();
        }

        $config['components']['cache'] = $this->getCacheConfig();
        $config['components']['queue'] = $this->getQueueConfig();
        $config['components']['assetManager'] = $this->getAssetManagerConfig();
        $config['container']['definitions'] = array_merge(
            $config['container']['definitions'] ?? [],
            $this->getContainerDefinitions()
        );

        return $config;
    }

    private function getId(): string
    {
        $id = $this->config['id'] ?? null;

        if (!$id || $id === 'CraftCMS') {
            $projectId = App::env('CRAFT_CLOUD_PROJECT_ID');
            return "CraftCMS--$projectId";
        }

        return $id;
    }

    private function getSessionConfig(): \Closure
    {
        return function() {
            $config = App::sessionConfig();

            if ($this->tableExists(\craft\db\Table::PHPSESSIONS)) {
                $config['class'] = \yii\web\DbSession::class;
                $config['sessionTable'] = \craft\db\Table::PHPSESSIONS;
            }

            return Craft::createObject($config);
        };
    }

    private function getCacheConfig(): \Closure
    {
        return function() {
            $redisUrl = App::env('CRAFT_CLOUD_REDIS_URL');
            $defaultDuration = Craft::$app->getConfig()->getGeneral()->cacheDuration;

            if ($redisUrl) {
                return Craft::createObject([
                    'class' => \yii\redis\Cache::class,
                    'defaultDuration' => $defaultDuration,
                    'redis' => [
                        'class' => Redis::class,
                        'url' => $redisUrl,
                        'database' => 0,
                    ],
                ]);
            }

            if ($this->tableExists(\craft\db\Table::CACHE)) {
                return Craft::createObject([
                    'class' => \craft\cache\DbCache::class,
                    'cacheTable' => \craft\db\Table::CACHE,
                    'defaultDuration' => $defaultDuration,
                ]);
            }

            return Craft::createObject(App::cacheConfig());
        };
    }

    private function getQueueConfig(): \Closure
    {
        return function() {
            $sqsUrl = App::env('CRAFT_CLOUD_SQS_URL');
            $useQueue = $sqsUrl && (App::env('CRAFT_CLOUD_USE_QUEUE') ?? true);
            $region = App::env('CRAFT_CLOUD_REGION') ?? App::env('AWS_REGION');

            return Craft::createObject([
                'class' => \craft\queue\Queue::class,
                'ttr' => runtime\event\CliHandler::MAX_EXECUTION_SECONDS,
                'proxyQueue' => $useQueue ? [
                    'class' => queue\SqsQueue::class,
                    'ttr' => runtime\event\CliHandler::MAX_EXECUTION_SECONDS,
                    'url' => $sqsUrl,
                    'region' => $region,
                ] : null,
            ]);
        };
    }

    private function getAssetManagerConfig(): \Closure
    {
        return function() {
            $config = App::assetManagerConfig();
            $assetManagerClass = 'craft\\cloud\\web\\AssetManager';

            if (class_exists($assetManagerClass)) {
                $config['class'] = $assetManagerClass;
            }

            return Craft::createObject($config);
        };
    }

    private function getContainerDefinitions(): array
    {
        $definitions = [];

        $tmpFsClass = 'craft\\cloud\\fs\\TmpFs';
        if (class_exists($tmpFsClass)) {
            $definitions[\craft\fs\Temp::class] = $tmpFsClass;
        }

        $storageFsClass = 'craft\\cloud\\fs\\StorageFs';
        if (class_exists($storageFsClass)) {
            $definitions[\craft\debug\Module::class] = [
                'class' => \craft\debug\Module::class,
                'fs' => Craft::createObject($storageFsClass),
                'dataPath' => 'debug',
            ];
        }

        $definitions[\craft\log\MonologTarget::class] = function($container, $params, $config) {
            return new \craft\log\MonologTarget(['logContext' => false] + $config);
        };

        return $definitions;
    }

    private function tableExists(string $table, ?string $schema = null): bool
    {
        $db = Craft::$app->getDb();
        $params = [':tableName' => $db->getSchema()->getRawTableName($table)];

        if ($db->getIsMysql()) {
            $sql = 'SHOW TABLES LIKE :tableName';
        } else {
            $sql = <<<SQL
SELECT c.relname FROM pg_class c
INNER JOIN pg_namespace ns ON ns.oid = c.relnamespace
WHERE ns.nspname = :schemaName AND c.relkind IN ('r','v','m','f','p') AND c.relname = :tableName
SQL;
            $params[':schemaName'] = $schema ?? $db->getSchema()->defaultSchema;
        }

        return (bool) $db->createCommand($sql, $params)->queryScalar();
    }
}
