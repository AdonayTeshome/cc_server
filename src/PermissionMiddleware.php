<?php
namespace CCServer;

use CCNode\Accounts\User;
use CCNode\Accounts\Remote;
use function CCNode\accountStore;
use function CCNode\load_account;
use function CCnode\permitted_operations;
use CreditCommons\Exceptions\PermissionViolation;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;


class PermissionMiddleware {

  public function __invoke(ServerRequestInterface $request, RequestHandlerInterface $handler) : ResponseInterface {
    global $cc_user, $cc_config;

    $cc_user = $this->authenticate($request);
    // The route name/operation ID corresponds to the API method ID,
    // (Except where phptest doesn't support optional params)
    $operationId = $request->getAttribute('__route__')->getName();
    if (!in_array($operationId, array_keys(permitted_operations()))) {
      if ($cc_user->id == $cc_config->trunkwardAcc) {
        // Change the username for a more helpful error message.
        $cc_user->id .= ' (trunkward)';
      }
      throw new PermissionViolation();
    }
    return $handler->handle($request);
  }

  /**
   * Taking the user id and auth key from the header and comparing with the database. If the id is of a remote account, compare the extra
   * @param Request $request
   * @return void
   * @throws DoesNotExistViolation|HashMismatchFailure|AuthViolation
   */
  function authenticate(ServerRequestInterface $request) : User {
    global $cc_config;
    $accountStore = accountStore();
    if ($request->hasHeader('cc-user') and $request->hasHeader('cc-auth')) {
      $acc_id = $request->getHeaderLine('cc-user');
      // Users connect with an API key which can compared directly with the database.
      if ($acc_id) {
        $auth_string = ($request->getHeaderLine('cc-auth') == 'null') ?
          '' : // not sure how or why null is returned as a string.
          (string)$request->getHeaderLine('cc-auth');
        $user = load_account($acc_id);
        $user->authenticate($auth_string); // will throw if there's a problem
      }
      else {
        // Blank username supplied, fallback to anon
        $user = $accountStore->anonAccount();// anon
      }
    }
    else {
      // No attempt to authenticate, fallback to anon
      $user = $accountStore->anonAccount();// anon
    }
    if (!$user instanceOf Remote and $cc_config->devMode) {
      // only display errors on the leaf node. Downstream errors are passed up.
      ini_set('display_errors', 1);
    }
    return $user;
  }

}
