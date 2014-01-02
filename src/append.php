<?php    
$xhprof_data = xhprof_disable();
file_put_contents(dirname(__FILE__) . '/../output/xhprof/' . $_SERVER['UNIQUE_ID'] . '.xhprof', serialize($xhprof_data));

$__cgroups_stats = __cgroups_metrics($__cgroups_stats);
file_put_contents(dirname(__FILE__) . '/../output/cgroups/' . $_SERVER['UNIQUE_ID'] . '.json', json_encode($__cgroups_stats));

