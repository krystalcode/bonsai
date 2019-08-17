<?php

namespace Bonsai;

interface EventTransformerInterface {
  public function transform($event, array $options = []);
}
