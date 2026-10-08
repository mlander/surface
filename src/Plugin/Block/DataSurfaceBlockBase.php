<?php

declare(strict_types=1);

namespace Drupal\data_surface\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\data_surface\DataSurfaceConfigurationTrait;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Form\DataSurfaceHostFormTrait;

/**
 * Base class for blocks whose settings are described by a surface.
 *
 * A block extending this contains its output and #[UsesSurface] naming
 * the surface class its configuration is. Nothing else: no
 * defaultConfiguration, no blockForm, no blockValidate, no blockSubmit.
 * Everything those would have said is in the surface.
 *
 * The class itself only composes the two traits and translates the
 * block host's names and quirks:
 * - blockForm, blockValidate and blockSubmit are what a block calls its
 *   third of the plugin triple, so they delegate to the host form
 *   trait's three bodies. The public triple stays BlockBase's, which is
 *   why the trait carrying those three names is not used here: it would
 *   replace the block form this class is supposed to extend.
 * - baseConfigurationDefaults() is the block host's own set of keys —
 *   id, label, label_display, provider — which the surface must neither
 *   advertise nor destroy. setConfiguration() fills them in before the
 *   trait runs, and the trait passes undeclared keys through untouched,
 *   so they survive every round trip.
 * - core's BlockPluginTrait::submitConfigurationForm() skips
 *   blockSubmit() entirely when the form carries any error, so nothing
 *   here has to guard against storing values that failed validation.
 * - a block's own access() asks whether this block may be *seen*, which
 *   is the visibility question core's host owns. surfaceAccess(), from
 *   the host trait, asks whether an account may *configure* the block's
 *   settings, which is the question a form, a config action or an agent
 *   is answering when it writes them: the surface's access class, when
 *   it names one. The two are unrelated — a block everyone may see is
 *   usually a block only an administrator may reconfigure — and that is
 *   also why the method is not called access(): BlockPluginInterface
 *   already owns that name with an incompatible signature.
 */
abstract class DataSurfaceBlockBase extends BlockBase {

  use DataSurfaceConfigurationTrait {
    setConfiguration as protected setSurfaceConfiguration;
  }
  use DataSurfaceHostFormTrait;

  /**
   * Builds the surface the class's #[UsesSurface] names.
   *
   * In the block host's `configure` context, stored as the host always
   * stored it, through the plugin configuration target this host
   * supplies.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The surface, alters applied.
   */
  public function getDataSurface(): DataSurfaceInterface {
    return $this->hostedSurface();
  }

  /**
   * {@inheritdoc}
   *
   * The host's own keys are added before the values reach the pipeline,
   * because a block is constructed with configuration that may not carry
   * them yet and every later reader expects them to be there.
   */
  public function setConfiguration(array $configuration): static {
    return $this->setSurfaceConfiguration($configuration + $this->baseConfigurationDefaults());
  }

  /**
   * {@inheritdoc}
   *
   * The surface enters here; the block's own description, title and
   * visibility elements stay BlockBase's business.
   */
  public function blockForm($form, FormStateInterface $form_state) {
    return $this->buildDataSurfaceForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function blockValidate($form, FormStateInterface $form_state): void {
    $this->validateDataSurfaceForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    $this->submitDataSurfaceForm($form, $form_state);
  }

}
