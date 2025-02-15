<?php

namespace CCServer\Tests;

use CCServer\Tests\Validation\MiddlewareBuilder;
use CreditCommons\ErrorContext;
use PHPUnit\Framework\TestCase;
use Nyholm\Psr7\Factory\Psr17Factory;

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

  /**
   * get the slim app with the validation middleware
   * @staticvar string $app
   *   Address of api file relative to the application root
   * @return \Slim\App
   */
  protected function getApp(): \Slim\App {
    static $app;
    if (!$app) {
      $psr15Middleware = (new MiddlewareBuilder)
        ->fromYamlFile(static::API_FILE_PATH)// rel or abs? $container->get('settings')['openApi']['specificationFilePath'],
        //->fromYaml(file_get_contents(static::API_FILE_PATH))
        ->getValidationMiddleware();
      $app = require_once static::SLIM_PATH;
      $app->add($psr15Middleware);
    }
    return $app;
  }

  /**
   * @param type $path
   * @param int|string $expected_response
   * @param string $acc_id
   * @param string $method
   * @param string $request_body
   * @return \stdClass|NULL|array
   */
  protected function sendRequest($path, int|string $expected_response, string $acc_id = '', string $method = 'get', string $request_body = '') : \stdClass|NULL|array {
    ErrorContext::create(node: 'test', method: $method, path: $path, user: $acc_id);
    if ($query = strstr($path, '?')) {
      $path = strstr($path, '?', TRUE);
      parse_str(substr($query, 1), $params);
    }
    $request = $this->getRequest($path, strtoupper($method));
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
    try {
      $response = $this->getApp()->handle($request);
    }
    catch (\Exception $e) {
      echo $e->getMessage();
      return NULL;
    }
    $raw_contents = strval($response->getBody());
    $contents = json_decode($raw_contents);
    $status_code = $response->getStatusCode();
    if (is_int($expected_response)) {
      if ($status_code <> $expected_response) {
        // Blurt out to terminal to ensure all info is captured.
        echo "\n$acc_id got unexpected code ".$status_code." on $path: ".print_r($contents, 1);
        if ($status_code == 500 and empty($contents)) {
          $divider = strpos($raw_contents, '{"errors":');
          $result = json_decode(substr($raw_contents, 0, $divider));
          echo "\nGiven result:".print_r($result, 1);
          $error = json_decode(substr($raw_contents, $divider))->errors[0];
          echo "\n500 error appended to result".print_r($error, 1);
        }
      }
      // stop this test;
      $this->assertEquals($expected_response, $status_code);
      return $contents;
    }
    elseif (is_string($expected_response)) {
      // $expected_response is the classname of the error.
      if (!empty($contents->errors) and is_array($contents->errors)) {
        $error = $contents->errors[0];
        $err = \CreditCommons\NodeRequester::reconstructCCErr($error);
        $class = "\\CreditCommons\Exceptions\\$expected_response";
        if (!$err instanceof $class) {// Print to terminal, hopefully
          echo "\nUnexpected error: ".print_r($err, 1);
        }
        $this->assertInstanceOf($class, $err);
        return $error;
      }
      else {
        echo "\nExpected $expected_response but got: ".print_r($contents, 1);
        echo "\nRequest body was:";
        echo "\n".$request_body;
        $this->assertEquals(1, 0, 'Expected error but got something else.');
      }
      return NULL;
    }
  }

  /**
   * @param string $path
   * @param string $method
   * @return type
   */
  protected function getRequest($path, $method = 'GET') : \Psr\Http\Message\RequestInterface {
    $psr17Factory = new Psr17Factory();
    return $psr17Factory->createServerRequest(strtoupper($method), '/'.$path);
  }

  /**
   *
   */
  function loadAccounts($include_remote = FALSE) {
    global $cc_config;
    $this->nodePath = explode('/', $cc_config->absPath);
    if ($cc_config->accountStore == '\Examples\AccountStore') {
      $this->rawAccounts = (array)json_decode(file_get_contents('accountstore.json'));
    }
    else {
      die('Testing requires account auth strings which can only be obtained using the example AccountStore. This node uses '.$cc_config->accountStore.'. To test the Accountstore see tests/AccountStoreTest');
    }
    foreach ($this->rawAccounts as $acc_id => $acc) {
      if (!empty($acc->key)) {
        $this->passwords[$acc_id] = $acc->key;
        if (isset($acc->admin) and $acc->admin) {
          $this->adminAccIds[] = $acc_id;
        }
        else {
          $this->normalAccIds[] = $acc_id;
        }
      }
      elseif (!empty($acc->url)) {
        if ($acc->id == $cc_config->trunkwardAcc) {
          $this->trunkwardId = $acc_id;
        }
        else {
          $this->branchAccIds[] = $acc_id;
        }
      }
    }
    if (empty($this->normalAccIds) || empty($this->adminAccIds)) {
      die("Testing requires both admin and non-admin accounts in ".realpath('accountstore.json'));
    }
  }

}
