<?php

declare(strict_types=1);

namespace Valencio\LaravelToolkit\Tests\Crud;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use PDO;
use PDOException;
use Valencio\LaravelToolkit\Tests\Crud\Fixtures\CrudWidget;

/**
 * MySQL 专用测试库 + Eloquent Capsule。
 *
 * 必须通过 TOOLKIT_DB_* 指定专用测试库（库名需含 test），禁止回退业务库。
 */
abstract class CrudDatabaseTestCase extends \PHPUnit\Framework\TestCase
{
    private const string TABLE = 'crud_widgets_toolkit_tmp';

    private Capsule $capsule;

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('pdo_mysql')) {
            $this->fail('pdo_mysql is required for CRUD database tests.');
        }

        $host = getenv('TOOLKIT_DB_HOST');
        $port = getenv('TOOLKIT_DB_PORT');
        $database = getenv('TOOLKIT_DB_DATABASE');
        $username = getenv('TOOLKIT_DB_USERNAME');
        $password = getenv('TOOLKIT_DB_PASSWORD');

        if ($host === false || $host === ''
            || $database === false || $database === ''
            || $username === false || $username === ''
        ) {
            $this->fail(
                'CRUD database tests require TOOLKIT_DB_HOST, TOOLKIT_DB_DATABASE and TOOLKIT_DB_USERNAME.'
            );
        }

        if (! str_contains(strtolower($database), 'test')) {
            $this->fail(
                'TOOLKIT_DB_DATABASE must be a dedicated testing database (name should contain "test").'
            );
        }

        $port = ($port === false || $port === '') ? '3306' : $port;
        $password = $password === false ? '' : (string) $password;

        try {
            new PDO(
                sprintf('mysql:host=%s;port=%s;dbname=%s', $host, $port, $database),
                $username,
                $password,
            );
        } catch (PDOException $e) {
            $this->fail('MySQL testing database unavailable: '.$e->getMessage());
        }

        $this->capsule = new Capsule;
        $this->capsule->addConnection([
            'driver' => 'mysql',
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'username' => $username,
            'password' => $password,
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
        ]);
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();

        $schema = $this->capsule->schema();
        $schema->dropIfExists(self::TABLE);
        $schema->create(self::TABLE, static function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('owner_id');
            $table->string('name');
            $table->unsignedTinyInteger('status')->default(0);
            $table->string('archived_at')->nullable();
            $table->string('created_at')->nullable();
        });
    }

    protected function tearDown(): void
    {
        if (isset($this->capsule)) {
            $this->capsule->schema()->dropIfExists(self::TABLE);
        }

        parent::tearDown();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    protected function seedWidgets(array $rows): void
    {
        foreach ($rows as $row) {
            CrudWidget::query()->create($row);
        }
    }
}
