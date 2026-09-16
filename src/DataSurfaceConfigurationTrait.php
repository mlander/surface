<?php

declare(strict_types=1);

namespace Drupal\data_surface;

use Drupal\data_surface\Pipeline\ViolationSummary;

/**
 * Implements ConfigurableInterface from a surface.
 *
 * Drop-in for plugins that own their state as a configuration array:
 * defaults come from the surface, and setConfiguration() runs the values
 * through the pipeline rather than trusting its caller, so a plugin
 * configured by a test, a config action or an agent is held to the same
 * contract a form holds it to.
 *
 * Two rules the hosts depend on. Keys the surface does not declare pass
 * through untouched, so host-owned keys (a block's id, label, provider;
 * a variant's weight; a context mapping) survive progressive adoption.
 * And the merge is the pipeline's, not the host's: accept() fills every
 * declared key from the surface's defaults before the input lands on
 * top, which is why adopters set the configuration property from the
 * accepted values instead of calling a host's own deep or shallow merge.
 *
 * The surface is built once per instance and coordinate. The host calls
 * these three methods freely and each of them needs the surface, so
 * building on every call meant dispatching the build event several times
 * for one submit; memoizedDataSurface() says why nothing invalidates it.
 *
 * The configuration property is declared here without a type or an
 * initial value on purpose: core's PluginBase already declares it
 * untyped, and PHP refuses to compose a trait property that differs from
 * an inherited one in either respect.
 *
 * @see \Drupal\data_surface\DataSurfaceHostTrait
 *   For why the pipeline is fetched from the container rather than
 *   injected — the host constructor trap, documented there once.
 */
trait DataSurfaceConfigurationTrait {

  use DataSurfaceHostTrait;

  /**
   * The stored values: the surface's keys plus whatever the host owns.
   *
   * @var array
   */
  protected $configuration;

  /**
   * The surfaces this instance has already built, keyed by coordinate.
   *
   * Keyed by operation and then by subject, which is the pair a surface
   * is asked for: two coordinates are two surfaces even when one object
   * answers for both. The empty string stands for the NULL subject,
   * because an array key cannot be NULL.
   *
   * @var array<string, array<string, \Drupal\data_surface\DataSurfaceInterface>>
   */
  protected array $memoizedDataSurfaces = [];

  /**
   * Builds the surface describing this object's values.
   *
   * @param string $operation
   *   The host operation the surface is wanted for.
   * @param string|null $subject
   *   The id of the thing the operation is about, or NULL when the
   *   provider is its own subject.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The surface.
   */
  abstract public function getDataSurface(string $operation = 'configure', ?string $subject = NULL): DataSurfaceInterface;

  /**
   * Gets this instance's surface, built at most once per coordinate.
   *
   * Every method in this trait needs the surface, and the host calls
   * them freely: one block submit asked for the configuration three
   * times and dispatched the build event three times with it, so three
   * sets of subscribers ran and any of them could have disagreed with
   * the others. Building once per instance and coordinate makes the
   * advertisement stable for the life of the object, which is what
   * "the surface is the single authority" has to mean in practice.
   *
   * Nothing invalidates this. A surface is a per-request description: it
   * is built from live site state at the moment it is asked for, and the
   * request that would need a different one is the next request, with a
   * new instance. A host whose state changes underneath it mid-request —
   * a test installing a module between two calls, say — asks the factory
   * itself rather than going through this trait.
   *
   * @param string $operation
   *   The host operation the surface is wanted for.
   * @param string|null $subject
   *   The id of the thing the operation is about, or NULL when the
   *   provider is its own subject.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The surface.
   */
  protected function memoizedDataSurface(string $operation = 'configure', ?string $subject = NULL): DataSurfaceInterface {
    return $this->memoizedDataSurfaces[$operation][(string) $subject] ??= $this->getDataSurface($operation, $subject);
  }

  /**
   * Gets the default configuration the surface declares.
   *
   * @return array
   *   The default values, keyed by surface key.
   */
  public function defaultConfiguration(): array {
    return $this->memoizedDataSurface()->getDefaultValues();
  }

  /**
   * Gets the configuration, with surface defaults filled in.
   *
   * @return array
   *   The configuration values.
   */
  public function getConfiguration(): array {
    return $this->configuration + $this->defaultConfiguration();
  }

  /**
   * Sets the configuration after running it through the pipeline.
   *
   * Replace semantics, as every host expects: the declared keys are
   * accepted against the surface's defaults rather than against what is
   * already stored, so setting one key resets its siblings to their
   * defaults instead of quietly keeping older values. Undeclared keys
   * are taken from the incoming array as they are.
   *
   * Access is deliberately not consulted here, and it is worth saying
   * why, because this looks like a write and is not one. A host
   * constructs its plugins with whatever it has already stored: core's
   * block, condition and action bases all call setConfiguration() from
   * inside their own constructors, so every instantiation of an already
   * placed block would be asking whether the current account may
   * configure it — during a cache warm, during a cron run, for an
   * anonymous visitor merely viewing the page. The gate belongs where
   * something is actually written, which is the pipeline's submit(), and
   * a host that means "write this" calls that rather than this.
   *
   * @param array $configuration
   *   The configuration values.
   *
   * @return $this
   *
   * @throws \InvalidArgumentException
   *   When a declared key's value violates the refined surface.
   */
  public function setConfiguration(array $configuration): static {
    $surface = $this->memoizedDataSurface();
    $definitions = $surface->getDefinitions()->toArray();
    $pipeline = $this->surfacePipeline();
    $values = $pipeline->accept($surface, array_intersect_key($configuration, $definitions));
    $violations = $pipeline->validate($surface, $values);
    if (!$violations->isEmpty()) {
      // The one place these message objects become text: an exception
      // message is a string and has nowhere to put an object.
      throw new \InvalidArgumentException('Invalid configuration: ' . ViolationSummary::fromViolations($violations));
    }
    $this->configuration = $values + array_diff_key($configuration, $definitions);
    return $this;
  }

}
