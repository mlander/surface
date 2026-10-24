<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Tests\data_surface\Traits\ServedControllerTrait;
use Drupal\data_surface_tool\OperationInputs;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\tool\Tool\ToolInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests what the fourth fresh-eyes log found at the tool and React doors.
 *
 * Entry 18: a runtime answer at the top put the same note on every key
 * of the listing; it is said once, in the tool's description. Entry 20:
 * the write answered a mounted key never sent and never stored, which the
 * read tool did not. Entry 19: a refused item of a multi-select listed no
 * choices, and a misspelled key suggested none.
 *
 * @see docs/fresh-eyes-2026-10-21.md
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class FreshEyesFourTest extends DataSurfaceKernelTestBase {

  use ServedControllerTrait;

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'serialization',
    'tool',
    'data_surface',
    'data_surface_tool',
    'data_surface_examples',
    'data_surface_examples_compliance',
    'data_surface_examples_workflow',
    'data_surface_react',
    'data_surface_tool_test',
  ];

  /**
   * The sentence a tool's description ends with when every key is marked.
   */
  protected const ONCE = 'Every key may be found locked or left out once the thing is known.';

  /**
   * The note a key carries when the mark is its own.
   */
  protected const OWN = 'Whether this may be changed is decided once the thing it belongs to is known';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['data_surface_examples']);
    // The first account is the super user; the tests act as another.
    $this->setUpCurrentUser(admin: TRUE);
    $this->setUpCurrentUser([], ['administer site configuration', 'see event fees']);
  }

  /**
   * Runs a tool directly, as a PHP caller, ECA or a page does.
   *
   * @param string $id
   *   The tool's plugin id.
   * @param array $inputs
   *   Its inputs, by name.
   *
   * @return array{success: bool, message: string, outputs: array}
   *   Whether it succeeded, its message, and its result's context.
   */
  protected function runTool(string $id, array $inputs): array {
    $tool = $this->container->get('plugin.manager.tool')->createInstance($id);
    assert($tool instanceof ToolInterface);
    foreach ($inputs as $name => $value) {
      $tool->setInputValue($name, $value);
    }
    $tool->execute();
    $result = $tool->getResult();
    return [
      'success' => $result->isSuccess(),
      'message' => (string) $result->getMessage(),
      'outputs' => $result->getContextValues(),
    ];
  }

  /**
   * Posts to React's /validate.
   *
   * @param string $resource
   *   The resource id.
   * @param array $values
   *   The values sent.
   *
   * @return array
   *   The decoded answer.
   */
  protected function validate(string $resource, array $values): array {
    return $this->httpDryRun($resource, 'configure', $values);
  }

  /**
   * Tests a mark every key carries from the top is said once.
   *
   * Example 10's workflow answers the program's configure operation at
   * runtime, so every key is marked: the write tool and the read tool say
   * it once, in their descriptions, and no key repeats it. The fees are
   * marked by their own structure's access as well, and keep the note on
   * the key, in configure as in check, which is not answered at the top.
   * The tool test module's cabinet is answered at the top by a static
   * access class, which marks nothing: its description says nothing.
   */
  public function testMarkFromTheTopIsSaidOnce(): void {
    $tools = $this->container->get('plugin.manager.tool');
    foreach (['data_surface:event.program:configure', 'data_surface:event.program:configure:read'] as $id) {
      $this->assertStringEndsWith(self::ONCE, (string) $tools->getDefinition($id)->getDescription(), $id);
    }
    $this->assertStringNotContainsString(self::ONCE, (string) $tools->getDefinition('data_surface:event.program:check')->getDescription());

    $values = $tools->createInstance('data_surface:event.program:configure')->getInputDefinition(OperationInputs::VALUES);
    $this->assertNotSame([], $values->getPropertyDefinitions());
    foreach ($values->getPropertyDefinitions() as $key => $property) {
      $own = $key === 'fees';
      $this->assertSame($own, str_contains((string) $property->getDescription(), self::OWN), $key);
    }
    $read = $this->container->get('tool.definition_serializer')->normalizeOutputSchema($tools->createInstance('data_surface:event.program:configure:read'));
    foreach (['headline', 'sessions', 'published'] as $key) {
      $this->assertStringNotContainsString(self::OWN, $read['properties'][OperationInputs::VALUES]['properties'][$key]['description'] ?? '', 'Nor does the read tool say it on ' . $key);
    }
    $this->assertStringNotContainsString(self::ONCE, (string) $tools->getDefinition('data_surface:tool_test.cabinet:update')->getDescription(), 'A static answer at the top marks nothing.');

    $check = $tools->createInstance('data_surface:event.program:check')->getInputDefinition(OperationInputs::VALUES);
    $this->assertStringContainsString(self::OWN, (string) $check->getPropertyDefinition('fees')->getDescription(), 'A mark that is the key\'s own stays on the key.');
    $this->assertStringNotContainsString(self::OWN, (string) $check->getPropertyDefinition('headline')->getDescription());

    // The built surfaces say which it is.
    $resources = $this->container->get('data_surface.resources');
    $this->assertTrue($resources->buildStatic('event.program', $resources->operation('event.program', 'configure'))->isRuntimeAccessWhole());
    $this->assertFalse($resources->buildStatic('event.program', $resources->operation('event.program', 'check'))->isRuntimeAccessWhole());
  }

  /**
   * Tests the write answers what the read answers after it.
   *
   * Example 4's compliance module mounts its keys on example 3, which
   * stores none of them: a write of the title alone answers no mount, as
   * a read after it answers none, at the tool door and at React's.
   */
  public function testTheWriteAnswersWhatTheReadAnswers(): void {
    $this->assertArrayNotHasKey('third_party_settings', $this->config('data_surface_examples.registration_step3')->getRawData());
    $id = 'data_surface:registration.step3:configure';

    $dry = $this->runTool($id, [
      OperationInputs::VALUES => ['title' => 'Autumn meetup'],
      OperationInputs::DRY_RUN => TRUE,
    ]);
    $this->assertTrue($dry['success'], $dry['message']);
    $this->assertArrayNotHasKey('third_party_settings', $dry['outputs'][OperationInputs::VALUES], 'A mounted key never sent and never stored is not answered.');
    $validated = $this->validate('registration.step3', ['title' => 'Autumn meetup']);
    $this->assertTrue($validated['ok']);
    $this->assertArrayNotHasKey('third_party_settings', $validated['values']);

    $written = $this->runTool($id, [OperationInputs::VALUES => ['title' => 'Autumn meetup']]);
    $this->assertTrue($written['success'], $written['message']);
    $this->assertSame(['/title'], $written['outputs']['changes']);
    $read = $this->runTool($id . ':read', []);
    $this->assertSame($read['outputs'][OperationInputs::VALUES], $written['outputs'][OperationInputs::VALUES], 'The write answers what the read tool answers after it.');
    $this->assertSame($read['outputs'][OperationInputs::VALUES], $dry['outputs'][OperationInputs::VALUES] + ['title' => 'Autumn meetup'], 'And so did the dry run.');

    // Sent, it is written and answered.
    $license = ['third_party_settings' => ['data_surface_examples_compliance' => ['license' => '2048']]];
    $written = $this->runTool($id, [OperationInputs::VALUES => $license]);
    $this->assertTrue($written['success'], $written['message']);
    $this->assertSame('2048', $written['outputs'][OperationInputs::VALUES]['third_party_settings']['data_surface_examples_compliance']['license']);
    $read = $this->runTool($id . ':read', []);
    $this->assertSame($read['outputs'][OperationInputs::VALUES], $written['outputs'][OperationInputs::VALUES]);
  }

  /**
   * Tests a refused item of a list lists what the item may be.
   */
  public function testRefusedItemListsItsChoices(): void {
    $refused = $this->runTool('data_surface:event.doors:configure', [
      OperationInputs::VALUES => ['days' => ['fri', 'mon']],
      OperationInputs::DRY_RUN => TRUE,
    ]);
    $this->assertFalse($refused['success']);
    $this->assertSame([
      [
        'path' => '/days/1',
        'code' => 'no_such_choice',
        'message' => 'The value you selected is not a valid choice. Choose fri (Friday), sat (Saturday) or sun (Sunday).',
      ],
    ], $refused['outputs']['violations']);
    $validated = $this->validate('event.doors', ['days' => ['fri', 'mon']]);
    $this->assertSame('The value you selected is not a valid choice. Choose Friday, Saturday or Sunday.', $validated['errors'][0]['message'], 'React, a person\'s door, names each by its label.');
  }

  /**
   * Tests a misspelled key is answered with the nearest declared key.
   */
  public function testMisspelledKeyNamesTheNearest(): void {
    // cspell:ignore titel capacty emial
    $refused = $this->runTool('data_surface:registration.step3:configure', [
      OperationInputs::VALUES => [
        'titel' => 'Autumn meetup',
        'capacty' => 40,
        'contact' => ['emial' => 'events@example.com'],
        'zz' => 1,
        'nonsense' => 1,
      ],
      OperationInputs::DRY_RUN => TRUE,
    ]);
    $this->assertFalse($refused['success']);
    $this->assertSame([
      '/titel' => 'Unknown key titel. Did you mean title?',
      '/capacty' => 'Unknown key capacty. Did you mean capacity?',
      '/zz' => 'Unknown key zz.',
      '/nonsense' => 'Unknown key nonsense.',
    ], array_column($refused['outputs']['violations'], 'message', 'path'), 'A short key, or one far from any, gets no guess.');
    $this->assertSame(['unknown_key'], array_values(array_unique(array_column($refused['outputs']['violations'], 'code'))));

    // Inside a map, by its path.
    $nested = $this->runTool('data_surface:registration.step3:configure', [
      OperationInputs::VALUES => ['contact' => ['emial' => 'events@example.com']],
      OperationInputs::DRY_RUN => TRUE,
    ]);
    $this->assertSame([
      [
        'path' => '/contact/emial',
        'code' => 'unknown_key',
        'message' => 'Unknown key contact.emial. Did you mean contact.email?',
      ],
    ], $nested['outputs']['violations']);
  }

}
