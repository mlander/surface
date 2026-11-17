<?php

declare(strict_types=1);

namespace Drupal\surface\ResourceBuild;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\surface\Contract\DefinitionSchema;
use Drupal\surface\Contract\NodeDefinition;
use Drupal\surface\Contract\NodeFacts;
use Drupal\surface\SurfaceRefinerInterface;
use Drupal\surface\Refinement\Answer;
use Drupal\surface\Refinement\AnswerFromDefinition;
use Drupal\surface\Refinement\ApplicabilityInterface;
use Drupal\surface\Refinement\DoesNotApply;
use Drupal\surface\Refinement\Narrowing;
use Drupal\surface\Pipeline\PipelineGuard;
use Drupal\surface\Surface;
use PortableSchema\Builder\Schema;

/**
 * The engine's refiner, spoken by one class's #[RefinesInput] methods.
 *
 * One of these per class that refines (the resource or structure, then
 * each alter), registered in the owner's chain of every key it refines,
 * in that order. So the engine runs the owner's methods first and the
 * alters' after, hands each link a deep clone, and checks every link
 * narrower; nothing about refinement, its narrowing check, its
 * cacheability or the form's AJAX and discard machinery knows a method
 * is behind the link.
 *
 * Asked to refine one key, it calls that key's methods on its class in
 * declaration order, each with the definition the one before returned,
 * and with the sibling values each one watches, by parameter order. The
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
 * owner's link holds the owner's class name, because a resource's or
 * structure's refiners are static and neither is ever instantiated; an
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
 * own module's property inside it, each method handed that property's
 * definition, so the method reads exactly as it would on an owner's key.
 * A method watching a key its alter added is handed that key's value
 * the same way, read at its dotted path, `third_party_settings.<module>.
 * <key>`, which is the name the engine knows the dependency by.
 *
 * A method that answers DoesNotApply::here() rules the key out: the link
 * hands back the definition it was handed, marked, and calls none of the
 * key's methods after it, since nothing is narrower. Only a method that
 * names DoesNotApply in its return type may answer it, so what may be
 * ruled out is known before anything runs (mayNotApply()).
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
   *   bound under self::MOUNT refine; NULL for the owner's own link.
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
  public function refine(string $name, Schema $key, array $values, string $who): array {
    if ($name === self::MOUNT && $this->module !== NULL) {
      return $this->refineMounted($key, $values, $who);
    }
    $answers = [];
    $schema = $key;
    $held = NULL;
    foreach ($this->bindings[$name] ?? [] as $refiner) {
      if (NodeFacts::doesNotApply($schema)) {
        break;
      }
      [$answer, $held] = $this->answerOf($refiner, $schema, $values, $name, $who, $held);
      $answers[] = $answer;
      $schema = Answer::apply($schema, $answer, $name, $who, TRUE);
    }
    return $answers;
  }

  /**
   * Gives one method's answer for a key.
   *
   * A method of the old shape is handed the key's definition, a copy, and
   * what it returns is held to the narrowing check (Narrowing) and read as
   * an answer (AnswerFromDefinition); a method of the answer shape is
   * handed the key's schema where its first parameter asks for it, and
   * its answer is held to its key (Answer::assertAuthored()).
   *
   * @param \Drupal\surface\ResourceBuild\RefinerDefinition $refiner
   *   The method.
   * @param \PortableSchema\Builder\Schema $key
   *   The key's schema as it stands.
   * @param array $values
   *   Sibling values, keyed by input key.
   * @param string $name
   *   The key, for a refusal.
   * @param string $who
   *   Who the chain is, in words, for a refusal.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface|null $held
   *   The definition an old-shape method before this one returned for the
   *   key, which this one is handed; NULL to derive it from the schema.
   *
   * @return array{0: \PortableSchema\Builder\Schema|\Drupal\surface\Refinement\DoesNotApply, 1: \Drupal\Core\TypedData\DataDefinitionInterface|null}
   *   The answer, and the definition an old-shape method returned.
   */
  public function answerOf(RefinerDefinition $refiner, Schema $key, array $values, string $name, string $who, ?DataDefinitionInterface $held = NULL): array {
    if ($refiner->takesOldShape()) {
      $before = $held ?? NodeDefinition::of($key);
      $returned = $this->invoke($refiner, Surface::deepClone($before), $values);
      Narrowing::assertNarrows($name, $who, $before, $returned);
      Surface::carryMetadata($before, $returned);
      return [AnswerFromDefinition::between($key, $returned, $before, $refiner->describe(), $name), $returned];
    }
    $answer = $this->answer($refiner, $key, $values);
    Answer::assertAuthored($answer, $name, $refiner->describe());
    return [$answer, NULL];
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
   * Each method's answer is placed at its key, inside the module's map
   * inside the mount, so it is applied there; a method of the old shape is
   * held to the narrowing check as the whole mount, so its refusal names
   * the mount and the path inside it, as the check of the mount's
   * definition names them.
   *
   * Decision: see docs/decisions.md#an-alter-refines-its-own-mounted-key.
   *
   * @param \PortableSchema\Builder\Schema $mount
   *   The mount's schema.
   * @param array $values
   *   Sibling values, keyed by input key.
   * @param string $who
   *   Who the chain is, in words.
   *
   * @return array<int, \PortableSchema\Builder\Schema>
   *   The answers, each for the mount.
   */
  protected function refineMounted(Schema $mount, array $values, string $who): array {
    $module = (string) $this->module;
    $own = DefinitionSchema::propertyNode($mount, $module);
    if ($own === NULL || !is_a(DefinitionSchema::classOf($own), MapDataDefinition::class, TRUE)) {
      return [];
    }
    $answers = [];
    $schema = $mount;
    foreach ($this->bindings[self::MOUNT] ?? [] as $refiner) {
      $own = DefinitionSchema::propertyNode($schema, $module);
      $key = $own === NULL ? NULL : DefinitionSchema::propertyNode($own, $refiner->key);
      if ($key === NULL || NodeFacts::doesNotApply($key)) {
        continue;
      }
      if ($refiner->takesOldShape()) {
        $before = NodeDefinition::of($key);
        $returned = $this->invoke($refiner, Surface::deepClone($before), $values);
        $wrap = static fn (DataDefinitionInterface $definition): MapDataDefinition => MapDataDefinition::create()
          ->setPropertyDefinition($module, MapDataDefinition::create()->setPropertyDefinition($refiner->key, $definition));
        Narrowing::assertNarrows(self::MOUNT, $who, $wrap($before), $wrap($returned));
        Surface::carryMetadata($before, $returned);
        $answer = AnswerFromDefinition::between($key, $returned, $before, $refiner->describe(), $refiner->key);
      }
      else {
        $answer = $this->answer($refiner, $key, $values);
        Answer::assertAuthored($answer, $refiner->key, $refiner->describe());
      }
      $placed = Schema::any()->with('properties', [
        $module => Schema::any()->with('properties', [
          $refiner->key => $answer instanceof DoesNotApply ? Schema::boolean(FALSE) : $answer,
        ]),
      ]);
      $answers[] = $placed;
      $schema = Answer::apply($schema, $placed, self::MOUNT, $who, TRUE);
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
   * Calls one method with the definition and the siblings it watches.
   *
   * @param \Drupal\surface\ResourceBuild\RefinerDefinition $refiner
   *   The method.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition to refine.
   * @param array $values
   *   Sibling values, keyed by input key, a mounted one by its dotted
   *   path; every watched key is present.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface
   *   What the method returned; the definition handed over, marked, when
   *   it answered that the key does not apply.
   *
   * @throws \LogicException
   *   When the method returned something other than a definition or
   *   DoesNotApply, or DoesNotApply without declaring it.
   */
  public function invoke(RefinerDefinition $refiner, DataDefinitionInterface $definition, array $values): DataDefinitionInterface {
    $refined = $this->call($refiner, [$definition], $values, 'DataDefinitionInterface');
    if ($refined instanceof DoesNotApply) {
      // Decision: see docs/decisions.md#a-key-that-does-not-apply.
      return DoesNotApply::marked($definition);
    }
    if (!$refined instanceof DataDefinitionInterface) {
      throw new \LogicException(sprintf(
        '%s returned %s; a #[RefinesInput] method returns the definition it was handed, tightened, or DoesNotApply::here().',
        $refiner->describe(),
        get_debug_type($refined),
      ));
    }
    return $refined;
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
