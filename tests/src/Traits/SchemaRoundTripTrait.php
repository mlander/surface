<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Traits;

use Drupal\data_surface\Contract\ContractEmitter;
use Drupal\data_surface\Contract\SchemaReader;
use Drupal\data_surface\Contract\SchemaResolver;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\ResourceBuild\AlterDefinition;
use Drupal\data_surface\ResourceBuild\ResourceDefinition;
use Drupal\data_surface\ResourceBuild\ResourceRegistry;
use Drupal\data_surface\ResourceBuild\ResourcesInterface;

/**
 * Reads a full schema back into a structure, and names why one differs.
 *
 * The invariant of reading a schema (docs/reading-a-schema.md#the-round-trip):
 * the full schema of a declaration, read by SchemaReader into a
 * structure and built in the same context, emits that document again,
 * byte for byte. What cannot is one of a few known kinds, each with its
 * reason (KINDS); kindOf() names the kind from the document and the
 * declaration, so a test reports a document that differs as that kind,
 * and fails one that differs for no known reason.
 *
 * @see docs/decisions.md#a-schema-can-be-read-back-into-a-structure
 */
trait SchemaRoundTripTrait {

  /**
   * Why a document does not round-trip, by kind.
   *
   * @return array<string, string>
   *   The reason, by kind.
   */
  protected static function roundTripKinds(): array {
    return [
      'coded' => 'A coded refiner: what it answers for each value it could be handed is read back as a declared rule, the same conditional with its index under the frame\'s `rules` and no `enumerated` on the key; what it could not list (`refine`) is not read at all. The documents differ in those markers alone.',
      'coded otherwise' => 'A coded refiner whose answers, read back as declared rules, are spelled otherwise: a rule ruling a key out guards the rules requiring it, a rule stated at a site moves into the child and is stated in the child\'s order, a refiner answering a whole child is no narrowing, and a refiner on a port read at a site leaves nothing to read.',
      'port' => 'A rule on a port, of a structure emitted alone: it is stated where a container wires the port, and alone there is none, so only the key\'s markers say it.',
      'rule' => 'A declared rule that narrows nothing the key does not already say: no conditional states it, so only the key\'s `dependsOn` says it, and nothing is read back from that.',
      'access' => 'An access answer (a lock, a whole locked, a key a runtime answer marks `live`) is the access class\'s, not the structure\'s.',
      'alter' => 'An alter\'s mount: its keys, the rules reading them and the structures it attaches there are the alter\'s; read back, the mount is a map or a child of the owner\'s, its rules a JsonSchema constraint on the frame.',
      'site' => 'Keys from the site: the entries are a provider\'s, so an entry it locks, and the ports each entry reads, are not the document\'s to say; read back, the rules on those ports are a JsonSchema constraint on the frame.',
      'server' => 'A constraint no keyword states (a composite, or one of a module that is not on), which only the server checks: the document names it under `checkedOnServer`, and a name is no constraint the reader can declare.',
      'computed' => 'A computed key: what computes it is a class, which no document names, so read back it is a key nobody types that holds what is stored, `readOnly`, with no `x-surface.computed`.',
      'live' => 'A runtime refiner: its rule is a lookup no document states, so the full schema marks the key `live` and nothing can read the rule back.',
      'site list' => 'A list the site decides, past the site list limit: the full schema states the key open and `deferred`, with no def per variant or entry, so read back it is an open map, which the provider\'s list is not.',
    ];
  }

  /**
   * Reads one full schema back, builds what it read, and emits it again.
   *
   * @param array $schema
   *   The full schema, as emitted (`document['schema']`).
   * @param string $id
   *   The declaration's id.
   * @param string $module
   *   Its module.
   * @param string $context
   *   The context it was emitted in.
   * @param string|null $operation
   *   The operation, or NULL for the declaration alone.
   * @param \Stringable|string|null $label
   *   The operation's label.
   * @param array $facts
   *   The operation's facts.
   * @param \Drupal\data_surface\DataSurfaceInterface|null $declared
   *   The declaration as the document was emitted from it, to tell a key
   *   `live` for a runtime refiner from one `live` for a runtime access
   *   answer; NULL builds it for the account alone in a context of that
   *   id.
   *
   * @return array{verdict: string, again: array|null, error: string|null}
   *   `same`, `differs`, `live` (a runtime refiner's, not read back) or
   *   `error`; the schema read back; and what was raised, for `error`.
   */
  protected function readBack(array $schema, string $id, string $module, string $context, ?string $operation, \Stringable|string|null $label, array $facts, ?DataSurfaceInterface $declared = NULL): array {
    $resources = \Drupal::service('data_surface.resources');
    assert($resources instanceof ResourcesInterface);
    if (str_contains((string) json_encode($schema), '"live":true')) {
      // `live` marks a runtime refiner and a runtime access answer alike;
      // only the first is a rule no document states. A key marked for its
      // access is read back, and differs as an access answer.
      try {
        $declared ??= $resources->buildStatic(\Drupal::service('data_surface.resource_registry')->getDefinition($id)->name(), new OperationContext($context));
      }
      catch (\Throwable) {
        $declared = NULL;
      }
      if ($declared === NULL || static::refinedAtRuntime($declared)) {
        return ['verdict' => 'live', 'again' => NULL, 'error' => NULL];
      }
    }
    $reader = \Drupal::service('data_surface.schema_reader');
    assert($reader instanceof SchemaReader);
    $emitter = \Drupal::service('data_surface.contract_emitter');
    assert($emitter instanceof ContractEmitter);
    try {
      $read = $reader->structure($schema, $id, $module);
      $again = $emitter->emitFull($resources->buildStatic($read, new OperationContext($context)), $id, $operation, $label, operation: $facts)->document['schema'];
    }
    catch (\Throwable $e) {
      return [
        'verdict' => 'error',
        'again' => NULL,
        'error' => get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(),
      ];
    }
    return [
      'verdict' => static::encoded($again) === static::encoded($schema) ? 'same' : 'differs',
      'again' => $again,
      'error' => NULL,
    ];
  }

  /**
   * Names the known kind a document that does not round-trip is of.
   *
   * Read from what the document marks, what reading it back added, and
   * what the declaration is, in an order where the first that holds is
   * the reason: a computed key; a constraint only the server checks; an
   * access answer (a `live` mark, which readBack() reads back only where
   * no runtime refiner set it, or a key read only where the declaration
   * or a structure it holds answers access); an alter that refines, or
   * whose rules read back as a constraint on the frame; keys from the
   * site a provider locks (a key read only, no access answering it); a
   * coded refiner (`coded` when the documents differ in its markers
   * alone); a port; a declared rule no conditional states (the documents
   * differ in `dependsOn` alone).
   *
   * @param array $original
   *   The full schema as emitted.
   * @param array|null $again
   *   What was emitted from it read back.
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $definition
   *   The declaration.
   *
   * @return string|null
   *   A kind of roundTripKinds(), or NULL for a difference no known kind
   *   explains.
   */
  protected static function kindOf(array $original, ?array $again, ResourceDefinition $definition): ?string {
    $marks = [];
    static::marks(SchemaResolver::restored(json_decode((string) json_encode($original), TRUE)), $marks);
    $added = [];
    static::marks($again === NULL ? [] : SchemaResolver::restored(json_decode((string) json_encode($again), TRUE)), $added);
    $added = array_diff_key($added, $marks);
    $registry = \Drupal::service('data_surface.resource_registry');
    assert($registry instanceof ResourceRegistry);
    $answering = $definition->answersAccess();
    foreach (array_keys($original['$defs'] ?? []) as $def) {
      $answering = $answering || ($registry->hasDefinition((string) $def) && $registry->getDefinition((string) $def)->answersAccess());
    }
    $refining = array_filter($definition->alters, static fn (AlterDefinition $alter): bool => $alter->refiners !== []);
    return match (TRUE) {
      static::pastTheLimit($original) => 'site list',
      isset($marks['computed']) => 'computed',
      isset($marks['checkedOnServer']) => 'server',
      isset($marks['live']) || (isset($marks['@readOnly']) && $answering) => 'access',
      $definition->alters !== [] && (isset($added['checkedOnServer']) || $refining !== []) => 'alter',
      isset($marks['@readOnly']) => 'site',
      isset($marks['enumerated']) || isset($marks['refine']) => $again !== NULL && static::encoded(static::withoutMarkers($original)) === static::encoded(static::withoutMarkers($again)) ? 'coded' : 'coded otherwise',
      isset($marks['ports']) => 'port',
      $again !== NULL && static::encoded(static::withoutMarkers($original, ['dependsOn'])) === static::encoded(static::withoutMarkers($again, ['dependsOn'])) => 'rule',
      default => NULL,
    };
  }

  /**
   * Answers whether a schema states a list the site decides open.
   *
   * @param mixed $schema
   *   A schema, or anything inside one.
   *
   * @return bool
   *   TRUE where a key is `deferred` and open: variants with their
   *   deciding key and no union, or keys from the site with their entries.
   *
   * @see docs/decisions.md#a-list-left-out-for-size-is-deferred-not-refined
   */
  protected static function pastTheLimit(mixed $schema): bool {
    if (!is_array($schema)) {
      return FALSE;
    }
    $extension = $schema[ContractEmitter::EXTENSION] ?? NULL;
    if (is_array($extension) && is_array($extension['deferred'] ?? NULL) && (isset($extension['deferred']['entries']) || (isset($extension['by']) && !isset($schema['anyOf'])))) {
      return TRUE;
    }
    foreach ($schema as $value) {
      if (static::pastTheLimit($value)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Collects what a schema marks, at any depth.
   *
   * @param mixed $schema
   *   A schema, or anything inside one.
   * @param array<string, true> $marks
   *   Collects each `x-surface` key, and `@readOnly` for a key read only.
   */
  protected static function marks(mixed $schema, array &$marks): void {
    if (!is_array($schema)) {
      return;
    }
    foreach ($schema as $keyword => $value) {
      if ($keyword === ContractEmitter::EXTENSION && is_array($value)) {
        foreach (array_keys($value) as $mark) {
          if ($value[$mark] !== FALSE && $value[$mark] !== [] && $value[$mark] !== NULL) {
            $marks[(string) $mark] = TRUE;
          }
        }
      }
      if ($keyword === 'readOnly' && $value === TRUE) {
        $marks['@readOnly'] = TRUE;
      }
      static::marks($value, $marks);
    }
  }

  /**
   * Strips the markers a coded refiner's rules are told apart by.
   *
   * @param mixed $schema
   *   A schema, or anything inside one.
   * @param string[] $markers
   *   The markers to strip, by default those of a coded refiner.
   *
   * @return mixed
   *   The same, without those markers (by default `enumerated`,
   *   `declared`, `refine` and `rules`) under any `x-surface`.
   */
  protected static function withoutMarkers(mixed $schema, array $markers = ['enumerated', 'declared', 'refine', 'rules']): mixed {
    if (!is_array($schema)) {
      return $schema;
    }
    foreach ($schema as $keyword => $value) {
      if ($keyword === ContractEmitter::EXTENSION && is_array($value)) {
        $value = array_diff_key($value, array_flip($markers));
        if ($value === []) {
          unset($schema[$keyword]);
          continue;
        }
        $schema[$keyword] = $value;
        continue;
      }
      $schema[$keyword] = static::withoutMarkers($value, $markers);
    }
    return $schema;
  }

  /**
   * Answers whether a runtime refiner narrows any key of a declaration.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The declaration, built.
   * @param array<int, true> $seen
   *   The data surfaces walked already.
   *
   * @return bool
   *   TRUE where one does, at any depth: a child, a variant, a
   *   collection's item, a structure an alter mounted.
   */
  protected static function refinedAtRuntime(DataSurfaceInterface $surface, array &$seen = []): bool {
    if (isset($seen[spl_object_id($surface)])) {
      return FALSE;
    }
    $seen[spl_object_id($surface)] = TRUE;
    foreach ($surface->getDefinitions()->entries() as $entry) {
      $children = [
        ...($entry->attachment === NULL ? [] : [$entry->attachment->child]),
        ...array_map(static fn ($variant): DataSurfaceInterface => $variant->child, $entry->variants->variants ?? []),
        ...($entry->list === NULL ? [] : [$entry->list->child()]),
        ...array_map(static fn ($mounted): DataSurfaceInterface => $mounted->child, $entry->mounted),
      ];
      if ($entry->isRefinedAtRuntime() || array_filter($children, static fn (DataSurfaceInterface $child): bool => static::refinedAtRuntime($child, $seen)) !== []) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Encodes a schema as the round trip compares it.
   *
   * @param mixed $schema
   *   The schema.
   *
   * @return string
   *   Its JSON.
   */
  protected static function encoded(mixed $schema): string {
    return (string) json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
  }

}
