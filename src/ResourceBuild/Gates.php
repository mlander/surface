<?php

declare(strict_types=1);

namespace Drupal\surface\ResourceBuild;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Access\AccessResultReasonInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Answers the gates an operation borrows, for the thing a call is about.
 *
 * Each gate is answered by what owns it, and by nothing copied from it:
 * another resource's operation as that operation is answered when it is
 * asked on its own (ResourcesInterface::access(): its permission, its
 * gates, its access class and every alter answering access for it), and
 * core's access through the entity type's access control handler, for an
 * entity (access()) or one of its fields (fieldAccess()), with the
 * cacheability core returns. Field access does not include entity access,
 * which is why an entity's own form asks both, and a gate list may too.
 *
 * Decision: see docs/decisions.md#a-gate-is-borrowed-never-copied.
 *
 * @internal
 */
final class Gates implements ContainerInjectionInterface {

  use DependencySerializationTrait;

  /**
   * Constructs Gates.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, whose handlers answer core's gates and whose
   *   storage loads the thing a gate asks about.
   * @param \Drupal\surface\ResourceBuild\ResourceRegistry $registry
   *   The registry, which names an operation gate's operation.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly ResourceRegistry $registry,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('entity_type.manager'), $container->get('surface.resource_registry'));
  }

  /**
   * Names the entity type a gate asks about.
   *
   * Core's gates name it. An operation gate asks about what its operation
   * takes: the entity its one required entity parameter takes, or none
   * when the operation needs nothing, which is then asked once with no
   * subject.
   *
   * @param \Drupal\surface\ResourceBuild\Gate $gate
   *   The gate.
   *
   * @return array{0: string|null, 1: string|null}
   *   The entity type id, and for an operation gate the parameter the
   *   entity is handed as; NULL for each when the gate asks about nothing.
   *
   * @throws \LogicException
   *   When the gated operation needs something that is no entity.
   */
  public function subjectOf(Gate $gate): array {
    if ($gate->kind !== Gate::OPERATION) {
      return [(string) $gate->entityType, NULL];
    }
    $operation = $this->registry->getOperation($this->registry->getDefinition((string) $gate->resource)->name(), $gate->operation);
    foreach ($operation->parameters as $parameter) {
      if ($parameter->optional) {
        continue;
      }
      $entity_type = $parameter->entityType ?? $this->entityTypeOf((string) $parameter->type);
      if ($entity_type === NULL) {
        throw new \LogicException(sprintf(
          'The gate "%s" names an operation that needs %s, which is no entity: a gate hands the operation it names the thing a call is about, and nothing else.',
          $gate->spelled,
          $parameter->describe(),
        ));
      }
      return [$entity_type, $parameter->name];
    }
    return [NULL, NULL];
  }

  /**
   * Loads the thing a gate asks about.
   *
   * @param string $entity_type_id
   *   Its entity type.
   * @param mixed $id
   *   Its id.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The entity, or NULL when the id names none.
   */
  public function load(string $entity_type_id, mixed $id): ?EntityInterface {
    if (!is_int($id) && !is_string($id) || $id === '') {
      return NULL;
    }
    return $this->entityTypeManager->getStorage($entity_type_id)->load($id);
  }

  /**
   * Answers one gate for the thing a call is about.
   *
   * @param \Drupal\surface\ResourceBuild\Gate $gate
   *   The gate.
   * @param \Drupal\Core\Entity\EntityInterface|null $subject
   *   The thing, or NULL for an operation gate that asks about none.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account the call is made as.
   * @param \Drupal\surface\ResourceBuild\ResourcesInterface $resources
   *   The build step, which answers an operation gate.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   What answered it, as it answered, with the cacheability it read.
   */
  public function answer(Gate $gate, ?EntityInterface $subject, AccountInterface $account, ResourcesInterface $resources): AccessResultInterface {
    if ($gate->kind === Gate::OPERATION) {
      [, $parameter] = $this->subjectOf($gate);
      try {
        $context = $resources->operation((string) $gate->resource, $gate->operation, $parameter === NULL || $subject === NULL ? [] : [$parameter => $subject]);
      }
      catch (\InvalidArgumentException $e) {
        // The operation does not take this thing: a page's edit, handed
        // an article.
        return AccessResult::forbidden($e->getMessage());
      }
      return $resources->access((string) $gate->resource, $context, $account);
    }
    if ($subject === NULL) {
      return AccessResult::forbidden(sprintf('Nothing names the %s the gate "%s" asks about.', $gate->entityType, $gate->spelled));
    }
    $handler = $this->entityTypeManager->getAccessControlHandler($subject->getEntityTypeId());
    if ($gate->kind === Gate::ENTITY) {
      return $handler->access($subject, $gate->operation, $account, TRUE);
    }
    if (!$subject instanceof FieldableEntityInterface || !$subject->hasField((string) $gate->field)) {
      return AccessResult::forbidden(sprintf('%s has no %s field.', $subject->label(), $gate->field))->addCacheableDependency($subject);
    }
    $items = $subject->get((string) $gate->field);
    return $handler->fieldAccess($gate->operation, $items->getFieldDefinition(), $account, $items, TRUE);
  }

  /**
   * Says why a gate refused, in the sentence a caller is shown.
   *
   * What it may not do to what, and the reason the owner gave when it
   * gave one: core's "The 'edit any page content' permission is
   * required." Untranslated, as every access reason is.
   *
   * @param \Drupal\surface\ResourceBuild\Gate $gate
   *   The gate.
   * @param \Drupal\Core\Entity\EntityInterface|null $subject
   *   The thing it was asked about.
   * @param \Drupal\Core\Access\AccessResultInterface $answer
   *   What refused.
   *
   * @return string
   *   The sentence.
   */
  public function refusal(Gate $gate, ?EntityInterface $subject, AccessResultInterface $answer): string {
    $label = $subject === NULL ? (string) ($gate->resource ?? $gate->entityType) : (string) $subject->label();
    $field = $gate->kind === Gate::FIELD && $subject instanceof FieldableEntityInterface && $subject->hasField((string) $gate->field)
      ? mb_strtolower((string) $subject->get((string) $gate->field)->getFieldDefinition()->getLabel())
      : (string) $gate->field;
    $refused = $gate->kind === Gate::FIELD
      ? sprintf('You may not %s the %s of %s', $gate->operation, $field, $label)
      : sprintf('You may not %s %s', $gate->operation, $label);
    $reason = $answer instanceof AccessResultReasonInterface ? (string) $answer->getReason() : '';
    // An operation gate's own gate already said what may not be done.
    if (str_starts_with($reason, $refused . ':')) {
      return $reason;
    }
    return $reason === '' ? $refused . '.' : $refused . ': ' . $reason;
  }

  /**
   * Finds the entity type whose entities are of a class or interface.
   *
   * @param string $type
   *   The parameter's declared class or interface.
   *
   * @return string|null
   *   The one entity type whose class is it or implements it, or NULL when
   *   none is, or more than one.
   */
  protected function entityTypeOf(string $type): ?string {
    if (!is_a($type, EntityInterface::class, TRUE)) {
      return NULL;
    }
    $found = [];
    foreach ($this->entityTypeManager->getDefinitions() as $id => $definition) {
      if ($definition->entityClassImplements($type)) {
        $found[] = (string) $id;
      }
    }
    return count($found) === 1 ? $found[0] : NULL;
  }

}
