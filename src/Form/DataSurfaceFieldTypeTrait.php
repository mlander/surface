<?php

declare(strict_types=1);

namespace Drupal\data_surface\Form;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Field\FieldConfigInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DataSurfaceHostTrait;
use Drupal\data_surface\Pipeline\DataSurfaceTargetInterface;
use Drupal\data_surface\Pipeline\TargetViolationsException;
use Drupal\data_surface\Target\FieldSettingsTarget;

/**
 * Implements the field settings protocol of a field type from a surface.
 *
 * The group C counterpart of the formatter trait: a field item class
 * whose per instance settings are described by a surface instead of by
 * the form that collects them. The method it replaces,
 * fieldSettingsForm(), is the one ADOPTION.md calls out as "described by
 * nothing more than the form" — a field type may hand back any elements
 * it likes and Field UI copies whatever those elements produced onto the
 * field config entity, so nothing outside the form knows what the
 * settings mean, what they may be, or what shape they take.
 *
 * A class using this trait declares that it implements
 * FieldSurfaceProviderInterface: both accessors are public, because the
 * settings form is not their only caller.
 *
 * Two host realities it absorbs, both inherited from group B and one of
 * them sharper here:
 * - There is no validate or submit hook, so an #element_validate on the
 *   surface container is the whole pipeline: it extracts, validates,
 *   flags violations on their own elements, and writes the result into
 *   form state.
 * - What it writes is the STORAGE shape, not the surface shape. Field UI
 *   builds $form['settings'] with #tree and merges the field type's
 *   elements into it flat, then copyFormValuesToEntity() copies the
 *   'settings' value onto the field config entity and saves it. So the
 *   values have to have been through the target's prepare() by the time
 *   validation ends. The target's commit() is deliberately NOT called on
 *   the form path: the entity is the host's to save, and calling both
 *   would save the field twice.
 *
 * That split is the honest shape of the pipeline under a host that owns
 * the write. Prepare is where the transform lives, so a caller that does
 * own the write — a config action, a test, an agent — reaches the same
 * transform through submit() and gets the same stored settings.
 *
 * The other half of the field type protocol, storage settings, is a
 * separate surface with a different signature and a $has_data lock; it
 * is not in this trait. One plugin, two surfaces.
 *
 * Nothing but identifiers rides on the element. The form cache
 * serializes the whole form array, and a surface holds its refiners
 * while a target holds services and a config entity, so putting either
 * on an element made the cache responsible for objects nobody designed
 * to be stored. What the element carries instead is the field config's
 * identifier, and the static callback rebuilds both from it: the field
 * being edited comes from the form object when the host is Field UI,
 * which is also the only way an unsaved field can be found, and
 * otherwise it is loaded by id. See docs/targets.md.
 */
trait DataSurfaceFieldTypeTrait {

  use DataSurfaceHostTrait;

  /**
   * The element key carrying the field config the settings belong to.
   */
  protected const FIELD_ELEMENT_KEY = '#data_surface';

  /**
   * The element key carrying the values the form started from.
   */
  protected const CURRENT_ELEMENT_KEY = '#data_surface_current';

  /**
   * Builds the surface describing this field type's instance settings.
   *
   * Named for the settings it describes rather than
   * DataSurfaceProviderInterface's getDataSurface(), because a field
   * type has two surfaces and the provider interface's single method
   * cannot say which one is meant. It takes the same operation and
   * subject pair, so both provider kinds are addressed one way.
   *
   * A field item is its own subject, so an implementation's first line
   * is surfaceSelfSubject(), which refuses any subject by name.
   *
   * @param string $operation
   *   The operation the surface is wanted for.
   * @param string|null $subject
   *   The id of the thing the operation is about, or NULL when the
   *   field item is its own subject.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The surface.
   */
  abstract public function getFieldSurface(string $operation = FieldSurfaceProviderInterface::OPERATION_FIELD_SETTINGS, ?string $subject = NULL): DataSurfaceInterface;

  /**
   * Gets the target the field settings are read from and written to.
   *
   * The default is the plain settings target: the surface shape is the
   * storage shape. A field type whose storage shape differs overrides
   * this and hands the target a SettingsShapeInterface, which is where
   * the transform becomes visible.
   *
   * A field item is its own subject, as it is for the surface, so the
   * first line refuses any subject by name.
   *
   * @param string $operation
   *   The operation the target is wanted for. Not read: both of a field
   *   type's settings sets are stored on the same field config entity,
   *   and the storage settings half is not in this trait at all.
   * @param string|null $subject
   *   The id of the thing the operation is about, which for a field item
   *   may only be NULL.
   *
   * @return \Drupal\data_surface\Pipeline\DataSurfaceTargetInterface
   *   The target.
   *
   * @throws \InvalidArgumentException
   *   When a subject was named.
   * @throws \LogicException
   *   When the item's field definition is not a field config entity,
   *   which is the only kind of definition whose settings are editable.
   */
  public function getDataSurfaceTarget(string $operation = FieldSurfaceProviderInterface::OPERATION_FIELD_SETTINGS, ?string $subject = NULL): DataSurfaceTargetInterface {
    $this->surfaceSelfSubject($subject);
    return new FieldSettingsTarget($this->settingsFieldConfig());
  }

  /**
   * Answers whether an account may configure these field settings.
   *
   * The field config entity answers, which is core's own spelling of who
   * may administer a field: its access handler delegates to the field
   * storage, where the "administer <entity type> fields" permission
   * lives, and any module refining that through the entity access hooks
   * is heard. So a tool, a settings form and an agent all reach the same
   * answer as Field UI, and the answer carries its own cacheability.
   *
   * A field item whose definition is not a field config entity — a base
   * field, a definition built in code — has no per instance settings to
   * write and nothing to ask, so it expresses no opinion rather than
   * refusing: the host that reached it has its own gate.
   *
   * The subject is not read, for the reason an access question never
   * throws over one: the field config entity this item is bound to is
   * the subject, and it is what answers however the caller spelled the
   * coordinate.
   *
   * @param string $operation
   *   The operation the answer is wanted for.
   * @param string|null $subject
   *   The id of the thing the operation is about, or NULL when the
   *   field item is its own subject.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The account to answer for, or NULL for the current user.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access answer.
   */
  public function surfaceAccess(string $operation = FieldSurfaceProviderInterface::OPERATION_FIELD_SETTINGS, ?string $subject = NULL, ?AccountInterface $account = NULL): AccessResultInterface {
    $definition = $this->getFieldDefinition();
    return $definition instanceof FieldConfigInterface
      ? $definition->access('update', $account, TRUE)
      : AccessResult::neutral();
  }

  /**
   * Gets the field config entity whose settings this item describes.
   *
   * @return \Drupal\Core\Field\FieldConfigInterface
   *   The field config entity.
   *
   * @throws \LogicException
   *   When the item's field definition is not a field config entity.
   */
  protected function settingsFieldConfig(): FieldConfigInterface {
    $definition = $this->getFieldDefinition();
    if (!$definition instanceof FieldConfigInterface) {
      throw new \LogicException(sprintf(
        'The settings of %s are not stored on a field config entity, so there is nothing for the field settings target to write to.',
        $definition->getName(),
      ));
    }
    return $definition;
  }

  /**
   * Reads the static default field settings from a class's declaration.
   *
   * The same static-protocol problem the formatter trait has:
   * defaultFieldSettings() cannot consult an instance. A field type that
   * declares its surface answers with this, because a declaration is
   * static; a field type whose surface needs services to describe itself
   * at all — a country list, a language list, a label helper — has no
   * declaration to read and keeps whatever static defaults it already
   * had.
   *
   * @param class-string $class
   *   The fully qualified field item class name.
   *
   * @return array
   *   The declared defaults keyed by setting name.
   *
   * @throws \LogicException
   *   When the class declares no surface.
   */
  protected static function surfaceDefaultFieldSettings(string $class): array {
    return static::surfaceDeclaredDefaults($class);
  }

  /**
   * {@inheritdoc}
   */
  public function fieldSettingsForm(array $form, FormStateInterface $form_state): array {
    $field = $this->settingsFieldConfig();
    $surface = $this->getFieldSurface();
    $target = $this->getDataSurfaceTarget();
    // Current values come through the target, so the form shows the
    // input shape of what is stored rather than the storage shape, and
    // an in-progress refinement rebuild overlays what was just chosen.
    $stored = array_intersect_key($target->load($surface), $surface->getDefinitions()->toArray());
    $values = array_replace($stored, $this->surfaceRefinementInput($surface, $form_state));
    $element = $this->surfaceFormBuilder()->buildSurfaceForm(
      $surface,
      $values,
      $form_state,
      'data-surface-field-settings-' . $field->getName(),
    );
    // Identifiers, not objects: the static callback rebuilds the item,
    // the surface and the target from the field this settings form is
    // for, and the form cache only ever holds strings and arrays.
    $element[static::FIELD_ELEMENT_KEY] = $field->id();
    // The stored settings ride along for the validate callback, which is
    // static and has no field item to ask. A key with no rendered
    // element must keep what is stored rather than fall back to its
    // default.
    $element[static::CURRENT_ELEMENT_KEY] = $stored;
    $element['#element_validate'] = [[static::class, 'validateSurfaceFieldSettings']];
    return $element;
  }

  /**
   * Element validate: the pipeline stage this host protocol has no hook for.
   *
   * Runs accept, validate and prepare, and leaves commit to Field UI,
   * which saves the field config entity the prepared settings are
   * copied onto.
   *
   * @param array $element
   *   The surface container element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public static function validateSurfaceFieldSettings(array $element, FormStateInterface $form_state): void {
    $item = static::surfaceFieldItem($element[static::FIELD_ELEMENT_KEY] ?? NULL, $form_state);
    $surface = $item->getFieldSurface();
    $target = $item->getDataSurfaceTarget();
    // @phpstan-ignore globalDrupalDependencyInjection.useDependencyInjection
    $builder = \Drupal::service('data_surface.form_builder');
    $current = $element[static::CURRENT_ELEMENT_KEY] ?? [];
    $values = $builder->extractSurfaceValues($surface, $element, $form_state, $current);
    if (!$builder->validateSurfaceForm($surface, $values, $element, $form_state, $current)) {
      // The values are already flagged on their own elements, and there
      // is nothing safe to hand the host, so what it finds in form state
      // stays whatever the elements produced.
      return;
    }
    try {
      // @phpstan-ignore globalDrupalDependencyInjection.useDependencyInjection
      $prepared = \Drupal::service('data_surface.pipeline')->prepare($surface, $values, $target);
    }
    catch (TargetViolationsException $e) {
      $builder->flagSurfaceErrors($e->getViolations(), $element, $form_state);
      return;
    }
    // The host copies what it finds here straight onto the field config
    // entity, so this is the storage shape, not the surface shape.
    $form_state->setValueForElement($element, $prepared->artifact);
  }

  /**
   * Rebuilds the field item the element was built from.
   *
   * The field being edited is found on the form object first. Field UI's
   * field settings form is an entity form over the field config, so the
   * object it is editing is the field itself — including the unsaved one
   * an add operation is holding, which no id can load. Only when there
   * is no such form object, which is a programmatic caller rather than
   * Field UI, is the stored id loaded.
   *
   * The item is then built straight from the field's own item
   * definition rather than from a field item list. A list goes through
   * the typed data manager's prototype cache, which is keyed by root
   * data type and property path and not by the field config object, so
   * two field configs with the same name hand back clones of the first
   * one's item — and a target built from that writes to the wrong
   * entity.
   *
   * @param mixed $field_id
   *   The field config identifier the element carries.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return \Drupal\data_surface\Form\FieldSurfaceProviderInterface
   *   The field item.
   *
   * @throws \LogicException
   *   When the field cannot be found, or when its item class does not
   *   describe its settings with a surface after all.
   */
  protected static function surfaceFieldItem(mixed $field_id, FormStateInterface $form_state): FieldSurfaceProviderInterface {
    $field = static::surfaceFormField($form_state);
    if ($field === NULL && is_string($field_id) && $field_id !== '') {
      // @phpstan-ignore globalDrupalDependencyInjection.useDependencyInjection
      $loaded = \Drupal::entityTypeManager()->getStorage('field_config')->load($field_id);
      $field = $loaded instanceof FieldConfigInterface ? $loaded : NULL;
    }
    if ($field === NULL) {
      throw new \LogicException(sprintf(
        'The %s settings element names no field this request can find, so its surface and its target cannot be rebuilt to validate it.',
        static::class,
      ));
    }
    // @phpstan-ignore globalDrupalDependencyInjection.useDependencyInjection
    $item = \Drupal::service('typed_data_manager')->create($field->getItemDefinition());
    if (!$item instanceof FieldSurfaceProviderInterface) {
      throw new \LogicException(sprintf(
        'The %s field type does not describe its settings with a surface, so %s has nothing to validate.',
        $field->getType(),
        static::class,
      ));
    }
    return $item;
  }

  /**
   * Reads the field config the host's form is editing, if it is one.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return \Drupal\Core\Field\FieldConfigInterface|null
   *   The field, or NULL when the host is not a field config form.
   */
  protected static function surfaceFormField(FormStateInterface $form_state): ?FieldConfigInterface {
    // Read through the build info rather than through getFormObject(),
    // which assumes the key is there and warns when it is not: a caller
    // replaying a submission builds its own form state and has no form
    // object at all.
    $form_object = $form_state->getBuildInfo()['callback_object'] ?? NULL;
    if (!$form_object instanceof EntityFormInterface) {
      return NULL;
    }
    $entity = $form_object->getEntity();
    return $entity instanceof FieldConfigInterface ? $entity : NULL;
  }

}
