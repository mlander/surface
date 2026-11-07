<?php

declare(strict_types=1);

namespace Drupal\surface\Refinement;

use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\surface\SurfaceRefinerInterface;
use Drupal\surface\ResourceBuild\RefinesInputRefiner;

/**
 * The engine's refiner for the rules one contributor declared as data.
 *
 * One of these per contributor that declared a rule (the owner, then
 * each alter's module), bound in the owner's chain of every key a rule
 * narrows, after that contributor's coded refiners. Asked to refine one
 * key, it evaluates each rule's condition on the values it reads and
 * applies the effects of each that holds, in declaration order; once one
 * rules the key out, nothing after it runs.
 *
 * It is static by construction: a condition and its effects are data, a
 * pure function of the values read, which the served contract states as
 * conditionals without running anything. It is never gated. A condition
 * reads a key holding nothing as nothing, which `absent` is about, so a
 * rule runs whatever its keys hold, and whatever the key's other
 * refiners are still waiting for: the engine hands it every key a rule
 * reads, as it stands. A watched value its own key refuses is handed over
 * as nothing, as to any refiner.
 *
 * An alter's rule on a key it mounted is bound under the mount, as its
 * coded refiners are, and narrows its own property inside it; a condition
 * key the alter mounted is read at its dotted path.
 *
 * A structure's rule that reads a port is stated where the port is wired,
 * the site: there the emitter reads the rule through one of these bound
 * at the key the structure sits at, $at naming the structure's key below
 * it and $paths each condition key's path in the container's frame.
 *
 * Decision: see docs/decisions.md#a-rule-declared-as-data.
 *
 * @internal
 */
final class DeclaredRefiner implements SurfaceRefinerInterface, ApplicabilityInterface {

  /**
   * Constructs a DeclaredRefiner.
   *
   * @param array<string, \Drupal\surface\Refinement\Rule[]> $rules
   *   The rules, keyed by the key they narrow; an alter's rules on its own
   *   mounted keys under RefinesInputRefiner::MOUNT.
   * @param string|null $module
   *   The alter's module, whose property inside the mount the rules bound
   *   under the mount narrow; NULL for the owner's.
   * @param array<string, string> $paths
   *   The condition keys the alter mounted, each mapped to the dotted path
   *   its value is read at.
   * @param string|null $at
   *   For rules stated at a site, the dotted path below the key they are
   *   bound at of the key they narrow; NULL for rules bound where they
   *   were declared.
   */
  public function __construct(
    public readonly array $rules,
    public readonly ?string $module = NULL,
    public readonly array $paths = [],
    public readonly ?string $at = NULL,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function refineDataDefinition(string $name, DataDefinitionInterface $definition, array $values): DataDefinitionInterface {
    if ($this->at !== NULL) {
      return $this->refineAt(explode('.', $this->at), $definition, $this->rules[$name] ?? [], $values);
    }
    if ($name === RefinesInputRefiner::MOUNT && $this->module !== NULL) {
      $own = $definition instanceof ComplexDataDefinitionInterface ? $definition->getPropertyDefinition($this->module) : NULL;
      if (!$own instanceof MapDataDefinition) {
        return $definition;
      }
      foreach ($this->rules[$name] ?? [] as $rule) {
        $key = $own->getPropertyDefinition($rule->key);
        if ($key !== NULL && !DoesNotApply::marks($key) && $this->holds($rule, $values)) {
          $own->setPropertyDefinition($rule->key, $rule->apply($key));
        }
      }
      return $definition;
    }
    foreach ($this->rules[$name] ?? [] as $rule) {
      if (DoesNotApply::marks($definition)) {
        break;
      }
      if ($this->holds($rule, $values)) {
        $definition = $rule->apply($definition);
      }
    }
    return $definition;
  }

  /**
   * Applies the rules that hold to the key at a path inside a map.
   *
   * @param string[] $parents
   *   The path below the definition.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition, a copy.
   * @param \Drupal\surface\Refinement\Rule[] $rules
   *   The rules.
   * @param array $values
   *   The values the engine handed over.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface
   *   The definition.
   */
  protected function refineAt(array $parents, DataDefinitionInterface $definition, array $rules, array $values): DataDefinitionInterface {
    if ($parents === []) {
      foreach ($rules as $rule) {
        if (!DoesNotApply::marks($definition) && $this->holds($rule, $values)) {
          $definition = $rule->apply($definition);
        }
      }
      return $definition;
    }
    $first = (string) array_shift($parents);
    $inner = $definition instanceof MapDataDefinition ? $definition->getPropertyDefinition($first) : NULL;
    if ($definition instanceof MapDataDefinition && $inner !== NULL && !DoesNotApply::marks($inner)) {
      $definition->setPropertyDefinition($first, $this->refineAt($parents, $inner, $rules, $values));
    }
    return $definition;
  }

  /**
   * {@inheritdoc}
   */
  public function mayNotApply(string $name): array {
    return array_values(array_unique(array_map(
      static fn (array $bound): string => $bound[0],
      array_filter($this->rulesFor($name), static fn (array $bound): bool => $bound[1]->rulesOut()),
    )));
  }

  /**
   * Lists the rules bound on one key, with where each narrows inside it.
   *
   * @param string $name
   *   The key; the mount for an alter's rules on the keys it added.
   *
   * @return array<int, array{0: string, 1: \Drupal\surface\Refinement\Rule}>
   *   Each rule in order, beside '' for the key itself or, for a key an
   *   alter mounted, `<module>.<key>`, its path below the mount.
   */
  public function rulesFor(string $name): array {
    $mounted = $name === RefinesInputRefiner::MOUNT && $this->module !== NULL;
    return array_map(
      fn (Rule $rule): array => [$this->at ?? ($mounted ? $this->module . '.' . $rule->key : ''), $rule],
      $this->rules[$name] ?? [],
    );
  }

  /**
   * Spells where a condition key's value is, in the frame.
   *
   * @param string $key
   *   The key, by the name the rule gives it.
   *
   * @return string[]
   *   The segments of its path: the key itself, or a mounted key's
   *   `third_party_settings`, module and key.
   */
  public function pathOf(string $key): array {
    return explode('.', $this->paths[$key] ?? $key);
  }

  /**
   * Evaluates a rule's condition on the values the engine handed over.
   *
   * @param \Drupal\surface\Refinement\Rule $rule
   *   The rule.
   * @param array $values
   *   The values, keyed by key, a mounted one by its dotted path.
   *
   * @return bool
   *   Whether the condition holds.
   */
  protected function holds(Rule $rule, array $values): bool {
    $condition = $rule->condition();
    if ($condition === NULL) {
      return FALSE;
    }
    $read = [];
    foreach ($condition->keys() as $key) {
      $read[$key] = $values[$this->paths[$key] ?? $key] ?? NULL;
    }
    return $condition->holds($read);
  }

}
