<?php

declare(strict_types=1);

namespace App\Service\Conversion;

use App\Entity\Job;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class ConverterFactory
{
    /** @param iterable<ConverterInterface> $converters */
    public function __construct(
        #[AutowireIterator('app.converter')]
        private readonly iterable $converters,
    ) {
    }

    /**
     * Returns the first converter that supports the given job's format combination.
     *
     * @throws \RuntimeException if no converter supports the format pair
     */
    public function getConverter(Job $job): ConverterInterface
    {
        foreach ($this->converters as $converter) {
            if ($converter->supports($job)) {
                return $converter;
            }
        }

        throw new \RuntimeException(
            sprintf(
                'No converter available for "%s" → "%s".',
                $job->getInputFormat()->value,
                $job->getOutputFormat()->value
            )
        );
    }
}
