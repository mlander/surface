<?php

declare(strict_types=1);

namespace Drupal\surface\Refinement;

use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\surface\Contract\DefinitionSchema;
use Drupal\surface\Contract\NodeFacts;
use Drupal\surface\Contract\PortableKeywords;
use Drupal\surface\DefinitionMetadata;
use PortableSchema\Builder\Schema;

/**
 * Reads what an old-shape refiner changed as an answer.
 *
 * A #[RefinesInput] method that takes the key's definition and returns it
 * tightened still runs, until it is converted to return an answer: the
 * engine hands it a definition, holds what it returned to the narrowing
 * check (Narrowing::assertNarrows()), and reads the difference from the
 * key's schema here, which Answer::apply() then applies. So every
 * refiner's answer is applied one way, whichever shape wrote it.
 *
 * What the difference is said as:
 * - a definition marked as not applying: DoesNotApply::here(), or `false`
 *   at a property or an item;
 * - another label or description: `title`, `description`;
 * - required where the key is not: `required()`;
 * - the key's list of values, narrowed: `enum` where the values stand in
 *   the key's order with the key's labels, `options()` where they do not;
 * - any other constraint that changed or was added: the check
 *   `drupal:<id>` with the options as the definition holds them (a bound
 *   among them, so its own options and messages are carried);
 * - inside a map or a list: `properties` and `items`, each the difference
 *   at its place.
 * What no answer says (a constraint removed, another data type, another
 * default) is left out; the narrowing check has refused what would widen,
 * and the log names the rest.
 *
 * When the environment variable SURFACE_ANSWER_LOG names a file, one line
 * is appended for each answer that needed `options()` or that carries a
 * title, a description or a bound, and for each change no answer says:
 * the source, the key and what it needed, by tabs. That list is what the
 * conversion of the old-shape methods reads.
 *
 * Deleted once every #[RefinesInput] method returns an answer.
 *
 * @internal
 */
final class AnswerFromDefinition {

  /**
   * The environment variable naming the file the log is appended to.
   */
  public const LOG = 'SURFACE_ANSWER_LOG';

  /**
   * Reads the difference a refiner made as an answer.
   *
   * @param \PortableSchema\Builder\Schema $key
   *   The key's schema, as it stood before the refiner ran.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $returned
   *   The definition the refiner returned.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface|null $handed
   *   The definition it was handed, as it was before the call, whose
   *   default and examples are compared; NULL to compare none.
   * @param string|null $source
   *   The refiner, for the log; NULL to log nothing.
   * @param string $name
   *   The key, for the log.
   *
   * @return \PortableSchema\Builder\Schema|\Drupal\surface\Refinement\DoesNotApply
   *   The answer: what the returned definition adds to the key.
   */
  public static function between(Schema $key, DataDefinitionInterface $returned, ?DataDefinitionInterface $handed = NULL, ?string $source = NULL, string $name = ''): Schema|DoesNotApply {
    $log = [];
    $answer = static::difference($key, $returned, $handed, $log);
    if ($source !== NULL && $log !== []) {
      static::log($source, $name, $log);
    }
    return Answer::rulesOut($answer) ? DoesNotApply::here() : $answer;
  }

  /**
   * Reads the difference at one place.
   *
   * @param \PortableSchema\Builder\Schema $key
   *   The schema there, before.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $returned
   *   The definition there, as returned.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface|null $handed
   *   The definition there, as handed; NULL where not known.
   * @param string[] $log
   *   What the log says of the answer; gains what this place needed.
   *
   * @return \PortableSchema\Builder\Schema
   *   The answer there; `false` for a place ruled out.
   */
  protected static function difference(Schema $key, DataDefinitionInterface $returned, ?DataDefinitionInterface $handed, array &$log): Schema {
    if (DoesNotApply::marks($returned)) {
      return NodeFacts::doesNotApply($key) ? Schema::any() : Schema::boolean(FALSE);
    }
    $answer = Schema::any();
    $model = DefinitionSchema::model($key);
    foreach (['title' => [$returned->getLabel(), 'label'], 'description' => [$returned->getDescription(), 'description']] as $text => [$now, $member]) {
      $was = $key->has($text) ? $key->get($text) : ($model[$member] ?? NULL);
      if ($now === $was) {
        continue;
      }
      $log[] = $now === NULL ? $text . ' removed' : $text;
      if ($now !== NULL) {
        $answer = $answer->with($text, $now);
      }
    }
    $required = $returned->isRequired() && !NodeFacts::isRequired($key);
    if ($required) {
      $answer = $answer->required();
    }
    if ($returned->getDataType() !== NodeFacts::dataType($key)) {
      $log[] = 'type';
    }
    $held = NodeFacts::constraints($key);
    $set = ChoiceSet::fromConstraints($held);
    $checks = [];
    $constraints = $returned->getConstraints();
    foreach ($constraints as $id => $options) {
      $id = (string) $id;
      if (array_key_exists($id, $held) && $held[$id] === $options) {
        continue;
      }
      if ($required && static::followsRequired($id, $options, $held)) {
        continue;
      }
      if ($set !== NULL && $id === $set->constraint) {
        $answer = static::listed($answer, $set, ChoiceSet::fromOptions($id, (array) $options), $log);
        continue;
      }
      if (in_array($id, Narrowing::COMPARATORS, TRUE) || $id === 'Regex') {
        $log[] = 'bound';
      }
      $checks[PortableKeywords::PROVIDER . $id] = $options;
    }
    if (array_diff_key($held, $constraints) !== []) {
      $log[] = 'removed';
    }
    if ($checks !== []) {
      $answer = $answer->with(PortableKeywords::CHECK, $checks);
    }
    if ($handed !== NULL && static::metadata($handed) !== static::metadata($returned)) {
      $log[] = 'default';
    }
    if ($returned instanceof ListDataDefinitionInterface) {
      $item = DefinitionSchema::isList($key) ? DefinitionSchema::itemNode($key) : NULL;
      if ($item !== NULL) {
        $inside = static::difference($item, $returned->getItemDefinition(), $handed instanceof ListDataDefinitionInterface ? $handed->getItemDefinition() : NULL, $log);
        if (!Answer::isNothing($inside)) {
          $answer = $answer->with('items', $inside);
        }
      }
    }
    elseif ($returned instanceof ComplexDataDefinitionInterface && is_array($key->get('properties'))) {
      $properties = [];
      foreach ($returned->getPropertyDefinitions() as $property => $definition) {
        $property = (string) $property;
        $schema = DefinitionSchema::propertyNode($key, $property);
        if ($schema === NULL) {
          $log[] = 'property added';
          continue;
        }
        $before = $handed instanceof ComplexDataDefinitionInterface ? $handed->getPropertyDefinition($property) : NULL;
        $inside = static::difference($schema, $definition, $before, $log);
        if (!Answer::isNothing($inside)) {
          $properties[$property] = $inside;
        }
      }
      if ($properties !== []) {
        $answer = $answer->with('properties', $properties);
      }
    }
    return $answer;
  }

  /**
   * Answers whether a constraint changed only because the key is required.
   *
   * @param string $id
   *   The constraint plugin id.
   * @param mixed $options
   *   Its options, as returned.
   * @param array $held
   *   The key's constraints, before.
   *
   * @return bool
   *   TRUE for the NotNull a required key gains, and the `allowNull` a
   *   NotBlank gains beside it.
   */
  protected static function followsRequired(string $id, mixed $options, array $held): bool {
    if ($id === 'NotNull') {
      return !array_key_exists('NotNull', $held);
    }
    if ($id !== 'NotBlank' || !array_key_exists('NotBlank', $held)) {
      return FALSE;
    }
    $expected = (array) $held['NotBlank'];
    $expected['allowNull'] = TRUE;
    return $expected === $options;
  }

  /**
   * Says a narrowed list as `enum` or `options()`.
   *
   * @param \PortableSchema\Builder\Schema $answer
   *   The answer so far.
   * @param \Drupal\surface\Refinement\ChoiceSet $key
   *   The key's list.
   * @param \Drupal\surface\Refinement\ChoiceSet $returned
   *   The list returned, under the same constraint.
   * @param string[] $log
   *   Gains `options` where the list needed it.
   *
   * @return \PortableSchema\Builder\Schema
   *   The answer with the list.
   */
  protected static function listed(Schema $answer, ChoiceSet $key, ChoiceSet $returned, array &$log): Schema {
    $values = $returned->values;
    $ordered = array_values(array_filter($key->values, static fn (mixed $value): bool => in_array($value, $values, TRUE))) === $values;
    $labeled = $key->constraint !== 'LabeledChoice'
      || array_intersect_key($key->labels, array_flip($values)) === array_intersect_key($returned->labels, array_flip($values));
    if ($ordered && $labeled) {
      return $answer->with('enum', $values);
    }
    $log[] = 'options';
    $branches = [];
    foreach ($values as $value) {
      $branch = Schema::const($value);
      $label = $key->constraint === 'LabeledChoice' && (is_int($value) || is_string($value)) ? $returned->labels[$value] ?? NULL : NULL;
      $branches[] = $label === NULL ? $branch : $branch->with('title', $label);
    }
    return $answer->with('oneOf', $branches);
  }

  /**
   * Gives a definition's default and examples, for a comparison.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   *
   * @return array
   *   Whether it has a default, the default, and its examples.
   */
  protected static function metadata(DataDefinitionInterface $definition): array {
    $default = DefinitionMetadata::canHoldDefaultValue($definition) && DefinitionMetadata::hasDefaultValue($definition);
    return [
      $default,
      $default ? DefinitionMetadata::getDefaultValue($definition) : NULL,
      DefinitionMetadata::canHoldExamples($definition) ? DefinitionMetadata::getExamples($definition) : [],
    ];
  }

  /**
   * Appends one line to the file SURFACE_ANSWER_LOG names.
   *
   * @param string $source
   *   The refiner.
   * @param string $name
   *   The key.
   * @param string[] $log
   *   What the answer needed.
   */
  protected static function log(string $source, string $name, array $log): void {
    $file = getenv(self::LOG);
    if (!is_string($file) || $file === '') {
      return;
    }
    file_put_contents($file, sprintf("%s\t%s\t%s\n", $source, $name, implode(',', array_values(array_unique($log)))), FILE_APPEND | LOCK_EX);
  }

}
