<?php
namespace CCServer;

use CCNode\Db;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;


class LoggingMiddleware {

  public function __invoke(ServerRequestInterface $request, RequestHandlerInterface $handler) : ResponseInterface {
    global $cc_config;
    if ($cc_config->devMode){
      // Server may not be able to recreate the file.
      file_put_contents('last_exception.log', '');
      file_put_contents('error.log', '');
      $method = $request->getMethod();
      $uri = $request->getUri();
      $path = $uri->getPath();
      if ($params = $uri->getQuery()) {
        $path .= '?'.$params;
      }
      $headers = array_map(function ($val){return $val[0];}, $request->getHeaders());
      $request_headers = http_build_query($headers, '', "\n");
      $request_body = mysqli_real_escape_string(Db::connect(), strval($request->getBody()));

      $query = "INSERT INTO log (method, path, request_headers, request_body) "
      . "VALUES ('$method', '$path', '$request_headers', '$request_body');";
      $last_id = Db::query($query);
    }
    try {
      $response = $handler->handle($request);
    }
    catch (\Error $e) {
      // remove 404 log entries.
      if ($last_id and $e->getCode() == 404) {
        $query = "DELETE FROM log WHERE id = $last_id";
      }
      else {
        $this->completeLog($last_id, $e->getCode(), $e->getMessage());
      }
      throw $e;
    }

    // complete the log entry if
    if (isset($last_id)){
      $body = $response->getBody();
      $body->rewind();
      $this->completeLog($last_id, $response->getStatusCode(), $body->getContents());
    }
    return $response;
  }

  function completeLog(int $last_id, int $code, string $response_body) {
    $response_body = mysqli_real_escape_string(Db::connect(), $response_body);
    $query = "UPDATE log "
      . "SET response_code = '$code', response_body = \"$response_body\" "
      . "WHERE id = $last_id";
      Db::query($query);
  }


}
