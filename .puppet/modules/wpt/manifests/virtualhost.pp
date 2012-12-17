# = Define: wpt::virtualhost
#
# Basic Virtual host management define
# You can use different templates for your wpt virtual host files
# Default is virtualhost.conf.erb, adapt it to your needs or create
# your custom template.
#
# == Usage:
# With standard template:
# wpt::virtualhost    { "www.example42.com": }
#
# With custom template (create it in MODULEPATH/wpt/templates/virtualhost/)
# wpt::virtualhost { "webmail.example42.com":
#   templatefile => "webmail.conf.erb"
# }
#
# With custom template in custom location
# (MODULEPATH/mymod/templates/wpt/vihost/)
# wpt::virtualhost { "webmail.example42.com":
#   templatefile => "webmail.conf.erb"
#   templatepath => "mymod/wpt/vihost"
# }
#
define wpt::virtualhost (
  $templatefile   = 'virtualhost.conf.erb' ,
  $templatepath   = 'wpt/virtualhost' ,
  $documentroot   = '' ,
  $filename       = '' ,
  $aliases        = '' ,
  $create_docroot = true ,
  $enable         = true ,
  $owner          = '' ,
  $groupowner     = '' ) {

  include wpt

  $real_filename = $filename ? {
    ''      => $name,
    default => $filename,
  }

  $real_documentroot = $documentroot ? {
    ''      =>  "${wpt::data_dir}/${name}",
    default => $documentroot,
  }

  $real_owner = $owner ? {
        ''      => $wpt::config_file_owner,
        default => $owner,
  }

  $real_groupowner = $groupowner ? {
        ''      => $wpt::config_file_group,
        default => $groupowner,
}

  $real_path = $::operatingsystem ? {
    /(?i:Debian|Ubuntu|Mint)/ => "${wpt::vdir}/${real_filename}",
    default                   => "${wpt::vdir}/${real_filename}.conf",
  }

  $ensure_link = any2bool($enable) ? {
    true  => "${wpt::vdir}/${real_filename}",
    false => absent,
  }
  $ensure = bool2ensure($enable)
  $bool_create_docroot = any2bool($create_docroot)

  file { "ApacheVirtualHost_$name":
    ensure  => $ensure,
    path    => $real_path,
    content => template("${templatepath}/${templatefile}"),
    mode    => $wpt::config_file_mode,
    owner   => $wpt::config_file_owner,
    group   => $wpt::config_file_group,
    require => Package['wpt'],
    notify  => $wpt::manage_service_autorestart,
  }

  # Some OS specific settings:
  # On Debian/Ubuntu manages sites-enabled
  case $::operatingsystem {
    ubuntu,debian,mint: {
      file { "ApacheVirtualHostEnabled_$name":
        ensure  => $ensure_link,
        path    => "${wpt::config_dir}/sites-enabled/${real_filename}",
        require => Package['wpt'],
      }
    }
    redhat,centos,scientific,fedora: {
      include wpt::redhat
    }
    default: { }
  }

  if $bool_create_docroot == true {
    file { $real_documentroot:
      ensure => directory,
      owner  => $real_owner,
      group  => $real_groupowner,
      mode   => '0775',
    }
  }

}
