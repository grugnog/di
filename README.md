Drupal Instrumented
===================

Installation
------------
1. Install Ubuntu 12.10
2. Create and log in as user "drupal"
3. Bootstrap:

```bash
sudo apt-get install git puppet
git init
git remote add --track master origin git://github.com/grugnog/di.git
git pull
git submodule init
git submodule update --recursive
sudo puppet apply --confdir=~/.puppet ~/.puppet/manifests/manifest.pp
```
