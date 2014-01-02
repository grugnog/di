<?php

$options['l'] = 'http://127.0.0.2';
$options['r'] = '/home/drupal/drupal';
$options['cache'] = TRUE;
$options['y'] = TRUE;
$options['yes'] = TRUE;
$command_specific['site-install'] = array(
  'account-name' => 'drupal',
  'account-pass' => 'drupal',
  'db-url' => 'mysql://drupal:drupal@localhost/drupal',
);
