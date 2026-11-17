<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\surface\Contract\DefinitionSchema;
use Drupal\surface\ResourceBuild\RefinerDefinition;
use Drupal\surface\ResourceBuild\RefinesInputRefiner;
use Drupal\surface\SurfaceBuilder;
use Drupal\surface\SurfaceInterface;
use Drupal\surface_test\AnswerShapes;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests an old-shape method and an answer give the same refined schema.
 *
 * Twin keys, each refined by one method of each shape for the same change
 * (AnswerShapes): a bound with a description, and a list narrowed and
 * required. Refined for the same value, each pair's schemas are the same
 * (DefinitionSchema::same()); left as advertised, too.
 *
 * @see \Drupal\surface\Refinement\Answer
 * @see \Drupal\surface\Refinement\AnswerFromDefinition
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class AnswerShapesTest extends SurfaceKernelTestBase {

  /**
   * Tests each twin refines to the same schema.
   */
  public function testBothShapesGiveOneSchema(): void {
    $surface = $this->surface();
    foreach (['small', 'large'] as $size) {
      $refined = $surface->refine(['size' => $size])->getDefinitions();
      foreach (['limit', 'color'] as $key) {
        $old = $refined->entry("{$key}_old")?->schema();
        $new = $refined->entry("{$key}_new")?->schema();
        $this->assertNotNull($old);
        $this->assertNotNull($new);
        $this->assertTrue(DefinitionSchema::same($old, $new), "$key, $size: one schema.");
      }
    }
    $limit = $surface->refine(['size' => 'small'])->getDefinitions()->entry('limit_new');
    $this->assertNotNull($limit);
    $this->assertSame(10, $limit->schema()->get('maximum'));
    $this->assertSame('Up to ten for a small one.', $limit->schema()->get('description'));
  }

  /**
   * Builds the twins.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   `size`, and `limit` and `color`, each twice: `_old` refined by the
   *   old shape, `_new` by an answer.
   */
  protected function surface(): SurfaceInterface {
    $builder = new SurfaceBuilder(['size' => DataDefinition::create('string')->setLabel('Size')]);
    $bindings = [];
    foreach (['old' => ['ByDefinition', 'definition'], 'new' => ['ByAnswer', 'none']] as $twin => [$suffix, $first]) {
      $range = ['min' => 1, 'max' => 100];
      $builder->setDefinition("limit_$twin", DataDefinition::create('integer')->setLabel('Limit')->addConstraint('Range', $range));
      $builder->setDefinition("color_$twin", DataDefinition::create('string')->setLabel('Color')->addConstraint('LabeledChoice', [
        'choices' => ['red', 'green', 'blue'],
        'labels' => ['red' => 'Red', 'green' => 'Green', 'blue' => 'Blue'],
      ]));
      foreach (['limit', 'color'] as $key) {
        $builder->addRefinement("{$key}_$twin", ['size']);
        $bindings["{$key}_$twin"] = [new RefinerDefinition(AnswerShapes::class, $key . $suffix, "{$key}_$twin", ['size'], TRUE, TRUE, first: $first)];
      }
    }
    $link = new RefinesInputRefiner(AnswerShapes::class, $bindings);
    foreach (array_keys($bindings) as $key) {
      $builder->addRefiner($key, $link);
    }
    return $builder->seal();
  }

}
