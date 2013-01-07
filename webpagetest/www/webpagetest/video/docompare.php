<?php
chdir('..');
include 'common.inc';

$urls = $_REQUEST['url'];
$labels = $_REQUEST['label'];
$ids = array();
$ip = $_SERVER['REMOTE_ADDR'];
$key = '';
$keys = parse_ini_file('./settings/keys.ini', true);
if( $keys && isset($keys['server']) && isset($keys['server']['key']) )
  $key = trim($keys['server']['key']);
$headless = false;
if (array_key_exists('headless', $settings) && $settings['headless']) {
    $headless = true;
}

if (!$headless) {
    foreach( $urls as $index => $url )
    {
        $url = trim($url);
        if( strlen($url) )
        {
            $id = SubmitTest($url, $labels[$index], $key);
            if( $id && strlen($id) )
                $ids[] = $id;
        }
    }

    // now add the industry urls
    foreach( $_REQUEST['t'] as $tid )
    {
        $tid = trim($tid);
        if( strlen($tid) )
            $ids[] = $tid;
    }
}

// if we were successful, redirect to the result page
if( count($ids) )
{
    $idStr = '';
    if( $_GET['tid'] )
    {
        $idStr = $_GET['tid'];
        if( $_GET['tidlabel'] )
            $idStr .= '-l:' . urlencode($_GET['tidlabel']);
    }
    foreach($ids as $id)
    {
        if( strlen($idStr) )
            $idStr .= ',';
        $idStr .= $id;
    }
    
    $compareUrl = 'http://' . $_SERVER['HTTP_HOST'] . "/video/compare.php?tests=$idStr";
    header("Location: $compareUrl");    
}
else
{
    DisplayError();
}

/**
* Submit a video test request with the appropriate parameters
* 
* @param mixed $url
* @param mixed $label
*/
function SubmitTest($url, $label, $key)
{
    global $uid;
    global $user;
    global $ip;
    $id = null;
    
    $testUrl = 'http://' . $_SERVER['HTTP_HOST'] . '/runtest.php?';
    $testUrl .= 'f=xml&priority=2&runs=3&video=1&mv=1&fvonly=1&url=' . urlencode($url);
    if( $label && strlen($label) )
        $testUrl .= '&label=' . urlencode($label);
    if( $ip )
        $testUrl .= "&addr=$ip";
    if( $uid )
        $testUrl .= "&uid=$uid";
    if( $user )
        $testUrl .= '&user=' . urlencode($uid);
    if( strlen($key) )
        $testUrl .= '&k=' . urlencode($key);
        
    // submit the request
    $result = simplexml_load_file($testUrl, 'SimpleXMLElement',LIBXML_NOERROR);
    if( $result && $result->data )
        $id = (string)$result->data->testId;
    
    return $id;
}

/**
* Something went wrong, give them an error message
* 
*/
function DisplayError()
{
?>
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN" "http://www.w3.org/TR/html4/loose.dtd">
<html>
    <head>
        <title>WebPagetest - Visual Comparison</title>
        <?php $gaTemplate = 'Visual Comparison Error'; include ('head.inc'); ?>
    </head>
    <body>
        <div class="page">
            <?php
            $tab = null;
            $headerType = 'video';
            include 'header.inc';
            ?>

            <h1>There was an error running the test(s).</h1>
            
            <?php include('footer.inc'); ?>
        </div>
    </body>
</html>
<?php
}
?>
