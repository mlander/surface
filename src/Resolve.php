<?php

declare(strict_types=1);

namespace Drupal\data_surface;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Pipeline\PipelineGuard;
use Drupal\data_surface\Pipeline\ValueState;
use Drupal\data_surface\ResourceBuild\RefinesInputRefiner;

/**
 * What a key's value is resolved from at use, and by what.
 *
 * Written on the key's definition by BlueprintAdditionsInterface::resolve()
 * and read back with of(). The key is asked and stored as declared; the
 * code that uses the resource reads it through Resources::resolved(),
 * which hands the class the stored values of the keys `from` names and
 * puts what it answers at the key. The answer is never stored, and no
 * document, tool or form states it: a resolve is a fact about use, not
 * about what is asked.
 *
 * Decision: see docs/decisions.md#a-key-may-stand-for-another-at-use.
 */
final class Resolve {

  /**
   * Constructs what a key is resolved from.
   *
   * @param array<string, string> $from
   *   Each key it is resolved from, by the name the class is handed it
   *   under (as resolve() was given it), mapped to its dotted path in the
   *   stored values: `third_party_settings.<module>.<key>` for an alter's
   *   own key.
   * @param class-string<\Drupal\data_surface\Resource\ComputesValueInterface> $using
   *   The class that resolves it.
   * @param string $by
   *   What declared it, the owner or an alter, as a refusal names it.
   */
  public function __construct(
    public readonly array $from,
    public readonly string $using,
    public readonly string $by,
  ) {
  }

  /**
   * Reads what a key is resolved from, off its definition.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface|null $definition
   *   The key's definition.
   *
   * @return static|null
   *   What it is resolved from, or NULL for a key read as stored.
   */
  public static function of(?DataDefinitionInterface $definition): ?static {
    return $definition === NULL ? NULL : DefinitionMetadata::resolveOf($definition);
  }

  /**
   * Gets the values the class is handed, or NULL when one holds nothing.
   *
   * @param array $stored
   *   The stored values of the resource's top level.
   *
   * @return array<string, mixed>|null
   *   Each key's stored value, by the name the class is handed it under;
   *   NULL when any of them holds nothing (NULL, the empty string, or no
   *   value at all), and the stored value of the key stands.
   */
  public function sources(array $stored): ?array {
    $from = [];
    foreach ($this->from as $name => $path) {
      $value = NestedArray::getValue($stored, explode('.', $path));
      if (!ValueState::isConfigured($value)) {
        return NULL;
      }
      $from[$name] = $value;
    }
    return $from;
  }

  /**
   * Puts every resolved value of a built resource over its stored values.
   *
   * The resource's own keys and the keys each alter mounted, at
   * `third_party_settings.<module>.<key>`. Each class is handed what is
   * stored, never another resolve's answer, and runs on the operation's
   * behalf: with assertions on, its own code may not ask for a
   * door-specific service.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The resource as built.
   * @param array $stored
   *   What its storage loaded for it.
   *
   * @return array
   *   The stored values, each resolved key holding what its class answered
   *   while every key it is resolved from holds a value.
   */
  public static function apply(DataSurfaceInterface $surface, array $stored): array {
    $values = $stored;
    foreach (static::declared($surface) as $path => [$resolve, $definition]) {
      $from = $resolve->sources($stored);
      if ($from === NULL) {
        continue;
      }
      $using = $resolve->using;
      $value = PipelineGuard::onBehalf(PipelineGuard::RESOLVE, $using, 'compute', static fn (): mixed => $using::compute($from, $definition));
      NestedArray::setValue($values, explode('.', (string) $path), $value, TRUE);
    }
    return $values;
  }

  /**
   * Lists the keys of a built resource that are resolved at use.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The resource as built.
   *
   * @return array<string, array{0: static, 1: \Drupal\Core\TypedData\DataDefinitionInterface}>
   *   What each is resolved from and its definition, by its dotted path.
   */
  public static function declared(DataSurfaceInterface $surface): array {
    $declared = [];
    foreach ($surface->getDefinitions()->entries() as $name => $entry) {
      if ($name === RefinesInputRefiner::MOUNT && $entry->definition instanceof ComplexDataDefinitionInterface) {
        foreach ($entry->definition->getPropertyDefinitions() as $module => $mount) {
          if (!$mount instanceof ComplexDataDefinitionInterface) {
            continue;
          }
          foreach ($mount->getPropertyDefinitions() as $key => $definition) {
            $resolve = static::of($definition);
            if ($resolve !== NULL) {
              $declared[RefinesInputRefiner::path((string) $module, (string) $key)] = [$resolve, $definition];
            }
          }
        }
        continue;
      }
      $resolve = static::of($entry->definition);
      if ($resolve !== NULL) {
        $declared[(string) $name] = [$resolve, $entry->definition];
      }
    }
    return $declared;
  }

}
