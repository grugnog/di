<?php    
$xhprof_data = xhprof_disable();
$__cgroups_stats = __cgroups_metrics($__cgroups_stats);

$dir = dirname(__FILE__) . '/../output/' . $_SERVER['UNIQUE_ID'];
mkdir($dir);
file_put_contents($dir . '/data.xhprof', serialize($xhprof_data));
file_put_contents($dir . '/cgroups.json', json_encode($__cgroups_stats));
chmod($dir . '/data.xhprof', 0777);
chmod($dir . '/cgroups.json', 0777);
