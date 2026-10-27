<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassMethodNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Flags a static refiner, or a compute(), whose body reaches for the container.
 *
 * A `#[RefinesInput]` method that does not declare `runtime: TRUE` is a
 * pure function of the keys it watches, which the served contract runs
 * ahead of time; a call to `\Drupal::` reads site state or a service, so
 * the method is a runtime rule wearing the static kind. The build and
 * the guarded container catch it when it runs; this says so before it
 * runs at all. A computed key's class, the static compute() of a class
 * implementing ComputesValueInterface, is held to the same rule.
 *
 * @implements \PHPStan\Rules\Rule<\PHPStan\Node\InClassMethodNode>
 *
 * @see docs/refinement.md#enforcing-the-kind
 */
final class StaticRefinerRule implements Rule {

  /**
   * The attribute that makes a method a refiner.
   */
  public const ATTRIBUTE = 'Drupal\data_surface\Resource\Attribute\RefinesInput';

  /**
   * The interface whose static compute() is held to the same rule.
   */
  public const COMPUTES = 'Drupal\data_surface\Resource\ComputesValueInterface';

  /**
   * The `\Drupal::` shortcuts a compute() may call, by class.
   *
   * The stock MachineName transliterates, as PipelineGuard::COMPUTE_ALLOWED
   * admits it to.
   *
   * @see docs/decisions.md#a-machine-name-is-a-computed-identity
   */
  public const COMPUTE_ALLOWED = [
    'Drupal\data_surface\Compute\MachineName' => ['transliteration'],
  ];

  /**
   * {@inheritdoc}
   */
  public function getNodeType(): string {
    return InClassMethodNode::class;
  }

  /**
   * {@inheritdoc}
   */
  public function processNode(Node $node, Scope $scope): array {
    $method = $node->getOriginalNode();
    if (!$method->isStatic() || $method->stmts === NULL) {
      return [];
    }
    if (static::isCompute($method->name->toString(), $scope)) {
      return static::errors($method, $scope, 'The computed key\'s %s::%s() calls \\Drupal::%s(), which reads a service or site state. A computed key is a pure function of the keys it is computed from: a value that needs a service, the clock or the request is a storage\'s to add in its prepare().', 'dataSurface.compute', self::COMPUTE_ALLOWED[$scope->getClassReflection()?->getName() ?? ''] ?? []);
    }
    if (!static::isStaticRefiner($method->attrGroups)) {
      return [];
    }
    return static::errors($method, $scope, 'The static refiner %s::%s() calls \\Drupal::%s(), which reads a service or site state. A static refiner is a pure function of the keys it watches: point at a list with a constraint, or declare #[RefinesInput(..., runtime: TRUE)].', 'dataSurface.staticRefiner');
  }

  /**
   * Flags each call to `\Drupal::` in a method.
   *
   * @param \PhpParser\Node\Stmt\ClassMethod $method
   *   The method.
   * @param \PHPStan\Analyser\Scope $scope
   *   Its scope.
   * @param string $message
   *   The message, with the class, the method and the call.
   * @param string $identifier
   *   The error's identifier.
   * @param string[] $allowed
   *   The shortcuts the method may call.
   *
   * @return list<\PHPStan\Rules\IdentifierRuleError>
   *   One error per call.
   */
  protected static function errors(ClassMethod $method, Scope $scope, string $message, string $identifier, array $allowed = []): array {
    $errors = [];
    $calls = (new NodeFinder())->findInstanceOf($method->stmts, StaticCall::class);
    foreach ($calls as $call) {
      if (!$call->class instanceof Name || ltrim($call->class->toString(), '\\') !== 'Drupal') {
        continue;
      }
      $called = $call->name instanceof Identifier ? $call->name->toString() : '...';
      if (in_array($called, $allowed, TRUE)) {
        continue;
      }
      $errors[] = RuleErrorBuilder::message(sprintf(
        $message,
        $scope->getClassReflection()?->getName() ?? '',
        $method->name->toString(),
        $called,
      ))
        ->identifier($identifier)
        ->line($call->getStartLine())
        ->build();
    }
    return $errors;
  }

  /**
   * Answers whether a method computes a computed key's value.
   *
   * @param string $name
   *   The method's name.
   * @param \PHPStan\Analyser\Scope $scope
   *   Its scope.
   *
   * @return bool
   *   TRUE for compute() on a class implementing ComputesValueInterface.
   */
  protected static function isCompute(string $name, Scope $scope): bool {
    $class = $scope->getClassReflection();
    return $name === 'compute' && $class !== NULL && $class->implementsInterface(self::COMPUTES);
  }

  /**
   * Answers whether attributes make a method a static refiner.
   *
   * @param \PhpParser\Node\AttributeGroup[] $groups
   *   The method's attribute groups.
   *
   * @return bool
   *   TRUE when one is #[RefinesInput] not declaring runtime: TRUE.
   */
  protected static function isStaticRefiner(array $groups): bool {
    foreach ($groups as $group) {
      foreach ($group->attrs as $attribute) {
        if (ltrim($attribute->name->toString(), '\\') !== self::ATTRIBUTE) {
          continue;
        }
        foreach ($attribute->args as $position => $arg) {
          if (static::isRuntime($arg, $position)) {
            return FALSE;
          }
        }
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Answers whether an argument declares the refiner runtime.
   *
   * @param \PhpParser\Node\Arg $arg
   *   The argument.
   * @param int $position
   *   Its position: runtime is the third.
   *
   * @return bool
   *   TRUE for `runtime: TRUE`.
   */
  protected static function isRuntime(Arg $arg, int $position): bool {
    $named = $arg->name?->toString();
    if ($named !== 'runtime' && !($named === NULL && $position === 2)) {
      return FALSE;
    }
    return $arg->value instanceof ConstFetch && strtolower($arg->value->name->toString()) === 'true';
  }

}
