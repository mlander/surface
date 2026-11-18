<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\HandCorpus;

use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\surface\Attachment;
use Drupal\surface\Pipeline\SurfacePipelineInterface;
use Drupal\surface\Port;
use Drupal\surface\Refinement\WatchedValueCheckInterface;
use Drupal\surface\Contract\DefinitionSchema;
use Drupal\surface\Contract\SchemaFacts;
use Drupal\surface\SurfaceBuilder;
use Drupal\surface\SurfaceInterface;

/**
 * Ports wired to a key of a class no schema derives a definition of.
 *
 * A child reads a port its container wires to a key of a class outside
 * DefinitionFromSchema::DERIVED (the hand corpus's NotAMapDefinition, core's
 * BaseFieldDefinition), or to an integer key; a refiner of the child's `v`
 * watches the port, and the watched-value check is sealed in at both
 * levels. The port's key is held as its schema alone, and the check is
 * handed that schema; `port` names the class the schema was read from.
 *
 * Decision: see docs/decisions.md#the-document-is-written-from-schemas.
 */
final class KeptPortCases {

  /**
   * The cases: the key the port is wired to, by case name.
   *
   * @return array<string, array{0: string, 1: \Drupal\Core\TypedData\DataDefinitionInterface, 2: array<string, mixed>}>
   *   The port's type, the key's definition, and the value sets the
   *   surface is accepted over, each with a value of the key's type and one
   *   of another.
   */
  public static function keys(): array {
    $complex = NotAMapDefinition::create()->setLabel('Pcx');
    $complex->setPropertyDefinition('z', DataDefinition::create('string')->setLabel('Z')->addConstraint('Length', ['max' => 2]));
    return [
      'notAMap' => ['any', $complex, ['right' => ['z' => 'a'], 'wrong' => 'flat']],
      'baseField' => [
        'any',
        BaseFieldDefinition::create('string')->setLabel('Bf'),
        ['right' => [['value' => 'a']], 'wrong' => 'flat'],
      ],
      'integer' => ['integer', DataDefinition::create('integer')->setLabel('Pint'), ['right' => 5, 'wrong' => 'word']],
    ];
  }

  /**
   * Builds the surface of one case.
   *
   * @param string $type
   *   The port's type.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $key
   *   The definition of the container's key the port is wired to.
   * @param \Drupal\surface\Refinement\WatchedValueCheckInterface $check
   *   The watched-value check, sealed in at both levels.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The container.
   */
  public static function surface(string $type, DataDefinitionInterface $key, WatchedValueCheckInterface $check): SurfaceInterface {
    $child = new SurfaceBuilder();
    $child->setWatchedValueCheck($check);
    $child->setDefinition('v', DataDefinition::create('string')->setLabel('V')->addConstraint('Length', ['max' => 20]));
    $child->addPort(new Port('p', $type));
    $child->addRefinement('v', ['p']);
    $child->addRefiner('v', new HandRefiner(['a' => [['len', '', 2]], '5' => [['len', '', 5]]], 'p'));
    $top = new SurfaceBuilder();
    $top->setWatchedValueCheck($check);
    $top->setDefinition('k', $key);
    $top->setDefinition('att', MapDataDefinition::create()->setLabel('Att'));
    $top->attach('att', (new Attachment($child->seal()))->withReads(['p' => 'k'], ['p' => $top->getDefinition('k')]));
    return $top->seal();
  }

  /**
   * Records what each case answers, as the test pins it.
   *
   * @param \Drupal\surface\Refinement\WatchedValueCheckInterface $check
   *   The watched-value check.
   * @param \Drupal\surface\Pipeline\SurfacePipelineInterface $pipeline
   *   The pipeline.
   *
   * @return array<string, mixed>
   *   By `<case> <set>`: the accepted values, the refusals' keys and paths,
   *   what the child's refinement withheld, and the class and label of the
   *   port's definition; fresh and, but for the NotAMapDefinition
   *   case, woken; or what was thrown.
   */
  public static function answers(WatchedValueCheckInterface $check, SurfacePipelineInterface $pipeline): array {
    $answers = [];
    foreach (static::keys() as $case => [$type, $key, $sets]) {
      $surface = static::surface($type, $key, $check);
      // A NotAMapDefinition wakes with no properties (core's
      // ComplexDataDefinitionBase drops them, and only a
      // MapDataDefinition's are kept): that case is answered fresh alone.
      // phpcs:ignore DrupalPractice.FunctionCalls.InsecureUnserialize.InsecureUnserialize
      $forms = $case === 'notAMap' ? ['' => $surface] : ['' => $surface, ' woken' => unserialize(serialize($surface))];
      foreach ($forms as $form => $each) {
        foreach ($sets as $set => $value) {
          try {
            $input = ['k' => $value, 'att' => ['v' => 'abcdef']];
            $accepted = $pipeline->accept($each, $input, []);
            $refusals = [];
            foreach ($pipeline->validate($each, $accepted, []) as $violation) {
              $refusals[] = $violation->key . ':' . $violation->path;
            }
            $child = $each->refine($accepted)->entries()->entry('att')->childFor($accepted);
            $schema = $child->getPortSchema('p');
            $answers["$case$form $set"] = [
              'accepted' => $accepted,
              'refusals' => $refusals,
              'withheld' => array_keys($child->refine($child->getDefaultValues())->withheldValues()),
              'port' => [DefinitionSchema::classOf($schema), (string) SchemaFacts::label($schema)],
            ];
          }
          catch (\Throwable $e) {
            $answers["$case$form $set"] = get_class($e) . ': ' . $e->getMessage();
          }
          // The child bound directly (withPorts()), its port handed the
          // value and the key's schema: the check is handed the value
          // whatever the container's own key accepts.
          try {
            $child = $each->entries()->entry('att')->attachment->child->withPorts(['p' => $value], ['p' => DefinitionSchema::of($key)]);
            $answers["$case$form $set bound"] = [
              'withheld' => array_keys($child->refine(['v' => 'abcdef'])->withheldValues()),
              'port' => DefinitionSchema::classOf($child->getPortSchema('p')),
            ];
          }
          catch (\Throwable $e) {
            $answers["$case$form $set bound"] = get_class($e) . ': ' . $e->getMessage();
          }
        }
      }
    }
    return $answers;
  }

}
