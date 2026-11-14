<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\surface\Contract\DefinitionSchema;
use Drupal\surface\Contract\NodeFacts;
use Drupal\surface\Contract\Secrets;
use Drupal\surface\DefinitionMetadata;
use PortableSchema\Builder\Schema;

/**
 * Compares each reader of a node with the definition call it stands for.
 *
 * For a definition and its built node, at every depth the node holds a
 * node (a map's properties, a list's item, a shape's, a config type's and
 * `each`'s definition), each reader the value pipeline uses answers what
 * the call on the definition answers, by strict equality: an object is
 * the same object. Shared by NodeFactsParityTest and the development
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
    'properties' => 'array_keys(getPropertyDefinitions())',
  ];

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
   *
   * @return list<string>
   *   Each disagreement: where, the reader, both answers.
   */
  protected static function parity(DataDefinitionInterface $definition, Schema $node, string $at, array &$counts, ?\SplObjectStorage $seen = NULL): array {
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
      'properties' => [
        array_keys(NodeFacts::properties($node)),
        DefinitionSchema::isMap($definition) && $complex ? array_keys($definition->getPropertyDefinitions()) : [],
      ],
    ];
    // defaultOf() reads every property's default, which a definition with
    // no place for one cannot answer: compared where each can.
    if (static::holdsDefaults($definition)) {
      $answers['defaultOf'] = [NodeFacts::defaultOf($node), DefinitionMetadata::defaultOf($definition)];
    }
    foreach ($answers as $reader => [$read, $called]) {
      $counts[$reader] = ($counts[$reader] ?? 0) + 1;
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
    $model = DefinitionSchema::model($node);
    $below['shape'] = [DefinitionMetadata::shapeDefinition($definition), $model['shape']['node'] ?? NULL];
    $below['configType'] = [DefinitionMetadata::configTypeDefinition($definition), $model['configType']['node'] ?? NULL];
    $each = DefinitionMetadata::eachOf($definition);
    $below['each'] = [$each instanceof DataDefinitionInterface ? $each : NULL, $model['each'] ?? NULL];
    foreach ($below as $where => [$inner, $inner_node]) {
      if (!$inner instanceof DataDefinitionInterface) {
        continue;
      }
      if (!$inner_node instanceof Schema) {
        $differ[] = "$at/$where: the definition holds one, the node none";
        continue;
      }
      array_push($differ, ...static::parity($inner, $inner_node, "$at/$where", $counts, $seen));
    }
    return $differ;
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
