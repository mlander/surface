<?php

declare(strict_types=1);

namespace Drupal\surface\ResourceBuild;

use Drupal\surface\Attachment;
use Drupal\surface\Mixin;

/**
 * The mixing phase of the build: structures mixed in.
 *
 * Step 5 of Resources: each structure mixed in, its ports' wiring
 * checked, built by itself for its def and asked its access, which holds
 * its members to what is stored or takes them back out; then what of the
 * owner's names a member it may not depend on is refused. What it
 * answers is bound in the owner's frame by the binding phase
 * (Binding::bindMixin()).
 *
 * A structure mixed in is built by itself through the whole sequence
 * again, so this builds through the attach phase
 * (Attaching::buildResource()).
 *
 * Decision: see docs/decisions.md#the-build-is-read-by-phase.
 *
 * @internal
 *
 * @see \Drupal\surface\ResourceBuild\Resources
 */
final class Mixing {

  /**
   * Constructs the mixing phase.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceRegistry $registry
   *   What discovery found.
   * @param \Drupal\surface\ResourceBuild\Gating $gating
   *   The access phase, which answers for each structure mixed in.
   * @param \Drupal\surface\ResourceBuild\Attaching $attaching
   *   The attach phase, which builds each by itself.
   */
  public function __construct(
    protected readonly ResourceRegistry $registry,
    protected readonly Gating $gating,
    protected readonly Attaching $attaching,
  ) {
  }

  /**
   * Builds each structure mixed in by itself, and settles its members.
   *
   * A mixed-in structure's members are the owner's keys already, declared
   * through its blueprint where mixIn() stands. Here each is checked (its
   * ports wired to the owner's keys), built by itself in the owner's
   * context for its def and asked its access: omitted takes its members
   * back out, locked holds them to what is stored, and what a structure
   * it mixes in itself answered inside it is read off its own build. A
   * member that holds a child is the owner's child at its key, built with
   * the owner's children (Attaching::attachChildren(),
   * Attaching::attachLists()): omitted, it goes with the other members;
   * locked, it is locked.
   *
   * Decision: see docs/decisions.md#a-mixin-is-merged-flat.
   *
   * @param \Drupal\surface\ResourceBuild\Build $build
   *   The owner's build: its builder, the owner, its context, its frame
   *   (with the permission its operation asks), and its input keys, every
   *   structure's members among them, the keys holding a child or
   *   variants, and its collection keys.
   * @param \Drupal\surface\ResourceBuild\ResourceBlueprint $inputs
   *   The owner's input blueprint.
   *
   * @return array{bind: array<int, array{definition: \Drupal\surface\ResourceBuild\ResourceDefinition, blueprint: \Drupal\surface\ResourceBuild\ResourceBlueprint, reads: array<string, string>, keys: string[], attached: string[], lists: string[]}>, members: array<string, string>, guarded: array<string, string>, answeredBy: array<string, string>, ruling: string[]}
   *   What to bind in the owner's frame, innermost first, each with the
   *   members still there; every member, and every member of a structure
   *   with an access answer (an access class, or an alter's), each mapped
   *   to its structure's id, with what answers for each such structure;
   *   and the members a structure's coded refiner may rule out.
   */
  public function mixIn(Build $build, ResourceBlueprint $inputs): array {
    $builder = $build->builder;
    $context = $build->context;
    $frame = $build->frame;
    $mixed = ['bind' => [], 'members' => [], 'guarded' => [], 'answeredBy' => [], 'ruling' => []];
    foreach ($inputs->mixins() as ['class' => $class, 'reads' => $reads, 'blueprint' => $blueprint]) {
      $structure = $this->registry->getDefinition($class);
      $this->assertMixinWiring($build, $structure, $blueprint, $reads);
      $mixed = static::mixedMembers($mixed, $blueprint, $structure, fn (string $name): ResourceDefinition => $this->registry->getDefinition($name));
      // By itself, as if on its own: what its def is written from, in the
      // context its owner's hands it, and what its access answers there.
      $child_context = Resources::childContext($context, $structure->id);
      [$access, $runtime] = $this->gating->childAccess($structure, NULL, $child_context, $this->gating->account(), $frame['permission'] ?? NULL);
      if ($access !== NULL) {
        $builder->addCacheableDependency($access);
      }
      $children = [...array_keys($blueprint->attachments()), ...array_keys($blueprint->lists())];
      if ($access !== NULL && $access->isOmitted()) {
        foreach ($blueprint->declarationOrder() as $key) {
          $builder->withdraw($key);
        }
        continue;
      }
      $built = $this->attaching->buildResource($structure->name(), $child_context, []);
      $map = $built->getDefinitions();
      $locked = $access !== NULL && $access->isLocked();
      $present = [];
      foreach ($blueprint->declarationOrder() as $key) {
        if (in_array($key, $children, TRUE)) {
          // The owner's child, asked its own access where the owner
          // builds it; locked with the structure.
          if ($locked) {
            $builder->lock($key, $access->getReason());
          }
          if ($runtime) {
            $builder->markRuntimeAccess($key);
          }
          $present[] = $key;
          continue;
        }
        if (!$map->has($key)) {
          // A structure it mixes in itself, which that one's access left
          // out inside it.
          $builder->withdraw($key);
          continue;
        }
        if ($locked || $map->isHeld($key)) {
          $builder->hold($key);
        }
        // Decided once the subject is known, here or by a structure it
        // mixes in itself: marked, built for the account alone.
        if ($runtime || $map->isRuntimeAccess($key)) {
          $builder->markRuntimeAccess($key);
        }
        $present[] = $key;
      }
      $builder->addMixin(new Mixin(new Attachment($built, $structure->name(), $structure->id, label: $structure->label(), description: $structure->description()), $present, $reads));
      array_push($mixed['bind'], ...$this->mixinBindings($structure, $blueprint, $reads, $present));
    }
    return $mixed;
  }

  /**
   * Lists a structure's members, and those its access class may take away.
   *
   * @param array $mixed
   *   What mixIn() collects so far.
   * @param \Drupal\surface\ResourceBuild\ResourceBlueprint $blueprint
   *   The blueprint the structure was declared through.
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $structure
   *   The structure.
   * @param callable(string): \Drupal\surface\ResourceBuild\ResourceDefinition $definitions
   *   Reads a structure it mixes in itself.
   *
   * @return array
   *   The same, with this structure's members and those of every
   *   structure it mixes in.
   */
  protected static function mixedMembers(array $mixed, ResourceBlueprint $blueprint, ResourceDefinition $structure, callable $definitions): array {
    foreach ($blueprint->mixins() as ['class' => $class, 'blueprint' => $inner]) {
      $mixed = static::mixedMembers($mixed, $inner, $definitions($class), $definitions);
    }
    foreach ($blueprint->declarationOrder() as $key) {
      $mixed['members'][$key] ??= $structure->id;
      if ($structure->answersAccess()) {
        $mixed['guarded'][$key] ??= $structure->id;
        $mixed['answeredBy'][$structure->id] = $structure->access !== NULL ? 'an access class' : 'an alter answering its access';
      }
    }
    foreach ($structure->refiners as $refiner) {
      if ($refiner->mayNotApply) {
        $mixed['ruling'][] = $refiner->key;
      }
    }
    return $mixed;
  }

  /**
   * Lists what to bind in the owner's frame for one structure mixed in.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $structure
   *   The structure.
   * @param \Drupal\surface\ResourceBuild\ResourceBlueprint $blueprint
   *   The blueprint it was declared through.
   * @param array<string, string> $reads
   *   Its ports, wired to the owner's keys.
   * @param string[] $present
   *   The members still there.
   *
   * @return array<int, array{definition: \Drupal\surface\ResourceBuild\ResourceDefinition, blueprint: \Drupal\surface\ResourceBuild\ResourceBlueprint, reads: array<string, string>, keys: string[], attached: string[], lists: string[]}>
   *   A structure it mixes in itself first, unless its members are gone,
   *   then this one: its plain members still there, and those holding a
   *   child at a fixed key or as a collection, which its refiners and
   *   rules may only rule out.
   */
  protected function mixinBindings(ResourceDefinition $structure, ResourceBlueprint $blueprint, array $reads, array $present): array {
    $bindings = [];
    foreach ($blueprint->mixins() as ['class' => $class, 'reads' => $inner_reads, 'blueprint' => $inner]) {
      if (array_diff($inner->declarationOrder(), $present) !== []) {
        continue;
      }
      // Its ports are wired to keys of the structure mixing it in, which
      // are the owner's keys now, under the same names.
      array_push($bindings, ...$this->mixinBindings($this->registry->getDefinition($class), $inner, $inner_reads, $present));
    }
    $holding = [...array_keys($blueprint->attachments()), ...array_keys($blueprint->variants())];
    $attached = array_values(array_intersect(array_map('strval', $holding), $present));
    $lists = array_values(array_intersect(array_map('strval', array_keys($blueprint->lists())), $present));
    $bindings[] = [
      'definition' => $structure,
      'blueprint' => $blueprint,
      'reads' => $reads,
      'keys' => array_values(array_intersect($blueprint->keys(), $present)),
      'attached' => $attached,
      'lists' => $lists,
    ];
    return $bindings;
  }

  /**
   * Refuses a wiring of a mixed-in structure's ports its owner cannot honor.
   *
   * Every port is wired, to a plain input of the owner's of the port's
   * type that the structure did not bring itself: in the owner's frame a
   * port is read as the key it is wired to, so one left unwired would
   * have nothing to be read as.
   *
   * Decision: see docs/decisions.md#a-mixin-is-merged-flat.
   *
   * @param \Drupal\surface\ResourceBuild\Build $build
   *   The owner's build: its builder, the owner, its plain input keys,
   *   the keys holding a child or variants, and its collection keys.
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $structure
   *   The structure.
   * @param \Drupal\surface\ResourceBuild\ResourceBlueprint $blueprint
   *   The blueprint it was declared through.
   * @param array<string, string> $reads
   *   The wiring.
   *
   * @throws \LogicException
   *   Naming the owner, the port and what is wrong with the wiring.
   */
  protected function assertMixinWiring(Build $build, ResourceDefinition $structure, ResourceBlueprint $blueprint, array $reads): void {
    $builder = $build->builder;
    $definition = $build->definition;
    $input_keys = $build->inputKeys;
    $attached = $build->attached;
    $lists = $build->lists;
    $ports = $blueprint->ports();
    foreach ($reads as $port => $wired) {
      if (!isset($ports[$port])) {
        throw new \LogicException(sprintf('%s wires the "%s" port of %s, mixed in, which %s does not read; it reads %s.', $definition->id, $port, $structure->id, $structure->id, $ports === [] ? 'no port' : implode(', ', array_keys($ports))));
      }
      $problem = match (TRUE) {
        in_array($wired, $blueprint->keys(), TRUE) => sprintf('which %s brought itself: a port reads a key of the owner it is mixed into, and its members are its own', $structure->id),
        in_array($wired, $attached, TRUE), in_array($wired, $lists, TRUE) => 'which holds a child: a port reads one value',
        !in_array($wired, $input_keys, TRUE) => sprintf('which %s does not declare as an input: a port reads a key of its owner, by name', $definition->id),
        default => NULL,
      };
      if ($problem !== NULL) {
        throw new \LogicException(sprintf('%s wires the "%s" port of %s, mixed in, to "%s", %s.', $definition->id, $port, $structure->id, $wired, $problem));
      }
      $type = $builder->getDefinition($wired)?->getDataType();
      if ($ports[$port]->type !== 'any' && $type !== $ports[$port]->type) {
        throw new \LogicException(sprintf('%s wires the "%s" port of %s, mixed in, which reads %s %s, to "%s", which holds %s %s.', $definition->id, $port, $structure->id, Attaching::article((string) $ports[$port]->type), $ports[$port]->type, $wired, Attaching::article((string) $type), $type));
      }
      $unshaped = Attaching::unshaped($ports[$port], $builder->getDefinition($wired));
      if ($unshaped !== NULL) {
        throw new \LogicException(sprintf('%s wires the "%s" port of %s, mixed in, to "%s", %s.', $definition->id, $port, $structure->id, $wired, $unshaped));
      }
    }
    foreach ($ports as $port) {
      if (!isset($reads[$port->name])) {
        throw new \LogicException(sprintf(
          '%s mixes %s in without wiring its "%s" port: mixIn(..., reads: [\'%s\' => \'<key>\']) names the key of %s it reads. A mixed-in structure\'s refiners read each port as the owner\'s key it is wired to, so every port is wired, required or not.',
          $definition->id,
          $structure->id,
          $port->name,
          $port->name,
          $definition->id,
        ));
      }
    }
  }

  /**
   * Refuses what of the owner's names a member it may not depend on.
   *
   * A mixed-in structure's members are the owner's keys, so the owner's
   * refiners and rules, and its alters', may watch them and narrow them
   * as their own, with two exceptions. Whether a member applies is the
   * structure's to say: its def, which every owner shares, says where it
   * is required. And nothing of the owner's may name a member of a
   * structure that has an access class: what that access may take away
   * for one account, nothing may depend on for every account.
   *
   * Decision: see docs/decisions.md#a-mixin-is-merged-flat.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $definition
   *   The owner.
   * @param array $mixins
   *   What mixIn() answered.
   * @param array<int, array{0: object|string, 1: \Drupal\surface\ResourceBuild\RefinerDefinition[], 2: string|null}> $links
   *   The owner's refiners and its alters'.
   * @param \Drupal\surface\Refinement\Rule[] $rules
   *   The owner's rules and its alters'.
   * @param array<string, array<string, string>> $wirings
   *   The ports of each child the owner or an alter attached, wired.
   *
   * @throws \LogicException
   *   Naming what names the member, and the structure.
   */
  public function assertMixinsUnnamed(ResourceDefinition $definition, array $mixins, array $links, array $rules, array $wirings): void {
    $guarded = $mixins['guarded'];
    $guard = static fn (string $what, string $key): \LogicException => new \LogicException(sprintf(
      '%s names "%s", a member of %s, which is mixed into %s and has %s. What that access may take away for one account, nothing of the owner\'s may depend on: read it inside %s, or drop %s.',
      $what,
      $key,
      $guarded[$key],
      $definition->id,
      $mixins['answeredBy'][$guarded[$key]] ?? 'an access class',
      $guarded[$key],
      ($mixins['answeredBy'][$guarded[$key]] ?? 'an access class') === 'an access class' ? 'the access class' : 'the alter\'s access',
    ));
    $ruled = static fn (string $what, string $key): \LogicException => new \LogicException(sprintf(
      '%s may answer that "%s", a member of %s mixed into %s, does not apply. Whether a mixed-in structure\'s member applies is the structure\'s to say: its def, which every owner shares, says where it is required.',
      $what,
      $key,
      $mixins['members'][$key],
      $definition->id,
    ));
    foreach ($links as [, $refiners]) {
      foreach ($refiners as $refiner) {
        foreach ([$refiner->key, ...$refiner->watched()] as $key) {
          if (isset($guarded[$key])) {
            throw $guard($refiner->describe(), $key);
          }
        }
        if ($refiner->mayNotApply && isset($mixins['members'][$refiner->key])) {
          throw $ruled($refiner->describe(), $refiner->key);
        }
      }
    }
    foreach ($rules as $rule) {
      $name = sprintf('narrow("%s") in %s', $rule->key, $definition->id);
      foreach ([$rule->key, ...($rule->condition()?->keys() ?? [])] as $key) {
        if (isset($guarded[$key])) {
          throw $guard($name, $key);
        }
      }
      if ($rule->rulesOut() && isset($mixins['members'][$rule->key])) {
        throw $ruled($name, $rule->key);
      }
    }
    foreach ($wirings as $at => $reads) {
      foreach ($reads as $port => $key) {
        if (isset($guarded[$key])) {
          throw $guard(sprintf('The "%s" port of the child at "%s"', $port, $at), $key);
        }
      }
    }
  }

}
