<?php
  class a {
    public function __construct() {
      c();
      c();
    }
  }

  class b {
    public function __construct() {
      d();
      d();
    }
  }

  function c() {
  }

  function d() {
    usleep(100000);
  }

  $a1 = new a;
  $b = new b;
  $a2 = new a;
  d();
  c();
  d();
  c();

  register_shutdown_function('c');
  register_shutdown_function('c');