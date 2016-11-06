<?php

namespace Bonsai;

interface RepositoryInterface
{
  public function getOne($url);
  public function getList(array $options = array());
}
