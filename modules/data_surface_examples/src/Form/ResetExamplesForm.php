<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Config\InstallStorage;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Puts every example's settings back to what the module ships.
 *
 * For a retake: whatever the examples were saved with, and whatever
 * another module mounted on them — the compliance module's licence under
 * example 3's third party settings — each config object is replaced
 * whole by its file in config/install. Read from the files rather than
 * written down here, so a shipped value that changes is reset to the new
 * one without touching this form.
 */
final class ResetExamplesForm extends ConfirmFormBase {

  /**
   * The prefix every example's config object shares.
   */
  public const PREFIX = 'data_surface_examples.registration_step';

  /**
   * Constructs the form.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configs
   *   The config factory, written through.
   * @param \Drupal\Core\Extension\ModuleExtensionList $moduleList
   *   The module list, which knows where this module's files are.
   */
  public function __construct(
    protected readonly ConfigFactoryInterface $configs,
    protected readonly ModuleExtensionList $moduleList,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('config.factory'), $container->get('extension.list.module'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'data_surface_examples_reset';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    return $this->t('Reset every example to the settings the module ships with?');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): TranslatableMarkup {
    return $this->t('Whatever the examples were saved with is replaced, including what another module stored on them. Use it to take the examples again from the start.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): TranslatableMarkup {
    return $this->t('Reset to defaults');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('data_surface_examples.index');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $shipped = new FileStorage($this->moduleList->getPath('data_surface_examples') . '/' . InstallStorage::CONFIG_INSTALL_DIRECTORY);
    foreach ($shipped->listAll(self::PREFIX) as $name) {
      $config = $this->configs->getEditable($name);
      // The installer's own bookkeeping stays: it says where the object
      // came from, which a reset does not change.
      $core = $config->get('_core');
      $config->setData((array) $shipped->read($name) + ($core === NULL ? [] : ['_core' => $core]))->save();
    }
    $this->messenger()->addStatus($this->t('The examples are back to the settings the module ships with.'));
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
