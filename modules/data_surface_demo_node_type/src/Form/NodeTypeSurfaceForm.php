<?php

declare(strict_types=1);

namespace Drupal\data_surface_demo_node_type\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\data_surface\Form\DataSurfaceFormBuilderInterface;
use Drupal\data_surface\Pipeline\DataSurfacePipelineInterface;
use Drupal\data_surface_demo_node_type\NodeTypeSurfaceProvider;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeTypeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The surface-driven alternative to the content type add/edit form.
 *
 * One form class, one surface declaration, both operations: the
 * operation is context handed to the provider, which locks the machine
 * name on edit and adds the uniqueness constraint on add. There is no
 * save method worth the name, because there is nothing left for one to
 * do: submit hands the raw values and the composite target to the
 * pipeline, and the pipeline accepts, validates, prepares, and commits.
 * Everything below the surface build is generic, and the only
 * form-specific code left is cosmetic, which is precisely the role this
 * architecture leaves to Form API.
 */
final class NodeTypeSurfaceForm extends FormBase {

  /**
   * Constructs a NodeTypeSurfaceForm.
   *
   * @param \Drupal\data_surface_demo_node_type\NodeTypeSurfaceProvider $provider
   *   The provider of the surface and its storage.
   * @param \Drupal\data_surface\Form\DataSurfaceFormBuilderInterface $surfaceFormBuilder
   *   The surface form builder.
   * @param \Drupal\data_surface\Pipeline\DataSurfacePipelineInterface $pipeline
   *   The pipeline, the one way values reach storage.
   */
  public function __construct(
    protected readonly NodeTypeSurfaceProvider $provider,
    protected readonly DataSurfaceFormBuilderInterface $surfaceFormBuilder,
    protected readonly DataSurfacePipelineInterface $pipeline,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('data_surface_demo_node_type.provider'),
      $container->get('data_surface.form_builder'),
      $container->get('data_surface.pipeline'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'data_surface_demo_node_type_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeTypeInterface $node_type = NULL): array {
    $surface = $this->provider->surfaceFor($node_type);
    // Definition defaults carry the entity's current values, so the
    // surface's own defaults are the form's values.
    $form['surface'] = $this->surfaceFormBuilder->buildSurfaceForm(
      $surface,
      $surface->getDefaultValues(),
      $form_state,
      'node-type-surface',
    );

    // Cosmetic layer: core's visual grouping, vertical tabs and all.
    // Pure presentation, since every element keeps its name and
    // #parents and #group only relocates it at render time, so
    // extraction, validation, and the machine-visible surface are
    // identical with or without this block. Deleting it yields the same
    // flat working form.
    //
    // Render-order landmine: the groups registry holds live references,
    // so a member that renders before its group self-suppresses through
    // the shared reference and the group later injects an already
    // printed copy, giving an empty details element. The group elements
    // must render BEFORE their members, hence the tabs sit inside the
    // surface container after the fields that stay flat, at weight 10,
    // with every grouped member pushed after them at weight 20.
    $form['surface']['additional_settings'] = [
      '#type' => 'vertical_tabs',
      '#weight' => 10,
    ];
    $form['surface']['submission'] = [
      '#type' => 'details',
      '#title' => $this->t('Submission form settings'),
      '#group' => 'surface][additional_settings',
      '#open' => TRUE,
      '#weight' => 11,
    ];
    $form['surface']['workflow'] = [
      '#type' => 'details',
      '#title' => $this->t('Publishing options'),
      '#group' => 'surface][additional_settings',
      '#weight' => 12,
    ];
    $form['surface']['display'] = [
      '#type' => 'details',
      '#title' => $this->t('Display settings'),
      '#group' => 'surface][additional_settings',
      '#weight' => 13,
    ];
    $groups = [
      'title_label' => 'submission',
      'preview_mode' => 'submission',
      'help' => 'submission',
      'status' => 'workflow',
      'promote' => 'workflow',
      'sticky' => 'workflow',
      'new_revision' => 'workflow',
      'display_submitted' => 'display',
    ];
    foreach ($groups as $key => $group) {
      $form['surface'][$key]['#group'] = 'surface][' . $group;
      $form['surface'][$key]['#weight'] = 20;
    }

    // Cosmetic layer: the machine name element's mirror-while-typing UX.
    // Presentation only, since the surface already carries the pattern,
    // the length, and the uniqueness constraint, so nothing about
    // validity changes here.
    if ($node_type === NULL) {
      $form['surface']['type']['#type'] = 'machine_name';
      $form['surface']['type']['#machine_name'] = [
        'exists' => [NodeType::class, 'load'],
        'source' => ['surface', 'name'],
      ];
    }

    $form['actions'] = [
      '#type' => 'actions',
      '#weight' => 100,
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
    $surface = $this->provider->surfaceFor($this->routeNodeType($form_state));
    // The provider puts the entity's current values on the definitions
    // as their defaults, so the surface's defaults are what storage
    // holds; passing them keeps a key with no rendered element at its
    // stored value rather than at a type default.
    $values = $this->surfaceFormBuilder->extractSurfaceValues(
      $surface,
      $form['surface'],
      $form_state,
      $surface->getDefaultValues(),
    );
    $this->surfaceFormBuilder->validateSurfaceForm($surface, $values, $form['surface'], $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $node_type = $this->routeNodeType($form_state);
    $surface = $this->provider->surfaceFor($node_type);
    $values = $this->surfaceFormBuilder->extractSurfaceValues(
      $surface,
      $form['surface'],
      $form_state,
      $surface->getDefaultValues(),
    );
    // On add the bundle is one of the submitted values, so the target
    // cannot be built before the values are read.
    $target = $this->provider->targetFor($node_type, (string) ($values['type'] ?? ''));
    // The provider's answer for the same coordinate the surface was
    // built for, handed to the pipeline rather than re-asked here: the
    // route that rendered this form and the write that ends it are then
    // gated by one answer, and a permission revoked between the two is
    // caught by the half that writes. Adding names no content type yet,
    // so the verb travels alone; editing names the one it is about.
    $access = $node_type === NULL
      ? $this->provider->surfaceAccess(NodeTypeSurfaceProvider::OPERATION_ADD)
      : $this->provider->surfaceAccess(
        NodeTypeSurfaceProvider::OPERATION_EDIT,
        (string) $node_type->id(),
      );
    $result = $this->pipeline->submit($surface, $values, $target, access: $access);
    if (!$result->isValid()) {
      $this->surfaceFormBuilder->flagSurfaceErrors($result->violations, $form['surface'], $form_state);
      return;
    }
    $this->messenger()->addStatus($node_type === NULL
      ? $this->t('The content type %name has been added.', ['%name' => $result->values['name']])
      : $this->t('The content type %name has been updated.', ['%name' => $result->values['name']]));
    $form_state->setRedirectUrl(Url::fromRoute('entity.node_type.collection'));
  }

  /**
   * Gets the content type from the route, if the form is editing one.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return \Drupal\node\NodeTypeInterface|null
   *   The content type being edited, or NULL when adding.
   */
  protected function routeNodeType(FormStateInterface $form_state): ?NodeTypeInterface {
    $argument = $form_state->getBuildInfo()['args'][0] ?? NULL;
    return $argument instanceof NodeTypeInterface ? $argument : NULL;
  }

}
