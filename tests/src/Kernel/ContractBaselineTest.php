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
 * Tests the examples', the first module's and the blocks' contracts.
 *
 * The additive-diff lint, as a test any module can copy: every resource
 * and structure the five modules declare has its full schema committed
 * under tests/fixtures/contracts/, and today's emission may differ from
 * it only compatibly, or by a breaking change with its owner's version
 * raised or an allowance recorded. Any other breaking change fails here;
 * `drush data_surface:contract-diff --write` pins a change once it is
 * accepted. Run with DATA_SURFACE_CONTRACT_WRITE=1, this test writes the
 * baselines instead, from the modules it installs alone: how a
 * declaration whose variants come from the site (example 12's
 * components, and the component block's) is pinned, since a site's own
 * themes would add theirs. Example 14's resources are pinned with the
 * module that manages keys on, its second source and its alter's key in
 * them: one baseline per declaration, so one state.
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
    'block',
    'path_alias',
    'data_surface_component_block',
    'data_surface_block',
    'data_surface_examples_keys',
  ];

  /**
   * The modules whose declarations are pinned.
   */
  protected const PINNED = [
    'data_surface_examples',
    'data_surface_examples_compliance',
    'data_surface_first',
    'data_surface_component_block',
    'data_surface_block',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['data_surface_examples', 'data_surface_first']);
    // A theme, so the placement's themes and regions are pinned.
    $this->container->get('theme_installer')->install(['stark']);
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Tests every declaration keeps the promise its baseline made.
   */
  public function testEveryDeclarationKeepsItsBaseline(): void {
    $baselines = $this->container->get('data_surface.contract_baselines');
    $current = $baselines->emit(self::PINNED);
    $committed = $baselines->read($baselines->directory('data_surface'));
    if (getenv('DATA_SURFACE_CONTRACT_WRITE')) {
      // A declaration whose variants come from the site is pinned where
      // the site holds only these modules: the drush command, run on a
      // site with more, would pin that site's.
      foreach ($current as $id => $baseline) {
        $baselines->write($baselines->directory('data_surface'), $baseline + ['allowed' => $committed[$id]['allowed'] ?? []]);
      }
      $this->markTestSkipped(sprintf('%d baselines written.', count($current)));
    }
    $this->assertSame(array_keys($committed), array_keys($current), 'Every declaration is pinned, and every baseline still declared.');
    $this->assertSame([
      'block.data_surface_component',
      'block.placement',
      'block.placement.condition',
      'event.bulletin',
      'event.desk',
      'event.doors',
      'event.fees',
      'event.mailer',
      'event.mailer.credential.managed',
      'event.mailer.credential.pasted',
      'event.person',
      'event.press',
      'event.program',
      'event.review',
      'event.sales',
      'event.spotlight',
      'event.ticket_limit',
      'event.venue',
      'event.venue.listing',
      'event.venue.order',
      'event.venue.reorder',
      'event.venue.room',
      'event.webhook',
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
   * Tests a required key added is judged per operation, by its kind.
   *
   * Nothing stored or sent before held it. On `configure`, an update, the
   * lint passes it and says what it asks of a write, since a key left out
   * keeps what is stored; on a create of the same declaration (an `add`
   * document beside it, as `node.type` has) every old create leaves it
   * out and is refused, so it is breaking there, and an allowance with a
   * reason answers it. A default answers for both. An optional key made
   * required stays breaking.
   */
  public function testRequiredKeyAddedIsJudgedPerOperation(): void {
    $baselines = $this->container->get('data_surface.contract_baselines');
    $committed = $baselines->read($baselines->directory('data_surface'));
    $current = $baselines->emit(self::PINNED);

    // The same declaration in a create beside its update.
    $create = static function (array $document): array {
      $document['operation'] = 'add';
      $document['schema']['x-surface']['operation'] = ['id' => 'add', 'kind' => 'create', 'creates' => TRUE] + $document['schema']['x-surface']['operation'];
      return $document;
    };
    $committed['registration.step2']['documents'][] = $create($committed['registration.step2']['documents'][0]);
    $current['registration.step2']['documents'][] = $create($current['registration.step2']['documents'][0]);
    $this->assertSame([], array_values(array_filter($baselines->compare($committed, $current), static fn (ContractChange $change): bool => $change->effect !== 'note')), 'Nothing differs before the key is added.');

    $added = $current;
    foreach ($added['registration.step2']['documents'] as &$document) {
      $document['schema']['properties']['organizer'] = ['title' => 'Organizer', 'type' => 'string'];
      $document['schema']['required'][] = 'organizer';
    }
    unset($document);
    $organizer = static fn (array $changes): array => array_map(
      static fn (ContractChange $change): array => [$change->path, $change->change, $change->effect, $change->status],
      array_values(array_filter($changes, static fn (ContractChange $change): bool => str_ends_with($change->path, '/organizer'))),
    );
    $this->assertSame([
      [
        'configure#/properties/organizer',
        'required key added: a write must now supply organizer where nothing is stored',
        'note',
        ContractChange::COMPATIBLE,
      ],
      [
        'add#/properties/organizer',
        'required key added with no default: a create that leaves organizer out is refused',
        'narrows',
        ContractChange::BREAKING,
      ],
    ], $organizer($baselines->compare($committed, $added)));

    $allowing = $committed;
    $allowing['registration.step2']['allowed'] = [
      ['path' => 'add#/properties/organizer', 'reason' => 'Every caller that creates one names its organizer already.'],
    ];
    $this->assertSame(ContractChange::ALLOWED, $organizer($baselines->compare($allowing, $added))[1][3]);

    foreach ($added['registration.step2']['documents'] as &$document) {
      $document['schema']['properties']['organizer']['default'] = 'The events team';
    }
    unset($document);
    $this->assertSame([
      ['configure#/properties/organizer', 'required key added, with a default', 'note', ContractChange::COMPATIBLE],
      ['add#/properties/organizer', 'required key added, with a default', 'note', ContractChange::COMPATIBLE],
    ], $organizer($baselines->compare($committed, $added)));

    $made = $baselines->emit(self::PINNED);
    $made['registration.step2']['documents'][0]['schema']['required'][] = 'capacity';
    $committed = $baselines->read($baselines->directory('data_surface'));
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
    $this->assertSame(['contract' => 3, 'version' => 1], $emitter->emit($built, [], 'registration.step1')->document['schema']['x-surface']);

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
      $this->assertSame(['contract' => 3, 'version' => 3], $schema['x-surface']);
      $this->assertSame(['version' => 2], $schema['$defs']['versioned.later']['x-surface']);
      $this->assertArrayNotHasKey('x-surface', $schema['$defs']['versioned.first'], 'A def at 1 says nothing.');
    }
  }

  /**
   * Tests baselines of another contract version are re-pinned, not compared.
   *
   * Every keyword of a contract 1 baseline may be spelled anew in a later
   * contract, so the lint says so once, for every such baseline, and fails
   * until the baselines are written again; it never reports a breaking
   * change per keyword, and writing needs no reason.
   */
  public function testAnotherContractVersionIsSaidOnce(): void {
    $baselines = $this->container->get('data_surface.contract_baselines');
    $current = $baselines->emit(self::PINNED);
    $old = [];
    foreach ($baselines->read($baselines->directory('data_surface')) as $id => $baseline) {
      $this->assertSame(3, $baseline['contract'], $id . ' is pinned in contract 3.');
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
    $this->assertSame(['*', '#', 'contract version 1 to 3', ContractChange::CONTRACT], $said);
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
