<?php

declare(strict_types=1);

namespace Valencio\LaravelToolkit\Files;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use finfo;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * 生成服务端独占的对象相对路径，并校验调用方给出的相对路径。
 *
 * 只读取本地文件头识别 MIME，不访问对象存储。
 */
final readonly class FileKeyGenerator
{
    /** 相对路径最大字符数。 */
    private const int MAX_PATH_LENGTH = 1024;

    /** 目录段数量上限。 */
    private const int MAX_DIRECTORY_SEGMENTS = 16;

    /**
     * 可由文件头 MIME 安全映射的扩展名。未列出的类型使用 bin。
     *
     * @var array<string, string>
     */
    private const array EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
        'text/plain' => 'txt',
        'text/csv' => 'csv',
        'application/json' => 'json',
        'application/xml' => 'xml',
        'text/xml' => 'xml',
        'application/zip' => 'zip',
    ];

    /**
     * @param  Closure(): DateTimeImmutable|null  $now  路径日期的时钟；为空时使用 UTC 当前时间
     */
    public function __construct(
        private ?Closure $now = null,
    ) {}

    /**
     * 生成形如 attachments/2026/10/15/<ulid>.pdf 的相对对象路径。
     *
     * @param  File|UploadedFile  $file  本地可读文件
     * @param  string  $directory  服务端配置的相对目录前缀
     * @return string 通过对象路径校验的相对路径
     */
    public function generate(File|UploadedFile $file, string $directory = 'uploads'): string
    {
        $this->assertDirectory($directory);
        $source = $this->localPath($file);
        $extension = $this->extensionFromMime($this->mimeType($source));
        $now = $this->currentTime();

        $path = $directory.'/'
            .$now->format('Y').'/'.$now->format('m').'/'.$now->format('d').'/'
            .Str::lower((string) Str::ulid()).'.'.$extension;

        $this->assertObjectPath($path);

        return $path;
    }

    /**
     * 校验服务端目录前缀。非法目录直接拒绝。
     *
     * @param  string  $directory  相对目录，允许多级
     */
    public function assertDirectory(string $directory): void
    {
        $segments = $this->segments($directory, 'directory');
        if (count($segments) > self::MAX_DIRECTORY_SEGMENTS) {
            throw new InvalidArgumentException('Storage directory has too many segments.');
        }

        foreach ($segments as $segment) {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/', $segment) !== 1) {
                throw new InvalidArgumentException('Storage directory contains an invalid segment.');
            }
        }
    }

    /**
     * 校验完整相对对象路径。非法路径直接拒绝，不改写成另一个目标。
     *
     * @param  string  $path  相对对象路径
     */
    public function assertObjectPath(string $path): void
    {
        $segments = $this->segments($path, 'path');
        $name = array_pop($segments);
        if (! is_string($name) || preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*\.[A-Za-z0-9]{1,16}$/', $name) !== 1) {
            throw new InvalidArgumentException('Storage object path must end with a single safe extension.');
        }

        if (count($segments) > self::MAX_DIRECTORY_SEGMENTS) {
            throw new InvalidArgumentException('Storage object path has too many segments.');
        }

        foreach ($segments as $segment) {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/', $segment) !== 1) {
                throw new InvalidArgumentException('Storage object path contains an invalid segment.');
            }
        }
    }

    /**
     * 读取本地文件头 MIME。无法识别时返回 application/octet-stream。
     *
     * @param  string  $path  本地文件路径
     */
    public function mimeType(string $path): string
    {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($path);

        return is_string($mime) && $mime !== '' ? $mime : 'application/octet-stream';
    }

    /**
     * 把 MIME 映射成落盘扩展名。
     *
     * @param  string  $mimeType  文件头 MIME
     */
    public function extensionFromMime(string $mimeType): string
    {
        return self::EXTENSIONS[$mimeType] ?? 'bin';
    }

    /**
     * 确认文件可读并返回真实路径。
     *
     * @param  File|UploadedFile  $file  待读取文件
     */
    public function localPath(File|UploadedFile $file): string
    {
        if ($file instanceof UploadedFile && ! $file->isValid()) {
            throw new InvalidArgumentException('Uploaded file is not valid.');
        }

        $path = $file->getRealPath();
        if (! is_string($path) || $path === '' || ! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException('Storage source file is not readable.');
        }

        return $path;
    }

    /**
     * @return list<string>
     */
    private function segments(string $value, string $label): array
    {
        if ($value === '' || strlen($value) > self::MAX_PATH_LENGTH) {
            throw new InvalidArgumentException("Storage {$label} length is invalid.");
        }

        if (str_contains($value, "\0")
            || str_contains($value, '\\')
            || str_contains($value, '://')
            || str_starts_with($value, '/')
            || preg_match('/^[A-Za-z]:/', $value) === 1
        ) {
            throw new InvalidArgumentException("Storage {$label} must be a relative path.");
        }

        $segments = explode('/', $value);
        if ($segments === [] || in_array('', $segments, true)) {
            throw new InvalidArgumentException("Storage {$label} contains an empty segment.");
        }

        foreach ($segments as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw new InvalidArgumentException("Storage {$label} contains a forbidden segment.");
            }
        }

        return $segments;
    }

    private function currentTime(): DateTimeImmutable
    {
        if ($this->now === null) {
            return new DateTimeImmutable('now', new DateTimeZone('UTC'));
        }

        $now = ($this->now)();
        if (! $now instanceof DateTimeImmutable) {
            throw new InvalidArgumentException('Storage path clock must return a DateTimeImmutable.');
        }

        return $now->setTimezone(new DateTimeZone('UTC'));
    }
}
