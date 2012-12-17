require "#{File.join(File.dirname(__FILE__),'..','spec_helper.rb')}"

describe 'wpt::virtualhost' do

  let(:title) { 'wpt::virtualhost' }
  let(:node) { 'rspec.example42.com' }
  let(:facts) { { :arch => 'i386' , :operatingsystem => 'redhat' } }
  let(:params) {
    { 'enable'       =>  'true',
      'name'         =>  'www.example42.com',
      'documentroot' =>  '/store/www',
    }
  }

  describe 'Test wpt::virtualhost on redhat' do
    it 'should create a wpt::virtualhost file' do
      should contain_file('ApacheVirtualHost_www.example42.com').with_ensure('present')
    end
    it 'should populate correctly the wpt::virtualhost file DocumentRoot' do
      content = catalogue.resource('file', 'ApacheVirtualHost_www.example42.com').send(:parameters)[:content]
      content.should match "    DocumentRoot /store/www"
    end
    it 'should populate correctly the wpt::virtualhost file ErrorLog' do
      content = catalogue.resource('file', 'ApacheVirtualHost_www.example42.com').send(:parameters)[:content]
      content.should match "    ErrorLog  /var/log/httpd/www.example42.com-error_log"
    end
    it 'should create the docroot directory' do
      content = catalogue.resource('file', '/store/www').send(:parameters)[:ensure]
      content.should match "directory"
    end

  end

  describe 'Test wpt::virtualhost on ubuntu' do
  let(:facts) { { :arch => 'i386' , :operatingsystem => 'ubuntu' } }
  let(:params) {
    { 'enable'       =>  'true',
      'name'         =>  'www.example42.com',
    }
  }

    it 'should create a wpt::virtualhost link in sites-enabled' do
      should contain_file('ApacheVirtualHostEnabled_www.example42.com').with_ensure('/etc/wpt2/sites-available/www.example42.com')
    end
    it 'should populate correctly the wpt::virtualhost file DocumentRoot' do
      content = catalogue.resource('file', 'ApacheVirtualHost_www.example42.com').send(:parameters)[:content]
      content.should match "    DocumentRoot /var/www/www.example42.com"
    end
    it 'should populate correctly the wpt::virtualhost file ErrorLog' do
      content = catalogue.resource('file', 'ApacheVirtualHost_www.example42.com').send(:parameters)[:content]
      content.should match "    ErrorLog  /var/log/wpt2/www.example42.com-error_log"
    end
    it 'should create the docroot directory' do
      content = catalogue.resource('file', '/var/www/www.example42.com').send(:parameters)[:ensure]
      content.should match "directory"
    end

  end

end

