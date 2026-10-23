<?php

declare(strict_types=1);

namespace Valencio\LaravelToolkit\Tests\Export;

use DateTimeImmutable;
use Illuminate\Contracts\Debug\ShouldntReport;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Valencio\LaravelToolkit\Export\Exceptions\ExportException;
use Valencio\LaravelToolkit\Export\Exceptions\ExportLimitExceededException;
use Valencio\LaravelToolkit\Export\ExportOptions;
use Valencio\LaravelToolkit\Export\XlsxExporter;
use ZipArchive;

/**
 * 同步 XLSX 写入、限额和文本单元格验收。
 */
final class XlsxExporterTest extends TestCase
{
    /** @var list<string> */
    private array $directories = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('xlswriter')) {
            $this->markTestSkipped('xlswriter extension is not installed.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            $this->removeTree($directory);
        }

        parent::tearDown();
    }

    public function test_writes_workbook_with_title_and_text_cells(): void
    {
        $directory = $this->directory();
        $logger = new ArrayLogger;
        $exporter = new XlsxExporter(defaultMaxRows: 100, logger: $logger, logSuccess: true);

        $workbook = $exporter->write(
            ['编号', '名称'],
            [
                ['001', '=1+1'],
                [12, '示例'],
            ],
            new ExportOptions(
                directory: $directory,
                title: '演示导出',
                columnWidths: ['A:B' => 20],
            ),
        );

        $this->assertFileExists($workbook->path);
        $this->assertSame(2, $workbook->rowCount);
        $this->assertGreaterThan(0, $workbook->byteSize);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}\.xlsx$/', $workbook->filename);
        $this->assertStringNotContainsString('演示导出', $workbook->filename);

        $xml = $this->sheetXml($workbook->path);
        $this->assertStringContainsString('A1:B1', $xml);
        $this->assertStringContainsString('001', $xml);
        $this->assertStringContainsString('=1+1', $xml);
        $this->assertStringNotContainsString('<f>', $xml);

        $this->assertCount(1, $logger->records);
        $this->assertSame('info', $logger->records[0]['level']);
        $this->assertSame(2, $logger->records[0]['context']['row_count']);
        $this->assertSame($workbook->byteSize, $logger->records[0]['context']['size']);
    }

    public function test_supports_thirty_columns(): void
    {
        $headers = [];
        $row = [];
        for ($index = 1; $index <= 30; $index++) {
            $headers[] = 'C'.$index;
            $row[] = $index;
        }

        $workbook = (new XlsxExporter)->write(
            $headers,
            [$row],
            new ExportOptions(directory: $this->directory(), title: '三十列'),
        );

        $this->assertStringContainsString('A1:AD1', $this->sheetXml($workbook->path));
    }

    public function test_exceeding_max_rows_deletes_the_file_and_does_not_truncate(): void
    {
        $directory = $this->directory();
        $logger = new ArrayLogger;
        $exporter = new XlsxExporter(defaultMaxRows: 1, logger: $logger);

        try {
            $exporter->write(
                ['名称'],
                [['一'], ['二']],
                new ExportOptions(directory: $directory),
            );
            $this->fail('Expected ExportLimitExceededException.');
        } catch (ExportLimitExceededException $exception) {
            $this->assertSame(1, $exception->maxRows);
            $this->assertSame(2, $exception->rowCount);
        }

        $files = glob($directory.DIRECTORY_SEPARATOR.'*.xlsx') ?: [];
        $this->assertSame([], $files);
        $this->assertSame('error', $logger->records[0]['level']);
        $this->assertSame(ExportLimitExceededException::class, $logger->records[0]['context']['exception_class']);
    }

    public function test_writes_datetime_as_text_not_insert_date(): void
    {
        $workbook = (new XlsxExporter)->write(
            ['时间'],
            [[new DateTimeImmutable('2026-10-22 12:00:00')]],
            new ExportOptions(directory: $this->directory()),
        );

        $xml = $this->sheetXml($workbook->path);
        $this->assertStringContainsString('2026-10-22 12:00:00', $xml);
    }

    public function test_rejects_non_finite_floats(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('finite');

        (new XlsxExporter)->write(
            ['数值'],
            [[NAN]],
            new ExportOptions(directory: $this->directory()),
        );
    }

    public function test_limit_exceeded_is_not_reported_but_generic_export_exception_is(): void
    {
        $this->assertInstanceOf(ShouldntReport::class, new ExportLimitExceededException(1, 2));
        $this->assertNotInstanceOf(ShouldntReport::class, new ExportException('boom'));
    }

    public function test_rejects_relative_directory(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new XlsxExporter)->write(
            ['名称'],
            [],
            new ExportOptions(directory: 'exports'),
        );
    }

    public function test_rejects_empty_headers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new XlsxExporter)->write(
            [],
            [],
            new ExportOptions(directory: $this->directory()),
        );
    }

    private function directory(): string
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'toolkit-export-'.bin2hex(random_bytes(8));
        $this->directories[] = $directory;

        return $directory;
    }

    private function sheetXml(string $path): string
    {
        return $this->zipEntry($path, 'xl/worksheets/sheet1.xml');
    }

    private function zipEntry(string $path, string $name): string
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $contents = $zip->getFromName($name);
        $zip->close();
        $this->assertIsString($contents);

        return $contents;
    }

    private function removeTree(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $items = scandir($directory);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory.DIRECTORY_SEPARATOR.$item;
            if (is_dir($path)) {
                $this->removeTree($path);
                continue;
            }

            @unlink($path);
        }

        @rmdir($directory);
    }
}

/**
 * 收集日志记录。
 */
final class ArrayLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /**
     * @param  mixed  $level
     * @param  string|\Stringable  $message
     * @param  array<string, mixed>  $context
     */
    public function log($level, $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
