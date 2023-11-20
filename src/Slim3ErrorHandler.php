<?php
namespace CCServer;

use CreditCommons\Exceptions\CCError;
use CreditCommons\Exceptions\CCFailure;
use CreditCommons\Exceptions\CCViolation;
use League\OpenAPIValidation\PSR15\Exception\InvalidResponseMessage;
use CCNode\Db;

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
    }
    $output = CCError::convertException($exception);
    $body = $response->getBody();
    $code = $output instanceOf CCViolation ? 400 : 500;
    $response_body = mysqli_real_escape_string(Db::connect(), $output);
    // Update the log because the logging middleware seems to be skipped
    Db::query("UPDATE log SET response_code = '$code', response_body = \"$response_body\" ORDER BY id DESC LIMIT 1");
    $body->write(json_encode(['errors' => [$output]], JSON_UNESCAPED_UNICODE));
    return $response
      ->withHeader('Content-Type', 'application/json')
      ->withStatus($code);
   }

}
