<?php
declare(strict_types=1);

namespace Bairwell\HydratorProvider;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionObject;
use ReflectionProperty;
use RuntimeException;

/**
 * Class HydratorProvider.
 *
 * Hydrates entities.
 *
 * @package Providers
 */
class HydratorProvider implements LoggerAwareInterface, HydratorProviderInterface
{

    /**
     * @var array For caching.
     */
    private $cachedHydratedProperties;

    /**
     * @var LoggerInterface $logger
     */
    private $logger;

    /**
     * HydratorProvider constructor.
     * @param LoggerInterface $logger
     */
    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Logger.
     * @return LoggerInterface
     */
    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    /**
     * @param LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }


    /**
     * Hydrate an object into a entity.
     *
     * @param string $entityClassName The name of the entity class we are hydrating.
     * @param object|array $object The data object returned from the database.
     * @param array $mapping The mapping that should be used.
     * @return mixed An instance of the entityClass.
     * @throws ReflectionException
     */
    public function hydrateInto(string $entityClassName, $object, array $mapping)
    {
        if (!is_array($this->cachedHydratedProperties)) {
            $this->cachedHydratedProperties = [];
        }
        if (!isset($this->cachedHydratedProperties[$entityClassName])) {
            $props = (new ReflectionClass($entityClassName))->getProperties();
            $this->cachedHydratedProperties[$entityClassName] = [];
            foreach ($props as $property) {
                $this->cachedHydratedProperties[$entityClassName][$property->getName()] = $property;
            }
        }
        if (is_object($object)) {
            /**
             * Read the object and parse it.
             */
            $objectProperties = (new ReflectionObject($object))->getProperties(ReflectionProperty::IS_PUBLIC);
            $objectPropertiesByName = [];
            foreach ($objectProperties as $property) {
                $objectPropertiesByName[$property->getName()] = $property->getValue();
            }
        } else {
            $objectPropertiesByName = $object;
        }
        return $this->hydrateArrayIntoEntity($entityClassName, $objectPropertiesByName, $mapping);
    }

    /**
     * Hydrate an entity from a simple array.
     *
     * @param string $entityClassName
     * @param array $items
     * @return mixed
     */
    public function simpleHydrate(string $entityClassName, array $items)
    {
        $mapping = [];
        foreach ($items as $key => $value) {
            if (is_object($value)) {
                if ($value instanceof DateTimeInterface) {
                    $mapping[$key] = ['name' => $key, 'type' => DateTimeImmutable::class];
                } else {
                    throw new RuntimeException(
                        sprintf(
                            'Unaccepted source object %s for %s in simpleHydrate for entity %s',
                            get_class($value),
                            $key,
                            $entityClassName,
                        )
                    );
                }
            } elseif (is_int($value)) {
                $mapping[$key] = ['name' => $key, 'type' => 'int'];
            } elseif (is_string($value)) {
                $mapping[$key] = ['name' => $key, 'type' => 'string'];
            } else {
                throw new RuntimeException(
                    sprintf(
                        'Unaccepted source object %s for %s in simpleHydrate for entity %s',
                        get_class($value),
                        $key,
                        $entityClassName,
                        )
                );
            }
        }
        return $this->hydrateArrayIntoEntity($entityClassName, $items, $mapping);
    }

    /**
     * Hydrate an array into an entity.
     *
     * @param string $entityClassName
     * @param array $objectPropertiesByName
     * @param array $mapping
     * @return mixed
     */
    private function hydrateArrayIntoEntity(string $entityClassName, array $objectPropertiesByName, array $mapping)
    {
        /**
         * Create the new entity.
         */
        $entity = new $entityClassName();
        foreach ($mapping as $objectName => $propertyData) {
            /*
             * Read out our property data assigning the name and type where appropriate.
             */
            if (is_string($propertyData)) {
                $propertyName = $propertyData;
                $entityType = 'string';
            } else {
                if (!isset($propertyData['name'])) {
                    throw new RuntimeException(
                        sprintf(
                            'Missing `name` in hydrateMapping for entity %s object name %s',
                            $entityClassName,
                            $objectName
                        )
                    );
                }
                $propertyName = $propertyData['name'];
                $entityType = $propertyData['type'] ?: 'string';
            }
            /**
             * Check the hydration.
             */
            if (!isset($objectPropertiesByName[$objectName])) {
                $this->logger->warning(
                    'Hydrate failed: object does not have property {objectName} when hydrating {entityClassName}',
                    [
                        'objectName' => $objectName,
                        'entityClassName' => $entityClassName
                    ]
                );
                continue 1;
            }
            if (!isset($this->cachedHydratedProperties[$entityClassName][$propertyName])) {
                $this->logger->warning(
                    'Hydrate failed:  entity does not have property {propertyName} for {objectName} ' .
                    'when hydrating {entityClassName}',
                    [
                        'propertyName' => $propertyName,
                        'objectName' => $objectName,
                        'entityClassName' => $entityClassName
                    ]
                );
                continue 1;
            }
            $value = $objectPropertiesByName[$objectName];

            /** @var ReflectionProperty $entityProperty */
            $entityProperty = $this->cachedHydratedProperties[$entityClassName][$propertyName];
            $entityProperty->setAccessible(true);

            switch ($entityType) {
                case 'int':
                    if (is_numeric($value)) {
                        $value = (int)$value;
                    } else {
                        throw new RuntimeException(
                            sprintf(
                                'Unaccepted type %s for %s in hydrateMapping for entity %s object name %s',
                                is_object($value) ? get_class($value) : gettype($value),
                                $propertyName,
                                $entityClassName,
                                $objectName
                            )
                        );
                    }
                    break;
                case DateTimeImmutable::class:
                    if (is_string($value)) {
                        $value = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);
                    } elseif ($value instanceof DateTime) {
                        $value = DateTimeImmutable::createFromMutable($value);
                    } elseif (!$value instanceof DateTimeImmutable) {
                        throw new RuntimeException(
                            sprintf(
                                'Unaccepted type %s for %s in hydrateMapping for entity %s object name %s',
                                is_object($value) ? get_class($value) : gettype($value),
                                $propertyName,
                                $entityClassName,
                                $objectName
                            )
                        );
                    }
                    break;
                case 'string':
                default:
                    $value = (string)$value;
            }
            $entityProperty->setValue($entity, $value);
        }
        return $entity;
    }
}
