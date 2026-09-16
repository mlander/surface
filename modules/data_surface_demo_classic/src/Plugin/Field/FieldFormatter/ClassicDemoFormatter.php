<?php

declare(strict_types=1);

namespace Drupal\data_surface_demo_classic\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\Attribute\FieldFormatter;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * The demo formatter, written the way formatters were written before.
 *
 * Behaviorally identical to `data_surface_demo_string`: the same three
 * settings, the same defaults, the same casing-narrows-variant chain
 * over AJAX, the same summary lines and the same markup. Field UI has no
 * validate and no submit hook for settings and prunes what it saves
 * against a static array, so the checking happens in an
 * `#element_validate` callback and `defaultSettings()` is written out by
 * hand beside the form that has to agree with it.
 *
 * The shortest correct version of the classic approach, not a straw man.
 *
 * @see \Drupal\data_surface_demo\Plugin\Field\FieldFormatter\DataSurfaceDemoFormatter
 * @see modules/data_surface_demo_classic/README.md
 */
#[FieldFormatter(
  id: 'data_surface_demo_classic_string',
  label: new TranslatableMarkup('Data surface demo formatter (classic)'),
  field_types: ['string'],
)]
final class ClassicDemoFormatter extends FormatterBase {

  /**
   * The prefix every variant class carries.
   */
  public const VARIANT_CLASS_PREFIX = 'data-surface-variant-';

  /**
   * The longest prefix a person may enter.
   */
  protected const PREFIX_MAX_LENGTH = 10;

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings(): array {
    return [
      'prefix' => NULL,
      'casing' => 'none',
      'variant' => NULL,
    ] + parent::defaultSettings();
  }

  /**
   * The casings this formatter offers, keyed by stored value.
   *
   * A method rather than a constant: PHP allows no object in a constant
   * and the labels are translatable markup.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   The labels, keyed by the value they belong to.
   */
  public static function casings(): array {
    return [
      'none' => new TranslatableMarkup('As written'),
      'uppercase' => new TranslatableMarkup('Upper case'),
      'lowercase' => new TranslatableMarkup('Lower case'),
    ];
  }

  /**
   * The variants this formatter offers, keyed by stored value.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   The labels, keyed by the value they belong to.
   */
  public static function variants(): array {
    return [
      'bold' => new TranslatableMarkup('Bold'),
      'strong' => new TranslatableMarkup('Strong'),
      'quiet' => new TranslatableMarkup('Quiet'),
      'muted' => new TranslatableMarkup('Muted'),
    ];
  }

  /**
   * The variants one casing offers.
   *
   * @param string $casing
   *   The chosen casing.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   The labels, keyed by the value they belong to; every variant when
   *   the casing has none of its own, which is not the same as none.
   */
  public static function variantsFor(string $casing): array {
    $offered = match ($casing) {
      'uppercase' => ['bold', 'strong'],
      'lowercase' => ['quiet', 'muted'],
      default => [],
    };
    return $offered === []
      ? static::variants()
      : array_intersect_key(static::variants(), array_flip($offered));
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state): array {
    $wrapper = 'data-surface-demo-classic-' . $this->fieldDefinition->getName();
    // During an AJAX rebuild the variant list has to be built from the
    // casing that was just chosen rather than from the stored one, and
    // the settings sit at an address only the triggering element knows.
    $casing = (string) ($this->submittedCasing($form_state) ?? $this->getSetting('casing'));

    $element = [];
    $element['#prefix'] = '<div id="' . $wrapper . '">';
    $element['#suffix'] = '</div>';

    $element['prefix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Prefix'),
      '#description' => $this->t('Text placed before each value.'),
      '#default_value' => $this->getSetting('prefix'),
      '#maxlength' => static::PREFIX_MAX_LENGTH,
    ];

    $element['casing'] = [
      '#type' => 'select',
      '#title' => $this->t('Casing'),
      '#description' => $this->t('How the value text is cased.'),
      '#default_value' => $casing,
      '#required' => TRUE,
      '#options' => static::casings(),
      '#ajax' => [
        'callback' => [static::class, 'refreshSettings'],
        'wrapper' => $wrapper,
      ],
    ];

    $element['variant'] = [
      '#type' => 'select',
      '#title' => $this->t('Variant'),
      '#description' => $this->t('The variants the chosen casing offers.'),
      '#default_value' => $this->getSetting('variant'),
      '#options' => static::variantsFor($casing),
      '#empty_option' => $this->t('- None -'),
    ];

    // The host protocol has no validate hook, so the one place left to
    // check what was entered is the element itself.
    $element['#element_validate'] = [[static::class, 'validateSettings']];

    return $element;
  }

  /**
   * AJAX callback: replaces the settings element with a rebuilt one.
   *
   * @param array $form
   *   The whole form, already rebuilt.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The settings element, which is the parent of the casing select.
   */
  public static function refreshSettings(array $form, FormStateInterface $form_state): array {
    $parents = $form_state->getTriggeringElement()['#array_parents'] ?? [];
    $element = $form;
    foreach (array_slice($parents, 0, -1) as $key) {
      $element = $element[$key] ?? [];
    }
    return $element;
  }

  /**
   * Element validate: the stage this host protocol has no hook for.
   *
   * @param array $element
   *   The settings element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public static function validateSettings(array $element, FormStateInterface $form_state): void {
    $values = $form_state->getValue($element['#parents']) ?? [];
    $prefix = (string) ($values['prefix'] ?? '');
    if (mb_strlen($prefix) > static::PREFIX_MAX_LENGTH) {
      $form_state->setError($element['prefix'], new TranslatableMarkup(
        'Prefix cannot be longer than @max characters.',
        ['@max' => static::PREFIX_MAX_LENGTH],
      ));
    }
    $casing = (string) ($values['casing'] ?? '');
    if (!isset(static::casings()[$casing])) {
      $form_state->setError($element['casing'], new TranslatableMarkup('The casing you selected is not a valid choice.'));
      return;
    }
    $variant = (string) ($values['variant'] ?? '');
    if ($variant !== '' && !isset(static::variantsFor($casing)[$variant])) {
      $form_state->setError($element['variant'], new TranslatableMarkup('The variant you selected is not a valid choice.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary(): array {
    $summary = [];
    $labels = [
      'prefix' => new TranslatableMarkup('Prefix'),
      'casing' => new TranslatableMarkup('Casing'),
      'variant' => new TranslatableMarkup('Variant'),
    ];
    foreach ($labels as $name => $label) {
      $value = $this->getSetting($name);
      if ($value === NULL || $value === '' || $value === []) {
        continue;
      }
      $summary[] = new TranslatableMarkup('@label: @value', [
        '@label' => $label,
        '@value' => $value,
      ]);
    }
    return $summary ?: [$this->t('Not configured')];
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode): array {
    $elements = [];
    $prefix = (string) ($this->getSetting('prefix') ?? '');
    $casing = $this->getSetting('casing');
    $variant = $this->getSetting('variant');
    $classes = is_string($variant) && $variant !== ''
      ? [static::VARIANT_CLASS_PREFIX . $variant]
      : [];
    foreach ($items as $delta => $item) {
      $value = (string) ($item->getValue()['value'] ?? '');
      $value = match ($casing) {
        'uppercase' => mb_strtoupper($value),
        'lowercase' => mb_strtolower($value),
        default => $value,
      };
      $elements[$delta] = [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#attributes' => $classes === [] ? [] : ['class' => $classes],
        // A #plain_text child rather than #value, which is run through
        // Xss::filterAdmin() and would render markup a person typed into
        // the field as markup.
        'text' => ['#plain_text' => $prefix . $value],
      ];
    }
    return $elements;
  }

  /**
   * Reads the casing an in-progress rebuild has already collected.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return string|null
   *   The submitted casing, or NULL when nothing has been submitted.
   */
  protected function submittedCasing(FormStateInterface $form_state): ?string {
    $trigger = $form_state->getTriggeringElement();
    if ($trigger === NULL || !isset($trigger['#parents'])) {
      return NULL;
    }
    $parents = $trigger['#parents'];
    if (end($parents) !== 'casing') {
      return NULL;
    }
    $value = $form_state->getValue($parents);
    return is_string($value) ? $value : NULL;
  }

}
