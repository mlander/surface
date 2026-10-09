<?php

declare(strict_types=1);

namespace Drupal\data_surface\SurfaceBuild;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\data_surface\DataSurfaceBuilderInterface;
use Drupal\data_surface\DefinitionMetadata;

/**
 * An alter's shape: keys are mounted under the alter's module.
 *
 * The pattern draws an alter's key beside the owner's. The engine keeps
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
  public function attach(string $key, string $child): MapDataDefinition {
    throw new \LogicException(sprintf(
      'The %s alter cannot attach %s at "%s" yet: an alter\'s keys are mounted under third_party_settings.%s, and a subsurface inside that mount is not built.',
      $this->provider,
      $child,
      $key,
      $this->provider,
    ));
  }

  /**
   * The owner's keys this module offered more values on, in order.
   *
   * @var string[]
   */
  protected array $extended = [];

  /**
   * {@inheritdoc}
   *
   * Through the engine's own contribution, recorded under this module,
   * so the values are advertised as this module's and refined apart from
   * the owner's.
   */
  public function extendChoices(string $key, array $choices): static {
    if ($this->outputs) {
      throw new \LogicException(sprintf('The %s alter cannot offer more values on the output "%s": outputs are never sent, so they have nothing to choose from.', $this->provider, $key));
    }
    if ($this->builder->getDefinition($key) === NULL) {
      throw new \LogicException(sprintf('The %s alter offers more values on "%s", which the surface does not declare as an input.', $this->provider, $key));
    }
    $this->builder->extendChoices($key, $choices, $this->provider);
    if (!in_array($key, $this->extended, TRUE)) {
      $this->extended[] = $key;
    }
    return $this;
  }

  /**
   * Gets the owner's keys this module offered more values on.
   *
   * @return string[]
   *   The keys, in the order they were extended.
   */
  public function extended(): array {
    return $this->extended;
  }

  /**
   * {@inheritdoc}
   *
   * This shape's own keys answer by their plain name first: they live in
   * this module's namespace, where another declaration of the same name
   * could not collide with them.
   */
  protected function find(string $key): ?DataDefinitionInterface {
    if (in_array($key, $this->keys, TRUE)) {
      return $this->builder->getThirdPartyDefinition($this->provider, $key, $this->outputs);
    }
    return parent::find($key);
  }

  /**
   * {@inheritdoc}
   */
  protected function ownMount(): ?string {
    return $this->outputs ? NULL : 'third_party_settings.' . $this->provider;
  }

  /**
   * {@inheritdoc}
   */
  protected function declare(string $key, DataDefinitionInterface $definition, mixed $default): void {
    // Decision: see docs/decisions.md#where-an-alters-keys-live.
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
