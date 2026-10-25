<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples_retitle\Hook;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\data_surface_examples_retitle\Retitle;

/**
 * Example 17: writes its three pages, and locks one title on request.
 *
 * The pages are written after every module installed with it, so the
 * page bundle the module ships as optional config is there, which it is
 * not yet when its own install hook runs beside node's. Nothing is
 * written during a configuration import.
 *
 * The lock is the kind of answer another module gives through core's
 * field access: while the State flag is set, nobody may edit the title of
 * the third page. The retitle never names this module; it asks core, and
 * core asks this hook.
 */
final class RetitleHooks {

  /**
   * Constructs RetitleHooks.
   *
   * @param \Drupal\Core\DependencyInjection\ClassResolverInterface $classResolver
   *   The class resolver, which builds the writer of the pages.
   * @param \Drupal\Core\State\StateInterface $state
   *   The State, which holds the ids and the flag.
   */
  public function __construct(
    protected readonly ClassResolverInterface $classResolver,
    protected readonly StateInterface $state,
  ) {}

  /**
   * Implements hook_modules_installed().
   */
  #[Hook('modules_installed')]
  public function modulesInstalled(array $modules, bool $is_syncing): void {
    if (!$is_syncing && in_array('data_surface_examples_retitle', $modules, TRUE)) {
      $this->classResolver->getInstanceFromDefinition(Retitle::class)->writeNodes();
    }
  }

  /**
   * Implements hook_entity_field_access().
   *
   * Forbids editing the third page's title while the flag is set; says
   * nothing of any other field or page. What it read is State, so its
   * answer about that page is never cached.
   */
  #[Hook('entity_field_access')]
  public function entityFieldAccess(string $operation, FieldDefinitionInterface $field_definition, AccountInterface $account, ?FieldItemListInterface $items = NULL): AccessResultInterface {
    $entity = $items?->getEntity();
    if ($operation !== 'edit' || $field_definition->getName() !== 'title' || $entity === NULL || $entity->getEntityTypeId() !== 'node') {
      return AccessResult::neutral();
    }
    $third = $this->state->get(Retitle::NODES, [])['three'] ?? NULL;
    if ($third === NULL || (int) $entity->id() !== (int) $third) {
      return AccessResult::neutral();
    }
    return $this->state->get(Retitle::LOCKED, FALSE)
      ? AccessResult::forbidden('The menu is at the printer, so its title is locked.')->setCacheMaxAge(0)
      : AccessResult::neutral()->setCacheMaxAge(0);
  }

}
