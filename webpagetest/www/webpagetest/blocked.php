<?php 
require_once('common.inc');
$page_keywords = array('Access Denied','Blocked','Webpagetest','Website Speed Test','Page Speed');
$page_description = "Website speed test blocked.";
?>
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN" "http://www.w3.org/TR/html4/loose.dtd">
<html>
    <head>
        <title>WebPagetest - Access Denied</title>
        <?php $gaTemplate = 'Blocked'; include ('head.inc'); ?>
    </head>
    <body>
        <div class="page">
            <?php
            include 'header.inc';
            ?>

            <div class="translucent">
                <h1>Oops...</h1>
                <p>Your test request was intercepted by our abuse filters (or because we need to talk to you about how you are submitting tests or the volume of tests you are submitting).  Most free web hosts have been blocked from testing because of excessive link spam.  
                <?php if($settings['contact']) echo 'If there is a site you want tested that was blocked, please <a href="mailto:' . $settings['contact'] . '">contact us</a>'. ' and send us your IP address (below) and URL that you are trying to test'; ?>.</p>
                <p>
                Your IP address: <b><?php echo $_SERVER['REMOTE_ADDR']; ?></b><br>
                </p>
            </div>

            <?php include('footer.inc'); ?>
        </div>
    </body>
</html>
