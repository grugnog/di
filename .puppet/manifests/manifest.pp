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
apache::vhost { 'localhost':
  docroot  => '/home/drupal/docroot',
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
}
class apache_wpt {
  $service = 'apache2-wpt'
  $config_dir = '/etc/apache2-wpt'
  $config_file = '/etc/apache2-wpt/apache2.conf'
  $config_file_mode = '0644'
  $config_file_owner = 'root'
  $config_file_group = 'root'
  $vhost_dir = '/etc/apache2-wpt/sites-enabled'
  $pid_file = '/var/run/apache2-wpt.pid'
  $log_dir = '/var/log/apache2-wpt'
  $log_file = ['/var/log/apache2-wpt/access.log','/var/log/apache2-wpt/error.log']
  $port = '8888'
  include apache
}
class {'apache_wpt': }
file { "wpt_ports.conf":
  ensure  => 'present',
  path    => "${apache_wpt::config_dir}/ports.conf",
  mode    => $apache_wpt::config_file_mode,
  owner   => $apache_wpt::config_file_owner,
  group   => $apache_wpt::config_file_group,
  require => Package['apache'],
  notify  => $apache_wpt::manage_service_autorestart,
  content => template('wpt_ports.conf'),
  audit   => $apache_wpt::manage_audit,
}
define apache_wpt::vhost (
  $docroot        = '/home/drupal/wpt/www',
  $docroot_create = false,
  $docroot_owner  = 'root',
  $docroot_group  = 'root',
  $port           = '8888',
  $ssl            = false,
  $template       = 'apache/virtualhost/vhost.conf.erb',
  $priority       = '50',
  $serveraliases  = '',
  $enable         = true ) {

  $ensure = bool2ensure($enable)
  $bool_docroot_create = any2bool($docroot_create)

  $real_docroot = $docroot ? {
    ''      => "${apache_wpt::data_dir}/${name}",
    default => $docroot,
  }

  include apache_wpt

  file { "${apache_wpt::vdir}/${priority}-${name}.conf":
    ensure  => $ensure,
    content => template($template),
    mode    => $apache_wpt::config_file_mode,
    owner   => $apache_wpt::config_file_owner,
    group   => $apache_wpt::config_file_group,
    require => Package['apache'],
    notify  => $apache_wpt::manage_service_autorestart,
  }

  file { "ApacheVHostEnabled_$name":
    ensure  => $enable ? {
      true  => "${apache_wpt::vdir}/${priority}-${name}.conf",
      false => absent,
    },
    path    => "${apache_wpt::config_dir}/sites-enabled/${priority}-${name}.conf",
    require => Package['apache'],
  }

  if $bool_docroot_create == true {
    file { $real_docroot:
      ensure  => directory,
      owner   => $docroot_owner,
      group   => $docroot_group,
      mode    => '0775',
      require => Package['apache'],
    }
  }
}
apache_wpt::vhost { 'wpt': }
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
