<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\surface\Contract\DefinitionSchema;
use Drupal\surface\Contract\SchemaFacts;
use Drupal\surface\Contract\Secrets;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Reference\References;
use Drupal\surface\Refinement\DoesNotApply;
use Drupal\surface\Storage\ConfigObjectStorage;
use PortableSchema\Builder\Schema;

/**
 * Compares each reader of a schema with the definition call it stands for.
 *
 * For a definition and its built schema, at every depth the schema holds a
 * schema (a map's properties, a list's item, a shape's, a config type's and
 * `each`'s definition), each reader of a schema answers what the call on
 * the definition answers, by strict equality: an object is the same
 * object, except a message the keywords make new on each call, compared
 * by what it holds. Shared by SchemaFactsParityTest and the development
 * site's parity script.
 */
trait SchemaFactsParityTrait {

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
    'each' => 'DefinitionMetadata::eachOf(), a definition as its schema',
    'variantsBy' => 'DefinitionMetadata::variantsBy()',
    'variantsFrom' => 'DefinitionMetadata::variantsFrom()',
    'readVariants' => 'DefinitionMetadata::variantsListedBySite()',
    'configType' => 'DefinitionMetadata::configTypeOf()',
    'configTypeOf' => 'DefinitionMetadata::configTypeDefinition() !== NULL',
    'shape' => 'DefinitionMetadata::shapeOf()',
    'shapeOf' => 'DefinitionMetadata::shapeDefinition() !== NULL',
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
   * Compares every reader on a definition and its schema, at every depth.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   * @param \PortableSchema\Builder\Schema $schema
   *   Its built schema.
   * @param string $at
   *   Where it is, for a difference.
   * @param array<string, int> $counts
   *   The comparisons made, by reader.
   * @param \SplObjectStorage<\PortableSchema\Builder\Schema, null>|null $seen
   *   The schemas compared already; each is compared once. Counted by schema,
   *   not by definition: a schema-first entry derives its definition, and a
   *   key that holds a child frame derives one that holds its own copies
   *   of the child's definitions, while its schema holds the very schemas the
   *   child's entries hold. One schema is one key read once, in either form.
   * @param array<string, int> $stated
   *   The comparisons whose definition call states the fact (an answer
   *   that is not NULL, FALSE, empty or a setting nothing holds), by
   *   reader.
   *
   * @return list<string>
   *   Each disagreement: where, the reader, both answers.
   */
  protected static function parity(DataDefinitionInterface $definition, Schema $schema, string $at, array &$counts, ?\SplObjectStorage $seen = NULL, array &$stated = []): array {
    $seen ??= new \SplObjectStorage();
    if ($seen->contains($schema)) {
      return [];
    }
    $seen->attach($schema);
    $differ = [];
    $holds = DefinitionMetadata::canHoldDefaultValue($definition);
    $complex = $definition instanceof ComplexDataDefinitionInterface;
    $list = $definition instanceof ListDataDefinitionInterface;
    $answers = [
      'dataType' => [SchemaFacts::dataType($schema), $definition->getDataType()],
      'hasConstraints' => [SchemaFacts::hasConstraints($schema), $definition->getConstraints() !== []],
      'isRequired' => [SchemaFacts::isRequired($schema), (bool) $definition->isRequired()],
      'isAlwaysHeld' => [SchemaFacts::isAlwaysHeld($schema), DefinitionMetadata::isAlwaysHeld($definition)],
      'isGrouping' => [SchemaFacts::isGrouping($schema), DefinitionMetadata::isGrouping($definition)],
      'holdsValue' => [SchemaFacts::holdsValue($schema), DefinitionMetadata::holdsValue($definition)],
      'isNullable' => [
        SchemaFacts::isNullable($schema),
        !DefinitionMetadata::holdsValue($definition) && !DefinitionMetadata::isSecret($definition),
      ],
      'secret' => [Secrets::marked($schema), DefinitionMetadata::isSecret($definition)],
      'settings' => [SchemaFacts::settings($schema), $definition->getSettings()],
      'label' => [SchemaFacts::label($schema), $definition->getLabel()],
      'ownLabel' => [SchemaFacts::ownLabel($schema), DefinitionMetadata::ownLabel($definition)],
      'hasDefault' => [SchemaFacts::hasDefault($schema), $holds && DefinitionMetadata::hasDefaultValue($definition)],
      'default' => [SchemaFacts::default($schema), $holds ? DefinitionMetadata::getDefaultValue($definition) : NULL],
      'dateOnly' => [
        DefinitionSchema::declares($schema, 'DateOnly'),
        array_key_exists('DateOnly', $definition->getConstraints()),
      ],
      'isList' => [DefinitionSchema::isList($schema), $list],
      'isMap' => [DefinitionSchema::holdsProperties($schema), DefinitionSchema::isMap($definition)],
      'isLeaf' => [DefinitionSchema::isLeaf($schema), !$complex && !$list],
      'isComplex' => [DefinitionSchema::isComplex($schema), $complex],
      'properties' => [
        array_keys(SchemaFacts::properties($schema)),
        DefinitionSchema::isMap($definition) && $complex ? array_keys($definition->getPropertyDefinitions()) : [],
      ],
      'description' => [SchemaFacts::description($schema), $definition->getDescription()],
      'constraints' => [SchemaFacts::constraints($schema), $definition->getConstraints()],
      'constraintsJson' => [json_encode(SchemaFacts::constraints($schema)), json_encode($definition->getConstraints())],
      'declaredConstraints' => [
        SchemaFacts::declaredConstraints($schema),
        $definition instanceof DataDefinition ? $definition->toArray()['constraints'] ?? [] : [],
      ],
      'doesNotApply' => [SchemaFacts::doesNotApply($schema), DoesNotApply::marks($definition)],
      'compute' => [SchemaFacts::compute($schema), DefinitionMetadata::computedOf($definition)],
      'resolve' => [SchemaFacts::resolve($schema), DefinitionMetadata::resolveOf($definition)],
      'each' => [
        static::eachAnswer(SchemaFacts::each($schema)),
        static::eachAnswer(DefinitionMetadata::eachOf($definition)),
      ],
      'variantsBy' => [SchemaFacts::variantsBy($schema), DefinitionMetadata::variantsBy($definition)],
      'variantsFrom' => [SchemaFacts::variantsFrom($schema), DefinitionMetadata::variantsFrom($definition)],
      'readVariants' => [SchemaFacts::readVariants($schema), DefinitionMetadata::variantsListedBySite($definition)],
      'configType' => [SchemaFacts::configType($schema), DefinitionMetadata::configTypeOf($definition)],
      'configTypeOf' => [
        SchemaFacts::configTypeOf($schema) !== NULL,
        DefinitionMetadata::configTypeDefinition($definition) !== NULL,
      ],
      'shape' => [SchemaFacts::shape($schema), DefinitionMetadata::shapeOf($definition)],
      'shapeOf' => [
        DefinitionSchema::shapeOf($schema) !== NULL,
        DefinitionMetadata::shapeDefinition($definition) !== NULL,
      ],
      'isFixed' => [SchemaFacts::isFixed($schema), DefinitionMetadata::isFixed($definition)],
      'isOneVariant' => [SchemaFacts::isOneVariant($schema), DefinitionMetadata::isOneVariant($definition)],
      'isHeldWhole' => [SchemaFacts::isHeldWhole($schema), DefinitionMetadata::isHeldWhole($definition)],
      'placedAfter' => [SchemaFacts::placedAfter($schema), DefinitionMetadata::getPlacedAfter($definition)],
      'arrangement' => [SchemaFacts::arrangement($schema), DefinitionMetadata::arrangementOf($definition)],
      'isReadOnly' => [SchemaFacts::isReadOnly($schema), (bool) $definition->isReadOnly()],
      'isComputed' => [SchemaFacts::isComputed($schema), (bool) $definition->isComputed()],
      'setting' => static::settingAnswers($definition, $schema),
      'storedByUuid' => [References::storedByUuidIn($schema), References::storedByUuid($definition)],
      'valueClass' => [
        ConfigObjectStorage::valueClass($schema, static fn (): DataDefinitionInterface => $definition),
        (string) $definition->getClass(),
      ],
    ];
    // The type a leaf states and the keywords its checks state, read from
    // the schema as the document's classes read them.
    $type = static::answered(static fn (): array => DefinitionSchema::type($definition));
    $answers['typeOf'] = [static::answered(static fn (): array => DefinitionSchema::typeOf($schema)), $type];
    $given = isset($type['threw']) ? [] : $type;
    // A message is made new on each call: compared by what it holds.
    $answers['keywordsOf'] = [
      static::messagesHeld(static::answered(static fn (): array => DefinitionSchema::keywordsOf($schema, $given['type'] ?? NULL, $given))),
      static::messagesHeld(static::answered(static fn (): array => DefinitionSchema::keywords($definition, $given['type'] ?? NULL, $given))),
    ];
    // defaultOf() reads every property's default, which a definition with
    // no place for one cannot answer: compared where each can.
    if (static::holdsDefaults($definition)) {
      $answers['defaultOf'] = [SchemaFacts::defaultOf($schema), DefinitionMetadata::defaultOf($definition)];
    }
    foreach ($answers as $reader => [$read, $called]) {
      $counts[$reader] = ($counts[$reader] ?? 0) + 1;
      if (!in_array($called, [NULL, FALSE, [], '', '[]', ['surface_parity_unset' => NULL]], TRUE)) {
        $stated[$reader] = ($stated[$reader] ?? 0) + 1;
      }
      if ($read !== $called) {
        $differ[] = sprintf('%s %s: schema %s, definition %s', $at, $reader, var_export($read, TRUE), var_export($called, TRUE));
      }
    }
    $below = [];
    if ($list) {
      $below['items'] = [$definition->getItemDefinition(), DefinitionSchema::itemOf($schema)];
    }
    elseif ($complex) {
      foreach ($definition->getPropertyDefinitions() as $name => $property) {
        $below["properties/$name"] = [$property, DefinitionSchema::propertyOf($schema, (string) $name)];
      }
    }
    $below['shape'] = [DefinitionMetadata::shapeDefinition($definition), DefinitionSchema::shapeOf($schema)];
    $below['configType'] = [DefinitionMetadata::configTypeDefinition($definition), SchemaFacts::configTypeOf($schema)];
    $each = DefinitionMetadata::eachOf($definition);
    $below['each'] = [$each instanceof DataDefinitionInterface ? $each : NULL, SchemaFacts::each($schema)];
    foreach ($below as $where => [$inner, $inner_schema]) {
      if (!$inner instanceof DataDefinitionInterface) {
        continue;
      }
      if (!$inner_schema instanceof Schema) {
        $differ[] = "$at/$where: the definition holds one, the schema none";
        continue;
      }
      array_push($differ, ...static::parity($inner, $inner_schema, "$at/$where", $counts, $seen, $stated));
    }
    return $differ;
  }

  /**
   * Says what `each` holds, so a definition and its schema compare alike.
   *
   * @param mixed $each
   *   What eachOf() or SchemaFacts::each() gives.
   *
   * @return string|null
   *   The class every entry is; 'definition' for a definition or a schema,
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
   * @param \PortableSchema\Builder\Schema $schema
   *   Its built schema.
   *
   * @return array{0: array, 1: array}
   *   SchemaFacts::setting() and getSetting(), by setting name.
   */
  protected static function settingAnswers(DataDefinitionInterface $definition, Schema $schema): array {
    $answers = [[], []];
    foreach ([...array_keys($definition->getSettings()), 'surface_parity_unset'] as $name) {
      $answers[0][$name] = SchemaFacts::setting($schema, (string) $name);
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
