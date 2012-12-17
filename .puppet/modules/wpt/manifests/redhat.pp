# Class wpt::redhat
#
# Apache resources specific for RedHat
#
class wpt::redhat {
  wpt::dotconf { '00-NameVirtualHost':
    content => template('wpt/00-NameVirtualHost.conf.erb'),
  }
}
