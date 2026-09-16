<?php

declare(strict_types=1);

namespace Drupal\data_surface\Form;

use Drupal\Core\Entity\Display\EntityDisplayInterface;
use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FormatterInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DataSurfaceHostTrait;

/**
 * Implements the field formatter settings protocol from a surface.
 *
 * The adapter for ADOPTION.md group B: host protocols that ask for a
 * settings form and then harvest the raw value tree themselves, with no
 * validate or submit hook to run the pipeline in. Field UI calls
 * settingsForm() and settingsSummary() as it always has, and this trait
 * answers from the surface. When the formatter plugin type understands
 * surfaces natively these methods are exactly the code that gets
 * deleted; the surface and the provider interface survive.
 *
 * Four host realities it absorbs:
 * - The $form parameter is not a fragment to merge into. Field UI hands
 *   settingsForm() the whole manage display form and places what comes
 *   back inside one row of it, so this returns only the surface's own
 *   elements, as every core formatter does. Contrast
 *   DataSurfaceHostFormTrait, where the block, condition and action
 *   protocols really do pass the host plugin's own fragment and the
 *   surface is a guest inside it.
 * - There is nowhere to validate, so an #element_validate callback on
 *   the surface container extracts, validates, flags violations on their
 *   own elements, and writes the accepted values back into form state as
 *   the settings the host will copy. Unlike the proof of concept there
 *   is nothing to flatten on the way: widgets emit definition-shaped
 *   trees, so what extraction returns is already the settings shape.
 * - defaultSettings() is static and cannot consult an instance surface.
 *   surfaceDefaultSettings() answers it from the class's own
 *   declaration, which is a static method for exactly this reason; a
 *   class whose surface is built at runtime overrides defaultSettings()
 *   itself.
 * - The host prunes settings against that static array on save:
 *   EntityDisplayBase::setComponent() runs values through the formatter
 *   manager's prepareConfiguration(), which intersects them with
 *   defaultSettings(). Keys mounted onto the surface at build time (the
 *   third_party_settings namespace) must therefore appear in the static
 *   array too, or they are silently dropped from the display — which is
 *   why surfaceDefaultSettings() always declares that key.
 *
 * Nothing but identifiers rides on the element. A surface holds its
 * refiners, and for a formatter the refiner is usually the formatter
 * itself, so putting one on a form array handed the form cache a plugin
 * with whatever the plugin holds. What the element carries instead is
 * the formatter's plugin id and the field's name, and the static
 * callback rebuilds the formatter from them: through the display the
 * host is editing when there is one, which is where the real field
 * definition lives, and otherwise through the formatter manager. See
 * docs/targets.md for the rule this is one case of.
 */
trait DataSurfaceFormatterTrait {

  use DataSurfaceHostTrait;

  /**
   * The element key carrying the formatter's plugin id.
   */
  protected const FORMATTER_ELEMENT_KEY = '#data_surface';

  /**
   * The element key carrying the name of the field being formatted.
   */
  protected const FIELD_NAME_ELEMENT_KEY = '#data_surface_field_name';

  /**
   * The element key carrying the values the form started from.
   */
  protected const CURRENT_ELEMENT_KEY = '#data_surface_current';

  /**
   * Builds the surface describing this formatter's settings.
   *
   * @param string $operation
   *   The host operation the surface is wanted for.
   * @param string|null $subject
   *   The id of the thing the operation is about, or NULL when the
   *   provider is its own subject.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The surface.
   */
  abstract public function getDataSurface(string $operation = 'configure', ?string $subject = NULL): DataSurfaceInterface;

  /**
   * States that a formatter has no target of its own to hand out.
   *
   * The shipped example of the provider contract's one refusal. A
   * formatter's settings are not the formatter's to store: they are one
   * component of an entity view display, and Field UI copies whatever
   * the settings element produced onto that display and saves it. So
   * there is no destination this object could name, and the settings
   * form path never asks for one — it runs accept and validate in an
   * #element_validate callback and writes the accepted values back into
   * form state for the host to copy.
   *
   * Saying so out loud is the point. A quietly useless target — the
   * formatter's own settings array, say, thrown away with the plugin
   * instance at the end of the request — would let a caller submit into
   * it and be told the values were stored.
   *
   * A caller that means to write a formatter's settings writes the
   * display: load the entity view display, set the component, save it.
   *
   * @param string $operation
   *   The host operation the target is wanted for.
   * @param string|null $subject
   *   The id of the thing the operation is about.
   *
   * @return never
   *   Never returns: the provider contract's one refusal.
   *
   * @throws \LogicException
   *   Always.
   *
   * @see \Drupal\data_surface\DataSurfaceProviderInterface::getDataSurfaceTarget()
   */
  public function getDataSurfaceTarget(string $operation = 'configure', ?string $subject = NULL): never {
    throw new \LogicException(sprintf(
      'The settings of the %s formatter are stored by the entity view display that hosts it, not by the formatter, so there is no target to hand out; write the display component instead.',
      static::class,
    ));
  }

  /**
   * Reads the static default settings from a class's declaration.
   *
   * @param class-string $class
   *   The fully qualified formatter class name.
   *
   * @return array
   *   The declared defaults keyed by setting name, plus the mounted
   *   third party namespace the host prunes against.
   *
   * @throws \LogicException
   *   When the class declares no surface, in which case it has to
   *   answer defaultSettings() itself.
   */
  protected static function surfaceDefaultSettings(string $class): array {
    $defaults = static::surfaceDeclaredDefaults($class);
    // Declared unconditionally: the host prunes saved settings against
    // this array, and definitions other modules mount onto the surface
    // at build time land under this key.
    $defaults['third_party_settings'] = [];
    return $defaults;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state): array {
    $surface = $this->getDataSurface();
    $builder = $this->surfaceFormBuilder();
    $stored = array_intersect_key($this->getSettings(), $surface->getDefinitions()->toArray());
    $values = array_replace($stored, $this->surfaceRefinementInput($surface, $form_state));
    $field_name = $this->fieldDefinition->getName();
    $element = $builder->buildSurfaceForm(
      $surface,
      $values,
      $form_state,
      'data-surface-' . $field_name,
    );
    // Identifiers, not objects: the static callback rebuilds the
    // formatter, and with it the surface and its refiner, from these two
    // strings, so the form cache only ever holds strings and arrays.
    $element[static::FORMATTER_ELEMENT_KEY] = $this->getPluginId();
    $element[static::FIELD_NAME_ELEMENT_KEY] = $field_name;
    // The stored settings ride along for the validate callback, which is
    // static and has no formatter to ask. A key with no rendered element
    // must keep what is stored rather than fall back to its default.
    $element[static::CURRENT_ELEMENT_KEY] = $stored;
    $element['#element_validate'] = [[static::class, 'validateSurfaceSettings']];
    // Only the surface's own elements, which is what every core
    // formatter returns and what Field UI asks for. The $form parameter
    // is not a fragment to merge into: EntityDisplayFormBase hands
    // settingsForm() the whole manage display form and then places the
    // return value inside one table row, so merging into it nested the
    // entire display form under one field's settings. It also decides
    // whether to offer the settings cog by asking whether the return is
    // empty, which a merged display form can never be. The container
    // built above is already complete — type, tree flag, wrapper id and
    // process callback and all — so there is nothing left to merge.
    return $element;
  }

  /**
   * Element validate: the pipeline stage this host protocol has no hook for.
   *
   * @param array $element
   *   The surface container element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public static function validateSurfaceSettings(array $element, FormStateInterface $form_state): void {
    $current = $element[static::CURRENT_ELEMENT_KEY] ?? [];
    $formatter = static::surfaceFormatter(
      (string) ($element[static::FORMATTER_ELEMENT_KEY] ?? ''),
      (string) ($element[static::FIELD_NAME_ELEMENT_KEY] ?? ''),
      is_array($current) ? $current : [],
      $form_state,
    );
    $surface = $formatter->getDataSurface();
    // @phpstan-ignore globalDrupalDependencyInjection.useDependencyInjection
    $builder = \Drupal::service('data_surface.form_builder');
    $values = $builder->extractSurfaceValues($surface, $element, $form_state, is_array($current) ? $current : []);
    $builder->validateSurfaceForm($surface, $values, $element, $form_state);
    // The host copies whatever the elements produced, so the accepted
    // values have to be what it finds there.
    $form_state->setValueForElement($element, $values);
  }

  /**
   * Rebuilds the formatter the element was built from.
   *
   * The display being edited is asked first. Field UI's manage display
   * form is an entity form over an entity display, and a display can
   * hand back the renderer for one of its fields already configured with
   * the field's real definition, which is the closest thing to the very
   * object that built the element.
   *
   * A caller that is not Field UI — a test, a REST resource, an agent
   * replaying a submission — has no such form object, so the formatter
   * is built through its plugin manager instead, with a field definition
   * carrying the field's name and nothing else. That is enough for the
   * surface, because a formatter describes its settings from its class
   * and refines them from the submitted values; a formatter whose
   * advertisement genuinely depends on the field it formats is reachable
   * through the display form, which supplies the real definition.
   *
   * @param string $plugin_id
   *   The formatter plugin id the element carries.
   * @param string $field_name
   *   The name of the field being formatted.
   * @param array $settings
   *   The settings the form started from.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return static
   *   The formatter, which is also the surface's refiner.
   *
   * @throws \LogicException
   *   When the element names no formatter this class can rebuild.
   */
  protected static function surfaceFormatter(string $plugin_id, string $field_name, array $settings, FormStateInterface $form_state): static {
    $formatter = static::surfaceDisplayFormatter($field_name, $form_state);
    if ($formatter === NULL && $plugin_id !== '') {
      // @phpstan-ignore globalDrupalDependencyInjection.useDependencyInjection
      $formatter = \Drupal::service('plugin.manager.field.formatter')->createInstance($plugin_id, [
        'field_definition' => BaseFieldDefinition::create('string')->setName($field_name),
        'settings' => $settings,
        'label' => 'above',
        'view_mode' => 'default',
        'third_party_settings' => [],
      ]);
    }
    if (!$formatter instanceof static) {
      throw new \LogicException(sprintf(
        'The settings element names no %s formatter this request can rebuild, so its surface cannot be rebuilt to validate it.',
        static::class,
      ));
    }
    return $formatter;
  }

  /**
   * Reads the formatter off the entity display the host form is editing.
   *
   * @param string $field_name
   *   The name of the field being formatted.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return \Drupal\Core\Field\FormatterInterface|null
   *   The renderer the display configures for the field, or NULL when
   *   the host is not an entity display form.
   */
  protected static function surfaceDisplayFormatter(string $field_name, FormStateInterface $form_state): ?FormatterInterface {
    // Read through the build info rather than through getFormObject(),
    // which assumes the key is there and warns when it is not: a caller
    // replaying a submission builds its own form state and has no form
    // object at all.
    $form_object = $form_state->getBuildInfo()['callback_object'] ?? NULL;
    if ($field_name === '' || !$form_object instanceof EntityFormInterface) {
      return NULL;
    }
    $display = $form_object->getEntity();
    if (!$display instanceof EntityDisplayInterface) {
      return NULL;
    }
    $renderer = $display->getRenderer($field_name);
    return $renderer instanceof FormatterInterface ? $renderer : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary(): array {
    $summary = [];
    $settings = $this->getSettings();
    foreach ($this->getDataSurface()->getDefinitions() as $name => $definition) {
      $this->appendSettingSummary($summary, $definition, $definition->getLabel() ?? $name, $settings[$name] ?? NULL);
    }
    return $summary ?: [$this->t('Not configured')];
  }

  /**
   * Appends "Label: value" lines, recursing into nested maps.
   *
   * A mounted third-party namespace stores as nested arrays; its leaves
   * are summarized under their own definitions' labels, so a saved value
   * is visible in the row rather than hiding behind an item count.
   *
   * @param array $summary
   *   The summary lines, appended to by reference.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition the value belongs to.
   * @param string|\Stringable $label
   *   The label to print for this value, still the definition's own
   *   object so the row is translated rather than assembled.
   * @param mixed $value
   *   The stored value.
   */
  protected function appendSettingSummary(array &$summary, DataDefinitionInterface $definition, string|\Stringable $label, mixed $value): void {
    if ($value === NULL || $value === '' || $value === []) {
      return;
    }
    if (is_array($value) && $definition instanceof ComplexDataDefinitionInterface) {
      foreach ($definition->getPropertyDefinitions() as $property_name => $property_definition) {
        if (array_key_exists($property_name, $value)) {
          $this->appendSettingSummary(
            $summary,
            $property_definition,
            $property_definition->getLabel() ?? $property_name,
            $value[$property_name],
          );
        }
      }
      return;
    }
    if (is_bool($value)) {
      $value = $value ? $this->t('yes') : $this->t('no');
    }
    // An array without a complex definition summarizes by size.
    if (is_array($value)) {
      $value = $this->formatPlural(count($value), '1 item', '@count items');
    }
    // One sentence with two placeholders, not two translated fragments
    // glued together: a translator sees the whole row, and both halves
    // are escaped by the placeholder mechanism rather than by whatever
    // happens to render the string later.
    $summary[] = new TranslatableMarkup('@label: @value', [
      '@label' => $label,
      '@value' => $value,
    ]);
  }

}
