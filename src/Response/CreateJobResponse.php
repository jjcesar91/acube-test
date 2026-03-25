<?php

declare(strict_types=1);

namespace App\Response;

use App\Entity\Job;

final readonly class CreateJobResponse
{
    public function __construct(
        public string $jobId,
        public string $status,
    ) {
    }

    public static function fromJob(Job $job): self
    {
        return new self(
            jobId: $job->getId()->toRfc4122(),
            status: $job->getStatus()->value,
        );
    }

    public function toArray(): array
    {
        return [
            'job_id' => $this->jobId,
            'status' => $this->status,
        ];
    }
}
