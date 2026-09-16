<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\data_surface\DataSurface;
use Drupal\data_surface\DefinitionMap;
use Drupal\data_surface\DataSurfaceFactoryInterface;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Form\DataSurfaceFormBuilderInterface;
use Drupal\data_surface\Options\DataSurfaceOptions;
use Drupal\data_surface\Pipeline\DataSurfacePipelineInterface;
use Drupal\data_surface_test\CasingVariantRefiner;
use Drupal\KernelTests\KernelTestBase;

/**
 * Base class for this module's kernel tests.
 *
 * Two things every kernel test here needed and five of them wrote out:
 * the four services a surface is exercised through, and the casing and
 * variant fixture that is this module's smallest interesting surface —
 * one key whose value space narrows another's. A fixture repeated per
 * class is a fixture that drifts per class, and a test asserting a
 * narrowing rule against its own private version of the rule's subject
 * is asserting less than it appears to.
 *
 * Deliberately no $modules here. KernelTestBase merges the property up
 * the class hierarchy, so declaring even 'system' would silently prepend
 * it to every subclass's install order; the subclasses say what they
 * install, as they did before.
 *
 * Deliberately no class-level attributes either, not even the group.
 * Core forbids PHPUnit metadata on abstract test classes and rules it
 * with a PHPStan rule of its own, and the metadata that matters most
 * here — #[RunTestsInSeparateProcesses] — has to sit on each concrete
 * class rather than be inherited from one place.
 *
 * @see \Drupal\PHPStan\Rules\TestClassClassMetadata
 */
abstract class DataSurfaceKernelTestBase extends KernelTestBase {

  /**
   * Gets the surface pipeline, which is where validation lives.
   *
   * @return \Drupal\data_surface\Pipeline\DataSurfacePipelineInterface
   *   The pipeline.
   */
  protected function pipeline(): DataSurfacePipelineInterface {
    return $this->container->get('data_surface.pipeline');
  }

  /**
   * Gets the surface form builder.
   *
   * @return \Drupal\data_surface\Form\DataSurfaceFormBuilderInterface
   *   The form builder.
   */
  protected function formBuilder(): DataSurfaceFormBuilderInterface {
    return $this->container->get('data_surface.form_builder');
  }

  /**
   * Gets the options service, which reads value lists off constraints.
   *
   * @return \Drupal\data_surface\Options\DataSurfaceOptions
   *   The options service.
   */
  protected function options(): DataSurfaceOptions {
    return $this->container->get('data_surface.options');
  }

  /**
   * Gets the surface factory, the one alter-aware entry point.
   *
   * @return \Drupal\data_surface\DataSurfaceFactoryInterface
   *   The factory.
   */
  protected function surfaceFactory(): DataSurfaceFactoryInterface {
    return $this->container->get('data_surface.factory');
  }

  /**
   * Builds the casing and variant definitions the fixtures share.
   *
   * The smallest surface that has something to say: casing is a required
   * choice of three, variant is the key casing narrows. A caller that
   * wants the variant's own value space declared passes it; a caller
   * testing what narrowing does to an undeclared key leaves it open.
   *
   * @param array $variant_choices
   *   The values the variant key declares for itself, or the empty array
   *   to leave it an open string.
   *
   * @return array<string, \Drupal\Core\TypedData\DataDefinitionInterface>
   *   The definitions, keyed by surface key.
   */
  protected function casingVariantDefinitions(array $variant_choices = []): array {
    $variant = DataDefinition::create('string')
      ->setLabel('Variant');
    if ($variant_choices !== []) {
      $variant->addConstraint('Choice', ['choices' => $variant_choices]);
    }
    return [
      'casing' => DataDefinition::create('string')
        ->setLabel('Casing')
        ->setRequired(TRUE)
        ->addConstraint('Choice', ['choices' => ['none', 'uppercase', 'lowercase']]),
      'variant' => $variant,
    ];
  }

  /**
   * Seals the shared fixture into a surface refined by casing.
   *
   * @param array $refiners
   *   Extra refiners to register, keyed by contributor name.
   * @param array $defaults
   *   Declared defaults, keyed by surface key.
   * @param array $locked
   *   The keys to lock.
   * @param array $variant_choices
   *   The values the variant key declares for itself.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The surface.
   */
  protected function casingVariantSurface(array $refiners = [], array $defaults = [], array $locked = [], array $variant_choices = []): DataSurfaceInterface {
    $definitions = $this->casingVariantDefinitions($variant_choices);
    foreach ($defaults as $name => $value) {
      DefinitionMetadata::setDefaultValue($definitions[$name], $value);
    }
    return new DataSurface(
      DefinitionMap::fromArrays(
        definitions: $definitions,
        refinements: ['variant' => ['casing']],
        locked: $locked,
        refiners: $refiners,
      ),
      refiner: new CasingVariantRefiner(),
    );
  }

}
