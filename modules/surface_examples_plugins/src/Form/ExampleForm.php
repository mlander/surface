<?php

declare(strict_types=1);

namespace Drupal\surface_examples_plugins\Form;

use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\SurfaceHostTrait;
use Drupal\surface\Plugin\Block\TypedBlockBase;
use Drupal\surface\Pipeline\Storage\StateStorage;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A standalone form generated entirely from the demo block's structure.
 *
 * Two claims in one class. A plugin-hosted structure stays externally
 * consumable: this form never instantiates a form element of its own and
 * never repeats the block's declaration. It asks the block manager for
 * the plugin and reads its contract, which is what any other consumer
 * would do. And a structure is independent of where its values are kept:
 * the same structure the block commits into its configuration array is
 * committed here into State, because the storage, not the structure,
 * owns the layout the values are kept in.
 *
 * Build, validate and submit are three delegations. The host trait
 * supplies the pipeline, the form builder and the in-progress AJAX input
 * so none of that is written twice.
 */
final class ExampleForm extends FormBase {

  use SurfaceHostTrait;

  /**
   * The state key holding the demo values.
   */
  protected const STATE_KEY = 'surface_examples_plugins.settings';

  /**
   * The AJAX wrapper key for this form's data surface container.
   */
  protected const WRAPPER_KEY = 'surface-example-form';

  /**
   * The block plugin manager, which hands over the demo block.
   */
  protected BlockManagerInterface $blockManager;

  /**
   * The state store this form's storage writes to.
   */
  protected StateInterface $state;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->blockManager = $container->get('plugin.manager.block');
    $instance->state = $container->get('state');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'surface_examples_plugins_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $surface = $this->demoSurface();
    // The pipeline's own merge rule, reused rather than restated: the
    // structure's defaults, then whatever the storage holds, then the
    // in-progress choice an AJAX rebuild is refining against.
    $values = $this->surfaceFormValues(
      $surface,
      $this->surfacePipeline()->accept($surface, [], $this->demoStorage()->load($surface)),
      $form_state,
    );
    $form['surface'] = $this->surfaceFormBuilder()
      ->buildSurfaceForm($surface, $values, $form_state, self::WRAPPER_KEY);
    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Save'),
      ],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $surface = $this->demoSurface();
    $builder = $this->surfaceFormBuilder();
    // What State already holds goes with the form: a key with no
    // rendered element has said nothing and must keep its stored value
    // rather than fall back to its declared default.
    $current = $this->demoStorage()->load($surface);
    $values = $builder->extractSurfaceValues(
      $surface,
      $form['surface'],
      $form_state,
      $current,
    );
    $builder->validateSurfaceForm($surface, $values, $form['surface'], $form_state, $current);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $surface = $this->demoSurface();
    $builder = $this->surfaceFormBuilder();
    $storage = $this->demoStorage();
    $values = $builder->extractSurfaceValues($surface, $form['surface'], $form_state, $storage->load($surface));
    $result = $this->surfacePipeline()->submit($surface, $values, $storage);
    if (!$result->isValid()) {
      $builder->flagSurfaceErrors($result->violations, $form['surface'], $form_state);
      return;
    }
    // What was saved is on the form the person is looking at, so the
    // message counts rather than repeating it. The serialized dump this
    // replaces put raw stored values into a status message, which is
    // neither translatable nor anything a person reads.
    $this->messenger()->addStatus($this->formatPlural(
      count($result->values),
      'Saved 1 value.',
      'Saved @count values.',
    ));
  }

  /**
   * Reads the demo block's built structure through the plugin manager.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The block's built structure, refiners bound.
   *
   * @throws \LogicException
   *   When the plugin behind the demo ID is not a data surface block.
   */
  protected function demoSurface(): SurfaceInterface {
    $block = $this->blockManager->createInstance('surface_examples_plugins');
    if (!$block instanceof TypedBlockBase) {
      throw new \LogicException('The surface_examples_plugins block is not a data surface block.');
    }
    return $block->getSurface();
  }

  /**
   * Builds the storage this form's values are read from and written to.
   *
   * @return \Drupal\surface\Pipeline\Storage\StateStorage
   *   The State storage.
   */
  protected function demoStorage(): StateStorage {
    return new StateStorage($this->state, self::STATE_KEY);
  }

}
