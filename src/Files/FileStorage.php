<?php

declare(strict_types=1);

namespace Valencio\LaravelToolkit\Files;

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use League\Flysystem\FilesystemException;
use Psr\Log\LoggerInterface;
use Throwable;
use Valencio\LaravelToolkit\Files\Exceptions\FileStorageException;
use Valencio\LaravelToolkit\Files\Logging\FileStorageLogger;

/**
 * 对象存储的写入与技术删除。
 *
 * 使用调用方打开的文件流写入，不读取整文件、不生成 URL、不写业务表。
 * 三个公开方法都不保留上一次调用的状态。
 */
final readonly class FileStorage
{
    /**
     * @param  FilesystemFactory  $disks  Laravel disk 工厂
     * @param  FileKeyGenerator  $keys  路径生成与校验
     * @param  FileStorageLogger  $storageLogger  存储操作日志
     */
    public function __construct(
        private FilesystemFactory $disks,
        private FileKeyGenerator $keys,
        private FileStorageLogger $storageLogger,
    ) {}

    /**
     * 生成独占路径并写入。
     *
     * @param  File|UploadedFile  $file  本地可读文件
     * @param  string  $disk  已配置的逻辑 disk
     * @param  string  $directory  服务端相对目录前缀
     * @param  string|null  $logChannel  本次日志通道；为空时按包配置再回退默认通道
     * @param  array<string, mixed>  $options  Filesystem 写入选项，例如 visibility、headers；不得包含凭据，也不接受 HTTP 透传
     */
    public function store(
        File|UploadedFile $file,
        string $disk,
        string $directory = 'uploads',
        ?string $logChannel = null,
        array $options = [],
    ): StoredFile {
        return $this->storeAt(
            $file,
            $disk,
            $this->keys->generate($file, $directory),
            $logChannel,
            $options,
        );
    }

    /**
     * 写入服务端已经分配的相对对象路径。
     *
     * @param  File|UploadedFile  $file  本地可读文件
     * @param  string  $disk  已配置的逻辑 disk
     * @param  string  $path  完整相对对象路径
     * @param  string|null  $logChannel  本次日志通道；为空时按包配置再回退默认通道
     * @param  array<string, mixed>  $options  Filesystem 写入选项，例如 visibility、headers；不得包含凭据，也不接受 HTTP 透传
     */
    public function storeAt(
        File|UploadedFile $file,
        string $disk,
        string $path,
        ?string $logChannel = null,
        array $options = [],
    ): StoredFile {
        $this->keys->assertObjectPath($path);
        $source = $this->inspect($file);
        $adapter = $this->adapter($disk);
        $logger = $this->storageLogger->open($logChannel);
        $started = hrtime(true);

        $stream = fopen($source['path'], 'rb');
        if ($stream === false) {
            throw new InvalidArgumentException('Storage source file is not readable.');
        }

        try {
            $this->writeStream($adapter, $disk, $path, $stream, $options);
            $stored = new StoredFile(
                disk: $disk,
                path: $path,
                size: $source['size'],
                mimeType: $source['mimeType'],
                extension: $source['extension'],
            );
            $this->storageLogger->record(
                $logger,
                failed: false,
                operation: 'store',
                disk: $disk,
                path: $path,
                size: $stored->size,
                durationMs: $this->duration($started),
                exceptionClass: null,
            );

            return $stored;
        } catch (FileStorageException $exception) {
            $this->storageLogger->record(
                $logger,
                failed: true,
                operation: 'store',
                disk: $disk,
                path: $path,
                size: $source['size'],
                durationMs: $this->duration($started),
                exceptionClass: $this->exceptionClass($exception),
                providerRequestId: $this->storageLogger->providerRequestId($exception->getPrevious()),
            );

            throw $exception;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * 删除一个对象。对象不存在视为成功，不删除数据库记录。
     *
     * @param  string  $disk  已配置的逻辑 disk
     * @param  string  $path  相对对象路径
     * @param  string|null  $logChannel  本次日志通道；为空时按包配置再回退默认通道
     */
    public function delete(string $disk, string $path, ?string $logChannel = null): void
    {
        $this->keys->assertObjectPath($path);
        $adapter = $this->adapter($disk);
        $logger = $this->storageLogger->open($logChannel);
        $started = hrtime(true);

        try {
            $this->removeObject($adapter, $disk, $path);
            $this->storageLogger->record(
                $logger,
                failed: false,
                operation: 'delete',
                disk: $disk,
                path: $path,
                size: null,
                durationMs: $this->duration($started),
                exceptionClass: null,
            );
        } catch (FileStorageException $exception) {
            $this->storageLogger->record(
                $logger,
                failed: true,
                operation: 'delete',
                disk: $disk,
                path: $path,
                size: null,
                durationMs: $this->duration($started),
                exceptionClass: $this->exceptionClass($exception),
                providerRequestId: $this->storageLogger->providerRequestId($exception->getPrevious()),
            );

            throw $exception;
        }
    }

    /**
     * @param  resource  $stream  调用方打开的只读文件流，由本方法在 finally 中关闭
     * @param  array<string, mixed>  $options  原样交给 Filesystem 的写入选项
     */
    private function writeStream(Filesystem $adapter, string $disk, string $path, mixed $stream, array $options): void
    {
        try {
            $written = $adapter->writeStream($path, $stream, $options);
        } catch (FilesystemException $exception) {
            throw new FileStorageException(
                'Storage write failed.',
                'store',
                $disk,
                $path,
                $exception,
            );
        }

        if ($written === false) {
            throw new FileStorageException('Storage write failed.', 'store', $disk, $path);
        }
    }

    private function removeObject(Filesystem $adapter, string $disk, string $path): void
    {
        try {
            $deleted = $adapter->delete($path);
        } catch (FilesystemException $exception) {
            if ($this->missing($adapter, $path)) {
                return;
            }

            throw new FileStorageException(
                'Storage delete failed.',
                'delete',
                $disk,
                $path,
                $exception,
            );
        }

        if ($deleted === false && ! $this->missing($adapter, $path)) {
            throw new FileStorageException('Storage delete failed.', 'delete', $disk, $path);
        }
    }

    private function missing(Filesystem $adapter, string $path): bool
    {
        try {
            return ! $adapter->exists($path);
        } catch (FilesystemException) {
            return false;
        }
    }

    private function adapter(string $disk): Filesystem
    {
        if ($disk === '') {
            throw new InvalidArgumentException('Storage disk must not be empty.');
        }

        try {
            $adapter = $this->disks->disk($disk);
        } catch (InvalidArgumentException $exception) {
            throw $exception;
        }

        if (! $adapter instanceof Filesystem) {
            throw new InvalidArgumentException("Storage disk [{$disk}] is not a filesystem.");
        }

        return $adapter;
    }

    /**
     * @return array{path: string, size: int, mimeType: string, extension: string}
     */
    private function inspect(File|UploadedFile $file): array
    {
        $path = $this->keys->localPath($file);
        $size = filesize($path);
        if ($size === false) {
            throw new InvalidArgumentException('Storage source file is not readable.');
        }

        $mimeType = $this->keys->mimeType($path);

        return [
            'path' => $path,
            'size' => $size,
            'mimeType' => $mimeType,
            'extension' => $this->keys->extensionFromMime($mimeType),
        ];
    }

    private function exceptionClass(FileStorageException $exception): string
    {
        $previous = $exception->getPrevious();

        return $previous instanceof Throwable ? $previous::class : $exception::class;
    }

    private function duration(int $started): int
    {
        return (int) ((hrtime(true) - $started) / 1_000_000);
    }
}
