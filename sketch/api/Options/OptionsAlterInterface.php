<?php

declare(strict_types=1);

namespace Drupal\surface_sketch\Options;

/**
 * Another module's say in a list, wherever that list is used.
 *
 * This is the wide alter point: change the source once and every surface
 * key pointing at it follows. To change one key on one surface instead,
 * use a SurfaceAlterInterface.
 */
interface OptionsAlterInterface {

  public function applies(OptionsSourceInterface $source): bool;

  /**
   * @param array<string|int, string|\Stringable> $options
   *   Labels keyed by value.
   *
   * @return array<string|int, string|\Stringable>
   *   The list to use instead.
   */
  public function alterOptions(array $options, array $arguments): array;

}
