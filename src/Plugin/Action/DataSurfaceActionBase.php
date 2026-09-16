<?php

declare(strict_types=1);

namespace Drupal\data_surface\Plugin\Action;

use Drupal\Core\Action\ConfigurableActionBase;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\DataSurfaceConfigurationTrait;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DataSurfaceProviderInterface;
use Drupal\data_surface\DataSurfaceRefinerInterface;
use Drupal\data_surface\Form\DataSurfacePluginFormTrait;

/**
 * Base class for actions whose settings are described by a surface.
 *
 * An action extending this contains its declaration, its refiner if it
 * has one, and its output: execute() and access(). The thinnest group A
 * host there is, because the action host owns no configuration keys of
 * its own — what is stored under an action's configuration is the
 * plugin's alone — and it supplies neither a build nor a submit of its
 * own for the surface to compose with. So the two traits are the whole
 * adoption: storage from the configuration trait, the plugin triple from
 * the plugin form trait, and not one line of translation between them.
 *
 * Two things about the host are worth stating rather than discovering:
 * - ConfigurableActionBase uses core's ConfigurableTrait, which declares
 *   getConfiguration(), setConfiguration() and defaultConfiguration().
 *   Those arrive here as ordinary inherited methods, because the trait
 *   is composed into the parent class, not into this one; a trait used
 *   here replaces an inherited method, so the configuration trait wins
 *   with no conflict to resolve. The deep merge ConfigurableTrait would
 *   have applied is therefore never reached, which is the point: the
 *   pipeline's merge rule is the only one that decides what a surface
 *   key holds.
 * - ConfigurableActionBase's constructor calls setConfiguration(), which
 *   asks the surface for its defaults before any subclass constructor
 *   body has run. The same construction-order rule as every other group
 *   A host follows: promote collaborators in the constructor signature
 *   and call the parent constructor last, never assign them in create()
 *   after construction.
 *
 * Two access questions, and they are not the same question. An action's
 * own access() asks whether this action may be *executed* on an object,
 * which is the host's protocol and stays the plugin's to answer.
 * surfaceAccess(), inherited neutral from the configuration trait, asks
 * whether an account may *configure* the values the surface describes —
 * what a form, a config action or an agent is doing when it writes the
 * plugin's configuration. An action that may be run by anyone with a
 * content permission is very often an action only an administrator may
 * reconfigure, so conflating the two would be a real mistake rather than
 * a stylistic one. It is also why the provider method is not called
 * access(): ActionInterface already owns that name with an incompatible
 * signature, and a class cannot answer both with one method.
 *
 * @see \Drupal\data_surface\Plugin\Block\DataSurfaceBlockBase
 * @see \Drupal\data_surface\Plugin\Condition\DataSurfaceConditionBase
 * @see \Drupal\data_surface\DataSurfaceProviderInterface::surfaceAccess()
 */
abstract class DataSurfaceActionBase extends ConfigurableActionBase implements DataSurfaceProviderInterface, DataSurfaceRefinerInterface {

  use DataSurfaceConfigurationTrait;
  use DataSurfacePluginFormTrait;

  /**
   * {@inheritdoc}
   *
   * The surface is whatever the class declares in its attribute, with
   * this plugin as the refiner. An action whose surface needs live site
   * state to describe itself overrides this and builds the surface here
   * instead, which is the one other legal home for it.
   */
  public function getDataSurface(string $operation = 'configure'): DataSurfaceInterface {
    // The host id is namespaced by plugin type, so a subscriber
    // matching on it cannot pick up a host of another kind that
    // happens to share a plugin id.
    return $this->surfaceFactory()->buildFromClass(static::class, $this, 'action:' . $this->getPluginId());
  }

  /**
   * {@inheritdoc}
   *
   * An action with no dependent settings refines nothing; one with them
   * overrides this and narrows the named definition.
   */
  public function refineDataDefinition(string $name, DataDefinitionInterface $definition, array $values): DataDefinitionInterface {
    return $definition;
  }

}
