<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Widget\SurfaceWidgetManager;
use Drupal\surface_widget_test\OutsideDiscovery\ValidatingWidget;
use Drupal\surface_widget_test\Plugin\SurfaceWidget\PairWidget;
use Drupal\surface_widget_test\Structure\WrongWidgetStructure;
use Drupal\surface_widget_test\Structure\MisfitHolderStructure;
use Drupal\surface_widget_test\Structure\PairsStructure;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests a structure may ask for its widget, and the guards around widgets.
 *
 * The pair asks for a widget of its own, which draws it wherever it is
 * attached, unless its owner asks for another at the key. A widget word no
 * widget answers to, or one that cannot draw what asks for it, is refused
 * when the resource is built; a widget whose code validates its element
 * is refused when the widgets are discovered.
 *
 * @see docs/decisions.md#a-structure-may-ask-for-its-widget-and-a-widget-never-validates
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class WidgetAskTest extends SurfaceKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'surface', 'surface_widget_test'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Builds a structure on its own.
   *
   * @param class-string $structure
   *   The structure.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   It, built.
   */
  protected function built(string $structure) {
    return $this->container->get('surface.resources')->build($structure, new OperationContext('configure'));
  }

  /**
   * Tests the pair is drawn by the widget it asks for, as its hint says.
   */
  public function testTheStructureAsksForItsWidget(): void {
    $built = $this->built(PairsStructure::class);
    $this->assertSame('widget_test_pair', $built->getDefinition('asked')->getSetting('widget'));
    $this->assertSame('fieldset', $built->getDefinition('owned')->getSetting('widget'), 'The owner\'s ask at the key wins.');
    $manager = $this->container->get('surface.widget_choice');
    $this->assertInstanceOf(PairWidget::class, $manager->forDefinition($built->getDefinition('asked')));

    $form = $this->formBuilder()->buildSurfaceForm($built, ['asked' => ['left' => 'L', 'right' => 'R']], new FormState());
    $this->assertSame(['widget-test-pair'], $form['asked']['#attributes']['class']);
    $this->assertSame('R', $form['asked']['right']['#default_value']);
    $this->assertNotSame(['widget-test-pair'], $form['owned']['#attributes']['class'] ?? NULL, 'The owner\'s fieldset, drawn as any map.');

    $emitter = $this->container->get('surface.contract_emitter');
    $hinted = $emitter->emitFull($built, 'widget_test.pairs', widgets: TRUE)->document['schema']['properties'];
    $this->assertSame('widget_test_pair', $hinted['asked']['x-portable-ui']['widget']);
    $this->assertSame('fieldset', $hinted['owned']['x-portable-ui']['widget']);
    $plain = $emitter->emitFull($built, 'widget_test.pairs')->document['schema']['properties'];
    $this->assertArrayNotHasKey('x-portable-ui', $plain['asked'], 'A hint only, and only when asked for.');
    $this->assertArrayNotHasKey('x-surface', $plain['asked']);
    // What is accepted is the pair's own keys, whichever widget draws it.
    $this->assertContains('asked', $this->pipeline()->validate($built, ['asked' => ['right' => 'Too long by far']])->keys());
  }

  /**
   * Tests a widget asked for that does not draw its key is refused at build.
   */
  public function testWidgetThatCannotDrawItIsRefusedAtBuild(): void {
    foreach ([
      WrongWidgetStructure::class => 'widget_test.wrong_widget asks for the "no_such_widget" widget at "word", which no widget answers to or which does not draw it',
      MisfitHolderStructure::class => 'widget_test.misfit_holder asks for the "checkbox" widget at "pair"',
    ] as $structure => $said) {
      try {
        $this->built($structure);
        $this->fail($said);
      }
      catch (\LogicException $e) {
        $this->assertStringContainsString($said, $e->getMessage());
      }
    }
  }

  /**
   * Tests a widget whose code validates its element is refused at discovery.
   */
  public function testValidatingWidgetIsRefusedAtDiscovery(): void {
    $manager = $this->container->get('plugin.manager.surface_widget');
    // Every widget the site has passed, one setting #element_validate to
    // an empty list among them.
    $this->assertArrayHasKey('widget_test_pair', $manager->getDefinitions());
    $this->assertArrayHasKey('number', $manager->getDefinitions());
    $definition = [
      'id' => 'widget_test_validating',
      'class' => ValidatingWidget::class,
      'provider' => 'surface_widget_test',
    ];
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('The widget_test_validating widget (' . ValidatingWidget::class . ') gives an element an #element_validate of its own, on line');
    $manager->processDefinition($definition, 'widget_test_validating');
  }

  /**
   * Tests the check reads an element validation set any way it is spelled.
   */
  public function testTheCheckReadsEverySpelling(): void {
    $this->expectException(\LogicException::class);
    SurfaceWidgetManager::assertNeverValidates(ValidatingWidget::class, 'validating');
  }

}
