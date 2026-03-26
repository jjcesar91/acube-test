<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\InputFormat;
use App\Service\FileUploadService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;

#[CoversClass(FileUploadService::class)]
final class FileUploadServiceTest extends TestCase
{
    private LoggerInterface&Stub $logger;
    private string $uploadDir;

    protected function setUp(): void
    {
        $this->logger    = $this->createStub(LoggerInterface::class);
        $this->uploadDir = sys_get_temp_dir();
    }

    // -------------------------------------------------------------------------
    // Accepted MIME types — upload() must succeed and return a file path
    // -------------------------------------------------------------------------

    /** @return array<string, array{InputFormat, string}> */
    public static function acceptedMimeTypeProvider(): array
    {
        return [
            'json → application/json'   => [InputFormat::Json, 'application/json'],
            'csv → text/csv'            => [InputFormat::Csv,  'text/csv'],
            'csv → text/plain (finfo)'  => [InputFormat::Csv,  'text/plain'],
            'xlsx → ooxml spreadsheet'  => [InputFormat::Xlsx, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            'ods → oasis spreadsheet'   => [InputFormat::Ods,  'application/vnd.oasis.opendocument.spreadsheet'],
        ];
    }

    #[DataProvider('acceptedMimeTypeProvider')]
    public function testUploadAcceptsValidMimeType(InputFormat $format, string $mime): void
    {
        $file = $this->makeUploadedFile($mime);

        $path = $this->makeService()->upload($file, $format);

        $this->assertFileExists($path);
        @unlink($path);
    }

    // -------------------------------------------------------------------------
    // Rejected MIME types — upload() must throw InvalidArgumentException
    // -------------------------------------------------------------------------

    /** @return array<string, array{InputFormat, string}> */
    public static function rejectedMimeTypeProvider(): array
    {
        return [
            'json with text/plain'          => [InputFormat::Json, 'text/plain'],
            'csv with application/json'     => [InputFormat::Csv,  'application/json'],
            'xlsx with application/json'    => [InputFormat::Xlsx, 'application/json'],
            'ods with text/csv'             => [InputFormat::Ods,  'text/csv'],
            'json with application/pdf'     => [InputFormat::Json, 'application/pdf'],
        ];
    }

    #[DataProvider('rejectedMimeTypeProvider')]
    public function testUploadRejectsInvalidMimeType(InputFormat $format, string $mime): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');
        $this->logger = $logger;

        $file = $this->makeUploadedFile($mime);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid MIME type/');

        $this->makeService()->upload($file, $format);
    }

    // -------------------------------------------------------------------------
    // getMimeType() returns null — falls back to "unknown", must reject
    // -------------------------------------------------------------------------

    public function testUploadRejectsWhenMimeTypeIsNull(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');
        $this->logger = $logger;

        /** @var UploadedFile&Stub $file */
        $file = $this->createStub(UploadedFile::class);
        $file->method('getMimeType')->willReturn(null);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid MIME type "unknown"/');

        $this->makeService()->upload($file, InputFormat::Json);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeService(): FileUploadService
    {
        return new FileUploadService($this->uploadDir, $this->logger);
    }

    /**
     * Creates a real temporary file and wraps it in an UploadedFile whose
     * getMimeType() is stubbed to return the given MIME string.
     * The `test: true` flag bypasses Symfony's is_uploaded_file() check.
     */
    private function makeUploadedFile(string $mime): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'acube_test_');
        file_put_contents($path, 'dummy content');

        /** @var UploadedFile&Stub $file */
        $file = $this->createStub(UploadedFile::class);
        $file->method('getMimeType')->willReturn($mime);

        // Stub move() to rename the file inside the temp dir so upload() succeeds
        $file->method('move')->willReturnCallback(
            static function (string $dir, string $name) use ($path): File {
                $dest = $dir . DIRECTORY_SEPARATOR . $name;
                rename($path, $dest);

                return new File($dest);
            }
        );

        return $file;
    }
}
