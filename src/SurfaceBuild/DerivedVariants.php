<?php

declare(strict_types=1);

namespace Drupal\data_surface\SurfaceBuild;

/**
 * Every DerivedVariantsInterface service, found by the slot it fills.
 *
 * @internal
 */
final class DerivedVariants {

  /**
   * The derivers, keyed by surface class and then by slot key.
   *
   * @var array<string, array<string, \Drupal\data_surface\SurfaceBuild\DerivedVariantsInterface>>|null
   */
  protected ?array $bySlot = NULL;

  /**
   * Constructs the collection.
   *
   * @param iterable<\Drupal\data_surface\SurfaceBuild\DerivedVariantsInterface> $derivers
   *   The services tagged `data_surface.derived_variants`.
   */
  public function __construct(
    protected readonly iterable $derivers = [],
  ) {
  }

  /**
   * Gets the deriver filling one slot, if any.
   *
   * @param class-string $surface
   *   The surface class.
   * @param string $key
   *   The slot's key.
   *
   * @return \Drupal\data_surface\SurfaceBuild\DerivedVariantsInterface|null
   *   The deriver, or NULL when nothing derives variants for the slot.
   *
   * @throws \LogicException
   *   When two derivers name the same slot.
   */
  public function for(string $surface, string $key): ?DerivedVariantsInterface {
    return $this->all()[$surface][$key] ?? NULL;
  }

  /**
   * Says where each of a surface's derived slots is filled from.
   *
   * @param class-string $surface
   *   The surface class.
   *
   * @return array<string, string>
   *   The source phrase, keyed by slot key.
   */
  public function sources(string $surface): array {
    return array_map(
      static fn (DerivedVariantsInterface $deriver): string => $deriver->source(),
      $this->all()[$surface] ?? [],
    );
  }

  /**
   * Indexes the derivers by the slot each fills.
   *
   * @return array<string, array<string, \Drupal\data_surface\SurfaceBuild\DerivedVariantsInterface>>
   *   The derivers.
   */
  protected function all(): array {
    if ($this->bySlot !== NULL) {
      return $this->bySlot;
    }
    $this->bySlot = [];
    foreach ($this->derivers as $deriver) {
      [$surface, $key] = $deriver->slot();
      if (isset($this->bySlot[$surface][$key])) {
        throw new \LogicException(sprintf(
          'Both %s and %s derive the variants of the %s surface\'s "%s" slot; one slot has one deriver.',
          get_class($this->bySlot[$surface][$key]),
          get_class($deriver),
          $surface,
          $key,
        ));
      }
      $this->bySlot[$surface][$key] = $deriver;
    }
    return $this->bySlot;
  }

}
