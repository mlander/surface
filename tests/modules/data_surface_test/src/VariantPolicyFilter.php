<?php

declare(strict_types=1);

namespace Drupal\data_surface_test;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\DataSurfaceFilterInterface;
use Drupal\data_surface\Refinement\ChoiceSet;

/**
 * A policy filter over one key's allowed values.
 *
 * Takes values away from whatever the contributions ended up offering,
 * which is what a policy is for. It can also be told to add one, which
 * is what a policy is not for: that is how the tests check that the
 * remove-only rule is enforced rather than documented.
 */
final class VariantPolicyFilter implements DataSurfaceFilterInterface {

  /**
   * Constructs a VariantPolicyFilter.
   *
   * @param string $key
   *   The surface key this policy has an opinion about.
   * @param array $remove
   *   The values it takes away.
   * @param array $add
   *   The values it puts back, which the narrowing check must refuse.
   */
  public function __construct(
    protected readonly string $key,
    protected readonly array $remove,
    protected readonly array $add = [],
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function filterDataDefinition(string $name, DataDefinitionInterface $definition, array $values): DataDefinitionInterface {
    $set = $name === $this->key ? ChoiceSet::of($definition) : NULL;
    if ($set === NULL) {
      return $definition;
    }
    $set->withValues(array_merge(array_diff($set->values, $this->remove), $this->add))
      ->applyTo($definition);
    return $definition;
  }

}
