<?php

declare(strict_types=1);

namespace App\Request;

use App\Enum\InputFormat;
use App\Enum\OutputFormat;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

final readonly class CreateJobRequest
{
    public function __construct(
        public UploadedFile $file,
        public OutputFormat $outputFormat,
        public InputFormat $inputFormat,
    ) {
    }

    /**
     * @throws \InvalidArgumentException if any field is missing or invalid
     */
    public static function fromRequest(Request $request): self
    {
        $file = $request->files->get('file');

        if (!$file instanceof UploadedFile) {
            throw new \InvalidArgumentException('The "file" field is required.');
        }

        $outputFormatRaw = $request->request->get('output_format');

        if (null === $outputFormatRaw) {
            throw new \InvalidArgumentException('The "output_format" field is required.');
        }

        $outputFormat = OutputFormat::tryFrom((string) $outputFormatRaw);

        if (null === $outputFormat) {
            throw new \InvalidArgumentException(
                sprintf('Invalid output_format. Allowed values: %s.', implode(', ', array_column(OutputFormat::cases(), 'value')))
            );
        }

        $guessedExtension = $file->guessExtension();

        if (null === $guessedExtension) {
            throw new \InvalidArgumentException(
                sprintf('Unsupported file format. Supported: %s.', implode(', ', array_column(InputFormat::cases(), 'value')))
            );
        }

        $inputFormat = InputFormat::tryFrom(strtolower($guessedExtension));
        if (null === $inputFormat) {
            throw new \InvalidArgumentException(
                sprintf('Unsupported file format. Supported: %s.', implode(', ', array_column(InputFormat::cases(), 'value')))
            );
        }

        return new self($file, $outputFormat, $inputFormat);
    }
}
