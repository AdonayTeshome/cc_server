<?php
namespace CCServer;

use CreditCommons\Exceptions\CCError;
use CreditCommons\Exceptions\CCFailure;

/**
 * Convert all errors into an stdClass, which includes a field showing
 * which node caused the error
 */
class Slim3ErrorHandler {

  /**
   * Probably all errors and warnings should include an emergency SMS to admin.
   * This callback is also used by the ValidationMiddleware.
   *
   * @note The task is made complicated because the $exception->message property is
   * protected and therefore lost during json_encode
   */
  public function __invoke($request, $response, $exception) {
    global $cc_user, $cc_config;
    if ($cc_config->devMode) {
      file_put_contents('last_exception.log', print_r($exception, 1)); //temp
    }
    $exception_class = explode('\\', get_class($exception));
    $exception_class = array_pop($exception_class);
    if ($exception instanceOf CCError) {// New or received from downstream.
      $output = $exception;
    }
    else {// An error from elsewhere, make a CCFailure.
      $code = 500;
      $exception_class = 'CCFailure';
      $output = new CCFailure($exception->getMessage()?:$exception_class);
      // Just show the last error.
//      while ($exception = $exception->getPrevious()) {
//        $output = (object)[
//          'message' => $exception->getMessage()?:$exception_class
//        ];
//        //if (get_class($exception) == 'League\OpenAPIValidation\PSR7\Exception\NoResponseCode') break;
//      }
    }
    $output->class = $exception_class;
    if (isset($exception->node)) {
      $output->node = $exception->node;
      $output->trace = $exception->trace;
      $output->break = $exception->break;
      $output->method = $exception->method;
      $output->path = $exception->path;
      $output->user = $exception->user;
    }
    else {
      $output->node = $cc_config->nodeName;
      $output->trace = $exception->getTraceAsString(); //experimental;
      $output->break = $exception->getFile() .': '.$exception->getLine();
      $output->method = $request->getMethod();
      $output->path = $request->geturi()->getPath();
      $output->user = $cc_user ? $cc_user->id : '-anon-';
      if ($q = $request->geturi()->getQuery()){
        $output->path .= '?'.$q;
      }
    }
    return json_response($response, $output, $code??$exception->getCode());
   }

}
