<?php
namespace CCServer;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;


class SetupMiddleware {

  public function __invoke(Request $request, Response $response, callable $next) : Response {
    global $node, $error_context, $cc_config;
    // Creates globals $cc_config, $cc_workflows, $cc_user
    $node = new \CCNode\Node(parse_ini_file('node.ini'));
    $error_context = (object)[
      'node' => $cc_config->nodeName,
      'path' => $request->getUri()->getPath(),
      'method' => $request->getMethod(),
      'user' => '- anon -'
    ];
    return $next($request, $response);
  }


}
