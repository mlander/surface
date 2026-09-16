<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\data_surface\DataSurface;
use Drupal\data_surface\DefinitionMap;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Plugin\Validation\Constraint\LabeledChoiceConstraint;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\ChoiceValidator;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that LabeledChoice is core's Choice, with labels.
 *
 * The constraint carries labels so a value list can be declared once,
 * but what it validates has to be exactly what Choice validates, or the
 * labels would have been bought with a change in meaning. It is a Choice
 * subclass validated by Choice's own validator, so the comparison is
 * Choice's comparison — strict — and the several spellings a form and a
 * payload give the same value are made to agree by the pipeline's
 * casting, before anything validates, rather than by a looser constraint.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class LabeledChoiceTest extends DataSurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'data_surface'];

  /**
   * The message the constraint reports, which is core's Choice message.
   */
  protected const MESSAGE = 'The value you selected is not a valid choice.';

  /**
   * Builds a one-key surface carrying a labeled choice.
   *
   * Spelled canonically: the allowed values are Choice's own list, the
   * labels hang beside them keyed by value. Integer choices have to be
   * written this way, because an integer-keyed label map cannot be told
   * from a list of values.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The surface.
   */
  protected function surface(): DataSurfaceInterface {
    return new DataSurface(
      DefinitionMap::fromArrays(
        definitions: [
          'mode' => DataDefinition::create('integer')
            ->setLabel('Mode')
            ->setRequired(FALSE)
            ->addConstraint('LabeledChoice', [
              'choices' => [0, 1, 2],
              'labels' => [
                0 => new TranslatableMarkup('Disabled'),
                1 => new TranslatableMarkup('Optional'),
                2 => new TranslatableMarkup('Required'),
              ],
            ]),
        ],
      ),
    );
  }

  /**
   * Instantiates the constraint the way a definition's options do.
   *
   * @param array $options
   *   The constraint options.
   *
   * @return \Drupal\data_surface\Plugin\Validation\Constraint\LabeledChoiceConstraint
   *   The constraint.
   */
  protected function constraint(array $options): LabeledChoiceConstraint {
    $constraint = $this->container->get('validation.constraint')
      ->create('LabeledChoice', $options);
    $this->assertInstanceOf(LabeledChoiceConstraint::class, $constraint);
    return $constraint;
  }

  /**
   * Tests that the constraint is a Choice, validated by Choice.
   *
   * This is the whole point of the subclass: every consumer that already
   * reads core's Choice — a schema emitter, a form builder, core's own
   * AllowedValues machinery — sees a labeled choice without being taught
   * a second constraint, and the upstream ask becomes "labels on Choice"
   * rather than "a new constraint".
   */
  public function testSubclassesChoice(): void {
    $constraint = $this->constraint([
      'choices' => ['star', 'flame'],
      'labels' => ['star' => 'Star', 'flame' => 'Flame'],
    ]);
    $this->assertInstanceOf(Choice::class, $constraint);
    $this->assertSame(ChoiceValidator::class, $constraint->validatedBy());
    $this->assertSame(['star', 'flame'], $constraint->choices);
    // Choice's own defaults are untouched, including the strict
    // comparison its validator refuses to run without.
    $this->assertTrue($constraint->strict);
    $this->assertFalse($constraint->multiple);
    $this->assertSame(self::MESSAGE, $constraint->message);
  }

  /**
   * Tests that the constraint carries nothing but the list and its meaning.
   *
   * It is expected to land in core, so it may know about values, labels
   * and help text and about nothing else: no surface, no widget, no
   * options resolver.
   */
  public function testCarriesNoModuleSpecificOption(): void {
    $constraint = $this->constraint([
      'choices' => ['star'],
      'labels' => ['star' => 'Star'],
      'descriptions' => ['star' => 'Shown on featured items.'],
    ]);
    $own = array_diff(
      array_keys(get_object_vars($constraint)),
      array_keys(get_object_vars(new Choice(choices: ['star']))),
    );
    $this->assertSame(['labels', 'descriptions'], array_values($own));
  }

  /**
   * Tests the short spelling, a map of value to label.
   *
   * Which spelling is meant is decided by whether labels are given
   * separately, so the same integer keys read one way with them and the
   * other way without, and neither reading is a guess.
   */
  public function testShortSpellingIsReadAsValuesAndLabels(): void {
    $short = $this->constraint([
      'choices' => ['star' => 'Star', 'flame' => 'Flame'],
    ]);
    $this->assertSame(['star', 'flame'], $short->choices);
    $this->assertSame(['star' => 'Star', 'flame' => 'Flame'], $short->labels);

    $integers = $this->constraint([
      'choices' => [0 => 'Disabled', 1 => 'Optional'],
    ]);
    $this->assertSame([0, 1], $integers->choices);
    $this->assertSame([0 => 'Disabled', 1 => 'Optional'], $integers->labels);

    $canonical = $this->constraint([
      'choices' => [0, 1],
      'labels' => [0 => 'Disabled', 1 => 'Optional'],
    ]);
    $this->assertSame([0, 1], $canonical->choices);
    $this->assertSame([0 => 'Disabled', 1 => 'Optional'], $canonical->labels);
  }

  /**
   * Tests a declared choice passes.
   */
  public function testDeclaredChoicePasses(): void {
    $this->assertCount(0, $this->pipeline()->validate($this->surface(), ['mode' => 2]));
  }

  /**
   * Tests a value outside the declared choices is refused.
   */
  public function testUnknownValueViolates(): void {
    $violations = $this->pipeline()->validate($this->surface(), ['mode' => 7]);

    $this->assertSame(['mode'], $violations->keys());
    $this->assertSame('', $violations->byKey('mode')[0]->path);
    // The message arrives as the object the constraint built, with its
    // placeholders still placeholders: flattening it here would render
    // them once and leave whatever prints the violation to escape the
    // result a second time.
    $message = $violations->byKey('mode')[0]->message;
    $this->assertInstanceOf(TranslatableMarkup::class, $message);
    $this->assertSame('7', $message->getArguments()['%value']);
    $this->assertSame(self::MESSAGE, (string) $message);
  }

  /**
   * Tests no value given passes, as it does for core's Choice.
   */
  public function testNoValuePasses(): void {
    $definition = DataDefinition::create('integer')
      ->setLabel('Mode')
      ->addConstraint('LabeledChoice', ['choices' => [0, 1], 'labels' => []]);
    $typed_data = $this->container->get('typed_data_manager')->create($definition, NULL);
    $this->assertCount(0, $typed_data->validate());
  }

  /**
   * Tests a value that cannot name a choice at all is refused.
   */
  public function testNonScalarValueViolates(): void {
    $definition = DataDefinition::create('any')
      ->setLabel('Mode')
      ->addConstraint('LabeledChoice', ['choices' => [0, 1], 'labels' => []]);
    $violations = $this->container->get('typed_data_manager')->create($definition, ['nested'])->validate();
    $this->assertCount(1, $violations);
    $this->assertSame(self::MESSAGE, (string) $violations->get(0)->getMessage());
  }

  /**
   * Tests that the casting, not the constraint, reconciles '1' and 1.
   *
   * Core's ChoiceValidator compares with in_array(..., strict), so the
   * string '1' is not the integer 1 and a form submission, where every
   * value arrives as a string, would fail against integer choices. The
   * surface makes the two agree one layer earlier: accept() casts every
   * value to its definition's type before anything validates, so what
   * reaches the constraint is already the integer the choices are
   * written in. Keeping the reconciliation there is what lets the
   * constraint be core's Choice exactly.
   */
  public function testCastingReconcilesTheSpellings(): void {
    $surface = $this->surface();

    $values = $this->pipeline()->accept($surface, ['mode' => '1']);
    $this->assertSame(1, $values['mode']);
    $this->assertCount(0, $this->pipeline()->validate($surface, $values));

    // Without the cast, the strict comparison is the whole answer, and
    // it is core's answer.
    $definition = DataDefinition::create('string')
      ->setLabel('Mode')
      ->addConstraint('LabeledChoice', ['choices' => [1], 'labels' => [1 => 'Optional']]);
    $manager = $this->container->get('typed_data_manager');
    $this->assertCount(1, $manager->create($definition, '1')->validate());
    $this->assertCount(
      1,
      $manager->create(
        DataDefinition::create('string')->addConstraint('Choice', ['choices' => [1]]),
        '1',
      )->validate(),
    );
  }

}
