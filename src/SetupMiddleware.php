<?php
namespace CCServer;

use CCNode\Node;
use CCNode\CCNodeConfig;
use CCNode\Workflows;
use CreditCommons\ErrorContext;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

class SetupMiddleware {

  // Paths relative to application root.
  const WORKFLOWS_FILE = 'workflows.json';
  const SETTINGS_FILE = 'node.ini';

  function __construct() {
    if (!file_exists(SELF::WORKFLOWS_FILE)) {
      throw new \CreditCommons\Exceptions\CCFailure('Missing '.SELF::WORKFLOWS_FILE.' file at '.getcwd());
    }
  }

  public function __invoke(ServerRequestInterface $request, RequestHandlerInterface $handler) : ResponseInterface {
    global $cc_config, $cc_workflows, $node;

    $cc_config = CCNodeConfig::createFromIniArray(\parse_ini_file(\realpath(SELF::SETTINGS_FILE)));

    // Creates the required global $error_context.
    ErrorContext::Create(
      node: $cc_config->nodeName,
      path: $request->getUri()->getPath(),
      method: $request->getMethod(),
      user: '- anon -'
    );

    $cc_workflows = new Workflows(json_decode(file_get_contents(SELF::WORKFLOWS_FILE)));
    // Creates globals $node
    $node = new Node();

    return $handler->handle($request);
  }

}
