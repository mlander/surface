<?php

declare(strict_types=1);

namespace Drupal\data_surface_demo_extras\Hook;

use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\data_surface_demo_extras\EventSubscriber\DemoExtrasSurfaceSubscriber;
use Drupal\data_surface_demo_extras\NodeTypeReviewSettings;
use Drupal\node\NodeTypeInterface;

/**
 * The editorial review settings, added the way core's own modules do it.
 *
 * The classic half of the comparison, and written to be a fair one: this
 * is what menu_ui does to the same form — a details element in the
 * vertical tabs, an element validator, an entity builder writing third
 * party settings — and it works well for the person filling the form in.
 * The deadline is an amount and a unit, stored as seconds and refused
 * outside one hour to thirty days; the tags field takes "Local news,
 * Sports, sports" and stores ["local news", "sports"].
 *
 * The config schema this module provides is as complete as a schema can
 * be: the deadline is an integer with its Range, one hour to thirty days
 * in seconds. What it cannot carry is meaning. That the integer is
 * seconds, that a person picks hours, days or weeks, and that "one week"
 * is 604800 live in this class's validation callback and entity builder,
 * as does everything done to the tags. A caller that never renders this
 * form — an agent creating a content type through a tool — sees at best
 * an integer between 3600 and 2592000, and has no way to learn that 7
 * meant a week to the person who would have typed it.
 *
 * @see \Drupal\data_surface_demo_extras\EventSubscriber\DemoExtrasSurfaceSubscriber::extendNodeType()
 *   The same two settings, said as contract.
 */
final class NodeTypeFormHooks {

  use StringTranslationTrait;

  /**
   * The form element and form state key the two settings live under.
   */
  protected const ELEMENT = DemoExtrasSurfaceSubscriber::PROVIDER;

  /**
   * Constructs a NodeTypeFormHooks object.
   *
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service.
   */
  public function __construct(TranslationInterface $string_translation) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Implements hook_form_BASE_FORM_ID_alter() for node_type_form.
   *
   * Adds the review settings to the content type add and edit forms.
   *
   * @see \Drupal\node\Form\NodeTypeForm::form()
   */
  #[Hook('form_node_type_form_alter')]
  public function formNodeTypeFormAlter(array &$form, FormStateInterface $form_state): void {
    $form_object = $form_state->getFormObject();
    if (!$form_object instanceof EntityFormInterface) {
      return;
    }
    $type = $form_object->getEntity();
    if (!$type instanceof NodeTypeInterface) {
      return;
    }
    $provider = DemoExtrasSurfaceSubscriber::PROVIDER;
    $tags = $type->getThirdPartySetting($provider, NodeTypeReviewSettings::TAGS, []);
    $seconds = $type->getThirdPartySetting($provider, NodeTypeReviewSettings::DEADLINE);
    $deadline = is_int($seconds) ? NodeTypeReviewSettings::split($seconds) : NULL;
    $form[self::ELEMENT] = [
      '#type' => 'details',
      '#title' => $this->t('Editorial review'),
      '#group' => 'additional_settings',
      '#tree' => TRUE,
      NodeTypeReviewSettings::DEADLINE => [
        '#type' => 'fieldset',
        '#title' => $this->t('Review deadline'),
        '#description' => $this->t('How long an editor has to review new content of this type, from one hour to thirty days. Leave the amount empty for no deadline.'),
        '#attributes' => ['class' => ['container-inline']],
        '#element_validate' => [static::class . ':validateReviewDeadline'],
        NodeTypeReviewSettings::AMOUNT => [
          '#type' => 'number',
          '#title' => $this->t('Amount'),
          '#min' => 1,
          '#step' => 1,
          '#default_value' => $deadline[NodeTypeReviewSettings::AMOUNT] ?? NULL,
        ],
        NodeTypeReviewSettings::UNIT => [
          '#type' => 'select',
          '#title' => $this->t('Unit'),
          '#options' => [
            'hours' => $this->t('Hours'),
            'days' => $this->t('Days'),
            'weeks' => $this->t('Weeks'),
          ],
          '#default_value' => $deadline[NodeTypeReviewSettings::UNIT] ?? NodeTypeReviewSettings::DEFAULT_UNIT,
        ],
      ],
      NodeTypeReviewSettings::TAGS => [
        '#type' => 'textfield',
        '#title' => $this->t('Audience tags'),
        '#description' => $this->t('Who content of this type is written for, separated by commas, for example "Local news, Sports".'),
        '#default_value' => is_array($tags) ? implode(', ', $tags) : '',
        '#maxlength' => 1024,
        '#element_validate' => [static::class . ':validateAudienceTags'],
      ],
    ];
    $form['#entity_builders'][] = static::class . ':buildNodeType';
  }

  /**
   * Element validator: turns the amount and unit into stored seconds.
   *
   * Refuses a deadline shorter than an hour or longer than thirty days,
   * then replaces the pair with the one integer the entity builder
   * stores, or NULL when no amount was given.
   *
   * @param array $element
   *   The deadline fieldset.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function validateReviewDeadline(array &$element, FormStateInterface $form_state): void {
    $amount = $element[NodeTypeReviewSettings::AMOUNT]['#value'] ?? '';
    if ($amount === '') {
      $form_state->setValueForElement($element, NULL);
      return;
    }
    $unit = (string) $element[NodeTypeReviewSettings::UNIT]['#value'];
    $seconds = NodeTypeReviewSettings::seconds((int) $amount, isset(NodeTypeReviewSettings::UNITS[$unit]) ? $unit : NodeTypeReviewSettings::DEFAULT_UNIT);
    if ($seconds < NodeTypeReviewSettings::DEADLINE_MIN || $seconds > NodeTypeReviewSettings::DEADLINE_MAX) {
      $form_state->setError($element[NodeTypeReviewSettings::AMOUNT], $this->t('The review deadline must be between one hour and thirty days.'));
      return;
    }
    $form_state->setValueForElement($element, $seconds);
  }

  /**
   * Element validator: turns the typed tags into the list that is stored.
   *
   * Splits on commas, trims, lower cases and drops empty entries and
   * repeats, then refuses a tag that is still not one a content type can
   * carry. What reaches the entity builder is the stored shape.
   *
   * @param array $element
   *   The tags element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function validateAudienceTags(array &$element, FormStateInterface $form_state): void {
    $tags = [];
    foreach (explode(',', (string) $element['#value']) as $tag) {
      $tag = mb_strtolower(trim($tag));
      if ($tag === '') {
        continue;
      }
      if (!preg_match(NodeTypeReviewSettings::TAG_PATTERN, $tag)) {
        $form_state->setError($element, $this->t('The audience tag %tag may contain only letters, digits, and single spaces or hyphens between words.', [
          '%tag' => $tag,
        ]));
        return;
      }
      $tags[$tag] = $tag;
    }
    $form_state->setValueForElement($element, array_values($tags));
  }

  /**
   * Entity builder: stores the two settings on the content type.
   *
   * @param string $entity_type_id
   *   The entity type identifier.
   * @param \Drupal\node\NodeTypeInterface $type
   *   The content type being built.
   * @param array $form
   *   The complete form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function buildNodeType(string $entity_type_id, NodeTypeInterface $type, array &$form, FormStateInterface $form_state): void {
    $provider = DemoExtrasSurfaceSubscriber::PROVIDER;
    $deadline = $form_state->getValue([self::ELEMENT, NodeTypeReviewSettings::DEADLINE]);
    $tags = $form_state->getValue([self::ELEMENT, NodeTypeReviewSettings::TAGS]);
    $type->setThirdPartySetting($provider, NodeTypeReviewSettings::DEADLINE, is_int($deadline) ? $deadline : NULL);
    $type->setThirdPartySetting($provider, NodeTypeReviewSettings::TAGS, is_array($tags) ? $tags : []);
  }

}
