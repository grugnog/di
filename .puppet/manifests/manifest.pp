Exec { path => "/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin" }
class { 'apt': }
class { 'percona::repo::apt': }
class { 'percona':
  server => true,
  percona_version => '5.5',
}
Class['apt'] -> Class['percona']
Class['percona::repo::apt'] -> Class['percona::install']
class {'apache': }
class { 'php': }
apache::module { ['expires', 'headers', 'rewrite']: }
php::module { ['gd', 'mysql', 'xdebug']: }
php::module { ['apc']:
  module_prefix => "php-",
}
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
file { "apache_ports.conf":
  ensure  => 'present',
  path    => "${apache::config_dir}/ports.conf",
  mode    => $apache::config_file_mode,
  owner   => $apache::config_file_owner,
  group   => $apache::config_file_group,
  require => Package['apache'],
  notify  => $apache::manage_service_autorestart,
  content => template('apache_ports.conf'),
  audit   => $apache::manage_audit,
}
percona::database { 'drupal':
  ensure => present,
}
percona::rights {'drupal@localhost/drupal':
  priv => 'all',
  password => 'drupal',
}

# WPT server specific
package {'ffmpeg':
  ensure => 'installed'
}
exec { 'apache_instance_wpt':
  command => '/bin/sh /usr/share/doc/apache2.2-common/examples/setup-instance wpt',
  onlyif => 'test ! -d /etc/apache2-wpt',
  require => Package['apache'],
}
# Ordering rules - first common config
File['000-default'] -> Exec['apache_instance_wpt']
Class['php'] -> Exec['apache_instance_wpt']
# Specific config
Exec['apache_instance_wpt'] -> File['wpt_ports.conf']
Exec['apache_instance_wpt'] -> Wpt::Vhost['wpt']
Exec['apache_instance_wpt'] -> File['apache_ports.conf']
Exec['apache_instance_wpt'] -> Apache::Vhost['drupal']
class {'wpt':
  package => 'apache2-mpm-prefork', # This is hacky, but saves having to edit the module.
  service => 'apache2-wpt',
  config_dir => '/etc/apache2-wpt',
  config_file => '/etc/apache2-wpt/apache2.conf',
  pid_file => '/var/run/apache2-wpt.pid',
  log_dir => '/var/log/apache2-wpt',
  log_file => ['/var/log/apache2-wpt/access.log','/var/log/apache2-wpt/error.log'],
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
# WPT also requires gd, declared above and zlib/zip which is built in.
php::module { ['curl']: }
wpt::vhost { 'wpt': 
  docroot => '/home/drupal/webpagetest/www/webpagetest',
}
# Test agent specific
exec { 'default_shell':
  command => 'echo "set dash/sh false" | debconf-communicate && dpkg-reconfigure dash -pcritical'
}
apt::source { 'chrome':
  location => 'http://dl.google.com/linux/chrome/deb/',
  repos => 'main',
  release => 'stable',
  include_src => false,
  key => "A040830F7FAC5991",
  key_server => "keyserver.ubuntu.com",
}
package { 'xvfb':
  ensure => present,
}
package { 'google-chrome-stable':
  ensure => present,
  require => Apt::Source['chrome'],
}
apt::ppa { 'ppa:grugnog/ipfw': }
package { 'ipfw3-utils':
  ensure => present,
  require => Apt::Ppa['ppa:grugnog/ipfw'],
}
exec { 'ipfw':
  command => '/bin/chmod u+s /usr/bin/ipfw',
  require => Package['ipfw3-utils'],
}

file { 'xvfb.conf':
  ensure  => 'present',
  path    => "/etc/init/xvfb.conf",
  require => Package['xvfb'],
  content => template('xvfb.conf'),
}
service { 'xvfb':
  ensure => running,
  provider => 'upstart',
  require => File['xvfb.conf'],
}
file { 'wptdriver.conf':
  ensure  => 'present',
  path    => "/etc/init/wptdriver.conf",
  content => template('wptdriver.conf'),
}
service { 'wptdriver':
  ensure => running,
  provider => 'upstart',
  require => File['wptdriver.conf'],
}
apt::ppa { 'ppa:chris-lea/node.js': }
package { 'nodejs':
  ensure => present,
  require => Apt::Ppa['ppa:chris-lea/node.js'],
}
package { 'default-jdk':
  ensure => present,
}
