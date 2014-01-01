# = Define: wpt::listen
#
# This define creates a Listen statement in Apache configuration
# It adds a single configuration file to Apache conf.d with the Listen
# statement
#
# == Parameters
#
# [*namevirtualhost*]
#   If to add a NameVirtualHost for this port. Default: *
#   (it creates a NameVirtualHost <%= @namevirtualhost %>:<%= @port %> entry)
#   Set to false to listen to the port without a NameVirtualHost
#
# == Examples
# wpt::listen { '8080':}
#
define wpt::listen (
  $namevirtualhost = '*',
  $ensure          = 'present',
  $template        = 'wpt/listen.conf.erb',
  $notify_service  = true ) {

  include wpt

  $manage_service_autorestart = $notify_service ? {
    true    => 'Service[wpt]',
    false   => undef,
  }

  file { "Apache_Listen_${name}.conf":
    ensure  => $ensure,
    path    => "${wpt::config_dir}/conf.d/0000_listen_${name}.conf",
    mode    => $wpt::config_file_mode,
    owner   => $wpt::config_file_owner,
    group   => $wpt::config_file_group,
    require => Package['wpt'],
    notify  => $manage_service_autorestart,
    content => template($template),
    audit   => $wpt::manage_audit,
  }

}
