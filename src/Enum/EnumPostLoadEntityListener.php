<?php

declare(strict_types = 1);

namespace Consistence\Doctrine\Enum;

use Consistence\Enum\Enum;
use Doctrine\Common\Annotations\Reader;
use Doctrine\Common\Cache\ArrayCache;
use Doctrine\Common\Cache\Cache;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;

class EnumPostLoadEntityListener
{

	/** @var \Doctrine\Common\Annotations\Reader */
	private $annotationReader;

	/** @var \Doctrine\Common\Cache\Cache */
	private $enumFieldsCache;

	public function __construct(
		Reader $annotationReader,
		?Cache $enumFieldsCache = null
	)
	{
		$this->annotationReader = $annotationReader;
		$this->enumFieldsCache = $enumFieldsCache !== null ? $enumFieldsCache : new ArrayCache();
	}

	public function postLoad(LifecycleEventArgs $event): void
	{
		$entity = $event->getEntity();
		$entityManager = $event->getEntityManager();
		foreach ($this->getEnumFields($entityManager, get_class($entity)) as $fieldName => $enumClassName) {
			$this->processField($entityManager, $entity, $fieldName, $enumClassName);
		}
	}

	public function warmUpCache(
		EntityManager $entityManager,
		string $className
	): void
	{
		$this->getEnumFields($entityManager, $className);
	}

	/**
	 * @param \Doctrine\ORM\EntityManager $entityManager
	 * @param string $className
	 * @return string[] format: enum field name (string) => enum class name for field (string)
	 */
	private function getEnumFields(
		EntityManager $entityManager,
		string $className
	): array
	{
		$enumFields = $this->enumFieldsCache->fetch($className);
		if ($enumFields !== false) {
			return $enumFields;
		}

		$enumFields = [];
		$metadata = $this->getClassMetadata($entityManager, $className);
		foreach ($metadata->getFieldNames() as $fieldName) {
			$annotation = $this->annotationReader->getPropertyAnnotation(
				$metadata->getReflectionProperty($fieldName),
				EnumAnnotation::class
			);
			if ($annotation !== null) {
				$enumClassName = $metadata->fullyQualifiedClassName($annotation->class);
				if (!is_a($enumClassName, Enum::class, true)) {
					throw new \Consistence\Doctrine\Enum\NotEnumException($enumClassName);
				}
				$enumFields[$fieldName] = $enumClassName;
			}
		}

		$this->enumFieldsCache->save($className, $enumFields);

		return $enumFields;
	}

	private function processField(
		EntityManager $entityManager,
		object $entity,
		string $fieldName,
		string $enumClassName
	): void
	{
		$metadata = $this->getClassMetadata($entityManager, get_class($entity));
		$enumValue = $metadata->getFieldValue($entity, $fieldName);

		if ($enumValue === null) {
			return;
		}

		$enum = $enumClassName::get(($enumValue instanceof Enum) ? $enumValue->getValue() : $enumValue);
		$metadata->setFieldValue($entity, $fieldName, $enum);
		$entityManager->getUnitOfWork()->setOriginalEntityProperty(
			spl_object_id($entity),
			$fieldName,
			$enum
		);
	}

	private function getClassMetadata(
		EntityManager $entityManager,
		string $class
	): ClassMetadata
	{
		$metadata = $entityManager->getClassMetadata($class);
		if (!($metadata instanceof ClassMetadata)) {
			throw new \Consistence\Doctrine\Enum\UnsupportedClassMetadataException(get_class($metadata));
		}

		return $metadata;
	}

}
