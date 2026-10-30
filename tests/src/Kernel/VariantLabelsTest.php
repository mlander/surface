<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\surface\Attachment;
use Drupal\surface\SurfaceBuilder;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\Refinement\ChoiceSet;
use Drupal\surface\Resource\Attribute\Variant;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface_examples\Resource\RegistrationStep3Resource;
use Drupal\surface_examples\Structure\PaidTicketStructure;
use Drupal\surface_resource_test\Structure\Broken\UnofferedVariantStructure;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests a union's tags live on its variants.
 *
 * A deciding key that declares no list is offered its variants' values,
 * by the labels the variants give; one that declares a list narrows the
 * variants' values, and may name no other.
 *
 * @see docs/decisions.md#variants-not-slots
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class VariantLabelsTest extends SurfaceKernelTestBase {

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
    'surface_examples',
    'surface_resource_test',
  ];

  /**
   * Gets a variant with no keys, sealed.
   *
   * @return \Drupal\surface\Attachment
   *   The variant.
   */
  protected static function variant(): Attachment {
    return new Attachment((new SurfaceBuilder())->seal());
  }

  /**
   * Attaches three variants to a deciding key, and seals.
   *
   * @param \Drupal\Core\TypedData\DataDefinition $deciding
   *   The deciding key.
   * @param array<string, string> $labels
   *   The labels the variants give.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The data surface.
   */
  protected static function attached(DataDefinition $deciding, array $labels = []): SurfaceInterface {
    $builder = new SurfaceBuilder(['kind' => $deciding->setLabel('Kind')]);
    $variants = ['jar' => self::variant(), 'tin' => self::variant(), 'pot' => self::variant()];
    $builder->attachVariants('settings', 'kind', $variants, $labels);
    return $builder->seal();
  }

  /**
   * Tests example 3's pricing is offered by its tickets' labels.
   *
   * The pricing declares no list: its values and labels are the tickets',
   * in the form's definition and in both documents.
   */
  public function testTheTicketsLabelThePricing(): void {
    $variant = (new \ReflectionClass(PaidTicketStructure::class))->getAttributes(Variant::class)[0]->newInstance();
    $this->assertSame('Paid', (string) $variant->label);

    $resources = $this->container->get('surface.resources');
    $surface = $resources->build(RegistrationStep3Resource::class, $resources->operation(RegistrationStep3Resource::class, 'configure'));
    $set = ChoiceSet::of($surface->getDefinition('pricing'));
    $this->assertNotNull($set);
    $this->assertSame('LabeledChoice', $set->constraint);
    $this->assertSame(['free', 'paid'], $set->values);
    $this->assertSame(['free' => 'Free', 'paid' => 'Paid'], array_map('strval', $set->labels));
    $this->assertSame('free', $surface->getDefault('pricing'), 'The default stays the key\'s.');

    $emitter = $this->container->get('surface.contract_emitter');
    $expected = [['const' => 'free', 'title' => 'Free'], ['const' => 'paid', 'title' => 'Paid']];
    $full = json_decode((string) json_encode($emitter->emitFull($surface, 'registration.step3')->document['schema']), TRUE);
    $this->assertSame($expected, $full['properties']['pricing']['oneOf']);
    $served = json_decode((string) json_encode($emitter->emit($surface, ['pricing' => 'paid'], 'registration.step3')->document), TRUE);
    $this->assertSame($expected, $served['schema']['properties']['pricing']['oneOf']);
  }

  /**
   * Tests the list derived from the variants, labeled or not.
   */
  public function testTheListIsTheVariants(): void {
    $plain = self::attached(DataDefinition::create('string'));
    $this->assertSame(['choices' => ['jar', 'tin', 'pot']], $plain->getDefinition('kind')->getConstraints()['Choice'], 'No labels: the values alone.');

    $labeled = self::attached(DataDefinition::create('string'), ['jar' => 'Glass jar', 'tin' => 'Tin can']);
    $set = ChoiceSet::of($labeled->getDefinition('kind'));
    $this->assertNotNull($set);
    $this->assertSame('LabeledChoice', $set->constraint);
    $this->assertSame(['jar', 'tin', 'pot'], $set->values);
    $this->assertSame(['jar' => 'Glass jar', 'tin' => 'Tin can', 'pot' => 'pot'], $set->labels, 'A variant with no label is offered as its value.');
  }

  /**
   * Tests a declared list narrows the variants, and keeps its own words.
   */
  public function testDeclaredListNarrows(): void {
    $surface = self::attached(DataDefinition::create('string')->addConstraint('Choice', ['choices' => ['tin', 'jar']]), ['jar' => 'Glass jar']);
    $this->assertSame(['choices' => ['tin', 'jar']], $surface->getDefinition('kind')->getConstraints()['Choice'], 'Its own list, in its own order, with no labels merged in.');
    $this->assertSame(['jar', 'tin'], $surface->getDefinitions()->entry('settings')->variants->variantIds(), 'A variant the list leaves out is not offered.');
  }

  /**
   * Tests a declared list naming a value no variant is for is refused.
   */
  public function testDeclaredListMayNotWiden(): void {
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('resource_test.broken.unoffered_variant lists "pot" for "kind", and no variant at "settings" is for it. A deciding key\'s list narrows the values its variants are for and names no other');
    $this->container->get('surface.resources')->build(UnofferedVariantStructure::class, new OperationContext('configure'));
  }

}
