include wpt

wpt::vhost { 'testsite':
  docroot       => '/var/www/test',
  env_variables => ['APP_ENV dev'],
}

