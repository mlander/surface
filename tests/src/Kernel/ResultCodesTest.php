<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Access\AccessResult;
use Drupal\data_surface\Pipeline\ResultDocument;
use Drupal\data_surface\Pipeline\Violation;
use Drupal\data_surface_examples\ConceptCalls;
use Drupal\data_surface_react\Controller\ResourceApiController;
use Drupal\data_surface_tool\OperationInputs;
use Drupal\data_surface_tool_test\Storage\VaultStorage;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\tool\Tool\ToolInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests every refusal path answers a stable code, in the one result shape.
 *
 * Group C of the 2026-10-20 reviews: a refusal reached a script as one
 * translated sentence, the code the constraint set was dropped, `notes`
 * and `stale` were maps, and a refusal of the whole call was filed under
 * the pseudo-path `@access`. Each path below is walked through a door,
 * the write tool or React, and its entry read back by path and code; the
 * table the codes are documented in is docs/semantics.md (Codes).
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class ResultCodesTest extends DataSurfaceKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'serialization',
    'tool',
    'data_surface',
    'data_surface_demo_node_type',
    'data_surface_tool',
    'data_surface_examples',
    'data_surface_examples_workflow',
    'data_surface_react',
    'data_surface_tool_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['field', 'node', 'data_surface_examples']);
    // The first account is the super user; every test acts as another.
    $this->setUpCurrentUser(admin: TRUE);
    $this->setUpCurrentUser([], ['administer site configuration', 'administer content types']);
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
   * Makes a dry run of an example's configure tool.
   *
   * @param string $resource
   *   The resource id.
   * @param array $values
   *   The values.
   * @param string $operation
   *   The operation id.
   *
   * @return array
   *   The result's context.
   */
  protected function dryRun(string $resource, array $values, string $operation = 'configure'): array {
    $inputs = [OperationInputs::VALUES => $values];
    $tool = 'data_surface:' . $resource . ':' . $operation;
    if (isset($this->container->get('plugin.manager.tool')->createInstance($tool)->getInputDefinitions()[OperationInputs::DRY_RUN])) {
      $inputs[OperationInputs::DRY_RUN] = TRUE;
    }
    return $this->runTool($tool, $inputs)['outputs'];
  }

  /**
   * Reads a list of entries as code by path.
   *
   * @param array $entries
   *   The `violations`, `notes` or `stale` of a result.
   *
   * @return array<string, string>
   *   Each entry's code, by its path.
   */
  protected static function codes(array $entries): array {
    return array_column($entries, 'code', 'path');
  }

  /**
   * Asserts a result is the one shape, every entry with a code.
   *
   * @param array $result
   *   The result's context, or React's answer.
   * @param string[] $more
   *   The keys a door adds after the shape.
   */
  protected function assertShape(array $result, array $more = []): void {
    $this->assertSame([...ResultDocument::KEYS, ...$more], array_keys($result));
    foreach (['violations', 'notes', 'stale'] as $list) {
      $this->assertIsArray($result[$list]);
      foreach ($result[$list] as $entry) {
        $this->assertSame($list === 'stale' ? ['path', 'code', 'message', 'stored'] : ['path', 'code', 'message'], array_keys($entry), $list);
        $this->assertMatchesRegularExpression('/^[a-z][a-z_]*$/', $entry['code'], $list . ': ' . $entry['message']);
        $this->assertNotSame('', $entry['message']);
      }
    }
  }

  /**
   * Tests each refusal of a value names its key and its code.
   */
  public function testValueRefusalsHaveCodes(): void {
    $concept = static fn (string $resource, string $call): array => ConceptCalls::CALLS[$resource][$call]['values'];
    $cases = [
      // The module's own.
      ['registration.step3', ['title' => NULL], ['title' => Violation::REQUIRED]],
      ['registration.step3', ['nope' => 1], ['nope' => Violation::UNKNOWN_KEY]],
      [
        'registration.step3',
        ['pricing' => 'free', 'ticket' => ['price' => 5]],
        ['ticket.price' => Violation::WRONG_VARIANT],
      ],
      [
        'registration.step3',
        ['pricing' => NULL],
        ['pricing' => Violation::REQUIRED, 'ticket' => Violation::NO_VARIANT],
      ],
      ['event.bulletin', $concept('event.bulletin', 'monthly_with_day'), ['send_day' => Violation::DOES_NOT_APPLY]],
      ['event.doors', $concept('event.doors', 'always_held'), ['badges' => Violation::CANNOT_CLEAR]],
      ['event.press', $concept('event.press', 'listed_without_email'), ['contact.email' => Violation::REQUIRED]],
      // A constraint's, by the name it gives Symfony's code.
      ['registration.step3', ['room' => 'harbour_deck'], ['room' => 'no_such_choice']],
      ['registration.step3', ['room' => 'riverside_east', 'capacity' => 500], ['capacity' => 'not_in_range']],
      ['event.desk', $concept('event.desk', 'phone_with_dashes'), ['phone' => 'regex_failed']],
      ['event.program', $concept('event.program', 'check_too_many'), ['sessions' => 'not_in_range'], 'check'],
    ];
    foreach ($cases as $case) {
      [$resource, $values, $expected] = $case;
      $result = $this->dryRun($resource, $values, $case[3] ?? 'configure');
      $this->assertShape($result);
      $this->assertFalse($result['ok'], $resource);
      $this->assertFalse($result['committed']);
      $this->assertSame($expected, static::codes($result['violations']), $resource . ' ' . json_encode($values));
    }

    // A value in a shape its definition cannot hold, which the Tool API
    // refuses on its own before the pipeline is asked, is the pipeline's
    // type mismatch where nothing holds the call to its shape first.
    $resources = $this->container->get('data_surface.resources');
    $context = $resources->operation('registration.step3', 'configure');
    $built = $resources->build('registration.step3', $context);
    $result = $this->pipeline()->submit($built, ['contact' => 'events@example.com'], $resources->storage('registration.step3', $context, $built), TRUE);
    $this->assertSame(['contact' => Violation::TYPE_MISMATCH], static::codes($result->document([])['violations']));
  }

  /**
   * Tests a secret a moved item would lose is refused by its code.
   */
  public function testSecretInItemHasItsCode(): void {
    $this->container->get('state')->set(VaultStorage::KEY, [
      'mirrors' => [
        ['host' => 'east.example.com', 'password' => 'mirror-one'],
        ['host' => 'west.example.com', 'password' => 'mirror-two'],
      ],
    ]);
    $removed = $this->runTool('data_surface:tool_test.vault:configure', [OperationInputs::VALUES => ['mirrors' => [['host' => 'west.example.com']]]]);
    $this->assertFalse($removed['success']);
    $this->assertShape($removed['outputs']);
    $this->assertSame(['mirrors.0.password' => Violation::SECRET_IN_ITEM], static::codes($removed['outputs']['violations']));
  }

  /**
   * Tests a refusal of the whole call has an empty path and its code.
   */
  public function testWholeCallRefusalsHaveCodes(): void {
    // The operation's permission.
    $this->setUpCurrentUser([], ['access content']);
    $refused = $this->runTool('data_surface:registration.step3:configure', [OperationInputs::VALUES => []]);
    $this->assertFalse($refused['success']);
    $this->assertShape($refused['outputs']);
    $this->assertSame(['' => Violation::PERMISSION], static::codes($refused['outputs']['violations']));
    $this->assertSame('You do not have permission to do this.', $refused['message']);

    $this->setUpCurrentUser([], ['administer site configuration', 'administer content types']);
    // A resource locked whole.
    $this->container->get('state')->set('data_surface_examples_workflow.state', 'published');
    $locked = $this->runTool('data_surface:event.program:configure', [
      OperationInputs::VALUES => ['headline' => 'Four days of talks'],
      OperationInputs::DRY_RUN => TRUE,
    ]);
    $this->container->get('state')->delete('data_surface_examples_workflow.state');
    $this->assertFalse($locked['success']);
    $this->assertShape($locked['outputs']);
    $this->assertSame(['' => Violation::LOCKED], static::codes($locked['outputs']['violations']));
    $this->assertSame("The values were refused:\nThis may be seen and not changed, so nothing was written: The program is published in its workflow, so it is fixed.", $locked['message']);
    $this->assertStringNotContainsString('@access', $locked['message']);

    // A parameter that names nothing, at the write and the read tool.
    $tools = [
      'data_surface:node.type:edit' => [OperationInputs::VALUES => []],
      'data_surface:node.type:edit:read' => [],
    ];
    foreach ($tools as $tool => $inputs) {
      $missing = $this->runTool($tool, ['type' => 'nowhere'] + $inputs);
      $this->assertFalse($missing['success'], $tool);
      $this->assertShape($missing['outputs']);
      $this->assertSame(['' => Violation::NOT_FOUND], static::codes($missing['outputs']['violations']), $tool);
    }

    // The resource's access answer, handed to the pipeline.
    $resources = $this->container->get('data_surface.resources');
    $context = $resources->operation('registration.step3', 'configure');
    $built = $resources->build('registration.step3', $context);
    $result = $this->pipeline()->submit($built, [], $resources->storage('registration.step3', $context, $built), access: AccessResult::forbidden('The gate is shut.'));
    $this->assertSame(['' => Violation::ACCESS], static::codes($result->document([])['violations']));

    // The Tool API's own refusal of the shape, answered by the tool when
    // it is called directly: values sent null.
    $null = $this->runTool('data_surface:registration.step3:configure', [
      OperationInputs::VALUES => NULL,
      OperationInputs::DRY_RUN => TRUE,
    ]);
    $this->assertFalse($null['success']);
    $this->assertShape($null['outputs']);
    $this->assertSame(['' => 'is_null'], static::codes($null['outputs']['violations']));
    $left_out = $this->runTool('data_surface:registration.step3:configure', [OperationInputs::DRY_RUN => TRUE]);
    $this->assertFalse($left_out['success']);
    $this->assertSame(['' => 'is_null'], static::codes($left_out['outputs']['violations']));

    // What storage holds changed since the form was loaded.
    $body = (string) json_encode(['values' => ['title' => 'Late'], 'fingerprint' => 'not-what-is-stored']);
    $request = Request::create('/surface-api/registration.step2/configure/submit', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body);
    $answer = json_decode((string) ResourceApiController::create($this->container)->submit($request, 'registration.step2', 'configure')->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertShape($answer, ['outputs', 'contract', 'created']);
    $this->assertSame(['' => Violation::CONFLICT], static::codes($answer['violations']));
    $this->assertSame([], $answer['values']);
  }

  /**
   * Tests a note and a stale reference are lists with their codes.
   */
  public function testNotesAndStaleHaveCodes(): void {
    $sales = $this->dryRun('event.sales', ConceptCalls::CALLS['event.sales']['speaker_on_sale']['values']);
    $this->assertShape($sales);
    $this->assertTrue($sales['ok']);
    $this->assertSame(['on_sale.speaker' => Violation::LOCKED], static::codes($sales['notes']));

    // A room stored that the venue stored no longer offers.
    $this->config('data_surface_examples.registration_step2')->set('venue', 'harbour')->set('room', 'library_garden')->save();
    $kept = $this->dryRun('registration.step2', []);
    $this->assertShape($kept);
    $this->assertTrue($kept['ok']);
    $this->assertSame(['room' => Violation::STALE], static::codes($kept['stale']));
    $this->assertSame('library_garden', $kept['stale'][0]['stored']);
    $this->assertSame([], $kept['violations']);
  }

}
