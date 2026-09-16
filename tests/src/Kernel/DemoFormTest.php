<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\data_surface_demo\Form\DataSurfaceDemoForm;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the standalone form: one surface, a different storage.
 *
 * The form repeats none of the block's declaration — it reads the
 * plugin's contract through the block manager — and it writes none of
 * the block's storage: the same surface is committed to State because
 * the target owns the storage shape and the surface never hears about
 * it.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class DemoFormTest extends DataSurfaceKernelTestBase {

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
  ];

  /**
   * The state key the demo form writes to.
   */
  protected const STATE_KEY = 'data_surface_demo.settings';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
  }

  /**
   * Submits the demo form with the given surface values.
   *
   * @param array $values
   *   Raw values keyed by surface key, as a browser would submit them.
   *
   * @return \Drupal\Core\Form\FormStateInterface
   *   The form state after the submission.
   */
  protected function submit(array $values) {
    $form_state = new FormState();
    $form_state->setValues(['surface' => $values]);
    // The surface declares an entity type of its own, and the keys that
    // narrow against it are built from whatever is chosen. A browser
    // reaches a bundle other than the default's by changing the entity
    // type first and letting the container rebuild, so a programmatic
    // submission has to say the same thing: the triggering element is
    // what tells the host which in-progress choice to refine against.
    $form_state->setTriggeringElement(['#parents' => ['surface', 'entity_type']]);
    $this->container->get('form_builder')
      ->submitForm(DataSurfaceDemoForm::class, $form_state);
    return $form_state;
  }

  /**
   * Tests that the form is generated from the block plugin's surface.
   */
  public function testFormIsGeneratedFromThePluginSurface(): void {
    $form_state = new FormState();
    $form = $this->container->get('form_builder')
      ->getForm(DataSurfaceDemoForm::class, $form_state);

    // Every element the block declares, and nothing this class authored.
    $this->assertSame('textfield', $form['surface']['headline']['#type']);
    $this->assertSame('select', $form['surface']['entity_type']['#type']);
    $this->assertSame('number', $form['surface']['limit']['#type']);
    $this->assertSame('checkbox', $form['surface']['show_summary']['#type']);
    // The default entity type already narrowed the bundle, on the first
    // page load and without any JavaScript: the user entity type has
    // exactly one bundle, itself.
    $this->assertSame('select', $form['surface']['bundle']['#type']);
    // A processed optional select carries its empty choice among the
    // options rather than beside them.
    $this->assertSame(
      ['' => '- None -', 'user' => 'User'],
      array_map('strval', $form['surface']['bundle']['#options']),
    );
    // The only element the form adds is the button.
    $this->assertSame('submit', $form['actions']['submit']['#type']);
  }

  /**
   * Tests that a submission reaches State through the pipeline.
   */
  public function testSubmitWritesToState(): void {
    $this->submit([
      'headline' => 'Latest articles',
      'entity_type' => 'node',
      'bundle' => 'article',
      'field' => 'title',
      'limit' => '5',
      // A browser sends nothing at all for an unchecked checkbox.
      'show_summary' => NULL,
    ]);

    // The pipeline's accept() cast the submitted strings on the way, so
    // what is stored is the definitions' native types rather than a
    // form's idea of them.
    $this->assertSame([
      'headline' => 'Latest articles',
      'entity_type' => 'node',
      'bundle' => 'article',
      'field' => 'title',
      'limit' => 5,
      'show_summary' => FALSE,
    ], $this->container->get('state')->get(self::STATE_KEY));

    // What was saved is on the form; the message says how much, in a
    // translatable sentence rather than a serialized dump of the stored
    // values.
    $messages = $this->container->get('messenger')->messagesByType('status');
    $this->assertSame('Saved 6 values.', (string) reset($messages));
  }

  /**
   * Tests that a stored value repopulates the regenerated form.
   */
  public function testStoredValuesReachTheForm(): void {
    $this->submit([
      'headline' => 'Latest articles',
      'entity_type' => 'node',
      'bundle' => 'article',
      'field' => 'title',
      'limit' => '5',
      'show_summary' => 1,
    ]);

    $form = $this->container->get('form_builder')
      ->getForm(DataSurfaceDemoForm::class, new FormState());

    $this->assertSame('Latest articles', $form['surface']['headline']['#default_value']);
    $this->assertSame('article', $form['surface']['bundle']['#default_value']);
    $this->assertSame(5, $form['surface']['limit']['#default_value']);
  }

  /**
   * Tests that the refined surface refuses what the form collected.
   */
  public function testInvalidSubmissionIsRefusedAndNotStored(): void {
    $form_state = $this->submit([
      'headline' => 'Latest articles',
      'entity_type' => 'node',
      'bundle' => 'not-a-bundle',
      'limit' => '5',
      'show_summary' => 1,
    ]);

    $errors = $form_state->getErrors();
    $this->assertArrayHasKey('surface][bundle', $errors);
    $this->assertNull($this->container->get('state')->get(self::STATE_KEY));
  }

  /**
   * Tests that a declared constraint refuses a value out of range.
   */
  public function testRangeViolationFlagsItsOwnElement(): void {
    $form_state = $this->submit([
      'headline' => 'Latest articles',
      'entity_type' => 'node',
      'bundle' => 'article',
      'limit' => '999',
      'show_summary' => 1,
    ]);

    $this->assertArrayHasKey('surface][limit', $form_state->getErrors());
    $this->assertNull($this->container->get('state')->get(self::STATE_KEY));
  }

}
