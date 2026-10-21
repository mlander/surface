<?php

declare(strict_types=1);

namespace Drupal\data_surface_demo_node_type\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Form\DataSurfaceFormBuilderInterface;
use Drupal\data_surface\Form\DataSurfaceFormCosmeticsInterface;
use Drupal\data_surface\Form\DataSurfaceOperationForm;
use Drupal\data_surface\Pipeline\DataSurfaceResult;

/**
 * Everything the content type form still has an opinion about.
 *
 * All that is left of a form class once the values and their arrangement
 * are declared on a resource: core's vertical tabs borrowed to hold the
 * groups the resource declares, the machine name's mirror-while-typing,
 * the sentence a person reads afterwards and the page they land on. No
 * build, no validate, no submit, and no grouping or weight: which key
 * sits in which group is the resource's, read by every door.
 *
 * Deleting this class yields the same working form, its groups drawn as
 * details one under another, which is the test of whether a cosmetic
 * layer is really cosmetic.
 */
final class NodeTypeResourceFormCosmetics implements DataSurfaceFormCosmeticsInterface {

  use StringTranslationTrait;

  /**
   * The groups the resource declares, which the vertical tabs hold.
   */
  protected const TABS = ['submission', 'workflow', 'display'];

  /**
   * Constructs a NodeTypeResourceFormCosmetics object.
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
   * The vertical tabs go directly before the first group, so they render
   * before the groups they gather by `#group`: a group drawn before its
   * holder would print itself in place and leave the tab empty.
   */
  public function alterSurfaceForm(array $form, DataSurfaceInterface $surface, FormStateInterface $form_state, string $operation, ?string $subject): array {
    $key = DataSurfaceOperationForm::SURFACE_KEY;
    $groups = array_values(array_filter(
      array_map(static fn (string $group): string => DataSurfaceFormBuilderInterface::GROUP_PREFIX . $group, self::TABS),
      static fn (string $group): bool => isset($form[$key][$group]),
    ));
    if ($groups !== []) {
      $surface_form = [];
      foreach ($form[$key] as $name => $element) {
        if ($name === $groups[0]) {
          $surface_form['additional_settings'] = ['#type' => 'vertical_tabs'];
          if (isset($element['#weight'])) {
            $surface_form['additional_settings']['#weight'] = $element['#weight'];
          }
        }
        $surface_form[$name] = $element;
      }
      foreach ($groups as $group) {
        $surface_form[$group]['#group'] = $key . '][additional_settings';
      }
      $form[$key] = $surface_form;
    }

    // The element type is borrowed for what it draws, not for what it
    // checks: its own validation asks what the resource's Regex and
    // uniqueness constraints already ask, answers first, and a form
    // state keeps only the FIRST error per element, so the element's
    // generic sentence would replace the resource's violation. Dropping
    // #element_validate is what keeps this layer cosmetic. Only an add
    // has anything to mirror; on edit the key is locked and the
    // generated element is already disabled.
    if ($operation === 'add') {
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
    return $operation === 'add'
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
