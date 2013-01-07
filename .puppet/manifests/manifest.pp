Exec { path => "/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin" }
class { 'apt': }
class { 'percona::repo::apt': }
class { 'percona':
  server => true,
  percona_version => '5.5',
}
class {'apache': }
class { 'php': }
php::module { "gd": }
php::module { "mysql": }
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
file { '000-default':
  ensure  => 'absent',
  path    => "${apache::config_dir}/sites-enabled/000-default",
  require => Package['apache'],
  notify  => $apache::manage_service_autorestart,
}
apache::vhost { 'drupal':
  docroot  => '/home/drupal/drupal',
}
percona::database { 'drupal':
  ensure => present,
}
percona::rights {'drupal@localhost/drupal':
  priv => 'all',
  password => 'drupal',
}
# WPT server specific
exec { 'apache_instance_wpt':
  command => '/usr/bin/sudo /bin/sh /usr/share/doc/apache2.2-common/examples/setup-instance wpt',
  onlyif => 'test ! -d /etc/apache2-wpt',
  require => Package['apache'],
}
Exec['apache_instance_wpt'] -> apache::vhost['drupal']
file['000-default'] -> Exec['apache_instance_wpt']
Class['php'] -> Exec['apache_instance_wpt']
Exec['apache_instance_wpt'] -> file['wpt_ports.conf']
Exec['apache_instance_wpt'] -> wpt::vhost['wpt']
class {'wpt':
  package => 'apache2-mpm-prefork', # This is hacky, but saves having to edit the module.
  service => 'apache2-wpt',
  config_dir => '/etc/apache2-wpt',
  config_file => '/etc/apache2-wpt/apache2.conf',
  pid_file => '/var/run/apache2-wpt.pid',
  log_dir => '/var/log/apache2-wpt',
  log_file => ['/var/log/apache2-wpt/access.log','/var/log/apache2-wpt/error.log'],
  port => '8888',
}
file { "wpt_ports.conf":
  ensure  => 'present',
  path    => "${wpt::config_dir}/ports.conf",
  mode    => $wpt::config_file_mode,
  owner   => $wpt::config_file_owner,
  group   => $wpt::config_file_group,
  require => Package['apache'],
  notify  => $wpt::manage_service_autorestart,
  content => template('wpt_ports.conf'),
  audit   => $wpt::manage_audit,
}
wpt::vhost { 'wpt': 
  docroot => '/home/drupal/wpt/www',
  port => '8888',
}
# Test agent specific
apt::source { 'chrome':
  location => 'http://dl.google.com/linux/chrome/deb/',
  repos => 'main',
  release => 'stable',
  include_src => false,
  key_source => 'http://www.google.com/linuxrepositories/',
}
package { 'google-chrome-stable':
  ensure => present,
}
apt::ppa { 'ppa:grugnog/ipfw': }
package { 'ipfw3-utils':
  ensure => present,
}
package { ['node', 'default-jdk']:
  ensure => present,
}
