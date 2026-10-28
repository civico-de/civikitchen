<?php

declare(strict_types = 1);

namespace CiviKitchen\Rector\Rules;

use CiviKitchen\Rector\CallArgs;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Throw_;
use PhpParser\Node\Name\FullyQualified;
use Rector\Rector\AbstractRector;

/**
 * Rewrite the removed CRM_Core_Error::fatal($msg) into
 * throw new \CRM_Core_Exception($msg). Another cklint-banned footgun, fixed.
 */
final class CrmCoreErrorFatalToExceptionRector extends AbstractRector {

  public function getNodeTypes(): array {
    return [StaticCall::class];
  }

  public function refactor(Node $node): ?Node {
    if (!$node instanceof StaticCall) {
      return NULL;
    }
    if (!$this->isName($node->class, 'CRM_Core_Error') || !$this->isName($node->name, 'fatal')) {
      return NULL;
    }

    // fatal($message = NULL, $code = NULL, $email = NULL) has no counterpart
    // for $email, and without a message it shows a generic one.
    $args = CallArgs::byName($node, ['message', 'code', 'email']);
    if (!isset($args['message']) || isset($args['email'])) {
      return NULL;
    }
    $exceptionArgs = [new Arg($args['message'])];
    if (isset($args['code'])) {
      $exceptionArgs[] = new Arg($args['code']);
    }

    return new Throw_(new New_(new FullyQualified('CRM_Core_Exception'), $exceptionArgs));
  }
}
