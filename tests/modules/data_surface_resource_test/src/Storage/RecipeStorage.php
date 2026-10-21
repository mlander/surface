<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Storage;

use Drupal\Core\State\StateInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\Resource\ResourceStorageInterface;

/**
 * Keeps recipes in state, by the identity the context knows.
 */
final class RecipeStorage implements ResourceStorageInterface {

  /**
   * Constructs a RecipeStorage.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service, autowired.
   */
  public function __construct(protected readonly StateInterface $state) {}

  /**
   * Gets the state key a recipe is kept under.
   *
   * @param string $kitchen
   *   The kitchen.
   * @param string $name
   *   The recipe name.
   *
   * @return string
   *   The key.
   */
  public static function key(string $kitchen, string $name): string {
    return 'data_surface_resource_test.recipe.' . $kitchen . '.' . $name;
  }

  /**
   * {@inheritdoc}
   */
  public function load(OperationContext $context): array {
    if ($context->creates || !isset($context->known['kitchen'], $context->known['name'])) {
      return [];
    }
    return $this->state->get(self::key($context->known['kitchen'], $context->known['name']), []);
  }

  /**
   * {@inheritdoc}
   *
   * State has no schema and refuses nothing, so what would be stored is
   * what was accepted.
   */
  public function prepare(OperationContext $context, array $values): array {
    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function commit(OperationContext $context, array $prepared): void {
    $values = $prepared;
    $this->state->set(self::key((string) $values['kitchen'], (string) $values['name']), $values);
  }

}
