<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\data_surface\Attachment;
use Drupal\data_surface\Contract\ContractChange;
use Drupal\data_surface\DataSurfaceBuilder;
use Drupal\data_surface_examples\Resource\RegistrationStep1Resource;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the examples' and the first module's contracts against baselines.
 *
 * The additive-diff lint, as a test any module can copy: every resource
 * and structure the three modules declare has its full schema committed
 * under tests/fixtures/contracts/, and today's emission may differ from
 * it only compatibly, or by a breaking change with its owner's version
 * raised or an allowance recorded. Any other breaking change fails here;
 * `drush data_surface:contract-diff --write` pins a change once it is
 * accepted.
 *
 * @see docs/evolution.md
 * @see \Drupal\data_surface\Contract\ContractDiff
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class ContractBaselineTest extends DataSurfaceKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'serialization',
    'tool',
    'data_surface',
    'data_surface_tool',
    'data_surface_examples',
    'data_surface_examples_compliance',
    'data_surface_first',
  ];

  /**
   * The modules whose declarations are pinned.
   */
  protected const PINNED = [
    'data_surface_examples',
    'data_surface_examples_compliance',
    'data_surface_first',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['data_surface_examples', 'data_surface_first']);
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Tests every declaration keeps the promise its baseline made.
   */
  public function testEveryDeclarationKeepsItsBaseline(): void {
    $baselines = $this->container->get('data_surface.contract_baselines');
    $current = $baselines->emit(self::PINNED);
    $committed = $baselines->read($baselines->directory('data_surface'));
    $this->assertSame(array_keys($committed), array_keys($current), 'Every declaration is pinned, and every baseline still declared.');
    $this->assertSame([
      'event.bulletin',
      'event.desk',
      'event.doors',
      'event.fees',
      'event.person',
      'event.press',
      'event.program',
      'event.review',
      'event.sales',
      'event.ticket_limit',
      'first.newsletter',
      'first.store',
      'registration.compliance',
      'registration.contact',
      'registration.step1',
      'registration.step2',
      'registration.step3',
      'registration.step3.ticket.free',
      'registration.step3.ticket.paid',
    ], array_keys($current));

    $failing = array_filter($baselines->compare($committed, $current), static fn (ContractChange $change): bool => $change->fails());
    $this->assertSame([], array_map(static fn (ContractChange $change): string => sprintf('%s %s: %s', $change->surface, $change->path, $change->change), array_values($failing)), 'A breaking change raises its owner\'s version, or its baseline allows it.');
  }

  /**
   * Tests a breaking change needs a version raised or an allowance.
   *
   * Each verdict, on a real baseline: the second example's capacity bound
   * tightened, and the contact structure's phone removed inside the third
   * example's document, where only the contact's own version answers for
   * it.
   */
  public function testBreakingChangeNeedsVersionOrAllowance(): void {
    $baselines = $this->container->get('data_surface.contract_baselines');
    $committed = $baselines->read($baselines->directory('data_surface'));
    $current = $baselines->emit(self::PINNED);

    $tightened = $current;
    $tightened['registration.step2']['documents'][0]['schema']['properties']['capacity']['maximum'] = 500;
    [$change] = $this->changes($baselines->compare($committed, $tightened));
    $this->assertSame('configure#/properties/capacity/maximum', $change->path);
    $this->assertSame('maximum lowered from 1000 to 500', $change->change);
    $this->assertSame(ContractChange::BREAKING, $change->status);

    $raised = $tightened;
    $raised['registration.step2']['documents'][0]['schema']['x-surface']['version'] = 2;
    $statuses = array_map(static fn (ContractChange $change): string => $change->change . ': ' . $change->status, $this->changes($baselines->compare($committed, $raised)));
    $this->assertSame(['maximum lowered from 1000 to 500: versioned', 'version raised from 1 to 2: compatible'], $statuses);

    $allowing = $committed;
    $allowing['registration.step2']['allowed'] = [
      ['path' => 'configure#/properties/capacity/maximum', 'reason' => 'No venue seats more than 500.'],
    ];
    [$change] = $this->changes($baselines->compare($allowing, $tightened));
    $this->assertSame(ContractChange::ALLOWED, $change->status);

    $removed = $current;
    $contact = &$removed['registration.step3']['documents'][0]['schema']['$defs']['registration.contact'];
    unset($contact['properties']['phone']);
    $removed['registration.step3']['documents'][0]['schema']['x-surface']['version'] = 2;
    [$change] = array_values(array_filter($this->changes($baselines->compare($committed, $removed)), static fn (ContractChange $change): bool => $change->owner === 'registration.contact'));
    $this->assertSame('configure#/$defs/registration.contact/properties/phone', $change->path);
    $this->assertSame(ContractChange::BREAKING, $change->status, 'The container\'s version does not answer for a structure it holds.');
    $contact['x-surface'] = ['version' => 2];
    [$change] = array_values(array_filter($this->changes($baselines->compare($committed, $removed)), static fn (ContractChange $change): bool => $change->owner === 'registration.contact' && $change->effect !== 'note'));
    $this->assertSame(ContractChange::VERSIONED, $change->status);
  }

  /**
   * Tests both documents stamp the version.
   *
   * At the top always, first under `x-surface` after how the operation
   * behaves; on a def only above 1.
   */
  public function testTheVersionIsStamped(): void {
    $emitter = $this->container->get('data_surface.contract_emitter');
    $resources = $this->container->get('data_surface.resources');
    $built = $resources->buildOperation(RegistrationStep1Resource::class, 'configure');
    $this->assertSame(1, $built->version());
    $full = $emitter->emitFull($built, 'registration.step1', 'configure', 'Configure', operation: ['id' => 'configure'])->document['schema'];
    $this->assertSame(['operation', 'version'], array_keys($full['x-surface']));
    $this->assertSame(['version' => 1], $emitter->emit($built, [], 'registration.step1')->document['schema']['x-surface']);

    $later = new DataSurfaceBuilder();
    $later->setDefinition('depth', DataDefinition::create('integer'));
    $later->setVersion(2);
    $first = new DataSurfaceBuilder();
    $first->setDefinition('name', DataDefinition::create('string'));
    $root = new DataSurfaceBuilder();
    $root->attach('later', new Attachment($later->seal(), NULL, 'versioned.later'));
    $root->attach('first', new Attachment($first->seal(), NULL, 'versioned.first'));
    $root->setVersion(3);
    $surface = $root->seal();
    foreach ([
      $emitter->emitFull($surface, 'versioned')->document['schema'],
      $emitter->emit($surface, $surface->getDefaultValues(), 'versioned')->document['schema'],
    ] as $schema) {
      $this->assertSame(['version' => 3], $schema['x-surface']);
      $this->assertSame(['version' => 2], $schema['$defs']['versioned.later']['x-surface']);
      $this->assertArrayNotHasKey('x-surface', $schema['$defs']['versioned.first'], 'A def at 1 says nothing.');
    }
  }

  /**
   * Leaves out what only notes.
   *
   * @param \Drupal\data_surface\Contract\ContractChange[] $changes
   *   The changes.
   *
   * @return \Drupal\data_surface\Contract\ContractChange[]
   *   The ones whose effect is more than a note, and the version raised.
   */
  protected function changes(array $changes): array {
    return array_values(array_filter($changes, static fn (ContractChange $change): bool => $change->effect !== 'note' || str_starts_with($change->change, 'version')));
  }

}
