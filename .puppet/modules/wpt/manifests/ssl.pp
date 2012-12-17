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
      file { "${wpt::configdir}/ssl.conf":
        mode   => '0644',
        owner  => 'root',
        group  => 'root',
        notify => Service['wpt'],
      }
      file {['/var/cache/mod_ssl', '/var/cache/mod_ssl/scache']:
        ensure => directory,
        owner  => 'wpt',
        group  => 'root',
        mode   => '0700',
        notify => Service['wpt'],
      }
    }
  }

}
