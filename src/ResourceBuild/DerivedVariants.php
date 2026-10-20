<?php

declare(strict_types=1);

namespace Drupal\data_surface\ResourceBuild;

/**
 * Every DerivedVariantsInterface service, found by the variants it adds to.
 *
 * @internal
 */
final class DerivedVariants {

  /**
   * The derivers, keyed by the class with the variants, then by their key.
   *
   * @var array<string, array<string, \Drupal\data_surface\ResourceBuild\DerivedVariantsInterface>>|null
   */
  protected ?array $byKey = NULL;

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
   * Gets the deriver for the variants at one key, if any.
   *
   * @param class-string $resource
   *   The class with the variants.
   * @param string $key
   *   The key the variants are attached at.
   *
   * @return \Drupal\data_surface\ResourceBuild\DerivedVariantsInterface|null
   *   The deriver, or NULL when nothing derives variants for the key.
   *
   * @throws \LogicException
   *   When two derivers name the same key.
   */
  public function for(string $resource, string $key): ?DerivedVariantsInterface {
    return $this->all()[$resource][$key] ?? NULL;
  }

  /**
   * Says where each of a class's derived variants come from.
   *
   * @param class-string $resource
   *   The class with the variants.
   *
   * @return array<string, string>
   *   The source phrase, keyed by the key the variants are attached at.
   */
  public function sources(string $resource): array {
    return array_map(
      static fn (DerivedVariantsInterface $deriver): string => $deriver->source(),
      $this->all()[$resource] ?? [],
    );
  }

  /**
   * Indexes the derivers by the variants each adds to.
   *
   * @return array<string, array<string, \Drupal\data_surface\ResourceBuild\DerivedVariantsInterface>>
   *   The derivers.
   */
  protected function all(): array {
    if ($this->byKey !== NULL) {
      return $this->byKey;
    }
    $this->byKey = [];
    foreach ($this->derivers as $deriver) {
      [$holder, $key] = $deriver->variantsAt();
      if (isset($this->byKey[$holder][$key])) {
        throw new \LogicException(sprintf(
          'Both %s and %s derive the variants for %s, at its "%s" key; the variants at one key have one deriver.',
          get_class($this->byKey[$holder][$key]),
          get_class($deriver),
          $holder,
          $key,
        ));
      }
      $this->byKey[$holder][$key] = $deriver;
    }
    return $this->byKey;
  }

}
