<?php

declare(strict_types=1);

namespace Drupal\surface_examples_entity\Access;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\surface\Resource\AccessAnswer;
use Drupal\surface\Resource\OperationAccessInterface;
use Drupal\surface\Resource\OperationContext;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Field access, as a field item child's access: open, locked or omitted.
 *
 * The entity's access control handler is asked about the field twice,
 * with the field's items: may this account edit it (open), and if not,
 * may it view it (locked: shown and fixed), and if not, the field is not
 * there at all (omitted). The items are the entity's own when the
 * context knows its id, and a throwaway entity of the bundle's when it
 * is being created, as core's own form makes one: fieldAccess() takes
 * items, and a field has none without an entity. What both answers read
 * is carried on the answer, so the data surface built from it varies by
 * it.
 *
 * A context that does not say which field of which entity type, as one
 * the item inherits when neither of its operations can be invoked (a
 * tool's static definition, built before any subject), has nothing to
 * ask about, and says nothing: the field is described as it may be, and
 * the definition per call, built in the real context, asks. Saying
 * nothing, it is runtime: the subject decides.
 *
 * Core's answers say which kind each answer is: one that read the entity
 * carries its cacheability and is runtime, one that read permissions
 * alone is static. One that read nothing at all (no hook restricts the
 * field) is the same for every account, which is static, and is said so
 * with `user.permissions`, the widest the account alone can vary by: an
 * answer carrying nothing promised nothing and would read runtime.
 *
 * Decision: see docs/decisions.md#field-access-is-child-access.
 * Decision: see
 * docs/decisions.md#access-is-static-or-runtime-read-from-its-cacheability.
 */
final class FieldItemAccess implements OperationAccessInterface, ContainerInjectionInterface {

  /**
   * The throwaway entities made so far, by entity type and bundle.
   *
   * @var array<string, \Drupal\Core\Entity\ContentEntityInterface>
   */
  protected array $stubs = [];

  /**
   * Constructs a FieldItemAccess.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('entity_type.manager'));
  }

  /**
   * {@inheritdoc}
   */
  public function access(OperationContext $context, AccountInterface $account): AccessAnswer {
    if (!isset($context->known['entity_type'], $context->known['field_name'])) {
      return AccessAnswer::open();
    }
    $entity = $this->entity($context);
    $field_name = (string) $context->known['field_name'];
    if ($entity === NULL || !$entity->hasField($field_name)) {
      return AccessAnswer::omitted(sprintf('There is no %s field to ask about.', $field_name));
    }
    $items = $entity->get($field_name);
    $handler = $this->entityTypeManager->getAccessControlHandler($entity->getEntityTypeId());
    $edit = $handler->fieldAccess('edit', $items->getFieldDefinition(), $account, $items, TRUE);
    if ($edit->isAllowed()) {
      return static::read(AccessAnswer::open(), $edit);
    }
    $view = $handler->fieldAccess('view', $items->getFieldDefinition(), $account, $items, TRUE);
    $answer = $view->isAllowed()
      ? AccessAnswer::locked(sprintf('The %s field may be seen and not changed.', $field_name))
      : AccessAnswer::omitted(sprintf('The %s field may not be seen.', $field_name));
    return static::read($answer, $edit, $view);
  }

  /**
   * Carries what core's answers read, saying which kind the answer is.
   *
   * @param \Drupal\surface\Resource\AccessAnswer $answer
   *   The answer.
   * @param \Drupal\Core\Access\AccessResultInterface ...$results
   *   Core's field access answers it was read from.
   *
   * @return \Drupal\surface\Resource\AccessAnswer
   *   The answer, depending on what they read; on `user.permissions` when
   *   they read nothing, which is the same for every account.
   */
  protected static function read(AccessAnswer $answer, AccessResultInterface ...$results): AccessAnswer {
    foreach ($results as $result) {
      $answer->addCacheableDependency($result);
    }
    if ($answer->getCacheContexts() === [] && $answer->getCacheTags() === [] && $answer->getCacheMaxAge() === Cache::PERMANENT) {
      $answer->addCacheContexts(['user.permissions']);
    }
    return $answer;
  }

  /**
   * Gets the entity the field's items belong to.
   *
   * @param \Drupal\surface\Resource\OperationContext $context
   *   The item's context, knowing the entity type, the bundle and, for
   *   an entity that exists, its id.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface|null
   *   The entity, a throwaway one of the bundle when there is no id, or
   *   NULL when the id names nothing.
   */
  protected function entity(OperationContext $context): ?ContentEntityInterface {
    $entity_type_id = (string) ($context->known['entity_type'] ?? '');
    $bundle = (string) ($context->known['bundle'] ?? '');
    $id = $context->known['id'] ?? NULL;
    $storage = $this->entityTypeManager->getStorage($entity_type_id);
    if ($id !== NULL) {
      $entity = $storage->load($id);
      return $entity instanceof ContentEntityInterface ? $entity : NULL;
    }
    // Hole: field access takes items, so a field of an entity not yet
    // made is asked about on a throwaway one. See docs/entities.md#holes.
    if (!isset($this->stubs[$entity_type_id . ':' . $bundle])) {
      $bundle_key = (string) $this->entityTypeManager->getDefinition($entity_type_id)->getKey('bundle');
      $stub = $storage->create($bundle_key === '' ? [] : [$bundle_key => $bundle]);
      assert($stub instanceof ContentEntityInterface);
      $this->stubs[$entity_type_id . ':' . $bundle] = $stub;
    }
    return $this->stubs[$entity_type_id . ':' . $bundle];
  }

}
