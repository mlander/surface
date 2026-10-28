<?php

declare(strict_types=1);

namespace Drupal\data_surface;

/**
 * A child not built yet, which builds itself the first time it is read.
 *
 * What an Attachment holds in place of its sealed child for a variant the
 * site lists: the build step seals the parent with each such variant's
 * value, label and access answer, and builds the variant itself only when
 * a door reads it (the form drawing the chosen one, the pipeline judging
 * it, a document stating it). Once built it is kept, so every later read
 * answers the same object, and a copy of the attachment (withReads(),
 * readFrom()) shares it.
 *
 * Decision: see
 * docs/decisions.md#the-static-build-is-cached-the-rest-is-per-request.
 *
 * @internal
 *   Made by the build step alone; nothing an author writes touches it.
 */
interface SettlesChildInterface {

  /**
   * Builds the child, once, and answers it.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The sealed child, the same object on every call.
   *
   * @throws \LogicException
   *   When the child cannot be built: what the build would have refused
   *   had it built the child with its parent.
   */
  public function settle(): DataSurfaceInterface;

  /**
   * Gets the child if it was built already.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface|null
   *   The child, or NULL when nothing has read it yet.
   */
  public function settled(): ?DataSurfaceInterface;

  /**
   * Answers whether the child, built or not, holds no secret at any depth.
   *
   * Known without building it for a structure read from a document that
   * states none (SchemaReader::holdsNoSecret()), so a door that walks
   * every variant for its secrets builds none of those.
   *
   * @return bool
   *   TRUE when nothing in the child is secret; FALSE when it may be.
   */
  public function holdsNoSecret(): bool;

}
