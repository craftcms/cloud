<?php

namespace craft\cloud;

use Closure;
use Craft;
use craft\cache\DbCache;
use craft\cachecascade\CascadeCache;
use craft\cloud\fs\StorageFs;
use craft\cloud\fs\TmpFs;
use craft\cloud\web\AssetManager;
use craft\db\Table;
use craft\helpers\App;
use yii\caching\ArrayCache;
use yii\redis\Cache as RedisCache;

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

    private function getSessionConfig(): Closure
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

    private function getCacheConfig(): Closure
    {
        return function() {
            $defaultDuration = Craft::$app->getConfig()->getGeneral()->cacheDuration;
            $valkey = $this->resolveValkeyEndpoint();
            $primaryCache = $valkey ? [
                'class' => RedisCache::class,
                'defaultDuration' => $defaultDuration,
                'redis' => [
                    'class' => Redis::class,
                    'url' => $valkey,
                    'database' => 0,
                ],
            ] : [
                'class' => DbCache::class,
                'cacheTable' => Table::CACHE,
                'defaultDuration' => $defaultDuration,
            ];

            return Craft::createObject([
                'class' => CascadeCache::class,
                'caches' => [
                    $primaryCache,
                    ['class' => ArrayCache::class],
                ],
            ]);
        };
    }

    private function resolveValkeyEndpoint(): ?string
    {
        $srv = App::env('CRAFT_CLOUD_CACHE_SRV');

        if ($srv) {
            $record = dns_get_record($srv, DNS_SRV);

            if (!empty($record)) {
                return 'redis://' . $record[0]['target'] . ':' . $record[0]['port'];
            }
        }

        // Deprecated. We are moving to CRAFT_CLOUD_CACHE_SRV for both Fargate and ECS.
        // Once every website is moved over, we can disregared this fallback.
        return App::env('CRAFT_CLOUD_REDIS_URL');
    }

    private function getQueueConfig(): Closure
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

    private function getAssetManagerConfig(): Closure
    {
        return function() {
            $config = App::assetManagerConfig();
            $config['class'] = AssetManager::class;
            return Craft::createObject($config);
        };
    }

    private function getContainerDefinitions(): array
    {
        $definitions = [];

        $definitions[\craft\fs\Temp::class] = TmpFs::class;

        $definitions[\craft\debug\Module::class] = [
            'class' => \craft\debug\Module::class,
            'fs' => new StorageFs(),
            'dataPath' => 'debug',
        ];

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
