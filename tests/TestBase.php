<?php

namespace CCServer\Tests;

use League\OpenAPIValidation\PSR15\ValidationMiddlewareBuilder;
use League\OpenAPIValidation\PSR15\SlimAdapter;
use Slim\Psr7\Response;
use PHPUnit\Framework\TestCase;

class TestBase extends TestCase {

  protected $rawAccounts = [];
  protected $adminAccIds = [];
  protected $normalAccIds = [];
  protected $branchAccIds = [];
  protected $trunkwardId = '';
  protected $nodePath = [];

  /**
   * Passwords, keyed by user
   * @var array
   */
  protected $passwords = [];//todo

  protected function sendRequest($path, int|string $expected_response, string $acc_id = '', string $method = 'get', string $request_body = '') : \stdClass|NULL|array {
    if ($query = strstr($path, '?')) {
      $path = strstr($path, '?', TRUE);
      parse_str(substr($query, 1), $params);
    }
    // This is a medium term hack to get around OpenAPI not supporting optional path parameters.
    if (substr($path, 0, 7) == 'account' and count(explode('/', $path)) > 2) {
      $trailing_slash = substr($path, -1) == '/' ? '/' : '';
      $subpath = implode('/', array_slice(explode('/', $path), 2));
      $old_subpath = $subpath;
      \CreditCommons\NodeRequester::prepareAccPath($subpath);
      $path = str_replace($old_subpath, $subpath, $path) . $trailing_slash;
    }

    $request = $this->getRequest($path, $method);
    if (isset($params)) {
      $request = $request->withQueryParams($params);
    }
    if ($acc_id) {
      $request = $request->withHeader('cc-user', $acc_id)
        ->withHeader('cc-auth', $this->passwords[$acc_id]??'---');
    }
    if ($request_body) {
      $request = $request->withHeader('Content-Type', 'application/json');
      $request->getBody()->write($request_body);
    }
    $response = $this->getApp()->process($request, new Response());
    $response->getBody()->rewind();
    $contents = json_decode($response->getBody()->getContents());
    $status_code = $response->getStatusCode();
    if (is_int($expected_response)) {
      if ($status_code <> $expected_response) {
        // Blurt out to terminal to ensure all info is captured.
        echo "\n $acc_id got unexpected code ".$status_code." on $path: ".print_r($contents, 1);
      }
      $this->assertEquals($expected_response, $status_code);
    }
    elseif (is_string($expected_response)) {
      if (isset($contents->class)) {
        $err = \CreditCommons\NodeRequester::reconstructCCErr($contents);
        $class = "\\CreditCommons\Exceptions\\$expected_response";
        if (!$err instanceof $class) {
          echo "\nUnexpected error: ".print_r($err, 1);
        }
        $this->assertInstanceOf($class, $err);
      }
      else {
        echo "\nExpected $expected_response but got: ".print_r($contents, 1);
        $this->assertEquals(1, 0, 'Expected error but got something else.');
      }
    }
    return $contents;
  }

  protected function getRequest($path, $method = 'GET') {
    $psr17Factory = new \Nyholm\Psr7\Factory\Psr17Factory();
    return $psr17Factory->createServerRequest(strtoupper($method), '/'.$path);
  }

  /**
   *
   * @staticvar string $app
   *   address of api file relative to the application root
   * @param type $api_file
   * @return \Slim\App
   */
  protected function getApp(): \Slim\App {
    static $app;
    if (!$app) {
      $app = require_once static::SLIM_PATH;
      if (static::API_FILE_PATH) {
        $spec = file_get_contents(static::API_FILE_PATH);
        $psr15Middleware = (new ValidationMiddlewareBuilder)
          ->fromYaml($spec)
          ->getValidationMiddleware();
        $middleware = new SlimAdapter($psr15Middleware);
        $app->add($middleware);
      }
    }
    return $app;
  }


  function loadAccounts() {
    global $config;
    $this->nodePath = explode('/', $config->absPath);
    $this->rawAccounts = (array)json_decode(file_get_contents('accounts.json'));

    foreach ($this->rawAccounts as $acc_id => $acc) {
      if (!empty($acc->key)) {
        $this->passwords[$acc_id] = $acc->key;
        if ($acc->admin) {
          $this->adminAccIds[] = $acc_id;
        }
        else {
          $this->normalAccIds[] = $acc_id;
        }
      }
      elseif (!empty($acc->url)) {
        if ($acc->id == $config->trunkwardAcc) {
          $this->trunkwardId = $acc_id;
        }
        else {
          $this->branchAccIds[] = $acc_id;
        }
      }
    }
    if (empty($this->normalAccIds) || empty($this->adminAccIds)) {
      die("Testing requires both admin and non-admin accounts in store.json");
    }
  }

}
