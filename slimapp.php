<?php
/**
 * Reference implementation of a credit commons node
 *
 * @todo Update to slim 4 using https://github.com/thephpleague/openapi-psr7-validator/issues/136
 *
 */

use CCServer\Slim3ErrorHandler;
use CCServer\PermissionMiddleware;
use CCNode\Transaction\TransversalTransaction;
use CCNode\Transaction\Transaction;
use CreditCommons\NewTransaction;
use CreditCommons\Exceptions\CCFailure;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use CreditCommons\Exceptions\CCViolation;

// Slim4 (when the League\OpenAPIValidation is ready)
//use Slim\Factory\AppFactory;
//use Psr\Http\Message\ServerRequestInterface;
//$app = AppFactory::create();
//$app->addErrorMiddleware(true, true, true);
//$app->addRoutingMiddleware();
//$errorMiddleware = $app->addErrorMiddleware(true, true, true);
//// See https://www.slimframework.com/docs/v4/middleware/error-handling.html
//// Todo this would be tidier in a class of its own extending Slim\Handlers\ErrorHandler.
//// Note that v4 has $app->addBodyParsingMiddleware();
////This handler converts the CCError exceptions into Json and returns them.
//$errorMiddleware->setDefaultErrorHandler(function (
//    ServerRequestInterface $request,
//    \Throwable $exception,
//    bool $displayErrorDetails,
//    bool $logErrors,
//    bool $logErrorDetails
//) use ($app) {
//    $response = $app->getResponseFactory()->createResponse();
//    if (!$exception instanceOf CCError) {
//      $exception = new CCFailure($exception->getMessage());
//    }
//    $response->getBody()->write(json_encode($exception, JSON_UNESCAPED_UNICODE));
//    return $response->withStatus($exception->getCode());
//});

$app = new \Slim\App();
$c = $app->getContainer();
$getErrorHandler = function ($c) {
  return new Slim3ErrorHandler();
};
$c['errorHandler'] = $getErrorHandler;
$c['phpErrorHandler'] = $getErrorHandler;


/**
 * Default HTML page. (Not part of the API)
 * Since this exists as an actual file, it should be handled by .htaccess
 */
$app->get('/', function (Request $request, Response $response) {
  header('Location: index.html');
  exit;
});

/**
 * Globals created in PermissionMiddleware are:
 * $node, $cc_config, $cc_workflows, $cc_user
 */
global $node, $cc_config;
// creates globals $cc_config, $cc_workflows, $cc_user
$node = new \CCNode\Node(parse_ini_file('node.ini'));

if ($cc_config->devMode) {
  ini_set('display_errors', '1');
  // this stops execution on ALL warnings and returns CCError objects
  set_error_handler( '\exception_error_handler' );
}

/**
 * Implement the Credit Commons API methods
 */
$app->options('/', function (Request $request, Response $response, $args) {
  global $node;
  $options = empty($args['id']) ? $node->getOptions() : [];
  return json_response($response, ['data' => $options]);
}
)->setName('permittedEndpoints')->add(PermissionMiddleware::class);


$app->get('/workflows', function (Request $request, Response $response) {
  global $cc_workflows; //is created when $node is instantiated
  return json_response($response, ['data' => $cc_workflows]);
}
)->setName('workflows')->add(PermissionMiddleware::class);

$app->get('/handshake', function (Request $request, Response $response) {
  global $node;
  return json_response($response, ['data' => $node->handshake()]);
}
)->setName('handshake')->add(PermissionMiddleware::class);

$app->get('/absolutepath', function (Request $request, Response $response) {
  global $node;
  return json_response($response, ['data' => $node->getAbsolutePath()]);
}
)->setName('absolutePath')->add(PermissionMiddleware::class);

$app->get("/convert", function (Request $request, Response $response, $args) {
  // get the downstream rate and multiply it by the current rate
  global $node;
  $query_params = $request->getQueryParams();
  if (!isset($query_params['node_path'])) {
    throw new CCViolation('Missing query param: node_path');
  }
  // Ensure the path has a final slash to identify it as a node, not an account?
  return json_response($response, ['data' => $node->convertPrice($query_params['node_path'])]);
}
)->setName('convertPrice')->add(PermissionMiddleware::class);//must be logged in to know which way to convert the price.

$app->get("/account/names", function (Request $request, Response $response, $args) {
  global $node;
  $query_params = $request->getQueryParams();
  $limit = $query_params['limit'] ??'10';
  // Assuming the limit is 10.
  $names = $node->accountNameFilter($query_params['acc_path']??'', $limit);
  return json_response($response, ['data' => $names]);
}
)->setName('accountNameFilter')->add(PermissionMiddleware::class);

$app->get("/account/summary", function (Request $request, Response $response, $args) {
  global $node;
  $query_params = $request->getQueryParams();
  $acc_path = $query_params['acc_path']??'';
  $content = $node->getAccountSummary($acc_path);
  return json_response($response, ['data' => $content]);
}
)->setName('accountSummary')->add(PermissionMiddleware::class);

$app->get("/account/limits", function (Request $request, Response $response, $args) {
  global $node;
  $query_params = $request->getQueryParams();
  $acc_path = $query_params['acc_path']??'';
  $content = $node->getAccountLimits($acc_path);
  return json_response($response, ['data' => (object)$content]);
}
)->setName('accountLimits')->add(PermissionMiddleware::class);

$app->get("/account/history", function (Request $request, Response $response, $args) {
  global $node;
  $query_params = $request->getQueryParams();
  $acc_path = $query_params['acc_path'];
  unset($query_params['acc_path']);
  if (!$acc_path) {
    throw new CCViolation ('Missing query param: acc_path');
  }
  $params = $query_params + ['samples' => -1];
  $points = $node->getAccountHistory($acc_path, $params['samples']);
  $times = array_keys($points);
  $content = [
    'data' => $points,
    'meta' => ['min' => min($points), 'max' => max($points), 'points' => count($points), 'start' => min($times), 'end'=>max($times)]
  ];
  $response->getBody()->write(json_encode($content));
  return $response;
}
)->setName('accountHistory')->add(PermissionMiddleware::class);

$uuid_regex = '[0-9a-f]{8}\b-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-\b[0-9a-f]{12}';
// Retrieve one transaction
$app->get('/transaction/{uuid:'.$uuid_regex.'}', function (Request $request, Response $response, $args) {
  global $node;
  $uuid = array_shift($args);
  [$transaction, $transitions] = $node->getTransaction($uuid);
  $content = [
    'data' => $transaction,
    'meta' => ['transitions' => [$transaction->uuid => $transitions]]
  ];
  return json_response($response, $content);
}
)->setName('getTransaction')->add(PermissionMiddleware::class);

// Retrieve one transaction as StandaloneEntries.
$app->get('/entries/{uuid:'.$uuid_regex.'}', function (Request $request, Response $response, $args) {
  global $node;
  $uuid = array_shift($args);
  $entries = $node->getTransactionEntries($uuid);
  return json_response($response, ['data' => $entries]);
}
)->setName('getTransaction')->add(PermissionMiddleware::class);

// The client sends a new transaction
$app->post('/transaction', function (Request $request, Response $response) {
  global $cc_user, $node;
// TODO try $request->getparsedBody()
  $request->getBody()->rewind(); // Testing Framework Middleware leaves this at the end.
  $data = json_decode($request->getBody()->getContents());
  // validate the input and create UUID
  $new_transaction = NewTransaction::create($data);
  $transaction = Transaction::createFromNew($new_transaction); // in state 'init'
  $additional_entries = $node->buildValidateRelayTransaction($transaction);
  $status_code = $transaction->version < 1 ? 200 : 201; // depends on workflow

  return json_response($response, ['data' => $transaction, 'meta' => ['transitions' => $transaction->transitions()]], $status_code);
}
)->setName('newTransaction')->add(PermissionMiddleware::class);

// An upstream node is sending a new transaction.
$app->post('/transaction/relay', function (Request $request, Response $response) {
  global $cc_user, $node, $cc_config;
  $request->getBody()->rewind(); // ValidationMiddleware leaves this at the end.
  $data = json_decode($request->getBody()->getContents());
  // Convert the incoming amounts as soon as possible.
  $transaction = TransversalTransaction::createFromUpstream($data);
  $additional_entries = $node->buildValidateRelayTransaction($transaction);
  $status_code = $transaction->version < 1 ? 200 : 201; // depends on workflow
  // $additional_entries via jsonSerialize
  return json_response($response, ['data' => array_values($additional_entries)], $status_code);
}
)->setName('relayTransaction')->add(PermissionMiddleware::class);

$app->patch('/transaction/{uuid:'.$uuid_regex.'}/{dest_state}', function (Request $request, Response $response, $args) {
  global $node;
  $written = $node->transactionChangeState($args['uuid'], $args['dest_state']);
  return $response->withStatus($written ? 201 : 200);
}
)->setName('stateChange')->add(PermissionMiddleware::class);

// Filter transactions
$app->get("/transactions", function (Request $request, Response $response, $args) {
  global $node;
  $params = $request->getQueryParams() + ['sort' => 'written', 'dir' => 'desc', 'limit' => 25, 'offset' => 0];
  [$count, $transactions, $transitions] = $node->filterTransactions($params);
  $content = [
    'data' => $transactions,
    'meta' => [
      'number_of_results' => $count,
      'current_page' => ($params['offset'] / $params['limit']) + 1,
      'transitions' => $transitions
    ],
    'links' => credcom_pager('/transactions', $params, $count)
  ];
  return json_response($response, $content);
}
)->setName('filterTransactions')->add(PermissionMiddleware::class);

// Filter transaction entries
$app->get("/entries", function (Request $request, Response $response, $args) {
  global $node;
  $params = $request->getQueryParams() + ['sort' => 'written', 'dir' => 'desc', 'limit' => 25, 'offset' => 0];
  [$count, $entries] = $node->filterTransactionEntries($params);
  $content = [
    'data' => $entries,
    'meta' => [
      'number_of_results' => $count,
      'current_page' => ($params['offset'] / $params['limit']) + 1,
    ],
    'links' => credcom_pager('/entries', $params, $count)
  ];
  return json_response($response, $content);
}
)->setName('filterTransactions')->add(PermissionMiddleware::class);

return $app;

/**
 * Populate a json response.
 *
 * @param Response $response
 * @param stdClass|array $body
 * @param int $code
 * @return Response
 *
 * @todo this should be moved to middleware
 */
function json_response(Response $response, $body = NULL, int $code = 200) : Response {
  if (is_scalar($body)){
    throw new CCFailure('Illegal value passed to json_response()');
  }
  $contents = json_encode($body, JSON_UNESCAPED_UNICODE);
  $response->getBody()->write($contents);
  return $response->withStatus($code)
    ->withHeader('Access-Control-Allow-Origin', '*')
    ->withHeader('Access-Control-Allow-Methods', 'GET')
    ->withHeader('Access-Control-Allow-Headers', 'content-type, cc-user, cc-auth')
    ->withHeader('Vary', 'Origin')
    ->withHeader('Content-Type', 'application/json');
}

/**
 * Custom error handling.
 * Everything, even warnings are logged locally AND thrown back to the client.
 */
function exception_error_handler( $severity, $message, $file, $line ) {
  throw new CCFailure("$message in $file: $line");
}

/**
 * Generate links first paged listings.
 *
 * @param string $endpoint
 * @param array $params
 * @param int $total_items
 * @return array
 */
function credcom_pager(string $endpoint, array $params, int $total_items) : array {
  $params = $params +=['offset' => 0, 'limit' => 25];
  $curr_page = floor($params['offset'] / $params['limit']);
  $pages = ceil($total_items/$params['limit']);
  $links = [];
  if ($pages > 1) {
    if($curr_page > 0) {
      $links['first'] = $endpoint .'?'.http_build_query(['offset' => 0] + $params);
      if($curr_page > 1) {
        $links['prev'] = $endpoint .'?'.http_build_query(['offset' => ($curr_page -1) * $params['limit']] + $params);
      }
    }
    if ($curr_page < $pages) {
      $links['next'] = $endpoint .'?'.http_build_query(['offset' => ($curr_page +1) * $params['limit']] + $params);
      if ($curr_page < ($pages -1)) {
        $links['last'] = $endpoint .'?'.http_build_query(['offset' => ($pages -1) * $params['limit']] + $params);
      }
    }
  }
  return $links;
}
