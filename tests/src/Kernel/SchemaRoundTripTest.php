<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\data_surface\Contract\ContractBaselines;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\Tests\data_surface\Traits\SchemaRoundTripTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The full schema of every shipped declaration, read back, is itself again.
 *
 * The invariant of reading a schema: for each resource and structure the
 * examples, the first resource and the test fixtures declare, the full
 * schema the emitter writes for each operation that needs no subject (or
 * for the declaration alone, when it has none), read by SchemaReader into
 * a structure and built in the same context, emits that document again,
 * byte for byte. A declaration a runtime refiner narrows is left out: its
 * rule is a lookup no document states. What does not round-trip is listed
 * here, each by the kind of its reason (SchemaRoundTripTrait), and held to
 * differing only in that: the test fails when one starts to round-trip,
 * when one differs otherwise, and when the kind the trait names for it
 * from the document is not the one listed, which is what holds an
 * adopter's report of the kinds (ResourceTestBase) to this list.
 *
 * @see docs/reading-a-schema.md#the-round-trip
 * @see docs/decisions.md#a-schema-can-be-read-back-into-a-structure
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class SchemaRoundTripTest extends DataSurfaceKernelTestBase {

  use SchemaRoundTripTrait;
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
    'data_surface_first',
    'data_surface_resource_test',
  ];

  /**
   * The modules whose declarations are read back.
   */
  protected const MODULES = ['data_surface_examples', 'data_surface_first', 'data_surface_resource_test'];

  /**
   * What does not round-trip, by document, each by the kind of its reason.
   */
  protected const DIFFERS = [
    'event.fees:configure' => 'access',
    'event.mailer.credential.managed:configure' => 'server',
    'event.person:configure' => 'port',
    'event.program:check' => 'access',
    'event.program:configure' => 'access',
    'event.sales:configure' => 'site',
    'event.ticket_limit:configure' => 'port',
    'event.venue:create' => 'computed',
    'first.store:configure' => 'coded',
    'registration.step2:configure' => 'coded',
    'registration.step3:configure' => 'coded',
    'resource_test.annex:configure' => 'access',
    'resource_test.archive:configure' => 'coded',
    'resource_test.badge:configure' => 'computed',
    'resource_test.booth:configure' => 'coded',
    'resource_test.bulletin:configure' => 'coded otherwise',
    'resource_test.cellar:create' => 'coded',
    'resource_test.crate.fruit:configure' => 'coded',
    'resource_test.crate:configure' => 'coded',
    'resource_test.cupboard:configure' => 'coded',
    'resource_test.depot:configure' => 'coded otherwise',
    'resource_test.dynamic_child:configure' => 'coded otherwise',
    'resource_test.entry:configure' => 'coded',
    'resource_test.gallery.finish.oil:configure' => 'access',
    'resource_test.gallery:configure' => 'access',
    'resource_test.gallery:curate' => 'access',
    'resource_test.gallery_room:configure' => 'access',
    'resource_test.hall:configure' => 'alter',
    'resource_test.insurance:configure' => 'access',
    'resource_test.journal:configure' => 'coded',
    'resource_test.kiosk:configure' => 'rule',
    'resource_test.ledger:configure' => 'coded',
    'resource_test.loan:configure' => 'access',
    'resource_test.mount_watcher:configure' => 'alter',
    'resource_test.notice:configure' => 'alter',
    'resource_test.ownership:configure' => 'port',
    'resource_test.pantry.shelf:configure' => 'coded',
    'resource_test.pantry:create' => 'coded',
    'resource_test.parcel:configure' => 'alter',
    'resource_test.provenance:configure' => 'access',
    'resource_test.record:configure' => 'coded',
    'resource_test.seating:configure' => 'port',
    'resource_test.shelf_tag:configure' => 'port',
    'resource_test.sticky_note:configure' => 'coded',
    'resource_test.storeroom.aisle:configure' => 'coded',
    'resource_test.storeroom:update' => 'coded',
    'resource_test.timestamps:configure' => 'coded',
    'resource_test.venue:configure' => 'coded otherwise',
    'resource_test.wall:configure' => 'access',
    'resource_test.workshop:configure' => 'site',
    'test.threshold:configure' => 'coded',
  ];

  /**
   * The documents a runtime refiner narrows, left out of the round trip.
   */
  protected const RUNTIME = [
    'resource_test.exhibit:configure',
    'resource_test.notice_board:configure',
    'resource_test.stockroom:configure',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['data_surface_examples', 'data_surface_first', 'data_surface_resource_test']);
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Emits the full schema of every declaration of the modules read back.
   *
   * @return array<string, array{document: array, declared: \Drupal\data_surface\DataSurfaceInterface, id: string, module: string, context: string, operation: string|null, label: \Stringable|string|null, facts: array}>
   *   Each document, by `<id>:<context>`, with what it was emitted for.
   */
  protected function documents(): array {
    $registry = $this->container->get('data_surface.resource_registry');
    $resources = $this->container->get('data_surface.resources');
    $emitter = $this->container->get('data_surface.contract_emitter');
    $documents = [];
    foreach ($registry->getDefinitions() as $definition) {
      if ($definition->isDerived() || !in_array($definition->module, self::MODULES, TRUE) || str_contains((string) $definition->class, '\\Broken\\')) {
        continue;
      }
      $name = $definition->name();
      try {
        $operations = $registry->getOperations($name);
        $asked = $operations === [] ? [NULL] : array_filter($operations, static fn ($operation): bool => $operation->needsNothing());
        foreach ($asked as $operation) {
          $context = $operation === NULL ? new OperationContext(ContractBaselines::ALONE) : $resources->operation($name, $operation->id);
          $declared = $resources->buildStatic($name, $context);
          $document = $emitter->emitFull($declared, $definition->id, $operation?->id, $operation?->label, operation: $operation?->facts() ?? [])->document;
          $documents[$definition->id . ':' . $context->id] = [
            'document' => $document,
            'declared' => $declared,
            'id' => $definition->id,
            'module' => $definition->module,
            'context' => $context->id,
            'operation' => $operation?->id,
            'label' => $operation?->label,
            'facts' => $operation?->facts() ?? [],
          ];
        }
      }
      catch (\LogicException) {
        // A fixture built to be refused in its own operations.
        continue;
      }
    }
    ksort($documents);
    return $documents;
  }

  /**
   * Reads every document back, and emits what it built.
   */
  public function testEveryFullSchemaReadsBackToItself(): void {
    $registry = $this->container->get('data_surface.resource_registry');
    $same = [];
    $differ = [];
    $runtime = [];
    $report = [];
    $kinds = [];
    foreach ($this->documents() as $label => $emitted) {
      $original = $emitted['document']['schema'];
      $read = $this->readBack($original, $emitted['id'], $emitted['module'], $emitted['context'], $emitted['operation'], $emitted['label'], $emitted['facts'], $emitted['declared']);
      if ($read['verdict'] === 'live') {
        $runtime[] = $label;
        continue;
      }
      if ($read['verdict'] === 'error') {
        $differ[$label] = 'error';
        $report[$label] = ['error' => $read['error'], 'original' => $original];
        continue;
      }
      if ($read['verdict'] === 'same') {
        $same[] = $label;
        continue;
      }
      $differ[$label] = 'differs';
      $report[$label] = ['original' => $original, 'read back' => $read['again']];
      $kinds[$label] = static::kindOf($original, $read['again'], $registry->getDefinition($emitted['id']));
    }
    if (getenv('DATA_SURFACE_ROUND_TRIP_REPORT')) {
      // Every document read back, for a reader looking at what differs.
      $written = ['same' => $same, 'runtime' => $runtime, 'differ' => $differ, 'kinds' => $kinds, 'report' => $report];
      file_put_contents((string) getenv('DATA_SURFACE_ROUND_TRIP_REPORT'), json_encode($written, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
    $this->assertSame(self::RUNTIME, $runtime, 'The documents a runtime refiner narrows are the ones left out.');
    $this->assertSame([], array_filter($differ, static fn (string $what): bool => $what === 'error'), 'Every document is read back, and what it reads builds.');
    $this->assertSame(array_keys(self::DIFFERS), array_keys($differ), 'What does not round-trip is listed, and nothing listed round-trips.');
    $this->assertGreaterThanOrEqual(30, count($same), 'Most shipped declarations round-trip byte for byte.');
    // The kind an adopter's test reports a document as
    // (ResourceTestBase) is the one listed here for each.
    $this->assertSame(self::DIFFERS, $kinds, 'Each document that differs is named the kind listed for it.');
    foreach (self::DIFFERS as $label => $kind) {
      $this->assertArrayHasKey($kind, static::roundTripKinds());
      if ($kind === 'coded') {
        // Differing in the markers alone: the conditionals, the keys and
        // their words are the same.
        $this->assertSame(json_encode(static::withoutMarkers($report[$label]['original'])), json_encode(static::withoutMarkers($report[$label]['read back'])), $label . ' differs in the markers alone.');
      }
    }
  }

}
