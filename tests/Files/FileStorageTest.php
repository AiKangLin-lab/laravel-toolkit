<?php

declare(strict_types=1);

namespace Valencio\LaravelToolkit\Tests\Files;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\FilesystemException;
use League\Flysystem\UnableToDeleteFile;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Valencio\LaravelToolkit\Files\Exceptions\FileStorageException;
use Valencio\LaravelToolkit\Files\FileKeyGenerator;
use Valencio\LaravelToolkit\Files\FileStorage;
use Valencio\LaravelToolkit\Files\Logging\FileStorageLogger;

/**
 * 文件存储包的路径、写入、失败和日志验收。
 */
final class FileStorageTest extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    private ArrayLogger $defaultLogger;

    private ArrayLogger $explicitLogger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->defaultLogger = new ArrayLogger;
        $this->explicitLogger = new ArrayLogger;
    }

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            $this->removeTree($root);
        }

        parent::tearDown();
    }

    public function test_generate_uses_mime_extension_and_ignores_client_name(): void
    {
        $source = $this->makeFile($this->pngBytes(), 'evil.php');
        $keys = $this->keys();

        $path = $keys->generate(new File($source), 'attachments/contracts');

        $this->assertMatchesRegularExpression(
            '#^attachments/contracts/2026/10/15/[0-9a-hjkmnp-tv-z]{26}\.png$#',
            $path,
        );
        $this->assertStringNotContainsString('php', $path);
        $this->assertStringNotContainsString('evil', $path);
    }

    public function test_generate_rejects_unsafe_directories(): void
    {
        $keys = $this->keys();
        $file = new File($this->makeFile($this->pngBytes(), 'a.png'));

        foreach (['', '../secrets', '/abs', 'attachments/../x', 'http://evil', "a\\b", "a/\0b", 'a/./b'] as $directory) {
            try {
                $keys->generate($file, $directory);
                $this->fail("Directory [{$directory}] should have been rejected.");
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_store_writes_stream_to_selected_disk_and_skips_success_log(): void
    {
        $roots = [
            'private' => $this->root(),
            'public' => $this->root(),
        ];
        $streams = [];
        $storage = $this->storage(
            disks: [
                'private' => $this->localDisk($roots['private'], record: $streams),
                'public' => $this->localDisk($roots['public']),
            ],
        );

        $stored = $storage->store(
            new File($this->makeFile($this->pngBytes(), 'photo.png')),
            'private',
            'attachments',
            options: ['visibility' => 'private'],
        );

        $this->assertSame('private', $stored->disk);
        $this->assertSame('image/png', $stored->mimeType);
        $this->assertSame('png', $stored->extension);
        $this->assertSame(strlen($this->pngBytes()), $stored->size);
        $this->assertSame(1, $streams['count'] ?? 0);
        $this->assertSame(
            $this->pngBytes(),
            file_get_contents($roots['private'].'/'.$stored->path),
        );
        $this->assertFileDoesNotExist($roots['public'].'/'.$stored->path);
        $this->assertSame([], $this->defaultLogger->records);
    }

    public function test_store_at_rejects_unsafe_path_without_rewriting_it(): void
    {
        $root = $this->root();
        $storage = $this->storage(disks: ['uploads' => $this->localDisk($root)]);

        $this->expectException(InvalidArgumentException::class);
        $storage->storeAt(
            new File($this->makeFile($this->pngBytes(), 'a.png')),
            'uploads',
            '../outside.png',
        );
    }

    public function test_store_at_rejects_invalid_upload(): void
    {
        $storage = $this->storage(disks: ['uploads' => $this->localDisk($this->root())]);
        $upload = new UploadedFile('', 'a.png', 'image/png', UPLOAD_ERR_NO_FILE, false);

        $this->expectException(InvalidArgumentException::class);
        $storage->storeAt($upload, 'uploads', 'attachments/2026/10/15/file.png');
    }

    public function test_false_write_becomes_storage_failure_and_logs_once(): void
    {
        $storage = $this->storage(disks: [
            'uploads' => new FalseWriteFilesystem(...$this->adapterArgs($this->root())),
        ], logSuccess: false);

        try {
            $storage->storeAt(
                new File($this->makeFile($this->pngBytes(), 'a.png')),
                'uploads',
                'attachments/2026/10/15/file.png',
            );
            $this->fail('False write should fail.');
        } catch (FileStorageException $exception) {
            $this->assertSame('store', $exception->operation);
            $this->assertSame('uploads', $exception->disk);
            $this->assertNull($exception->getPrevious());
        }

        $this->assertCount(1, $this->defaultLogger->records);
        $this->assertSame('error', $this->defaultLogger->records[0]['level']);
        $this->assertSame('failure', $this->defaultLogger->records[0]['context']['outcome']);
        $this->assertSame('trace-1', $this->defaultLogger->records[0]['context']['trace_id']);
        $this->assertArrayNotHasKey('provider_request_id', $this->defaultLogger->records[0]['context']);
    }

    public function test_write_exception_keeps_cause_and_omits_secret_message(): void
    {
        $storage = $this->storage(disks: [
            'uploads' => new ThrowingWriteFilesystem(...$this->adapterArgs($this->root())),
        ]);

        try {
            $storage->storeAt(
                new File($this->makeFile($this->pngBytes(), 'a.png')),
                'uploads',
                'attachments/2026/10/15/file.png',
                'explicit',
            );
            $this->fail('Write exception should fail.');
        } catch (FileStorageException $exception) {
            $this->assertInstanceOf(SecretWriteException::class, $exception->getPrevious());
        }

        $this->assertCount(1, $this->explicitLogger->records);
        $this->assertSame([], $this->defaultLogger->records);
        $encoded = json_encode($this->explicitLogger->records[0]['context'], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('secret-token', $encoded);
        $this->assertStringNotContainsString('https://', $encoded);
        $this->assertSame('req-123', $this->explicitLogger->records[0]['context']['provider_request_id']);
        $this->assertSame(SecretWriteException::class, $this->explicitLogger->records[0]['context']['exception_class']);
    }

    public function test_invalid_channel_is_rejected_before_write(): void
    {
        $root = $this->root();
        $storage = $this->storage(
            disks: ['uploads' => $this->localDisk($root)],
            namedChannel: static function (string $name): LoggerInterface {
                throw new InvalidArgumentException("Unknown channel {$name}");
            },
        );

        try {
            $storage->storeAt(
                new File($this->makeFile($this->pngBytes(), 'a.png')),
                'uploads',
                'attachments/2026/10/15/file.png',
                'missing',
            );
            $this->fail('Invalid channel should fail before write.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('missing', $exception->getMessage());
        }

        $this->assertSame([], glob($root.'/*') ?: []);
    }

    public function test_log_failure_does_not_change_store_result(): void
    {
        $root = $this->root();
        $storage = $this->storage(
            disks: ['uploads' => $this->localDisk($root)],
            logSuccess: true,
            defaultLogger: new ThrowingLogger,
        );

        $stored = $storage->storeAt(
            new File($this->makeFile($this->pngBytes(), 'a.png')),
            'uploads',
            'attachments/2026/10/15/file.png',
        );

        $this->assertFileExists($root.'/'.$stored->path);
    }

    public function test_delete_missing_object_is_successful_and_real_failure_logs_once(): void
    {
        $root = $this->root();
        $storage = $this->storage(disks: ['uploads' => $this->localDisk($root)]);

        $storage->delete('uploads', 'attachments/2026/10/15/missing.png');
        $this->assertSame([], $this->defaultLogger->records);

        $failing = $this->storage(disks: [
            'uploads' => new FalseDeleteFilesystem(...$this->adapterArgs($root)),
        ]);

        try {
            $failing->delete('uploads', 'attachments/2026/10/15/stuck.png');
            $this->fail('Delete failure should throw.');
        } catch (FileStorageException $exception) {
            $this->assertSame('delete', $exception->operation);
            $this->assertInstanceOf(UnableToDeleteFile::class, $exception->getPrevious());
        }

        $this->assertCount(1, $this->defaultLogger->records);
    }

    public function test_unknown_disk_is_a_programming_error(): void
    {
        $storage = $this->storage(disks: []);

        $this->expectException(InvalidArgumentException::class);
        $storage->storeAt(
            new File($this->makeFile($this->pngBytes(), 'a.png')),
            'missing',
            'attachments/2026/10/15/file.png',
        );
    }

    /**
     * @param  array<string, FilesystemAdapter>  $disks
     * @param  array<string, int>|null  $record
     */
    private function storage(
        array $disks,
        bool $logSuccess = false,
        ?LoggerInterface $defaultLogger = null,
        ?callable $namedChannel = null,
        ?string $configuredChannel = null,
    ): FileStorage {
        $defaultLogger ??= $this->defaultLogger;
        $explicitLogger = $this->explicitLogger;

        return new FileStorage(
            new MapFilesystemFactory($disks),
            $this->keys(),
            new FileStorageLogger(
                namedChannel: $namedChannel ?? static function (string $name) use ($explicitLogger, $defaultLogger): LoggerInterface {
                    return $name === 'explicit' ? $explicitLogger : $defaultLogger;
                },
                defaultChannel: static fn (): LoggerInterface => $defaultLogger,
                configuredChannel: $configuredChannel,
                logSuccess: $logSuccess,
                traceId: static fn (): string => 'trace-1',
            ),
        );
    }

    private function keys(): FileKeyGenerator
    {
        return new FileKeyGenerator(
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-10-15 02:00:00', new DateTimeZone('UTC')),
        );
    }

    /**
     * @param  array<string, int>|null  $record
     */
    private function localDisk(string $root, ?array &$record = null): FilesystemAdapter
    {
        [$driver, $adapter, $config] = $this->adapterArgs($root);
        if ($record === null) {
            return new FilesystemAdapter($driver, $adapter, $config);
        }

        return new RecordingFilesystem($driver, $adapter, $config, $record);
    }

    /**
     * @return array{0: Flysystem, 1: LocalFilesystemAdapter, 2: array{root: string, throw: bool}}
     */
    private function adapterArgs(string $root): array
    {
        $adapter = new LocalFilesystemAdapter($root);

        return [
            new Flysystem($adapter),
            $adapter,
            ['root' => $root, 'throw' => false],
        ];
    }

    private function root(): string
    {
        $root = sys_get_temp_dir().'/toolkit-files-'.bin2hex(random_bytes(6));
        mkdir($root);
        $this->roots[] = $root;

        return $root;
    }

    private function makeFile(string $contents, string $name): string
    {
        $directory = $this->root();
        $path = $directory.'/'.$name;
        file_put_contents($path, $contents);

        return $path;
    }

    private function pngBytes(): string
    {
        $bytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        );
        $this->assertIsString($bytes);

        return $bytes;
    }

    private function removeTree(string $root): void
    {
        if (! is_dir($root)) {
            return;
        }

        $items = scandir($root);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $root.'/'.$item;
            if (is_dir($path)) {
                $this->removeTree($path);
            } else {
                unlink($path);
            }
        }

        rmdir($root);
    }
}

/**
 * @internal
 */
final class MapFilesystemFactory implements FilesystemFactory
{
    /**
     * @param  array<string, FilesystemAdapter>  $disks
     */
    public function __construct(private array $disks) {}

    public function disk($name = null)
    {
        if (! is_string($name) || ! isset($this->disks[$name])) {
            throw new InvalidArgumentException("Disk [{$name}] does not have a configured driver.");
        }

        return $this->disks[$name];
    }
}

/**
 * @internal
 */
final class ArrayLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log($level, $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}

/**
 * @internal
 */
final class ThrowingLogger extends AbstractLogger
{
    public function log($level, $message, array $context = []): void
    {
        throw new RuntimeException('log down');
    }
}

/**
 * @internal
 */
final class RecordingFilesystem extends FilesystemAdapter
{
    /** @param  array<string, int>  $record */
    public array $record = [];

    /**
     * @param  array<string, int>  $record
     * @param  array{root: string, throw: bool}  $config
     */
    public function __construct(Flysystem $driver, LocalFilesystemAdapter $adapter, array $config, array &$record)
    {
        parent::__construct($driver, $adapter, $config);
        $this->record = &$record;
    }

    public function writeStream($path, $resource, array $options = [])
    {
        if (! is_resource($resource)) {
            throw new RuntimeException('writeStream expected a stream.');
        }

        $this->record['count'] = ($this->record['count'] ?? 0) + 1;

        return parent::writeStream($path, $resource, $options);
    }
}

/**
 * @internal
 */
final class FalseWriteFilesystem extends FilesystemAdapter
{
    public function writeStream($path, $resource, array $options = [])
    {
        return false;
    }
}

/**
 * @internal
 */
final class ThrowingWriteFilesystem extends FilesystemAdapter
{
    public function writeStream($path, $resource, array $options = [])
    {
        throw new SecretWriteException('signed https://secret.example/file?token=secret-token');
    }
}

/**
 * @internal
 */
final class SecretWriteException extends RuntimeException implements FilesystemException
{
    public function getRequestId(): string
    {
        return 'req-123';
    }
}

/**
 * @internal
 */
final class FalseDeleteFilesystem extends FilesystemAdapter
{
    public function delete($paths)
    {
        throw UnableToDeleteFile::atLocation(is_string($paths) ? $paths : 'stuck.png', 'busy');
    }

    public function exists($path)
    {
        return true;
    }
}
