<?php

declare(strict_types=1);

namespace Drupal\surface\ResourceBuild;

use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\surface\Surface;
use Drupal\surface\SurfaceBuilder;
use Drupal\surface\SurfaceBuilderInterface;
use Drupal\surface\Refinement\ChoiceSet;
use Drupal\surface\Attachment;
use Drupal\surface\Mixin;
use Drupal\surface\MixinVariants;
use Drupal\surface\Refinement\Condition;
use Drupal\surface\Refinement\Rule;
use Drupal\surface\Reader\LeafRules;

/**
 * The mixing phase of the build: structures and variants mixed in.
 *
 * Step 5 of Resources: each structure mixed in, its ports' wiring
 * checked, built by itself for its def and asked its access, which holds
 * its members to what is stored or takes them back out; each variant
 * mixed in the same way, its members declared where its call stands once
 * the owner's declaration ran (layVariantMixins(), from step 1), its
 * value offered on the deciding key and each member given the rules that
 * say where it applies; then what of the owner's names a member it may
 * not depend on is refused. What it answers is bound in the owner's frame
 * by the binding phase (Binding::bindMixin()).
 *
 * A structure or a variant mixed in is built by itself through the whole
 * sequence again, so this builds through the attach phase
 * (Attaching::buildResource(), ::buildRead()) and finds a call's variants
 * as that phase finds the variants at a key (Attaching::findVariants()).
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
   * @param \Drupal\surface\ResourceBuild\Declaring $declaring
   *   The declaring phase, for the blueprint a variant mixed in declares.
   * @param \Drupal\surface\ResourceBuild\Gating $gating
   *   The access phase, which answers for each structure and each variant
   *   mixed in.
   * @param \Drupal\surface\ResourceBuild\Attaching $attaching
   *   The attach phase, which builds each by itself and finds the variants
   *   a call mixes in.
   */
  public function __construct(
    protected readonly ResourceRegistry $registry,
    protected readonly Declaring $declaring,
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
   * Declares the members of each call's variants where mixInVariants() stands.
   *
   * Run once the owner's declaration is done, before its alters, the site's
   * defaults and the context are read, so every member is the owner's key
   * by then. The variants are found as attachVariants() finds them
   * (findVariants()), each declared by itself for its keys, and its
   * members laid at the place the call held, in variant order then key
   * order: a member two variants declare alike once, required nowhere in
   * the owner's frame, since it is required only where its variant is
   * chosen. Nothing is lazy: the members exist in the frame before any
   * value is known, so a variant the site lists is read now, not when a
   * door reads it (VariantsToSettle is for nested variants alone).
   *
   * Decision: see docs/decisions.md#a-mixin-may-be-chosen-by-a-sibling-key.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $definition
   *   The owner.
   * @param \Drupal\surface\ResourceBuild\ResourceBlueprint $inputs
   *   The owner's input blueprint, its declaration run.
   * @param \Drupal\surface\SurfaceBuilderInterface $builder
   *   The owner's builder.
   *
   * @throws \LogicException
   *   When the deciding key is not a plain input of the owner, or holds
   *   nested variants too; when a variant is a resource, a shape, has
   *   outputs, reads a port, holds keys from the site or variants mixed in
   *   of its own, or wires a child's ports; or when a member is a key the
   *   owner, a structure mixed in or another variant declares, or one two
   *   variants declare unlike where one holds variants at it
   *   (declareMembers()).
   */
  public function layVariantMixins(ResourceDefinition $definition, ResourceBlueprint $inputs, SurfaceBuilderInterface $builder): void {
    // Who declared each key laid so far: by call, value and variant id.
    $laid = [];
    foreach ($inputs->variantMixins() as $call => $mixed) {
      ['by' => $by, 'from' => $from, 'arguments' => $arguments, 'read' => $read] = $mixed;
      if (isset($inputs->variants()[$by])) {
        $classes = array_values($this->registry->getVariants($definition->name(), $by));
        throw new \LogicException(sprintf(
          'In %s, "%s" holds variants (attachVariants()) and chooses variants mixed in (mixInVariants()), and a #[Variant(of:, at: \'%s\')] names one of the two: %s. The variants at a key and the variants a key mixes in are found by the same `at`, so one name is one of them: rename the key the variants sit at, or the deciding key.',
          $definition->id,
          $by,
          $by,
          $classes === [] ? 'none is declared yet' : implode(', ', $classes) . ' would be a variant of both',
        ));
      }
      if (!in_array($by, $inputs->keys(), TRUE)) {
        throw new \LogicException(sprintf(
          'In %s, the variants mixed in are chosen by "%s", which it never declares as a plain input: variants are chosen by a sibling that holds one value.',
          $definition->id,
          $by,
        ));
      }
      $deciding = $builder->getDefinition($by);
      if ($deciding instanceof ListDataDefinitionInterface || $deciding instanceof ComplexDataDefinitionInterface) {
        throw new \LogicException(sprintf('The variants mixed in by "%s" are chosen by "%s", which is a %s: a deciding key holds one value.', $by, $by, $deciding->getDataType()));
      }
      ['children' => $children, 'labels' => $labels] = $this->attaching->findVariants($definition, $inputs, $builder, $by, $read, $from, $arguments);
      $blueprints = [];
      foreach ($children as $value => $child) {
        $blueprints[(string) $value] = $this->variantBlueprint($definition, $by, (string) $value, $child);
      }
      [$members, $values, $required, $held, $widened] = $this->declareMembers($definition, $inputs, $builder, $by, $blueprints, $laid);
      $inputs->layVariantMixin($call, $members, [
        'children' => array_map('strval', $children),
        'labels' => $labels + $this->attaching->variantLabels($children),
        'blueprints' => $blueprints,
        'values' => $values,
        'required' => $required,
        'attachments' => $held['attachments'],
        'lists' => $held['lists'],
        'variants' => $held['variants'],
        'widened' => $widened,
      ]);
      foreach ($values as $key => $for) {
        $laid[$key] = [$by, $for[0], $this->registry->getDefinition($children[$for[0]])->id];
      }
    }
  }

  /**
   * Declares one variant mixed in by itself, refusing what cannot be one.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $definition
   *   The owner.
   * @param string $by
   *   The deciding key.
   * @param string $value
   *   The value the variant is for.
   * @param string $child
   *   The variant, by name.
   *
   * @return \Drupal\surface\ResourceBuild\ResourceBlueprint
   *   The blueprint it declared its keys through.
   *
   * @throws \LogicException
   *   For a resource with storage or identity, a shape, one with outputs,
   *   one that reads a port, one that holds keys from the site or variants
   *   mixed in of its own, or one that wires a child's ports.
   */
  protected function variantBlueprint(ResourceDefinition $definition, string $by, string $value, string $child): ResourceBlueprint {
    $refuse = static fn (string $why): \LogicException => new \LogicException(sprintf('%s mixes in the variants chosen by "%s", and the variant for "%s" %s', $definition->id, $by, $value, $why));
    if (Shapes::isShape($child)) {
      throw $refuse(sprintf('is %s, a shape: a shape is one value, declared at a key; a variant mixed in is a structure whose members are the owner\'s keys.', $child));
    }
    $variant = $this->registry->getDefinition($child);
    if ($variant->storage !== NULL || $variant->identity !== []) {
      throw $refuse(sprintf('is %s, which names %s. A variant\'s members are the owner\'s keys, stored by the owner: mix in a structure, or attach a resource under a key of its own.', $variant->id, $variant->storage !== NULL ? 'storage of its own' : 'identity keys'));
    }
    if ($variant->hasOutputs()) {
      throw $refuse(sprintf('is %s, which declares outputs. A variant\'s inputs are mixed in; its outputs are not, yet.', $variant->id));
    }
    $blueprint = $this->declaring->blueprint(new SurfaceBuilder(), $variant);
    $this->declaring->fillBlueprints($variant, $blueprint);
    $this->declaring->settleReferences($variant->id, ['' => $blueprint]);
    // A child at a fixed key or a collection is the owner's child at its
    // key, and variants at a key the owner's variants there, chosen by a
    // member that is the owner's key; keys from the site are found by the
    // frame that holds them, which a variant mixed in is not.
    // Decision: see docs/decisions.md#a-mixin-may-be-chosen-by-a-sibling-key.
    $refused_here = array_map(static fn (string|int $key): string => sprintf('keys from the site at "%s"', $key), array_keys($blueprint->fromSite()));
    if ($refused_here !== [] || $blueprint->variantMixins() !== []) {
      throw $refuse(sprintf(
        'is %s, which holds %s. A variant mixed in holds keys, maps, children at a fixed key or in a collection, and variants at a key, not keys from the site or variants mixed in of its own, for now: attach the variants under a key of their own with attachVariants() instead.',
        $variant->id,
        $refused_here[0] ?? 'variants mixed in of its own',
      ));
    }
    foreach (array_keys($blueprint->attachments()) as $key) {
      $wired = $blueprint->wiring((string) $key);
      if ($wired !== []) {
        throw $refuse(sprintf(
          'is %s, which attaches %s at "%s" and wires its "%s" port. A child a variant mixed in brings is the owner\'s child at that key, and nothing wires its ports, for now: attach it with no reads:.',
          $variant->id,
          $this->registry->getDefinition((string) $blueprint->attachments()[$key])->id,
          $key,
          (string) array_key_first($wired),
        ));
      }
    }
    if ($blueprint->ports() !== []) {
      throw $refuse(sprintf(
        'is %s, which reads the "%s" port. Nothing wires a variant mixed in: any module may add one, so no wiring the owner writes could name its ports.',
        $variant->id,
        (string) array_key_first($blueprint->ports()),
      ));
    }
    return $blueprint;
  }

  /**
   * Declares every variant's members at the place its call held.
   *
   * In variant order, then each variant's declaration order, a member
   * holding a child or variants among them. A member two variants declare
   * alike is one key, which applies when either is chosen and wears the
   * label and description of the first variant to declare it. One they
   * declare unlike is one key too, declared as wide as every variant's
   * definition (widest()), and held to the chosen variant's own where it
   * is chosen (chosenRules()); a child that is not alike in every variant
   * is such a member, and no child of the owner's. Refused: a key the
   * owner, a structure mixed in or another call's variant declares, and
   * variants at a key that are not alike in every variant that holds
   * them. Variants at a key are alike where the same deciding key chooses
   * the same structures by the same values, found as the variant built
   * alone finds them (findVariants()), which the first variant to declare
   * them finds for the owner.
   *
   * Decision: see docs/decisions.md#a-mixin-may-be-chosen-by-a-sibling-key.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $definition
   *   The owner.
   * @param \Drupal\surface\ResourceBuild\ResourceBlueprint $inputs
   *   The owner's blueprint.
   * @param \Drupal\surface\SurfaceBuilderInterface $builder
   *   The owner's builder, holding its keys and those laid so far.
   * @param string $by
   *   The deciding key.
   * @param array<string, \Drupal\surface\ResourceBuild\ResourceBlueprint> $blueprints
   *   Each variant's blueprint, by value.
   * @param array<string, array{0: string, 1: string, 2: string}> $laid
   *   The members another call laid, each with its deciding key, its first
   *   value and that variant's id.
   *
   * @return array{0: array<string, \Drupal\Core\TypedData\DataDefinitionInterface>, 1: array<string, string[]>, 2: array<string, string[]>, 3: array{attachments: array<string, string>, lists: array<string, string>, variants: array<string, array{by: string, from: class-string<\Drupal\surface\Resource\SiteListInterface>|null, arguments: list<string|int>, read: array<string, array{name: string, label: string|null}>, declaredBy: string}>}, 4: string[]}
   *   Each member's definition in the owner's frame, by key, in order;
   *   the values each member applies under; the values under which each
   *   plain member is required; the members holding a child, at a fixed
   *   key or as a collection, each by the child's name, and those holding
   *   variants, each as the variant that declared it holds them; and the
   *   members held to the chosen variant's definition.
   *
   * @throws \LogicException
   *   Naming the owner, the variants and the key.
   */
  protected function declareMembers(ResourceDefinition $definition, ResourceBlueprint $inputs, SurfaceBuilderInterface $builder, string $by, array $blueprints, array $laid): array {
    $values = [];
    $held = ['attachments' => [], 'lists' => [], 'variants' => []];
    // Each member's declarations, by value: the definition, the child or
    // the variants it holds, and the blueprint that declared it.
    $declarations = [];
    $mixedBy = [];
    foreach ($inputs->mixins() as ['class' => $class, 'blueprint' => $mixin]) {
      foreach ($mixin->declarationOrder() as $key) {
        $mixedBy[$key] ??= $this->registry->getDefinition($class)->id;
      }
    }
    foreach ($blueprints as $value => $blueprint) {
      $variant = (string) $blueprint->ownerId();
      $attachments = $blueprint->attachments();
      $lists = $blueprint->lists();
      $nested = $blueprint->variants();
      foreach ($blueprint->declarationOrder() as $key) {
        $declared = $blueprint->definitionOf($key);
        assert($declared !== NULL);
        // What a member holding a child holds, by kind and child; for
        // variants, the structures each value chooses.
        $child = match (TRUE) {
          isset($attachments[$key]) => ['attachments', (string) $attachments[$key]],
          isset($lists[$key]) => ['lists', (string) $lists[$key]],
          isset($nested[$key]) => ['variants', $this->nestedVariants($blueprint, $builder, $key)],
          default => NULL,
        };
        if (!isset($declarations[$key]) && $builder->getDefinition($key) !== NULL) {
          throw new \LogicException(sprintf(
            '%s mixes in the variants chosen by "%s": the variant for "%s", %s, declares "%s", which %s declares too. A variant\'s members are the owner\'s keys, so a name is declared once: rename one, or attach the variants under a key of their own with attachVariants().',
            $definition->id,
            $by,
            $value,
            $variant,
            $key,
            match (TRUE) {
              isset($laid[$key]) => sprintf('the variant for "%s", %s, of the variants chosen by "%s",', $laid[$key][1], $laid[$key][2], $laid[$key][0]),
              isset($mixedBy[$key]) => sprintf('%s, mixed in,', $mixedBy[$key]),
              default => $definition->id,
            },
          ));
        }
        $declarations[$key][(string) $value] = [$declared, $child, $blueprint];
        $values[$key][] = (string) $value;
      }
    }
    $members = [];
    $required = [];
    $widened = [];
    foreach ($declarations as $key => $declared_by) {
      $first = (string) array_key_first($declared_by);
      [$declared, $child, $blueprint] = $declared_by[$first];
      $alike = TRUE;
      foreach ($declared_by as $value => [$other, $other_child]) {
        if ($other_child !== $child || !static::alike($other, $declared)) {
          $alike = FALSE;
          if (($child !== NULL && $child[0] === 'variants') || ($other_child !== NULL && $other_child[0] === 'variants')) {
            throw new \LogicException(sprintf(
              '%s mixes in the variants chosen by "%s": the variant for "%s", %s, and the variant for "%s", %s, both declare "%s", and not alike, where one holds variants at it. Variants a variant mixed in holds at a key are the owner\'s variants there, so every variant that declares the key holds the same variants by the same deciding key: declare them alike, or rename one.',
              $definition->id,
              $by,
              $first,
              $blueprint->ownerId(),
              $value,
              $declared_by[$value][2]->ownerId(),
              $key,
            ));
          }
        }
      }
      $requiring = array_keys(array_filter($declared_by, static fn (array $one): bool => $one[0]->isRequired()));
      if (!$alike) {
        // As wide as every variant's, held to the chosen one's by a rule.
        // A child against a value or another child is no shape the frame
        // can hold: any value.
        $holds_child = array_filter($declared_by, static fn (array $one): bool => $one[1] !== NULL) !== [];
        $members[$key] = static::widest(array_map(static fn (array $one): DataDefinitionInterface => $one[0], $declared_by), $holds_child);
        $widened[] = (string) $key;
        if ($requiring !== []) {
          $required[$key] = array_map('strval', $requiring);
        }
        continue;
      }
      $member = Surface::deepClone($declared);
      // Required only where its variant is chosen: a rule says where.
      if ($member instanceof DataDefinition) {
        $member->setRequired(FALSE);
      }
      $members[$key] = $member;
      if ($child !== NULL && $child[0] === 'variants') {
        // The owner's variants at the key, chosen by the variant's member,
        // found as the variant that declared them finds them.
        $held['variants'][$key] = [
          'by' => $blueprint->variants()[$key],
          'from' => $blueprint->variantsFrom()[$key] ?? NULL,
          'arguments' => $blueprint->variantsArguments()[$key] ?? [],
          'read' => $blueprint->readVariants()[$key] ?? [],
          'declaredBy' => $blueprint->ownerName() ?? (string) $blueprint->ownerId(),
        ];
      }
      elseif ($child !== NULL) {
        // The owner's child at the key, built with its own children.
        $held[$child[0]][$key] = $child[1];
      }
      elseif ($requiring !== []) {
        $required[$key] = array_map('strval', $requiring);
      }
    }
    return [$members, $values, $required, $held, $widened];
  }

  /**
   * Gives the widest definition every variant's of one member fits.
   *
   * Of one class and one data type, and for a map or a list alike inside
   * (its keys, its item): that type, with the constraints, the settings
   * and every other entry of its definition (the default among them)
   * that every variant declares identically; what any of them declares
   * otherwise is no part of it, so a default that differs is none. Two
   * config schema leaf types that typed data reads as one data type, such
   * as `label` and `string`, are that type here. Anything else (a list
   * against a map, a map against a value, an integer against a string, a
   * child against a value, two children of different structures) is a
   * value of any shape, open, as a reader declares a key it reads no
   * shape for (LeafRules::open()). The label and the description are the
   * first variant's.
   *
   * Decision: see docs/decisions.md#a-mixin-may-be-chosen-by-a-sibling-key.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface[] $definitions
   *   Each variant's definition of the member, the first first.
   * @param bool $child
   *   Whether one of them holds a child, which makes it open.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface
   *   The definition in the owner's frame, optional.
   */
  protected static function widest(array $definitions, bool $child = FALSE): DataDefinitionInterface {
    $first = reset($definitions);
    assert($first instanceof DataDefinitionInterface);
    $same = !$child;
    foreach ($definitions as $other) {
      $shape = static::withoutPresentation($other);
      $mine = static::withoutPresentation($first);
      unset($shape['own'], $mine['own']);
      if ($other::class !== $first::class || $other->getDataType() !== $first->getDataType() || serialize($shape) !== serialize($mine)) {
        $same = FALSE;
      }
    }
    if (!$same || !$first instanceof DataDefinition) {
      $open = LeafRules::open($first->getLabel());
      if ($first->getDescription() !== NULL) {
        $open->setDescription($first->getDescription());
      }
      return $open;
    }
    $widest = Surface::deepClone($first);
    assert($widest instanceof DataDefinition);
    // Each of one class with the first, so each a DataDefinition.
    $arrays = array_map(static fn (DataDefinitionInterface $one): array => $one instanceof DataDefinition ? $one->toArray() : [], $definitions);
    $shared = static function (array $entries) use ($arrays): array {
      $kept = [];
      foreach ($entries as $name => $entry) {
        foreach ($arrays as $array) {
          if (!array_key_exists($name, $array) || serialize($array[$name]) !== serialize($entry)) {
            continue 2;
          }
        }
        $kept[$name] = $entry;
      }
      return $kept;
    };
    $own = $arrays[array_key_first($arrays)];
    $widest->setConstraints(array_filter(
      $own['constraints'] ?? [],
      static fn (mixed $options, string|int $name): bool => array_filter($arrays, static fn (array $array): bool => !isset($array['constraints'][$name]) || serialize($array['constraints'][$name]) !== serialize($options)) === [],
      ARRAY_FILTER_USE_BOTH,
    ));
    $widest->setSettings(array_filter(
      $own['settings'] ?? [],
      static fn (mixed $setting, string|int $name): bool => array_filter($arrays, static fn (array $array): bool => !array_key_exists($name, $array['settings'] ?? []) || serialize($array['settings'][$name]) !== serialize($setting)) === [],
      ARRAY_FILTER_USE_BOTH,
    ));
    $said = ['type', 'label', 'description', 'required', 'constraints', 'settings'];
    $rest = array_diff_key($own, array_flip($said));
    $kept = $shared($rest);
    foreach (array_keys($rest) as $name) {
      if (!array_key_exists($name, $kept)) {
        $widest->offsetUnset($name);
      }
    }
    $widest->setRequired(FALSE);
    return $widest;
  }

  /**
   * Says what variants a variant mixed in holds at a key, to tell two alike.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceBlueprint $blueprint
   *   The variant's blueprint.
   * @param \Drupal\surface\SurfaceBuilderInterface $builder
   *   The owner's builder, which what a provider read reaches.
   * @param string $key
   *   The key.
   *
   * @return string
   *   The deciding key, and each value's structure and label.
   */
  protected function nestedVariants(ResourceBlueprint $blueprint, SurfaceBuilderInterface $builder, string $key): string {
    $declarer = $this->registry->getDefinition($blueprint->ownerName() ?? $blueprint->ownerId());
    ['children' => $children, 'labels' => $labels] = $this->attaching->findVariants($declarer, $blueprint, $builder, $key, $blueprint->readVariants()[$key] ?? [], $blueprint->variantsFrom()[$key] ?? NULL, $blueprint->variantsArguments()[$key] ?? []);
    return serialize([$blueprint->variants()[$key], $children, array_map('strval', $labels)]);
  }

  /**
   * Answers whether two variants declare a member alike.
   *
   * Alike is the same definition but for what presents it and whether it
   * is required, which a variant says for itself: the type, the
   * constraints, the settings (the default among them), and a map's or a
   * list's keys inside, alike in turn. The label and the description are
   * presentation, at any depth: the owner's key wears the first variant's,
   * and each variant's def its own.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $one
   *   One variant's.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $other
   *   The other's.
   *
   * @return bool
   *   TRUE when they are alike.
   */
  protected static function alike(DataDefinitionInterface $one, DataDefinitionInterface $other): bool {
    return serialize(static::withoutPresentation($one)) === serialize(static::withoutPresentation($other));
  }

  /**
   * Says what a definition declares, less what presents it.
   *
   * Its class and its definition array less the label, the description
   * and whether it is required, its constraints and settings in name
   * order; and a list's item or a map's keys the same way, at any depth.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   *
   * @return array
   *   What it declares.
   */
  protected static function withoutPresentation(DataDefinitionInterface $definition): array {
    $own = $definition instanceof DataDefinition ? $definition->toArray() : ['type' => $definition->getDataType()];
    unset($own['label'], $own['description'], $own['required']);
    foreach (['constraints', 'settings'] as $named) {
      if (is_array($own[$named] ?? NULL)) {
        ksort($own[$named]);
      }
    }
    ksort($own);
    $declared = ['class' => $definition::class, 'own' => $own];
    if ($definition instanceof ListDataDefinitionInterface) {
      $declared['item'] = static::withoutPresentation($definition->getItemDefinition());
    }
    elseif ($definition instanceof ComplexDataDefinitionInterface) {
      $declared['keys'] = array_map(static::withoutPresentation(...), $definition->getPropertyDefinitions());
    }
    return $declared;
  }

  /**
   * Builds each variant mixed in by itself, and settles its members.
   *
   * The members are the owner's keys already (layVariantMixins()). Here
   * each variant is built by itself in the owner's context for its def,
   * as a structure mixed in is, and asked its access: omitted takes its
   * value out of the choices, and its members with it where no other
   * variant offered brings them; locked holds them to what is stored,
   * where every variant offered that brings them is locked. The deciding
   * key is offered the values left, and each member gets the rules that
   * say where it applies and where it is required, bound in the owner's
   * frame, before each variant's own refiners and rules, which bind as a
   * structure mixed in binds, on its own members.
   *
   * Decision: see docs/decisions.md#a-mixin-may-be-chosen-by-a-sibling-key.
   *
   * @param \Drupal\surface\ResourceBuild\Build $build
   *   The owner's build: its builder, the owner, its context and frame.
   * @param \Drupal\surface\ResourceBuild\ResourceBlueprint $inputs
   *   The owner's input blueprint, its variants laid.
   * @param array $mixed
   *   What mixIn() answered.
   *
   * @return array
   *   The same, with each variant's members, guarded members and ruling
   *   added, and under `variants` each call's deciding key, the rules
   *   saying where its members apply, and its variants to bind.
   *
   * @throws \LogicException
   *   When a list the deciding key declares names a value no variant is
   *   for.
   */
  public function mixInVariants(Build $build, ResourceBlueprint $inputs, array $mixed): array {
    $builder = $build->builder;
    $definition = $build->definition;
    $context = $build->context;
    $frame = $build->frame;
    $mixed['variants'] = [];
    foreach ($inputs->variantMixins() as $call) {
      $by = $call['by'];
      $laid = $call['laid'] ?? NULL;
      if ($laid === NULL) {
        // Declared where no build laid its members: nothing to settle.
        continue;
      }
      $variants = [];
      $offered = [];
      $bind = [];
      $locked = [];
      $reasons = [];
      $widened = $laid['widened'] ?? [];
      // Each member two variants declare unlike, as each variant built
      // declares it, by value: what it is held to where that one is chosen.
      $declaredAs = [];
      foreach ($laid['children'] as $value => $child) {
        $value = (string) $value;
        $structure = $this->registry->getDefinition($child);
        $blueprint = $laid['blueprints'][$value];
        $child_context = Resources::childContext($context, $structure->id);
        [$access, $runtime] = $this->gating->childAccess($structure, NULL, $child_context, $this->gating->account(), $frame['permission'] ?? NULL);
        if ($access !== NULL) {
          $builder->addCacheableDependency($access);
        }
        $offered[] = $value;
        if ($access !== NULL && $access->isOmitted()) {
          continue;
        }
        $built = $structure->isRead() && $access === NULL ? $this->attaching->buildRead($structure->name(), $child_context, []) : $this->attaching->buildResource($structure->name(), $child_context, []);
        $map = $built->getDefinitions();
        $present = [];
        // A child that is not alike in every variant is no child of the
        // owner's: a plain member, held to this one's definition of it.
        $children = array_diff([
          ...array_keys($blueprint->attachments()),
          ...array_keys($blueprint->lists()),
          ...array_keys($blueprint->variants()),
        ], $widened);
        if ($access !== NULL && $access->isLocked()) {
          $reasons[$value] = $access->getReason();
        }
        foreach ($blueprint->declarationOrder() as $key) {
          if (in_array($key, $children, TRUE)) {
            // The owner's child, or its variants, asked their own access
            // where the owner builds them; locked where every variant
            // offered that brings it is.
            $present[] = $key;
            $locked[$value][$key] = $access !== NULL && $access->isLocked();
            if ($runtime) {
              $builder->markRuntimeAccess($key);
            }
            continue;
          }
          if (!$map->has($key)) {
            // A structure the variant mixes in itself, which that one's
            // access left out inside it.
            continue;
          }
          $present[] = $key;
          if (in_array($key, $widened, TRUE)) {
            $declaredAs[$key][$value] = $map->get($key);
          }
          $locked[$value][$key] = ($access !== NULL && $access->isLocked()) || $map->isHeld($key);
          if ($runtime || $map->isRuntimeAccess($key)) {
            $builder->markRuntimeAccess($key);
          }
        }
        $variants[$value] = new Mixin(new Attachment($built, $structure->name(), $structure->id, label: $structure->label(), description: $structure->description()), $present);
        $mixed = static::mixedMembers($mixed, $blueprint, $structure, fn (string $name): ResourceDefinition => $this->registry->getDefinition($name));
        $bind[$value] = $this->mixinBindings($structure, $blueprint, [], $present);
      }
      // A list the deciding key declares narrows the values offered.
      Attaching::assertListNarrowsVariants($builder, $definition, sprintf('mixed in by "%s"', $by), $by, $offered);
      $set = ChoiceSet::of($builder->getDefinition($by) ?? DataDefinition::create('string'));
      if ($set !== NULL) {
        $kept = array_map('strval', $set->values);
        $variants = array_intersect_key($variants, array_flip($kept));
        $bind = array_intersect_key($bind, array_flip($kept));
      }
      $table = new MixinVariants($by, $variants, array_intersect_key($laid['labels'], $variants));
      $present = $table->members();
      $withdrawn = array_values(array_diff(array_keys($laid['values']), $present));
      foreach ($withdrawn as $key) {
        $builder->withdraw($key);
      }
      $held = [
        ...array_keys($laid['attachments'] ?? []),
        ...array_keys($laid['lists'] ?? []),
        ...array_keys($laid['variants'] ?? []),
      ];
      foreach ($present as $key) {
        // Held where every variant offered that brings it holds it; a
        // child locked.
        $holding = array_map(static fn (string $value): bool => $locked[$value][$key] ?? FALSE, $table->valuesOf($key));
        if (in_array(FALSE, $holding, TRUE)) {
          continue;
        }
        if (in_array($key, $held, TRUE)) {
          $builder->lock($key, $reasons[$table->valuesOf($key)[0]] ?? NULL);
          continue;
        }
        $builder->hold($key);
      }
      $builder->addMixinVariants(new MixinVariants($by, $variants, $table->labels, $withdrawn, array_values(array_intersect($widened, $present))));
      $mixed['variants'][] = [
        'by' => $by,
        'rules' => $this->chosenRules($builder, $by, $table, $laid['required'], $mixed['ruling'], $declaredAs),
        'bind' => array_merge(...array_values($bind)),
      ];
    }
    return $mixed;
  }

  /**
   * Writes the rules that say where each member of variants mixed in applies.
   *
   * A member does not apply unless the deciding key holds a value whose
   * variant brings it; one two variants declare unlike is held to the
   * chosen variant's definition of it, one rule per definition, the
   * values that declare it alike together; and it is required where the
   * variant chosen requires it, in that order. Nothing else is said of it
   * here, since its own refiners and rules are its variant's. Each is a
   * declared rule, so the pipeline, the form and the tool listing read it
   * as any rule; the contract states the variants once, as conditionals
   * naming their defs, in its place.
   *
   * @param \Drupal\surface\SurfaceBuilderInterface $builder
   *   The owner's builder, holding the deciding key.
   * @param string $by
   *   The deciding key.
   * @param \Drupal\surface\MixinVariants $table
   *   The variants offered.
   * @param array<string, string[]> $required
   *   The values under which each member is required.
   * @param string[] $ruling
   *   The keys a coded refiner may rule out, which no rule requires.
   * @param array<string, array<string, \Drupal\Core\TypedData\DataDefinitionInterface|null>> $declaredAs
   *   Each member two variants declare unlike, with each variant's own
   *   definition of it, by value.
   *
   * @return \Drupal\surface\Refinement\Rule[]
   *   The rules, in the members' order.
   */
  protected function chosenRules(SurfaceBuilderInterface $builder, string $by, MixinVariants $table, array $required, array $ruling, array $declaredAs = []): array {
    $type = $builder->getDefinition($by)?->getDataType();
    // A condition compares the key with values of its own type.
    $cast = static fn (string $value): int|float|string|bool => match ($type) {
      'integer' => (int) $value,
      'float' => (float) $value,
      'boolean' => (bool) (int) $value,
      default => $value,
    };
    $chosen = static fn (array $values): Condition => count($values) === 1
      ? Condition::equals($by, $cast($values[0]))
      : Condition::in($by, array_map($cast, $values));
    $rules = [];
    foreach ($table->members() as $key) {
      $values = $table->valuesOf($key);
      $rules[] = (new Rule($key))->when(Condition::not($chosen($values)))->doesNotApply();
      // Decision: see docs/decisions.md#a-mixin-may-be-chosen-by-a-sibling-key.
      $alike = [];
      foreach (array_intersect_key($declaredAs[$key] ?? [], array_flip($values)) as $value => $own) {
        if ($own !== NULL) {
          $alike[serialize(static::withoutPresentation($own))][] = [(string) $value, $own];
        }
      }
      foreach ($alike as $same) {
        $rules[] = (new Rule($key))->when($chosen(array_column($same, 0)))->holdTo($same[0][1]);
      }
      $requiring = array_values(array_intersect($required[$key] ?? [], $values));
      if ($requiring !== [] && !in_array($key, $ruling, TRUE)) {
        $rules[] = (new Rule($key))->when($chosen($requiring))->require();
      }
    }
    return $rules;
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
          '%s mixes %s in without wiring its "%s" port: with(..., reads: [\'%s\' => \'<key>\']) names the key of %s it reads. A mixed-in structure\'s refiners read each port as the owner\'s key it is wired to, so every port is wired, required or not.',
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
