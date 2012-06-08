<?php

function a() {
  print __FUNCTION__ . "\n";
  c(100);
  c(1000);
}

function b() {
  print __FUNCTION__ . "\n";
  c(10000);
  c(100000);
}

function c($c) {
  print " " . __FUNCTION__ . "\n";
  for ($i = 0; $i < $c; $i++) {}
  d(10000000 / $c / 4);
}

function d($c) {
  print "  " . __FUNCTION__ . "\n";
  for ($i = 0; $i < $c; $i++) {}
}

a();
a();
b();
register_shutdown_function('b');

?>