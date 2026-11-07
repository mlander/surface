<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\Access\AccessResultReasonInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\surface\Pipeline\Violation;
use Drupal\surface\Tool\OperationInputs;
use Drupal\Tests\surface\Traits\ServedControllerTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\tool\FailureCategory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests a stage is its owner's, at the tool and over HTTP.
 *
 * Another account is refused every operation on a stage it did not open;
 * an id that names no stage names nothing; an anonymous account may not
 * open one.
 *
 * @see \Drupal\surface\Access\StageAccess
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class StageOwnershipTest extends SurfaceKernelTestBase {

  use ServedControllerTrait;
  use StageTestTrait;
  use UserCreationTrait;

  /**
   * An id no stage has.
   */
  protected const NONE = '00000000-0000-4000-8000-000000000000';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'serialization', 'tool', 'surface', 'surface_stage_test'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->setUpCurrentUser(admin: TRUE);
    $this->setUpCurrentUser();
  }

  /**
   * Lists a tool's violations, each its code by its pointer.
   *
   * @param array $answer
   *   What the tool answered.
   *
   * @return array<string, string>
   *   The codes, by path.
   */
  protected static function toolCodes(array $answer): array {
    return array_column($answer['outputs'][OperationInputs::VIOLATIONS] ?? [], 'code', 'path');
  }

  /**
   * Tests another account is refused every operation on the stage.
   */
  public function testAnotherAccountsStageIsForbidden(): void {
    $id = $this->open('Mine');
    $this->setUpCurrentUser();
    foreach (['read', 'update', 'changes', 'apply', 'delete'] as $operation) {
      $answer = $operation === 'update'
        ? $this->runTool('surface:surface.stage:update', ['id' => $id, OperationInputs::VALUES => ['label' => 'Theirs']])
        : $this->stageTool($operation, $id);
      $this->assertFalse($answer['success'], $operation);
      $this->assertSame(FailureCategory::Access, $answer['category'], $operation);
      $this->assertSame(['' => Violation::ACCESS], static::toolCodes($answer), $operation);
    }
    $this->assertSame('Mine', $this->stages()->read($id)['label']);

    $answer = $this->servedJson('PATCH', '/surface-api/surface.stage/' . $id, ['label' => 'Theirs']);
    $this->assertSame(403, $answer['status']);
    $this->assertSame(403, $this->served('PATCH', '/surface-api/surface.stage/' . $id . ':apply', [])->getStatusCode());
    $this->assertSame(403, $this->served('DELETE', '/surface-api/surface.stage/' . $id)->getStatusCode());
    $this->assertNotNull($this->stages()->read($id));
  }

  /**
   * Tests an id that names no stage names nothing, at both doors.
   */
  public function testMissingStageNamesNothing(): void {
    foreach (['read', 'changes', 'apply', 'delete'] as $operation) {
      $answer = $this->stageTool($operation, self::NONE);
      $this->assertFalse($answer['success'], $operation);
      $this->assertSame(['' => Violation::NOT_FOUND], static::toolCodes($answer), $operation);
    }
    $this->assertSame(404, $this->served('GET', '/surface-api/surface.stage/' . self::NONE)->getStatusCode());
    $this->assertSame(404, $this->served('PATCH', '/surface-api/surface.stage/' . self::NONE . ':apply', [])->getStatusCode());
  }

  /**
   * Tests an anonymous account may not open a stage.
   */
  public function testAnonymousCannotOpenStage(): void {
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());
    $answer = $this->runTool('surface:surface.stage:create', [OperationInputs::VALUES => ['label' => 'Anonymous']]);
    $this->assertFalse($answer['success']);
    $this->assertSame(FailureCategory::Access, $answer['category']);
    $resources = $this->resources();
    $access = $resources->access('surface.stage', $resources->operation('surface.stage', 'create'), new AnonymousUserSession());
    $this->assertTrue($access->isForbidden());
    $this->assertInstanceOf(AccessResultReasonInterface::class, $access);
    $this->assertStringContainsString('session cookie', (string) $access->getReason());
  }

}
