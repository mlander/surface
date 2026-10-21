<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Pipeline\ResultDocument;
use Drupal\data_surface\Refinement\Rule;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface_resource_test\Structure\Kiosk\KioskStructure;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests a declared rule's message on a Range, a Length and a Count.
 *
 * One `message` option on the rule, written under Symfony's option for
 * each bound the rule's own stands for, and said by the form and the tool
 * when that bound refuses a value; a bound the key already held keeps the
 * key's message. Neither document carries it.
 *
 * @see docs/decisions.md#a-rule-declared-as-data
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class RuleMessageTest extends DataSurfaceKernelTestBase {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'serialization',
    'data_surface',
    'data_surface_resource_test',
  ];

  /**
   * Builds the kiosk.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The kiosk.
   */
  protected function kiosk(): DataSurfaceInterface {
    return $this->container->get('data_surface.resources')->build(KioskStructure::class, new OperationContext('configure'));
  }

  /**
   * Validates values against the kiosk, by key, rendered for both doors.
   *
   * @param array $values
   *   The values, over the kiosk's defaults.
   *
   * @return array<string, array{0: string, 1: string}>
   *   Each refused key's message, for a person and for a machine.
   */
  protected function refusals(array $values): array {
    $kiosk = $this->kiosk();
    $refusals = [];
    foreach ($this->pipeline()->validate($kiosk, $values + $kiosk->getDefaultValues()) as $violation) {
      $refusals[$violation->key] = [
        ResultDocument::message($violation, FALSE),
        ResultDocument::message($violation, TRUE),
      ];
    }
    return $refusals;
  }

  /**
   * Tests each bound's rule refuses in its own words, at both doors.
   */
  public function testTheRuleSaysItsMessage(): void {
    $refusals = $this->refusals(['licensed' => FALSE, 'capacity' => 120]);
    $this->assertStringStartsWith('From 1 to 100 without a license.', $refusals['capacity'][0], 'The form says the rule\'s message.');
    $this->assertStringStartsWith('From 1 to 100 without a license.', $refusals['capacity'][1], 'The tool says it too.');
    $this->assertStringContainsString('This applies when', $refusals['capacity'][0], 'The condition follows it.');

    $refusals = $this->refusals(['licensed' => TRUE, 'code' => 'ab', 'days' => []]);
    $this->assertStringStartsWith('A licensed code is four characters or more.', $refusals['code'][0]);
    $this->assertStringStartsWith('Choose a day while licensed.', $refusals['days'][0]);
    $this->assertStringStartsWith('Choose a day while licensed.', $refusals['days'][1]);

    $refusals = $this->refusals(['licensed' => FALSE, 'code' => 'ab', 'days' => [], 'capacity' => 50]);
    $this->assertSame([], $refusals, 'Where the rules do not hold, nothing is refused.');
  }

  /**
   * Tests a bound the key already held keeps the key's message.
   */
  public function testTheKeysOwnBoundKeepsItsMessage(): void {
    $refusals = $this->refusals(['licensed' => FALSE, 'queue' => 11]);
    $this->assertStringStartsWith('A queue holds ten at most.', $refusals['queue'][0]);
  }

  /**
   * Tests where the message is written, tightening and as written.
   */
  public function testWhereTheMessageIsWritten(): void {
    $rule = (new Rule('capacity'))->constrain('Range', ['max' => 100, 'message' => 'Up to 100.']);
    $range = $rule->apply(DataDefinition::create('integer')->addConstraint('Range', ['min' => 1, 'max' => 1000]))->getConstraints()['Range'];
    $this->assertSame(['min' => 1, 'max' => 100, 'notInRangeMessage' => 'Up to 100.'], $range, 'A range holding both bounds reports in one message, and Symfony refuses the other two beside it.');
    $below = $rule->apply(DataDefinition::create('integer'))->getConstraints()['Range'];
    $this->assertSame(['max' => 100, 'maxMessage' => 'Up to 100.'], $below, 'A maximum alone is said in its own message; Symfony\'s Range has no `message`.');

    // A bound the key holds tighter keeps the key's words; as written, the
    // build's check reads the rule's.
    $held = DataDefinition::create('integer')->addConstraint('Range', ['max' => 50, 'maxMessage' => 'Fifty.']);
    $this->assertSame(['max' => 50, 'maxMessage' => 'Fifty.'], $rule->apply(clone $held)->getConstraints()['Range']);
    $this->assertSame('Up to 100.', $rule->applyAsWritten(clone $held)->getConstraints()['Range']['maxMessage']);

    $length = (new Rule('code'))->constrain('Length', ['min' => 4, 'message' => 'Four.'])
      ->apply(DataDefinition::create('string'))->getConstraints()['Length'];
    $this->assertSame(['min' => 4, 'minMessage' => 'Four.', 'exactMessage' => 'Four.'], $length);

    $count = (new Rule('days'))->constrain('Count', ['min' => 1, 'max' => 3, 'message' => $this->t('One to three.')])
      ->apply(new ListDataDefinition([], DataDefinition::create('string')))->getConstraints()['Count'];
    $expected = [
      'min' => 1,
      'max' => 3,
      'minMessage' => 'One to three.',
      'maxMessage' => 'One to three.',
      'exactMessage' => 'One to three.',
    ];
    $this->assertSame($expected, $count, 'A translatable message is handed to the validator as its source.');
  }

  /**
   * Tests neither document carries the message: the bound says itself.
   */
  public function testTheDocumentsStateTheBound(): void {
    $kiosk = $this->kiosk();
    $emitter = $this->container->get('data_surface.contract_emitter');
    $full = json_decode((string) json_encode($emitter->emitFull($kiosk, 'resource_test.kiosk')->document['schema']), TRUE);
    $thens = array_column($full['allOf'], 'then');
    $this->assertContains(['properties' => ['capacity' => ['maximum' => 100]]], $thens);
    $this->assertStringNotContainsString('without a license', (string) json_encode($full));
    $served = json_decode((string) json_encode($emitter->emit($kiosk, ['licensed' => FALSE], 'resource_test.kiosk')->document), TRUE);
    $this->assertSame(100, $served['schema']['properties']['capacity']['maximum']);
    $this->assertStringNotContainsString('without a license', (string) json_encode($served));
  }

  /**
   * Tests what a bound's message refuses.
   */
  public function testWhatItRefuses(): void {
    foreach ([
      'a message and no bound' => [
        static fn () => (new Rule('count'))->constrain('Range', ['message' => 'No.']),
        'was given a message and no bound',
      ],
      'a message that says nothing' => [
        static fn () => (new Rule('count'))->constrain('Count', ['max' => 2, 'message' => '']),
        'takes a message that says something',
      ],
      'Symfony\'s own option names' => [
        static fn () => (new Rule('count'))->constrain('Length', ['max' => 2, 'maxMessage' => 'No.']),
        'takes min, max and message, and was given max, maxMessage',
      ],
    ] as $label => [$call, $message]) {
      try {
        $call();
        $this->fail($label . ' was accepted.');
      }
      catch (\InvalidArgumentException $e) {
        $this->assertStringContainsString($message, $e->getMessage(), $label);
      }
    }
  }

}
