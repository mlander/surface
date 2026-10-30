<?php

declare(strict_types=1);

namespace Drupal\surface\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\surface\Pipeline\PipelineGuard;
use Drupal\surface\Resource\DeletableStorageInterface;
use Drupal\surface\Resource\OperationContext;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the SurfaceUnique constraint, and answers the form's question.
 *
 * Decision: see docs/decisions.md#a-machine-name-is-a-computed-identity.
 */
final class UniqueConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * Constructs a UniqueConstraintValidator.
   *
   * @param \Drupal\Core\DependencyInjection\ClassResolverInterface $classResolver
   *   The class resolver, which hands over the storage the constraint
   *   names, an autowired service.
   */
  public function __construct(
    protected readonly ClassResolverInterface $classResolver,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('class_resolver'));
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    assert($constraint instanceof UniqueConstraint);
    if ((!is_string($value) && !is_int($value)) || $value === '') {
      return;
    }
    if ($this->taken($constraint->storage, $constraint->key, (string) $value)) {
      $this->context->buildViolation($constraint->message, [
        '@thing' => (string) $constraint->thing,
        '@id' => (string) $value,
      ])
        ->setCode(UniqueConstraint::TAKEN_ERROR)
        ->addViolation();
    }
  }

  /**
   * Answers whether a thing is stored under an identity.
   *
   * The one question behind the constraint and the form's machine name
   * element (Form\MachineNameElement::exists()), asked of the storage on
   * the operation's behalf.
   *
   * @param string $storage
   *   The class of the resource's storage.
   * @param string $key
   *   The identity key.
   * @param string $id
   *   The identity asked about.
   *
   * @return bool
   *   TRUE when a thing is stored under it; FALSE too for a storage that
   *   cannot say.
   */
  public function taken(string $storage, string $key, string $id): bool {
    if ($storage === '' || $key === '' || $id === '') {
      return FALSE;
    }
    $instance = $this->classResolver->getInstanceFromDefinition($storage);
    if (!$instance instanceof DeletableStorageInterface) {
      return FALSE;
    }
    $context = new OperationContext(known: [$key => $id]);
    return (bool) PipelineGuard::onBehalf(PipelineGuard::STORAGE, $storage, 'exists', static fn (): bool => $instance->exists($context));
  }

}
