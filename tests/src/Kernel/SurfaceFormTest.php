<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\SubformState;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\data_surface\DataSurface;
use Drupal\data_surface\DefinitionMap;
use Drupal\data_surface\DataSurfaceBuilder;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Form\DataSurfaceFormBuilder;
use Drupal\data_surface_test\CacheableCasingRefiner;
use Drupal\data_surface_test\CasingVariantRefiner;
use Drupal\data_surface_test\ModeSubsetRefiner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests widget-driven form generation and extraction.
 *
 * The behaviors the adapter era masked are native here: defaults at
 * every depth, constraint-to-element mapping, empty options on optional
 * selects, options derived from the constraints that declare them,
 * examples as placeholder text, locked rendering, and extraction that
 * casts through the definition.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class SurfaceFormTest extends DataSurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'data_surface'];

  /**
   * Builds a surface exercising every stock widget.
   */
  protected function surface(array $locked = []): DataSurfaceInterface {
    $badge = DataDefinition::create('string')
      ->setLabel('Badge')
      ->setRequired(FALSE)
      ->addConstraint('Choice', ['choices' => ['star', 'flame']]);
    // The nested default lives on the property definition, so the map's
    // own default assembles itself.
    DefinitionMetadata::setDefaultValue($badge, 'flame');
    $map = MapDataDefinition::create()->setLabel('Extras')->setRequired(FALSE);
    $map->setPropertyDefinition('badge', $badge);

    $definitions = [
      'title' => DataDefinition::create('string')
        ->setLabel('Title')
        ->setRequired(TRUE)
        ->addConstraint('Length', ['max' => 40]),
      'notes' => DataDefinition::create('string')
        ->setLabel('Notes')
        ->setRequired(FALSE)
        ->setSetting('multiline', TRUE),
      'mode' => DataDefinition::create('integer')
        ->setLabel('Mode')
        ->setRequired(FALSE)
        // Integer values need the canonical spelling: a map keyed 0, 1,
        // 2 is indistinguishable from a bare list of labels.
        ->addConstraint('LabeledChoice', [
          'choices' => [0, 1, 2],
          'labels' => [0 => 'Disabled', 1 => 'Optional', 2 => 'Required'],
        ]),
      'limit' => DataDefinition::create('integer')
        ->setLabel('Limit')
        ->setRequired(FALSE)
        ->addConstraint('Range', ['min' => 1, 'max' => 50]),
      'active' => DataDefinition::create('boolean')->setLabel('Active')->setRequired(FALSE),
      'extras' => $map,
    ];
    DefinitionMetadata::setDefaultValue($definitions['title'], 'Hello');
    DefinitionMetadata::setDefaultValue($definitions['mode'], 1);
    DefinitionMetadata::setExamples($definitions['title'], ['Quarterly report', 'Release notes']);
    DefinitionMetadata::setExamples($definitions['notes'], ['One line per change']);
    DefinitionMetadata::setExamples($definitions['mode'], [2]);
    DefinitionMetadata::setExamples($definitions['limit'], [10, 25]);

    return new DataSurface(
      DefinitionMap::fromArrays(
        definitions: $definitions,
        locked: $locked,
      ),
    );
  }

  /**
   * The constraint-to-element mapping, one row per claim.
   *
   * This module's whole form story is that a definition and its
   * constraints decide the element, so the mapping is a contract and
   * belongs in one place. It used to be restated by every host test,
   * which meant a widget change failed in six classes and was owned by
   * none of them; the hosts now assert that their keys are present and
   * carry the right values, and this owns what those keys render as.
   *
   * @return array<string, array{0: string, 1: array}>
   *   Test cases, each a surface key and the element properties the
   *   definitions on it must produce.
   */
  public static function constraintMappingCases(): array {
    return [
      'a plain string is a text field' => [
        'title',
        ['#type' => 'textfield', '#default_value' => 'Hello'],
      ],
      'Length caps the text field' => [
        'title',
        ['#maxlength' => 40],
      ],
      'the multiline setting is a textarea' => [
        'notes',
        ['#type' => 'textarea'],
      ],
      'a labeled choice is a select over its own labels' => [
        'mode',
        [
          '#type' => 'select',
          '#options' => [0 => 'Disabled', 1 => 'Optional', 2 => 'Required'],
          '#default_value' => 1,
        ],
      ],
      'Range bounds the number input' => [
        'limit',
        ['#type' => 'number', '#min' => 1, '#max' => 50],
      ],
      'a boolean is a checkbox' => [
        'active',
        ['#type' => 'checkbox'],
      ],
    ];
  }

  /**
   * Tests that constraints and settings decide the element properties.
   */
  #[DataProvider('constraintMappingCases')]
  public function testConstraintsMapToElementProperties(string $key, array $expected): void {
    $surface = $this->surface();
    $form = $this->formBuilder()->buildSurfaceForm($surface, $surface->getDefaultValues(), new FormState());

    foreach ($expected as $property => $value) {
      $this->assertSame($value, $form[$key][$property], sprintf('%s[%s]', $key, $property));
    }
  }

  /**
   * Tests element generation for the shapes a single key cannot show.
   *
   * What is left once the mapping above owns the per-key properties:
   * where an optional select's empty choice comes from, and that a map
   * is rendered as its children rather than as a value of its own, each
   * child carrying the default its own property definition declares.
   */
  public function testBuildElements(): void {
    $form = $this->formBuilder()->buildSurfaceForm($this->surface(), $this->surface()->getDefaultValues(), new FormState());

    // An optional select can be left unanswered, so it offers the empty
    // choice; a required one has no such state to offer.
    $this->assertArrayHasKey('#empty_option', $form['mode']);
    // Map: children keyed directly (no wrapper), defaults populated at
    // depth from the property definition.
    $this->assertSame('select', $form['extras']['badge']['#type']);
    $this->assertSame('flame', $form['extras']['badge']['#default_value']);
  }

  /**
   * Tests that the first declared example becomes placeholder text.
   */
  public function testExamplesAsPlaceholder(): void {
    $surface = $this->surface();
    $form = $this->formBuilder()->buildSurfaceForm($surface, $surface->getDefaultValues(), new FormState());

    // String and number inputs show the first example, cast to string.
    $this->assertSame('Quarterly report', $form['title']['#placeholder']);
    $this->assertSame('10', $form['limit']['#placeholder']);
    // A textarea, a select and a checkbox get none, even where the
    // definition declares examples.
    $this->assertArrayNotHasKey('#placeholder', $form['notes']);
    $this->assertArrayNotHasKey('#placeholder', $form['mode']);
    $this->assertArrayNotHasKey('#placeholder', $form['active']);
  }

  /**
   * Tests locked rendering and AJAX wiring on refinement dependencies.
   */
  public function testLockedAndAjax(): void {
    $kind = DataDefinition::create('string')->setLabel('Kind')->setRequired(TRUE);
    DefinitionMetadata::setDefaultValue($kind, 'fixed');
    $surface = new DataSurface(
      DefinitionMap::fromArrays(
        definitions: [
          'kind' => $kind,
          'detail' => DataDefinition::create('string')->setLabel('Detail')->setRequired(FALSE),
        ],
        refinements: ['detail' => ['kind']],
        locked: ['kind'],
      ),
      refiner: new CasingVariantRefiner(),
    );
    // data_surface_test not installed here; the test class loader still
    // finds the refiner class, and it never fires for these keys.
    $form = $this->formBuilder()->buildSurfaceForm($surface, $surface->getDefaultValues(), new FormState());
    $this->assertTrue($form['kind']['#disabled']);
    // The locked key renders the default its definition declares, even
    // when a different value is supplied.
    $this->assertSame('fixed', $form['kind']['#default_value']);
    $tampered = $this->formBuilder()->buildSurfaceForm($surface, ['kind' => 'other'], new FormState());
    $this->assertSame('fixed', $tampered['kind']['#default_value']);
    // The dependency carries the AJAX rebuild.
    $this->assertArrayHasKey('#ajax', $form['kind']);
  }

  /**
   * Tests extraction: casting, empties, locking, nested maps.
   */
  public function testExtraction(): void {
    $surface = $this->surface(locked: ['title']);
    $form = $this->formBuilder()->buildSurfaceForm($surface, $surface->getDefaultValues(), new FormState());

    $form_state = new FormState();
    $form_state->setValues([
      // Tampering with a locked key is ignored.
      'title' => 'evil',
      'notes' => '',
      // Form submissions arrive as strings; extraction casts.
      'mode' => '2',
      'limit' => '7',
      'active' => 1,
      'extras' => ['badge' => 'star'],
    ]);
    $values = $this->formBuilder()->extractSurfaceValues($surface, $form, $form_state);

    $this->assertSame('Hello', $values['title']);
    $this->assertNull($values['notes']);
    $this->assertSame(2, $values['mode']);
    $this->assertSame(7, $values['limit']);
    $this->assertTrue($values['active']);
    $this->assertSame(['badge' => 'star'], $values['extras']);

    // The extracted values validate against the surface — the choice
    // constraints hold because casting restored native types.
    $this->assertCount(0, $this->pipeline()->validate($surface, $values));
  }

  /**
   * Tests violation flagging falls back to names for unprocessed forms.
   */
  public function testErrorFlagging(): void {
    $surface = $this->surface();
    $form = $this->formBuilder()->buildSurfaceForm($surface, [], new FormState());
    $form_state = new FormState();
    $errors = $this->pipeline()->validate($surface, ['title' => NULL, 'extras' => ['badge' => 'bogus']]);
    $this->formBuilder()->flagSurfaceErrors($errors, $form, $form_state);
    $flagged = $form_state->getErrors();
    $this->assertArrayHasKey('title', $flagged);
    // The nested violation lands on the exact child path.
    $this->assertArrayHasKey('extras][badge', $flagged);
  }

  /**
   * Tests that a violation reaches form state as an object, not as text.
   *
   * The message a constraint builds is a TranslatableMarkup with its
   * placeholders still placeholders, and it stays one the whole way:
   * through validate(), through the violation set, into
   * FormStateInterface::setError(), which takes a Stringable. Rendering
   * it early would substitute and escape the placeholders once, and then
   * whatever prints the error would escape the result a second time, so
   * a value containing markup would be shown with its escaping visible.
   */
  public function testViolationMessagesReachFormStateAsObjects(): void {
    $surface = $this->surface();
    $form = $this->formBuilder()->buildSurfaceForm($surface, [], new FormState());
    $form_state = new FormState();
    $errors = $this->pipeline()->validate($surface, ['title' => NULL, 'extras' => ['badge' => '<em>bogus</em>']]);
    $this->formBuilder()->flagSurfaceErrors($errors, $form, $form_state);
    $flagged = $form_state->getErrors();

    // The surface's own required message, with the label in a
    // placeholder rather than rendered into the string.
    $this->assertInstanceOf(TranslatableMarkup::class, $flagged['title']);
    $this->assertSame('@label is required.', $flagged['title']->getUntranslatedString());
    $this->assertSame('Title', (string) $flagged['title']->getArguments()['@label']);
    $this->assertSame('Title is required.', (string) $flagged['title']);

    // And a constraint's own message, which core builds as an object
    // too. The refused value rides along as a raw argument rather than
    // being substituted and escaped into the text, which is what makes
    // it safe for whatever prints the error to escape the message once.
    $badge = $flagged['extras][badge'];
    $this->assertInstanceOf(TranslatableMarkup::class, $badge);
    $this->assertSame(
      'The value you selected is not a valid choice.',
      $badge->getUntranslatedString(),
    );
    $this->assertSame('"<em>bogus</em>"', $badge->getArguments()['%value']);
  }

  /**
   * Builds a surface holding one list of labeled choices.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The surface.
   */
  protected function tagsSurface(): DataSurfaceInterface {
    $tags = ListDataDefinition::create('string')->setLabel('Tags')->setRequired(FALSE);
    $tags->getItemDefinition()->addConstraint('LabeledChoice', [
      'choices' => ['news', 'tips', 'events'],
      'labels' => ['news' => 'News', 'tips' => 'Tips', 'events' => 'Events'],
    ]);
    return new DataSurface(DefinitionMap::fromArrays(definitions: ['tags' => $tags]));
  }

  /**
   * Tests a list of choices renders one select over the item's options.
   */
  public function testListOfChoicesRendersMultipleSelect(): void {
    $surface = $this->tagsSurface();
    $form = $this->formBuilder()->buildSurfaceForm($surface, ['tags' => ['tips']], new FormState());

    $this->assertSame('select', $form['tags']['#type']);
    $this->assertTrue($form['tags']['#multiple']);
    // The options are the item definition's, read through the same
    // service a single value goes through.
    $this->assertSame(['news' => 'News', 'tips' => 'Tips', 'events' => 'Events'], $form['tags']['#options']);
    $this->assertSame(['tips'], $form['tags']['#default_value']);
    $this->assertSame(3, $form['tags']['#size']);
    // Choosing nothing is the empty list, so there is nothing an empty
    // option would add.
    $this->assertArrayNotHasKey('#empty_option', $form['tags']);
  }

  /**
   * Tests a submitted multiple select accepts to a clean list.
   */
  public function testListExtractionNormalizes(): void {
    $surface = $this->tagsSurface();
    $form = $this->formBuilder()->buildSurfaceForm($surface, [], new FormState());

    $form_state = new FormState();
    // A multiple select hands back what was chosen, keyed by itself, and
    // an unchosen empty option along with it.
    $form_state->setValues(['tags' => ['news' => 'news', '' => '', 'events' => 'events']]);
    $values = $this->formBuilder()->extractSurfaceValues($surface, $form, $form_state);

    $this->assertSame(['news', 'events'], $values['tags']);
    $this->assertCount(0, $this->pipeline()->validate($surface, $values));
  }

  /**
   * Tests an invalid item is reported at its index within the list.
   */
  public function testListItemViolationCarriesItsIndex(): void {
    $surface = $this->tagsSurface();

    $violations = $this->pipeline()->validate($surface, ['tags' => ['news', 'bogus']]);

    // Typed data walks into the list and names the item by its delta, so
    // the violation says which item was refused, not just that one was.
    $this->assertSame(['tags'], $violations->keys());
    $this->assertSame('1', $violations->byKey('tags')[0]->path);
    $this->assertSame(
      'The value you selected is not a valid choice.',
      (string) $violations->byKey('tags')[0]->message,
    );
  }

  /**
   * Tests a refiner narrowing a labeled choice reaches the select.
   */
  public function testRefinedChoicesReachTheSelect(): void {
    $mode = DataDefinition::create('integer')
      ->setLabel('Mode')
      ->setRequired(FALSE)
      ->addConstraint('LabeledChoice', [
        'choices' => [0, 1, 2],
        'labels' => [0 => 'Disabled', 1 => 'Optional', 2 => 'Required'],
      ]);
    $surface = new DataSurface(
      DefinitionMap::fromArrays(
        definitions: [
          'scope' => DataDefinition::create('string')->setLabel('Scope')->setRequired(FALSE),
          'mode' => $mode,
        ],
        refinements: ['mode' => ['scope']],
      ),
      refiner: new ModeSubsetRefiner(),
    );

    $full = $this->formBuilder()->buildSurfaceForm($surface, ['scope' => 'full'], new FormState());
    $this->assertSame(
      [0 => 'Disabled', 1 => 'Optional', 2 => 'Required'],
      array_map('strval', $full['mode']['#options']),
    );

    // The refiner replaced the one declaration with a smaller one, so
    // the select offers exactly what the refined definition validates.
    $basic = $this->formBuilder()->buildSurfaceForm($surface, ['scope' => 'basic'], new FormState());
    $this->assertSame(
      [0 => 'Disabled', 1 => 'Optional'],
      array_map('strval', $basic['mode']['#options']),
    );
    $refused = $this->pipeline()->validate($surface, ['scope' => 'basic', 'mode' => 2])->byKey('mode');
    $this->assertSame('', $refused[0]->path);
    $this->assertSame(
      'The value you selected is not a valid choice.',
      (string) $refused[0]->message,
    );
  }

  /**
   * Tests that the marker is required rather than fallen back from.
   */
  public function testMissingContainerThrows(): void {
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessageMatches('/#data_surface_wrapper/');
    DataSurfaceFormBuilder::findSurfaceContainer(['nothing' => ['#type' => 'textfield']]);
  }

  /**
   * Tests that the container is found however deep a host buried it.
   */
  public function testContainerIsFoundAtAnyDepth(): void {
    $container = $this->formBuilder()->buildSurfaceForm($this->surface(), [], new FormState());
    $buried = ['a' => ['b' => ['c' => ['d' => ['e' => $container]]]]];

    // Five levels down, where the old fixed depth of three gave up and
    // extraction silently collected nothing.
    $this->assertSame(
      $container['#data_surface_wrapper'],
      DataSurfaceFormBuilder::findSurfaceContainer($buried)['#data_surface_wrapper'],
    );
    // The walk never descends into render keys, which are not elements
    // and may hold arrays of anything at all.
    $this->assertSame(
      $container['#data_surface_wrapper'],
      DataSurfaceFormBuilder::findSurfaceContainer(['#attached' => ['library' => ['x/y']], 'inner' => $container])['#data_surface_wrapper'],
    );
  }

  /**
   * Tests that a key with no element keeps what storage holds for it.
   */
  public function testUnrenderedKeyKeepsItsStoredValue(): void {
    $surface = $this->surface();
    $form = $this->formBuilder()->buildSurfaceForm($surface, [], new FormState());
    // What a host does when access is denied, an alter hook removes an
    // element, or a conditional group is not built this time.
    unset($form['limit']);

    $form_state = new FormState();
    $form_state->setValues([
      'title' => 'Kept',
      'notes' => '',
      'mode' => '2',
      'active' => 1,
      'extras' => ['badge' => 'star'],
    ]);

    $values = $this->formBuilder()->extractSurfaceValues($surface, $form, $form_state, ['limit' => 42]);
    $this->assertSame(42, $values['limit']);
    // The rendered keys are unaffected by the stored values arriving.
    $this->assertSame('Kept', $values['title']);
    $this->assertSame(2, $values['mode']);

    // Without them the same form says the key holds nothing, which is
    // the data loss the current values exist to prevent.
    $this->assertNull($this->formBuilder()->extractSurfaceValues($surface, $form, $form_state)['limit']);
  }

  /**
   * Tests that a map property with no element keeps its stored value.
   */
  public function testUnrenderedMapPropertyKeepsItsStoredValue(): void {
    $surface = $this->surface();
    $form = $this->formBuilder()->buildSurfaceForm($surface, [], new FormState());
    unset($form['extras']['badge']);

    $form_state = new FormState();
    $form_state->setValues(['title' => 'Kept', 'extras' => []]);

    // The property is not collected at all, so accept() merges the
    // stored value in rather than writing NULL over it.
    $values = $this->formBuilder()->extractSurfaceValues(
      $surface,
      $form,
      $form_state,
      ['extras' => ['badge' => 'star']],
    );
    $this->assertSame(['badge' => 'star'], $values['extras']);
  }

  /**
   * Tests that every built container carries an id of its own.
   */
  public function testWrapperIdsAreUniquePerInstance(): void {
    $surface = $this->surface();
    // The wrapper key is derived from the host's plugin ID, so two
    // placements of one block — a Layout Builder page, a region with the
    // same block twice — ask for exactly this.
    $first = $this->formBuilder()->buildSurfaceForm($surface, [], new FormState(), 'data-surface-demo');
    $second = $this->formBuilder()->buildSurfaceForm($surface, [], new FormState(), 'data-surface-demo');

    $this->assertNotSame($first['#attributes']['id'], $second['#attributes']['id']);
    // The marker records the id in force, because that is what the AJAX
    // callback returns and what the rebuild replaces.
    $this->assertSame($first['#attributes']['id'], $first['#data_surface_wrapper']);
    $this->assertSame($second['#attributes']['id'], $second['#data_surface_wrapper']);
  }

  /**
   * Tests that a dependency's AJAX points at its own container.
   */
  public function testAjaxPointsAtItsOwnWrapper(): void {
    $surface = $this->dependencySurface();
    $first = $this->formBuilder()->buildSurfaceForm($surface, [], new FormState(), 'data-surface-demo');
    $second = $this->formBuilder()->buildSurfaceForm($surface, [], new FormState(), 'data-surface-demo');

    $this->assertSame($first['#data_surface_wrapper'], $first['kind']['#ajax']['wrapper']);
    $this->assertSame($second['#data_surface_wrapper'], $second['kind']['#ajax']['wrapper']);
    $this->assertNotSame($first['kind']['#ajax']['wrapper'], $second['kind']['#ajax']['wrapper']);
  }

  /**
   * Tests that the AJAX validates the surface and nothing around it.
   */
  public function testAjaxIsLimitedToTheSurfaceContainer(): void {
    $container = $this->formBuilder()->buildSurfaceForm($this->dependencySurface(), [], new FormState());
    // The limit cannot be written at build time: a container does not
    // know its own value path until Form API assigns one.
    $this->assertArrayNotHasKey('#limit_validation_errors', $container['kind']);

    $container['#parents'] = ['settings'];
    $form_state = new FormState();
    $complete_form = [];
    DataSurfaceFormBuilder::processSurfaceContainer($container, $form_state, $complete_form);

    // Scoped to the container, not to the element: the surface's own
    // values stay readable on the rebuild, and the host form around it
    // is neither validated nor stripped.
    $this->assertSame([['settings']], $container['kind']['#limit_validation_errors']);
    // Only what carries AJAX is limited.
    $this->assertArrayNotHasKey('#limit_validation_errors', $container['detail']);
  }

  /**
   * Tests that a map dependency is left unwired rather than wired wrong.
   */
  public function testMapDependencyCarriesNoAjax(): void {
    $map = MapDataDefinition::create()->setLabel('Extras')->setRequired(TRUE);
    $map->setPropertyDefinition('badge', DataDefinition::create('string')->setLabel('Badge'));
    $surface = new DataSurface(
      DefinitionMap::fromArrays(
        definitions: [
          'extras' => $map,
          'detail' => DataDefinition::create('string')->setLabel('Detail')->setRequired(FALSE),
        ],
        refinements: ['detail' => ['extras']],
      ),
      refiner: new CasingVariantRefiner(),
    );

    $form = $this->formBuilder()->buildSurfaceForm($surface, [], new FormState());

    // A details fires no change event, so an #ajax on it would look
    // wired and never run; it is skipped on purpose.
    $this->assertSame('details', $form['extras']['#type']);
    $this->assertArrayNotHasKey('#ajax', $form['extras']);
    // A required map has no input of its own to mark, so the marker goes
    // on the details itself.
    $this->assertTrue($form['extras']['#required']);
  }

  /**
   * Tests that a locked element says why it cannot be changed.
   */
  public function testLockedElementSaysWhy(): void {
    $surface = new DataSurface(
      DefinitionMap::fromArrays(
        definitions: [
          'plain' => DataDefinition::create('string')->setLabel('Plain'),
          'described' => DataDefinition::create('string')
            ->setLabel('Described')
            ->setDescription('What it does.'),
        ],
        locked: ['plain', 'described'],
      ),
    );

    $form = $this->formBuilder()->buildSurfaceForm($surface, [], new FormState());

    // Disabled tells a sighted user it is fixed and a screen reader
    // nothing, so the reason goes where every user reaches it.
    $this->assertSame('Fixed for this operation.', (string) $form['plain']['#description']);
    $this->assertSame('What it does. Fixed for this operation.', (string) $form['described']['#description']);
  }

  /**
   * Tests that an empty option set is not rendered as an empty select.
   */
  public function testEmptyOptionSetFallsThroughToTheNextWidget(): void {
    $surface = new DataSurface(
      DefinitionMap::fromArrays(
        definitions: [
          'badge' => DataDefinition::create('string')
            ->setLabel('Badge')
            ->setRequired(TRUE)
            ->addConstraint('LabeledChoice', ['choices' => [], 'labels' => []]),
        ],
      ),
    );

    $form = $this->formBuilder()->buildSurfaceForm($surface, [], new FormState());

    // A required select with nothing in it cannot be satisfied at all.
    // The options widget declines, the string widget serves the key, and
    // the constraint keeps refusing values on its own terms.
    $this->assertSame('textfield', $form['badge']['#type']);
    $this->assertArrayNotHasKey('#options', $form['badge']);
  }

  /**
   * Tests that an unregistered constraint does not break the form.
   */
  public function testUnregisteredConstraintDoesNotBreakWidgetSelection(): void {
    $surface = new DataSurface(
      DefinitionMap::fromArrays(
        definitions: [
          'badge' => DataDefinition::create('string')
            ->setLabel('Badge')
            ->addConstraint('ThisConstraintPluginDoesNotExist', ['choices' => ['star']]),
        ],
      ),
    );

    // Reading options is a courtesy; a missing plugin is a site problem
    // and belongs in the log, not thrown out of widget selection.
    $form = $this->formBuilder()->buildSurfaceForm($surface, [], new FormState());
    $this->assertSame('textfield', $form['badge']['#type']);
  }

  /**
   * Tests that the options widget carries the list's cacheability.
   */
  public function testOptionsCarryTheirCacheability(): void {
    $surface = new DataSurface(
      DefinitionMap::fromArrays(
        definitions: [
          'badge' => DataDefinition::create('string')
            ->setLabel('Badge')
            ->addConstraint('LabeledChoice', ['choices' => ['star'], 'labels' => ['star' => 'Star']]),
        ],
      ),
    );

    $form = $this->formBuilder()->buildSurfaceForm($surface, [], new FormState());

    // A list read from the constraint alone is permanent, and saying so
    // is the point: the resolver's answer reaches the element instead of
    // being computed and dropped.
    $this->assertSame(Cache::PERMANENT, $form['badge']['#cache']['max-age']);
  }

  /**
   * Tests that the surface's own cacheability reaches the container.
   *
   * The other half of the same rule. The widget says how long one option
   * list may be reused; this says how long the shape the whole container
   * was built from may be. A form holding a shape read from site state
   * and cached as though it were static is the bug both halves close.
   */
  public function testSurfaceCacheabilityReachesTheContainer(): void {
    $builder = new DataSurfaceBuilder(
      definitions: [
        'casing' => DataDefinition::create('string')->setLabel('Casing')->setRequired(FALSE),
        'variant' => DataDefinition::create('string')->setLabel('Variant')->setRequired(FALSE),
      ],
      refinements: ['variant' => ['casing']],
    );
    $builder->addRefiner('variant', new CacheableCasingRefiner());
    $surface = $builder->seal();

    $form = $this->formBuilder()->buildSurfaceForm($surface, ['casing' => 'uppercase'], new FormState());

    $this->assertSame(['data_surface_test:casing'], $form['#cache']['tags']);
    $this->assertSame(['languages:language_interface'], $form['#cache']['contexts']);
    $this->assertSame(CacheableCasingRefiner::MAX_AGE, $form['#cache']['max-age']);
  }

  /**
   * Tests that merging into a host form leaves the host's keys alone.
   */
  public function testHostMergeKeepsTheHostsRenderKeys(): void {
    $container = $this->formBuilder()->buildSurfaceForm($this->dependencySurface(), [], new FormState());
    $host = [
      '#type' => 'fieldset',
      '#tree' => FALSE,
      '#attributes' => ['id' => 'host-own-id', 'class' => ['host']],
      'host_key' => ['#type' => 'textfield'],
    ];

    $merged = $this->formBuilder()->mergeSurfaceContainer($container, $host);

    // Everything the host said about its own element survives.
    $this->assertSame('fieldset', $merged['#type']);
    $this->assertFalse($merged['#tree']);
    $this->assertSame('host-own-id', $merged['#attributes']['id']);
    $this->assertSame(['host'], $merged['#attributes']['class']);
    $this->assertSame('textfield', $merged['host_key']['#type']);
    // The surface's children and its marker are added, and the AJAX now
    // replaces the element the host named rather than one that is not
    // in the document.
    $this->assertSame('host-own-id', $merged['#data_surface_wrapper']);
    $this->assertSame('host-own-id', $merged['kind']['#ajax']['wrapper']);
    $this->assertSame('textfield', $merged['detail']['#type']);
    // A host that says nothing gets the surface's container.
    $this->assertSame('container', $this->formBuilder()->mergeSurfaceContainer($container, [])['#type']);
  }

  /**
   * Tests that values are read in the frame their #parents belong to.
   */
  public function testValuesAreReadInTheCompleteFormsFrame(): void {
    $surface = $this->surface();
    $form = ['#parents' => []];
    $form['settings'] = $this->formBuilder()->buildSurfaceForm($surface, [], new FormState());
    // What Form API assigns once the form is processed: #parents are
    // absolute, counted from the root of the complete form.
    $form['settings']['#parents'] = ['settings'];
    foreach (['title', 'notes', 'mode', 'limit', 'active', 'extras'] as $key) {
      $form['settings'][$key]['#parents'] = ['settings', $key];
    }
    $form['settings']['extras']['badge']['#parents'] = ['settings', 'extras', 'badge'];

    $form_state = new FormState();
    $form_state->setValues([
      'settings' => [
        'title' => 'From the browser',
        'mode' => '2',
        'extras' => ['badge' => 'star'],
      ],
    ]);
    // What a host hands its plugin: a state that reads values relative
    // to the fragment it wraps. Read naively, every absolute path lands
    // one level too deep and comes back NULL — which the surface would
    // report as "not configured" and write over what is stored.
    $subform_state = SubformState::createForSubform($form['settings'], $form, $form_state);

    $values = $this->formBuilder()->extractSurfaceValues($surface, $form['settings'], $subform_state);

    $this->assertSame('From the browser', $values['title']);
    $this->assertSame(2, $values['mode']);
    $this->assertSame(['badge' => 'star'], $values['extras']);
  }

  /**
   * Builds a two-key surface whose second key refines against the first.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The surface.
   */
  protected function dependencySurface(): DataSurfaceInterface {
    return new DataSurface(
      DefinitionMap::fromArrays(
        definitions: [
          'kind' => DataDefinition::create('string')->setLabel('Kind')->setRequired(FALSE),
          'detail' => DataDefinition::create('string')->setLabel('Detail')->setRequired(FALSE),
        ],
        refinements: ['detail' => ['kind']],
      ),
      refiner: new CasingVariantRefiner(),
    );
  }

}
