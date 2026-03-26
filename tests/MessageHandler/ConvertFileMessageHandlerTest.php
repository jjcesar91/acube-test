<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Entity\Job;
use App\Enum\InputFormat;
use App\Enum\JobStatus;
use App\Enum\OutputFormat;
use App\Message\ConvertFileMessage;
use App\MessageHandler\ConvertFileMessageHandler;
use App\Repository\JobRepository;
use App\Service\Conversion\ConverterFactory;
use App\Service\Conversion\ConverterInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ConvertFileMessageHandler::class)]
/**
 * Unit tests for ConvertFileMessageHandler.
 *
 * All dependencies are mocked so no database or filesystem is required.
 * ConverterFactory is instantiated directly with mock ConverterInterface
 * implementations because it is a final class and cannot be mocked by PHPUnit.
 */
final class ConvertFileMessageHandlerTest extends TestCase
{
    private JobRepository&MockObject $jobRepository;
    private EntityManagerInterface&MockObject $entityManager;
    private LoggerInterface&MockObject $logger;
    private string $outputDir;

    protected function setUp(): void
    {
        $this->jobRepository = $this->createMock(JobRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->logger        = $this->createMock(LoggerInterface::class);
        $this->outputDir     = sys_get_temp_dir();
    }

    // -------------------------------------------------------------------------
    // Happy path
    // -------------------------------------------------------------------------

    #[AllowMockObjectsWithoutExpectations]
    public function testHandlerMarksJobAsCompletedOnSuccess(): void
    {
        $job   = new Job('/tmp/input.json', InputFormat::Json, OutputFormat::Json);
        $jobId = $job->getId();

        $outputPath = sys_get_temp_dir() . '/output.json';

        $converter = $this->createStub(ConverterInterface::class);
        $converter->method('supports')->willReturn(true);
        $converter->method('convert')->willReturn($outputPath);

        // flush() must be called twice: after markAsProcessing and after markAsCompleted
        $this->entityManager->expects($this->exactly(2))->method('flush');

        $this->jobRepository->method('findByUuid')->with($jobId)->willReturn($job);

        $handler = $this->makeHandler($converter);
        ($handler)(new ConvertFileMessage($jobId));

        $this->assertSame(JobStatus::Completed, $job->getStatus());
        $this->assertSame($outputPath, $job->getOutputFilePath());
        $this->assertNotNull($job->getUpdatedAt());
    }

    // -------------------------------------------------------------------------
    // Job not found
    // -------------------------------------------------------------------------

    #[AllowMockObjectsWithoutExpectations]
    public function testHandlerLogsAndReturnsEarlyWhenJobIsNotFound(): void
    {
        $jobId = Uuid::v7();

        $this->jobRepository->method('findByUuid')->willReturn(null);

        // No DB flush should occur — there is nothing to update
        $this->entityManager->expects($this->never())->method('flush');

        $this->logger
            ->expects($this->once())
            ->method('error')
            ->with('Job not found — skipping message', $this->anything());

        $handler = $this->makeHandler();
        ($handler)(new ConvertFileMessage($jobId));
    }

    // -------------------------------------------------------------------------
    // Conversion failure
    // -------------------------------------------------------------------------

    public function testHandlerMarksJobAsFailedWhenConverterThrows(): void
    {
        $job          = new Job('/tmp/input.json', InputFormat::Json, OutputFormat::Json);
        $jobId        = $job->getId();
        $errorMessage = 'Disk full';

        $converter = $this->createStub(ConverterInterface::class);
        $converter->method('supports')->willReturn(true);
        $converter->method('convert')->willThrowException(new \RuntimeException($errorMessage));

        // flush() must still be called twice: after markAsProcessing and after markAsFailed
        $this->entityManager->expects($this->exactly(2))->method('flush');

        $this->logger
            ->expects($this->once())
            ->method('error')
            ->with('Job failed during conversion', $this->anything());

        $this->jobRepository->method('findByUuid')->with($jobId)->willReturn($job);

        $handler = $this->makeHandler($converter);
        ($handler)(new ConvertFileMessage($jobId));

        $this->assertSame(JobStatus::Failed, $job->getStatus());
        $this->assertSame($errorMessage, $job->getErrorMessage());
        $this->assertNotNull($job->getUpdatedAt());
    }

    /**
     * When no converter supports the job (e.g. unsupported format pair),
     * ConverterFactory throws RuntimeException and the handler marks the job failed.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testHandlerMarksJobAsFailedWhenNoConverterSupports(): void
    {
        $job   = new Job('/tmp/input.json', InputFormat::Json, OutputFormat::Json);
        $jobId = $job->getId();

        $unsupportedConverter = $this->createStub(ConverterInterface::class);
        $unsupportedConverter->method('supports')->willReturn(false);

        $this->entityManager->expects($this->exactly(2))->method('flush');

        $this->jobRepository->method('findByUuid')->willReturn($job);

        $handler = $this->makeHandler($unsupportedConverter);
        ($handler)(new ConvertFileMessage($jobId));

        $this->assertSame(JobStatus::Failed, $job->getStatus());
        $this->assertNotEmpty($job->getErrorMessage());
    }

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    private function makeHandler(ConverterInterface ...$converters): ConvertFileMessageHandler
    {
        return new ConvertFileMessageHandler(
            $this->jobRepository,
            new ConverterFactory($converters),
            $this->entityManager,
            $this->logger,
            $this->outputDir,
        );
    }
}
