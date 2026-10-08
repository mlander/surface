<?php

declare(strict_types=1);

namespace Drupal\data_surface\Form;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Pipeline\DataSurfaceTargetInterface;

/**
 * A field item that hosts the surface its #[UsesSurface] names.
 *
 * The field type host's one public face. A plugin host is its own
 * caller, but the field type host is not: Field UI's settings form
 * validates in a static #element_validate callback, which rebuilds the
 * item from the field it was handed and has to ask that item for its
 * surface and its target. This interface is what the callback checks
 * the rebuilt item against, and what lets a field type supply a target
 * of its own (SurfaceAddressItem hands the field settings target its
 * storage shape).
 *
 * DataSurfaceFieldTypeTrait implements all three methods; a field item
 * using it declares that it implements this interface.
 *
 * @see \Drupal\data_surface\Form\DataSurfaceFieldTypeTrait
 */
interface FieldSurfaceProviderInterface {

  /**
   * The host operation a field type's per instance settings are asked in.
   *
   * The context's operation when the field type host builds its surface:
   * a host verb, not a situation, since the field config entity the item
   * is bound to is the whole subject.
   */
  public const OPERATION_FIELD_SETTINGS = 'field_settings';

  /**
   * Builds the surface describing this field type's instance settings.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The surface #[UsesSurface] names, built in the host's context.
   */
  public function getFieldSurface(): DataSurfaceInterface;

  /**
   * Gets the target the field settings are read from and written to.
   *
   * The target is bound to one field config entity: the surface is the
   * same for every instance of the field type, and the target is the
   * instance being described. The host supplies it, since only the item
   * holds the field config Field UI is editing, unsaved changes and all.
   *
   * @return \Drupal\data_surface\Pipeline\DataSurfaceTargetInterface
   *   The target.
   */
  public function getDataSurfaceTarget(): DataSurfaceTargetInterface;

  /**
   * Answers whether an account may configure these settings.
   *
   * Forbidden blocks the pipeline before it reads anything, neutral
   * expresses no opinion and blocks nothing, and allowed agrees without
   * bypassing a host's own gate.
   *
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The account to answer for, or NULL for the current user.
   * @param string $operation
   *   The host operation the answer is wanted for.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access answer, with its reason and its cacheability.
   */
  public function surfaceAccess(?AccountInterface $account = NULL, string $operation = self::OPERATION_FIELD_SETTINGS): AccessResultInterface;

}
