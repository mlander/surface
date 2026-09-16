<?php

declare(strict_types=1);

namespace Drupal\data_surface;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Something whose values are described by a runtime surface.
 *
 * Typically implemented by plugins. The surface is the single authority
 * for what the thing accepts: defaults, validation, generated forms and
 * machine-readable contracts all derive from it, so adopting this
 * interface on an existing plugin removes the hand-rolled configuration
 * form rather than duplicating it. Form alters survive as cosmetics, not
 * as the only place the meaning of a value is written down.
 *
 * The surface is built at runtime, so it may consult live site state:
 * plugin managers, bundle information, the current account. It should be
 * cheap enough to build on demand, because hosts call it freely.
 */
interface DataSurfaceProviderInterface {

  /**
   * Builds the surface for one operation.
   *
   * Most providers describe one set of values and ignore the argument.
   * It exists for hosts that resolve a different form per operation
   * through plugin_form.factory — the settings tray offering a subset of
   * a block's configure form, a workflow type with separate state and
   * transition forms — where one provider owns several surfaces and the
   * operation is what tells them apart.
   *
   * @param string $operation
   *   The host operation the surface is wanted for.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The surface describing the values of that operation.
   */
  public function getDataSurface(string $operation = 'configure'): DataSurfaceInterface;

  /**
   * Answers whether an account may run one operation on this surface.
   *
   * One answer per operation, resolved once and read by every caller: a
   * generated form, a Drush command, a config action, an agent and the
   * pipeline itself all ask this rather than each spelling a permission
   * of their own, which is what keeps a route's gate and a payload's
   * gate from drifting apart.
   *
   * The answer is core's AccessResultInterface, so it carries a reason
   * and its own cacheability, and the third state means something:
   * - **Forbidden** blocks. DataSurfacePipelineInterface::submit()
   *   refuses before it reads anything from storage.
   * - **Neutral** is no opinion, and does NOT block. A provider with
   *   nothing to say about an operation says so, and the host's own
   *   gates stand exactly as they stood.
   * - **Allowed** is an affirmative grant that still does not bypass a
   *   host's gates. A provider may agree that an operation is legal; it
   *   cannot hand out a permission the host refused.
   *
   * Providers that have nothing to say inherit a neutral default from
   * DataSurfaceHostTrait, so nothing an existing provider does changes
   * until it answers for itself.
   *
   * Not to be confused with a host's own access method, where a host has
   * one: an action plugin's access() asks whether the action may be
   * *executed* on an object, and a block's asks whether the block may be
   * *seen*. This asks whether the values a surface describes may be
   * configured, which is a different question with a different answer,
   * and is why this method is not called access().
   *
   * @param string $operation
   *   The host operation the answer is wanted for; the same vocabulary
   *   getDataSurface() takes.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The account to answer for, or NULL for the current user.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access answer, with its reason and its cacheability.
   *
   * @see \Drupal\data_surface\DataSurfaceAccess
   * @see \Drupal\data_surface\Pipeline\DataSurfacePipelineInterface::submit()
   */
  public function surfaceAccess(string $operation = 'configure', ?AccountInterface $account = NULL): AccessResultInterface;

}
