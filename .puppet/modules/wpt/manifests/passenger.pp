# Class wpt::passenger
#
# Apache resources specific for passenger
#
class wpt::passenger {

  include wpt

  case $::operatingsystem {
    ubuntu,debian,mint: {
      package { 'libwpt2-mod-passenger':
        ensure => present;
      }

      exec { 'enable-passenger':
        command => '/usr/sbin/a2enmod passenger',
        creates => '/etc/wpt2/mods-enabled/passenger.load',
        notify  => Service['wpt'],
        require => [
          Package['wpt'],
          Package['libwpt2-mod-passenger']
        ],
      }
    }

    centos,redhat,scientific,fedora: {
      $osver = split($::operatingsystemrelease, '[.]')

      case $osver[0] {
        5: { require yum::repo::passenger }
        default: { }
      }
      package { 'mod_passenger':
        ensure => present;
      }
    }

    default: { }
  }

}
