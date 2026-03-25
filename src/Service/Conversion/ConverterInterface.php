<?php

declare(strict_types=1);

namespace App\Service\Conversion;

use App\Entity\Job;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.converter')]
interface ConverterInterface
{
    /**
     * Returns true if this converter handles the given job's input/output format combination.
     */
    public function supports(Job $job): bool;

    /**
     * Performs the conversion and returns the absolute path to the output file.
     *
     * @throws \RuntimeException if the conversion fails
     */
    public function convert(Job $job, string $outputDirectory): string;
}
