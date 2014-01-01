# Class wpt::ssl
#
# Apache resources specific for SSL
#
class wpt::ssl {

  include wpt

  case $::operatingsystem {
    ubuntu,debian,mint: {
      exec { 'enable-ssl':
        command => '/usr/sbin/a2enmod ssl',
        creates => '/etc/wpt2/mods-enabled/ssl.load',
        notify  => Service['wpt'],
        require => Package['wpt'],
      }
    }

    default: {
      package { 'mod_ssl':
        ensure  => present,
        require => Package['wpt'],
        notify  => Service['wpt'],
      }
      file { "${wpt::config_dir}/ssl.conf":
        mode   => '0644',
        owner  => 'root',
        group  => 'root',
        notify => Service['wpt'],
      }
      file {['/var/cache/mod_ssl', '/var/cache/mod_ssl/scache']:
        ensure  => directory,
        owner   => 'wpt',
        group   => 'root',
        mode    => '0700',
        require => Package['mod_ssl'],
        notify  => Service['wpt'],
      }
    }
  }

  ### Port monitoring, if enabled ( monitor => true )
  if $wpt::bool_monitor == true {
    monitor::port { "wpt_${wpt::protocol}_${wpt::ssl_port}":
      protocol => $wpt::protocol,
      port     => $wpt::ssl_port,
      target   => $wpt::monitor_target,
      tool     => $wpt::monitor_tool,
      enable   => $wpt::manage_monitor,
    }
  }

  ### Firewall management, if enabled ( firewall => true )
  if $wpt::bool_firewall == true {
    firewall { "wpt_${wpt::protocol}_${wpt::ssl_port}":
      source      => $wpt::firewall_src,
      destination => $wpt::firewall_dst,
      protocol    => $wpt::protocol,
      port        => $wpt::ssl_port,
      action      => 'allow',
      direction   => 'input',
      tool        => $wpt::firewall_tool,
      enable      => $wpt::manage_firewall,
    }
  }

}
