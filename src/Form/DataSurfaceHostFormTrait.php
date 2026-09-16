<?php

declare(strict_types=1);

namespace Drupal\data_surface\Form;

use Drupal\Component\Plugin\ConfigurableInterface;
use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DataSurfaceHostTrait;
use Drupal\data_surface\Pipeline\DataSurfaceTargetInterface;
use Drupal\data_surface\Target\PluginConfigurationTarget;

/**
 * The three form stages a configurable plugin's surface goes through.
 *
 * Deliberately named for what they do rather than for one host's
 * spelling of them, because hosts disagree: the plain plugin triple
 * calls them buildConfigurationForm, validateConfigurationForm and
 * submitConfigurationForm, while a block calls its own third of that
 * triple blockForm, blockValidate and blockSubmit and keeps the public
 * names for its own description, title and visibility elements. A host
 * whose names match the triple adds them with the plugin form trait; a
 * host that renames them maps its names onto these three and adds
 * nothing else.
 *
 * Keeping the bodies here rather than behind the public triple is what
 * lets a renaming host compose them: a trait method replaces an
 * inherited one, so a block base class that pulled in the public triple
 * would silently replace the block form it is supposed to extend.
 *
 * Submit goes through the pipeline rather than assigning the values: the
 * plugin configuration target puts the host-owned keys back around them
 * and commits, so the storage rule lives in one place for every caller,
 * form or not. The target itself comes from getDataSurfaceTarget(),
 * which this trait also answers, so the form path and a caller holding
 * only a coordinate reach one construction rather than two.
 */
trait DataSurfaceHostFormTrait {

  use DataSurfaceHostTrait;

  /**
   * Builds the surface describing the values the form collects.
   *
   * @param string $operation
   *   The host operation the surface is wanted for.
   * @param string|null $subject
   *   The id of the thing the operation is about, or NULL when the
   *   provider is its own subject.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The surface.
   */
  abstract public function getDataSurface(string $operation = 'configure', ?string $subject = NULL): DataSurfaceInterface;

  /**
   * Gets the plugin whose configuration array the surface describes.
   *
   * The adopting plugin itself in the common case; a form class serving
   * a plugin overrides this to return the plugin it was given.
   *
   * @return \Drupal\Component\Plugin\ConfigurableInterface
   *   The plugin.
   */
  protected function surfaceConfigurable(): ConfigurableInterface {
    if (!$this instanceof ConfigurableInterface) {
      throw new \LogicException(sprintf(
        '%s must either be a ConfigurableInterface plugin or override surfaceConfigurable().',
        static::class,
      ));
    }
    return $this;
  }

  /**
   * Gets the target this host's surface values are stored through.
   *
   * The one construction path for the plugin host family, and what the
   * submit stage below now asks rather than constructing a target of its
   * own: a plugin's values live in its configuration array, so the
   * target is the plugin wrapped in a PluginConfigurationTarget, and a
   * form, a config action, a tool and the discovery endpoint all reach
   * the same object by asking the provider for it.
   *
   * A plugin is its own subject, exactly as it is for the surface, so a
   * caller naming one has addressed the wrong provider and is refused by
   * name.
   *
   * @param string $operation
   *   The host operation the target is wanted for. Not read: a plugin's
   *   configuration array is where every one of its operations stores,
   *   which is the whole reason this host family needs no target code
   *   per operation.
   * @param string|null $subject
   *   The id of the thing the operation is about, which for a plugin may
   *   only be NULL.
   *
   * @return \Drupal\data_surface\Pipeline\DataSurfaceTargetInterface
   *   The target.
   *
   * @throws \InvalidArgumentException
   *   When a subject was named.
   *
   * @see \Drupal\data_surface\DataSurfaceProviderInterface::getDataSurfaceTarget()
   */
  public function getDataSurfaceTarget(string $operation = 'configure', ?string $subject = NULL): DataSurfaceTargetInterface {
    $this->surfaceSelfSubject($subject);
    return new PluginConfigurationTarget($this->surfaceConfigurable());
  }

  /**
   * Builds the surface container, with current values.
   *
   * Current values are the stored configuration narrowed to the
   * surface's own keys, overlaid with whatever an in-progress AJAX
   * refinement rebuild has already collected, so the definitions refine
   * against what the person just chose.
   *
   * @param array $form
   *   The host's form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The host's form with the surface container merged in.
   */
  protected function buildDataSurfaceForm(array $form, FormStateInterface $form_state): array {
    $surface = $this->getDataSurface();
    $builder = $this->surfaceFormBuilder();
    $values = array_replace(
      $this->storedSurfaceValues($surface),
      $this->surfaceRefinementInput($surface, $form_state),
    );
    // Merged through the builder rather than with a plain union: the
    // host's fragment is the host's element, and a union would hand the
    // surface's container type, attributes and tree flag to it.
    return $builder->mergeSurfaceContainer(
      $builder->buildSurfaceForm($surface, $values, $form_state, $this->surfaceWrapperKey()),
      $form,
    );
  }

  /**
   * Reads what the host stores for the surface's own keys.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The surface.
   *
   * @return array
   *   The stored values, keyed by surface key.
   */
  protected function storedSurfaceValues(DataSurfaceInterface $surface): array {
    return array_intersect_key(
      $this->surfaceConfigurable()->getConfiguration(),
      $surface->getDefinitions()->toArray(),
    );
  }

  /**
   * Extracts and validates, flagging violations on their own elements.
   *
   * @param array $form
   *   The form array carrying the surface container.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  protected function validateDataSurfaceForm(array &$form, FormStateInterface $form_state): void {
    $surface = $this->getDataSurface();
    $builder = $this->surfaceFormBuilder();
    // The stored values go with the form: a key the host chose not to
    // render has said nothing, and must keep what it holds rather than
    // fall back to its declared default.
    $values = $builder->extractSurfaceValues($surface, $form, $form_state, $this->storedSurfaceValues($surface));
    $builder->validateSurfaceForm($surface, $values, $form, $form_state);
  }

  /**
   * Stores what the form collected, through the pipeline.
   *
   * The host's own access answer goes with the values, so the form path
   * runs the same gate a payload runs rather than relying on the route
   * that rendered the form. A host that answers neutral — which is every
   * host until it says otherwise — changes nothing by doing this.
   *
   * @param array $form
   *   The form array carrying the surface container.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  protected function submitDataSurfaceForm(array &$form, FormStateInterface $form_state): void {
    $surface = $this->getDataSurface();
    $builder = $this->surfaceFormBuilder();
    $values = $builder->extractSurfaceValues($surface, $form, $form_state, $this->storedSurfaceValues($surface));
    $result = $this->surfacePipeline()->submit(
      $surface,
      $values,
      $this->getDataSurfaceTarget(),
      access: $this->surfaceAccess(),
    );
    if (!$result->isValid()) {
      // Validation runs first on every host that has a validate hook, so
      // reaching this means the storage refused what the surface
      // accepted, or the host refused the write outright. Say so on the
      // elements rather than throwing from inside a submit handler; the
      // access refusal has no element of its own and becomes a form
      // level error.
      $builder->flagSurfaceErrors($result->violations, $form, $form_state);
    }
  }

  /**
   * The stable AJAX wrapper key for this surface's container.
   *
   * @return string
   *   An identifier unique within the page.
   */
  protected function surfaceWrapperKey(): string {
    $host = $this->surfaceConfigurable();
    return 'data-surface-' . ($host instanceof PluginInspectionInterface ? $host->getPluginId() : 'form');
  }

}
