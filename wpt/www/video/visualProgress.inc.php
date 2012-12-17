<?php
/**
* Calculate the progress for all of the images in a given directory
*/
function GetVisualProgress($testPath, $run, $cached, $options = null) {
    $frames = null;
    $video_directory = "$testPath/video_{$run}";
    if ($cached)
        $video_directory .= '_cached';
    $cache_file = "$testPath/$run.$cached.visual.dat";
    $dirty = false;
    $current_version = 3;
    if (!isset($options) && gz_is_file($cache_file)) {
        $frames = json_decode(gz_file_get_contents($cache_file), true);
        if (!array_key_exists('frames', $frames) || !array_key_exists('version', $frames))
            unset($frames);
        elseif(array_key_exists('version', $frames) && $frames['version'] !== $current_version)
            unset($frames);
    }
    if ((!isset($frames) || !count($frames)) && is_dir($video_directory)) {
        $frames = array('version' => $current_version);
        $frames['frames'] = array();
        $dirty = true;
        $base_path = substr($video_directory, 1);
        $files = scandir($video_directory);
        $last_file = null;
        $first_file = null;
        foreach ($files as $file) {
            if (strpos($file,'frame_') !== false && strpos($file,'.hist') === false) {
                $parts = explode('_', $file);
                if (count($parts) >= 2) {
                    if (!isset($first_file))
                        $first_file = $file;
                    $last_file = $file;
                    $time = ((int)$parts[1]) * 100;
                    $frames['frames'][$time] = array( 'path' => "$base_path/$file",
                                            'file' => $file);
                }
            } 
        }
        if (count($frames['frames']) == 1) {
            foreach($frames['frames'] as $time => &$frame) {
                $frame['progress'] = 100;
                $frames['complete'] = $time;
            }
        } elseif (  isset($first_file) && strlen($first_file) && 
                    isset($last_file) && strlen($last_file) && count($frames['frames'])) {
            $start_histogram = GetImageHistogram("$video_directory/$first_file", $options);
            $final_histogram = GetImageHistogram("$video_directory/$last_file", $options);
            foreach($frames['frames'] as $time => &$frame) {
                $histogram = GetImageHistogram("$video_directory/{$frame['file']}", $options);
                $frame['progress'] = CalculateFrameProgress($histogram, $start_histogram, $final_histogram, $options);
                if ($frame['progress'] == 100 && !array_key_exists('complete', $frames)) {
                    $frames['complete'] = $time;
                }
            }
        }
    }
    if (isset($frames) && !array_key_exists('SpeedIndex', $frames)) {
        $dirty = true;
        $frames['SpeedIndex'] = $frames['FLI'] = CalculateFeelsLikeIndex($frames);
    }
    if (isset($frames)) {
        $frames['visualComplete'] = 0;
        foreach($frames['frames'] as $time => &$frame) {
            if ($frame['progress'] == 100) {
                $frames['visualComplete'] = $time;
                break;
            }
        }
    }
    if (!isset($options) && $dirty && isset($frames) && count($frames))
        gz_file_put_contents($cache_file,json_encode($frames));
    return $frames;
}

/**
* Calculate histograms for each color channel for the given image
*/
function GetImageHistogram($image_file, $options = null) {
    $histogram = null;
    $ext = strripos($image_file, '.jpg');
    if ($ext !== false) {
        $histogram_file = substr($image_file, 0, $ext) . '.hist';
    }
    // first, see if we have a client-generated histogram
    if (!isset($options) && isset($histogram_file) && is_file($histogram_file)) {
        $histogram = json_decode(file_get_contents($histogram_file), true);
        if (!is_array($histogram) || 
            !array_key_exists('r', $histogram) ||
            !array_key_exists('g', $histogram) ||
            !array_key_exists('b', $histogram) ||
            count($histogram['r']) != 256 ||
            count($histogram['g']) != 256 ||
            count($histogram['b']) != 256) {
            unset($histogram);
        }
    }
    
    // generate a histogram from the image itself
    if (!isset($histogram)) {
        $im = imagecreatefromjpeg($image_file);
        if ($im !== false) {
            $width = imagesx($im);
            $height = imagesy($im);
            if ($width > 0 && $height > 0) {
                if (isset($options) && array_key_exists('resample', $options) && $options['resample'] > 2) {
                    $oldWidth = $width;
                    $oldHeight = $height;
                    $width = ($width * 2) / $options['resample'];
                    $height = ($height * 2) / $options['resample'];
                    $tmp = imagecreatetruecolor($width, $height);
                    fastimagecopyresampled($tmp, $im, 0, 0, 0, 0, $width, $height, $oldWidth, $oldHeight, 3);
                    imagedestroy($im);
                    $im = $tmp;    
                    unset($tmp);
                }
                $histogram = array();
                $histogram['r'] = array();
                $histogram['g'] = array();
                $histogram['b'] = array();
                $buckets = 256;
                if (isset($options) && array_key_exists('buckets', $options) && $options['buckets'] >= 1 && $options['buckets'] <= 256) {
                    $buckets = $options['buckets'];
                }
                for ($i = 0; $i < $buckets; $i++) {
                    $histogram['r'][$i] = 0;
                    $histogram['g'][$i] = 0;
                    $histogram['b'][$i] = 0;
                }
                for ($y = 0; $y < $height; $y++) {
                    for ($x = 0; $x < $width; $x++) {
                        $rgb = ImageColorAt($im, $x, $y); 
                        $r = ($rgb >> 16) & 0xFF;
                        $g = ($rgb >> 8) & 0xFF;
                        $b = $rgb & 0xFF;
                        // ignore white pixels
                        if ($r != 255 || $g != 255 || $b != 255) {
                            if (isset($options) && array_key_exists('colorSpace', $options) && $options['colorSpace'] != 'RGB') {
                                if ($options['colorSpace'] == 'HSV') {
                                    RGB_TO_HSV($r, $g, $b);
                                } elseif ($options['colorSpace'] == 'YUV') {
                                    RGB_TO_YUV($r, $g, $b);
                                }
                            }
                            $bucket = (int)(($r + 1.0) / 256.0 * $buckets) - 1;
                            $histogram['r'][$bucket]++;
                            $bucket = (int)(($g + 1.0) / 256.0 * $buckets) - 1;
                            $histogram['g'][$bucket]++;
                            $bucket = (int)(($b + 1.0) / 256.0 * $buckets) - 1;
                            $histogram['b'][$bucket]++;
                        }
                    }
                }
            }
            imagedestroy($im);
            unset($im);
        }
    }
    return $histogram;
}

/**
* Calculate how close a given histogram is to the final
*/
function CalculateFrameProgress(&$histogram, &$start_histogram, &$final_histogram, $options) {
    $progress = 0;
    $channels = array('r', 'g', 'b');
    $totalWeight = count($channels);
    if (isset($options) && array_key_exists('weights', $options) && is_array($options['weights'])) {
        $totalWeight = 0;
        foreach ($channels as $index => $channel) {
            if (array_key_exists($index, $options['weights']))
                $totalWeight += $options['weights'][$index];
            else
                $options['weights'][$index] = 0;
        }
    }
    foreach ($channels as $index => $channel) {
        $weight = 1;
        if (isset($options) && array_key_exists('weights', $options) && is_array($options['weights']) && array_key_exists($index, $options['weights'])) {
            $weight = $options['weights'][$index];
        }
        $weightFactor += $weight;
        $total = 0;
        $achieved = 0;
        $buckets = count($final_histogram[$channel]);
        for ($i = 0; $i < $buckets; $i++) {
            $total += abs($final_histogram[$channel][$i] - $start_histogram[$channel][$i]);
        }
        for ($i = 0; $i < $buckets; $i++) {
            $achieved += min(abs($final_histogram[$channel][$i] - $start_histogram[$channel][$i]), abs($histogram[$channel][$i] - $start_histogram[$channel][$i]));
        }
        if ($totalWeight)
            $progress += (($achieved / $total) * $weight) / $totalWeight;
    }
    return round($progress * 100);
}

/**
* Boil the frame loading progress down to a single number
*/
function CalculateFeelsLikeIndex(&$frames) {
    $index = null;
    if (array_key_exists('frames', $frames)) {
        $last_ms = 0;
        $last_progress = 0;
        $index = 0;
        foreach($frames['frames'] as $time => &$frame) {
            $elapsed = $time - $last_ms;
            $index += $elapsed * (1.0 - $last_progress);
            $last_ms = $time;
            $last_progress = $frame['progress'] / 100.0;
        }
    }
    $index = (int)($index);
    
    return $index;
}

/**
* Convert RGB values (0-255) into HSV values (and force it into a 0-255 range)
* Return the values in-place (R = H, G = S, B = V)
* 
* @param mixed $R
* @param mixed $G
* @param mixed $B
*/
function RGB_TO_HSV(&$R, &$G, &$B) {
   $HSL = array();

   $var_R = ($R / 255);
   $var_G = ($G / 255);
   $var_B = ($B / 255);

   $var_Min = min($var_R, $var_G, $var_B);
   $var_Max = max($var_R, $var_G, $var_B);
   $del_Max = $var_Max - $var_Min;

   $V = $var_Max;

   if ($del_Max == 0) {
      $H = 0;
      $S = 0;
   } else {
      $S = $del_Max / $var_Max;

      $del_R = ( ( ( $var_Max - $var_R ) / 6 ) + ( $del_Max / 2 ) ) / $del_Max;
      $del_G = ( ( ( $var_Max - $var_G ) / 6 ) + ( $del_Max / 2 ) ) / $del_Max;
      $del_B = ( ( ( $var_Max - $var_B ) / 6 ) + ( $del_Max / 2 ) ) / $del_Max;

      if      ($var_R == $var_Max) $H = $del_B - $del_G;
      else if ($var_G == $var_Max) $H = ( 1 / 3 ) + $del_R - $del_B;
      else if ($var_B == $var_Max) $H = ( 2 / 3 ) + $del_G - $del_R;

      if ($H<0) $H++;
      if ($H>1) $H--;
   }

   $R = min(max((int)($H * 255), 0), 255);
   $G = min(max((int)($S * 255), 0), 255);
   $B = min(max((int)($V * 255), 0), 255);
}

/**
* Convert RGB in-place to YUV
*/
function RGB_TO_YUV(&$r, &$g, &$b) {
    $Y = (0.257 * $r) + (0.504 * $g) + (0.098 * $b) + 16;
    $U = -(0.148 * $r) - (0.291 * $g) + (0.439 * $b) + 128;
    $V = (0.439 * $r) - (0.368 * $g) - (0.071 * $b) + 128;
    $r = min(max((int)$Y, 0), 255);
    $g = min(max((int)$U, 0), 255);
    $b = min(max((int)$V, 0), 255);
}
?>
