<?php

namespace Bonsai;

interface MessageTransformerInterface {
  public function transform($message, array $options = []);
}
