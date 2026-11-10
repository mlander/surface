<?php

declare(strict_types=1);

namespace Drupal\surface\Operation;

/**
 * Why the runner refused a call before it ran the operation.
 *
 * Decision: see docs/decisions.md#one-runner-runs-an-operation.
 */
enum RefusalCategory: string {

  // The resource, the operation or the arguments name nothing.
  case NamesNothing = 'names_nothing';

  // The operation's permission, the access class or the account refused.
  case Access = 'access';

  // The stage does not apply to the operation, is gone, or may not be
  // written into by the account.
  case Stage = 'stage';

}
