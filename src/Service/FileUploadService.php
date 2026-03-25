<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\InputFormat;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class FileUploadService
{
    /**
     * CSV files are commonly detected as text/plain by finfo — both are accepted.
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED_MIME_TYPES = [
        'csv'  => ['text/csv', 'text/plain'],
        'json' => ['application/json'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'ods'  => ['application/vnd.oasis.opendocument.spreadsheet'],
    ];

    public function __construct(
        #[Autowire(param: 'app.upload_dir')]
        private readonly string $uploadDirectory,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Validates the uploaded file by MIME type and moves it to the upload directory.
     *
     * @throws \InvalidArgumentException if the MIME type does not match the declared format
     * @throws FileException             if the file cannot be moved
     */
    public function upload(UploadedFile $file, InputFormat $inputFormat): string
    {
        $this->validateMimeType($file, $inputFormat);

        $fileName = sprintf('%s_%s.%s', bin2hex(random_bytes(8)), time(), $inputFormat->value);

        $file->move($this->uploadDirectory, $fileName);

        $filePath = $this->uploadDirectory . DIRECTORY_SEPARATOR . $fileName;

        $this->logger->info('File uploaded successfully', [
            'path'   => $filePath,
            'format' => $inputFormat->value,
        ]);

        return $filePath;
    }

    private function validateMimeType(UploadedFile $file, InputFormat $inputFormat): void
    {
        $allowedMimes = self::ALLOWED_MIME_TYPES[$inputFormat->value] ?? [];

        if ([] === $allowedMimes) {
            throw new \InvalidArgumentException(
                sprintf('Unsupported input format: "%s"', $inputFormat->value)
            );
        }

        $actualMime = $file->getMimeType() ?? 'unknown';

        if (!in_array($actualMime, $allowedMimes, strict: true)) {
            $this->logger->warning('MIME type mismatch on upload', [
                'expected' => implode('|', $allowedMimes),
                'actual'   => $actualMime,
                'format'   => $inputFormat->value,
            ]);

            throw new \InvalidArgumentException(
                sprintf(
                    'Invalid MIME type "%s" for format "%s". Expected one of: %s.',
                    $actualMime,
                    $inputFormat->value,
                    implode(', ', $allowedMimes)
                )
            );
        }
    }
}
