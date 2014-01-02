Exec { path => "/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin" }
service { 'networking':
  ensure => running,
  provider => 'upstart',
  subscribe => Network_config[['eth0', 'lo', 'lo:0']],
}
network_config { 'eth0':
  ensure  => 'present',
  family  => 'inet',
  method  => 'dhcp',
  onboot  => 'true',
  hotplug => 'true',
}
network_config { 'lo':
  ensure => 'present',
  family => 'inet',
  method => 'loopback',
  onboot => 'true',
}
network_config { 'lo:0':
  ensure => 'present',
  family => 'inet',
  method => 'static',
  ipaddress => '127.0.0.2',
  netmask => '255.0.0.0', 
  onboot => 'true',
}
class { 'apt': }
class { 'percona::repo::apt': }
class { 'percona':
  server => true,
  percona_version => '5.6',
}
Class['apt'] -> Class['percona']
Class['percona::repo::apt'] -> Class['percona::install']
class {'apache': }
class { 'php': }
apache::module { ['expires', 'headers', 'rewrite']: }
php::module { ['gd', 'mysql', 'xdebug']: }
php::pecl::module { "xhprof":
  use_package => no,
  preferred_state => "beta",
}
php::conf { 'xhprof.ini':
  path     => '/etc/php5/mods-available/xhprof.ini',
  content => 'extension=xhprof.so',
  before => Exec["enable-xhprof"],
}
exec { "enable-xhprof":
  command => "php5enmod xhprof",
}
file { '000-default':
  ensure  => 'absent',
  path    => "${apache::config_dir}/sites-enabled/000-default",
  require => Package['apache'],
  notify  => $apache::manage_service_autorestart,
}
apache::vhost { '127.0.0.2':
  docroot => '/home/drupal/drupal',
  template => 'apache_vhost.conf',
  subscribe => Network_config['lo'],
}
file { "apache_ports.conf":
  ensure  => 'present',
  path    => "${apache::config_dir}/ports.conf",
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
class { 'nginx': }
nginx::vhost { '127.0.0.1':
  docroot => '/home/drupal/webpagetest/www',
  template => 'wpt_vhost.conf',
  subscribe => Network_config['lo:0'],
}
file { 'default':
  ensure  => 'absent',
  path    => "${nginx::config_dir}/sites-enabled/default",
  require => Package['nginx'],
  notify  => $nginx::manage_service_autorestart,
}
package {['ffmpeg', 'php5-fpm']:
  ensure => 'installed'
}
# WPT also requires gd, declared above and zlib/zip which is built in.
php::module { ['curl']: }
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
package { 'google-chrome-stable':
  ensure => present,
  require => Apt::Source['chrome'],
}
apt::ppa { 'ppa:kai-mast/misc': }
package { 'ipfw3-utils':
  ensure => present,
  require => Apt::Ppa['ppa:kai-mast/misc'],
}
exec { 'ipfw':
  command => '/bin/chmod u+s /usr/bin/ipfw',
  require => Package['ipfw3-utils'],
}
package { 'xvfb':
  ensure => present,
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
