<?php

declare(strict_types=1);

namespace ADS\Bundle\ApiPlatformEventEngineBundle\PropertyMetadataFactory;

use ADS\Bundle\ApiPlatformEventEngineBundle\Message\ApiPlatformMessage;
use ADS\JsonImmutableObjects\HasPropertyExamples;
use ADS\Util\StringUtil;
use ADS\ValueObjects\ValueObject;
use ApiPlatform\Core\Metadata\Property\Factory\PropertyMetadataFactoryInterface;
use ApiPlatform\Core\Metadata\Property\PropertyMetadata;
use EventEngine\Data\ImmutableRecord;
use EventEngine\JsonSchema\JsonSchemaAwareRecord;
use InvalidArgumentException;
use phpDocumentor\Reflection\DocBlock;
use phpDocumentor\Reflection\DocBlockFactory;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;

use function in_array;
use function method_exists;
use function sprintf;

final class JsonSchemaPropertyMetadataFactory implements PropertyMetadataFactoryInterface
{
    /** @readonly */
    private DocBlockFactory $docBlockFactory;

    public function __construct(
        private PropertyMetadataFactoryInterface $decorated
    ) {
        $this->docBlockFactory = DocBlockFactory::createInstance();
    }

    /**
     * @param class-string $resourceClass
     * @param array<mixed> $options
     */
    public function create(string $resourceClass, string $property, array $options = []): PropertyMetadata
    {
        $property = StringUtil::camelize($property); // todo change the way we work with camelize and decamilize
        $propertyMetadata = $this->decorated->create($resourceClass, $property, $options);
        /** @var ReflectionClass<ImmutableRecord> $reflectionClass */
        $reflectionClass = new ReflectionClass($resourceClass);

        if (! $reflectionClass->implementsInterface(JsonSchemaAwareRecord::class)) {
            return $propertyMetadata;
        }

        $schema = $resourceClass::__schema()->toArray();
        $propertySchema = $schema['properties'][$property] ?? [];

        $this
            ->addDefault($propertyMetadata, $resourceClass, $property)
            ->addDescription($propertyMetadata, $resourceClass, $property, $reflectionClass, $propertySchema)
            ->addExample($propertyMetadata, $resourceClass, $property, $reflectionClass)
            ->addDeprecated($propertyMetadata, $property, $reflectionClass);

        return $propertyMetadata
            ->withRequired(in_array($property, $schema['required'] ?? []))
            ->withReadable(true)
            ->withWritable(true)
            ->withReadableLink(true);
    }

    private function addDefault(PropertyMetadata &$propertyMetadata, string $resourceClass, string $property): self
    {
        try {
            if (
                method_exists($resourceClass, 'propertyDefault')
                && method_exists($resourceClass, 'defaultProperties')
            ) {
                $default = $resourceClass::propertyDefault($property, $resourceClass::defaultProperties());
            }
        } catch (RuntimeException) {
        }

        $propertyMetadata = $propertyMetadata->withDefault($default ?? null);

        return $this;
    }

    /**
     * @param ReflectionClass<ImmutableRecord> $reflectionClass
     * @param array<mixed> $propertySchema
     */
    private function addDescription(
        PropertyMetadata &$propertyMetadata,
        string $resourceClass,
        string $property,
        ReflectionClass $reflectionClass,
        array $propertySchema
    ): self {
        /** @var ReflectionNamedType|null $propertyType */
        $propertyType = $reflectionClass->hasProperty($property)
            ? $reflectionClass->getProperty($property)->getType()
            : null;

        $patchPropertyDescription = $reflectionClass->implementsInterface(ApiPlatformMessage::class)
        && $resourceClass::__httpMethod() === Request::METHOD_PATCH
        && isset($propertyType)
        && $propertyType->allowsNull()
            ? sprintf(
                "\n If '%s' is not added in the payload, then it will not be used.",
                StringUtil::decamelize($property)
            )
            : '';

        if ($propertySchema['description'] ?? false) {
            $propertyMetadata = $propertyMetadata->withDescription(
                $propertySchema['description'] . $patchPropertyDescription
            );

            return $this;
        }

        if (isset($propertyType) && ! $propertyType->isBuiltin()) {
            // Get the description of the value object
            /** @var class-string $className */
            $className = $propertyType->getName();
            $propertyReflectionClass = new ReflectionClass($className);

            try {
                $docBlock = $this->docBlockFactory->create($propertyReflectionClass);
                $description = $docBlock->getDescription()->render();
                $propertyMetadata = $propertyMetadata->withDescription(
                    sprintf(
                        '%s%s%s',
                        $docBlock->getSummary(),
                        ! empty($description) ? "\n" . $description : $description,
                        $patchPropertyDescription
                    )
                );
            } catch (InvalidArgumentException) {
            }

            return $this;
        }

        if ($patchPropertyDescription) {
            $propertyMetadata = $propertyMetadata->withDescription(
                $propertyMetadata->getDescription() ?? '' . $patchPropertyDescription
            );
        }

        return $this;
    }

    /**
     * @param ReflectionClass<ImmutableRecord> $reflectionClass
     */
    private function addExample(
        PropertyMetadata &$propertyMetadata,
        string $resourceClass,
        string $property,
        ReflectionClass $reflectionClass
    ): self {
        if ($reflectionClass->implementsInterface(HasPropertyExamples::class)) {
            $examples = $resourceClass::examples();
            $example = $examples[$property] ?? null;

            if ($example) {
                if ($example instanceof ValueObject) {
                    $example = $example->toValue();
                }

                $propertyMetadata = $propertyMetadata->withExample($example);

                return $this;
            }
        }

        $tags = $this->docTagsFromProperty($reflectionClass, $property, 'example');

        if (empty($tags)) {
            return $this;
        }

        /** @var DocBlock\Tags\Example $exampleTag */
        $exampleTag = $tags[0];

        $propertyMetadata = $propertyMetadata->withExample((string) $exampleTag);

        return $this;
    }

    /**
     * @param ReflectionClass<ImmutableRecord> $reflectionClass
     */
    private function addDeprecated(
        PropertyMetadata &$propertyMetadata,
        string $property,
        ReflectionClass $reflectionClass
    ): self {
        $tags = $this->docTagsFromProperty($reflectionClass, $property, 'deprecated');

        if (empty($tags)) {
            return $this;
        }

        /** @var DocBlock\Tags\Deprecated $deprecatedTag */
        $deprecatedTag = $tags[0];
        $reason = (string) $deprecatedTag;

        $propertyMetadata = $propertyMetadata->withAttributes(
            [
                'deprecation_reason' =>  empty($reason) ? 'deprecated' : $reason,
            ]
        );

        return $this;
    }

    /**
     * @param ReflectionClass<ImmutableRecord> $reflectionClass
     *
     * @return array<DocBlock\Tag>
     */
    private function docTagsFromProperty(ReflectionClass $reflectionClass, string $property, string $tagName): array
    {
        if (! $reflectionClass->hasProperty($property)) {
            return [];
        }

        $reflectionProperty = $reflectionClass->getProperty($property);

        try {
            $docBlock = $this->docBlockFactory->create($reflectionProperty);
            $tags = $docBlock->getTagsByName($tagName);
        } catch (InvalidArgumentException) {
            return [];
        }

        return $tags;
    }
}
