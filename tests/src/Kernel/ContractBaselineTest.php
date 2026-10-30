<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\surface\Attachment;
use Drupal\surface\Contract\ContractChange;
use Drupal\surface\SurfaceBuilder;
use Drupal\surface_examples\Resource\RegistrationStep1Resource;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
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
 * `drush surface:contract-diff --write` pins a change once it is
 * accepted. Run with SURFACE_CONTRACT_WRITE=1, this test writes the
 * baselines instead, from the modules it installs alone: how a
 * declaration whose variants come from the site (example 12's
 * components, and the component block's) is pinned, since a site's own
 * themes would add theirs. Example 14's resources are pinned with the
 * module that manages keys on, its second source and its alter's key in
 * them: one baseline per declaration, so one state.
 *
 * @see docs/evolution.md
 * @see \Drupal\surface\Contract\ContractDiff
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class ContractBaselineTest extends SurfaceKernelTestBase {

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
    'surface',
    'surface_tool',
    'surface_examples',
    'surface_examples_compliance',
    'surface_examples_workflow',
    'surface_examples_first',
    'block',
    'path_alias',
    'surface_examples_component_block',
    'surface_examples_block',
    'surface_examples_keys',
    'text',
    'filter',
    'node',
    'surface_examples_references',
    'surface_examples_retitle',
  ];

  /**
   * The modules whose declarations are pinned.
   */
  protected const PINNED = [
    'surface_examples',
    'surface_examples_compliance',
    'surface_examples_first',
    'surface_examples_component_block',
    'surface_examples_block',
    'surface_examples_references',
    'surface_examples_retitle',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    // Example 16's bare reference counts what each content entity type
    // holds, and its keys list the site's users, roles and articles: each
    // of a fixed name, so its baseline is the same on every run.
    $this->installEntitySchema('node');
    $this->installEntitySchema('path_alias');
    $this->installSchema('node', ['node_access']);
    $this->installConfig([
      'surface_examples',
      'surface_examples_first',
      'node',
      'filter',
      'surface_examples_references',
      'surface_examples_retitle',
    ]);
    // A theme, so the placement's themes and regions are pinned.
    $this->container->get('theme_installer')->install(['stark']);
    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    User::create(['uid' => 1, 'name' => 'admin', 'status' => 1])->save();
    Role::create(['id' => 'administrator', 'label' => 'Administrator', 'is_admin' => TRUE])->save();
    $sponsor = User::create(['name' => 'sponsor', 'status' => 1, 'roles' => ['administrator']]);
    $sponsor->save();
    $this->setCurrentUser($sponsor);
  }

  /**
   * Tests every declaration keeps the promise its baseline made.
   */
  public function testEveryDeclarationKeepsItsBaseline(): void {
    $baselines = $this->container->get('surface.contract_baselines');
    $current = $baselines->emit(self::PINNED);
    $committed = $baselines->read($baselines->directory('surface'));
    if (getenv('SURFACE_CONTRACT_WRITE')) {
      // A declaration whose variants come from the site is pinned where
      // the site holds only these modules: the drush command, run on a
      // site with more, would pin that site's.
      foreach ($current as $id => $baseline) {
        $baselines->write($baselines->directory('surface'), $baseline + [
          'allowed' => $committed[$id]['allowed'] ?? [],
        ]);
      }
      $this->markTestSkipped(sprintf('%d baselines written.', count($current)));
    }
    $this->assertSame(array_keys($committed), array_keys($current), 'Every declaration is pinned, and every baseline still declared.');
    $this->assertSame([
      'ai.retitle',
      'ai.retitle.item',
      'block.placement',
      'block.placement.condition',
      'block.surface_component',
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
      'event.sponsorship',
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
    $baselines = $this->container->get('surface.contract_baselines');
    $committed = $baselines->read($baselines->directory('surface'));
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
   * keeps what is stored; on a create of the same declaration (an `create`
   * document beside it, as `node.type` has) every old create leaves it
   * out and is refused, so it is breaking there, and an allowance with a
   * reason answers it. A default answers for both. An optional key made
   * required stays breaking.
   */
  public function testRequiredKeyAddedIsJudgedPerOperation(): void {
    $baselines = $this->container->get('surface.contract_baselines');
    $committed = $baselines->read($baselines->directory('surface'));
    $current = $baselines->emit(self::PINNED);

    // The same declaration in a create beside its update.
    $create = static function (array $document): array {
      $document['operation'] = 'create';
      $document['schema']['x-surface']['operation'] = ['id' => 'create', 'kind' => 'create', 'creates' => TRUE] + $document['schema']['x-surface']['operation'];
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
        'create#/properties/organizer',
        'required key added with no default: a create that leaves organizer out is refused',
        'narrows',
        ContractChange::BREAKING,
      ],
    ], $organizer($baselines->compare($committed, $added)));

    $allowing = $committed;
    $allowing['registration.step2']['allowed'] = [
      [
        'path' => 'create#/properties/organizer',
        'reason' => 'Every caller that creates one names its organizer already.',
      ],
    ];
    $this->assertSame(ContractChange::ALLOWED, $organizer($baselines->compare($allowing, $added))[1][3]);

    foreach ($added['registration.step2']['documents'] as &$document) {
      $document['schema']['properties']['organizer']['default'] = 'The events team';
    }
    unset($document);
    $this->assertSame([
      ['configure#/properties/organizer', 'required key added, with a default', 'note', ContractChange::COMPATIBLE],
      ['create#/properties/organizer', 'required key added, with a default', 'note', ContractChange::COMPATIBLE],
    ], $organizer($baselines->compare($committed, $added)));

    $made = $baselines->emit(self::PINNED);
    $made['registration.step2']['documents'][0]['schema']['required'][] = 'capacity';
    $committed = $baselines->read($baselines->directory('surface'));
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
    $emitter = $this->container->get('surface.contract_emitter');
    $resources = $this->container->get('surface.resources');
    $built = $resources->buildOperation(RegistrationStep1Resource::class, 'configure');
    $this->assertSame(1, $built->version());
    $full = $emitter->emitFull($built, 'registration.step1', 'configure', 'Configure', operation: ['id' => 'configure'])->document['schema'];
    $this->assertSame(['contract', 'operation', 'version'], array_keys($full['x-surface']));
    $this->assertSame(['contract' => 4, 'version' => 1], $emitter->emit($built, [], 'registration.step1')->document['schema']['x-surface']);

    $later = new SurfaceBuilder();
    $later->setDefinition('depth', DataDefinition::create('integer'));
    $later->setVersion(2);
    $first = new SurfaceBuilder();
    $first->setDefinition('name', DataDefinition::create('string'));
    $root = new SurfaceBuilder();
    $root->attach('later', new Attachment($later->seal(), NULL, 'versioned.later'));
    $root->attach('first', new Attachment($first->seal(), NULL, 'versioned.first'));
    $root->setVersion(3);
    $surface = $root->seal();
    foreach ([
      $emitter->emitFull($surface, 'versioned')->document['schema'],
      $emitter->emit($surface, $surface->getDefaultValues(), 'versioned')->document['schema'],
    ] as $schema) {
      $this->assertSame(['contract' => 4, 'version' => 3], $schema['x-surface']);
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
    $baselines = $this->container->get('surface.contract_baselines');
    $current = $baselines->emit(self::PINNED);
    $old = [];
    foreach ($baselines->read($baselines->directory('surface')) as $id => $baseline) {
      $this->assertSame(4, $baseline['contract'], $id . ' is pinned in contract 4.');
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
    $this->assertSame(['*', '#', 'contract version 1 to 4', ContractChange::CONTRACT], $said);
    $this->assertTrue($change->fails());
    $this->assertFalse($change->needsAllowance());
  }

  /**
   * Leaves out what only notes.
   *
   * @param \Drupal\surface\Contract\ContractChange[] $changes
   *   The changes.
   *
   * @return \Drupal\surface\Contract\ContractChange[]
   *   The ones whose effect is more than a note, and the version raised.
   */
  protected function changes(array $changes): array {
    return array_values(array_filter($changes, static fn (ContractChange $change): bool => $change->effect !== 'note' || str_starts_with($change->change, 'version')));
  }

}
