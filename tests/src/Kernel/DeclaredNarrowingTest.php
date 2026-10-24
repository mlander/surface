<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Refinement\Condition;
use Drupal\data_surface\Refinement\DeclaredRefiner;
use Drupal\data_surface\Refinement\DoesNotApply;
use Drupal\data_surface\Refinement\Rule;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface_resource_test\Resource\Parcel\ParcelResource;
use Drupal\data_surface_resource_test\Structure\Sender\SenderIdentityStructure;
use Drupal\data_surface_resource_test\Structure\Broken\MistypedConditionStructure;
use Drupal\data_surface_resource_test\Structure\Broken\RequiresRuledOutStructure;
use Drupal\data_surface_resource_test\Structure\Broken\UnconditionalRuleStructure;
use Drupal\data_surface_resource_test\Structure\Broken\UnofferedRuleValueStructure;
use Drupal\data_surface_resource_test\Structure\Broken\WideningRuleStructure;
use Drupal\data_surface_tool\OperationInputs;
use Drupal\Tests\data_surface\Traits\ContractValidationTrait;
use Drupal\Tests\data_surface\Traits\StaticRefinerAgreementTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests rules declared as data: built, evaluated, stated, listed.
 *
 * The parcel declares every rule between its keys with narrow(): each
 * condition of the vocabulary and each effect at least once, and its
 * alter a customs code with rules on its own key and on the owner's. A
 * rule is a static refiner: bound in the key's chain, held to the
 * narrowing check, evaluated by the pipeline, and stated by the full
 * schema as written, as a conditional of its own.
 *
 * @see docs/decisions.md#a-rule-declared-as-data
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class DeclaredNarrowingTest extends DataSurfaceKernelTestBase {

  use ContractValidationTrait;
  use StaticRefinerAgreementTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'serialization',
    'tool',
    'data_surface',
    'data_surface_tool',
    'data_surface_resource_test',
  ];

  /**
   * Where the alter's customs code is, in the frame.
   */
  protected const CUSTOMS = 'third_party_settings.data_surface_resource_test.customs';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['data_surface_resource_test']);
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Builds the parcel in its operation.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The built parcel.
   */
  protected function parcel(): DataSurfaceInterface {
    $resources = $this->container->get('data_surface.resources');
    return $resources->build(ParcelResource::class, $resources->operation(ParcelResource::class, 'configure'));
  }

  /**
   * Refines the parcel for values and reads one key.
   *
   * @param array $values
   *   The values.
   * @param string $key
   *   The key, or `customs` for the alter's mounted key.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface
   *   Its definition.
   */
  protected function refined(array $values, string $key): DataDefinitionInterface {
    $refined = $this->parcel()->refine($values);
    if ($key !== 'customs') {
      return $refined->getDefinition($key);
    }
    $mount = $refined->getDefinition('third_party_settings');
    $own = $mount instanceof ComplexDataDefinitionInterface ? $mount->getPropertyDefinition('data_surface_resource_test') : NULL;
    $customs = $own instanceof ComplexDataDefinitionInterface ? $own->getPropertyDefinition('customs') : NULL;
    $this->assertNotNull($customs, 'The customs code is mounted.');
    return $customs;
  }

  /**
   * Reads one constraint of a key, refined for values.
   *
   * @param array $values
   *   The values.
   * @param string $key
   *   The key.
   * @param string $constraint
   *   The constraint.
   *
   * @return mixed
   *   Its options, or NULL when the key does not carry it.
   */
  protected function constraint(array $values, string $key, string $constraint): mixed {
    return $this->refined($values, $key)->getConstraints()[$constraint] ?? NULL;
  }

  /**
   * The customs code as values.
   *
   * @param string|null $customs
   *   The code.
   *
   * @return array
   *   The values of the mount.
   */
  protected static function customs(?string $customs): array {
    return ['third_party_settings' => ['data_surface_resource_test' => ['customs' => $customs]]];
  }

  /**
   * Reads a schema written as JSON.
   *
   * @param string $json
   *   The JSON.
   *
   * @return array
   *   The schema.
   */
  protected static function json(string $json): array {
    return json_decode($json, TRUE, flags: JSON_THROW_ON_ERROR);
  }

  /**
   * Tests each rule is bound as a refiner that gates nothing.
   */
  public function testTheRulesAreRefinersThatWaitForNothing(): void {
    $definitions = $this->parcel()->getDefinitions();
    $value = $definitions->entry('declared_value');
    $this->assertTrue($value->isDeclared());
    $this->assertFalse($value->isRefinedAtRuntime());
    $this->assertSame(['weight', 'reference', 'insured', self::CUSTOMS], $value->dependencies);
    $this->assertSame(['weight', 'reference', 'insured', self::CUSTOMS], $value->declaredDependencies());
    $this->assertSame([], $value->codedDependencies(), 'No coded refiner watches them.');
    $this->assertSame([], $value->gate(DataSurfaceInterface::EVERY), 'A rule waits for nothing.');
    $links = array_merge(...array_values($value->refiners));
    $this->assertCount(2, $links, 'The owner\'s rules, then the alter\'s.');
    $this->assertContainsOnlyInstancesOf(DeclaredRefiner::class, $links);

    $note = $definitions->entry('note');
    $this->assertSame([''], $note->mayNotApply(), 'Ruled out by a rule.');
    $this->assertSame([''], $note->mayNotApply(DataSurfaceInterface::DECLARED));
    $this->assertSame([], $note->mayNotApply(DataSurfaceInterface::CODED));
    $mount = $definitions->entry('third_party_settings');
    $this->assertSame(['data_surface_resource_test.customs'], $mount->mayNotApply());
    foreach (['service', 'tracking', 'handling', 'labels', 'signature'] as $key) {
      $this->assertTrue($definitions->entry($key)->isDeclared(), $key);
    }
    $this->assertFalse($definitions->entry('weight')->isDeclared());
  }

  /**
   * Tests the pipeline evaluates every condition and applies every effect.
   */
  public function testEachConditionAndEffect(): void {
    // Equals a boolean; require and describe.
    $signature = $this->refined(['insured' => TRUE], 'signature');
    $this->assertTrue($signature->isRequired());
    $this->assertSame('An insured parcel is signed for.', (string) $signature->getDescription());
    $this->assertFalse($this->refined(['insured' => FALSE], 'signature')->isRequired());
    $this->assertTrue($this->refined(['insured' => '1'], 'signature')->isRequired(), 'A form\'s "1" is TRUE.');

    // In; a subset of a list.
    $handling = $this->constraint(['service' => 'express'], 'handling', 'LabeledChoice');
    $this->assertSame(['fragile', 'upright'], $handling['choices']);
    $handling = $this->constraint(['service' => 'freight'], 'handling', 'LabeledChoice');
    $this->assertSame(['fragile', 'upright', 'cold'], $handling['choices']);

    // At least; a subset of a list, by Choice.
    $this->assertSame(['freight'], $this->constraint(['weight' => 30], 'service', 'LabeledChoice')['choices']);
    $this->assertSame(['freight'], $this->constraint(['weight' => '30'], 'service', 'LabeledChoice')['choices'], 'A form\'s "30" is 30.');
    $this->assertCount(3, $this->constraint(['weight' => 29], 'service', 'LabeledChoice')['choices']);

    // At most; Count.
    $this->assertSame(['max' => 1], $this->constraint(['weight' => 5], 'labels', 'Count'));
    $this->assertSame(['max' => 3], $this->constraint(['weight' => 6], 'labels', 'Count'));

    // Below and absent; Range, tightened by each rule that holds.
    $this->assertSame(['min' => 0, 'max' => 500], $this->constraint(['weight' => 1], 'declared_value', 'Range'));
    $described = $this->refined(['weight' => 1], 'declared_value')->getDescription();
    $this->assertSame('Up to 500 for a parcel under 2 kilograms.', (string) $described);
    $this->assertSame(['min' => 0, 'max' => 5000], $this->constraint(['weight' => 2], 'declared_value', 'Range'));
    $referenced = ['weight' => 2, 'reference' => 'R1'];
    $this->assertSame(['min' => 0, 'max' => 100000], $this->constraint($referenced, 'declared_value', 'Range'));
    $empty = ['weight' => 2, 'reference' => ''];
    $this->assertSame(['min' => 0, 'max' => 5000], $this->constraint($empty, 'declared_value', 'Range'), 'An empty reference is no reference.');
    $light = ['weight' => 1, 'reference' => 'R1'];
    $this->assertSame(['min' => 0, 'max' => 500], $this->constraint($light, 'declared_value', 'Range'));

    // A rule says why only where it tightened: a light parcel already
    // holds a lower ceiling, and keeps its own words.
    $insured = $this->refined(['weight' => 1, 'insured' => TRUE], 'declared_value');
    $this->assertSame(500, $insured->getConstraints()['Range']['max']);
    $this->assertSame('Up to 500 for a parcel under 2 kilograms.', (string) $insured->getDescription());
    $insured = $this->refined(['weight' => 5, 'insured' => TRUE, 'reference' => 'R1'], 'declared_value');
    $this->assertSame(2000, $insured->getConstraints()['Range']['max']);
    $this->assertSame('Up to 2000 for an insured parcel.', (string) $insured->getDescription());

    // Above; Length beside what the key declares.
    $dear = ['declared_value' => 1001, 'weight' => 10, 'reference' => 'R1'];
    $this->assertSame(['max' => 64, 'min' => 2], $this->constraint($dear, 'signature', 'Length'));
    $dear['declared_value'] = 1000;
    $this->assertSame(['max' => 64], $this->constraint($dear, 'signature', 'Length'));

    // Not; does not apply.
    $this->assertTrue(DoesNotApply::marks($this->refined(['service' => 'standard'], 'note')));
    $this->assertTrue(DoesNotApply::marks($this->refined([], 'note')), 'No service is not freight.');
    $note = $this->refined(['service' => 'freight', 'weight' => 40], 'note');
    $this->assertFalse(DoesNotApply::marks($note));
    $this->assertTrue($note->isRequired());

    // All of, any of and present; a pattern.
    $pattern = '/^[A-Z]{2}\d{6}$/D';
    $express = ['reference' => 'R1', 'service' => 'express'];
    $this->assertSame($pattern, $this->constraint($express, 'tracking', 'Regex')['pattern'] ?? NULL);
    $insured = ['reference' => 'R1', 'insured' => TRUE];
    $this->assertSame($pattern, $this->constraint($insured, 'tracking', 'Regex')['pattern'] ?? NULL);
    $this->assertNull($this->constraint(['reference' => 'R1', 'service' => 'standard'], 'tracking', 'Regex'));
    $this->assertNull($this->constraint(['service' => 'express'], 'tracking', 'Regex'));

    // The alter's rules: on its own key, read by the owner's keys; on the
    // owner's key, read by its own.
    $this->assertTrue($this->refined(['service' => 'freight', 'weight' => 40], 'customs')->isRequired());
    $customs = $this->refined(['service' => 'freight', 'weight' => 1], 'customs');
    $this->assertTrue(DoesNotApply::marks($customs), 'Required, then ruled out: nothing is narrower.');
    $this->assertFalse($this->refined(['service' => 'standard', 'weight' => 3], 'customs')->isRequired());
    $coded = ['weight' => 3, 'reference' => 'R1'] + self::customs('C1');
    $this->assertSame(['min' => 1, 'max' => 100000], $this->constraint($coded, 'declared_value', 'Range'));
  }

  /**
   * Tests a mount with nothing in it is an empty map, written and encoded.
   *
   * Under two kilograms the alter's one key does not apply; with nothing
   * stored for it and no default, the mount is an empty map, not null,
   * which the config object's schema refuses, and its JSON document is
   * an object.
   *
   * @see docs/decisions.md#a-key-that-does-not-apply
   */
  public function testMountWithNothingInItIsAnEmptyMap(): void {
    $resources = $this->container->get('data_surface.resources');
    $context = $resources->operation(ParcelResource::class, 'configure');
    $parcel = $resources->build(ParcelResource::class, $context);
    $storage = $resources->storage(ParcelResource::class, $context, $parcel);
    $result = $this->pipeline()->submit($parcel, ['note' => ''], $storage);
    $this->assertTrue($result->committed);
    $this->assertSame([], $result->values['third_party_settings']);
    $this->assertSame([], $this->config('data_surface_resource_test.parcel')->get('third_party_settings'));
    $result = $this->pipeline()->submit($parcel, ['third_party_settings' => ['data_surface_resource_test' => ['customs' => NULL]]], $storage);
    $this->assertTrue($result->committed);
    $this->assertSame(['data_surface_resource_test' => []], $result->values['third_party_settings']);
    $this->assertSame('{"data_surface_resource_test":{}}', json_encode($this->pipeline()->document($parcel, $result->values)->third_party_settings));
  }

  /**
   * Tests the pipeline refuses what a rule refuses, naming what it read.
   */
  public function testThePipelineHoldsValuesToTheRules(): void {
    $parcel = $this->parcel();
    $stored = $this->config('data_surface_resource_test.parcel')->getRawData();
    $refusals = function (array $values) use ($parcel, $stored): array {
      $messages = [];
      $accepted = $this->pipeline()->accept($parcel, $values, $stored);
      foreach ($this->pipeline()->validate($parcel, $accepted, $stored) as $violation) {
        $at = $violation->path === '' ? $violation->key : $violation->key . '.' . $violation->path;
        $messages[$at] = strip_tags((string) $violation->message);
      }
      return $messages;
    };
    $freight = ['service' => 'freight', 'weight' => 40, 'handling' => 'cold'];
    $this->assertSame([], $refusals([]));
    $this->assertSame([
      'declared_value' => 'This value should be between 0 and 500. This applies when all 4 of these hold: Weight in kilograms is 1; Reference is empty; Insured is off; Data Surface Resource Test: Customs code is empty.',
    ], $refusals(['declared_value' => 501]));
    // A declared rule's require() names what it read, as a refused
    // constraint does; a key declared required names nothing.
    $this->assertSame(['signature' => 'Signed for by is required when Insured is on and Declared value is 100.'], $refusals(['insured' => TRUE]));
    $this->assertSame([], $refusals(['insured' => TRUE, 'signature' => 'Ann']));
    $this->assertSame([
      'handling' => 'The value you selected is not a valid choice. This applies when Service is Standard. Choose Fragile or This way up.',
    ], $refusals(['handling' => 'cold']));
    $this->assertSame([
      'note' => 'Note for the driver does not apply when Service is Standard.',
    ], $refusals(['note' => 'Ring twice']));
    $this->assertSame(['note' => 'Note for the driver is required.'], $refusals($freight + self::customs('C1')));
    $this->assertSame([], $refusals(['note' => 'Ring twice', 'declared_value' => 100] + $freight + self::customs('C1')));
    $this->assertSame([
      self::CUSTOMS => 'Customs code is required when Service is Freight and Weight in kilograms is 40.',
    ], $refusals(['note' => 'Ring twice'] + $freight), 'An alter\'s rule on its mounted key, the same.');
    $this->assertArrayHasKey('tracking', $refusals(['reference' => 'R1', 'service' => 'express', 'tracking' => 'nope']));
    $this->assertSame([], $refusals(['reference' => 'R1', 'service' => 'express', 'tracking' => 'AB123456']));
  }

  /**
   * Tests a key a rule reads for presence alone is said as set, not held.
   *
   * The present() and absent() conditions ask whether a key holds a value,
   * so the refusal says "when Reference is set"; equals, in and the bounds
   * keep the value they compared.
   */
  public function testPresenceConditionIsSaidAsSet(): void {
    $parcel = $this->parcel();
    $stored = $this->config('data_surface_resource_test.parcel')->getRawData();
    $refusals = function (array $values) use ($parcel, $stored): array {
      $messages = [];
      $accepted = $this->pipeline()->accept($parcel, $values, $stored);
      foreach ($this->pipeline()->validate($parcel, $accepted, $stored) as $violation) {
        $messages[$violation->fullPath()] = strip_tags((string) $violation->message);
      }
      return $messages;
    };
    $this->assertSame([
      'declared_value' => 'This value should be between 0 and 500. This applies when all 4 of these hold: Weight in kilograms is 1; Reference is set; Insured is off; Data Surface Resource Test: Customs code is empty.',
    ], $refusals(['declared_value' => 501, 'reference' => 'R1']));
    $this->assertSame([
      'declared_value' => 'This value should be between 1 and 100000. This applies when all 4 of these hold: Weight in kilograms is 2; Reference is set; Insured is off; Data Surface Resource Test: Customs code is set.',
    ], $refusals(['weight' => 2, 'declared_value' => 0, 'reference' => 'R1'] + self::customs('C1')), 'A key an alter mounted, the same.');
    $this->assertSame([
      'tracking' => 'A tracking code is two capitals and six digits, such as AB123456. This applies when all 3 of these hold: Reference is set; Service is Express; Insured is off.',
    ], $refusals(['reference' => 'R1', 'service' => 'express', 'tracking' => 'nope']));

    // The third fresh-eyes run's sender: a name once an email is given.
    $resources = $this->container->get('data_surface.resources');
    $sender = $resources->build(SenderIdentityStructure::class, new OperationContext('configure'));
    $said = function (array $values) use ($sender): array {
      $messages = [];
      foreach ($this->pipeline()->validate($sender, $this->pipeline()->accept($sender, $values, []), []) as $violation) {
        $messages[$violation->fullPath()] = strip_tags((string) $violation->message);
      }
      return $messages;
    };
    $this->assertSame(['name' => 'Name is required when Email is set.'], $said(['email' => 'digest@example.com']));
    $this->assertSame([], $said(['email' => 'digest@example.com', 'name' => 'Digest']));
    $this->assertSame(['signature' => 'Signature does not apply when Email is empty.'], $said(['signature' => 'Ann']));
  }

  /**
   * Tests a rule that would widen, or that no schema can state, is refused.
   */
  public function testWhatRulesMayNotSayIsRefusedAtBuild(): void {
    $refusals = [
      WideningRuleStructure::class => 'narrow("count") in resource_test.broken.widening_rule widens what the key declares: the max of the Range constraint moved from 10 to 20.',
      UnofferedRuleValueStructure::class => 'narrow("kind") in resource_test.broken.unoffered_rule_value widens what the key declares: the LabeledChoice constraint gained the values z',
      MistypedConditionStructure::class => 'narrow("name") in resource_test.broken.mistyped_condition compares "count", whose type is integer, with \'5\', which it never holds.',
      UnconditionalRuleStructure::class => 'narrow("name") in resource_test.broken.unconditional_rule says no ->when().',
      RequiresRuledOutStructure::class => 'narrow("detail") in resource_test.broken.requires_ruled_out requires "detail", which a #[RefinesInput] method may answer does not apply.',
    ];
    foreach ($refusals as $class => $message) {
      try {
        $this->container->get('data_surface.resources')->build($class, new OperationContext('configure'));
        $this->fail($class . ' was built.');
      }
      catch (\LogicException $e) {
        $this->assertStringContainsString($message, $e->getMessage(), $class);
      }
    }
  }

  /**
   * Tests the vocabulary is closed when a rule is written.
   */
  public function testTheVocabularyIsClosed(): void {
    $cases = [
      'a constraint outside it' => [
        static fn () => (new Rule('name'))->constrain('Email', []),
        'which a rule cannot state',
      ],
      'an option outside it' => [
        static fn () => (new Rule('count'))->constrain('Range', ['max' => 5, 'maxMessage' => 'No.']),
        'takes min, max and message, and was given max, maxMessage',
      ],
      'a pattern no schema states exactly' => [
        static fn () => (new Rule('code'))->constrain('Regex', ['pattern' => '/^\w+$/i']),
        'no JSON Schema can state exactly',
      ],
      'an effect beside does not apply' => [
        static fn () => (new Rule('note'))->doesNotApply()->require(),
        'after doesNotApply()',
      ],
      'does not apply beside an effect' => [
        static fn () => (new Rule('note'))->require()->doesNotApply(),
        'beside another effect',
      ],
      'a second condition' => [
        static fn () => (new Rule('note'))->when(Condition::present('a'))->when(Condition::absent('b')),
        'a second condition',
      ],
      'one of nothing' => [
        static fn () => Condition::in('kind', []),
        'at least one value',
      ],
    ];
    foreach ($cases as $label => [$call, $message]) {
      try {
        $call();
        $this->fail($label . ' was accepted.');
      }
      catch (\LogicException | \InvalidArgumentException $e) {
        $this->assertStringContainsString($message, $e->getMessage(), $label);
      }
    }
  }

  /**
   * Tests each condition's `if`, as the full schema writes it.
   */
  public function testEachConditionIsOneIf(): void {
    $cases = [
      '{"properties": {"k": {"not": {"type": "null"}}}, "required": ["k"]}' => Condition::present('k'),
      '{"properties": {"k": {"type": "null"}}}' => Condition::absent('k'),
      '{"properties": {"k": {"const": "a"}}, "required": ["k"]}' => Condition::equals('k', 'a'),
      '{"properties": {"k": {"enum": ["a", "b"]}}, "required": ["k"]}' => Condition::in('k', ['a', 'b']),
      '{"properties": {"k": {"type": "number", "minimum": 1}}, "required": ["k"]}' => Condition::atLeast('k', 1),
      '{"properties": {"k": {"type": "number", "maximum": 1}}, "required": ["k"]}' => Condition::atMost('k', 1),
      '{"properties": {"k": {"type": "number", "exclusiveMinimum": 1}}, "required": ["k"]}' => Condition::above('k', 1),
      '{"properties": {"k": {"type": "number", "exclusiveMaximum": 1}}, "required": ["k"]}' => Condition::below('k', 1),
      '{"not": {"properties": {"k": {"type": "null"}}}}' => Condition::not(Condition::absent('k')),
      '{"allOf": [{"properties": {"k": {"type": "null"}}}]}' => Condition::allOf(Condition::absent('k')),
      '{"anyOf": [{"properties": {"k": {"type": "null"}}}]}' => Condition::anyOf(Condition::absent('k')),
    ];
    foreach ($cases as $json => $condition) {
      $this->assertSame(self::json($json), $condition->schema(), $json);
    }
    $mounted = '{"properties": {"m": {"properties": {"k": {"const": 1}}, "required": ["k"]}}, "required": ["m"]}';
    $this->assertSame(self::json($mounted), Condition::equals('k', 1)->schema(['k' => ['m', 'k']]), 'A key inside a map.');
  }

  /**
   * Tests the full schema states each rule as written, and agrees.
   */
  public function testTheFullSchemaStatesEachRuleAsWritten(): void {
    $parcel = $this->parcel();
    $emitter = $this->container->get('data_surface.contract_emitter');
    $full = json_decode((string) json_encode($emitter->emitFull($parcel, 'resource_test.parcel', 'configure')->document['schema']), TRUE);
    $this->assertSame(['service', 'weight'], $full['required'], 'The note may not apply, so it is required only where it does.');
    $declared = [
      'service',
      'declared_value',
      'signature',
      'tracking',
      'handling',
      'labels',
      'note',
      'third_party_settings',
    ];
    foreach ($declared as $key) {
      $this->assertTrue($full['properties'][$key]['x-surface']['declared'] ?? FALSE, $key . ' is declared.');
      $this->assertArrayNotHasKey('refine', $full['properties'][$key]['x-surface'], $key . ' is exact.');
    }
    $this->assertArrayNotHasKey('required', $full['properties']['third_party_settings']['properties']['data_surface_resource_test']);
    // Listed by the frame, none marked on the conditional: no x-surface
    // below an applicator. Decision: see docs/decisions.md#contract-2.
    $this->assertSame(array_keys($full['allOf']), $full['x-surface']['rules'], 'Every conditional here is a rule\'s.');
    foreach ($full['allOf'] as $condition) {
      $this->assertArrayNotHasKey('x-surface', $condition);
    }
    $stated = $full['allOf'];
    $expected = [
      'equals, require, describe' => <<<'JSON'
        {"if": {"properties": {"insured": {"const": true}}, "required": ["insured"]},
         "then": {"properties": {"signature": {"description": "An insured parcel is signed for.", "type": "string"}},
                  "required": ["signature"]}}
        JSON,
      'at least, a subset by Choice' => <<<'JSON'
        {"if": {"properties": {"weight": {"type": "number", "minimum": 30}}, "required": ["weight"]},
         "then": {"properties": {"service": {"oneOf": [{"const": "freight", "title": "Freight"}]}}}}
        JSON,
      'in, a subset by LabeledChoice' => <<<'JSON'
        {"if": {"properties": {"service": {"enum": ["standard", "express"]}}, "required": ["service"]},
         "then": {"properties": {"handling": {"oneOf": [
           {"const": "fragile", "title": "Fragile"}, {"const": "upright", "title": "This way up"}, {"const": null}]}}}}
        JSON,
      'at most, Count' => <<<'JSON'
        {"if": {"properties": {"weight": {"type": "number", "maximum": 5}}, "required": ["weight"]},
         "then": {"properties": {"labels": {"maxItems": 1}}}}
        JSON,
      'below, Range, describe' => <<<'JSON'
        {"if": {"properties": {"weight": {"type": "number", "exclusiveMaximum": 2}}, "required": ["weight"]},
         "then": {"properties": {"declared_value": {"description": "Up to 500 for a parcel under 2 kilograms.", "maximum": 500}}}}
        JSON,
      'equals, Range, said only where it tightens' => <<<'JSON'
        {"if": {"properties": {"insured": {"const": true}}, "required": ["insured"]},
         "then": {"properties": {"declared_value": {"description": "Up to 2000 for an insured parcel.", "maximum": 2000}}}}
        JSON,
      'absent, Range' => <<<'JSON'
        {"if": {"properties": {"reference": {"type": "null"}}},
         "then": {"properties": {"declared_value": {"maximum": 5000}}}}
        JSON,
      'above, Length' => <<<'JSON'
        {"if": {"properties": {"declared_value": {"type": "number", "exclusiveMinimum": 1000}}, "required": ["declared_value"]},
         "then": {"properties": {"signature": {"minLength": 2}}}}
        JSON,
      'not, does not apply' => <<<'JSON'
        {"if": {"not": {"properties": {"service": {"const": "freight"}}, "required": ["service"]}},
         "then": {"properties": {"note": false}}}
        JSON,
      // A rule declared with unless() rules the note out where the
      // service is not freight: required wherever it applies states the
      // condition itself, not its double negation.
      'required wherever it applies' => <<<'JSON'
        {"if": {"properties": {"service": {"const": "freight"}}, "required": ["service"]},
         "then": {"required": ["note"]}}
        JSON,
      'all of, any of, present, a pattern' => <<<'JSON'
        {"if": {"allOf": [
           {"properties": {"reference": {"not": {"type": "null"}}}, "required": ["reference"]},
           {"anyOf": [
             {"properties": {"service": {"const": "express"}}, "required": ["service"]},
             {"properties": {"insured": {"const": true}}, "required": ["insured"]}]}]},
         "then": {"properties": {"tracking": {"pattern": "^[A-Z]{2}\\d{6}$"}}}}
        JSON,
      'the alter\'s own key, required only where no rule rules it out' => <<<'JSON'
        {"if": {"allOf": [
           {"properties": {"service": {"const": "freight"}}, "required": ["service"]},
           {"not": {"properties": {"weight": {"type": "number", "exclusiveMaximum": 2}}, "required": ["weight"]}}]},
         "then": {"properties": {"third_party_settings": {"properties": {"data_surface_resource_test": {
           "properties": {"customs": {"type": "string"}}, "required": ["customs"]}}}}}}
        JSON,
      'the alter\'s own key, ruled out' => <<<'JSON'
        {"if": {"properties": {"weight": {"type": "number", "exclusiveMaximum": 2}}, "required": ["weight"]},
         "then": {"properties": {"third_party_settings": {"properties": {"data_surface_resource_test": {
           "properties": {"customs": false}}}}}}}
        JSON,
      'the owner\'s key, read by the alter\'s' => <<<'JSON'
        {"if": {"properties": {"third_party_settings": {
           "properties": {"data_surface_resource_test": {
             "properties": {"customs": {"not": {"type": "null"}}}, "required": ["customs"]}},
           "required": ["data_surface_resource_test"]}},
           "required": ["third_party_settings"]},
         "then": {"properties": {"declared_value": {"minimum": 1}}}}
        JSON,
    ];
    // Less the declared type each narrowing states beside a keyword one
    // type reads, which narrows nothing. Decision: see
    // docs/decisions.md#contract-2.
    $stated = array_map(self::untyped(...), $stated);
    foreach ($expected as $label => $json) {
      $this->assertContains(self::untyped(self::json($json)), $stated, $label);
    }
    $this->assertCount(count($expected), $stated, 'Nothing else is stated.');

    $compared = $this->assertStaticRefinersAgree($parcel, NULL, 'resource_test.parcel');
    $this->assertGreaterThan(50, $compared, 'Every rule agrees with the pipeline for sampled values.');
  }

  /**
   * Tests the schema for these answers is refined by the rules.
   */
  public function testTheSchemaForTheseAnswersEvaluatesTheRules(): void {
    $emitter = $this->container->get('data_surface.contract_emitter');
    $values = ['service' => 'express', 'weight' => 1, 'insured' => TRUE, 'reference' => 'R1'];
    $document = json_decode((string) json_encode($emitter->emit($this->parcel(), $values, 'resource_test.parcel', 'configure')->document), TRUE);
    $schema = $document['schema'];
    $this->assertArrayNotHasKey('allOf', $schema, 'No rule is stated: each was evaluated for these values.');
    $this->assertSame(500, $schema['properties']['declared_value']['maximum']);
    $this->assertSame(['service', 'weight', 'signature'], $schema['required']);
    $this->assertSame('^[A-Z]{2}\d{6}$', $schema['properties']['tracking']['pattern']);
    $this->assertSame(['note' => ['service']], $schema['x-surface']['doesNotApply']);
    $this->assertArrayNotHasKey('note', $schema['properties']);
    $extension = $schema['properties']['declared_value']['x-surface'];
    $this->assertArrayNotHasKey('declared', $extension);
    $this->assertTrue($extension['refined']);
    $this->assertSame(['weight', 'reference', 'insured', self::CUSTOMS], $extension['dependsOn']);
  }

  /**
   * Tests the tool lists a key a rule may rule out, or require, as optional.
   */
  public function testTheToolListing(): void {
    $tools = $this->container->get('plugin.manager.tool');
    $tool = $tools->createInstance('data_surface:resource_test.parcel:configure');
    $values = $tool->getInputDefinition(OperationInputs::VALUES);
    $this->assertFalse($values->getPropertyDefinition('note')->isRequired(), 'Required where it applies, which a listing cannot say.');
    $this->assertFalse($values->getPropertyDefinition('signature')->isRequired(), 'Required for an insured parcel only.');
    // An update: the weight the resource requires is said in words, and a
    // stored one stands when left out.
    $this->assertFalse($values->getPropertyDefinition('weight')->isRequired());
    $this->assertStringEndsWith('Required; a value already stored stands when left out.', (string) $values->getPropertyDefinition('weight')->getDescription());

    $parcel = ['service' => 'standard', 'weight' => 1, 'insured' => TRUE];
    $tool->setInputValue(OperationInputs::VALUES, $parcel);
    $tool->execute();
    $this->assertFalse($tool->getResult()->isSuccess());
    $this->assertStringContainsString('signature: Signed for by is required when Insured is on and Declared value is 100.', (string) $tool->getResult()->getMessage());

    $tool = $tools->createInstance('data_surface:resource_test.parcel:configure');
    $tool->setInputValue(OperationInputs::VALUES, $parcel + ['signature' => 'Ann', 'declared_value' => 400]);
    $tool->execute();
    $this->assertTrue($tool->getResult()->isSuccess(), (string) $tool->getResult()->getMessage());
    $this->assertSame('Ann', $this->config('data_surface_resource_test.parcel')->get('signature'));
  }

  /**
   * Leaves out every `type` a schema states, at any depth.
   *
   * @param array $schema
   *   The schema.
   *
   * @return array
   *   The same, with no `type`.
   */
  protected static function untyped(array $schema): array {
    unset($schema['type']);
    foreach ($schema as $keyword => $value) {
      if (is_array($value)) {
        $schema[$keyword] = self::untyped($value);
      }
    }
    return $schema;
  }

}
