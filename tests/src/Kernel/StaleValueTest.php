<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\data_surface\DataSurface;
use Drupal\data_surface\DefinitionMap;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Pipeline\DataSurfacePipelineInterface;
use Drupal\data_surface\Target\StateTarget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests what happens to a stored value the option list no longer offers.
 *
 * The third state a value can be in. A key is not only answered or
 * unanswered: it can hold an answer that was true when it was given and
 * is not true now, because a bundle was deleted, a module uninstalled, a
 * refinement narrowed. Nothing the caller did caused it and nothing the
 * caller sends can be blamed for it, so the rules are their own:
 *
 * - Display never errors. The select renders with no real option chosen
 *   and a sentinel naming what is missing, never with the stale value
 *   injected back into the list and never pre-set to some other option.
 * - Untouched means keep. The sentinel maps back to the stored value on
 *   extraction, so an unrelated save cannot clear it — which is the trap,
 *   because an empty select otherwise means clear.
 * - Stale never blocks. A value identical to what is stored and outside
 *   the list warns and saves; a value that differs is refused however far
 *   outside it falls, because that one was chosen.
 * - Required splits. Never set is the ordinary required violation; stale
 *   and required stashes and nags like any other stale.
 *
 * Every row of that is asserted here on both paths a value can arrive by,
 * a generated form and a raw payload, because the two go through one
 * pipeline and are allowed to differ only in what a browser submits.
 *
 * @see docs/semantics.md
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class StaleValueTest extends DataSurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'data_surface'];

  /**
   * The value stored before the list stopped offering it.
   */
  protected const GONE = 'gone';

  /**
   * The state key the payload path stores through.
   */
  protected const STATE_KEY = 'data_surface_test.stale';

  /**
   * Builds the fixture: one select, and one text field beside it.
   *
   * The neighbor is not decoration. The trap this whole model exists to
   * close is an unrelated save — somebody edits the text field, leaves
   * the select alone, and the stored value it could not render is gone.
   * A fixture with one key cannot express that.
   *
   * @param bool $required
   *   Whether the select is required.
   * @param array $choices
   *   What the select offers now.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The surface.
   */
  protected function surface(bool $required, array $choices = ['keep', 'other']): DataSurfaceInterface {
    $pick = DataDefinition::create('string')
      ->setLabel('Pick')
      ->setDescription('What to feature.')
      ->setRequired($required)
      ->addConstraint('LabeledChoice', [
        'choices' => $choices,
        'labels' => array_combine($choices, array_map('ucfirst', $choices)),
      ]);
    return new DataSurface(DefinitionMap::fromArrays(definitions: [
      'pick' => $pick,
      'note' => DataDefinition::create('string')->setLabel('Note')->setRequired(FALSE),
    ]));
  }

  /**
   * Extracts values the way a browser would submit the built form.
   *
   * A select submits whatever option is selected, so "left alone" is the
   * element's own default value read back off the element rather than a
   * string written into the test. That is the only honest simulation of
   * untouched, and it is the case the trap lives in.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The surface to build and extract against.
   * @param array $current
   *   The stored values the form is built from.
   * @param array $submitted
   *   What the browser sends, keyed by surface key. A key left out is
   *   submitted as the element came up, which is what leaving a control
   *   alone does.
   *
   * @return array
   *   The accepted values.
   */
  protected function extract(DataSurfaceInterface $surface, array $current, array $submitted = []): array {
    $form = $this->formBuilder()->buildSurfaceForm($surface, $current, new FormState());
    $values = [];
    foreach (['pick', 'note'] as $key) {
      $form[$key]['#parents'] = [$key];
      $values[$key] = array_key_exists($key, $submitted)
        ? $submitted[$key]
        : ($form[$key]['#default_value'] ?? '');
    }
    $form_state = new FormState();
    $form_state->setValues($values);
    return $this->formBuilder()->extractSurfaceValues($surface, $form, $form_state, $current);
  }

  /**
   * Builds the select element alone, for the rendering assertions.
   *
   * @param bool $required
   *   Whether the select is required.
   * @param mixed $value
   *   The stored value.
   *
   * @return array
   *   The select element.
   */
  protected function element(bool $required, mixed $value): array {
    $surface = $this->surface($required);
    return $this->formBuilder()
      ->buildSurfaceForm($surface, ['pick' => $value], new FormState())['pick'];
  }

  /**
   * The target the payload path writes through.
   *
   * @return \Drupal\data_surface\Target\StateTarget
   *   The target.
   */
  protected function target(): StateTarget {
    return new StateTarget($this->container->get('state'), self::STATE_KEY);
  }

  /**
   * Whether a select is required.
   *
   * @return array<string, array{0: bool}>
   *   Test cases.
   */
  public static function requiredCases(): array {
    return ['required' => [TRUE], 'optional' => [FALSE]];
  }

  /**
   * Tests that a stale value renders with no real option selected.
   */
  #[DataProvider('requiredCases')]
  public function testStaleSelectComesUpOnTheSentinel(bool $required): void {
    $element = $this->element($required, self::GONE);

    // Nothing real is selected, and nothing was guessed at: the element
    // comes up on the sentinel, which is not a value the key can hold.
    $this->assertSame(DataSurfacePipelineInterface::KEEP_STALE, $element['#default_value']);
    $this->assertArrayHasKey(DataSurfacePipelineInterface::KEEP_STALE, $element['#options']);
    // The sentinel says which value is missing, so the person is not
    // told only that something is wrong.
    $this->assertStringContainsString(self::GONE, (string) $element['#options'][DataSurfacePipelineInterface::KEEP_STALE]);
    // And the stale value is never offered as a choice: putting it back
    // would let somebody re-save a reference to something gone, and
    // would make the list that is offered wider than the one that
    // validates.
    $this->assertArrayNotHasKey(self::GONE, $element['#options']);
    // The real options are all still there.
    $this->assertArrayHasKey('keep', $element['#options']);
    $this->assertArrayHasKey('other', $element['#options']);
    // The description says what leaving it alone will do, where a screen
    // reader reaches it rather than only in the option label.
    $this->assertStringContainsString('is kept until you choose another', (string) $element['#description']);
    $this->assertContains('data-surface-stale', $element['#attributes']['class']);
  }

  /**
   * Tests that only an optional stale select can still be cleared.
   */
  public function testTheEmptyChoiceSurvivesBesideTheSentinel(): void {
    // Optional: keep and clear are different answers and both have to be
    // expressible, so the empty choice stays beside the sentinel.
    $optional = $this->element(FALSE, self::GONE);
    $this->assertSame('', $optional['#empty_value']);
    $this->assertNotEmpty($optional['#empty_option']);

    // Required: the sentinel and the real options, and nothing else. An
    // empty choice here would offer a way to empty a key the definition
    // says must be answered.
    $required = $this->element(TRUE, self::GONE);
    $this->assertArrayNotHasKey('#empty_value', $required);
    $this->assertSame(
      [DataSurfacePipelineInterface::KEEP_STALE, 'keep', 'other'],
      array_keys($required['#options']),
    );
  }

  /**
   * Tests that a key that was never set renders as it always did.
   */
  #[DataProvider('requiredCases')]
  public function testNeverSetSelectIsUntouchedByTheStaleRules(bool $required): void {
    $element = $this->element($required, NULL);

    $this->assertNull($element['#default_value']);
    $this->assertArrayNotHasKey(DataSurfacePipelineInterface::KEEP_STALE, $element['#options']);
    $this->assertSame('What to feature.', (string) $element['#description']);
    $this->assertArrayNotHasKey('#attributes', $element);
  }

  /**
   * Tests that a value still on the list is not treated as stale.
   */
  #[DataProvider('requiredCases')]
  public function testLiveValueRendersAsTheChosenOption(bool $required): void {
    $element = $this->element($required, 'keep');

    $this->assertSame('keep', $element['#default_value']);
    $this->assertArrayNotHasKey(DataSurfacePipelineInterface::KEEP_STALE, $element['#options']);
  }

  /**
   * Tests the trap: an unrelated save keeps the value it could not show.
   */
  #[DataProvider('requiredCases')]
  public function testAnUnrelatedSaveKeepsTheStaleValue(bool $required): void {
    $surface = $this->surface($required);
    $current = ['pick' => self::GONE, 'note' => 'before'];
    $this->container->get('state')->set(self::STATE_KEY, $current);

    // Somebody edits the note and never touches the select.
    $values = $this->extract($surface, $current, ['note' => 'after']);
    $this->assertSame(self::GONE, $values['pick']);
    $this->assertSame('after', $values['note']);

    // It validates, so the host saves, and it saves the value the form
    // could not render rather than the nothing an empty select means.
    $violations = $this->pipeline()->validate($surface, $values, $current);
    $this->assertTrue($violations->isEmpty());
    $this->assertCount(1, $violations->stale());
    $this->assertSame('pick', $violations->stale()[0]->key);

    $result = $this->pipeline()->submit($surface, $values, $this->target());
    $this->assertTrue($result->isValid());
    $this->assertTrue($result->committed);
    $this->assertSame(self::GONE, $this->container->get('state')->get(self::STATE_KEY)['pick']);
  }

  /**
   * Tests that choosing a real option replaces the stale value.
   */
  #[DataProvider('requiredCases')]
  public function testRePickingStoresTheNewValue(bool $required): void {
    $surface = $this->surface($required);
    $current = ['pick' => self::GONE, 'note' => 'before'];

    $values = $this->extract($surface, $current, ['pick' => 'keep']);
    $this->assertSame('keep', $values['pick']);

    $violations = $this->pipeline()->validate($surface, $values, $current);
    $this->assertTrue($violations->isEmpty());
    // Nothing is stale any more: the sentinel was a placeholder, not a
    // value, and the moment a real option is chosen the key is ordinary.
    $this->assertFalse($violations->hasStale());
  }

  /**
   * Tests that an optional stale select can still be emptied.
   */
  public function testClearingAnOptionalStaleSelectClearsIt(): void {
    $surface = $this->surface(FALSE);
    $current = ['pick' => self::GONE, 'note' => 'before'];

    // The empty choice, which is what the browser submits for "- None -".
    $values = $this->extract($surface, $current, ['pick' => '']);
    $this->assertNull($values['pick']);

    $violations = $this->pipeline()->validate($surface, $values, $current);
    $this->assertTrue($violations->isEmpty());
    $this->assertFalse($violations->hasStale());
  }

  /**
   * Tests that never set plus required is still an ordinary violation.
   */
  public function testNeverSetAndRequiredIsStillRefused(): void {
    $surface = $this->surface(TRUE);
    $values = $this->extract($surface, ['pick' => NULL, 'note' => NULL]);

    $this->assertNull($values['pick']);
    $violations = $this->pipeline()->validate($surface, $values, ['pick' => NULL]);
    $this->assertFalse($violations->isEmpty());
    $this->assertSame(['pick'], $violations->keys());
    $this->assertFalse($violations->hasStale());
    $this->assertSame('Pick is required.', (string) $violations->byKey('pick')[0]->message);
  }

  /**
   * Tests that stale plus required stashes and nags like any other stale.
   */
  public function testStaleAndRequiredStashesRatherThanRefuses(): void {
    $surface = $this->surface(TRUE);
    $current = ['pick' => self::GONE];

    $violations = $this->pipeline()->validate($surface, ['pick' => self::GONE], $current);

    $this->assertTrue($violations->isEmpty());
    $this->assertCount(1, $violations->stale());
    // The nag names the value, so a person reading only the message
    // knows what is about to be kept.
    $this->assertStringContainsString(self::GONE, (string) $violations->stale()[0]->message);
  }

  /**
   * Tests that a payload resubmitting the stored stale value saves it.
   */
  public function testPayloadResubmittingTheStaleValueWarnsAndSaves(): void {
    $this->container->get('state')->set(self::STATE_KEY, ['pick' => self::GONE, 'note' => 'before']);
    $surface = $this->surface(TRUE);

    $result = $this->pipeline()->submit($surface, ['pick' => self::GONE, 'note' => 'after'], $this->target());

    $this->assertTrue($result->isValid());
    $this->assertTrue($result->committed);
    // The set travels with the result rather than being replaced by an
    // empty one, which is how a caller with no form in front of it
    // learns there is something to re-choose.
    $this->assertCount(1, $result->violations->stale());
    $stored = $this->container->get('state')->get(self::STATE_KEY);
    $this->assertSame(self::GONE, $stored['pick']);
    $this->assertSame('after', $stored['note']);
  }

  /**
   * Tests that a payload naming no key at all keeps the stale value.
   */
  public function testPayloadOmittingTheKeyKeepsTheStaleValue(): void {
    $this->container->get('state')->set(self::STATE_KEY, ['pick' => self::GONE, 'note' => 'before']);
    $surface = $this->surface(TRUE);

    $result = $this->pipeline()->submit($surface, ['note' => 'after'], $this->target());

    $this->assertTrue($result->committed);
    $this->assertSame(self::GONE, $this->container->get('state')->get(self::STATE_KEY)['pick']);
  }

  /**
   * Tests that a new out-of-set value is refused however stale the key.
   */
  public function testPayloadSendingAnotherOutOfSetValueIsRefused(): void {
    $this->container->get('state')->set(self::STATE_KEY, ['pick' => self::GONE, 'note' => 'before']);
    $surface = $this->surface(TRUE);

    $result = $this->pipeline()->submit($surface, ['pick' => 'invented'], $this->target());

    // Chosen, therefore judged: the line between "re-choose this" and
    // "that is not a valid answer" is whether the value is the one that
    // was already there.
    $this->assertFalse($result->isValid());
    $this->assertFalse($result->committed);
    $this->assertSame(['pick'], $result->violations->keys());
    $this->assertFalse($result->violations->hasStale());
    $this->assertSame(self::GONE, $this->container->get('state')->get(self::STATE_KEY)['pick']);
  }

  /**
   * Tests that a refusal with no list behind it is never called stale.
   */
  public function testRefusalWithNoOptionListIsAnOrdinaryViolation(): void {
    $surface = new DataSurface(DefinitionMap::fromArrays(definitions: [
      'pick' => DataDefinition::create('string')
        ->setLabel('Pick')
        ->setRequired(FALSE)
        ->addConstraint('Length', ['max' => 3]),
    ]));

    // The value is exactly what is stored, and it is still refused: a key
    // with no list of values has no membership to have fallen out of, so
    // whatever its constraints said, they said on their own terms.
    $violations = $this->pipeline()->validate($surface, ['pick' => 'far too long'], ['pick' => 'far too long']);

    $this->assertFalse($violations->isEmpty());
    $this->assertFalse($violations->hasStale());
  }

  /**
   * Tests that a multiple select's items are refused as they always were.
   */
  public function testListsAreNeverStale(): void {
    $item = DataDefinition::create('string')
      ->addConstraint('Choice', ['choices' => ['keep', 'other']]);
    $surface = new DataSurface(DefinitionMap::fromArrays(definitions: [
      'picks' => ListDataDefinition::create('string')
        ->setItemDefinition($item)
        ->setLabel('Picks')
        ->setRequired(FALSE),
    ]));

    // Deliberate: a partial keep — some items stashed, others chosen —
    // is a shape neither the widget nor the rule has, so a list whose
    // items went stale is refused the way it always was.
    $violations = $this->pipeline()->validate($surface, ['picks' => [self::GONE]], ['picks' => [self::GONE]]);

    $this->assertFalse($violations->isEmpty());
    $this->assertFalse($violations->hasStale());
  }

  /**
   * Tests that the sentinel is only ever honoured where it was rendered.
   */
  public function testTheMarkerIsAnOrdinaryStringOnAnyOtherKey(): void {
    $surface = $this->surface(FALSE);
    $current = ['pick' => 'keep', 'note' => NULL];

    // The select came up on a real option, so it carries no stash, and
    // the marker spelled into it by hand is just a value the key does
    // not offer.
    $values = $this->extract($surface, $current, ['pick' => DataSurfacePipelineInterface::KEEP_STALE]);
    $this->assertSame(DataSurfacePipelineInterface::KEEP_STALE, $values['pick']);
    $this->assertFalse($this->pipeline()->validate($surface, $values, $current)->isEmpty());
  }

  /**
   * Tests that what the element stashes is a value and nothing else.
   */
  public function testTheStashIsSerializable(): void {
    $element = $this->element(TRUE, self::GONE);

    // The form cache rule, asserted rather than trusted: what a built
    // element carries is ids and values, never anything with behavior.
    $this->assertSame(self::GONE, $element['#data_surface_stale']);
    // Equal, not identical: the messages are objects and come back as
    // new ones, which is the whole point of round-tripping them.
    $this->assertEquals($element, unserialize(serialize($element), [
      'allowed_classes' => [TranslatableMarkup::class],
    ]));
  }

}
