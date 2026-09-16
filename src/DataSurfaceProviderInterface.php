<?php

declare(strict_types=1);

namespace Drupal\data_surface;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\data_surface\Pipeline\DataSurfaceTargetInterface;

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
   * Builds the surface for one operation on one subject.
   *
   * Most providers describe one set of values and ignore both
   * arguments. The operation exists for hosts that resolve a different
   * form per operation through plugin_form.factory — the settings tray
   * offering a subset of a block's configure form, a workflow type with
   * separate state and transition forms — where one provider owns
   * several surfaces and the operation is what tells them apart.
   *
   * ## The vocabulary rule
   *
   * The two arguments are one coordinate, and they divide the work
   * between them so that neither has to encode the other:
   * - **operation** is a closed verb from the host type's own
   *   vocabulary — configure, add, edit, field_settings — and it never
   *   carries identity. A provider that spells a subject into the verb
   *   has invented a vocabulary nobody can enumerate, which is what
   *   this rule exists to forbid.
   * - **subject** is an opaque id the provider resolves for itself.
   *   Nothing between the caller and the provider parses it: it is a
   *   content type machine name to one provider and a workflow state to
   *   the next, and only the provider that answers knows which.
   *
   * NULL for the subject means the provider is its own subject, which
   * is the ordinary case: a block, a condition, an action, a formatter
   * and a field item each describe themselves and have no second thing
   * to be asked about. A provider that owns several subjects — one
   * service describing every content type — is addressed by naming one.
   *
   * A provider handed a subject it cannot resolve throws rather than
   * quietly serving a surface nobody asked for. A provider that is its
   * own subject cannot resolve any subject at all, so it throws for
   * every non-NULL one; that is the rule the host base classes in this
   * module follow, through DataSurfaceHostTrait::surfaceSelfSubject().
   *
   * The pair is the wire coordinate the Phase B discovery route and the
   * dry-run endpoint address a surface by: host type, host id,
   * operation, subject.
   *
   * @param string $operation
   *   The host operation the surface is wanted for.
   * @param string|null $subject
   *   The id of the thing the operation is about, or NULL when the
   *   provider is its own subject.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The surface describing the values of that operation.
   *
   * @throws \InvalidArgumentException
   *   When the operation is not one this provider has a surface for, or
   *   when the subject is one it cannot resolve.
   */
  public function getDataSurface(string $operation = 'configure', ?string $subject = NULL): DataSurfaceInterface;

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
   * An access question is never answered with an exception, which is
   * the one place this method's handling of the pair differs from
   * getDataSurface(): an operation or a subject this provider cannot
   * resolve is refused out loud, because something has to be told no.
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
   *   getDataSurface() takes, under the same rule — a closed verb that
   *   never carries identity.
   * @param string|null $subject
   *   The id of the thing the operation is about, or NULL when the
   *   provider is its own subject. The same opaque id getDataSurface()
   *   takes, so one coordinate asks for a surface and asks whether it
   *   may be written.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The account to answer for, or NULL for the current user.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access answer, with its reason and its cacheability.
   *
   * @see \Drupal\data_surface\DataSurfaceAccess
   * @see \Drupal\data_surface\Pipeline\DataSurfacePipelineInterface::submit()
   */
  public function surfaceAccess(string $operation = 'configure', ?string $subject = NULL, ?AccountInterface $account = NULL): AccessResultInterface;

  /**
   * Gets where one operation's values are read from and written to.
   *
   * The third answer of the triple, and the one that completes it:
   * surface, access and target are all resolvable from the same
   * operation and subject, so a caller holding nothing but a coordinate
   * can describe a thing, ask whether it may be written, and write it.
   * Without this a generated endpoint could serve plugins, whose target
   * is always the plugin itself, and nothing else.
   *
   * The pair means here exactly what it means on getDataSurface(): the
   * operation is a closed verb that never carries identity, the subject
   * is an opaque id the provider resolves for itself, and NULL is a
   * provider that is its own subject — which is every plugin, formatter
   * and field item, and is why the host base classes in this module
   * answer this by wrapping themselves in a
   * \Drupal\data_surface\Target\PluginConfigurationTarget.
   *
   * A surface and a target are not the same object for a reason worth
   * restating here: one destination serves the add surface and the edit
   * surface of one thing, and one surface may be written to several
   * destinations. So this is a second question about the same
   * coordinate, not a property of the surface.
   *
   * @param string $operation
   *   The host operation the target is wanted for; the same vocabulary
   *   getDataSurface() takes, under the same rule.
   * @param string|null $subject
   *   The id of the thing the operation is about, or NULL when the
   *   provider is its own subject. The same opaque id getDataSurface()
   *   takes.
   *
   * @return \Drupal\data_surface\Pipeline\DataSurfaceTargetInterface
   *   Where that operation's values live.
   *
   * @throws \InvalidArgumentException
   *   When the operation is not one this provider has a target for, or
   *   when the subject is one it cannot resolve — the same refusals
   *   getDataSurface() makes, for the same reasons.
   * @throws \LogicException
   *   When the operation has a surface but no target this provider can
   *   name: a read-only surface describing values nobody writes, or a
   *   host that owns the write itself and never hands a destination out
   *   — a field formatter's settings are the shipped example, since the
   *   entity display that hosts it is what stores them. A quietly
   *   useless target would be worse, because a caller would submit into
   *   it and be told the values were stored.
   *
   * @see \Drupal\data_surface\Pipeline\DataSurfaceTargetInterface
   * @see docs/targets.md
   */
  public function getDataSurfaceTarget(string $operation = 'configure', ?string $subject = NULL): DataSurfaceTargetInterface;

}
