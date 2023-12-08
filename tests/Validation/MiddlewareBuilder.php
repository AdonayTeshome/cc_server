<?php

namespace CCServer\Tests\Validation;

use CCServer\Tests\Validation\Middleware;
use League\OpenAPIValidation\PSR7\ValidatorBuilder;
use Psr\Http\Server\MiddlewareInterface;

final class MiddlewareBuilder extends ValidatorBuilder {

  public function getValidationMiddleware(): MiddlewareInterface {
    return new Middleware(
      $this->getServerRequestValidator(),
      $this->getResponseValidator()
    );
  }

}
