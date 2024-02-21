<?php
namespace CCServer;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;


class DecorateResponse {

  public function __invoke(ServerRequestInterface $request, RequestHandlerInterface $handler) : ResponseInterface {
    $response = $handler->handle($request);
    // Do these headers just apply to the OPTIONS requests?
    return $response
      ->withHeader('Access-Control-Allow-Origin', '*')
      ->withHeader('Access-Control-Allow-Methods', 'GET')
      ->withHeader('Access-Control-Allow-Headers', 'content-type, cc-user, cc-auth')
      ->withHeader('Vary', 'Origin')
      ->withHeader('Content-Type', 'application/json');
  }


}
