<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\block\Entity\Block;
use Drupal\Core\Form\EnforcedResponseException;
use Drupal\Core\Form\FormState;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\surface\Form\SurfaceOperationForm;
use Drupal\surface\Pipeline\Storage\PluginConfigurationStorage;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\ResourceBuild\ResourceRegistry;
use Drupal\surface_intro_test\OutsideDiscovery\LooseningBadgeAlter;
use Drupal\surface_intro_test\OutsideDiscovery\TeaserBadgeAlter;
use Drupal\surface_intro_test\Plugin\Block\TeaserBlock;
use Drupal\surface_intro_test\Structure\TeaserBlockStructure;
use Drupal\surface_tool\OperationInputs;
use Drupal\Tests\surface\Traits\ServedControllerTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Produces what the introduction quotes of a developer's own code.
 *
 * Pages 1, 2, 5, 6 and 8 describe my_module's settings, page 4 its
 * colors and the shape they share, and pages 3, 12, 13 and 17 a teaser
 * block and a badge alter, that no shipped module has; my_module and
 * surface_intro_test hold them as the pages show them
 * (IntroductionDocsTest holds the snippets to their lines). Each test
 * here runs the code, takes what it answers, and asserts the page says
 * that text: the settings' form, tool and HTTP path (page 1), their JSON
 * Schema (pages 5 and 6), their refusals (page 6) and their path (page
 * 8); the form drawn from the structure (page 3); the shape's def, the
 * two keys that refer to it and their refusals (page 4); the input the
 * placement tool takes (page 12), the refusal of a loosening alter (page
 * 13), and one refused value at the form, the tool and HTTP (page 17).
 * The output is the authority: a page that drifts from it fails.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class IntroductionExcerptsTest extends SurfaceKernelTestBase {

  use ServedControllerTrait;

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'block',
    'path_alias',
    'field',
    'serialization',
    'tool',
    'surface',
    'surface_tool',
    'surface_react',
    'surface_examples_block',
    'surface_intro_test',
    'my_module',
  ];

  /**
   * The introduction, relative to the module root.
   */
  protected const PAGES = __DIR__ . '/../../../docs/introduction/';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    // Core's block form asks the request path condition's alias manager.
    $this->installEntitySchema('path_alias');
    $this->installConfig(['system', 'my_module']);
    $this->container->get('theme_installer')->install(['olivero']);
    $this->config('system.theme')->set('default', 'olivero')->save();
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Reads a page of the introduction.
   *
   * @param string $page
   *   Its file name.
   *
   * @return string
   *   Its contents.
   */
  protected static function page(string $page): string {
    return (string) file_get_contents(self::PAGES . $page);
  }

  /**
   * Collapses every run of white space to one space.
   *
   * @param string $text
   *   The text.
   *
   * @return string
   *   The same, as one line, so a sentence a page wraps still matches.
   */
  protected static function flat(string $text): string {
    return trim((string) preg_replace('/\s+/', ' ', $text));
  }

  /**
   * Finds the fenced block of a page that holds a text.
   *
   * @param string $page
   *   The page's file name.
   * @param string $holding
   *   Text the block holds.
   *
   * @return string
   *   The block's text, without its fences.
   */
  protected function block(string $page, string $holding): string {
    preg_match_all('/^```[a-z]*\n(.*?)\n```/ms', self::page($page), $blocks);
    $found = array_values(array_filter($blocks[1], static fn (string $block): bool => str_contains($block, $holding)));
    $this->assertCount(1, $found, sprintf('%s has one block holding %s.', $page, $holding));
    return $found[0];
  }

  /**
   * Asserts a page says a text, however it wraps it.
   *
   * @param string $page
   *   The page's file name.
   * @param string $text
   *   The text the code produced.
   */
  protected function assertPageSays(string $page, string $text): void {
    $this->assertStringContainsString(self::flat($text), self::flat(self::page($page)), sprintf('%s does not quote what the code says; copy it into the page again.', $page));
  }

  /**
   * Calls a placement tool.
   *
   * @param string $operation
   *   The operation.
   * @param array $inputs
   *   Its inputs, by name.
   *
   * @return array
   *   `success`, then every output.
   */
  protected function call(string $operation, array $inputs): array {
    $tool = $this->container->get('plugin.manager.tool')->createInstance('surface:block.placement:' . $operation);
    foreach ($inputs as $name => $value) {
      $tool->setInputValue($name, $value);
    }
    $tool->execute();
    $result = $tool->getResult();
    return ['success' => $result->isSuccess(), 'message' => (string) $result->getMessage()] + $result->getContextValues();
  }

  /**
   * Creates the teaser block.
   *
   * @return \Drupal\surface_intro_test\Plugin\Block\TeaserBlock
   *   The block plugin, its settings the defaults.
   */
  protected function teaser(): TeaserBlock {
    $block = $this->container->get('plugin.manager.block')->createInstance('my_teaser');
    $this->assertInstanceOf(TeaserBlock::class, $block);
    return $block;
  }

  /**
   * Gets the input page 12 places the teaser with.
   *
   * @return array
   *   The tool's input, as the page writes it.
   */
  protected function placement(): array {
    return json_decode($this->block('12-doors.md', '"plugin": "my_teaser"'), TRUE, 512, JSON_THROW_ON_ERROR);
  }

  /**
   * Builds the settings form my_module's route serves.
   *
   * @return array
   *   The form's data surface container.
   */
  protected function settingsForm(): array {
    $route = $this->container->get('router.route_provider')->getRouteByName('my_module.settings');
    $stack = $this->container->get('request_stack');
    $request = Request::create($route->getPath());
    $request->setSession(new Session(new MockArraySessionStorage()));
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, 'my_module.settings');
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $route);
    $stack->push($request);
    $form_state = new FormState();
    return $this->container->get('form_builder')->buildForm(SurfaceOperationForm::class, $form_state)[SurfaceOperationForm::SURFACE_KEY];
  }

  /**
   * Gets a configure operation's JSON Schema, as the HTTP door serves it.
   *
   * @param string $resource
   *   The resource's id: my_module's settings unless named.
   *
   * @return array
   *   The schema of the values, through JSON and back.
   */
  protected function settingsSchema(string $resource = 'my_module.settings'): array {
    $served = $this->container->get('surface_react.served_operations');
    $operation = $served->resolve($resource, 'configure', []);
    $contract = $this->container->get('surface.contract_emitter')->emit(
      $operation->surface,
      $served->current($operation),
      $operation->definition->id,
      $operation->operation->id,
      $operation->operation->label,
      FALSE,
      $operation->operation->facts(),
    );
    return json_decode((string) json_encode($contract->document), TRUE)['schema'];
  }

  /**
   * Asserts a page's trimmed JSON Schema excerpt is the served schema's.
   *
   * @param string $page
   *   The page's file name.
   * @param string $holding
   *   Text the excerpt holds.
   * @param string $resource
   *   The resource whose configure operation is served.
   */
  protected function assertSchemaExcerpt(string $page, string $holding, string $resource = 'my_module.settings'): void {
    $schema = $this->settingsSchema($resource);
    $quoted = json_decode('{' . $this->block($page, $holding) . '}', TRUE, 512, JSON_THROW_ON_ERROR);
    foreach ($quoted as $key => $value) {
      if ($key === 'required') {
        $this->assertSame($schema['required'], $value, $page);
        continue;
      }
      $this->assertArrayHasKey($key, $schema['properties'], $page);
      // Trimmed: what the page quotes is what is served, less the rest.
      $this->assertSame($value, array_intersect_key($schema['properties'][$key], $value), sprintf('%s quotes %s otherwise than it is served.', $page, $key));
    }
  }

  /**
   * Calls the settings tool, dry.
   *
   * @param array $values
   *   The values sent.
   * @param string $resource
   *   The resource: my_module's settings unless named.
   *
   * @return array
   *   `success`, then every output.
   */
  protected function configure(array $values, string $resource = 'my_module.settings'): array {
    $tool = $this->container->get('plugin.manager.tool')->createInstance('surface:' . $resource . ':configure');
    $tool->setInputValue(OperationInputs::VALUES, $values);
    $tool->setInputValue(OperationInputs::DRY_RUN, TRUE);
    $tool->execute();
    $result = $tool->getResult();
    return ['success' => $result->isSuccess()] + $result->getContextValues();
  }

  /**
   * Tests page 1: one class, a form, a tool, its command and an HTTP path.
   */
  public function testOneDescriptionEveryDoor(): void {
    $form = $this->settingsForm();
    $keys = ['title', 'items_per_page', 'show_author', 'notify_email', 'layout'];
    $types = array_map(static fn (string $key): string => $form[$key]['#type'], $keys);
    $this->assertSame(['textfield', 'number', 'checkbox', 'email', 'select'], $types);
    $this->assertPageSays('01-what-it-does.md', '| A form | a text field, a number field, a checkbox, an email field and a select |');
    $this->assertPageSays('05-keys.md', 'a text field for `title`, a number field for `items_per_page`, a checkbox for `show_author` and an email field for `notify_email`.');

    $this->assertTrue($this->container->get('plugin.manager.tool')->hasDefinition('surface:my_module.settings:configure'));
    $this->assertPageSays('01-what-it-does.md', '`surface:my_module.settings:configure`');
    // The tool module's command that runs a tool, read from its attribute in
    // the source: loading the class loads Drush's base class, which raises
    // a deprecation in Drush's own code.
    $source = (string) file_get_contents($this->root . '/' . $this->container->get('extension.list.module')->getPath('tool') . '/src/Drush/Commands/ToolRunCommand.php');
    $this->assertSame(1, preg_match("/#\\[Command\\(name: '([^']+)'[^\\n]*\\n(?:\\s*#\\[.*\\n)*\\s*public function runTool\\(/", $source, $match));
    [, $command] = $match;
    $this->assertSame('tool:run', $command);
    $this->assertPageSays('01-what-it-does.md', '| The command line | `drush ' . $command . ' surface:my_module.settings:configure`, which runs the same tool |');

    $registry = $this->container->get('surface.resource_registry');
    $definition = $registry->getDefinition('my_module.settings');
    $operation = $registry->getOperation($definition->name(), 'configure');
    $paths = $this->container->get('surface_react.served_paths');
    [$method, $named] = $paths->writes($definition, $operation);
    $path = $method . ' ' . $paths->url($definition, $operation, [], $named, FALSE)->getGeneratedUrl();
    $this->assertSame('PATCH /surface-api/my_module.settings', $path);
    $this->assertPageSays('01-what-it-does.md', '`' . $path . '`');
    $this->assertStringContainsString($path, $this->block('08-identity.md', 'PATCH /surface-api/node.type/{type}'));
  }

  /**
   * Tests pages 5 and 6: the JSON Schema they quote is the served one.
   */
  public function testTheSchemaTheyQuote(): void {
    $this->assertSchemaExcerpt('05-keys.md', '"items_per_page"');
    $this->assertSchemaExcerpt('06-rules-on-a-key.md', '"oneOf"');
  }

  /**
   * Tests page 6: the form's rules and the tool's refusals.
   */
  public function testTheRulesRefuse(): void {
    $form = $this->settingsForm();
    $this->assertSame(['list' => 'List', 'grid' => 'Grid'], array_map('strval', $form['layout']['#options']));
    $this->assertSame([1, 50], [$form['items_per_page']['#min'], $form['items_per_page']['#max']]);
    $this->assertPageSays('06-rules-on-a-key.md', 'The form reads the rules to draw the fields: `layout` becomes a select of List and Grid, `items_per_page` a number field from 1 to 50');

    $refused = $this->configure(['title' => NULL]);
    $this->assertFalse($refused['success']);
    $quoted = json_decode($this->block('06-rules-on-a-key.md', '"path": "/title"'), TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame([$quoted], $refused['violations']);
    // The form, posted with no title: the same message, at the field.
    $form_state = new FormState();
    $form_state->setValues([
      SurfaceOperationForm::SURFACE_KEY => [
        'title' => '',
        'items_per_page' => '10',
        'show_author' => 1,
        'notify_email' => 'admin@example.com',
        'layout' => 'list',
      ],
    ]);
    $this->container->get('form_builder')->submitForm(SurfaceOperationForm::class, $form_state);
    $errors = array_map(static fn ($message): string => strip_tags((string) $message), $form_state->getErrors());
    $this->assertSame([$quoted['message']], array_values($errors));
    $this->assertPageSays('06-rules-on-a-key.md', 'The form says "' . $quoted['message'] . '" at the field.');

    $refused = $this->configure(['layout' => 'table']);
    $this->assertFalse($refused['success']);
    $this->assertSame(['/layout'], array_column($refused['violations'], 'path'));
    $this->assertPageSays('06-rules-on-a-key.md', 'the message lists the allowed values instead: "' . $refused['violations'][0]['message'] . '"');
  }

  /**
   * Tests page 3: the form is drawn from the structure's keys and rules.
   */
  public function testTheFormIsDrawnFromTheStructure(): void {
    $form = $this->teaser()->buildConfigurationForm([], new FormState());
    $this->assertSame('textfield', $form['headline']['#type']);
    // The browser's maxlength; core's #maxlength is left to the rule.
    $this->assertSame(50, $form['headline']['#attributes']['maxlength']);
    $this->assertSame('Featured content', $form['headline']['#default_value']);
    $this->assertSame('number', $form['limit']['#type']);
    $this->assertSame(1, $form['limit']['#min']);
    $this->assertSame(50, $form['limit']['#max']);
    $this->assertSame(10, $form['limit']['#default_value']);
    $this->assertPageSays('03-structures.md', 'a text field with a maximum length of 50 for the headline, and a number field bounded at 1 and 50 for the limit, each starting from the default the structure gives it.');
  }

  /**
   * Tests page 4: one shape, one def, two keys, one message at both.
   */
  public function testOneShapeAtTwoKeys(): void {
    $this->assertSchemaExcerpt('04-shapes.md', '"background_color"', 'my_module.colors');
    $def = json_decode('{' . $this->block('04-shapes.md', '"pattern"') . '}', TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame($this->settingsSchema('my_module.colors')['$defs'], $def, 'The page quotes the one def whole.');

    $refused = $this->configure(['link_color' => 'blue', 'background_color' => '#fff'], 'my_module.colors');
    $this->assertFalse($refused['success']);
    $quoted = json_decode('{' . $this->block('04-shapes.md', '"violations"') . '}', TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame($quoted['violations'], $refused['violations']);
  }

  /**
   * Tests page 13: the badge tightens the limit, and loosening is refused.
   */
  public function testAnAlterTightensAndMayNotLoosen(): void {
    $resources = $this->container->get('surface.resources');
    $built = function (string $alter) use ($resources) {
      $registry = new ResourceRegistry([
        'resources' => [TeaserBlockStructure::class => 'surface_intro_test'],
        'alters' => [$alter => 'my_badges'],
      ], $this->container->get('cache.discovery'));
      return $resources->withRegistry($registry, $this->container->get('class_resolver'))->build(TeaserBlockStructure::class, new OperationContext('configure'));
    };

    $surface = $built(TeaserBadgeAlter::class);
    $badged = ['headline' => 'Latest', 'limit' => 30, 'third_party_settings' => ['my_badges' => ['badge' => 'New']]];
    $this->assertSame(['limit'], $this->pipeline()->validate($surface, $this->pipeline()->accept($surface, $badged))->keys(), 'A limit above 20 is refused while a badge is set.');
    $plain = ['headline' => 'Latest', 'limit' => 30];
    $this->assertTrue($this->pipeline()->validate($surface, $this->pipeline()->accept($surface, $plain))->isEmpty(), 'Without a badge the owner\'s 1 to 50 stands.');

    try {
      $built(LooseningBadgeAlter::class);
      $this->fail('An alter loosening the limit was built.');
    }
    catch (\LogicException $e) {
      $this->assertSame(self::flat($this->block('13-other-modules.md', 'widens what')), $e->getMessage());
    }
  }

  /**
   * Tests page 12: the tool places the teaser from the page's input.
   */
  public function testTheToolTakesThePagesInput(): void {
    $placed = $this->call('create', $this->placement() + [OperationInputs::DRY_RUN => TRUE]);
    $this->assertTrue($placed['success'], $placed['message'] . json_encode($placed['violations'] ?? []));
    $settings = $placed['values']['settings'];
    $this->assertSame('Latest', $settings['headline']);
    $this->assertSame(5, $settings['limit']);
  }

  /**
   * Tests page 17: one refused value, one message at every door.
   */
  public function testOneValueOneAnswer(): void {
    // Code with no door: the page's call, as it writes it.
    $block = $this->teaser();
    $result = \Drupal::service('surface.pipeline')->submit(
      $block->getSurface(),
      ['headline' => 'Latest', 'limit' => '60'],
      new PluginConfigurationStorage($block),
    );
    $this->assertFalse($result->isValid());
    $this->assertSame(['limit'], $result->violations->keys());

    // The form: core's block form for a new teaser, posted.
    $request = Request::create('/admin/structure/block/add/my_teaser/olivero', 'POST');
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);
    $entity = Block::create(['plugin' => 'my_teaser', 'theme' => 'olivero']);
    $form_object = $this->container->get('entity_type.manager')->getFormObject('block', 'default')->setEntity($entity);
    $form_state = new FormState();
    $form_state->setUserInput([
      'form_id' => $form_object->getFormId(),
      'settings' => ['label' => 'Teaser', 'label_display' => 'visible', 'headline' => 'Latest', 'limit' => '60'],
      'id' => 'teaser_front',
      'region' => 'content',
      'op' => 'Save block',
    ]);
    // Posted with no account, so the form carries no token to check.
    $current = $this->container->get('current_user');
    $account = $current->getAccount();
    $current->setAccount(new AnonymousUserSession());
    try {
      $this->container->get('form_builder')->buildForm($form_object, $form_state);
    }
    catch (EnforcedResponseException) {
      $this->fail('The block was saved with a limit of 60.');
    }
    $current->setAccount($account);
    $errors = array_map(static fn ($message): string => strip_tags((string) $message), $form_state->getErrors());
    $this->assertSame(['settings][limit'], array_keys($errors));
    $this->assertSame(trim($this->block('17-a-value-sent-in.md', 'Number of items:')), $errors['settings][limit']);

    // The tool that places the block.
    $values = $this->placement()['values'];
    $values['settings']['limit'] = 60;
    $refused = $this->call('create', [OperationInputs::VALUES => $values, OperationInputs::DRY_RUN => TRUE]);
    $this->assertFalse($refused['success']);
    $quoted = json_decode('{' . $this->block('17-a-value-sent-in.md', '"violations"') . '}', TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame($quoted['violations'], $refused['violations']);

    // HTTP: status 422 and the same object in its errors.
    $problem = $this->httpDryRun('block.placement', 'create', $values);
    $this->assertSame(422, $problem['status'] ?? NULL, print_r($problem, TRUE));
    $said = array_flip(['path', 'code', 'message']);
    $this->assertSame($quoted['violations'], array_map(static fn (array $error): array => array_intersect_key($error, $said), $problem['errors']));
    $this->assertPageSays('17-a-value-sent-in.md', 'HTTP refuses it with status 422 and the same object in its `errors`.');
  }

}
