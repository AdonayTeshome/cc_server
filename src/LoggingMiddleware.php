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
      // server may not be able to recreate the file.
      file_put_contents('last_exception.log', '');
      file_put_contents('error.log', '');
    }

    $method = $request->getMethod();
    $uri = $request->getUri();
    $path = $uri->getPath();
    if ($params = $uri->getQuery()) {
      $path .= '?'.$params;
    }
    $headers = array_map(function ($val){return $val[0];}, $request->getHeaders());
    $request_headers = http_build_query($headers, '', "\n");
    $request_body = mysqli_real_escape_string(Db::connect(), strval($request->getBody()->getContents()));

    $query = "INSERT INTO log (method, path, request_headers, request_body) "
    . "VALUES ('$method', '$path', '$request_headers', '$request_body');";
    $last_id = Db::query($query);

    $response = $handler->handle($request);

    $response_code = $response->getStatusCode();
    $body = $response->getBody();
    $body->rewind();
    // When response_code is 400 or 500, the response_body is empty.
    $response_body = mysqli_real_escape_string(Db::connect(), $body->getContents());
    $body->rewind();
    $query = "UPDATE log "
      . "SET response_code = '$response_code', response_body = \"$response_body\" "
      . "WHERE id = $last_id";
    Db::query($query);
    return $response;


  }


}
