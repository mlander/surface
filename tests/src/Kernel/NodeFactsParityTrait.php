<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\surface\Contract\DefinitionSchema;
use Drupal\surface\Contract\NodeFacts;
use Drupal\surface\Contract\Secrets;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Reference\References;
use Drupal\surface\Refinement\DoesNotApply;
use Drupal\surface\Storage\ConfigObjectStorage;
use PortableSchema\Builder\Schema;

/**
 * Compares each reader of a node with the definition call it stands for.
 *
 * For a definition and its built node, at every depth the node holds a
 * node (a map's properties, a list's item, a shape's, a config type's and
 * `each`'s definition), each reader of a node answers what the call on
 * the definition answers, by strict equality: an object is the same
 * object, except a message the keywords make new on each call, compared
 * by what it holds. Shared by NodeFactsParityTest and the development
 * site's parity script.
 */
trait NodeFactsParityTrait {

  /**
   * The readers compared, each with the definition call it stands for.
   */
  protected const READERS = [
    'dataType' => 'getDataType()',
    'hasConstraints' => 'getConstraints() !== []',
    'isRequired' => 'isRequired()',
    'isAlwaysHeld' => 'DefinitionMetadata::isAlwaysHeld()',
    'isGrouping' => 'DefinitionMetadata::isGrouping()',
    'holdsValue' => 'DefinitionMetadata::holdsValue()',
    'isNullable' => '!holdsValue() && !isSecret()',
    'secret' => 'DefinitionMetadata::isSecret()',
    'settings' => 'getSettings()',
    'label' => 'getLabel()',
    'ownLabel' => 'DefinitionMetadata::ownLabel()',
    'hasDefault' => 'DefinitionMetadata::hasDefaultValue()',
    'default' => 'DefinitionMetadata::getDefaultValue()',
    'defaultOf' => 'DefinitionMetadata::defaultOf()',
    'dateOnly' => 'array_key_exists(\'DateOnly\', getConstraints())',
    'isList' => 'instanceof ListDataDefinitionInterface',
    'isMap' => 'DefinitionSchema::isMap()',
    'isLeaf' => 'neither complex nor a list',
    'isComplex' => 'instanceof ComplexDataDefinitionInterface',
    'properties' => 'array_keys(getPropertyDefinitions())',
    'description' => 'getDescription()',
    'constraints' => 'getConstraints()',
    'constraintsJson' => 'json_encode(getConstraints())',
    'declaredConstraints' => 'toArray()[\'constraints\'] ?? [] of a DataDefinition',
    'doesNotApply' => 'DoesNotApply::marks()',
    'compute' => 'DefinitionMetadata::computedOf()',
    'resolve' => 'DefinitionMetadata::resolveOf()',
    'each' => 'DefinitionMetadata::eachOf(), a definition as its node',
    'variantsBy' => 'DefinitionMetadata::variantsBy()',
    'variantsFrom' => 'DefinitionMetadata::variantsFrom()',
    'readVariants' => 'DefinitionMetadata::variantsListedBySite()',
    'configType' => 'DefinitionMetadata::configTypeOf()',
    'configTypeNode' => 'DefinitionMetadata::configTypeDefinition() !== NULL',
    'shape' => 'DefinitionMetadata::shapeOf()',
    'shapeNode' => 'DefinitionMetadata::shapeDefinition() !== NULL',
    'isFixed' => 'DefinitionMetadata::isFixed()',
    'isOneVariant' => 'DefinitionMetadata::isOneVariant()',
    'isHeldWhole' => 'DefinitionMetadata::isHeldWhole()',
    'placedAfter' => 'DefinitionMetadata::getPlacedAfter()',
    'arrangement' => 'DefinitionMetadata::arrangementOf()',
    'isReadOnly' => 'isReadOnly()',
    'isComputed' => 'isComputed()',
    'setting' => 'getSetting(), for each setting held and one not',
    'typeOf' => 'DefinitionSchema::type()',
    'keywordsOf' => 'DefinitionSchema::keywords(), with the type\'s own keywords given',
    'storedByUuid' => 'References::storedByUuid()',
    'valueClass' => 'getClass()',
  ];

  /**
   * Gets an answer, or the class of what was thrown in its place.
   *
   * @param \Closure(): array $ask
   *   What answers.
   *
   * @return array
   *   The answer; `['threw' => class]` where it threw.
   */
  protected static function answered(\Closure $ask): array {
    try {
      return $ask();
    }
    catch (\Throwable $thrown) {
      return ['threw' => get_class($thrown)];
    }
  }

  /**
   * Gives an answer with each object in it replaced by what it holds.
   *
   * @param mixed $answer
   *   An answer.
   *
   * @return mixed
   *   The answer, each object as its class and its members, at every depth.
   */
  protected static function messagesHeld(mixed $answer): mixed {
    if (is_object($answer)) {
      return ['class' => get_class($answer), 'members' => static::messagesHeld((array) $answer)];
    }
    return is_array($answer) ? array_map(static::messagesHeld(...), $answer) : $answer;
  }

  /**
   * Compares every reader on a definition and its node, at every depth.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   * @param \PortableSchema\Builder\Schema $node
   *   Its built node.
   * @param string $at
   *   Where it is, for a difference.
   * @param array<string, int> $counts
   *   The comparisons made, by reader.
   * @param \SplObjectStorage<\Drupal\Core\TypedData\DataDefinitionInterface, null>|null $seen
   *   The definitions compared already; each is compared once.
   * @param array<string, int> $stated
   *   The comparisons whose definition call states the fact (an answer
   *   that is not NULL, FALSE, empty or a setting nothing holds), by
   *   reader.
   *
   * @return list<string>
   *   Each disagreement: where, the reader, both answers.
   */
  protected static function parity(DataDefinitionInterface $definition, Schema $node, string $at, array &$counts, ?\SplObjectStorage $seen = NULL, array &$stated = []): array {
    $seen ??= new \SplObjectStorage();
    if ($seen->contains($definition)) {
      return [];
    }
    $seen->attach($definition);
    $differ = [];
    $holds = DefinitionMetadata::canHoldDefaultValue($definition);
    $complex = $definition instanceof ComplexDataDefinitionInterface;
    $list = $definition instanceof ListDataDefinitionInterface;
    $answers = [
      'dataType' => [NodeFacts::dataType($node), $definition->getDataType()],
      'hasConstraints' => [NodeFacts::hasConstraints($node), $definition->getConstraints() !== []],
      'isRequired' => [NodeFacts::isRequired($node), (bool) $definition->isRequired()],
      'isAlwaysHeld' => [NodeFacts::isAlwaysHeld($node), DefinitionMetadata::isAlwaysHeld($definition)],
      'isGrouping' => [NodeFacts::isGrouping($node), DefinitionMetadata::isGrouping($definition)],
      'holdsValue' => [NodeFacts::holdsValue($node), DefinitionMetadata::holdsValue($definition)],
      'isNullable' => [
        NodeFacts::isNullable($node),
        !DefinitionMetadata::holdsValue($definition) && !DefinitionMetadata::isSecret($definition),
      ],
      'secret' => [Secrets::marked($node), DefinitionMetadata::isSecret($definition)],
      'settings' => [NodeFacts::settings($node), $definition->getSettings()],
      'label' => [NodeFacts::label($node), $definition->getLabel()],
      'ownLabel' => [NodeFacts::ownLabel($node), DefinitionMetadata::ownLabel($definition)],
      'hasDefault' => [NodeFacts::hasDefault($node), $holds && DefinitionMetadata::hasDefaultValue($definition)],
      'default' => [NodeFacts::default($node), $holds ? DefinitionMetadata::getDefaultValue($definition) : NULL],
      'dateOnly' => [
        DefinitionSchema::declares($node, 'DateOnly'),
        array_key_exists('DateOnly', $definition->getConstraints()),
      ],
      'isList' => [DefinitionSchema::isList($node), $list],
      'isMap' => [DefinitionSchema::nodeIsMap($node), DefinitionSchema::isMap($definition)],
      'isLeaf' => [DefinitionSchema::isLeaf($node), !$complex && !$list],
      'isComplex' => [DefinitionSchema::isComplex($node), $complex],
      'properties' => [
        array_keys(NodeFacts::properties($node)),
        DefinitionSchema::isMap($definition) && $complex ? array_keys($definition->getPropertyDefinitions()) : [],
      ],
      'description' => [NodeFacts::description($node), $definition->getDescription()],
      'constraints' => [NodeFacts::constraints($node), $definition->getConstraints()],
      'constraintsJson' => [json_encode(NodeFacts::constraints($node)), json_encode($definition->getConstraints())],
      'declaredConstraints' => [
        NodeFacts::declaredConstraints($node),
        $definition instanceof DataDefinition ? $definition->toArray()['constraints'] ?? [] : [],
      ],
      'doesNotApply' => [NodeFacts::doesNotApply($node), DoesNotApply::marks($definition)],
      'compute' => [NodeFacts::compute($node), DefinitionMetadata::computedOf($definition)],
      'resolve' => [NodeFacts::resolve($node), DefinitionMetadata::resolveOf($definition)],
      'each' => [
        static::eachAnswer(NodeFacts::each($node)),
        static::eachAnswer(DefinitionMetadata::eachOf($definition)),
      ],
      'variantsBy' => [NodeFacts::variantsBy($node), DefinitionMetadata::variantsBy($definition)],
      'variantsFrom' => [NodeFacts::variantsFrom($node), DefinitionMetadata::variantsFrom($definition)],
      'readVariants' => [NodeFacts::readVariants($node), DefinitionMetadata::variantsListedBySite($definition)],
      'configType' => [NodeFacts::configType($node), DefinitionMetadata::configTypeOf($definition)],
      'configTypeNode' => [
        NodeFacts::configTypeNode($node) !== NULL,
        DefinitionMetadata::configTypeDefinition($definition) !== NULL,
      ],
      'shape' => [NodeFacts::shape($node), DefinitionMetadata::shapeOf($definition)],
      'shapeNode' => [
        DefinitionSchema::shapeNode($node) !== NULL,
        DefinitionMetadata::shapeDefinition($definition) !== NULL,
      ],
      'isFixed' => [NodeFacts::isFixed($node), DefinitionMetadata::isFixed($definition)],
      'isOneVariant' => [NodeFacts::isOneVariant($node), DefinitionMetadata::isOneVariant($definition)],
      'isHeldWhole' => [NodeFacts::isHeldWhole($node), DefinitionMetadata::isHeldWhole($definition)],
      'placedAfter' => [NodeFacts::placedAfter($node), DefinitionMetadata::getPlacedAfter($definition)],
      'arrangement' => [NodeFacts::arrangement($node), DefinitionMetadata::arrangementOf($definition)],
      'isReadOnly' => [NodeFacts::isReadOnly($node), (bool) $definition->isReadOnly()],
      'isComputed' => [NodeFacts::isComputed($node), (bool) $definition->isComputed()],
      'setting' => static::settingAnswers($definition, $node),
      'storedByUuid' => [References::storedByUuidIn($node), References::storedByUuid($definition)],
      'valueClass' => [
        ConfigObjectStorage::valueClass($node, static fn (): DataDefinitionInterface => $definition),
        (string) $definition->getClass(),
      ],
    ];
    // The type a leaf states and the keywords its checks state, read from
    // the node as the document's classes read them.
    $type = static::answered(static fn (): array => DefinitionSchema::type($definition));
    $answers['typeOf'] = [static::answered(static fn (): array => DefinitionSchema::typeOf($node)), $type];
    $given = isset($type['threw']) ? [] : $type;
    // A message is made new on each call: compared by what it holds.
    $answers['keywordsOf'] = [
      static::messagesHeld(static::answered(static fn (): array => DefinitionSchema::keywordsOf($node, $given['type'] ?? NULL, $given))),
      static::messagesHeld(static::answered(static fn (): array => DefinitionSchema::keywords($definition, $given['type'] ?? NULL, $given))),
    ];
    // defaultOf() reads every property's default, which a definition with
    // no place for one cannot answer: compared where each can.
    if (static::holdsDefaults($definition)) {
      $answers['defaultOf'] = [NodeFacts::defaultOf($node), DefinitionMetadata::defaultOf($definition)];
    }
    foreach ($answers as $reader => [$read, $called]) {
      $counts[$reader] = ($counts[$reader] ?? 0) + 1;
      if (!in_array($called, [NULL, FALSE, [], '', '[]', ['surface_parity_unset' => NULL]], TRUE)) {
        $stated[$reader] = ($stated[$reader] ?? 0) + 1;
      }
      if ($read !== $called) {
        $differ[] = sprintf('%s %s: node %s, definition %s', $at, $reader, var_export($read, TRUE), var_export($called, TRUE));
      }
    }
    $below = [];
    if ($list) {
      $below['items'] = [$definition->getItemDefinition(), DefinitionSchema::itemNode($node)];
    }
    elseif ($complex) {
      foreach ($definition->getPropertyDefinitions() as $name => $property) {
        $below["properties/$name"] = [$property, DefinitionSchema::propertyNode($node, (string) $name)];
      }
    }
    $below['shape'] = [DefinitionMetadata::shapeDefinition($definition), DefinitionSchema::shapeNode($node)];
    $below['configType'] = [DefinitionMetadata::configTypeDefinition($definition), NodeFacts::configTypeNode($node)];
    $each = DefinitionMetadata::eachOf($definition);
    $below['each'] = [$each instanceof DataDefinitionInterface ? $each : NULL, NodeFacts::each($node)];
    foreach ($below as $where => [$inner, $inner_node]) {
      if (!$inner instanceof DataDefinitionInterface) {
        continue;
      }
      if (!$inner_node instanceof Schema) {
        $differ[] = "$at/$where: the definition holds one, the node none";
        continue;
      }
      array_push($differ, ...static::parity($inner, $inner_node, "$at/$where", $counts, $seen, $stated));
    }
    return $differ;
  }

  /**
   * Says what `each` holds, so a definition and its node compare alike.
   *
   * @param mixed $each
   *   What eachOf() or NodeFacts::each() gives.
   *
   * @return string|null
   *   The class every entry is; 'definition' for a definition or a node,
   *   whose own facts the walk compares below; NULL for none.
   */
  protected static function eachAnswer(mixed $each): ?string {
    return $each instanceof DataDefinitionInterface || $each instanceof Schema ? 'definition' : $each;
  }

  /**
   * Reads each setting a definition holds, and one it does not, both ways.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   * @param \PortableSchema\Builder\Schema $node
   *   Its built node.
   *
   * @return array{0: array, 1: array}
   *   NodeFacts::setting() and getSetting(), by setting name.
   */
  protected static function settingAnswers(DataDefinitionInterface $definition, Schema $node): array {
    $answers = [[], []];
    foreach ([...array_keys($definition->getSettings()), 'surface_parity_unset'] as $name) {
      $answers[0][$name] = NodeFacts::setting($node, (string) $name);
      $answers[1][$name] = $definition->getSetting((string) $name);
    }
    return $answers;
  }

  /**
   * Answers whether every definition in a map's tree has a default's place.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   *
   * @return bool
   *   TRUE where DefinitionMetadata::defaultOf() can read it whole.
   */
  protected static function holdsDefaults(DataDefinitionInterface $definition): bool {
    if (!DefinitionMetadata::canHoldDefaultValue($definition)) {
      return FALSE;
    }
    if ($definition instanceof ComplexDataDefinitionInterface) {
      foreach ($definition->getPropertyDefinitions() as $property) {
        if (!static::holdsDefaults($property)) {
          return FALSE;
        }
      }
    }
    return TRUE;
  }

}
