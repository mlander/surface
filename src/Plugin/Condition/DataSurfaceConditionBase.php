<?php

declare(strict_types=1);

namespace Drupal\data_surface\Plugin\Condition;

use Drupal\Core\Condition\ConditionPluginBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\DataSurfaceConfigurationTrait;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DataSurfaceProviderInterface;
use Drupal\data_surface\DataSurfaceRefinerInterface;
use Drupal\data_surface\Form\DataSurfacePluginFormTrait;

/**
 * Base class for conditions whose settings are described by a surface.
 *
 * A condition extending this contains its declaration, its refiner if it
 * has one, and its output: evaluate() and summary(). No
 * defaultConfiguration, no buildConfigurationForm, no
 * validateConfigurationForm, no submitConfigurationForm.
 *
 * The condition host names the plugin triple exactly as the interface
 * does, so unlike a block this class takes the triple straight from the
 * plugin form trait. What it does have to absorb is that the condition
 * host owns two configuration keys of its own and answers for them in
 * four places, each of which is one line here:
 * - negate is a real setting core renders, stores and reads. Its default
 *   comes from the parent's defaultConfiguration(), its element from the
 *   parent's form build, and its stored value from the parent's submit,
 *   so all three keep core's behavior by calling the parent around the
 *   surface's own work rather than by restating it.
 * - id is not stored at all: core's getConfiguration() prepends the
 *   plugin ID to whatever is stored, and callers (the block visibility
 *   UI among them) depend on it being there and first.
 * - context_mapping is set by the parent's submit through
 *   setContextMapping(); the surface neither advertises nor destroys it,
 *   because the configuration trait passes undeclared keys through.
 *
 * Construction order, as for every group A host: ConditionPluginBase's
 * constructor calls setConfiguration(), which asks the surface for its
 * defaults before any subclass constructor body has run, so a condition
 * needing collaborators promotes them in its constructor signature and
 * calls the parent constructor last.
 *
 * @see \Drupal\data_surface\Plugin\Block\DataSurfaceBlockBase
 *   The same composition for the host that renames the triple.
 */
abstract class DataSurfaceConditionBase extends ConditionPluginBase implements DataSurfaceProviderInterface, DataSurfaceRefinerInterface {

  use DataSurfaceConfigurationTrait {
    defaultConfiguration as protected surfaceDefaultConfiguration;
    getConfiguration as protected getSurfaceConfiguration;
    setConfiguration as protected setSurfaceConfiguration;
  }
  use DataSurfacePluginFormTrait;

  /**
   * {@inheritdoc}
   *
   * The surface is whatever the class declares in its attribute, with
   * this plugin as the refiner. A condition whose surface needs live
   * site state to describe itself overrides this and builds the surface
   * here instead, which is the one other legal home for it.
   */
  public function getDataSurface(string $operation = 'configure'): DataSurfaceInterface {
    // The host id is namespaced by plugin type, so a subscriber
    // matching on it cannot pick up a host of another kind that
    // happens to share a plugin id.
    return $this->surfaceFactory()->buildFromClass(static::class, $this, 'condition:' . $this->getPluginId());
  }

  /**
   * {@inheritdoc}
   *
   * A condition with no dependent settings refines nothing; one with
   * them overrides this and narrows the named definition.
   */
  public function refineDataDefinition(string $name, DataDefinitionInterface $definition, array $values): DataDefinitionInterface {
    return $definition;
  }

  /**
   * {@inheritdoc}
   *
   * The surface's defaults plus the host's own, which for a condition is
   * negate alone. Read from the parent rather than written out again, so
   * a core change to the host's keys arrives here for free.
   */
  public function defaultConfiguration(): array {
    return $this->surfaceDefaultConfiguration() + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   *
   * The plugin ID is prepended, and first, because that is the shape
   * core's condition host stores and reads back.
   */
  public function getConfiguration(): array {
    return ['id' => $this->getPluginId()] + $this->getSurfaceConfiguration();
  }

  /**
   * {@inheritdoc}
   *
   * The host's own keys are filled in before the values reach the
   * pipeline, because a condition is constructed with configuration that
   * may not carry them yet and core's isNegated() reads negate directly.
   */
  public function setConfiguration(array $configuration): static {
    return $this->setSurfaceConfiguration($configuration + parent::defaultConfiguration());
  }

  /**
   * {@inheritdoc}
   *
   * The surface's elements first, then the parent's negate checkbox and
   * context assignment on top of them. The parent unwraps a subform
   * state before reading gathered contexts, which does not disturb the
   * surface: the host form trait locates in-progress refinement input on
   * the complete form state itself, so both halves see the same state
   * whichever kind the host hands over.
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    return parent::buildConfigurationForm($this->buildDataSurfaceForm($form, $form_state), $form_state);
  }

  /**
   * {@inheritdoc}
   *
   * The surface stores through the pipeline; the parent then stores
   * negate and the context mapping exactly as core does.
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $this->submitDataSurfaceForm($form, $form_state);
    parent::submitConfigurationForm($form, $form_state);
  }

}
