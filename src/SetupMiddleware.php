<?php
namespace CCServer;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use CreditCommons\ErrorContext;
use CCNode\Node;

class SetupMiddleware {

  public function __invoke(Request $request, Response $response, callable $next) : Response {
    global $node;
    $node = new Node(parse_ini_file(realpath('node.ini')));
    global $cc_config;
    ErrorContext::Create(
      node: $cc_config->nodeName,
      path: $request->getUri()->getPath(),
      method: $request->getMethod(),
      user: '- anon -'
    );
    // Creates globals $cc_config, $cc_workflows, $cc_user
    // we can't rely on $_GET, $_POST etc because phpunit bypasses them.
    // meanwhile the error classes in cc-php-lib don't have access to globals or $request.
    return $next($request, $response);
  }

}
