# = Define: wpt::dotconf
#
# General Apache define to be used to create generic custom .conf files
# Very simple wrapper to a normal file type
# Use source or template to define the source
#
# == Parameters
#
# [*source*]
#   Sets the content of source parameter for the dotconf file
#   If defined, wpt dotconf file will have the param: source => $source
#
# [*template*]
#   Sets the path to the template to use as content for dotconf file
#   If defined, wpt dotconf file has: content => content("$template")
#   Note source and template parameters are mutually exclusive: don't use both
#
# == Usage
# wpt::dotconf { "sarg": source => 'puppet://$servername/sarg/sarg.conf' }
# or
# wpt::dotconf { "trac": content => 'template("trac/wpt.conf.erb")' }
#
define wpt::dotconf (
  $source  = '' ,
  $content = '' ,
  $ensure  = present ) {

  $manage_file_source = $source ? {
    ''        => undef,
    default   => $source,
  }

  $manage_file_content = $content ? {
    ''        => undef,
    default   => $content,
  }

  file { "Apache_${name}.conf":
    ensure  => $ensure,
    path    => "${wpt::config_dir}/conf.d/${name}.conf",
    mode    => $wpt::config_file_mode,
    owner   => $wpt::config_file_owner,
    group   => $wpt::config_file_group,
    require => Package['wpt'],
    notify  => $wpt::manage_service_autorestart,
    source  => $manage_file_source,
    content => $manage_file_content,
    audit   => $wpt::manage_audit,
  }

}
