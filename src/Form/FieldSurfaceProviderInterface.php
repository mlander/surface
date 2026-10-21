<?php

declare(strict_types=1);

namespace Drupal\data_surface\Form;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Pipeline\DataSurfaceStorageInterface;

/**
 * A field item that hosts the structure its #[UsesStructure] names.
 *
 * The field type host's one public face. A plugin host is its own
 * caller, but the field type host is not: Field UI's settings form
 * validates in a static #element_validate callback, which rebuilds the
 * item from the field it was handed and has to ask that item for its
 * data surface and its storage. This interface is what the callback
 * checks the rebuilt item against, and what lets a field type supply a
 * storage of its own (SurfaceAddressItem hands the field settings
 * storage its storage layout).
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
   * The context's id when the field type host builds its structure: a
   * host verb, not a declared operation, since the field config entity
   * the item is bound to is the whole subject.
   */
  public const OPERATION_FIELD_SETTINGS = 'field_settings';

  /**
   * Builds the data surface for this field type's instance settings.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The structure #[UsesStructure] names, built in the host's context.
   */
  public function getFieldSurface(): DataSurfaceInterface;

  /**
   * Gets the storage the field settings are read from and written to.
   *
   * The storage is bound to one field config entity: the structure is
   * the same for every instance of the field type, and the storage is the
   * instance being described. The host supplies it, since only the item
   * holds the field config Field UI is editing, unsaved changes and all.
   *
   * @return \Drupal\data_surface\Pipeline\DataSurfaceStorageInterface
   *   The storage.
   */
  public function getDataSurfaceStorage(): DataSurfaceStorageInterface;

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
