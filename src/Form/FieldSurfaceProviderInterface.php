<?php

declare(strict_types=1);

namespace Drupal\data_surface\Form;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Pipeline\DataSurfaceTargetInterface;

/**
 * A field item whose instance settings are described by a surface.
 *
 * The field type counterpart of DataSurfaceProviderInterface, which
 * cannot serve here because a field type has two surfaces — instance
 * settings and storage settings — and one getDataSurface() cannot say
 * which is meant.
 *
 * Both methods are public because a field item is not the only caller. A
 * settings form is a method on the item's own class and could reach a
 * protected accessor, but a bridge cannot: the tool module's field tools
 * describe and write the same settings from outside the class, and so
 * would a REST resource, a config action or an agent. An interface with
 * two public methods is how that is said out loud, and it replaces the
 * reflection the bridge used to need.
 *
 * DataSurfaceFieldTypeTrait implements both methods; a field item using
 * it declares that it implements this interface, which is the statement
 * consumers type-check.
 *
 * @see \Drupal\data_surface\Form\DataSurfaceFieldTypeTrait
 * @see \Drupal\data_surface\DataSurfaceProviderInterface
 */
interface FieldSurfaceProviderInterface {

  /**
   * The operation naming a field type's per instance settings.
   *
   * A field type has two surfaces, so "configure" would not say which
   * one is meant here any more than getDataSurface() would. This is the
   * operation the instance settings answer for; storage settings, when
   * they arrive, are a second operation with a second answer, because a
   * site builder may well be allowed to change a label on one bundle and
   * not to change a shape every bundle shares.
   *
   * A verb, under the vocabulary rule the provider interface states: it
   * says what is being configured, never which field. Which field is
   * settled by the item the method is called on.
   */
  public const OPERATION_FIELD_SETTINGS = 'field_settings';

  /**
   * Builds the surface describing this field type's instance settings.
   *
   * The same operation and subject pair the provider interface takes,
   * so a caller holding either kind of provider addresses it the same
   * way and the storage settings verb slots in beside this one without
   * reshaping the method again.
   *
   * A field item is its own subject, and that is the normal case: the
   * item is bound to the field config entity whose settings it
   * describes, so there is nothing left for a subject to name and NULL
   * is what a caller passes. A field type handed any other subject
   * refuses it by name, exactly as a plugin does.
   *
   * @param string $operation
   *   The operation the surface is wanted for; OPERATION_FIELD_SETTINGS
   *   unless a field type serves more than the instance settings.
   * @param string|null $subject
   *   The id of the thing the operation is about, or NULL when the
   *   field item is its own subject.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The surface.
   *
   * @throws \InvalidArgumentException
   *   When the operation is not one this field type has a surface for,
   *   or when the subject is one it cannot resolve.
   *
   * @see \Drupal\data_surface\DataSurfaceProviderInterface::getDataSurface()
   */
  public function getFieldSurface(string $operation = self::OPERATION_FIELD_SETTINGS, ?string $subject = NULL): DataSurfaceInterface;

  /**
   * Gets the target the field settings are read from and written to.
   *
   * The target is bound to one field config entity: the surface is the
   * same for every instance of the field type, and the target is the
   * instance being described.
   *
   * @return \Drupal\data_surface\Pipeline\DataSurfaceTargetInterface
   *   The target.
   */
  public function getFieldSettingsTarget(): DataSurfaceTargetInterface;

  /**
   * Answers whether an account may configure these settings.
   *
   * The same question, the same tri-state and the same rules as
   * DataSurfaceProviderInterface::surfaceAccess(): forbidden blocks the
   * pipeline before it reads anything, neutral expresses no opinion and
   * blocks nothing, and allowed agrees without bypassing a host's own
   * gate. One spelling for both provider kinds, so a caller holding
   * either asks the same way.
   *
   * DataSurfaceFieldTypeTrait answers it from the field config entity
   * the settings belong to, which is where core already spells who may
   * administer a field.
   *
   * @param string $operation
   *   The operation the answer is wanted for; OPERATION_FIELD_SETTINGS
   *   unless a field type serves more than the instance settings.
   * @param string|null $subject
   *   The id of the thing the operation is about, or NULL when the
   *   field item is its own subject. The same opaque id
   *   getFieldSurface() takes.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The account to answer for, or NULL for the current user.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access answer, with its reason and its cacheability.
   *
   * @see \Drupal\data_surface\DataSurfaceAccess
   */
  public function surfaceAccess(string $operation = self::OPERATION_FIELD_SETTINGS, ?string $subject = NULL, ?AccountInterface $account = NULL): AccessResultInterface;

}
