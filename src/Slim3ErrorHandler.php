<?php
namespace CCServer;

use CreditCommons\Exceptions\CCError;
use CreditCommons\Exceptions\CCFailure;
use League\OpenAPIValidation\PSR15\Exception\InvalidResponseMessage;

/**
 * Convert all errors and warnings into Credcom exceptions and send them upstream
 */
class Slim3ErrorHandler {

  /**
   * Probably all errors and warnings should include an emergency SMS to admin.
   */
  public function __invoke($request, $response, \Throwable $exception) {
    global $cc_user, $cc_config;
    if ($cc_config->devMode) {
      file_put_contents('last_exception.log', "Disable this log in src/Slim3ErrorHandler.php\n".print_r($exception, 1));
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
      $code = 500;
    }
    $output = CCError::convertException($exception);
    $body = $response->getBody();
    $body->write(json_encode(['errors' => [$output]], JSON_UNESCAPED_UNICODE));
    return $response
      ->withHeader('Content-Type', 'application/json')
      ->withStatus($output instanceOf CCViolation ? '400' : 500);
   }

}
