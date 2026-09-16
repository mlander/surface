<?php

declare(strict_types=1);

namespace Drupal\data_surface\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\DataSurfaceConfigurationTrait;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DataSurfaceProviderInterface;
use Drupal\data_surface\DataSurfaceRefinerInterface;
use Drupal\data_surface\Form\DataSurfaceHostFormTrait;

/**
 * Base class for blocks whose settings are described by a surface.
 *
 * A block extending this contains its declaration, its refiner if it has
 * one, and its output. Nothing else: no defaultConfiguration, no
 * blockForm, no blockValidate, no blockSubmit. Everything those would
 * have said is in the surface.
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
 *   is the visibility question core's host owns. surfaceAccess(),
 *   inherited neutral from the configuration trait, asks whether an
 *   account may *configure* the block's settings, which is the question
 *   a form, a config action or an agent is answering when it writes
 *   them. The two are unrelated — a block everyone may see is usually a
 *   block only an administrator may reconfigure — and that is also why
 *   the provider method is not called access(): BlockPluginInterface
 *   already owns that name with an incompatible signature.
 */
abstract class DataSurfaceBlockBase extends BlockBase implements DataSurfaceProviderInterface, DataSurfaceRefinerInterface {

  use DataSurfaceConfigurationTrait {
    setConfiguration as protected setSurfaceConfiguration;
  }
  use DataSurfaceHostFormTrait;

  /**
   * {@inheritdoc}
   *
   * The surface is whatever the class declares in its attribute, with
   * this plugin as the refiner. A block whose surface needs live site
   * state to describe itself overrides this and builds the surface here
   * instead, which is the one other legal home for it.
   *
   * A block is its own subject: the plugin instance is the whole of
   * what this surface describes, so the subject is NULL, and any
   * other is refused by name rather than quietly ignored.
   */
  public function getDataSurface(string $operation = 'configure', ?string $subject = NULL): DataSurfaceInterface {
    // The plugin instance is the only thing this surface describes, so
    // a caller naming a subject has addressed the wrong provider.
    $this->surfaceSelfSubject($subject);
    // The host id is namespaced by plugin type, so a subscriber
    // matching on it cannot pick up a host of another kind that
    // happens to share a plugin id.
    return $this->surfaceFactory()->buildFromClass(static::class, $this, 'block:' . $this->getPluginId());
  }

  /**
   * {@inheritdoc}
   *
   * A block with no dependent settings refines nothing; one with them
   * overrides this and narrows the named definition.
   */
  public function refineDataDefinition(string $name, DataDefinitionInterface $definition, array $values): DataDefinitionInterface {
    return $definition;
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
