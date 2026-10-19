<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Traits;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\data_surface\Contract\ContractEmitter;
use Drupal\data_surface\Contract\SchemaResolver;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Options\DataSurfaceOptions;
use Drupal\data_surface\Refinement\DoesNotApply;
use Opis\JsonSchema\CompliantValidator;

/**
 * Holds what the served contract enumerated to what refinement answers.
 *
 * The emitter runs a key's static refiners ahead of time, once per
 * combination of the values its watched siblings can take, and writes
 * each answer down as a conditional: `if` the siblings hold these values,
 * `then` the key is its declared schema narrowed so. That is only true
 * if a static refiner is what it declares, a pure function of what it
 * watches. So, for every enumerated conditional in a data surface's
 * document, this refines the data surface afresh against the
 * conditional's values, with the static refiners alone (the ones a
 * conditional states), and asserts the key it answers is, keyword for
 * keyword, the declared key with the conditional's `then` laid over it.
 * A refiner that read anything else (storage, a counter, the time)
 * answers differently and fails here.
 *
 * A declared rule's conditional is held the other way round: it was
 * written from the rule, not from running it, so for sampled values (each
 * value its `if` names, the bounds and either side of them, nothing, and
 * a value its key would hold) refining afresh with the declared rules
 * alone must answer the key with the `then` of every conditional whose
 * `if` a standard validator finds met, tightened over the declared key,
 * and require it, or rule it out, exactly where they say.
 *
 * Both sides are described by the emitter itself
 * (ContractEmitter::describeKey()), so what is compared is what a
 * conditional says against what refinement says, not two describers.
 * Every part is held to it as well, as a document of its own for
 * any values: an attached child, each variant a slot offers, and a
 * collection's item.
 *
 * @see docs/decisions.md#static-by-default-runtime-by-declaration
 */
trait StaticRefinerAgreementTrait {

  /**
   * Asserts every enumerated conditional is what a fresh refine() answers.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The data surface.
   * @param array|null $values
   *   The values to emit the schema for, whose conditionals hold each
   *   watched sibling that has a value at it; NULL for the full schema,
   *   which reads none and enumerates every combination.
   * @param string $label
   *   The data surface's name, for messages.
   *
   * @return int
   *   How many conditionals were compared, parts included, so a
   *   caller can assert the check reached something.
   */
  protected function assertStaticRefinersAgree(DataSurfaceInterface $surface, ?array $values = NULL, string $label = 'surface'): int {
    $emitter = \Drupal::service('data_surface.contract_emitter');
    assert($emitter instanceof ContractEmitter);
    $served = $values === NULL ? $emitter->emitFull($surface, $label) : $emitter->emit($surface, $values, $label);
    $schema = self::decoded($served->document['schema']);
    if (isset($schema['unevaluatedProperties'])) {
      // A frame that mixes structures in, read flat: their members are its
      // keys, and what their defs state, the frame's.
      // Decision: see docs/decisions.md#a-mixin-is-merged-flat.
      $schema = SchemaResolver::inline($schema);
    }
    $definitions = $surface->getDefinitions();
    // What the full schema itself is refined against: the locked keys,
    // which no value can change.
    $known = $values ?? [];
    if ($values === NULL) {
      foreach ($definitions->entries() as $name => $entry) {
        if ($entry->locked && !$entry->held) {
          $known[$name] = $surface->getDefault((string) $name);
        }
      }
    }

    // What an enumerated conditional states: the coded refiners' answer in
    // the full schema, which states a declared rule apart; the static
    // ones' for one set of values, which evaluates it.
    $kinds = $values === NULL ? DataSurfaceInterface::CODED : DataSurfaceInterface::STATIC;
    // What a conditional is laid over: the key as those refiners leave it
    // while their gate is closed, as declared but for a rule already
    // evaluated for these values.
    $unanswered = $surface->refine($known, $kinds);
    $compared = 0;
    foreach ($schema['allOf'] ?? [] as $condition) {
      if (($condition['x-surface']['declared'] ?? FALSE) === TRUE) {
        // Held below, for sampled values.
        continue;
      }
      // A conditional required only where no declared rule rules the key
      // out names its combination first.
      $if = $condition['if']['allOf'][0] ?? $condition['if'];
      foreach ($condition['then']['required'] ?? [] as $key) {
        // Required here and only here: the key applies, and requires.
        $entry = $definitions->entry((string) $key);
        $this->assertNotNull($entry, sprintf('%s requires "%s", which it does not declare.', $label, $key));
        $combination = array_map(static fn (array $value): mixed => $value['const'], $if['properties']);
        $refined = $surface->refine(array_replace($known, $combination), $kinds)->getDefinition((string) $key) ?? $entry->definition;
        $this->assertTrue($refined->isRequired() && $emitter->describeKey($entry, $refined) !== FALSE, sprintf('In %s, "%s" for %s is not required where the contract says.', $label, $key, json_encode($combination)));
        $compared++;
      }
      foreach ($condition['then']['properties'] ?? [] as $key => $narrowed) {
        // A slot's conditional names its variant; only an enumerated
        // key's is a refiner's answer written down.
        if (($schema['properties'][$key]['x-surface']['enumerated'] ?? FALSE) !== TRUE || isset($narrowed['x-surface']['variant'])) {
          continue;
        }
        $entry = $definitions->entry((string) $key);
        $this->assertNotNull($entry, sprintf('%s states "%s", which it does not declare.', $label, $key));
        $combination = array_map(static fn (array $value): mixed => $value['const'], $if['properties']);
        $refined = $surface->refine(array_replace($known, $combination), $kinds)->getDefinition((string) $key) ?? $entry->definition;
        if ($narrowed === FALSE || ($entry->isNested() && !isset($schema['properties'][$key]['x-surface']['reads']))) {
          // A key ruled out is `false`; a key holding a child is stated
          // only where it does not apply, unless the child reads ports,
          // whose answers its site states inside it.
          $this->assertSame($narrowed === FALSE, $emitter->describeKey($entry, $refined) === FALSE, sprintf('In %s, "%s" for %s does not apply exactly where the contract says.', $label, $key, json_encode($combination)));
          $compared++;
          continue;
        }
        $this->assertEquals(
          self::laidOver(self::decoded($emitter->describeKey($entry, $unanswered->getDefinition((string) $key) ?? $entry->definition)), $narrowed),
          self::decoded($emitter->describeKey($entry, $refined)),
          sprintf(
            'In %s, "%s" for %s is not what the contract states for it: one of its refiners is declared static but answered differently when run again.',
            $label,
            $key,
            json_encode($combination),
          ),
        );
        $compared++;
      }
    }

    if ($values === NULL) {
      $compared += $this->assertDeclaredRulesAgree($surface, $schema, $known, $label);
    }

    foreach ($definitions->entries() as $name => $entry) {
      $children = [];
      if ($entry->attachment !== NULL) {
        $children[] = $entry->attachment->child;
      }
      foreach ($entry->slot->variants ?? [] as $variant) {
        $children[] = $variant->child;
      }
      if ($entry->list !== NULL) {
        $children[] = $entry->list->child();
      }
      foreach ($children as $child) {
        $compared += $this->assertStaticRefinersAgree($child, NULL, $label . '.' . $name);
      }
    }
    return $compared;
  }

  /**
   * Asserts every declared rule's conditional is what refinement answers.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The data surface.
   * @param array $schema
   *   Its full schema, decoded.
   * @param array $known
   *   The values the full schema is refined against: its locked keys.
   * @param string $label
   *   The data surface's name, for messages.
   *
   * @return int
   *   How many sampled values were compared.
   */
  protected function assertDeclaredRulesAgree(DataSurfaceInterface $surface, array $schema, array $known, string $label): int {
    $emitter = \Drupal::service('data_surface.contract_emitter');
    assert($emitter instanceof ContractEmitter);
    $rules = [];
    foreach ($schema['allOf'] ?? [] as $condition) {
      if (($condition['x-surface']['declared'] ?? FALSE) === TRUE) {
        $key = array_key_first($condition['then']['properties'] ?? []) ?? $condition['then']['required'][0];
        $rules[(string) $key][] = $condition;
      }
    }
    $compared = 0;
    foreach ($rules as $key => $conditions) {
      $entry = $surface->getDefinitions()->entry($key);
      $this->assertNotNull($entry, sprintf('%s states a rule for "%s", which it does not declare.', $label, $key));
      $this->assertTrue($schema['properties'][$key]['x-surface']['declared'] ?? FALSE, sprintf('In %s, "%s" has declared conditionals and is not marked declared.', $label, $key));
      // Every value a condition names, either side of every bound, nothing,
      // and a value the key would hold.
      $candidates = [];
      foreach ($conditions as $condition) {
        self::samples($condition['if'], [], $candidates);
      }
      $points = [[]];
      foreach ($candidates as $path => $samples) {
        $definition = self::definitionAt($surface, (string) $path);
        $samples = array_merge([NULL], $samples, self::held($definition));
        $samples = array_values(array_filter(
          array_unique($samples, SORT_REGULAR),
          static fn (mixed $value): bool => $value === NULL || ($definition !== NULL && \Drupal::service('data_surface.watched_value_check')->admits($definition, $value)),
        ));
        $next = [];
        foreach ($points as $point) {
          foreach ($samples as $sample) {
            $next[] = $point + [$path => $sample];
          }
        }
        $points = array_slice($next, 0, 512);
      }
      $declared = self::decoded($emitter->describeKey($entry));
      foreach ($points as $point) {
        $sampled = $known;
        foreach ($point as $path => $value) {
          NestedArray::setValue($sampled, explode('.', (string) $path), $value, TRUE);
        }
        // Only a document the pipeline could accept: no value for a key
        // that does not apply, none its own key refuses as refined.
        $standing = $surface->refine($sampled, DataSurfaceInterface::STATIC);
        foreach ($point as $path => $value) {
          $definition = self::definitionAt($standing, (string) $path);
          if ($value !== NULL && ($definition === NULL || DoesNotApply::marks($definition) || !\Drupal::service('data_surface.watched_value_check')->admits($definition, $value))) {
            continue 2;
          }
        }
        $expected = $declared;
        $ruled = FALSE;
        // With the declared rules alone, the key is required as declared
        // unless a rule requires it.
        $required = $entry->definition->isRequired();
        foreach ($conditions as $condition) {
          if (!self::met($condition['if'], $sampled)) {
            continue;
          }
          $required = $required || in_array($key, $condition['then']['required'] ?? [], TRUE);
          $then = $condition['then']['properties'][$key] ?? NULL;
          if ($then === FALSE) {
            $ruled = TRUE;
          }
          elseif (is_array($then)) {
            $expected = self::tightened($expected, $then);
          }
        }
        $refined = $surface->refine($sampled, DataSurfaceInterface::DECLARED)->getDefinition($key) ?? $entry->definition;
        $actual = $emitter->describeKey($entry, $refined);
        $at = sprintf('In %s, "%s" for %s', $label, $key, json_encode($point));
        $this->assertSame($ruled, $actual === FALSE, $at . ' does not apply where its rules say otherwise.');
        if (!$ruled) {
          $this->assertEquals($expected, self::decoded($actual), $at . ' is not what its declared conditionals state.');
          $this->assertSame($required, $refined->isRequired(), $at . ' is not required exactly where its declared conditionals say.');
        }
        $compared++;
      }
    }
    return $compared;
  }

  /**
   * Collects the values an `if` names, by the dotted path they sit at.
   *
   * @param array $schema
   *   The `if`, or a part of it.
   * @param string[] $path
   *   Where the part sits.
   * @param array<string, array> $candidates
   *   Collects the values, by path.
   */
  protected static function samples(array $schema, array $path, array &$candidates): void {
    foreach (['allOf', 'anyOf'] as $keyword) {
      foreach ($schema[$keyword] ?? [] as $part) {
        self::samples($part, $path, $candidates);
      }
    }
    if (isset($schema['not'])) {
      self::samples($schema['not'], $path, $candidates);
    }
    foreach ($schema['properties'] ?? [] as $name => $part) {
      $at = [...$path, (string) $name];
      if (isset($part['properties']) || isset($part['allOf']) || isset($part['anyOf'])) {
        self::samples($part, $at, $candidates);
        continue;
      }
      $candidates[implode('.', $at)] ??= [];
      $values = &$candidates[implode('.', $at)];
      if (array_key_exists('const', $part)) {
        $values[] = $part['const'];
      }
      array_push($values, ...($part['enum'] ?? []));
      foreach (['minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum'] as $bound) {
        if (isset($part[$bound])) {
          array_push($values, $part[$bound] - 1, $part[$bound], $part[$bound] + 1);
        }
      }
      unset($values);
    }
  }

  /**
   * Reads the definition of a key, or of a key an alter mounted.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The data surface.
   * @param string $path
   *   The key, or its dotted path.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface|null
   *   The definition.
   */
  protected static function definitionAt(DataSurfaceInterface $surface, string $path): ?DataDefinitionInterface {
    $parents = explode('.', $path);
    $definition = $surface->getDefinition((string) array_shift($parents));
    foreach ($parents as $property) {
      $definition = $definition instanceof ComplexDataDefinitionInterface ? $definition->getPropertyDefinition($property) : NULL;
    }
    return $definition;
  }

  /**
   * Gives values a key would hold: its list, its examples, or its type's.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface|null $definition
   *   The key.
   *
   * @return array
   *   A few values.
   */
  protected static function held(?DataDefinitionInterface $definition): array {
    if ($definition === NULL || $definition instanceof ListDataDefinitionInterface) {
      return [];
    }
    $options = \Drupal::service('data_surface.options');
    assert($options instanceof DataSurfaceOptions);
    $set = $options->resolve($definition);
    if ($set !== NULL) {
      return array_keys($set->options);
    }
    $examples = DefinitionMetadata::getExamples($definition);
    if ($examples !== []) {
      return $examples;
    }
    return match ($definition->getDataType()) {
      'integer' => [1, 50, 500],
      'float' => [1.5],
      'boolean' => [TRUE, FALSE],
      default => ['a'],
    };
  }

  /**
   * Answers whether a standard validator finds an `if` met.
   *
   * @param array $schema
   *   The `if`.
   * @param array $values
   *   The values, as the accepted document holds them.
   *
   * @return bool
   *   Whether they validate.
   */
  protected static function met(array $schema, array $values): bool {
    $validator = new CompliantValidator();
    $validator->parser()->setOption('defaultDraft', '2020-12');
    $document = json_decode((string) json_encode(self::asDocument($values), JSON_PRESERVE_ZERO_FRACTION));
    return $validator->validate($document, (string) json_encode($schema))->isValid();
  }

  /**
   * Marks every map an object, so it encodes as one.
   *
   * @param mixed $value
   *   A value.
   *
   * @return mixed
   *   The value with its maps as objects.
   */
  protected static function asDocument(mixed $value): mixed {
    if (!is_array($value)) {
      return $value;
    }
    $items = array_map(static fn (mixed $item): mixed => self::asDocument($item), $value);
    return $value !== [] && array_is_list($value) ? $items : (object) $items;
  }

  /**
   * Lays a declared rule's `then` over a key, as the pipeline applies it.
   *
   * A rule tightens what the key allows by the time it runs: the lower of
   * two maxima, the higher of two minima, the values both lists offer;
   * anything else it states replaces what was there, and a `required` is
   * added to. Its description is laid over only where the rest changed
   * something, or where it is all the rule says.
   *
   * @param array $base
   *   The key's schema so far.
   * @param array $then
   *   What the rule's conditional says about the key.
   *
   * @return array
   *   The key's schema under the rule.
   */
  protected static function tightened(array $base, array $then): array {
    // A rule's sentence is said only where the rest of it did something,
    // or where it is all the rule says.
    $said = array_key_exists('description', $then) ? $then['description'] : NULL;
    unset($then['description']);
    $before = $base;
    foreach ($then as $keyword => $value) {
      $base[$keyword] = match (TRUE) {
        $keyword === 'properties' && is_array($value) => (static function () use ($base, $value): array {
          $properties = $base['properties'] ?? [];
          foreach ($value as $name => $property) {
            if ($property === FALSE) {
              unset($properties[$name]);
              continue;
            }
            $properties[$name] = self::tightened($properties[$name] ?? [], $property);
          }
          return $properties;
        })(),
        $keyword === 'required' => array_values(array_unique([...($base['required'] ?? []), ...$value])),
        in_array($keyword, ['maximum', 'maxLength', 'maxItems'], TRUE) && isset($base[$keyword]) => min($base[$keyword], $value),
        in_array($keyword, ['minimum', 'minLength', 'minItems'], TRUE) && isset($base[$keyword]) => max($base[$keyword], $value),
        $keyword === 'oneOf' && isset($base['oneOf']) => array_values(array_filter($base['oneOf'], static fn (array $choice): bool => in_array($choice['const'], array_column($value, 'const'), TRUE))),
        default => $value,
      };
    }
    foreach ($then['properties'] ?? [] as $name => $property) {
      if ($property === FALSE && isset($base['required'])) {
        $base['required'] = array_values(array_diff($base['required'], [$name]));
        if ($base['required'] === []) {
          unset($base['required']);
        }
      }
    }
    if ($said !== NULL && ($then === [] || $base !== $before)) {
      $base['description'] = $said;
    }
    return $base;
  }

  /**
   * Lays a conditional's `then` for a key over the key's declared schema.
   *
   * A conditional says only what changed, and a map's properties one by
   * one, `false` for one that does not apply; every other keyword it
   * states replaces the declared one.
   *
   * @param array $base
   *   The key's declared schema.
   * @param array $narrowed
   *   What the conditional's `then` says about the key.
   *
   * @return array
   *   The schema a validator holds the key to under the conditional.
   */
  protected static function laidOver(array $base, array $narrowed): array {
    foreach ($narrowed as $keyword => $value) {
      if ($keyword === 'properties' && is_array($value)) {
        foreach ($value as $name => $property) {
          if ($property === FALSE) {
            // Ruled out here: not there, so not required either.
            unset($base['properties'][$name]);
            $base['required'] = array_values(array_diff($base['required'] ?? [], [$name]));
            if ($base['required'] === []) {
              unset($base['required']);
            }
            continue;
          }
          $base['properties'][$name] = self::laidOver($base['properties'][$name] ?? [], $property);
        }
        continue;
      }
      $base[$keyword] = $value;
    }
    return $base;
  }

  /**
   * Reads a schema the way a client does, through JSON and back.
   *
   * @param array $schema
   *   The schema, which may hold markup objects.
   *
   * @return array
   *   Plain arrays and scalars.
   */
  protected static function decoded(array $schema): array {
    return json_decode((string) json_encode($schema), TRUE);
  }

}
