<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ConvertFileMessage;
use App\Repository\JobRepository;
use App\Service\Conversion\ConverterFactory;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ConvertFileMessageHandler
{
    public function __construct(
        private readonly JobRepository $jobRepository,
        private readonly ConverterFactory $converterFactory,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        #[Autowire(param: 'app.upload_dir')]
        private readonly string $outputDirectory,
    ) {
    }

    public function __invoke(ConvertFileMessage $message): void
    {
        $jobId = $message->jobId->toRfc4122();
        $job   = $this->jobRepository->findByUuid($message->jobId);

        if (null === $job) {
            $this->logger->error('Job not found — skipping message', ['jobId' => $jobId]);

            return;
        }

        $this->logger->info('Job processing started', ['jobId' => $jobId]);

        $job->markAsProcessing();
        $this->entityManager->flush();

        try {
            $converter  = $this->converterFactory->getConverter($job);
            $outputPath = $converter->convert($job, $this->outputDirectory);

            $job->markAsCompleted($outputPath);
            $this->entityManager->flush();

            $this->logger->info('Job completed successfully', [
                'jobId'      => $jobId,
                'outputPath' => $outputPath,
            ]);
        } catch (\Throwable $e) {
            $job->markAsFailed($e->getMessage());
            $this->entityManager->flush();

            $this->logger->error('Job failed during conversion', [
                'jobId' => $jobId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
