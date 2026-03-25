<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Job;
use App\Message\ConvertFileMessage;
use App\Repository\JobRepository;
use App\Request\CreateJobRequest;
use App\Response\CreateJobResponse;
use App\Response\JobStatusResponse;
use App\Service\FileUploadService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api/jobs')]
final class JobController extends AbstractController
{
    public function __construct(
        private readonly JobRepository $jobRepository,
        private readonly FileUploadService $fileUploadService,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[Route('', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        try {
            $dto = CreateJobRequest::fromRequest($request);
        } catch (\InvalidArgumentException $e) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'Validation error', $e->getMessage());
        }

        try {
            $filePath = $this->fileUploadService->upload($dto->file, $dto->inputFormat);
        } catch (\InvalidArgumentException $e) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'Invalid file', $e->getMessage());
        }

        $job = new Job($filePath, $dto->inputFormat, $dto->outputFormat);
        $this->entityManager->persist($job);
        $this->entityManager->flush();

        $this->bus->dispatch(new ConvertFileMessage($job->getId()));

        return $this->json(CreateJobResponse::fromJob($job)->toArray(), Response::HTTP_ACCEPTED);
    }

    #[Route('/{id}', methods: ['GET'])]
    public function status(string $id): JsonResponse
    {
        $job = $this->resolveJob($id);

        if ($job instanceof JsonResponse) {
            return $job;
        }

        return $this->json(JobStatusResponse::fromJob($job)->toArray());
    }

    #[Route('/{id}/result', methods: ['GET'])]
    public function result(string $id): Response
    {
        $job = $this->resolveJob($id);

        if ($job instanceof JsonResponse) {
            return $job;
        }

        if ($job->getStatus() !== \App\Enum\JobStatus::Completed) {
            return $this->problem(
                Response::HTTP_CONFLICT,
                'Job not completed',
                sprintf('Job is currently "%s". Result is only available when status is "completed".', $job->getStatus()->value)
            );
        }

        $outputPath = $job->getOutputFilePath();

        if (null === $outputPath || !file_exists($outputPath)) {
            return $this->problem(Response::HTTP_INTERNAL_SERVER_ERROR, 'Output file missing', 'The converted file could not be found on the server.');
        }

        $response = new BinaryFileResponse($outputPath);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            sprintf('job-%s.%s', $job->getId()->toRfc4122(), $job->getOutputFormat()->value)
        );

        return $response;
    }

    /**
     * Resolves a Job by UUID string, returning a 400/404 JsonResponse on failure.
     *
     * @return Job|JsonResponse
     */
    private function resolveJob(string $id): Job|JsonResponse
    {
        if (!Uuid::isValid($id)) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'Invalid UUID', sprintf('"%s" is not a valid UUID.', $id));
        }

        $job = $this->jobRepository->findByUuid(Uuid::fromString($id));

        if (null === $job) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'Job not found', sprintf('No job found with id "%s".', $id));
        }

        return $job;
    }

    /**
     * Builds an RFC 7807-style problem JSON response.
     */
    private function problem(int $status, string $title, string $detail): JsonResponse
    {
        return $this->json([
            'status' => $status,
            'title'  => $title,
            'detail' => $detail,
        ], $status, ['Content-Type' => 'application/problem+json']);
    }
}
