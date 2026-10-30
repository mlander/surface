<?php

declare(strict_types=1);

namespace Drupal\surface\Form;

use Drupal\Core\Form\FormStateInterface;

/**
 * Implements PluginFormInterface from a structure.
 *
 * The adapter for the plain plugin triple described in ADOPTION.md
 * group A: conditions, actions, image effects, layouts, search pages,
 * display variants and the rest, whose configuration form is
 * buildConfigurationForm / validateConfigurationForm /
 * submitConfigurationForm over a configuration array. An existing plugin
 * keeps its class, declares its structure, uses this trait, and its form
 * is generated. Alter hooks keep working but are reduced to cosmetics:
 * the data the form collects is declared on the structure, not buried in
 * a build method.
 *
 * The three bodies are the host form trait's, so a host that renames the
 * triple reaches the same code without inheriting these three names —
 * see that trait for why the split exists.
 */
trait SurfacePluginFormTrait {

  use SurfaceHostFormTrait;

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    return $this->buildSurfaceForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $this->validateSurfaceForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $this->submitSurfaceForm($form, $form_state);
  }

}
