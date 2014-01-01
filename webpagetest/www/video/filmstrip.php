<?php
header('Content-disposition: attachment; filename=filmstrip.png');
header ("Content-type: image/png");

chdir('..');
include 'common.inc';
require_once('page_data.inc');
require_once('draw.inc');
include 'video/filmstrip.inc.php';  // include the commpn php shared across the filmstrip code

$colMargin = 5;
$rowMargin = 5;
$font = 4;
if ($thumbSize > 400) {
    $font = 5;
}
$fontWidth = imagefontwidth($font);
$fontHeight = imagefontheight($font);
$thumbTop = $fontHeight + $rowMargin;

$bgcolor = '000000';
$color = 'ffffff';
if (array_key_exists('bg', $_GET)) {
    $bgcolor = $_GET['bg'];
}
if (array_key_exists('text', $_GET)) {
    $color = $_GET['text'];
}
$bgcolor = html2rgb($bgcolor);
$color = html2rgb($color);

// go through each of the tests and figure out the width/height
$columnWidth = 0;
foreach( $tests as &$test ) {
    if( $test['video']['width'] && $test['video']['height'] ) {
        if( $test['video']['width'] > $test['video']['height'] ) {
            $test['video']['thumbWidth'] = $thumbSize;
            $test['video']['thumbHeight'] = (int)(((float)$thumbSize / (float)$test['video']['width']) * (float)$test['video']['height']);
        } else {
            $test['video']['thumbHeight'] = $thumbSize;
            $test['video']['thumbWidth'] = (int)(((float)$thumbSize / (float)$test['video']['height']) * (float)$test['video']['width']);
        }
        if ($test['video']['thumbWidth'] > $columnWidth) {
            $columnWidth = $test['video']['thumbWidth'];
        }
    }
}

// figure out the label width
$labelWidth = 0;
foreach( $tests as &$test )
{
    $test['label'] = $test['name'];
    $len = strlen($test['label']);
    if( $len > 30 )
    {
        $test['label'] = substr($test['label'], 0, 27) . '...';
        $len = strlen($test['label']);
    }
    if( $len > $labelWidth )
        $labelWidth = $len;
}
$labelWidth = $labelWidth * $fontWidth;
$thumbLeft = $labelWidth + $colMargin;

// figure out how many columns there are
$end = 0;
foreach( $tests as &$test )
    if( $test['video']['end'] > $end )
        $end = $test['video']['end'];

// figure out the size of the resulting image
$width = $thumbLeft + $colMargin;
$count = 0;
$skipped = $interval;
$last = $end + $interval - 1;
for( $frame = 0; $frame <= $last; $frame++ )
{
    $skipped++;
    if( $skipped >= $interval )
    {
        $skipped = 0;
        $count++;
    }
}

$width += ($columnWidth + ($colMargin * 2)) * $count;

// figure out the height of each row
$height = $fontHeight + ($rowMargin * 2);
foreach( $tests as &$test ) {
    $height += $test['video']['thumbHeight'] + ($rowMargin * 2);
}

// create the blank image
$im = imagecreatetruecolor($width, $height);

// define some colors
$background = GetColor($im, $bgcolor[0], $bgcolor[1], $bgcolor[2]);
$textColor = GetColor($im, $color[0], $color[1], $color[2]);
$colChanged = GetColor($im, 254,179,1);
$colAFT = GetColor($im, 255,0,0);

imagefilledrectangle($im, 0, 0, $width, $height, $background);

// put the time markers across the top
$left = $thumbLeft;
$top = $thumbTop - $fontHeight;
$skipped = $interval;
$last = $end + $interval - 1;
for( $frame = 0; $frame <= $last; $frame++ )
{
    $skipped++;
    if( $skipped >= $interval )
    {
        $left += $colMargin;
        $skipped = 0;
        $val = number_format((float)$frame / 10.0, 1) . 's';
        $x = $left + (int)($columnWidth / 2.0) - (int)((double)$fontWidth * ((double)strlen($val) / 2.0));
        imagestring($im, $font, $x, $top, $val, $textColor);
        $left += $columnWidth + $colMargin;
    }
}

// draw the text labels
$top = $thumbTop;
$left = $colMargin;
foreach( $tests as &$test )
{
    $top += $rowMargin;
    $x = $left + $labelWidth - (int)(strlen($test['label']) * $fontWidth);
    $y = $top + (int)(($test['video']['thumbHeight'] / 2.0) - ($fontHeight / 2.0));
    imagestring($im, $font, $x, $y, $test['label'], $textColor);
    $top += $test['video']['thumbHeight'] + $rowMargin;
}

// fill in the actual thumbnails
$top = $thumbTop;
$thumb = null;
foreach( $tests as &$test )
{
    $aft = (int)$test['aft'] / 100;
    $left = $thumbLeft;
    $top += $rowMargin;
    
    $lastThumb = null;
    if( $thumb )
    {
        imagedestroy($thumb);
        unset($thumb);
    }
    $skipped = $interval;
    $last = $end + $interval - 1;
    for( $frame = 0; $frame <= $last; $frame++ )
    {
        $path = $test['video']['frames'][$frame];
        if( isset($path) )
            $test['currentframe'] = $frame;
        else
        {
            if( isset($test['currentframe']) )
                $path = $test['video']['frames'][$test['currentframe']];
            else
                $path = $test['video']['frames'][0];
        }

        if( !$lastThumb )
            $lastThumb = $path;
        
        $skipped++;
        if( $skipped >= $interval )
        {
            $skipped = 0;

            if( $frame - $interval + 1 <= $test['video']['end'] )
            {
                unset($border);
                $cached = '';
                if( $test['cached'] )
                    $cached = '_cached';
                $imgPath = GetTestPath($test['id']) . "/video_{$test['run']}$cached/$path";
                if( $lastThumb != $path || !$thumb )
                {
                    if( $lastThumb != $path )
                        $border = $colChanged;
                    if( $aft && $frame >= $aft )
                    {
                        $aft = 0;
                        $border = $colAFT;
                    }
                    
                    // load the new thumbnail
                    if( $thumb )
                    {
                        imagedestroy($thumb);
                        unset($thuumb);
                    }
                    $tmp = imagecreatefromjpeg("./$imgPath");
                    if( $tmp )
                    {
                        $thumb = imagecreatetruecolor($test['video']['thumbWidth'], $test['video']['thumbHeight']);
                        fastimagecopyresampled($thumb, $tmp, 0, 0, 0, 0, $test['video']['thumbWidth'], $test['video']['thumbHeight'], imagesx($tmp), imagesy($tmp), 4);
                        imagedestroy($tmp);
                    }
                }
                
                // draw the thumbnail
                $left += $colMargin;
                $width = imagesx($thumb);
                $padding = ($columnWidth - $width) / 2;
                if( isset($border) )
                    imagefilledrectangle($im, $left - 2 + $padding, $top - 2, $left + imagesx($thumb) + 2 + $padding, $top + imagesy($thumb) + 2, $border);
                imagecopy($im, $thumb, $left + $padding, $top, 0, 0, $width, imagesy($thumb));
                $left += $columnWidth + $colMargin;
                
                $lastThumb = $path;
            }
        }
    }
    
    $top += $test['video']['thumbHeight'] + $rowMargin;
}

// spit the image out to the browser
imagepng($im);
imagedestroy($im);

function html2rgb($color) {
    if ($color[0] == '#')
        $color = substr($color, 1);

    if (strlen($color) == 6)
        list($r, $g, $b) = array($color[0].$color[1],
                                 $color[2].$color[3],
                                 $color[4].$color[5]);
    elseif (strlen($color) == 3)
        list($r, $g, $b) = array($color[0].$color[0], $color[1].$color[1], $color[2].$color[2]);
    else
        return false;

    $r = hexdec($r); $g = hexdec($g); $b = hexdec($b);

    return array($r, $g, $b);
}

?>
