<?php

declare(strict_types=1);

namespace Valencio\LaravelToolkit\Tests\Crud;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Valencio\LaravelToolkit\Tests\Crud\Fixtures\CrudWidget;

/**
 * MySQL 临时表 + Eloquent Capsule（本机无 pdo_sqlite 时的阶段 A 验收库）。
 *
 * 连接参数可读环境变量：TOOLKIT_DB_HOST / PORT / DATABASE / USERNAME / PASSWORD。
 */
abstract class CrudDatabaseTestCase extends TestCase
{
    private Capsule $capsule;

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('pdo_mysql is required for CRUD QueryApplier tests.');
        }

        $host = getenv('TOOLKIT_DB_HOST') ?: '127.0.0.1';
        $port = getenv('TOOLKIT_DB_PORT') ?: '3306';
        $database = getenv('TOOLKIT_DB_DATABASE') ?: 'code_base';
        $username = getenv('TOOLKIT_DB_USERNAME') ?: 'root';
        $password = getenv('TOOLKIT_DB_PASSWORD') !== false
            ? (string) getenv('TOOLKIT_DB_PASSWORD')
            : 'rooter';

        try {
            new PDO(
                sprintf('mysql:host=%s;port=%s;dbname=%s', $host, $port, $database),
                $username,
                $password,
            );
        } catch (PDOException $e) {
            $this->markTestSkipped('MySQL unavailable for CRUD tests: '.$e->getMessage());
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
        $schema->dropIfExists('crud_widgets');
        $schema->create('crud_widgets', static function (Blueprint $table): void {
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
            $this->capsule->schema()->dropIfExists('crud_widgets');
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
