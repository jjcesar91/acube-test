<?php

declare(strict_types=1);

namespace App\Service\Conversion;

use App\Entity\Job;
use App\Enum\OutputFormat;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

final class DummyConverter implements ConverterInterface
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function supports(Job $job): bool
    {
        return true;
    }

    public function convert(Job $job, string $outputDirectory): string
    {
        $this->logger->debug('DummyConverter: starting conversion', [
            'jobId'        => $job->getId()->toRfc4122(),
            'inputFormat'  => $job->getInputFormat()->value,
            'outputFormat' => $job->getOutputFormat()->value,
        ]);

        sleep(130);

        $outputFileName = sprintf('%s.%s', Uuid::v4()->toRfc4122(), $job->getOutputFormat()->value);
        $outputFilePath = $outputDirectory . DIRECTORY_SEPARATOR . $outputFileName;

        $content = match ($job->getOutputFormat()) {
            OutputFormat::Json => json_encode([
                'converted' => true,
                'source'    => $job->getInputFormat()->value,
                'job_id'    => $job->getId()->toRfc4122(),
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            OutputFormat::Xml => implode("\n", [
                '<?xml version="1.0" encoding="UTF-8"?>',
                '<conversion>',
                '  <converted>true</converted>',
                sprintf('  <source>%s</source>', $job->getInputFormat()->value),
                sprintf('  <job_id>%s</job_id>', $job->getId()->toRfc4122()),
                '</conversion>',
            ]),
        };

        if (false === file_put_contents($outputFilePath, $content)) {
            throw new \RuntimeException(
                sprintf('Could not write output file to "%s"', $outputFilePath)
            );
        }

        $this->logger->debug('DummyConverter: conversion complete', [
            'outputPath' => $outputFilePath,
        ]);

        return $outputFilePath;
    }
}
