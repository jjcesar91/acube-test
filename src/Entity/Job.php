<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\InputFormat;
use App\Enum\JobStatus;
use App\Enum\OutputFormat;
use App\Repository\JobRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: JobRepository::class)]
#[ORM\Table(name: 'jobs')]
class Job
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\Column(type: 'string', length: 20, enumType: JobStatus::class)]
    private JobStatus $status;

    #[ORM\Column(type: 'string')]
    private string $inputFilePath;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $outputFilePath;

    #[ORM\Column(type: 'string', length: 10, enumType: InputFormat::class)]
    private InputFormat $inputFormat;

    #[ORM\Column(type: 'string', length: 10, enumType: OutputFormat::class)]
    private OutputFormat $outputFormat;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $errorMessage;

    public function __construct(
        string $inputFilePath,
        InputFormat $inputFormat,
        OutputFormat $outputFormat,
    ) {
        $this->id             = Uuid::v7();
        $this->status         = JobStatus::Pending;
        $this->inputFilePath  = $inputFilePath;
        $this->outputFilePath = null;
        $this->inputFormat    = $inputFormat;
        $this->outputFormat   = $outputFormat;
        $this->createdAt      = new \DateTimeImmutable();
        $this->updatedAt      = null;
        $this->errorMessage   = null;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getStatus(): JobStatus
    {
        return $this->status;
    }

    public function getInputFilePath(): string
    {
        return $this->inputFilePath;
    }

    public function getOutputFilePath(): ?string
    {
        return $this->outputFilePath;
    }

    public function getInputFormat(): InputFormat
    {
        return $this->inputFormat;
    }

    public function getOutputFormat(): OutputFormat
    {
        return $this->outputFormat;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function markAsProcessing(): void
    {
        $this->status    = JobStatus::Processing;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function markAsCompleted(string $outputFilePath): void
    {
        $this->status         = JobStatus::Completed;
        $this->outputFilePath = $outputFilePath;
        $this->updatedAt      = new \DateTimeImmutable();
    }

    public function markAsFailed(string $errorMessage): void
    {
        $this->status       = JobStatus::Failed;
        $this->errorMessage = $errorMessage;
        $this->updatedAt    = new \DateTimeImmutable();
    }
}
