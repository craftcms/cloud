<?php

namespace craft\cloud\tests\unit;

use Codeception\Test\Unit;
use Craft;
use craft\behaviors\SessionBehavior;
use craft\cloud\AppConfig;
use craft\cloud\web\Session;
use craft\db\Table;
use yii\db\Schema;

class AppConfigTest extends Unit
{
    /**
     * @var \UnitTester
     */
    protected $tester;

    private string|false $craftCloud = false;
    private bool $createdSessionTable = false;

    protected function _before(): void
    {
        parent::_before();

        $this->craftCloud = getenv('CRAFT_CLOUD');
        putenv('CRAFT_CLOUD=1');
    }

    protected function _after(): void
    {
        if ($this->createdSessionTable) {
            Craft::$app->getDb()->createCommand()->dropTable(Table::PHPSESSIONS)->execute();
        }

        if ($this->craftCloud === false) {
            putenv('CRAFT_CLOUD');
        } else {
            putenv("CRAFT_CLOUD={$this->craftCloud}");
        }

        parent::_after();
    }

    public function testCloudWebSessionUsesCloudDbSessionWithCraftBehavior(): void
    {
        $this->ensureSessionTable();

        $config = (new AppConfig([
            'components' => [],
        ], 'web'))->getConfig();

        $session = $config['components']['session']();

        $this->assertInstanceOf(Session::class, $session);
        $this->assertInstanceOf(SessionBehavior::class, $session->getBehavior('session'));
        $this->assertSame(Table::PHPSESSIONS, $session->sessionTable);
    }

    private function ensureSessionTable(): void
    {
        $db = Craft::$app->getDb();

        if ($db->getSchema()->getTableSchema(Table::PHPSESSIONS, true) !== null) {
            return;
        }

        $db->createCommand()->createTable(Table::PHPSESSIONS, [
            'id' => Schema::TYPE_STRING . '(40) NOT NULL',
            'expire' => Schema::TYPE_INTEGER,
            'data' => Schema::TYPE_BINARY,
        ])->execute();

        $this->createdSessionTable = true;
    }
}
