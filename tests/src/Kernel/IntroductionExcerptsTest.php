<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\block\Entity\Block;
use Drupal\Core\Form\EnforcedResponseException;
use Drupal\Core\Form\FormState;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\data_surface\Pipeline\Storage\PluginConfigurationStorage;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\ResourceBuild\ResourceRegistry;
use Drupal\data_surface_intro_test\OutsideDiscovery\LooseningBadgeAlter;
use Drupal\data_surface_intro_test\OutsideDiscovery\TeaserBadgeAlter;
use Drupal\data_surface_intro_test\Plugin\Block\TeaserBlock;
use Drupal\data_surface_intro_test\Structure\TeaserBlockStructure;
use Drupal\data_surface_tool\OperationInputs;
use Drupal\Tests\data_surface\Traits\ServedControllerTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Produces what the introduction quotes of the teaser block, and finds it.
 *
 * Pages 9, 11, 12 and 16 describe a teaser block and a badge alter that
 * no shipped module has; data_surface_intro_test holds them as the pages
 * show them (IntroductionDocsTest holds the snippets to its lines). Each
 * test here runs the code, takes what it answers, and asserts the page
 * says that text: the form drawn from the structure (page 9), the input
 * the placement tool takes (page 11), the refusal of a loosening alter
 * (page 12), and one refused value at the form, the tool and HTTP (page
 * 16). The output is the authority: a page that drifts from it fails.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class IntroductionExcerptsTest extends DataSurfaceKernelTestBase {

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
    'data_surface',
    'data_surface_tool',
    'data_surface_react',
    'data_surface_block',
    'data_surface_intro_test',
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
    $this->installConfig(['system']);
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
    $tool = $this->container->get('plugin.manager.tool')->createInstance('data_surface:block.placement:' . $operation);
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
   * @return \Drupal\data_surface_intro_test\Plugin\Block\TeaserBlock
   *   The block plugin, its settings the defaults.
   */
  protected function teaser(): TeaserBlock {
    $block = $this->container->get('plugin.manager.block')->createInstance('my_teaser');
    $this->assertInstanceOf(TeaserBlock::class, $block);
    return $block;
  }

  /**
   * Gets the input page 11 places the teaser with.
   *
   * @return array
   *   The tool's input, as the page writes it.
   */
  protected function placement(): array {
    return json_decode($this->block('11-doors.md', '"plugin": "my_teaser"'), TRUE, 512, JSON_THROW_ON_ERROR);
  }

  /**
   * Tests page 9: the form is drawn from the structure's keys and rules.
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
    $this->assertPageSays('09-structures.md', 'a text field with a maximum length of 50 for the headline, and a number field bounded at 1 and 50 for the limit, each starting from its key\'s default.');
  }

  /**
   * Tests page 12: the badge tightens the limit, and loosening is refused.
   */
  public function testAnAlterTightensAndMayNotLoosen(): void {
    $resources = $this->container->get('data_surface.resources');
    $built = function (string $alter) use ($resources) {
      $registry = new ResourceRegistry([
        'resources' => [TeaserBlockStructure::class => 'data_surface_intro_test'],
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
      $this->assertSame(self::flat($this->block('12-other-modules.md', 'widens what')), $e->getMessage());
    }
  }

  /**
   * Tests page 11: the tool places the teaser from the page's input.
   */
  public function testTheToolTakesThePagesInput(): void {
    $placed = $this->call('create', $this->placement() + [OperationInputs::DRY_RUN => TRUE]);
    $this->assertTrue($placed['success'], $placed['message'] . json_encode($placed['violations'] ?? []));
    $settings = $placed['values']['settings'];
    $this->assertSame('Latest', $settings['headline']);
    $this->assertSame(5, $settings['limit']);
  }

  /**
   * Tests page 16: one refused value, one message at every door.
   */
  public function testOneValueOneAnswer(): void {
    // Code with no door: the page's call, as it writes it.
    $block = $this->teaser();
    $result = \Drupal::service('data_surface.pipeline')->submit(
      $block->getDataSurface(),
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
    $this->assertSame(trim($this->block('16-a-value-sent-in.md', 'Number of items:')), $errors['settings][limit']);

    // The tool that places the block.
    $values = $this->placement()['values'];
    $values['settings']['limit'] = 60;
    $refused = $this->call('create', [OperationInputs::VALUES => $values, OperationInputs::DRY_RUN => TRUE]);
    $this->assertFalse($refused['success']);
    $quoted = json_decode('{' . $this->block('16-a-value-sent-in.md', '"violations"') . '}', TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame($quoted['violations'], $refused['violations']);

    // HTTP: status 422 and the same object in its errors.
    $problem = $this->httpDryRun('block.placement', 'create', $values);
    $this->assertSame(422, $problem['status'] ?? NULL, print_r($problem, TRUE));
    $said = array_flip(['path', 'code', 'message']);
    $this->assertSame($quoted['violations'], array_map(static fn (array $error): array => array_intersect_key($error, $said), $problem['errors']));
    $this->assertPageSays('16-a-value-sent-in.md', 'HTTP refuses it with status 422 and the same object in its `errors`.');
  }

}
