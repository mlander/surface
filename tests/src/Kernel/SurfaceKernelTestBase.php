<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\surface\Surface;
use Drupal\surface\DefinitionMap;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Form\SurfaceFormBuilderInterface;
use Drupal\surface\Options\SurfaceOptions;
use Drupal\surface\Pipeline\SurfacePipelineInterface;
use Drupal\surface_test\CasingVariantRefiner;
use Drupal\KernelTests\KernelTestBase;

/**
 * Base class for this module's kernel tests.
 *
 * Two things every kernel test here needed and five of them wrote out:
 * the four services a data surface is exercised through, and the casing and
 * variant fixture that is this module's smallest interesting data surface:
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
abstract class SurfaceKernelTestBase extends KernelTestBase {

  /**
   * Gets the data surface pipeline, which is where validation lives.
   *
   * @return \Drupal\surface\Pipeline\SurfacePipelineInterface
   *   The pipeline.
   */
  protected function pipeline(): SurfacePipelineInterface {
    return $this->container->get('surface.pipeline');
  }

  /**
   * Gets the data surface form builder.
   *
   * @return \Drupal\surface\Form\SurfaceFormBuilderInterface
   *   The form builder.
   */
  protected function formBuilder(): SurfaceFormBuilderInterface {
    return $this->container->get('surface.form_builder');
  }

  /**
   * Gets the options service, which reads value lists off constraints.
   *
   * @return \Drupal\surface\Options\SurfaceOptions
   *   The options service.
   */
  protected function options(): SurfaceOptions {
    return $this->container->get('surface.options');
  }

  /**
   * Builds the casing and variant definitions the fixtures share.
   *
   * The smallest data surface that has something to say: casing is a required
   * choice of three, variant is the key casing narrows. A caller that
   * wants the variant's own value space declared passes it; a caller
   * testing what narrowing does to an undeclared key leaves it open.
   *
   * @param array $variant_choices
   *   The values the variant key declares for itself, or the empty array
   *   to leave it an open string.
   *
   * @return array<string, \Drupal\Core\TypedData\DataDefinitionInterface>
   *   The definitions, keyed by definition key.
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
   * Seals the shared fixture into a data surface refined by casing.
   *
   * @param array $refiners
   *   Extra refiners to register, keyed by contributor name.
   * @param array $defaults
   *   Declared defaults, keyed by definition key.
   * @param array $locked
   *   The keys to lock.
   * @param array $variant_choices
   *   The values the variant key declares for itself.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The data surface.
   */
  protected function casingVariantSurface(array $refiners = [], array $defaults = [], array $locked = [], array $variant_choices = []): SurfaceInterface {
    $definitions = $this->casingVariantDefinitions($variant_choices);
    foreach ($defaults as $name => $value) {
      DefinitionMetadata::setDefaultValue($definitions[$name], $value);
    }
    // The owner's refiner comes first in the owner's chain for the
    // variant, and anything a test registers goes after it.
    $refiners['variant'][SurfaceInterface::OWNER] = [
      new CasingVariantRefiner(),
      ...($refiners['variant'][SurfaceInterface::OWNER] ?? []),
    ];
    return new Surface(
      DefinitionMap::fromArrays(
        definitions: $definitions,
        refinements: ['variant' => ['casing']],
        locked: $locked,
        refiners: $refiners,
      ),
    );
  }

  /**
   * Calls the triggering element's #ajax callback, as core's AJAX does.
   *
   * With the form Form API rebuilt, which is what core hands the
   * callback once a refinement rebuild has run.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state after an AJAX request was processed.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The response the callback built.
   */
  protected function ajaxResponse(FormStateInterface $form_state): AjaxResponse {
    $form = $form_state->getCompleteForm();
    $callback = $form_state->prepareCallback($form_state->getTriggeringElement()['#ajax']['callback']);
    $response = call_user_func_array($callback, [&$form, $form_state]);
    $this->assertInstanceOf(AjaxResponse::class, $response);
    return $response;
  }

  /**
   * Lists the selectors an AJAX response's commands of one kind name.
   *
   * @param \Drupal\Core\Ajax\AjaxResponse $response
   *   The response.
   * @param string $kind
   *   An insert method ('replaceWith', 'append', 'prepend') or a command
   *   name ('remove').
   *
   * @return string[]
   *   The selectors, in command order.
   */
  protected function ajaxSelectors(AjaxResponse $response, string $kind): array {
    $selectors = [];
    foreach ($response->getCommands() as $command) {
      if (($command['method'] ?? $command['command']) === $kind) {
        $selectors[] = $command['selector'];
      }
    }
    return $selectors;
  }

  /**
   * Gets the rendered markup of the command replacing one selector.
   *
   * @param \Drupal\Core\Ajax\AjaxResponse $response
   *   The response.
   * @param string $selector
   *   The selector.
   *
   * @return string
   *   The markup, or the empty string when nothing replaces it.
   */
  protected function ajaxMarkup(AjaxResponse $response, string $selector): string {
    foreach ($response->getCommands() as $command) {
      if (($command['selector'] ?? NULL) === $selector && isset($command['data'])) {
        return (string) $command['data'];
      }
    }
    return '';
  }

  /**
   * Gets the selector of an element's own AJAX wrapper.
   *
   * @param array $element
   *   A processed element.
   *
   * @return string
   *   The selector.
   */
  protected function wrapperSelector(array $element): string {
    $this->assertArrayHasKey(SurfaceFormBuilderInterface::REFRESH_ID_KEY, $element);
    return '#' . $element[SurfaceFormBuilderInterface::REFRESH_ID_KEY];
  }

}
