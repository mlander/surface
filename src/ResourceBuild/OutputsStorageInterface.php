<?php

declare(strict_types=1);

namespace Drupal\surface\ResourceBuild;

use Drupal\surface\SurfaceInterface;
use Drupal\surface\Pipeline\PreparedValues;

/**
 * A pipeline storage that reads back what a call answers with.
 *
 * The doors ask it for a call's outputs once the pipeline has run: the
 * tool, and the HTTP door's write and list. StorageAdapter answers it
 * from the resource's storage; a storage that stands in front of the
 * adapter is to answer it too, so a door reads outputs from whichever
 * storage it was handed and never asks which class that is.
 *
 * Internal beside StorageAdapter, a final internal class: nothing an
 * author writes implements it or calls it.
 *
 * @see \Drupal\surface\ResourceBuild\StorageAdapter::outputs()
 *
 * @internal
 */
interface OutputsStorageInterface {

  /**
   * Reads what a call answers with, from its storage.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface, whose output definitions say which keys are
   *   outputs.
   * @param array $values
   *   The accepted values.
   * @param \Drupal\surface\Pipeline\PreparedValues|null $prepared
   *   What prepare() returned, for an operation that changes nothing or a
   *   dry run; NULL for one that was committed.
   *
   * @return array
   *   The outputs the storage could say, keyed by output name; empty for
   *   a data surface that declares none.
   */
  public function outputs(SurfaceInterface $surface, array $values, ?PreparedValues $prepared = NULL): array;

}
