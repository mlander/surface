<?php

declare(strict_types=1);

namespace Drupal\surface\Pipeline;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\DefinitionTypedData;

/**
 * The conform verb of the pipeline: outputs held to their declared shape.
 *
 * What SurfacePipelineInterface::conformOutput() does: every key sent
 * as NotEmitted stripped, every key no output declares refused at any
 * depth, every leaf held to its type's PHP type with nothing coerced, and
 * then the constraints, through typed data.
 *
 * Decision: see docs/decisions.md#the-pipeline-is-read-by-verb.
 *
 * @internal
 *
 * @see \Drupal\surface\Pipeline\SurfacePipeline
 * @see docs/outputs.md
 */
final class Conforming {

  use StringTranslationTrait;

  /**
   * The data types an output is held to the PHP type of.
   */
  protected const OUTPUT_TYPES = ['integer', 'float', 'boolean', 'string', 'email', 'uri'];

  /**
   * Constructs the conform verb.
   *
   * @param \Drupal\Core\TypedData\TypedDataManagerInterface $typedDataManager
   *   The typed data manager, used to run the outputs' constraints.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service, for the sentences a refusal says.
   */
  public function __construct(
    protected readonly TypedDataManagerInterface $typedDataManager,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Checks an emitted array against what the data surface says it emits.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface whose outputs the values are held to.
   * @param array $output
   *   The emitted values, keyed by output key, NotEmitted included.
   *
   * @return \Drupal\surface\Pipeline\ViolationSet
   *   The violations, each carrying its output key, the property path
   *   within that key and an unrendered message.
   *
   * @see \Drupal\surface\Pipeline\SurfacePipelineInterface::conformOutput()
   */
  public function conformOutput(SurfaceInterface $surface, array $output): ViolationSet {
    $definitions = $surface->getOutputDefinitions();
    // NotEmitted is the producer's way of saying "this key is not emitted"
    // from inside an array literal, so it is resolved into absence
    // before anything else looks at the array.
    $output = NotEmitted::strip($output);
    $errors = [];
    foreach (array_keys(array_diff_key($output, $definitions->toArray())) as $unknown) {
      $errors[] = new Violation((string) $unknown, '', $this->t('Unknown output key @key.', [
        '@key' => $unknown,
      ]), Violation::UNKNOWN_KEY);
    }
    foreach ($definitions as $name => $definition) {
      $name = (string) $name;
      if (!array_key_exists($name, $output)) {
        // Absent, which is legal for an output that is not required.
        // NULL is not this case: NULL is a value and is held to the
        // definition below like any other.
        if ($definition->isRequired()) {
          $errors[] = new Violation($name, '', $this->t('@label was not emitted.', [
            '@label' => $definition->getLabel() ?? $name,
          ]), Violation::REQUIRED);
        }
        continue;
      }
      $holdable = TRUE;
      $errors = array_merge(
        $errors,
        $this->conformStructure($definition, $output[$name], $name, '', $holdable),
      );
      if (!$holdable) {
        // The value is not in a shape the definition can hold at all, so
        // there is nothing left for typed data to say about it that the
        // shape refusal has not already said more precisely.
        continue;
      }
      $typed_data = DefinitionTypedData::create($this->typedDataManager, $definition, $output[$name], $name);
      foreach ($typed_data->validate() as $violation) {
        $errors[] = Violation::fromConstraint($name, (string) $violation->getPropertyPath(), $violation);
      }
    }
    return new ViolationSet($errors);
  }

  /**
   * Checks an emitted value's shape and the keys it carries.
   *
   * The part of conformance typed data cannot do. Typed data runs the
   * constraints at every depth once a value is in the object, but it
   * will hold an array it was never told about — an extra property in a
   * map is simply ignored — and it turns a scalar handed to a list into
   * a one-item list, which is exactly the quiet coercion outputs are not
   * allowed. Both are found here, addressed by path, before the value is
   * handed over.
   *
   * NULL is left alone at every level: it is a value, and whether it is
   * acceptable is the definition's business, which typed data asks by
   * running the required flag as a constraint.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition the value is held to.
   * @param mixed $value
   *   The emitted value, already stripped of omitted keys.
   * @param string $key
   *   The top level output key every violation is filed under.
   * @param string $path
   *   The property path within that key, dotted; '' at the top.
   * @param bool $holdable
   *   Set to FALSE when the value is in a shape the definition cannot
   *   hold, so the caller knows not to hand it to typed data.
   *
   * @return \Drupal\surface\Pipeline\Violation[]
   *   The violations found.
   */
  protected function conformStructure(DataDefinitionInterface $definition, mixed $value, string $key, string $path, bool &$holdable): array {
    if ($value === NULL) {
      return [];
    }
    if ($definition instanceof ListDataDefinitionInterface) {
      if (!is_array($value)) {
        $holdable = FALSE;
        return [$this->outputShapeViolation($key, $path, 'list', $value)];
      }
      $errors = [];
      foreach ($value as $delta => $item) {
        $errors = array_merge($errors, $this->conformStructure(
          $definition->getItemDefinition(),
          $item,
          $key,
          Accepting::joinPath($path, (string) $delta),
          $holdable,
        ));
      }
      return $errors;
    }
    $properties = $definition instanceof ComplexDataDefinitionInterface
      ? $definition->getPropertyDefinitions()
      : [];
    if ($properties === []) {
      $mismatch = $this->outputTypeMismatch($definition, $value, $key, $path);
      return $mismatch === NULL ? [] : [$mismatch];
    }
    if (!is_array($value)) {
      $holdable = FALSE;
      return [$this->outputShapeViolation($key, $path, 'map', $value)];
    }
    $errors = [];
    foreach (array_keys(array_diff_key($value, $properties)) as $unknown) {
      $unknown_path = Accepting::joinPath($path, (string) $unknown);
      $errors[] = new Violation($key, $unknown_path, $this->t('Unknown output key @key.', [
        '@key' => $key . '.' . $unknown_path,
      ]), Violation::UNKNOWN_KEY);
    }
    foreach ($properties as $property => $property_definition) {
      if (array_key_exists($property, $value)) {
        $errors = array_merge($errors, $this->conformStructure(
          $property_definition,
          $value[$property],
          $key,
          Accepting::joinPath($path, (string) $property),
          $holdable,
        ));
      }
    }
    return $errors;
  }

  /**
   * Refuses a leaf value that is not in its definition's native type.
   *
   * Core's own PrimitiveType constraint asks whether a value *could be*
   * the type — a string of digits passes as an integer, and any scalar
   * passes as a string — because on the input side that is the right
   * question: input arrives as text and the coercion table converts it.
   * An output is produced by code with the declaration in front of it,
   * nothing converts it, and a consumer reading `integer` in the emitted
   * schema will be handed whatever the producer put there. So the
   * question here is the strict one, for the types whose PHP type is
   * plain; anything else — 'any', a contributed type — is left alone,
   * because a rule it never defined would be a guess.
   *
   * An integer where a float was declared is the one widening allowed:
   * every integer is that float exactly, so nothing is lost and no
   * consumer is surprised.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition the value is held to.
   * @param mixed $value
   *   The emitted value; never NULL, which is judged by the required
   *   flag instead.
   * @param string $key
   *   The output key.
   * @param string $path
   *   The property path within it.
   *
   * @return \Drupal\surface\Pipeline\Violation|null
   *   The violation, or NULL when the type is right or unknown here.
   */
  protected function outputTypeMismatch(DataDefinitionInterface $definition, mixed $value, string $key, string $path): ?Violation {
    $type = $definition->getDataType();
    if (!in_array($type, self::OUTPUT_TYPES, TRUE)) {
      return NULL;
    }
    $holds = match ($type) {
      'integer' => is_int($value),
      'float' => is_float($value) || is_int($value),
      'boolean' => is_bool($value),
      default => is_string($value),
    };
    return $holds ? NULL : $this->outputShapeViolation($key, $path, $type, $value);
  }

  /**
   * Builds the violation for an emitted value of the wrong shape.
   *
   * The same sentence accept() refuses a misshapen input with, because
   * it is the same mistake read from the other side, and a caller
   * reading both reports should not have to learn two wordings.
   *
   * @param string $key
   *   The output key.
   * @param string $path
   *   The property path within it.
   * @param string $expected
   *   The shape the definition can hold.
   * @param mixed $value
   *   What arrived instead.
   *
   * @return \Drupal\surface\Pipeline\Violation
   *   The violation.
   */
  protected function outputShapeViolation(string $key, string $path, string $expected, mixed $value): Violation {
    return new Violation($key, $path, $this->t('This value must be of type @expected, @actual given.', [
      '@expected' => $expected,
      '@actual' => get_debug_type($value),
    ]), Violation::TYPE_MISMATCH);
  }

}
