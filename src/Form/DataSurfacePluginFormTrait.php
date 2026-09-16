<?php

declare(strict_types=1);

namespace Drupal\data_surface\Form;

use Drupal\Core\Form\FormStateInterface;

/**
 * Implements PluginFormInterface from a surface.
 *
 * The adapter for the plain plugin triple described in ADOPTION.md
 * group A: conditions, actions, image effects, layouts, search pages,
 * display variants and the rest, whose configuration form is
 * buildConfigurationForm / validateConfigurationForm /
 * submitConfigurationForm over a configuration array. An existing plugin
 * keeps its class, declares a surface, uses this trait, and its form is
 * generated. Alter hooks keep working but are demoted to cosmetics: the
 * data the form collects is declared on the surface, not buried in a
 * build method.
 *
 * The three bodies are the host form trait's, so a host that renames the
 * triple reaches the same code without inheriting these three names —
 * see that trait for why the split exists.
 */
trait DataSurfacePluginFormTrait {

  use DataSurfaceHostFormTrait;

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    return $this->buildDataSurfaceForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $this->validateDataSurfaceForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $this->submitDataSurfaceForm($form, $form_state);
  }

}
