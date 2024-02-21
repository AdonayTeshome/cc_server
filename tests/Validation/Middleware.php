<?php

namespace CCServer\Tests\Validation;

use League\OpenAPIValidation\PSR7\ServerRequestValidator;
use League\OpenAPIValidation\PSR7\ResponseValidator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * ValidationMiddleware for Slim 4.
 * @see https://github.com/thephpleague/openapi-psr7-validator/issues/136
 */
final class Middleware implements MiddlewareInterface {
  /** @var ServerRequestValidator */
  private ServerRequestValidator $requestValidator;
  /** @var ResponseValidator */
  private ResponseValidator $responseValidator;

  public function __construct(ServerRequestValidator $requestValidator, ResponseValidator $responseValidator) {
    $this->requestValidator = $requestValidator;
    $this->responseValidator = $responseValidator;
  }

  /**
   * Process an incoming server request.
   *
   * Processes an incoming server request in order to produce a response.
   * If unable to produce the response itself, it may delegate to the provided
   * request handler to do so.
   * @param ServerRequestInterface $request
   * @param RequestHandlerInterface $handler
   * @return ResponseInterface
   * @throws HttpException
   */
  public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
  {
    try {
      // 1. Validate request
      $matchedOASOperation = $this->requestValidator->validate($request);

      // 2. Process request
      $response = $handler->handle($request);

      // 3. Validate response
      $this->responseValidator->validate($matchedOASOperation, $response);

      return $response;
    } catch (Throwable $e) {
      // @todo this doesn't seem to be a class.
      throw ExceptionFactory::getHttpException($e);
    }
  }
}
