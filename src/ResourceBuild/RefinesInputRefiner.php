<?php

declare(strict_types=1);

namespace Drupal\surface\ResourceBuild;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\surface\Contract\DefinitionSchema;
use Drupal\surface\Contract\SchemaFacts;
use Drupal\surface\SurfaceRefinerInterface;
use Drupal\surface\Refinement\Answer;
use Drupal\surface\Refinement\ApplicabilityInterface;
use Drupal\surface\Refinement\DoesNotApply;
use Drupal\surface\Pipeline\PipelineGuard;
use PortableSchema\Builder\Schema;

/**
 * The engine's refiner, spoken by one class's #[RefinesInput] methods.
 *
 * One of these per class that refines (the resource or structure, then
 * each alter), registered in the declaring class's chain of every key it
 * refines, in that order. So the engine runs the declaring class's methods
 * first and the alters' after and applies each answer (Answer::apply());
 * nothing about refinement, its cacheability or the form's AJAX and discard
 * machinery knows a method is behind the link.
 *
 * Asked to refine one key, it calls that key's methods on its class in
 * declaration order, each with the sibling values it watches, by
 * parameter order, and, where its first parameter is typed Schema, with
 * the key's schema as the methods before it left it. Each answers a
 * Schema of what the values add, or DoesNotApply::here(), and the link
 * gives the answers in order. A method whose first parameter is typed
 * for a definition is refused when the link is made. The
 * engine calls it only once every key the key refines against holds a
 * value; for a key two methods refine, that is the union of what both
 * watch, because the engine gates per key rather than per method. A
 * watched value its own key's definition refuses is never handed over:
 * the engine treats it as unanswered, so a method on a mounted key gets
 * NULL for it and a method gated on a whole key does not run.
 *
 * The call is made through reflection, which is what coerces a sibling
 * value the way PHP coerces a scalar argument outside strict mode. A
 * form hands refinement its raw input, so a checkbox arrives as "1" and
 * a number as "5"; a refiner declaring bool and int parameters receives
 * TRUE and 5, rather than a TypeError from this file's strict types.
 *
 * It rides inside the sealed data surface, so inside cached forms. The
 * declaring class's link holds the declaring class name, because a resource's
 * or structure's refiners are static and neither is ever instantiated; an
 * alter's link holds the alter, an autowired service whose runtime
 * refiners may be instance methods, and the dependency serialization
 * trait stores a service by its id, so a serialized data surface carries
 * no container either way. A static method and an instance method are
 * dispatched alike.
 *
 * With assertions on, a static refiner, one not declared runtime (and
 * so, the build has made sure, a static method), is called with the
 * global container guarded (PipelineGuard): asking it for a
 * service throws, naming the method. A runtime refiner is called guarded
 * too, its class refused the services bound to the request a door
 * answers. Production, where assert() is not compiled, makes the call as
 * it is. A runtime refiner whose last parameter is typed AccountInterface
 * is handed the account the operation runs as.
 *
 * An alter's method on a key the alter itself added is bound under the
 * mount, `third_party_settings`, the one key the engine knows the
 * alter's keys by. Asked to refine the mount, the link refines only its
 * own module's property inside it, each method's answer placed at that
 * property, so the method reads exactly as it would on a declaring class's key.
 * A method watching a key its alter added is handed that key's value
 * the same way, read at its dotted path, `third_party_settings.<module>.
 * <key>`, which is the name the engine knows the dependency by.
 *
 * A method that answers DoesNotApply::here() rules the key out: the link
 * calls none of the key's methods after it, since nothing is narrower.
 * Only a method that names DoesNotApply in its return type may answer it,
 * so what may be ruled out is known before anything runs (mayNotApply()).
 *
 * @internal
 */
final class RefinesInputRefiner implements SurfaceRefinerInterface, ApplicabilityInterface {

  use DependencySerializationTrait;

  /**
   * The key an alter's own keys are mounted under.
   */
  public const MOUNT = 'third_party_settings';

  /**
   * Constructs a RefinesInputRefiner.
   *
   * @param object|class-string $instance
   *   The resource or structure class, or the alter instance, the
   *   methods are on.
   * @param array<string, \Drupal\surface\ResourceBuild\RefinerDefinition[]> $bindings
   *   The methods, keyed by the input key they refine; an alter's methods
   *   on its own mounted keys under self::MOUNT.
   * @param string|null $module
   *   The alter's module, whose property inside the mount the methods
   *   bound under self::MOUNT refine; NULL for the declaring class's own link.
   * @param array<string, string> $paths
   *   The keys the alter mounted that its methods watch, each mapped to
   *   the dotted path its value is handed under.
   */
  public function __construct(
    protected object|string $instance,
    protected array $bindings,
    protected ?string $module = NULL,
    protected array $paths = [],
  ) {
    foreach ($bindings as $refiners) {
      foreach ($refiners as $refiner) {
        if ($refiner->first === 'definition') {
          throw new \LogicException(sprintf(
            '%s takes the key\'s definition as its first parameter. A #[RefinesInput] method returns a Schema of what the values it watches add to the key, or DoesNotApply::here(); a first parameter typed Schema is handed the key\'s schema.',
            $refiner->describe(),
          ));
        }
      }
    }
  }

  /**
   * Spells the dotted path of a key an alter mounted.
   *
   * @param string $module
   *   The alter's module.
   * @param string $key
   *   The key, as the alter added it.
   *
   * @return string
   *   `third_party_settings.<module>.<key>`.
   */
  public static function path(string $module, string $key): string {
    return self::MOUNT . '.' . $module . '.' . $key;
  }

  /**
   * Names the methods this link runs for one key, for a message.
   *
   * @param string $name
   *   The key; the mount for an alter's methods on the keys it added.
   *
   * @return string[]
   *   Each method, Class::method(), in the order the link calls them.
   */
  public function describes(string $name): array {
    return array_map(static fn (RefinerDefinition $refiner): string => $refiner->describe(), $this->bindings[$name] ?? []);
  }

  /**
   * {@inheritdoc}
   */
  public function refine(string $name, Schema $key, array $values): array {
    if ($name === self::MOUNT && $this->module !== NULL) {
      return $this->refineMounted($key, $values);
    }
    $answers = [];
    $schema = $key;
    foreach ($this->bindings[$name] ?? [] as $refiner) {
      if (SchemaFacts::doesNotApply($schema)) {
        break;
      }
      $answer = $this->answerOf($refiner, $schema, $values, $name);
      $answers[] = $answer;
      // The next method reads the key as this one left it. An answer the
      // key refuses is refused where the chain applies it, which names the
      // chain: nothing after it runs.
      try {
        $schema = Answer::apply($schema, $answer, $name, $refiner->describe(), TRUE);
      }
      catch (\LogicException) {
        break;
      }
    }
    return $answers;
  }

  /**
   * Gives one method's answer for a key, held to its key.
   *
   * @param \Drupal\surface\ResourceBuild\RefinerDefinition $refiner
   *   The method.
   * @param \PortableSchema\Builder\Schema $key
   *   The key's schema as it stands, handed where the method's first
   *   parameter is typed Schema.
   * @param array $values
   *   Sibling values, keyed by input key.
   * @param string $name
   *   The key, for a refusal.
   *
   * @return \PortableSchema\Builder\Schema|\Drupal\surface\Refinement\DoesNotApply
   *   The answer.
   *
   * @throws \LogicException
   *   When the answer reaches inside the key (Answer::assertAuthored()).
   */
  public function answerOf(RefinerDefinition $refiner, Schema $key, array $values, string $name): Schema|DoesNotApply {
    $answer = $this->answer($refiner, $key, $values);
    Answer::assertAuthored($answer, $name, $refiner->describe());
    return $answer;
  }

  /**
   * {@inheritdoc}
   */
  public function mayNotApply(string $name): array {
    $paths = [];
    foreach ($this->bindings[$name] ?? [] as $refiner) {
      if ($refiner->mayNotApply) {
        $paths[] = $name === self::MOUNT && $this->module !== NULL ? $this->module . '.' . $refiner->key : '';
      }
    }
    return array_values(array_unique($paths));
  }

  /**
   * Answers for the alter's own keys inside the mount.
   *
   * Each method is handed its key's schema inside the module's map inside
   * the mount, and its answer is placed there, so it is applied there.
   *
   * Decision: see docs/decisions.md#an-alter-refines-its-own-mounted-key.
   *
   * @param \PortableSchema\Builder\Schema $mount
   *   The mount's schema.
   * @param array $values
   *   Sibling values, keyed by input key.
   *
   * @return array<int, \PortableSchema\Builder\Schema>
   *   The answers, each for the mount.
   */
  protected function refineMounted(Schema $mount, array $values): array {
    $module = (string) $this->module;
    $own = DefinitionSchema::propertyOf($mount, $module);
    if ($own === NULL || !is_a(DefinitionSchema::classOf($own), MapDataDefinition::class, TRUE)) {
      return [];
    }
    $answers = [];
    $schema = $mount;
    foreach ($this->bindings[self::MOUNT] ?? [] as $refiner) {
      $own = DefinitionSchema::propertyOf($schema, $module);
      $key = $own === NULL ? NULL : DefinitionSchema::propertyOf($own, $refiner->key);
      if ($key === NULL || SchemaFacts::doesNotApply($key)) {
        continue;
      }
      $answer = $this->answerOf($refiner, $key, $values, $refiner->key);
      $placed = Schema::any()->with('properties', [
        $module => Schema::any()->with('properties', [
          $refiner->key => $answer instanceof DoesNotApply ? Schema::boolean(FALSE) : $answer,
        ]),
      ]);
      $answers[] = $placed;
      try {
        $schema = Answer::apply($schema, $placed, self::MOUNT, $refiner->describe(), TRUE);
      }
      catch (\LogicException) {
        break;
      }
    }
    return $answers;
  }

  /**
   * Gets the account the operation runs as: the current user every door set.
   *
   * @return \Drupal\Core\Session\AccountInterface
   *   The account; anonymous with no container, in a unit test.
   */
  protected static function account(): AccountInterface {
    // @phpstan-ignore globalDrupalDependencyInjection.useDependencyInjection
    if (!\Drupal::hasContainer()) {
      return new AnonymousUserSession();
    }
    // @phpstan-ignore globalDrupalDependencyInjection.useDependencyInjection
    return \Drupal::currentUser()->getAccount();
  }

  /**
   * Calls one method of the answer shape and gives its answer.
   *
   * @param \Drupal\surface\ResourceBuild\RefinerDefinition $refiner
   *   The method.
   * @param \PortableSchema\Builder\Schema $key
   *   The key's schema, handed where the method's first parameter is
   *   typed Schema.
   * @param array $values
   *   Sibling values, keyed by input key, a mounted one by its dotted
   *   path; every watched key is present.
   *
   * @return \PortableSchema\Builder\Schema|\Drupal\surface\Refinement\DoesNotApply
   *   What the method answered.
   *
   * @throws \LogicException
   *   When the method returned something other than a Schema or
   *   DoesNotApply, or DoesNotApply without declaring it.
   */
  public function answer(RefinerDefinition $refiner, Schema $key, array $values): Schema|DoesNotApply {
    $answer = $this->call($refiner, $refiner->first === 'schema' ? [$key] : [], $values, 'Schema');
    if (!$answer instanceof Schema && !$answer instanceof DoesNotApply) {
      throw new \LogicException(sprintf(
        '%s returned %s; a #[RefinesInput] method returns a Schema of what the watched values add, or DoesNotApply::here().',
        $refiner->describe(),
        get_debug_type($answer),
      ));
    }
    return $answer;
  }

  /**
   * Calls one method with what it is handed and the siblings it watches.
   *
   * @param \Drupal\surface\ResourceBuild\RefinerDefinition $refiner
   *   The method.
   * @param array $first
   *   What its first parameter is handed, if anything: the definition or
   *   the schema.
   * @param array $values
   *   Sibling values, keyed by input key, a mounted one by its dotted
   *   path; every watched key is present.
   * @param string $returns
   *   What the method returns beside DoesNotApply, for the refusal.
   *
   * @return mixed
   *   What the method returned.
   *
   * @throws \LogicException
   *   When it answered DoesNotApply without declaring it.
   */
  protected function call(RefinerDefinition $refiner, array $first, array $values, string $returns): mixed {
    $arguments = $first;
    foreach ($refiner->watched() as $key) {
      $arguments[] = $values[$this->paths[$key] ?? $key] ?? NULL;
    }
    if ($refiner->takesAccount) {
      // The account the operation runs as, which every door sets as the
      // current user before it asks: read here, once, so the refiner is
      // handed it and never reads it itself.
      // Decision: see
      // docs/decisions.md#nothing-an-operation-runs-reads-the-request.
      $arguments[] = static::account();
    }
    $method = new \ReflectionMethod($this->instance, $refiner->method);
    $object = $method->isStatic() || !is_object($this->instance) ? NULL : $this->instance;
    $call = static fn (): mixed => $method->invokeArgs($object, $arguments);
    // In development and tests only: a static refiner runs with the
    // global container guarded, so one that reaches for a service is
    // caught rather than written into the schema; a runtime one is
    // refused the services bound to the request a door answers.
    $guarded = FALSE;
    assert(PipelineGuard::asserting($guarded));
    $refined = match (TRUE) {
      !$guarded => $call(),
      !$refiner->runtime => PipelineGuard::guard($refiner->describe(), $call),
      default => PipelineGuard::onBehalf(PipelineGuard::RUNTIME_REFINER, $refiner->class, $refiner->method, $call),
    };
    if ($refined instanceof DoesNotApply && !$refiner->mayNotApply) {
      throw new \LogicException(sprintf(
        '%s answered DoesNotApply::here() without naming DoesNotApply in its return type. Declare it, `: %s|DoesNotApply`, so the schema and the tool know ahead of time that the key may not apply.',
        $refiner->describe(),
        $returns,
      ));
    }
    return $refined;
  }

}
