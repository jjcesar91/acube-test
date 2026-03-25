<?php

declare(strict_types=1);

namespace App\Response;

use App\Entity\Job;

final readonly class JobStatusResponse
{
    public function __construct(
        public string $jobId,
        public string $status,
        public string $createdAt,
        public ?string $updatedAt,
    ) {
    }

    public static function fromJob(Job $job): self
    {
        return new self(
            jobId: $job->getId()->toRfc4122(),
            status: $job->getStatus()->value,
            createdAt: $job->getCreatedAt()->format(\DateTimeInterface::ATOM),
            updatedAt: $job->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
        );
    }

    public function toArray(): array
    {
        return [
            'job_id'     => $this->jobId,
            'status'     => $this->status,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
