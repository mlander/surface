<?php

declare(strict_types=1);

namespace Drupal\data_surface_demo_node_type\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Form\DataSurfaceFormCosmeticsInterface;
use Drupal\data_surface\Form\DataSurfaceProviderForm;
use Drupal\data_surface\Pipeline\DataSurfaceResult;
use Drupal\data_surface_demo_node_type\NodeTypeSurfaceProvider;

/**
 * Everything the content type form still has an opinion about.
 *
 * All that is left of a form class once the values are declared on a
 * surface: core's visual grouping, the machine name's mirror-while-
 * typing, the sentence a person reads afterwards and the page they land
 * on. There is no build, no validate and no submit, because there was
 * nothing in them that was about content types — DataSurfaceProviderForm
 * runs the pipeline for every provider alike, and the two routes name
 * this class beside the provider.
 *
 * Nothing here can change what a value means. Every element keeps its
 * name and its #parents; #group only relocates an element at render
 * time, and the machine name element already carries the pattern, the
 * length and the uniqueness constraint from the surface. Deleting this
 * class yields the same flat working form, which is the test of whether
 * a cosmetic layer is really cosmetic.
 */
final class NodeTypeSurfaceFormCosmetics implements DataSurfaceFormCosmeticsInterface {

  use StringTranslationTrait;

  /**
   * Constructs a NodeTypeSurfaceFormCosmetics object.
   *
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service.
   */
  public function __construct(TranslationInterface $string_translation) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * {@inheritdoc}
   *
   * Render-order landmine, kept from the form class this replaces: the
   * groups registry holds live references, so a member that renders
   * before its group self-suppresses through the shared reference and
   * the group later injects an already printed copy, giving an empty
   * details element. The group elements must render BEFORE their
   * members, hence the tabs sit inside the surface container after the
   * fields that stay flat, at weight 10, with every grouped member
   * pushed after them at weight 20.
   */
  public function alterSurfaceForm(array $form, DataSurfaceInterface $surface, FormStateInterface $form_state, string $operation, ?string $subject): array {
    $key = DataSurfaceProviderForm::SURFACE_KEY;
    $form[$key]['additional_settings'] = [
      '#type' => 'vertical_tabs',
      '#weight' => 10,
    ];
    $form[$key]['submission'] = [
      '#type' => 'details',
      '#title' => $this->t('Submission form settings'),
      '#group' => $key . '][additional_settings',
      '#open' => TRUE,
      '#weight' => 11,
    ];
    $form[$key]['workflow'] = [
      '#type' => 'details',
      '#title' => $this->t('Publishing options'),
      '#group' => $key . '][additional_settings',
      '#weight' => 12,
    ];
    $form[$key]['display'] = [
      '#type' => 'details',
      '#title' => $this->t('Display settings'),
      '#group' => $key . '][additional_settings',
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
    foreach ($groups as $name => $group) {
      $form[$key][$name]['#group'] = $key . '][' . $group;
      $form[$key][$name]['#weight'] = 20;
    }

    // The machine name element's mirror-while-typing UX, which only an
    // add has anything to mirror: on edit the key is locked, and the
    // generated element is already disabled.
    //
    // The element type is borrowed for what it draws, not for what it
    // checks. Core's machine name element also validates, against its
    // own pattern and against an "exists" callback, and both of those
    // questions are already on the surface: the Regex constraint the
    // definition carries, and the uniqueness constraint the provider
    // adds for this operation. Left in place they answer first, and a
    // form state keeps only the FIRST error per element, so the
    // element's generic sentence would replace the surface's violation
    // and the value that was refused would never be named. A layer that
    // decides what a value may be is no longer cosmetic, so the
    // checking half of the borrowed element is dropped here and the
    // surface stays the one authority on what this key accepts.
    if ($operation === NodeTypeSurfaceProvider::OPERATION_ADD) {
      $form[$key]['type']['#type'] = 'machine_name';
      $form[$key]['type']['#machine_name'] = [
        'source' => [$key, 'name'],
      ];
      $form[$key]['type']['#element_validate'] = [];
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * The accepted values name the content type rather than the form
   * does, which is what makes the message right for a machine name the
   * lock refused to move. Narrower than the interface allows, because
   * this layer always has something to say.
   */
  public function surfaceFormMessage(DataSurfaceResult $result, string $operation, ?string $subject): \Stringable {
    return $operation === NodeTypeSurfaceProvider::OPERATION_ADD
      ? $this->t('The content type %name has been added.', ['%name' => $result->values['name']])
      : $this->t('The content type %name has been updated.', ['%name' => $result->values['name']]);
  }

  /**
   * {@inheritdoc}
   */
  public function surfaceFormRedirect(DataSurfaceResult $result, string $operation, ?string $subject): Url {
    return Url::fromRoute('entity.node_type.collection');
  }

}
