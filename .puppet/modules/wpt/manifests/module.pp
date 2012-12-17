# = Define: wpt::module
#
# This define installs and configures wpt modules
# On Debian and derivatives it places the module config
# into /etc/wpt/mods-available.
# On RedHat and derivatives it just creates the configuration file, if
# provided via the templatefile => argument
# If you need to customize the module .conf file,
# add a templatefile with path to the template,
#
# == Parameters
#
# [*ensure*]
#   If to enable/install the module. Default: present
#   Set to absent to disable/remove the module
#
# [*templatefile*]
#   Optional. Location of the template to use to configure
#   the module
#
# [*install_package*]
#   If a module package has to be installed. Default: false
#   Set to true if the module package is not installed by default
#   and you need to install the relevant package
#   In this case the package name is calculated according to the operatingsystem
#   and the ${name} variable.
#   If the autocalculated package name for the module is not
#   correct, you can explicitely set it (using a string different than
#   true or false)
#
# [*notify_service*]
#   If you want to restart the wpt service automatically when
#   the module is applied. Default: true
#
# == Examples
# wpt::module { 'proxy':
#   templatefile => 'wpt/module/proxy.conf.erb',
# }
#
# wpt::module { 'bw':
#   install_package => true,
#   templatefile    => 'myclass/wpt/bw.conf.erb',
# }
#
# wpt::module { 'proxy_html':
#   install_package => 'libwpt2-mod-proxy-html',
# }
#
#
define wpt::module (
  $ensure          = 'present',
  $templatefile    = '',
  $install_package = false,
  $notify_service  = true ) {

  include wpt

  $manage_service_autorestart = $notify_service ? {
    true    => 'Service[wpt]',
    false   => undef,
  }

  if $install_package != false {
    $modpackage_basename = $::operatingsystem ? {
      /(?i:Ubuntu|Debian|Mint)/ => 'libwpt2-mod-',
      default                   => 'mod_',
    }

    $real_install_package = $install_package ? {
      true    => "${modpackage_basename}${name}",
      default => $install_package,
    }

    package { "ApacheModule_${name}":
      ensure  => $ensure,
      name    => $real_install_package,
      notify  => $manage_service_autorestart,
      require => Package['wpt'],
    }
  }


  if $templatefile != '' {
    $module_conf_path = $::operatingsystem ? {
      /(?i:Ubuntu|Debian|Mint)/ => "${wpt::config_dir}/mods-available/${name}.conf",
      default                   => "${wpt::config_dir}/conf.d/module_${name}.conf",
    }

    file { "ApacheModule_${name}_conf":
      ensure  => present ,
      path    => $module_conf_path,
      mode    => $wpt::config_file_mode,
      owner   => $wpt::config_file_owner,
      group   => $wpt::config_file_group,
      content => template($templatefile),
      notify  => $manage_service_autorestart,
      require => Package['wpt'],
    }
  }


  if $::operatingsystem == 'Debian'
  or $::operatingsystem == 'Ubuntu'
  or $::operatingsystem == 'Mint' {
    case $ensure {
      'present': {
        exec { "/usr/sbin/a2enmod ${name}":
          unless    => "/bin/sh -c '[ -L ${wpt::config_dir}/mods-enabled/${name}.load ] && [ ${wpt::config_dir}/mods-enabled/${name}.load -ef ${wpt::config_dir}/mods-available/${name}.load ]'",
          notify    => $manage_service_autorestart,
          require   => Package['wpt'],
        }
      }
      'absent': {
        exec { "/usr/sbin/a2dismod ${name}":
          onlyif    => "/bin/sh -c '[ -L ${wpt::config_dir}/mods-enabled/${name}.load ] && [ ${wpt::config_dir}/mods-enabled/${name}.load -ef ${wpt::config_dir}/mods-available/${name}.load ]'",
          notify    => $manage_service_autorestart,
          require   => Package['wpt'],
        }
      }
      default: {
      }
    }
  }

}
