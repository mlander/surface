<?php

declare(strict_types=1);

namespace Drupal\data_surface;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\data_surface\Attribute\DataSurfaceAware;
use Drupal\data_surface\Form\DataSurfaceFormBuilderInterface;
use Drupal\data_surface\Pipeline\DataSurfacePipelineInterface;

/**
 * The few things every host-side adoption trait needs.
 *
 * Composed into the configuration trait, the plugin form trait and the
 * formatter trait so none of them repeats the other, and so a class that
 * uses two of them at once (a surface block uses the first two) inherits
 * one copy of each method rather than colliding.
 *
 * Why the services are fetched rather than injected, documented here
 * once because it is the trap that keeps catching adopters: the host
 * base classes call into surface code from inside their constructors.
 * BlockPluginTrait::__construct() calls setConfiguration(), which asks
 * the surface for its defaults, before any subclass constructor body has
 * run — so a collaborator assigned through constructor promotion or
 * through create() is still unset at the moment it would be needed. A
 * property assigned before parent::__construct() would work, but every
 * adopting plugin would have to know that and write its constructor
 * around it, which is exactly the ceremony this layer exists to delete.
 * Fetching from the container at the point of use is the honest answer
 * for a host whose constructor calls us; it stays a documented exception
 * rather than a habit.
 *
 * The surface factory is fetched here for the same reason and under the
 * same exception: a base class asked for its surface from inside its own
 * constructor has no injected factory yet, and the factory is where
 * building an attribute-declared surface lives.
 */
trait DataSurfaceHostTrait {

  /**
   * Gets the surface pipeline.
   *
   * @return \Drupal\data_surface\Pipeline\DataSurfacePipelineInterface
   *   The pipeline.
   */
  protected function surfacePipeline(): DataSurfacePipelineInterface {
    // @phpstan-ignore globalDrupalDependencyInjection.useDependencyInjection
    return \Drupal::service('data_surface.pipeline');
  }

  /**
   * Gets the surface form builder.
   *
   * @return \Drupal\data_surface\Form\DataSurfaceFormBuilderInterface
   *   The form builder.
   */
  protected function surfaceFormBuilder(): DataSurfaceFormBuilderInterface {
    // @phpstan-ignore globalDrupalDependencyInjection.useDependencyInjection
    return \Drupal::service('data_surface.form_builder');
  }

  /**
   * Gets the surface factory.
   *
   * @return \Drupal\data_surface\DataSurfaceFactoryInterface
   *   The factory.
   */
  protected function surfaceFactory(): DataSurfaceFactoryInterface {
    // @phpstan-ignore globalDrupalDependencyInjection.useDependencyInjection
    return \Drupal::service('data_surface.factory');
  }

  /**
   * Gets the account an access answer is about.
   *
   * NULL means the current user, everywhere a surface answers for an
   * account, and this is where that is resolved. Fetched from the
   * container under the same documented exception as the services above:
   * a host base class may ask a plugin about access from inside its own
   * construction, and an injected account would still be unset.
   *
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The account, or NULL for the current user.
   *
   * @return \Drupal\Core\Session\AccountInterface
   *   The account to answer for.
   */
  protected function surfaceAccount(?AccountInterface $account = NULL): AccountInterface {
    // @phpstan-ignore globalDrupalDependencyInjection.useDependencyInjection
    return $account ?? \Drupal::currentUser();
  }

  /**
   * Holds a provider that is its own subject to the NULL subject.
   *
   * The rule the host base classes in this module follow, kept here so
   * that the four of them and every field item cannot disagree about
   * it: a plugin instance, a formatter and a field item each describe
   * themselves, so they have no second thing to be asked about and no
   * id vocabulary a caller could name one with. Handed a subject
   * anyway, they refuse it by name rather than serving the surface that
   * was not asked for, which is what a wire caller addressing the wrong
   * host needs to be told.
   *
   * Only getDataSurface() calls this. An access question is never
   * answered with an exception, so surfaceAccess() has no opinion about
   * a subject rather than throwing over one.
   *
   * @param string|null $subject
   *   The subject the caller named, which for a provider that is its
   *   own subject may only be NULL.
   *
   * @throws \InvalidArgumentException
   *   When a subject was named.
   *
   * @see \Drupal\data_surface\DataSurfaceProviderInterface::getDataSurface()
   */
  protected function surfaceSelfSubject(?string $subject): void {
    if ($subject !== NULL) {
      throw new \InvalidArgumentException(sprintf(
        '%s is its own subject and has no surface for the subject "%s".',
        static::class,
        $subject,
      ));
    }
  }

  /**
   * Answers whether an account may configure this host's values.
   *
   * The default for every host: no opinion. A surface describes what the
   * values may be, not who may write them, so a host that has not been
   * taught otherwise leaves the question to whoever asked — a route
   * requirement, an entity access handler, a tool's own check — and
   * neutral is how that is said without closing anything.
   *
   * That is the answer for every operation and every subject alike. A
   * default that refused a subject it could not place would be a gate,
   * and a host with nothing to say owns no gate; the surface build is
   * where a subject nobody can resolve is refused.
   *
   * A host with an answer of its own overrides this. Forbidden blocks
   * the pipeline's submit before it reads storage; allowed agrees
   * without bypassing the host's own gates.
   *
   * @param string $operation
   *   The host operation the answer is wanted for.
   * @param string|null $subject
   *   The id of the thing the operation is about, or NULL when the
   *   provider is its own subject.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The account to answer for, or NULL for the current user.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access answer.
   *
   * @see \Drupal\data_surface\DataSurfaceProviderInterface::surfaceAccess()
   */
  public function surfaceAccess(string $operation = 'configure', ?string $subject = NULL, ?AccountInterface $account = NULL): AccessResultInterface {
    return AccessResult::neutral();
  }

  /**
   * Reads the default values a class declares in its attribute.
   *
   * Several host protocols ask for their defaults statically — a
   * formatter's defaultSettings(), a field type's defaultFieldSettings()
   * — and a static method cannot consult an instance surface. A class
   * whose surface is fully declared in the DataSurfaceAware attribute
   * can still answer, because the definitions are readable from the
   * class itself; this is the one rule for doing so, kept here rather
   * than in each host's trait so the several static-defaults shims
   * cannot disagree about what a declared default is.
   *
   * A class whose surface needs live site state to describe itself has
   * no static declaration to read, so it answers its host's static
   * protocol itself. The exception says so rather than returning a
   * quietly empty array.
   *
   * @param string $class
   *   The fully qualified class name.
   *
   * @return array
   *   The declared defaults keyed by surface key.
   *
   * @throws \LogicException
   *   When the class declares no static surface.
   */
  protected static function surfaceDeclaredDefaults(string $class): array {
    $attribute = DataSurfaceAware::fromClass($class);
    if ($attribute === NULL || $attribute->definitions === []) {
      throw new \LogicException(sprintf(
        '%s declares no static surface definitions, so its default settings cannot be read from the class; answer the host\'s static defaults method with the defaults of the surface built at runtime.',
        $class,
      ));
    }
    $defaults = [];
    foreach ($attribute->definitions as $name => $definition) {
      $defaults[$name] = DefinitionMetadata::defaultOf($definition);
    }
    return $defaults;
  }

  /**
   * Reads in-progress input from an AJAX refinement rebuild.
   *
   * When a person changes a value others refine against, the form
   * rebuilds through AJAX and the surface has to refine against what was
   * just chosen rather than against what is stored. That input is not on
   * the host's own form state in any predictable place: a subform
   * state's values are unreadable before processing assigns #parents. So
   * it is located on the complete form state through the triggering
   * element's own position, which is nesting-agnostic.
   *
   * Widgets emit definition-shaped trees, so a definition's value sits
   * directly at its own key. (The adapter-era proof of concept had to
   * reach one level deeper, into a 'value' child, which is the kind of
   * coordinate bookkeeping the widget rewrite removed.)
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The surface being built.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state of the containing form.
   *
   * @return array
   *   Submitted values keyed by surface key; empty when the form is not
   *   rebuilding.
   */
  protected function surfaceRefinementInput(DataSurfaceInterface $surface, FormStateInterface $form_state): array {
    $state = $form_state instanceof SubformStateInterface
      ? $form_state->getCompleteFormState()
      : $form_state;
    $trigger = $state->getTriggeringElement();
    if ($trigger === NULL || !isset($trigger['#parents'])) {
      return [];
    }
    $tree = $state->getValue(array_slice($trigger['#parents'], 0, -1));
    return is_array($tree) ? array_intersect_key($tree, $surface->getDefinitions()->toArray()) : [];
  }

}
