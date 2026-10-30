<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\surface\Tool\OperationInputs;
use Drupal\Tests\surface\Traits\ServedControllerTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests an array sent for a scalar key is refused at every door, not thrown.
 *
 * Example 14's mailer sends its mail to `endpoint`, a `uri`. Core's
 * primitive type validator reads a `uri` with parse_url(), which throws a
 * TypeError on an array, and the Tool API validates `values` with it
 * before the tool runs: the tool answered "Tool execution failed:
 * TypeError". The tool's shape check refuses it first, `type_mismatch` by
 * pointer, in the pipeline's words, as the HTTP door does.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class ArraySentForAScalarTest extends SurfaceKernelTestBase {

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
    'surface',
    'surface_examples',
  ];

  /**
   * The refusal every door answers.
   */
  protected const REFUSED = [
    'path' => '/endpoint',
    'code' => 'type_mismatch',
    'message' => 'This value must be of type uri, array given.',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['surface_examples']);
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Tests the tool answers the refusal in the result shape.
   */
  public function testTheToolRefusesIt(): void {
    $tool = $this->container->get('plugin.manager.tool')->createInstance('surface:event.mailer:configure');
    $tool->setInputValue(OperationInputs::VALUES, ['endpoint' => ['x']]);
    $tool->setInputValue(OperationInputs::DRY_RUN, TRUE);
    $tool->execute();
    $result = $tool->getResult();
    $this->assertFalse($result->isSuccess());
    $this->assertStringNotContainsString('TypeError', (string) $result->getMessage());
    $this->assertSame([self::REFUSED], $result->getContextValues()[OperationInputs::VIOLATIONS]);

    // A list item and a key inside a child, the same, each by its path.
    $violations = $this->container->get('plugin.manager.tool')->createInstance('surface:event.mailer:configure')->validateInputValues([
      OperationInputs::VALUES => ['sender' => ['a' => 1], 'credential' => ['api_key' => ['k']]],
      OperationInputs::DRY_RUN => TRUE,
    ]);
    $said = [];
    foreach ($violations as $violation) {
      $said[(string) $violation->getPropertyPath()] = (string) $violation->getMessage();
    }
    ksort($said);
    $this->assertSame([
      'values.credential.api_key' => 'This value must be of type string, array given.',
      'values.sender' => 'This value must be of type email, array given.',
    ], $said);
  }

  /**
   * Tests the HTTP door refuses it the same way.
   */
  public function testTheHttpDoorRefusesIt(): void {
    $answer = $this->httpDryRun('event.mailer', 'configure', ['endpoint' => ['x']]);
    $this->assertSame(422, $answer['status']);
    $this->assertSame([self::REFUSED], $answer['errors']);
  }

}
