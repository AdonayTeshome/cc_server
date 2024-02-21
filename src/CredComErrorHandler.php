<?php
namespace CCServer;

use CCNode\Db;
use CreditCommons\Exceptions\CCError;
use CreditCommons\Exceptions\CCFailure;
use CreditCommons\Exceptions\CCViolation;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use League\OpenAPIValidation\PSR15\Exception\InvalidResponseMessage;

/**
 * Convert all errors and warnings into Credcom exceptions and send them upstream
 */
class CredComErrorHandler extends \Slim\Handlers\ErrorHandler {

  /**
   * {@inheritDoc}
   */
  public function __invoke(ServerRequestInterface $request, \Throwable $exception, bool $displayErrorDetails, bool $logErrors, bool $logErrorDetails) : ResponseInterface {
    global $cc_config;
    if (!isset($cc_config)) {
      // The error happened before the config was loaded.
      // Can probably do something tidier than this.
      print_r($exception);
      $this->writeLastException($exception);
      exit;
    }
    if ($cc_config->devMode) {
      $this->writeLastException($exception);
    }
    if ($exception instanceof InvalidResponseMessage) {// Testing framework error
      $message = $exception->getMessage();
      if ($prev = $exception->getPrevious()) {
        $message .= "\n".$prev->getMessage();
      }
      if ($prev = $prev->getPrevious()) {
        $message .= "\n".$prev->getMessage()."\n";
      }
      $exception = new CCFailure($message);
    }
    return parent::__invoke($request, $exception, $displayErrorDetails, $logErrors, $logErrorDetails);
  }

  /**
   * {@inheritDoc}
   */
  protected function respond(): ResponseInterface {
    // @todo handle other classes of Slim Http errors?
    if ($this->exception instanceof HttpMethodNotAllowedException) {
      $response = $this->responseFactory->createResponse(500);
      $allowedMethods = implode(', ', $this->exception->getAllowedMethods());
      $response = $response->withHeader('Allow', $allowedMethods);
    }
    else {
      $this->exception = CCError::convertException($this->exception);
      //$this->exception->trace = $trace();
      $code = $this->exception instanceOf CCViolation ? 400 : 500;
      $response = $this->responseFactory->createResponse($this->statusCode);
      $response = $response->withHeader('Content-type', 'application/json');
      $body = $response->getBody();
      $body->write(json_encode(['errors' => [$this->exception]], JSON_UNESCAPED_UNICODE));
    }
    return $response;
  }

  private function writeLastException(\Throwable $exception) {
    $contents = "Disable this log in node.ini:devMode or src/CredComErrorHandler.php";
    $contents .= "\n".print_r($exception, 1);
    file_put_contents('last_exception.log', $contents);
  }

  protected function writeToErrorLog(): void {
    $response_body = mysqli_real_escape_string(Db::connect(), $this->exception);
    $code = $this->exception->getCode();
    // Update the log because the logging middleware seems to be skipped
    Db::query("UPDATE log SET response_code = '$code', response_body = \"$response_body\" ORDER BY id DESC LIMIT 1");
  }

}
