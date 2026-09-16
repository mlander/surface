<?php

declare(strict_types=1);

namespace Drupal\data_surface_demo\Form;

use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DataSurfaceHostTrait;
use Drupal\data_surface\DataSurfaceProviderInterface;
use Drupal\data_surface\Target\StateTarget;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A standalone form generated entirely from the demo block's surface.
 *
 * Two claims in one class. A plugin-hosted surface stays externally
 * consumable: this form never instantiates a form element of its own and
 * never repeats the block's declaration — it asks the block manager for
 * the plugin and reads its contract, which is what any other consumer
 * would do. And a surface is independent of where its values are stored:
 * the same surface the block commits into its configuration array is
 * committed here into State, because the target, not the surface, owns
 * the storage shape.
 *
 * Build, validate and submit are three delegations. The host trait
 * supplies the pipeline, the form builder and the in-progress AJAX input
 * so none of that is written twice.
 */
final class DataSurfaceDemoForm extends FormBase {

  use DataSurfaceHostTrait;

  /**
   * The state key holding the demo values.
   */
  protected const STATE_KEY = 'data_surface_demo.settings';

  /**
   * The AJAX wrapper key for this form's surface container.
   */
  protected const WRAPPER_KEY = 'data-surface-demo-form';

  /**
   * The block plugin manager, which hands over the surface's provider.
   */
  protected BlockManagerInterface $blockManager;

  /**
   * The state store this form's target writes to.
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
    return 'data_surface_demo_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $surface = $this->demoSurface();
    // The pipeline's own merge rule, reused rather than restated: the
    // surface's defaults, then whatever the target holds, then the
    // in-progress choice an AJAX rebuild is refining against.
    $values = array_replace(
      $this->surfacePipeline()->accept($surface, [], $this->demoTarget()->load($surface)),
      $this->surfaceRefinementInput($surface, $form_state),
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
    $values = $builder->extractSurfaceValues(
      $surface,
      $form['surface'],
      $form_state,
      $this->demoTarget()->load($surface),
    );
    $builder->validateSurfaceForm($surface, $values, $form['surface'], $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $surface = $this->demoSurface();
    $builder = $this->surfaceFormBuilder();
    $target = $this->demoTarget();
    $values = $builder->extractSurfaceValues($surface, $form['surface'], $form_state, $target->load($surface));
    $result = $this->surfacePipeline()->submit($surface, $values, $target);
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
   * Reads the demo block's surface through the plugin manager.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The block's surface, refiner bound.
   *
   * @throws \LogicException
   *   When the plugin behind the demo ID does not provide a surface.
   */
  protected function demoSurface(): DataSurfaceInterface {
    $block = $this->blockManager->createInstance('data_surface_demo');
    if (!$block instanceof DataSurfaceProviderInterface) {
      throw new \LogicException('The data_surface_demo block does not provide a surface.');
    }
    return $block->getDataSurface();
  }

  /**
   * Builds the target this form's values are read from and written to.
   *
   * @return \Drupal\data_surface\Target\StateTarget
   *   The state target.
   */
  protected function demoTarget(): StateTarget {
    return new StateTarget($this->state, self::STATE_KEY);
  }

}
