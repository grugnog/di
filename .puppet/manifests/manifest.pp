Exec { path => "/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin" }
class { 'apt': }
class { 'percona::repo::apt': }
class { 'percona':
  server => true,
  percona_version => '5.5',
}
class {'apache': }
class { 'php': }
php::module { 'pear':
  module_prefix => "php-",
}
php::module { 'apc':
  module_prefix => "php-",
}
php::module { 'xdebug': }
php::pecl::module { "xhprof":
  use_package => no,
  preferred_state => "beta",
}