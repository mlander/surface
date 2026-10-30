<?php

declare(strict_types=1);

namespace Drupal\surface\Plugin\Action;

use Drupal\Core\Action\ConfigurableActionBase;
use Drupal\surface\SurfaceConfigurationTrait;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\Form\SurfacePluginFormTrait;

/**
 * Base class for actions whose settings are described by a structure.
 *
 * An action extending this contains #[UsesStructure] naming the
 * structure class its configuration is, and its output: execute() and
 * access(). The thinnest group A host there is, because the action host
 * owns no configuration keys of its own (what is stored under an
 * action's configuration is the plugin's alone), and it supplies neither
 * a build nor a submit of its own for the structure to compose with. So
 * the two traits are the whole adoption: storage from the configuration
 * trait, the plugin triple from the plugin form trait, and not one line
 * of translation between them.
 *
 * Two things about the host are worth stating rather than discovering:
 * - ConfigurableActionBase uses core's ConfigurableTrait, which declares
 *   getConfiguration(), setConfiguration() and defaultConfiguration().
 *   Those arrive here as ordinary inherited methods, because the trait
 *   is composed into the parent class, not into this one; a trait used
 *   here replaces an inherited method, so the configuration trait wins
 *   with no conflict to resolve. The deep merge ConfigurableTrait would
 *   have applied is therefore never reached, which is the point: the
 *   pipeline's merge rule is the only one that decides what a structure
 *   key holds.
 * - ConfigurableActionBase's constructor calls setConfiguration(), which
 *   asks the structure for its defaults before any subclass constructor
 *   body has run. The same construction-order rule as every other group
 *   A host follows: promote collaborators in the constructor signature
 *   and call the parent constructor last, never assign them in create()
 *   after construction.
 *
 * Two access questions, and they are not the same question. An action's
 * own access() asks whether this action may be *executed* on an object,
 * which is the host's protocol and stays the plugin's to answer.
 * surfaceAccess(), from the host trait, asks whether an account may
 * *configure* the values the structure describes: what a form, a config
 * action or an agent is doing when it writes the plugin's configuration.
 * An action that may be run by anyone with a content permission is very
 * often an action only an administrator may reconfigure, so conflating
 * the two would be a real mistake rather than a stylistic one. It is also
 * why the method is not called access(): ActionInterface already owns
 * that name with an incompatible signature, and a class cannot answer
 * both with one method.
 *
 * @see \Drupal\surface\Plugin\Block\TypedBlockBase
 * @see \Drupal\surface\Plugin\Condition\TypedConditionPluginBase
 */
abstract class TypedConfigurableActionBase extends ConfigurableActionBase {

  use SurfaceConfigurationTrait;
  use SurfacePluginFormTrait;

  /**
   * Builds the structure the class's #[UsesStructure] names.
   *
   * In the action host's `configure` context, stored as the host always
   * stored it, through the plugin configuration storage this host
   * supplies.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The built structure, alters applied.
   */
  public function getSurface(): SurfaceInterface {
    return $this->hostedStructure();
  }

}
