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
    'data_surface_examples_workflow',
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

    $this->assertSame(array_column($current, 'module', 'resource'), array_column($committed, 'module', 'resource'), 'Each baseline records the module declaring it.');

    $failing = array_filter($baselines->compare($committed, $current), static fn (ContractChange $change): bool => $change->fails());
    $this->assertSame([], array_map(static fn (ContractChange $change): string => sprintf('%s %s: %s', $change->surface, $change->path, $change->change), array_values($failing)), 'A breaking change raises its owner\'s version, or its baseline allows it.');
  }

  /**
   * Tests a breaking change needs a version raised or an allowance.
   *
   * Each verdict, on a real baseline: the first example's capacity bound
   * tightened, and the contact structure's phone removed inside the third
   * example's document, where only the contact's own version answers for
   * it.
   */
  public function testBreakingChangeNeedsVersionOrAllowance(): void {
    $baselines = $this->container->get('data_surface.contract_baselines');
    $committed = $baselines->read($baselines->directory('data_surface'));
    $current = $baselines->emit(self::PINNED);

    $tightened = $current;
    $tightened['registration.step1']['documents'][0]['schema']['properties']['capacity']['maximum'] = 500;
    [$change] = $this->changes($baselines->compare($committed, $tightened));
    $this->assertSame('configure#/properties/capacity/maximum', $change->path);
    $this->assertSame('maximum lowered from 1000 to 500', $change->change);
    $this->assertSame(ContractChange::BREAKING, $change->status);

    $raised = $tightened;
    $raised['registration.step1']['documents'][0]['schema']['x-surface']['version'] = 2;
    $statuses = array_map(static fn (ContractChange $change): string => $change->change . ': ' . $change->status, $this->changes($baselines->compare($committed, $raised)));
    $this->assertSame(['maximum lowered from 1000 to 500: versioned', 'version raised from 1 to 2: compatible'], $statuses);

    $allowing = $committed;
    $allowing['registration.step1']['allowed'] = [
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
   * Tests a required key added is compatible, with a note.
   *
   * Nothing stored or sent before held it, so the lint passes it and says
   * what it asks of a write; a default answers for it. An optional key
   * made required stays breaking.
   */
  public function testRequiredKeyAddedOnlyNotes(): void {
    $baselines = $this->container->get('data_surface.contract_baselines');
    $committed = $baselines->read($baselines->directory('data_surface'));
    $current = $baselines->emit(self::PINNED);

    $added = $current;
    $schema = &$added['registration.step2']['documents'][0]['schema'];
    $schema['properties']['organizer'] = ['title' => 'Organizer', 'type' => 'string'];
    $schema['required'][] = 'organizer';
    [$change] = array_values(array_filter($baselines->compare($committed, $added), static fn (ContractChange $change): bool => str_ends_with($change->path, '/organizer')));
    $this->assertSame('configure#/properties/organizer', $change->path);
    $this->assertSame('required key added: a write must now supply organizer where nothing is stored', $change->change);
    $this->assertSame(['note', ContractChange::COMPATIBLE], [$change->effect, $change->status]);

    $schema['properties']['organizer']['default'] = 'The events team';
    [$change] = array_values(array_filter($baselines->compare($committed, $added), static fn (ContractChange $change): bool => str_ends_with($change->path, '/organizer')));
    $this->assertSame('required key added, with a default', $change->change);
    $this->assertSame(ContractChange::COMPATIBLE, $change->status);
    unset($schema);

    $made = $current;
    $made['registration.step2']['documents'][0]['schema']['required'][] = 'capacity';
    [$change] = $this->changes($baselines->compare($committed, $made));
    $this->assertSame(['capacity made required', ContractChange::BREAKING], [$change->change, $change->status]);
  }

  /**
   * Tests both documents stamp the version.
   *
   * At the top always, under `x-surface` after the contract's own version
   * and how the operation behaves; on a def only above 1.
   */
  public function testTheVersionIsStamped(): void {
    $emitter = $this->container->get('data_surface.contract_emitter');
    $resources = $this->container->get('data_surface.resources');
    $built = $resources->buildOperation(RegistrationStep1Resource::class, 'configure');
    $this->assertSame(1, $built->version());
    $full = $emitter->emitFull($built, 'registration.step1', 'configure', 'Configure', operation: ['id' => 'configure'])->document['schema'];
    $this->assertSame(['contract', 'operation', 'version'], array_keys($full['x-surface']));
    $this->assertSame(['contract' => 2, 'version' => 1], $emitter->emit($built, [], 'registration.step1')->document['schema']['x-surface']);

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
      $this->assertSame(['contract' => 2, 'version' => 3], $schema['x-surface']);
      $this->assertSame(['version' => 2], $schema['$defs']['versioned.later']['x-surface']);
      $this->assertArrayNotHasKey('x-surface', $schema['$defs']['versioned.first'], 'A def at 1 says nothing.');
    }
  }

  /**
   * Tests baselines of another contract version are re-pinned, not compared.
   *
   * Every keyword of a contract 1 baseline may be spelled anew in contract
   * 2, so the lint says so once, for every such baseline, and fails until
   * the baselines are written again; it never reports a breaking change
   * per keyword, and writing needs no reason.
   */
  public function testAnotherContractVersionIsSaidOnce(): void {
    $baselines = $this->container->get('data_surface.contract_baselines');
    $current = $baselines->emit(self::PINNED);
    $old = [];
    foreach ($baselines->read($baselines->directory('data_surface')) as $id => $baseline) {
      $this->assertSame(2, $baseline['contract'], $id . ' is pinned in contract 2.');
      // As contract 1 spelled it.
      $documents = [];
      foreach ($baseline['documents'] as $document) {
        unset($document['schema']['x-surface']['contract']);
        $keys = ['surface' => $document['resource'], 'situation' => $document['operation']];
        $documents[] = $keys + array_diff_key($document, ['resource' => TRUE, 'operation' => TRUE]);
      }
      $old[$id] = ['surface' => $id, 'module' => $baseline['module'], 'documents' => $documents];
    }
    $changes = $baselines->compare($old, $current);
    $this->assertCount(1, $changes);
    [$change] = $changes;
    $said = [$change->surface, $change->path, $change->change, $change->status];
    $this->assertSame(['*', '#', 'contract version 1 to 2', ContractChange::CONTRACT], $said);
    $this->assertTrue($change->fails());
    $this->assertFalse($change->needsAllowance());
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
