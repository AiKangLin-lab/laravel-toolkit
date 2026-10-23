<?php

declare(strict_types=1);

namespace Valencio\LaravelToolkit\Export;

use DateTimeInterface;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Stringable;
use Throwable;
use Valencio\LaravelToolkit\Export\Exceptions\ExportException;
use Valencio\LaravelToolkit\Export\Exceptions\ExportLimitExceededException;
use Valencio\LaravelToolkit\Export\Exceptions\XlswriterMissingException;
use Vtiful\Kernel\Excel;
use Vtiful\Kernel\Format;

/**
 * 同步写入 XLSX。
 *
 * 使用 xlswriter 恒定内存模式按行顺序写。一次调用完成，不保留上次输入。
 * 不生成 URL、不处理权限、不查询业务数据。
 */
final readonly class XlsxExporter
{
    /**
     * @param  int  $defaultMaxRows  未指定选项上限时的数据行限额
     * @param  LoggerInterface|null  $logger  可选操作日志
     * @param  bool  $logSuccess  是否记录成功写入
     */
    public function __construct(
        private int $defaultMaxRows = 100_000,
        private ?LoggerInterface $logger = null,
        private bool $logSuccess = false,
    ) {
        if ($this->defaultMaxRows < 1) {
            throw new InvalidArgumentException('Export default max rows must be at least 1.');
        }
    }

    /**
     * 写入一张工作表并返回本地文件。
     *
     * @param  list<string>  $headers  表头，至少一列
     * @param  iterable<mixed>  $rows  数据行；每行是 list<mixed>
     * @param  ExportOptions  $options  输出目录、限额、标题和列宽
     */
    public function write(array $headers, iterable $rows, ExportOptions $options): ExportedWorkbook
    {
        $this->assertExtension();
        $this->assertHeaders($headers);

        $maxRows = $options->maxRows ?? $this->defaultMaxRows;
        $directory = $this->prepareDirectory($options->directory);
        $filename = $this->storageName();
        $path = $directory.DIRECTORY_SEPARATOR.$filename;
        $columnCount = count($headers);
        $started = hrtime(true);
        $excel = null;
        $rowCount = 0;

        try {
            $excel = new Excel(['path' => $directory]);
            $fileObject = $excel->constMemory($filename, $options->sheetName);
            $handle = $excel->getHandle();

            $titleStyle = $this->titleStyle($handle);
            $headerStyle = $this->headerStyle($handle);
            $cellStyle = $this->cellStyle($handle);
            $fileObject->defaultFormat($cellStyle);

            foreach ($options->columnWidths as $range => $width) {
                $fileObject->setColumn($this->columnRange($range), (float) $width);
            }

            $headerRow = 0;
            if ($options->title !== '') {
                $last = ColumnLetter::lastForCount($columnCount);
                $fileObject->setRow('A1', 30, $titleStyle);
                $fileObject->mergeCells('A1:'.$last.'1', $options->title, $titleStyle);
                $headerRow = 1;
            }

            $fileObject->setRow('A'.($headerRow + 1), 15, $headerStyle);
            foreach ($headers as $column => $header) {
                $fileObject->insertText($headerRow, $column, $header, null, $headerStyle);
            }

            $dataRow = $headerRow + 1;
            foreach ($rows as $row) {
                $rowCount++;
                if ($rowCount > $maxRows) {
                    throw new ExportLimitExceededException($maxRows, $rowCount);
                }

                foreach ($this->normalizeRow($row, $columnCount, $rowCount) as $column => $value) {
                    $this->writeCell($fileObject, $dataRow, $column, $value);
                }
                $dataRow++;
            }

            $output = $fileObject->output();
            $excel->close();
            $excel = null;

            if (! is_string($output) || $output === '' || ! is_file($output)) {
                throw new ExportException('Export did not produce a local file.', $path, $rowCount);
            }

            $path = $output;
            $byteSize = filesize($path);
            if ($byteSize === false) {
                throw new ExportException('Export file size could not be read.', $path, $rowCount);
            }

            $durationMs = $this->elapsedMs($started);
            $workbook = new ExportedWorkbook(
                path: $path,
                filename: basename($path),
                rowCount: $rowCount,
                byteSize: $byteSize,
                durationMs: $durationMs,
            );
            $this->log(failed: false, rowCount: $rowCount, byteSize: $byteSize, durationMs: $durationMs);

            return $workbook;
        } catch (ExportLimitExceededException $exception) {
            $this->abandon($excel, $path);
            $this->log(true, $rowCount, null, $this->elapsedMs($started), $exception::class, $exception->getMessage());
            throw $exception;
        } catch (ExportException $exception) {
            $this->abandon($excel, $path);
            $this->log(true, $rowCount, null, $this->elapsedMs($started), $exception::class, $exception->getMessage());
            throw $exception;
        } catch (InvalidArgumentException $exception) {
            $this->abandon($excel, $path);
            throw $exception;
        } catch (Throwable $exception) {
            $this->abandon($excel, $path);
            $this->log(
                true,
                $rowCount,
                null,
                $this->elapsedMs($started),
                $exception::class,
                $exception->getMessage(),
            );
            throw new ExportException(
                'Export write failed.',
                null,
                $rowCount,
                $exception,
            );
        }
    }

    private function assertExtension(): void
    {
        if (! extension_loaded('xlswriter') || ! class_exists(Excel::class)) {
            throw new XlswriterMissingException;
        }
    }

    /**
     * @param  list<string>  $headers
     */
    private function assertHeaders(array $headers): void
    {
        if ($headers === []) {
            throw new InvalidArgumentException('Export headers must not be empty.');
        }

        foreach ($headers as $index => $header) {
            if (! is_string($header) || $header === '') {
                throw new InvalidArgumentException(sprintf('Export header at column %d must be a non-empty string.', $index + 1));
            }
        }
    }

    private function prepareDirectory(string $directory): string
    {
        if (str_contains($directory, "\0") || str_contains($directory, '..')) {
            throw new InvalidArgumentException('Export directory is invalid.');
        }

        if (! $this->isAbsolutePath($directory)) {
            throw new InvalidArgumentException('Export directory must be an absolute path.');
        }

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new InvalidArgumentException('Export directory could not be created.');
        }

        $resolved = realpath($directory);
        if ($resolved === false || ! is_dir($resolved) || ! is_writable($resolved)) {
            throw new InvalidArgumentException('Export directory is not a writable directory.');
        }

        return $resolved;
    }

    private function isAbsolutePath(string $path): bool
    {
        if ($path[0] === '/' || $path[0] === '\\') {
            return true;
        }

        return strlen($path) >= 3
            && ctype_alpha($path[0])
            && $path[1] === ':'
            && ($path[2] === '/' || $path[2] === '\\');
    }

    private function storageName(): string
    {
        return bin2hex(random_bytes(16)).'.xlsx';
    }

    /**
     * @return list<mixed>
     */
    private function normalizeRow(mixed $row, int $columnCount, int $rowNumber): array
    {
        if (! is_array($row)) {
            throw new InvalidArgumentException(sprintf('Export row %d must be a list.', $rowNumber));
        }

        $cells = array_values($row);
        if (count($cells) > $columnCount) {
            throw new InvalidArgumentException(sprintf(
                'Export row %d has %d cells but only %d columns are declared.',
                $rowNumber,
                count($cells),
                $columnCount,
            ));
        }

        while (count($cells) < $columnCount) {
            $cells[] = null;
        }

        return $cells;
    }

    /**
     * const memory 下 setColumn 必须是 A:A 这种范围，单独的 A 会改已写入单元格。
     */
    private function columnRange(string $range): string
    {
        if (str_contains($range, ':')) {
            return $range;
        }

        return $range.':'.$range;
    }

    /**
     * 按类型写入单元格；日期统一写文本，非有限 float 拒绝。
     *
     * @throws InvalidArgumentException 浮点非有限值时
     */
    private function writeCell(Excel $fileObject, int $row, int $column, mixed $value): void
    {
        if ($value === null) {
            $fileObject->insertText($row, $column, '');

            return;
        }

        if (is_bool($value)) {
            $fileObject->insertText($row, $column, $value ? 1 : 0);

            return;
        }

        if (is_int($value)) {
            $fileObject->insertText($row, $column, $value);

            return;
        }

        if (is_float($value)) {
            if (! is_finite($value)) {
                throw new InvalidArgumentException(sprintf(
                    'Export cell float must be finite, got %s.',
                    (string) $value,
                ));
            }

            $fileObject->insertText($row, $column, $value);

            return;
        }

        if ($value instanceof DateTimeInterface) {
            // insertDate 需要时间戳；统一写成文本，避免格式串被误当时间戳。
            $fileObject->insertText($row, $column, $value->format('Y-m-d H:i:s'));

            return;
        }

        if ($value instanceof Stringable) {
            $fileObject->insertText($row, $column, (string) $value);

            return;
        }

        if (is_string($value)) {
            $fileObject->insertText($row, $column, $value);

            return;
        }

        throw new InvalidArgumentException(sprintf(
            'Export cell type %s is not supported. Map the value in the project first.',
            get_debug_type($value),
        ));
    }

    private function titleStyle(mixed $handle): mixed
    {
        return (new Format($handle))
            ->bold()
            ->align(Format::FORMAT_ALIGN_CENTER, Format::FORMAT_ALIGN_VERTICAL_CENTER)
            ->toResource();
    }

    private function headerStyle(mixed $handle): mixed
    {
        return (new Format($handle))
            ->bold()
            ->align(Format::FORMAT_ALIGN_CENTER, Format::FORMAT_ALIGN_VERTICAL_CENTER)
            ->toResource();
    }

    private function cellStyle(mixed $handle): mixed
    {
        return (new Format($handle))
            ->align(Format::FORMAT_ALIGN_CENTER, Format::FORMAT_ALIGN_VERTICAL_CENTER)
            ->toResource();
    }

    private function abandon(?Excel $excel, ?string $path): void
    {
        if ($excel !== null) {
            try {
                $excel->close();
            } catch (Throwable) {
            }
        }

        if (is_string($path) && $path !== '' && is_file($path)) {
            @unlink($path);
        }
    }

    private function elapsedMs(int $started): int
    {
        return (int) ((hrtime(true) - $started) / 1_000_000);
    }

    private function log(
        bool $failed,
        int $rowCount,
        ?int $byteSize,
        int $durationMs,
        ?string $exceptionClass = null,
        ?string $exceptionMessage = null,
    ): void {
        if ($this->logger === null) {
            return;
        }

        if (! $failed && ! $this->logSuccess) {
            return;
        }

        try {
            $context = [
                'operation' => 'write',
                'row_count' => $rowCount,
                'size' => $byteSize,
                'duration_ms' => $durationMs,
                'outcome' => $failed ? 'failure' : 'success',
                'exception_class' => $exceptionClass,
                'exception_message' => $exceptionMessage,
            ];

            if ($failed) {
                $this->logger->error('xlsx_export', $context);

                return;
            }

            $this->logger->info('xlsx_export', $context);
        } catch (Throwable $loggingError) {
            error_log(sprintf(
                '[laravel-toolkit] xlsx export log failed: %s',
                $loggingError::class,
            ));
        }
    }
}
