<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\TypedData\DataDefinition;
use PortableSchema\Builder\Schema;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\surface\Contract\DefinitionSchema;
use Drupal\surface\Contract\SchemaWidgetInterface;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\SurfaceBuilder;
use Drupal\surface\SurfaceRefinerInterface;
use Drupal\Tests\surface\HandCorpus\NotAMapDefinition;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests every stock widget answers for the schemas a class test once decided.
 *
 * A widget used to tell a list or a map by the definition's class; it now
 * reads the schema (DefinitionSchema::isList(), isComplex(), itemNode()).
 * Each case is a schema whose answer hangs on that: one read from a class
 * outside the three NodeDefinition derives, the variants' placeholder, a
 * list of maps, a list a refiner narrowed (the refined entry's schema), and
 * a secret. The answers are the ones every stock widget gave the
 * definitions before the flip, recorded on the base by the parity
 * instrument of slice 32.9a.
 *
 * @see docs/decisions.md#a-widget-is-chosen-by-the-keys-schema
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class WidgetSchemaClassCasesTest extends SurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'surface'];

  /**
   * Tests each stock widget's answer and the widget chosen, per case.
   */
  public function testEveryStockWidgetAnswersAsBefore(): void {
    $choice = $this->container->get('surface.widget_choice');
    $manager = $this->container->get('plugin.manager.surface_widget');
    $stock = [];
    foreach ($manager->instances() as $id => $widget) {
      if (($manager->getDefinition($id)['provider'] ?? NULL) === 'surface') {
        $this->assertInstanceOf(SchemaWidgetInterface::class, $widget, $id);
        $stock[$id] = $widget;
      }
    }
    $this->assertCount(15, $stock, 'Every stock widget is asked.');

    $expected = [
      'not a map' => [['fieldset'], 'fieldset'],
      'variants placeholder' => [[], 'No data surface widget serves the "any" data type.'],
      'list of maps' => [['json'], 'json'],
      'refined list' => [['checkboxes', 'select', 'json'], 'checkboxes'],
      'secret' => [['string'], 'string'],
    ];
    foreach ($this->cases() as $case => $schema) {
      $drawn = [];
      foreach ($stock as $id => $widget) {
        if ($widget->drawsSchema($schema, $choice)) {
          $drawn[] = $id;
        }
      }
      try {
        $chosen = $choice->forSchema($schema)->getPluginId();
      }
      catch (\InvalidArgumentException $e) {
        $chosen = $e->getMessage();
      }
      $this->assertSame($expected[$case], [$drawn, $chosen], $case);
    }
  }

  /**
   * Tests the refined list's schema holds what the refiner narrowed.
   */
  public function testTheRefinedListOffersWhatTheRefinerLeft(): void {
    $schema = $this->cases()['refined list'];
    $this->assertSame(['red'], array_keys($this->container->get('surface.widget_choice')->optionSet($schema)->options));
  }

  /**
   * Builds the schemas of the cases.
   *
   * @return array<string, \PortableSchema\Builder\Schema>
   *   The schemas, by case.
   */
  protected function cases(): array {
    $not_a_map = NotAMapDefinition::create()->setPropertyDefinition('name', DataDefinition::create('string')->setLabel('Name'));
    $not_a_map->setLabel('Not a map');
    // As Variants::placeholder() makes it.
    $placeholder = DataDefinition::create('any')->setLabel('Kind of thing')->setRequired(TRUE);
    DefinitionMetadata::setVariantsBy($placeholder, 'kind');
    $maps = ListDataDefinition::create('map')->setLabel('Rows');
    $maps->setItemDefinition(MapDataDefinition::create()->setPropertyDefinition('label', DataDefinition::create('string')->setLabel('Label')));
    $secret = DataDefinition::create('string')->setLabel('Token');
    DefinitionMetadata::setSecret($secret);
    $tags = ListDataDefinition::create('string')->setLabel('Tags');
    $tags->getItemDefinition()->addConstraint('Choice', ['choices' => ['red', 'green', 'blue']]);
    $size = DataDefinition::create('string')->setLabel('Size')->addConstraint('Choice', [
      'choices' => ['small', 'large'],
    ]);
    $builder = (new SurfaceBuilder())
      ->setDefinition('size', $size)
      ->setDefinition('tags', $tags)
      ->addRefinement('tags', ['size']);
    $builder->addRefiner('tags', new class () implements SurfaceRefinerInterface {

      /**
       * {@inheritdoc}
       */
      public function refine(string $name, Schema $key, array $values, string $who): array {
        return ($values['size'] ?? NULL) === 'small' ? [Schema::any()->with('items', Schema::enum(['red']))] : [];
      }

    });
    $refined = $builder->seal()->refine(['size' => 'small'])->getDefinitions()->entry('tags');
    $this->assertNotNull($refined);
    return [
      'not a map' => DefinitionSchema::of($not_a_map),
      'variants placeholder' => DefinitionSchema::of($placeholder),
      'list of maps' => DefinitionSchema::of($maps),
      'refined list' => $refined->schema(),
      'secret' => DefinitionSchema::of($secret),
    ];
  }

}
