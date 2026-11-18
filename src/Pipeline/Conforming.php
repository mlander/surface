<?php

declare(strict_types=1);

namespace Drupal\surface\Pipeline;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\surface\Contract\DefinitionSchema;
use Drupal\surface\Contract\SchemaFacts;
use Drupal\surface\Contract\OutputsDocument;
use Drupal\surface\Contract\Schemas;
use Drupal\surface\Surface;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\Validation\PortableValidator;
use PortableSchema\Builder\Schema;

/**
 * The conform verb of the pipeline: outputs held by the dialect's validator.
 *
 * What SurfacePipelineInterface::conformOutput() does.
 *
 * In order: every NotEmitted key is stripped (NotEmitted::strip()); an
 * object is refused where it stands, with `type`, since an output is a
 * plain value; a key nothing declares is refused at its own place, at any
 * depth, `unknown_key`, as accept() refuses an input key; a required output
 * that is absent is refused, `required`, "@label was not emitted.". Then
 * the values are held once to the outputs' validation document
 * (OutputsDocument) by the validator (PortableValidator::held()), each
 * `drupal:` check answered by the check provider over the output entries,
 * read as a frame of plain keys: nothing refines an output, so the place
 * of a check is found as an input key's is (Location::definitionAt()).
 *
 * What the validator answers is filed as the input path files it: by key
 * and dotted path, its code and params as the dialect gives them. A
 * `required` inside a map is filed at the property it names, in the words
 * of an absent output. An `unknownProperty` is not said, and nothing at or
 * below a refused object is.
 *
 * Nothing is coerced: an output is held as the producer gave it.
 *
 * Decision: see
 * docs/decisions.md#outputs-conform-through-the-dialects-validator, and
 * docs/decisions.md#the-pipeline-is-read-by-verb.
 *
 * @internal
 *
 * @see \Drupal\surface\Pipeline\SurfacePipeline
 * @see docs/outputs.md
 */
final class Conforming {

  use StringTranslationTrait;

  /**
   * Constructs the conform verb.
   *
   * @param \Drupal\surface\Validation\PortableValidator $validator
   *   The dialect's validator, as the pipeline holds it.
   * @param \Drupal\surface\Contract\OutputsDocument $documents
   *   The writer of the outputs' validation document.
   * @param \Drupal\surface\Pipeline\SurfacePipelineInterface $pipeline
   *   The pipeline, which the check provider is handed.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service, for the sentences a refusal says.
   */
  public function __construct(
    private readonly PortableValidator $validator,
    private readonly OutputsDocument $documents,
    private readonly SurfacePipelineInterface $pipeline,
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
   *   The violations, each with its output key, its dotted path within
   *   that key and an unrendered message.
   *
   * @throws \Throwable
   *   What a check threw, once the validation returned.
   */
  public function conformOutput(SurfaceInterface $surface, array $output): ViolationSet {
    $outputs = $surface->getOutputDefinitions();
    $output = NotEmitted::strip($output);
    $errors = [];
    // The places an object was refused at, by full dotted path.
    $objects = [];
    $data = [];
    $document = $this->documents->of($surface);
    $arrayed = $document === NULL ? [] : Schemas::arrayed($document);
    foreach ($output as $name => $value) {
      $name = (string) $name;
      $entry = $outputs->entry($name);
      if ($entry === NULL) {
        $errors[] = $this->unknown($name, '');
        continue;
      }
      $data[$name] = $this->walked($entry->schema(), $value, $name, '', $arrayed['properties'][$name] ?? NULL, $errors, $objects);
    }
    foreach ($outputs->entries() as $name => $entry) {
      $name = (string) $name;
      $schema = $entry->schema();
      if (!array_key_exists($name, $output) && SchemaFacts::isRequired($schema)) {
        // Absent, which only an output that is not required may be. NULL
        // is a value, held below like any other.
        $errors[] = new Violation($name, '', $this->notEmitted(SchemaFacts::label($schema) ?? $name), Violation::REQUIRED);
      }
    }
    if ($document === NULL || $data === []) {
      return new ViolationSet($errors);
    }
    $provider = $this->validator->provider(new Surface($outputs), $data, $this->pipeline);
    foreach ($this->validator->held($document, $data, $provider) as $found) {
      $path = $found->path;
      if ($found->code === 'unknownProperty' || self::under($path, $objects)) {
        // Said where the key stands, or about what was refused as an object.
        continue;
      }
      $property = is_string($found->params['property'] ?? NULL) ? $found->params['property'] : NULL;
      if ($found->code === Violation::REQUIRED && $property !== NULL) {
        if ($path === '') {
          // A top-level output, said above.
          continue;
        }
        $full = $path . '.' . $property;
        [$key, $below] = self::split($full);
        $schema = self::schemaAt($surface, $full);
        $errors[] = new Violation($key, $below, $this->notEmitted(($schema === NULL ? NULL : SchemaFacts::label($schema)) ?? $property), Violation::REQUIRED);
        continue;
      }
      [$key, $below] = self::split($path);
      $errors[] = new Violation($key, $below, $found->message, $found->code, params: $found->params);
    }
    return new ViolationSet($errors);
  }

  /**
   * Walks one emitted value down its schema, refusing objects and unknown keys.
   *
   * @param \PortableSchema\Builder\Schema|null $schema
   *   The value's built schema; NULL below a place no schema describes (a
   *   map with no properties, `any`), where only objects are looked for.
   * @param mixed $value
   *   The value, stripped of NotEmitted.
   * @param string $key
   *   The output key.
   * @param string $path
   *   The dotted path within it; '' at the key.
   * @param mixed $written
   *   The validation document's schema at the place, as an array, for the
   *   type a refused object is said against.
   * @param \Drupal\surface\Pipeline\Violation[] $errors
   *   The violations so far, added to.
   * @param string[] $objects
   *   The full dotted paths of the objects refused, added to.
   *
   * @return mixed
   *   The value as the validator is handed it: an object as NULL, a key
   *   nothing declares left out.
   */
  private function walked(?Schema $schema, mixed $value, string $key, string $path, mixed $written, array &$errors, array &$objects): mixed {
    if (is_object($value)) {
      $objects[] = self::full($key, $path);
      $expected = is_array($written) ? array_values(array_diff(array_map('strval', (array) ($written['type'] ?? [])), ['null'])) : [];
      $message = $expected === [] || array_intersect($expected, ['object', 'array']) !== []
        ? $this->t('This value must be a plain value, @actual given.', ['@actual' => get_debug_type($value)])
        : $this->t('This value must be of type @expected, @actual given.', [
          '@expected' => implode(' or ', $expected),
          '@actual' => 'object',
        ]);
      $params = ['expected' => $expected, 'actual' => 'object'];
      $errors[] = new Violation($key, $path, $message, Violation::TYPE_MISMATCH, params: $params);
      return NULL;
    }
    if (!is_array($value)) {
      return $value;
    }
    $written = is_array($written) ? $written : NULL;
    if ($schema !== NULL && DefinitionSchema::isList($schema)) {
      if (!array_is_list($value)) {
        // A map for a list: the validator says so, whole.
        return $this->plain($value, $key, $path, $errors, $objects);
      }
      $item = DefinitionSchema::itemOf($schema);
      $walked = [];
      foreach ($value as $delta => $one) {
        $walked[] = $this->walked($item, $one, $key, Accepting::joinPath($path, (string) $delta), $written['items'] ?? NULL, $errors, $objects);
      }
      return $walked;
    }
    $properties = $schema === NULL ? [] : SchemaFacts::properties($schema);
    if ($properties === [] || ($value !== [] && array_is_list($value))) {
      // A value no keys describe, or a list for a map, which the validator
      // says whole: only objects are looked for inside.
      return $this->plain($value, $key, $path, $errors, $objects);
    }
    $walked = [];
    foreach ($value as $name => $one) {
      $name = (string) $name;
      if (!isset($properties[$name])) {
        $errors[] = $this->unknown($key, Accepting::joinPath($path, $name));
        continue;
      }
      $walked[$name] = $this->walked($properties[$name], $one, $key, Accepting::joinPath($path, $name), $written['properties'][$name] ?? NULL, $errors, $objects);
    }
    return $walked;
  }

  /**
   * Walks a value no schema describes below, refusing each object in it.
   *
   * @param array $value
   *   The value.
   * @param string $key
   *   The output key.
   * @param string $path
   *   The dotted path of the value within it.
   * @param \Drupal\surface\Pipeline\Violation[] $errors
   *   The violations so far, added to.
   * @param string[] $objects
   *   The full dotted paths of the objects refused, added to.
   *
   * @return array
   *   The value, each object in it NULL.
   */
  private function plain(array $value, string $key, string $path, array &$errors, array &$objects): array {
    foreach ($value as $name => $one) {
      if (is_object($one) || is_array($one)) {
        $value[$name] = $this->walked(NULL, $one, $key, Accepting::joinPath($path, (string) $name), NULL, $errors, $objects);
      }
    }
    return $value;
  }

  /**
   * Refuses a key no output declares.
   *
   * @param string $key
   *   The output key, or the unknown key itself at the top.
   * @param string $path
   *   The dotted path of the unknown key within the output key; '' at the
   *   top.
   *
   * @return \Drupal\surface\Pipeline\Violation
   *   The violation.
   */
  private function unknown(string $key, string $path): Violation {
    return new Violation($key, $path, $this->t('Unknown output key @key.', [
      '@key' => self::full($key, $path),
    ]), Violation::UNKNOWN_KEY);
  }

  /**
   * Says that a required output was not emitted.
   *
   * @param mixed $label
   *   Its label, or its name.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The sentence.
   */
  private function notEmitted(mixed $label): TranslatableMarkup {
    return $this->t('@label was not emitted.', ['@label' => $label]);
  }

  /**
   * Writes the full dotted path of a place below an output key.
   *
   * @param string $key
   *   The output key.
   * @param string $path
   *   The dotted path within it; '' at the key.
   *
   * @return string
   *   The path from the top.
   */
  private static function full(string $key, string $path): string {
    return $path === '' ? $key : $key . '.' . $path;
  }

  /**
   * Splits a full dotted path into its output key and the path below it.
   *
   * @param string $full
   *   The path, from the top.
   *
   * @return array{0: string, 1: string}
   *   The key ('' for the top) and the dotted path below it.
   */
  private static function split(string $full): array {
    $parts = explode('.', $full, 2);
    return [$parts[0], $parts[1] ?? ''];
  }

  /**
   * Answers whether a dotted path is at or below one of some paths.
   *
   * @param string $path
   *   The path.
   * @param string[] $paths
   *   The paths.
   *
   * @return bool
   *   TRUE when it is.
   */
  private static function under(string $path, array $paths): bool {
    foreach ($paths as $one) {
      if ($path === $one || str_starts_with($path, $one . '.')) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Finds the built schema at a full dotted path into the outputs.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface.
   * @param string $full
   *   The path, from the top: the key, then property names and deltas.
   *
   * @return \PortableSchema\Builder\Schema|null
   *   The schema there, or NULL where none describes it.
   */
  private static function schemaAt(SurfaceInterface $surface, string $full): ?Schema {
    $segments = explode('.', $full);
    $schema = $surface->getOutputDefinitions()->entry((string) array_shift($segments))?->schema();
    foreach ($segments as $segment) {
      if ($schema === NULL) {
        return NULL;
      }
      $schema = match (TRUE) {
        DefinitionSchema::isList($schema) => ctype_digit($segment) ? DefinitionSchema::itemOf($schema) : NULL,
        DefinitionSchema::isComplex($schema) => DefinitionSchema::propertyOf($schema, $segment),
        default => NULL,
      };
    }
    return $schema;
  }

}
