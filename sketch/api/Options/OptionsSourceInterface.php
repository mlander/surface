<?php

declare(strict_types=1);

namespace Drupal\surface_sketch\Options;

/**
 * A named list of allowed values: countries, entity types, text formats.
 *
 * A surface never fetches a list. It points at a source with the
 * OptionsList constraint, and whoever needs the list asks the source.
 * Sources are where services live, so surfaces can stay plain objects.
 */
interface OptionsSourceInterface {

  /**
   * @param array<string, mixed> $arguments
   *   What the constraint passed along, e.g. a sibling key's value.
   *
   * @return array<string|int, string|\Stringable>
   *   Labels keyed by value.
   */
  public function getOptions(array $arguments = []): array;

}
