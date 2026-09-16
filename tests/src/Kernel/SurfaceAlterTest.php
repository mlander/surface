<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DataSurfaceBuilder;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Event\DataSurfaceBuildEvent;
use Drupal\data_surface\DataSurfaceProviderInterface;
use Drupal\data_surface\Plugin\Block\DataSurfaceBlockBase;
use Drupal\data_surface_test\CasingVariantRefiner;
use Drupal\data_surface_test\Plugin\Block\DataSurfaceTestBlock;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests build-event altering: third-party mounts and refiner chains.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class SurfaceAlterTest extends DataSurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'data_surface', 'data_surface_test'];

  /**
   * Builds the test surface through the factory (alters applied).
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The sealed surface.
   */
  protected function buildSurface(): DataSurfaceInterface {
    $builder = new DataSurfaceBuilder(
      definitions: $this->casingVariantDefinitions(['bold', 'strong', 'quiet', 'muted']),
      refinements: ['variant' => ['casing']],
      refiner: new CasingVariantRefiner(),
    );
    return $this->surfaceFactory()->build($builder, static::class, 'test:data_surface_test');
  }

  /**
   * Tests that the subscriber's third-party mount joins the surface.
   */
  public function testThirdPartyMount(): void {
    $surface = $this->buildSurface();
    $third_party = $surface->getDefinition('third_party_settings');
    $this->assertInstanceOf(ComplexDataDefinitionInterface::class, $third_party);
    $mounted = $third_party->getPropertyDefinitions()['data_surface_test'];
    assert($mounted instanceof ComplexDataDefinitionInterface);
    $badge = $mounted->getPropertyDefinitions()['badge'];
    $this->assertSame(['star', 'flame'], $badge->getConstraints()['Choice']['choices']);
    // The mount's default lives on the mounted definition, and the
    // map's default assembles itself from there.
    $this->assertSame('star', DefinitionMetadata::getDefaultValue($badge));
    $this->assertSame(
      ['data_surface_test' => ['badge' => 'star']],
      $surface->getDefault('third_party_settings'),
    );
    // Mounted values validate through the normal path.
    $errors = $this->pipeline()->validate($surface, [
      'casing' => 'none',
      'third_party_settings' => ['data_surface_test' => ['badge' => 'bogus']],
    ]);
    $this->assertSame('data_surface_test.badge', $errors->byKey('third_party_settings')[0]->path);
  }

  /**
   * Tests that the subscriber's contribution joins the advertisement.
   *
   * The value is added at build time, so it is part of what the surface
   * says it accepts rather than something a refiner produces later, and
   * the module that added it is on the record as its owner.
   */
  public function testContributionIsAdvertised(): void {
    $surface = $this->buildSurface();

    $this->assertSame(
      ['bold', 'strong', 'quiet', 'muted', 'ribbon'],
      $surface->getDefinition('variant')->getConstraints()['Choice']['choices'],
    );
    $this->assertSame(
      ['variant' => ['data_surface_test' => ['ribbon']]],
      $surface->getDefinitions()->contributions(),
    );
  }

  /**
   * Tests that refinement is the union of the two contributions.
   */
  public function testUnionOfContributions(): void {
    $surface = $this->buildSurface();

    // The owner narrows its own values; the contributor keeps its one.
    $refined = $surface->refine(['casing' => 'lowercase']);
    $this->assertSame(['quiet', 'muted', 'ribbon'], $refined->getDefinition('variant')->getConstraints()['Choice']['choices']);

    // A casing the owner has no variants for leaves its values alone,
    // and the contributor takes its own away: what the owner is handed
    // never held the contributed value in the first place.
    $refined = $surface->refine(['casing' => 'none']);
    $this->assertSame(
      ['bold', 'strong', 'quiet', 'muted'],
      $refined->getDefinition('variant')->getConstraints()['Choice']['choices'],
    );

    // What validates is what the union offers.
    $this->assertCount(0, $this->pipeline()->validate($surface, ['casing' => 'lowercase', 'variant' => 'ribbon']));
    $this->assertContains(
      'variant',
      $this->pipeline()->validate($surface, ['casing' => 'none', 'variant' => 'ribbon'])->keys(),
    );
  }

  /**
   * Tests that a subscriber matching a class also matches its subclasses.
   *
   * Matching on class equality dropped an extension the moment anyone
   * subclassed the host, which is the normal way a site reuses a block
   * or a formatter. appliesTo() asks with is_a(), so what a module said
   * about a host goes on being said about everything that is that host.
   */
  public function testAppliesToFollowsTheClassHierarchy(): void {
    $event = new DataSurfaceBuildEvent(
      new DataSurfaceBuilder(['casing' => DataDefinition::create('string')]),
      DataSurfaceTestBlock::class,
      'block:data_surface_test_block',
    );
    $this->assertTrue($event->appliesTo(DataSurfaceTestBlock::class));
    $this->assertTrue($event->appliesTo(DataSurfaceBlockBase::class));
    $this->assertTrue($event->appliesTo(DataSurfaceProviderInterface::class));
    $this->assertFalse($event->appliesTo(CasingVariantRefiner::class));
  }

  /**
   * Tests that a host the subscriber does not target is untouched.
   */
  public function testOtherHostUntouched(): void {
    $builder = new DataSurfaceBuilder(['casing' => DataDefinition::create('string')]);
    $surface = $this->container->get('data_surface.factory')->build($builder, static::class, 'test:someone_else');
    $this->assertNull($surface->getDefinition('third_party_settings'));
  }

}
