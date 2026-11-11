<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\surface\Contract\ServedContract;
use Drupal\surface\Resource\ArrangementGroup;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface_examples\Resource\CateringByMethodResource;
use Drupal\surface_examples\Resource\DoorsResource;
use Drupal\surface_examples\Resource\SpotlightResource;
use Drupal\surface_examples\Resource\VenueResource;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PortableSchema\Registry;
use PortableSchema\Validator;

/**
 * Tests a served contract is a Portable Schema document, as it is emitted.
 *
 * Two example resources, in process: the doors, whose opening time is a
 * Regex with its message, emitted with the widget hints; and a new venue,
 * whose rooms are a collection and whose id the server alone holds unique.
 * Both documents of each give a ServedContract whose schema(), the
 * dialect's own value, writes the same JSON the array does, and whose
 * document loads in the dialect's registry and validator; the listing's
 * outputs too. And two documents the fixtures do not pin: the
 * spotlight with a component whose prop is a list of anything, and a
 * structure read from a schema that holds identifiers below its root.
 *
 * Decision: see
 * docs/decisions.md#the-served-document-is-a-portable-schema-document.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class PortableServedContractTest extends SurfaceKernelTestBase {

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
    'surface_http',
    'surface_examples',
    'surface_widget_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['surface_examples']);
  }

  /**
   * Tests both documents of two resources are the dialect's.
   */
  public function testBothDocumentsAreThePortableDocument(): void {
    $resources = $this->container->get('surface.resources');
    $emitter = $this->container->get('surface.contract_emitter');
    $registry = $this->container->get('surface.resource_registry');
    $contracts = [];
    $asked = [
      [DoorsResource::class, 'event.doors', 'configure', TRUE],
      [VenueResource::class, 'event.venue', 'create', FALSE],
      [CateringByMethodResource::class, 'event.catering.method', 'configure', TRUE],
    ];
    foreach ($asked as [$class, $id, $operation_id, $widgets]) {
      $operation = $registry->getOperation($class, $operation_id);
      $context = $resources->operation($class, $operation_id);
      $built = $resources->build($class, $context);
      $values = array_replace($built->getDefaultValues(), $resources->storage($class, $context, $built)->load($built));
      $contracts[$id . ' emit'] = $emitter->emit($built, $values, $id, $operation_id, $operation->label, $widgets, $operation->facts());
      $contracts[$id . ' full'] = $emitter->emitFull($resources->buildStatic($class, $context), $id, $operation_id, $operation->label, $widgets, $operation->facts());
    }
    foreach ($contracts as $which => $contract) {
      $this->assertPortable($contract, $which);
    }

    // What each is checked by, in the dialect's keywords.
    $doors = $contracts['event.doors full']->document['schema']['properties']['opens'];
    $this->assertSame('pattern', array_key_first($doors['x-portable-messages']), 'The pattern\'s message, under its code.');
    $this->assertSame(['widget' => 'time'], $doors['x-portable-ui'], 'Emitted with the widget hints.');
    // A group its frame declares, which the dialect's `groups` holds.
    foreach (['emit', 'full'] as $which) {
      $catering = $contracts['event.catering.method ' . $which]->document['schema'];
      $this->assertSame(['details' => ['label' => 'Details', 'widget' => 'fieldset']], $catering['x-portable-ui']['groups'], $which);
      $this->assertSame('details', $catering['properties']['notes']['x-portable-ui']['group'], $which);
    }
    $venue = $contracts['event.venue full']->document['schema']['properties'];
    $this->assertArrayHasKey('drupal:SurfaceUnique', $venue['id']['portable-check']);
    $this->assertTrue($venue['rooms']['x-surface']['collection']);
    $this->assertNull($contracts['event.venue full']->outputs(), 'A create declares no outputs.');

    // The listing's outputs are the dialect's too.
    $context = $resources->operation(VenueResource::class, 'list');
    $listing = $emitter->emitFull($resources->buildStatic(VenueResource::class, $context), 'event.venue', 'list');
    $outputs = $listing->outputs();
    $this->assertNotNull($outputs);
    $this->assertSame($outputs, $listing->outputs(), 'Read once.');
    $this->assertSame(json_encode($listing->document['outputs']), json_encode($outputs->toDocument()));
    $this->assertInstanceOf(Validator::class, new Validator(Registry::withTypes()->add($outputs->toDocument(), 'surface:event.venue/list/outputs')));
  }

  /**
   * Tests no served document can declare a group with no label.
   *
   * The dialect refuses a group whose label is empty; ArrangementGroup
   * refuses such a group when it is declared, so no document carries one.
   */
  public function testNoDocumentDeclaresGroupWithNoLabel(): void {
    $resources = $this->container->get('surface.resources');
    $registry = $this->container->get('surface.resource_registry');
    $context = $resources->operation(CateringByMethodResource::class, 'configure');
    $contract = $this->container->get('surface.contract_emitter')->emitFull($resources->buildStatic(CateringByMethodResource::class, $context), 'event.catering.method', 'configure', $registry->getOperation(CateringByMethodResource::class, 'configure')->label, TRUE);
    $this->assertPortable($contract, 'event.catering.method full');
    $document = $contract->schema()->toDocument();
    $document->{'x-portable-ui'}->groups->details->label = '';
    try {
      new Validator(Registry::withTypes()->add($document, 'surface:event.catering.method/no-label'));
      $this->fail('The dialect refuses a group with no label.');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertStringContainsString('x-portable-ui/groups/details/label', $e->getMessage());
    }
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('The group "details" has no label');
    ArrangementGroup::fieldset('details', '');
  }

  /**
   * Tests a list of anything is written with an empty schema for its items.
   *
   * The widget test's mixed component declares `classes: {type: array}`
   * with no items; an empty JSON array is no schema.
   */
  public function testTheListOfAnythingIsTheDialects(): void {
    $resources = $this->container->get('surface.resources');
    $registry = $this->container->get('surface.resource_registry');
    $context = $resources->operation(SpotlightResource::class, 'configure');
    $contract = $this->container->get('surface.contract_emitter')->emitFull($resources->buildStatic(SpotlightResource::class, $context), 'event.spotlight', 'configure', $registry->getOperation(SpotlightResource::class, 'configure')->label);
    $classes = $contract->document['schema']['$defs']['event.spotlight.props.surface_widget_test:mixed']['properties']['classes'];
    $this->assertEquals(new \stdClass(), $classes['items']);
    $this->assertPortable($contract, 'event.spotlight full');
  }

  /**
   * Tests a structure read from a schema with identifiers below its root.
   *
   * What a structure keeps of a schema is served again under
   * `portable-check`; the reader drops what identifies a schema below the
   * root, so the document it serves loads in the dialect.
   */
  public function testIdentifiersBelowTheRootAreNotServed(): void {
    $document = json_decode(<<<'JSON'
      {"type": "object", "additionalProperties": false, "properties": {
        "name": {"$id": "https://example.com/name", "type": "string"},
        "own": {"$schema": "https://json-schema.org/draft/2020-12/schema", "type": "string"},
        "odd": {"type": "integer", "not": {"$id": "https://example.com/odd", "multipleOf": 2}},
        "tags": {"type": "array", "items": {"type": "string"}, "contains": {"$id": "https://example.com/tag", "const": "a"}}
      },
      "$defs": {"part": {"$id": "https://example.com/part", "type": "object", "additionalProperties": false}}}
      JSON, TRUE, 512, JSON_THROW_ON_ERROR);
    $resources = $this->container->get('surface.resources');
    $read = $resources->buildStatic($this->container->get('surface.schema_reader')->structure($document, 'test.identified'), new OperationContext('configure'));
    $contract = $this->container->get('surface.contract_emitter')->emitFull($read, 'test.identified');
    $this->assertSame(['drupal:JsonSchema' => ['schema' => ['not' => ['multipleOf' => 2]]]], $contract->document['schema']['properties']['odd']['portable-check']);
    $this->assertPortable($contract, 'test.identified full');
  }

  /**
   * Holds one contract's schema to the dialect.
   *
   * @param \Drupal\surface\Contract\ServedContract $contract
   *   The contract.
   * @param string $which
   *   Which it is, for the message.
   */
  protected function assertPortable(ServedContract $contract, string $which): void {
    $schema = $contract->schema();
    $this->assertSame($schema, $contract->schema(), $which . ': read once.');
    $this->assertSame('https://portable-schema.dev/v1/schema', $contract->document['schema']['$schema'], $which);
    $this->assertSame(json_encode($contract->document['schema']), json_encode($schema->toDocument()), $which . ': the Schema writes what the array says.');
    $document = $schema->toDocument();
    $this->assertInstanceOf(\stdClass::class, $document);
    $id = 'surface:' . str_replace(' ', '/', $which);
    $this->assertInstanceOf(Validator::class, new Validator(Registry::withTypes()->add($document, $id)), $which . ': loads in the dialect.');
  }

}
