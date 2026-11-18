<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\surface\Contract\KeywordReader;
use Drupal\surface\Contract\SchemaFacts;
use Drupal\surface\Contract\ValueSchema;
use Drupal\surface\KeyFacts;
use Drupal\surface\Resource\SiteList;
use Drupal\surface\Resource\SiteListInterface;
use Drupal\surface\ResourceBuild\ResourceBlueprint;
use Drupal\surface\SurfaceBuilder;
use Drupal\surface_property_test\Shape\Code;
use Drupal\surface_resource_test\Structure\ThresholdStructure;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PortableSchema\Builder\Schema;

/**
 * Tests each fact a verb is handed reaches what the key holds.
 *
 * One test per fact the slice 32.9 review's mutants dropped with no test
 * failing: a locked const's default, the date widget, a list item's
 * title, the secret mark, a list item's settled options, an entry's
 * default, a key declared twice, an attachment's widget.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class DeclaredFactsKeptTest extends SurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'surface',
    'surface_property_test',
    'surface_resource_test',
  ];

  /**
   * Gets a resource's input blueprint over a builder.
   *
   * @param \Drupal\surface\SurfaceBuilder $builder
   *   The builder.
   * @param string|null $declaring_class
   *   The declaring id, or NULL for none.
   *
   * @return \Drupal\surface\ResourceBuild\ResourceBlueprint
   *   The blueprint.
   */
  protected function blueprint(SurfaceBuilder $builder, ?string $declaring_class = NULL): ResourceBlueprint {
    return new ResourceBlueprint($builder, $this->container->get('typed_data_manager'), FALSE, $this->container->get('surface.resource_registry'), $declaring_class);
  }

  /**
   * Gets the class of a provider that lists nothing.
   *
   * @return class-string<\Drupal\surface\Resource\SiteListInterface>
   *   The class.
   */
  protected static function provider(): string {
    $provider = new class() implements SiteListInterface {

      /**
       * {@inheritdoc}
       */
      public function list(): SiteList {
        return new SiteList();
      }

    };
    return get_class($provider);
  }

  /**
   * Tests a locked const is the key's default (mutant K3).
   */
  public function testLockedConstIsTheDefault(): void {
    $read = KeywordReader::forBuild()->read('fixed', Schema::const('fixed')->with('readOnly', TRUE));
    $this->assertNotNull($read);
    $this->assertTrue($read->locked);
    $this->assertSame('fixed', ValueSchema::factsOf($read->schema)['default_value'] ?? NULL);
  }

  /**
   * Tests a date string is drawn with the date widget (mutant K5).
   */
  public function testDateIsDrawnWithTheDateWidget(): void {
    $read = KeywordReader::forBuild()->read('day', Schema::string()->format('date'));
    $this->assertNotNull($read);
    $this->assertSame('date', SchemaFacts::setting($read->schema, 'widget'));
    $choice = KeywordReader::forBuild()->read('day', Schema::enum(['2026-01-01'])->type('string')->format('date'));
    $this->assertNotNull($choice);
    $this->assertNull(SchemaFacts::setting($choice->schema, 'widget'), 'A list of dates is drawn as its list.');
  }

  /**
   * Tests a list item's title is the item's label (mutant K6).
   */
  public function testListItemKeepsItsTitle(): void {
    $read = KeywordReader::forBuild()->read('tags', Schema::list(Schema::string()->title('Tag')));
    $this->assertNotNull($read);
    $this->assertSame('Tag', (string) KeyFacts::item($read->schema)?->get('title'));
  }

  /**
   * Tests writeOnly marks the key secret (mutant K9).
   *
   * ContractBaselineTest catches the mark dropped by what is served; this
   * pins it where it is read.
   */
  public function testWriteOnlyMarksTheKeySecret(): void {
    $read = KeywordReader::forBuild()->read('token', Schema::string()->writeOnly(TRUE));
    $this->assertNotNull($read);
    $this->assertTrue(ValueSchema::factsOf($read->schema)['secret'] ?? FALSE);
    $plain = KeywordReader::forBuild()->read('token', Schema::string());
    $this->assertNotNull($plain);
    $this->assertArrayNotHasKey('secret', ValueSchema::factsOf($plain->schema));
  }

  /**
   * Tests a list item's constraint given no options is settled (mutant B3).
   */
  public function testListItemOptionsAreSettled(): void {
    $builder = new SurfaceBuilder();
    $inputs = $this->blueprint($builder);
    $inputs->addDefinition('codes', new ListDataDefinition(['type' => 'list'], DataDefinition::create('string')->addConstraint('NotBlank', [])));
    $inputs->settleOptions();
    $codes = $builder->getDefinition('codes');
    $this->assertInstanceOf(ListDataDefinition::class, $codes);
    $item = $codes->getItemDefinition();
    $this->assertInstanceOf(DataDefinition::class, $item);
    $this->assertSame(['NotBlank' => NULL], $item->toArray()['constraints'] ?? NULL);
  }

  /**
   * Tests each entry of addEach() starts from its schema's default (B6, B7).
   */
  public function testEachEntryStartsFromItsDefault(): void {
    $inputs = $this->blueprint(new SurfaceBuilder(), 'property_test.schema_keys');
    $inputs->addEach('levels', Schema::string()->default('low'), static::provider(), 'Levels');
    $inputs->addEach('codes', Schema::of(Code::class)->default('ab'), static::provider(), 'Codes');
    $this->assertSame('low', $inputs->fromSite()['levels']['default'], 'A schema\'s default (B6).');
    $this->assertSame('ab', $inputs->fromSite()['codes']['default'], 'A shape\'s default beside it (B7).');
  }

  /**
   * Tests a key read from a schema is declared once (mutant B8).
   */
  public function testSchemaKeyIsDeclaredOnce(): void {
    $inputs = $this->blueprint(new SurfaceBuilder());
    $inputs->add('name', Schema::string());
    try {
      $inputs->add('name', Schema::integer());
      $this->fail('A key declared twice was not refused.');
    }
    catch (\LogicException $e) {
      $this->assertSame('The input "name" was already added to this blueprint. Adding is never replacing: what one declaration said, another may not change.', $e->getMessage());
    }
  }

  /**
   * Tests attach(widget:) is the attachment's widget (mutant B9).
   */
  public function testAttachKeepsItsWidget(): void {
    $inputs = $this->blueprint(new SurfaceBuilder());
    $inputs->attach('limit', ThresholdStructure::class, 'Limit', widget: 'fieldset');
    $this->assertSame('fieldset', $inputs->shellAt('limit')->getSetting('widget'));
  }

}
