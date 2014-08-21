<?php


class SimpleAuthProviderClient extends \Thruway\Authentication\AbstractAuthProviderClient {

  /**
   * @return string
   */
  public function getMethodName() {
    return 'simplysimple';
  }

  public function processAuthenticate($signature, $extra = NULL) {

    if ($signature == "letMeIn") {
      return array("SUCCESS");
    }
    else {
      return array("FAILURE");
    }

  }
}