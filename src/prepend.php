<?php
function __cgroups_metrics($original = array()) {
  $results = array();
  $listing = glob('/cgroup/*/*/*');
  foreach ($listing as $file) {
    $parts = explode('/', $file);
    $name = array_pop($parts);
    $daemon = array_pop($parts);
    $resource = array_pop($parts);
    $metrics = explode("\n", trim(file_get_contents($file)));
    foreach ($metrics as $metric) {
      $values = explode(' ', $metric);
      $value = array_pop($values);
      $key = implode(' ', $values);
      if (empty($key)) {
        if (isset($original[$resource][$daemon][$name]) && $original_value = $original[$resource][$daemon][$name]) {
          $value = $value - $original_value;
        }
	$results[$resource][$daemon][$name] = $value;
      }
      else {
	if (isset($original[$resource][$daemon][$name][$key]) && $original_value = $original[$resource][$daemon][$name][$key]) {
          $value = $value - $original_value;
        }
	$results[$resource][$daemon][$name][$key] = $value;
      }
    }
  }
  return $results;
}

$__cgroups_stats = __cgroups_metrics();

xhprof_enable(XHPROF_FLAGS_CPU + XHPROF_FLAGS_MEMORY);
