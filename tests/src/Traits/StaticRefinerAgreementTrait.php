<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Traits;

use Drupal\data_surface\Contract\ContractEmitter;
use Drupal\data_surface\DataSurfaceInterface;

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
    $definitions = $surface->getDefinitions();
    // What the full schema itself is refined against: the locked keys,
    // which no value can change.
    $known = $values ?? [];
    if ($values === NULL) {
      foreach ($definitions->entries() as $name => $entry) {
        if ($entry->locked) {
          $known[$name] = $surface->getDefault((string) $name);
        }
      }
    }

    $compared = 0;
    foreach ($schema['allOf'] ?? [] as $condition) {
      foreach ($condition['then']['properties'] ?? [] as $key => $narrowed) {
        // A slot's conditional names its variant; only an enumerated
        // key's is a refiner's answer written down.
        if (($schema['properties'][$key]['x-surface']['enumerated'] ?? FALSE) !== TRUE) {
          continue;
        }
        $entry = $definitions->entry((string) $key);
        $this->assertNotNull($entry, sprintf('%s states "%s", which it does not declare.', $label, $key));
        $combination = array_map(static fn (array $value): mixed => $value['const'], $condition['if']['properties']);
        $refined = $surface->refine(array_replace($known, $combination), FALSE)->getDefinition((string) $key) ?? $entry->definition;
        $this->assertEquals(
          self::laidOver(self::decoded($emitter->describeKey($entry)), $narrowed),
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
   * Lays a conditional's `then` for a key over the key's declared schema.
   *
   * A conditional says only what changed, and a map's properties one by
   * one; every other keyword it states replaces the declared one.
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
