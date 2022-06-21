<?php
namespace CCServer;

use CCNode\Db;
use CCNode\Accounts\User;
use CCNode\Accounts\Remote;
use CreditCommons\Exceptions\PermissionViolation;
use CreditCommons\Exceptions\PasswordViolation;
use CreditCommons\Exceptions\HashMismatchFailure;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use function CCNode\accountStore;
use function CCNode\load_account;
use function CCnode\permitted_operations;


class PermissionMiddleware {

  public function __invoke(Request $request, Response $response, callable $next) : Response {
    global $user, $config;
    $user = $this->authenticate($request);

    // The name corresponds roughly to the api route name, except where phptest doesn't support optional params
    $operationId = $request->getAttribute('route')->getName();
    if (!in_array($operationId, array_keys(permitted_operations()))) {
      if ($user->id == $config->trunkwardAcc) {
        // Change the username for a more helpful error message.
        $user->id .= ' (trunkward)';
      }
      throw new PermissionViolation(operation: $operationId);
    }
    return $next($request, $response);
  }

  /**
   * Taking the user id and auth key from the header and comparing with the database. If the id is of a remote account, compare the extra
   * @param Request $request
   * @return void
   * @throws DoesNotExistViolation|HashMismatchFailure|AuthViolation
   */
  function authenticate(Request $request) : User {
    global $config;
    $user = accountStore()->anonAccount();
    if ($request->hasHeader('cc-user') and $request->hasHeader('cc-auth')) {
      $acc_id = $request->getHeaderLine('cc-user');
      // Users connect with an API key which can compared directly with the database.
      if ($acc_id) {
        $user = load_account($acc_id);
        $auth = ($request->getHeaderLine('cc-auth') == 'null') ?
          NULL : // not sure how or why null is returned as a string.
          (string)$request->getHeaderLine('cc-auth');
        if ($user instanceOf Remote) {
          if (!$this->matchHashes($acc_id, $auth)) {
            throw new HashMismatchFailure($acc_id);
          }
        }
        elseif (!accountStore()->checkCredentials($acc_id, $auth)) {
          //local user with the wrong password
          throw new PasswordViolation();
        }
      }
      else {
        // Blank username supplied, fallback to anon
      }
    }
    else {
      // No attempt to authenticate, fallback to anon
    }
    if (!$user instanceOf Remote and $config->devMode) {
      // only display errors on the leaf node. Downstream errors are handled.
      ini_set('display_errors', 1);
    }
    return $user;
  }

  function matchHashes(string $acc_id, string $auth) : bool {
    if (empty($auth)) {// If there is no history...
      // this might not be super secure...
      $query = "SELECT TRUE FROM hash_history "
        . "WHERE acc = '$acc_id'"
        . "LIMIT 0, 1";
      $result = Db::query($query)->fetch_object();
      return $result == FALSE;
    }
    else {
      // Remote nodes connect with a hash of the connected account, which needs to be compared.
      $query = "SELECT TRUE FROM hash_history WHERE acc = '$acc_id' AND hash = '$auth' ORDER BY id DESC LIMIT 0, 1";
      $result = Db::query($query)->fetch_object();
      return (bool)$result;// temp
    }
  }


}
