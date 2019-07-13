<?php

declare(strict_types=1);

namespace MsgPhp\Domain\Infrastructure\Elasticsearch;

use Elasticsearch\Client;
use MsgPhp\Domain\Projection\ProjectionTypeRegistry as BaseProjectionTypeRegistry;
use Psr\Log\LoggerInterface;

/**
 * @author Roland Franssen <franssen.roland@gmail.com>
 */
final class ProjectionTypeRegistry implements BaseProjectionTypeRegistry
{
    private const DEFAULT_PROPERTY_TYPE = 'text';

    private $client;
    private $index;
    /** @var array<string, class-string<DocumentMappingProvider>|array> */
    private $mappings;
    private $settings;
    private $logger;
    /** @var array<int, string>|null */
    private $types;
    /** @var array|null */
    private $indexParams;

    /**
     * @param array<string, class-string<DocumentMappingProvider>|array> $mappings
     */
    public function __construct(Client $client, string $index, array $mappings, array $settings = [], LoggerInterface $logger = null)
    {
        $this->client = $client;
        $this->index = $index;
        $this->mappings = $mappings;
        $this->settings = $settings;
        $this->logger = $logger;
    }

    public function all(): array
    {
        return $this->types ?? ($this->types = array_keys($this->getIndexParams()['body']['mappings'] ?? []));
    }

    public function initialize(): void
    {
        $indices = $this->client->indices();

        if ($indices->exists($params = ['index' => $this->index])) {
            return;
        }

        $indices->create($params + $this->getIndexParams());

        if (null !== $this->logger) {
            $this->logger->info('Initialized Elasticsearch index "{index}".', ['index' => $this->index]);
        }
    }

    public function destroy(): void
    {
        $indices = $this->client->indices();

        if (!$indices->exists($params = ['index' => $this->index])) {
            return;
        }

        $indices->delete($params);

        if (null !== $this->logger) {
            $this->logger->info('Destroyed Elasticsearch index "{index}".', ['index' => $this->index]);
        }
    }

    private function getIndexParams(): array
    {
        if (null !== $this->indexParams) {
            return $this->indexParams;
        }

        $params = [];

        if ($this->settings) {
            $params['body']['settings'] = $this->settings;
        }

        foreach ($this->provideMappings() as $type => $mapping) {
            foreach ($mapping as $property => $propertyMapping) {
                if (!\is_array($propertyMapping)) {
                    $propertyMapping = ['type' => $propertyMapping];
                } elseif (!isset($propertyMapping['type'])) {
                    $propertyMapping['type'] = self::DEFAULT_PROPERTY_TYPE;
                }

                $params['body']['mappings'][$type]['properties'][$property] = $propertyMapping;
            }
        }

        return $this->indexParams = $params;
    }

    private function provideMappings(): iterable
    {
        foreach ($this->mappings as $type => $mapping) {
            if (\is_string($mapping)) {
                yield from $mapping::provideDocumentMappings();
            } else {
                yield $type => $mapping;
            }
        }
    }
}
