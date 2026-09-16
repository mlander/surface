<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\data_surface\Pipeline\DataSurfacePipelineInterface;
use Drupal\data_surface_test\Plugin\Block\DataSurfaceChainTestBlock;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Tests what a refinement rebuild does to input it has just orphaned.
 *
 * Two ways a value can stop being allowed, opposite treatments, and the
 * whole distinction is where the invalidation came from.
 *
 * Out of the form, in storage: a bundle was deleted, a module was
 * uninstalled. Nobody did it here and nobody can be told they did it
 * wrong, so the value is kept, the select says what is missing, and the
 * stored value only ever clears on a real submit. That is the stale
 * model and StaleValueTest owns it.
 *
 * Inside the form, in the person's own half-finished edit: they chose a
 * bundle and then changed the entity type above it. Nothing was
 * submitted — touching one select says one thing about one key — so the
 * orphaned input is not an answer to be judged, it is an answer to a
 * question that is no longer on the screen. It is discarded, silently,
 * and the key falls back: to what is stored if that is still offered, to
 * the stale placeholder if something is stored and is not, and otherwise
 * to nothing chosen at all.
 *
 * The bug this closes was the opposite of every line of that. The
 * refinement AJAX limited validation to the whole surface container, so
 * the rebuild judged the orphan as a wrong answer and flagged it — and
 * because Form API skips a rebuild outright once anything has errored,
 * the container came back refined against the choice the person had just
 * replaced. One line, both halves: the error nobody caused, and the
 * select that would not follow its own parent.
 *
 * @see \Drupal\Tests\data_surface\Kernel\StaleValueTest
 * @see docs/forms.md
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class RefinementDiscardTest extends DataSurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'block',
    'node',
    'field',
    'text',
    'data_surface',
    'data_surface_demo',
    'data_surface_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
  }

  /**
   * Rebuilds the chain block's form the way an AJAX round trip does.
   *
   * The triggering element and the raw input, which is the pair a
   * rebuild actually has: the values are gone by then, because a
   * refinement trigger limits validation to itself and Form API answers
   * a limit by throwing away everything outside it.
   *
   * @param array $stored
   *   What the block has saved.
   * @param string $trigger
   *   The surface key that was touched.
   * @param array $input
   *   The whole surface as the browser sent it back.
   * @param \Drupal\Core\Form\FormStateInterface|null $form_state
   *   The form state to drive, so a caller can read it afterwards.
   *
   * @return array
   *   The rebuilt surface container.
   */
  protected function rebuild(array $stored, string $trigger, array $input, ?FormStateInterface $form_state = NULL): array {
    $block = $this->container->get('plugin.manager.block')
      ->createInstance('data_surface_chain_test_block', $stored);
    $this->assertInstanceOf(DataSurfaceChainTestBlock::class, $block);
    $form_state ??= new FormState();
    $form_state->setTriggeringElement(['#parents' => ['settings', $trigger]]);
    $form_state->setUserInput(['settings' => $input]);
    return $block->buildConfigurationForm([], $form_state);
  }

  /**
   * Reads an element's options as plain strings.
   *
   * @param array $element
   *   The element.
   *
   * @return array
   *   The options.
   */
  protected function offered(array $element): array {
    return array_map('strval', $element['#options'] ?? []);
  }

  /**
   * Tests the reported scenario end to end, through Form API itself.
   *
   * Choose a bundle, then change the entity type above it. Everything
   * about the bug lives in what Form API does between those two clicks,
   * so this drives the real thing — build, validate, rebuild — rather
   * than the pieces: a browser's POST, the triggering element the way
   * the AJAX layer names it, and the container that comes back.
   */
  public function testTheReportedScenarioFlagsNothingAndFollowsTheNewParent(): void {
    $request = Request::create('/demo', 'POST');
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);

    $form_state = new FormState();
    $form_state->setUserInput([
      'form_id' => 'data_surface_demo_form',
      'surface' => [
        'headline' => 'Featured content',
        // Changed. The entity type is what everything else narrows on.
        'entity_type' => 'node',
        // Chosen a moment ago, under the entity type that was there
        // then, and no longer a bundle of anything.
        'bundle' => 'user',
        'field' => '',
        'limit' => '10',
        'show_summary' => '1',
      ],
      '_triggering_element_name' => 'surface[entity_type]',
    ]);
    $form = $this->container->get('form_builder')
      ->buildForm('Drupal\data_surface_demo\Form\DataSurfaceDemoForm', $form_state);

    // Nothing was submitted, so nothing is judged: the surface's AJAX is
    // limited to the one element that was touched.
    $this->assertSame(
      [['surface', 'entity_type']],
      $form_state->getTriggeringElement()['#limit_validation_errors'],
    );
    // No error on the orphan, and no error anywhere else either — the
    // required headline and limit beside it were not being answered.
    $this->assertSame([], $form_state->getErrors());
    // Nor a warning. A warning is what a stale stored value earns, and
    // nothing here went stale: a choice nobody submitted was withdrawn.
    $this->assertSame([], $this->container->get('messenger')->all());

    // The rebuild ran, which it cannot do once anything has errored.
    $this->assertTrue($form_state->isRebuilding());
    // And it followed the new entity type: node's bundles, not user's.
    $this->assertSame(
      ['' => '- None -', 'article' => 'Article', 'page' => 'Page'],
      $this->offered($form['surface']['bundle']),
    );
    // The bundle select comes up on the empty option. Nothing is stored
    // for it, so there is nothing to fall back to and nothing to keep.
    $this->assertSame('', $form['surface']['bundle']['#value']);
    // The entity type itself is the one thing that did move.
    $this->assertSame('node', $form['surface']['entity_type']['#value']);
    // What the person typed elsewhere is still in front of them.
    $this->assertSame('Featured content', $form['surface']['headline']['#value']);
  }

  /**
   * Tests that an orphaned input is dropped and nothing is flagged.
   */
  public function testTheOrphanedInputComesUpOnTheEmptyOption(): void {
    $form_state = new FormState();
    $form = $this->rebuild(
      // Nothing saved for the second tier at all.
      ['tier_one' => 'a'],
      'tier_one',
      // The person had picked "one", which only tier one's "a" offers,
      // and has just moved tier one to "c".
      ['tier_one' => 'c', 'tier_two' => 'one', 'note' => 'typed'],
      $form_state,
    );

    // The list followed the new parent.
    $this->assertSame(
      ['three' => 'three', 'four' => 'four'],
      $this->offered($form['tier_two']),
    );
    // And nothing is chosen in it: the input was withdrawn, and there is
    // no stored value underneath it.
    $this->assertNull($form['tier_two']['#default_value']);
    // Optional and unchosen, so the empty choice is what it comes up on.
    // It is still a render key here rather than an option: Form API
    // folds it into the list while processing, which a container read
    // straight off the builder has not been through.
    $this->assertSame('', $form['tier_two']['#empty_value']);
    $this->assertArrayNotHasKey(DataSurfacePipelineInterface::KEEP_STALE, $form['tier_two']['#options']);
    $this->assertSame([], $form_state->getErrors());
    $this->assertSame([], $this->container->get('messenger')->all());
    // The withdrawal reaches the raw input too, because Form API
    // resolves #value from the input before it looks at #default_value:
    // an input left in place would put the orphan straight back.
    $this->assertArrayNotHasKey('tier_two', $form_state->getUserInput()['settings']);
    // Only the orphan. An unrelated key the person was also editing is
    // still exactly where they left it.
    $this->assertSame('typed', $form_state->getUserInput()['settings']['note']);
    $this->assertSame('typed', $form['note']['#default_value']);
  }

  /**
   * Tests that a value the new parent still offers is left alone.
   *
   * The negative control the rest of this class needs: discarding is for
   * what a change orphaned, not for everything downstream of a change.
   */
  public function testAnInputTheNewParentStillOffersIsKept(): void {
    $form = $this->rebuild(
      ['tier_one' => 'a'],
      'tier_one',
      // Both "a" and "b" offer "two", so moving between them takes
      // nothing away from the person.
      ['tier_one' => 'b', 'tier_two' => 'two'],
    );

    $this->assertSame(
      ['two' => 'two', 'three' => 'three'],
      $this->offered($form['tier_two']),
    );
    $this->assertSame('two', $form['tier_two']['#default_value']);
  }

  /**
   * Tests that a discarded input falls back to the stored value.
   */
  public function testTheDependentFallsBackToItsStoredValue(): void {
    $form = $this->rebuild(
      // Saved: "two", which "b" also offers.
      ['tier_one' => 'a', 'tier_two' => 'two'],
      'tier_one',
      // In progress: "one", which "b" does not.
      ['tier_one' => 'b', 'tier_two' => 'one'],
    );

    $this->assertSame(
      ['two' => 'two', 'three' => 'three'],
      $this->offered($form['tier_two']),
    );
    // The stored value, not the withdrawn one and not an empty select.
    // The unsaved edit is gone; what was saved is untouched, because
    // only a submit ever writes.
    $this->assertSame('two', $form['tier_two']['#default_value']);
  }

  /**
   * Tests the branch where the stored value is orphaned too.
   *
   * Both halves of the rule at once. The in-progress input is withdrawn
   * because the form itself invalidated it; the stored value underneath
   * it is orphaned by the same change and is kept rather than cleared,
   * so the fall-back lands on the stale placeholder the existing model
   * already builds.
   */
  public function testAnOrphanedStoredValueHandsOverToTheStalePlaceholder(): void {
    $form = $this->rebuild(
      // Saved: "one", which "c" does not offer either.
      ['tier_one' => 'a', 'tier_two' => 'one'],
      'tier_one',
      ['tier_one' => 'c', 'tier_two' => 'two'],
    );

    $this->assertSame(DataSurfacePipelineInterface::KEEP_STALE, $form['tier_two']['#default_value']);
    // The placeholder names the stored value, so the person is told what
    // is about to be kept rather than only that something is missing.
    $this->assertStringContainsString(
      'one',
      (string) $form['tier_two']['#options'][DataSurfacePipelineInterface::KEEP_STALE],
    );
    // The withdrawn input is nowhere: it was never a stored value and it
    // is not offered back as one.
    $this->assertArrayNotHasKey('two', $form['tier_two']['#options']);
    $this->assertArrayHasKey('three', $form['tier_two']['#options']);
  }

  /**
   * Tests that a stale sentinel is withdrawn like any other input.
   *
   * The two cases meeting. A stored value had already gone stale out of
   * the form, so the select came up on the sentinel and the browser
   * posts the sentinel back. Changing a parent withdraws that sentinel
   * exactly as it withdraws a real choice — and the stored value it
   * stands for is still stored, because a stored value clears on a
   * submit and at no other time.
   */
  public function testTheStaleSentinelIsWithdrawnAndTheStoredValueStays(): void {
    $stored = ['tier_one' => 'a', 'tier_two' => 'gone'];
    $form = $this->rebuild($stored, 'tier_one', [
      'tier_one' => 'b',
      'tier_two' => DataSurfacePipelineInterface::KEEP_STALE,
    ]);

    // Still the placeholder, standing for the same stored value, under
    // the new parent's list.
    $this->assertSame(DataSurfacePipelineInterface::KEEP_STALE, $form['tier_two']['#default_value']);
    $this->assertStringContainsString(
      'gone',
      (string) $form['tier_two']['#options'][DataSurfacePipelineInterface::KEEP_STALE],
    );
    $this->assertArrayHasKey('three', $form['tier_two']['#options']);
    // Nothing was written: a rebuild is not a submit.
    $this->assertSame('gone', $this->container->get('plugin.manager.block')
      ->createInstance('data_surface_chain_test_block', $stored)
      ->getConfiguration()['tier_two']);
  }

  /**
   * Tests that one change resets the whole chain in one rebuild.
   *
   * Discarding the second tier moves what the third tier refines
   * against, which invalidates the third tier's input in exactly the way
   * the first change invalidated the second's. So the question is asked
   * again rather than once, and a chain of any length settles inside the
   * one rebuild the person is waiting on rather than one link per click.
   */
  public function testTheWholeChainResetsInOneRebuild(): void {
    $form_state = new FormState();
    $form = $this->rebuild(
      ['tier_one' => 'a'],
      'tier_one',
      ['tier_one' => 'c', 'tier_two' => 'one', 'tier_three' => 'x'],
      $form_state,
    );

    // Second tier: orphaned by the change, withdrawn, nothing stored.
    $this->assertSame(
      ['three' => 'three', 'four' => 'four'],
      $this->offered($form['tier_two']),
    );
    $this->assertNull($form['tier_two']['#default_value']);

    // Third tier: its own dependency has just moved under it, so its
    // input goes the same way. With nothing left to narrow against it is
    // an open key again, which is what it was before any of this.
    $this->assertNull($form['tier_three']['#default_value']);
    $this->assertSame('textfield', $form['tier_three']['#type']);

    // Both, out of the raw input, in the one pass.
    $settings = $form_state->getUserInput()['settings'];
    $this->assertArrayNotHasKey('tier_two', $settings);
    $this->assertArrayNotHasKey('tier_three', $settings);
    $this->assertSame([], $form_state->getErrors());
    $this->assertSame([], $this->container->get('messenger')->all());
  }

  /**
   * Tests that the rule names keys and never touches a stored value.
   *
   * The builder's half, asked directly, because the whole distinction
   * the two cases turn on is that only input is ever dropped. A rule
   * that could reach storage would be the stale model with the safety
   * taken off.
   */
  public function testOnlyInputIsEverNamed(): void {
    $block = $this->container->get('plugin.manager.block')
      ->createInstance('data_surface_chain_test_block', ['tier_one' => 'a', 'tier_two' => 'one']);
    $surface = $block->getDataSurface();
    $stored = $block->getConfiguration();

    // A key that is only stored, never sent, is never named: there is no
    // in-progress edit of it to withdraw.
    $this->assertSame([], $this->formBuilder()->discardedRefinementInput(
      $surface,
      $stored,
      ['tier_one' => 'c'],
    ));
    // Sent and orphaned: named.
    $this->assertSame(['tier_two'], $this->formBuilder()->discardedRefinementInput(
      $surface,
      $stored,
      ['tier_one' => 'c', 'tier_two' => 'one'],
    ));
    // A key that refines against nothing is outside the rule entirely,
    // however odd its value looks.
    $this->assertSame([], $this->formBuilder()->discardedRefinementInput(
      $surface,
      $stored,
      ['note' => 'anything at all'],
    ));
  }

}
