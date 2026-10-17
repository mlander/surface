<?php

declare(strict_types=1);

namespace Drupal\data_surface\ResourceBuild;

/**
 * Every DerivedVariantsInterface service, found by the slot it fills.
 *
 * @internal
 */
final class DerivedVariants {

  /**
   * The derivers, keyed by the class with the slot and then by slot key.
   *
   * @var array<string, array<string, \Drupal\data_surface\ResourceBuild\DerivedVariantsInterface>>|null
   */
  protected ?array $bySlot = NULL;

  /**
   * Constructs the collection.
   *
   * @param iterable<\Drupal\data_surface\ResourceBuild\DerivedVariantsInterface> $derivers
   *   The services tagged `data_surface.derived_variants`.
   */
  public function __construct(
    protected readonly iterable $derivers = [],
  ) {
  }

  /**
   * Gets the deriver filling one slot, if any.
   *
   * @param class-string $resource
   *   The class with the slot.
   * @param string $key
   *   The slot's key.
   *
   * @return \Drupal\data_surface\ResourceBuild\DerivedVariantsInterface|null
   *   The deriver, or NULL when nothing derives variants for the slot.
   *
   * @throws \LogicException
   *   When two derivers name the same slot.
   */
  public function for(string $resource, string $key): ?DerivedVariantsInterface {
    return $this->all()[$resource][$key] ?? NULL;
  }

  /**
   * Says where each of a class's derived slots is filled from.
   *
   * @param class-string $resource
   *   The class with the slots.
   *
   * @return array<string, string>
   *   The source phrase, keyed by slot key.
   */
  public function sources(string $resource): array {
    return array_map(
      static fn (DerivedVariantsInterface $deriver): string => $deriver->source(),
      $this->all()[$resource] ?? [],
    );
  }

  /**
   * Indexes the derivers by the slot each fills.
   *
   * @return array<string, array<string, \Drupal\data_surface\ResourceBuild\DerivedVariantsInterface>>
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
          'Both %s and %s derive the variants for %s, at its "%s" slot; one slot has one deriver.',
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
