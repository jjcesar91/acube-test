<?php

declare(strict_types=1);

namespace App\Tests\Doubles;

use App\Entity\Job;
use App\Enum\OutputFormat;
use App\Service\Conversion\ConverterInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Test double for DummyConverter.
 *
 * Produces output files instantly (no sleep) so end-to-end tests complete in
 * milliseconds. Registered as the DummyConverter service in APP_ENV=test via
 * config/services_test.yaml.
 */
final class FastConverter implements ConverterInterface
{
    public function supports(Job $job): bool
    {
        return true;
    }

    public function convert(Job $job, string $outputDirectory): string
    {
        $outputPath = $outputDirectory
            . DIRECTORY_SEPARATOR
            . Uuid::v4()->toRfc4122()
            . '.'
            . $job->getOutputFormat()->value;

        $content = match ($job->getOutputFormat()) {
            OutputFormat::Json => '{"converted":true}',
            OutputFormat::Xml  => '<?xml version="1.0" encoding="UTF-8"?><conversion><converted>true</converted></conversion>',
        };

        file_put_contents($outputPath, $content);

        return $outputPath;
    }
}
