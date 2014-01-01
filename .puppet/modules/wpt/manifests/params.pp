# Class: wpt::params
#
# This class defines default parameters used by the main module class wpt
# Operating Systems differences in names and paths are addressed here
#
# == Variables
#
# Refer to wpt class for the variables defined here.
#
# == Usage
#
# This class is not intended to be used directly.
# It may be imported or inherited by other classes
#
class wpt::params {

  ### Application specific parameters
  $package_modssl = $::operatingsystem ? {
    /(?i:Ubuntu|Debian|Mint)/ => 'libwpt-mod-ssl',
    /(?i:SLES|OpenSuSE)/      => undef,
    default                   => 'mod_ssl',
  }

  ### Application related parameters

  $package = $::operatingsystem ? {
    /(?i:Ubuntu|Debian|Mint)/ => 'wpt2',
    /(?i:SLES|OpenSuSE)/      => 'wpt2',
    default                   => 'httpd',
  }

  $service = $::operatingsystem ? {
    /(?i:Ubuntu|Debian|Mint)/ => 'wpt2',
    /(?i:SLES|OpenSuSE)/      => 'wpt2',
    default                   => 'httpd',
  }

  $service_status = $::operatingsystem ? {
    default => true,
  }

  $process = $::operatingsystem ? {
    /(?i:Ubuntu|Debian|Mint)/ => 'wpt2',
    /(?i:SLES|OpenSuSE)/      => 'httpd2-prefork',
    default                   => 'httpd',
  }

  $process_args = $::operatingsystem ? {
    default => '',
  }

  $process_user = $::operatingsystem ? {
    /(?i:Ubuntu|Debian|Mint)/ => 'www-data',
    /(?i:SLES|OpenSuSE)/      => 'wwwrun',
    default                   => 'wpt',
  }

  $config_dir = $::operatingsystem ? {
    /(?i:Ubuntu|Debian|Mint)/ => '/etc/wpt2',
    /(?i:SLES|OpenSuSE)/      => '/etc/wpt2',
    freebsd                   => '/usr/local/etc/wpt20',
    default                   => '/etc/httpd',
  }

  $config_file = $::operatingsystem ? {
    /(?i:Ubuntu|Debian|Mint)/ => '/etc/wpt2/wpt2.conf',
    /(?i:SLES|OpenSuSE)/      => '/etc/wpt2/httpd.conf',
    freebsd                   => '/usr/local/etc/wpt20/httpd.conf',
    default                   => '/etc/httpd/conf/httpd.conf',
  }

  $config_file_mode = $::operatingsystem ? {
    default => '0644',
  }

  $config_file_owner = $::operatingsystem ? {
    default => 'root',
  }

  $config_file_group = $::operatingsystem ? {
    freebsd => 'wheel',
    default => 'root',
  }

  $config_file_init = $::operatingsystem ? {
    /(?i:Debian|Ubuntu|Mint)/ => '/etc/default/wpt2',
    /(?i:SLES|OpenSuSE)/      => '/etc/sysconfig/wpt2',
    default                   => '/etc/sysconfig/httpd',
  }

  $pid_file = $::operatingsystem ? {
    /(?i:Debian|Ubuntu|Mint)/ => '/var/run/wpt2.pid',
    /(?i:SLES|OpenSuSE)/      => '/var/run/httpd2.pid',
    default                   => '/var/run/httpd.pid',
  }

  $log_dir = $::operatingsystem ? {
    /(?i:Debian|Ubuntu|Mint)/ => '/var/log/wpt2',
    /(?i:SLES|OpenSuSE)/      => '/var/log/wpt2',
    default                   => '/var/log/httpd',
  }

  $log_file = $::operatingsystem ? {
    /(?i:Debian|Ubuntu|Mint)/ => ['/var/log/wpt2/access.log','/var/log/wpt2/error.log'],
    /(?i:SLES|OpenSuSE)/      => ['/var/log/wpt2/access.log','/var/log/wpt2/error.log'],
    default                   => ['/var/log/httpd/access.log','/var/log/httpd/error.log'],
  }

  $data_dir = $::operatingsystem ? {
    /(?i:Debian|Ubuntu|Mint)/ => '/var/www',
    /(?i:Suse|OpenSuse)/      => '/srv/www/htdocs',
    default                   => '/var/www/html',
  }

  $ports_conf_path = $::operatingsystem ? {
    /(?i:Debian|Ubuntu|Mint)/ => '/etc/wpt2/ports.conf',
    default                   => '',
  }

  $port = '80'
  $ssl_port = '443'
  $protocol = 'tcp'

  # General Settings
  $my_class = ''
  $source = ''
  $source_dir = ''
  $source_dir_purge = false
  $config_file_default_purge = false
  $template = ''
  $options = ''
  $service_autorestart = true
  $service_requires = Package['wpt']
  $absent = false
  $version = ''
  $disable = false
  $disableboot = false

  ### General module variables that can have a site or per module default
  $monitor = false
  $monitor_tool = ''
  $monitor_target = $::ipaddress
  $firewall = false
  $firewall_tool = ''
  $firewall_src = '0.0.0.0/0'
  $firewall_dst = $::ipaddress
  $puppi = false
  $puppi_helper = 'standard'
  $debug = false
  $audit_only = false

}
