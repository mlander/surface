<?php

declare(strict_types=1);

namespace Drupal\data_surface\SurfaceBuild;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\data_surface\DataSurfaceBuilderInterface;
use Drupal\data_surface\DefinitionMetadata;

/**
 * An alter's shape: keys are mounted under the alter's module.
 *
 * The sketch draws an alter's key beside the owner's. The engine keeps
 * every contribution in the contributor's own namespace instead —
 * third_party_settings.<module>.<key> for an input,
 * third_party_outputs.<module>.<key> for an output — and that is the
 * spelling kept here, because it is what lets the owner's target and
 * config schema store a contribution without knowing its keys, and what
 * lets two modules add a key of the same name without colliding.
 *
 * One of these serves every alter of one module on one build, so two
 * alters of the same module cannot add the same key either.
 *
 * @internal
 */
final class SurfaceShapeAdditions extends ShapeAdapterBase {

  /**
   * Constructs an alter's shape.
   *
   * @param \Drupal\data_surface\DataSurfaceBuilderInterface $builder
   *   The engine's builder the shape fills.
   * @param \Drupal\Core\TypedData\TypedDataManagerInterface $typed_data_manager
   *   The typed data manager.
   * @param string $provider
   *   The module the alter is in, which its keys are mounted under.
   * @param bool $outputs
   *   TRUE when this shape describes outputs rather than inputs.
   */
  public function __construct(
    DataSurfaceBuilderInterface $builder,
    TypedDataManagerInterface $typed_data_manager,
    protected readonly string $provider,
    bool $outputs = FALSE,
  ) {
    parent::__construct($builder, $typed_data_manager, $outputs);
  }

  /**
   * {@inheritdoc}
   */
  protected function declare(string $key, DataDefinitionInterface $definition, mixed $default): void {
    // phpcs:ignore Drupal.Files.LineLength.TooLong
    // SKETCH GAP: the sketch puts an alter's key beside the owner's; here it is mounted at third_party_settings.<module>.<key> (outputs: third_party_outputs) so the owner's storage and schema need not know it.
    if ($this->outputs) {
      if ($default !== NULL) {
        DefinitionMetadata::setDefaultValue($definition, $default);
      }
      $this->builder->setThirdPartyOutputDefinition($this->provider, $key, $definition);
      return;
    }
    $this->builder->setThirdPartyDefinition($this->provider, $key, $definition, $default);
  }

  /**
   * {@inheritdoc}
   */
  protected function isDeclared(string $key): bool {
    // The provider's namespace is this shape's alone, so the keys it has
    // added are the whole answer.
    return FALSE;
  }

}
